<?php
/**
 * lib/push.php — notification on the customer's phone when an order is ready, also when the
 * page is closed or the phone locked: Web Push (RFC 8030 delivery, RFC 8291 encryption of the
 * message, RFC 8292 "VAPID" identification of this server). No library: PHP's OpenSSL does the
 * P-256 key agreement, the ES256 signature and AES-128-GCM.
 *
 * What a phone needs: a browser with Web Push (Chrome, Firefox, Edge, Samsung Internet on
 * Android; Safari on an iPhone only once the shop has been added to the home screen, iOS 16.4
 * and later). The customer page asks the permission on a tap, registers the service worker
 * (public/sw.js), subscribes with the server's public key and sends the subscription to
 * public/api/push.php. The push service of the browser only ever carries the encrypted message.
 *
 * Sending is best effort: a push service that does not answer within a few seconds does not
 * hold the volunteer's request for long, and a page left open on the phone still announces the
 * order by itself. A subscription the push service says is gone (404, 410) is deleted.
 */

if (defined('SHP_PUSH_LOADED')) return;
define('SHP_PUSH_LOADED', true);

require_once __DIR__ . '/common.php';

define('SHP_PUSH_PER_CUSTOMER', 5);   // browsers of one customer kept for one competition

/** URL-safe base64 without padding, as the Web Push specifications write keys. */
function shp_b64u($bin)
{
    return rtrim(strtr(base64_encode((string) $bin), '+/', '-_'), '=');
}

/** Bytes of a URL-safe base64 text, false when it is not one. */
function shp_b64u_dec($text)
{
    $s = strtr(trim((string) $text), '-_', '+/');
    if (!preg_match('/^[A-Za-z0-9+\/]*$/', $s)) return false;
    // bytes: base64 text is ASCII
    $pad = strlen($s) % 4;
    return base64_decode($s . str_repeat('=', $pad ? 4 - $pad : 0), true);
}

/** PEM public key of a raw uncompressed P-256 point (0x04 || X || Y, 65 bytes). */
function shp_push_pem_public($raw)
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Raw uncompressed point of an OpenSSL P-256 key. */
function shp_push_raw_public($key)
{
    $d = openssl_pkey_get_details($key);
    if (!$d || empty($d['ec']['x']) || empty($d['ec']['y'])) return '';
    // bytes: coordinates are binary, left-padded to 32 bytes
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

/** ES256 signature as JOSE wants it (r || s, 64 bytes) from OpenSSL's DER form. */
function shp_push_sig_raw($der)
{
    // bytes: DER of SEQUENCE { INTEGER r, INTEGER s }
    $o = (ord($der[1]) & 0x80) ? 2 + (ord($der[1]) & 0x7f) : 2;
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$o + 1]);
        // bytes: one INTEGER of the signature, its leading zeros dropped then padded to 32 bytes
        $int = ltrim(substr($der, $o + 2, $len), "\0");
        $o += 2 + $len;
        $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
    }
    return $out;
}

/**
 * The server's VAPID key pair, made the first time it is needed: ['public' (base64url point),
 * 'private' (PEM), 'subject'], or false when OpenSSL cannot make an EC key on this server.
 */
function shp_push_keys()
{
    static $keys = null;
    if ($keys !== null) return $keys;
    shp_schema();
    $r = safe_fetch(safe_r_sql("SELECT * FROM ShopPushKeys WHERE SzId = 1"));
    if (!$r || (string) $r->SzPrivate === '') {
        $key = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
        if (!$key || !openssl_pkey_export($key, $pem)) return $keys = false;
        $host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
        $subject = $host !== '' ? 'https://' . $host : 'mailto:webmaster@localhost';
        safe_w_sql("INSERT IGNORE INTO ShopPushKeys SET SzId = 1, SzPublic = " . StrSafe_DB(shp_b64u(shp_push_raw_public($key)))
            . ", SzPrivate = " . StrSafe_DB($pem) . ", SzSubject = " . StrSafe_DB($subject) . ", SzCreated = UTC_TIMESTAMP()");
        $r = safe_fetch(safe_w_sql("SELECT * FROM ShopPushKeys WHERE SzId = 1"));
    }
    return $keys = $r ? array('public' => (string) $r->SzPublic, 'private' => (string) $r->SzPrivate, 'subject' => (string) $r->SzSubject) : false;
}

/** Value of the Authorization header that identifies this server to a push service (RFC 8292). */
function shp_push_vapid($endpoint, array $keys)
{
    $p = parse_url((string) $endpoint);
    if (empty($p['scheme']) || empty($p['host'])) return '';
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . intval($p['port']) : '');
    $head = shp_b64u(json_encode(array('typ' => 'JWT', 'alg' => 'ES256')));
    $claims = shp_b64u(json_encode(array('aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $keys['subject']), JSON_UNESCAPED_SLASHES));
    $priv = openssl_pkey_get_private($keys['private']);
    if (!$priv || !openssl_sign($head . '.' . $claims, $der, $priv, OPENSSL_ALGO_SHA256)) return '';
    return 'vapid t=' . $head . '.' . $claims . '.' . shp_b64u(shp_push_sig_raw($der)) . ', k=' . $keys['public'];
}

/**
 * Body of a push message encrypted for one browser (RFC 8291, content coding aes128gcm, one
 * record). $uaPublic and $auth: the keys of the subscription (base64url). $asKey and $salt are
 * only given by tests (fixed values of the RFC's example); otherwise both are new each time.
 * False when a key is not usable.
 */
function shp_push_encrypt($payload, $uaPublic, $auth, $asKey = null, $salt = null)
{
    $ua = shp_b64u_dec($uaPublic);
    $secret = shp_b64u_dec($auth);
    // bytes: binary keys of fixed sizes
    if ($ua === false || strlen($ua) !== 65 || $ua[0] !== "\x04" || $secret === false || strlen($secret) !== 16) return false;
    if ($asKey === null) $asKey = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
    $peer = openssl_pkey_get_public(shp_push_pem_public($ua));
    if (!$asKey || !$peer) return false;
    $asPublic = shp_push_raw_public($asKey);
    $shared = openssl_pkey_derive($peer, $asKey);
    if ($shared === false || $asPublic === '') return false;
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $ua . $asPublic, $secret);
    if ($salt === null) $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $tag = '';
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipher === false) return false;
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

/**
 * Is this an address of a known push service? The server POSTs to it: an address typed by
 * anyone could otherwise make it call machines of its own network.
 */
function shp_push_endpoint_ok($endpoint)
{
    $endpoint = (string) $endpoint;
    $p = parse_url($endpoint);
    // bytes: an URL's length, ASCII
    if (strlen($endpoint) > 1000 || empty($p['scheme']) || $p['scheme'] !== 'https' || empty($p['host']) || isset($p['user'])) return false;
    return (bool) preg_match('/(^|\.)(fcm\.googleapis\.com|android\.googleapis\.com|push\.services\.mozilla\.com|push\.apple\.com|notify\.windows\.com)$/i', $p['host']);
}

/**
 * Records the subscription of the customer's browser ($sub: endpoint, keys.p256dh, keys.auth).
 * The same browser subscribing again replaces its row. Returns ['error' => 0] or an error.
 */
function shp_push_subscribe($tourId, array $customer, array $sub, $lang)
{
    $tourId = intval($tourId);
    $endpoint = (string) ($sub['endpoint'] ?? '');
    $p256 = (string) ($sub['keys']['p256dh'] ?? '');
    $auth = (string) ($sub['keys']['auth'] ?? '');
    $ua = shp_b64u_dec($p256);
    $sec = shp_b64u_dec($auth);
    // bytes: binary keys of fixed sizes
    if (!shp_push_endpoint_ok($endpoint) || $ua === false || strlen($ua) !== 65 || $sec === false || strlen($sec) !== 16) {
        return array('error' => 1, 'code' => 'bad_request', 'msg' => shp_t('ShErrBadRequest'));
    }
    $guest = $customer['kind'] === 'GUEST' ? intval($customer['guest']) : 0;
    $licence = $customer['kind'] === 'ARCHER' ? (string) $customer['licence'] : '';
    if ($guest <= 0 && $licence === '') return array('error' => 1, 'code' => 'customer', 'msg' => shp_t('ShErrCustomer'));
    $lang = preg_match('/^[a-z]{2}$/', (string) $lang) ? (string) $lang : 'en';
    $hash = hash('sha256', $endpoint);
    safe_w_sql("INSERT INTO ShopPush SET SyTournament = $tourId, SyGuest = $guest, SyLicence = " . StrSafe_DB($licence)
        . ", SyHash = '$hash', SyEndpoint = " . StrSafe_DB($endpoint) . ", SyP256dh = " . StrSafe_DB($p256)
        . ", SyAuth = " . StrSafe_DB($auth) . ", SyLang = " . StrSafe_DB($lang) . ", SyCreated = UTC_TIMESTAMP(), SyFails = 0
        ON DUPLICATE KEY UPDATE SyTournament = $tourId, SyGuest = $guest, SyLicence = " . StrSafe_DB($licence)
        . ", SyP256dh = " . StrSafe_DB($p256) . ", SyAuth = " . StrSafe_DB($auth) . ", SyLang = " . StrSafe_DB($lang) . ", SyFails = 0");
    // A customer keeps a few browsers at most: the oldest go.
    $who = $guest > 0 ? "SyGuest = $guest" : "SyLicence = " . StrSafe_DB($licence);
    $rs = safe_w_sql("SELECT SyId FROM ShopPush WHERE SyTournament = $tourId AND $who ORDER BY SyId DESC");
    $n = 0;
    $old = array();
    while ($r = safe_fetch($rs)) if (++$n > SHP_PUSH_PER_CUSTOMER) $old[] = intval($r->SyId);
    if ($old) safe_w_sql("DELETE FROM ShopPush WHERE SyId IN (" . implode(',', $old) . ")");
    return array('error' => 0);
}

/** Forgets the subscription of this browser (only the customer's own). */
function shp_push_unsubscribe($tourId, array $customer, $endpoint)
{
    $guest = $customer['kind'] === 'GUEST' ? intval($customer['guest']) : 0;
    $who = $guest > 0 ? "SyGuest = $guest" : "SyLicence = " . StrSafe_DB((string) ($customer['licence'] ?? ''));
    safe_w_sql("DELETE FROM ShopPush WHERE SyTournament = " . intval($tourId) . " AND SyHash = '" . hash('sha256', (string) $endpoint) . "' AND $who");
    return array('error' => 0);
}

/** The $lang array of a language file, read in a scope of its own. */
function shp_push_lang_file($file)
{
    $lang = array();
    include $file;
    return is_array($lang) ? $lang : array();
}

/** A text of the shop in a given language (the subscriber's, not the volunteer's), English underneath. */
function shp_push_text($lang, $key, $a = null)
{
    static $cache = array();
    $lang = preg_match('/^[a-z]{2}$/', (string) $lang) ? (string) $lang : 'en';
    if (!isset($cache[$lang])) {
        $dir = dirname(__DIR__, 2) . '/languages/shop/';
        $strings = array();
        foreach (array_unique(array('en', $lang)) as $code) {
            if (is_file($dir . $code . '.php')) $strings = array_merge($strings, shp_push_lang_file($dir . $code . '.php'));
        }
        $cache[$lang] = $strings;
    }
    $text = (string) ($cache[$lang][$key] ?? $key);
    if (is_array($a)) {
        foreach ($a as $k => $v) $text = str_replace('{$a[' . $k . ']}', (string) $v, $text);
        return $text;
    }
    return $a === null ? $text : str_replace('{$a}', (string) $a, $text);
}

/**
 * Sends one message ($message: title, body, url, tag) to one subscription row. Returns the
 * HTTP status of the push service (0 when nothing could be sent). Keeps the table clean.
 */
function shp_push_send($row, array $message)
{
    $keys = shp_push_keys();
    if (!$keys || !function_exists('curl_init') || !shp_push_endpoint_ok($row->SyEndpoint)) return 0;
    $body = shp_push_encrypt(json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $row->SyP256dh, $row->SyAuth);
    $auth = shp_push_vapid($row->SyEndpoint, $keys);
    if ($body === false || $auth === '') return 0;
    $ch = curl_init((string) $row->SyEndpoint);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => array('Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm',
            'TTL: 3600', 'Urgency: high', 'Authorization: ' . $auth),
    ));
    curl_exec($ch);
    $code = intval(curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
    curl_close($ch);
    $id = intval($row->SyId);
    if ($code >= 200 && $code < 300) {
        safe_w_sql("UPDATE ShopPush SET SyLastOk = UTC_TIMESTAMP(), SyFails = 0 WHERE SyId = $id");
    } elseif ($code === 404 || $code === 410) {
        safe_w_sql("DELETE FROM ShopPush WHERE SyId = $id");
    } else {
        safe_w_sql("UPDATE ShopPush SET SyFails = LEAST(SyFails + 1, 100) WHERE SyId = $id");
        safe_w_sql("DELETE FROM ShopPush WHERE SyId = $id AND SyFails >= 5");
    }
    return $code;
}

/** "Order A-042 ready — go to the bar" to every browser of the order's customer. Never fatal. */
function shp_push_order_ready($orderId)
{
    $o = safe_fetch(safe_w_sql("SELECT ShId, ShTournament, ShStand, ShNumber, ShCustKind, ShLicence, ShGuest FROM ShopOrders WHERE ShId = " . intval($orderId)));
    if (!$o) return 0;
    if ((string) $o->ShCustKind === 'ARCHER' && (string) $o->ShLicence !== '') $who = "SyLicence = " . StrSafe_DB($o->ShLicence);
    elseif ((string) $o->ShCustKind === 'GUEST' && intval($o->ShGuest) > 0) $who = "SyGuest = " . intval($o->ShGuest);
    else return 0;
    $tourId = intval($o->ShTournament);
    $rs = safe_r_sql("SELECT * FROM ShopPush WHERE SyTournament = $tourId AND $who ORDER BY SyId DESC LIMIT " . SHP_PUSH_PER_CUSTOMER, false, true);
    if (!$rs) return 0;
    $rows = array();
    while ($r = safe_fetch($rs)) $rows[] = $r;
    if (!$rows) return 0;
    $stand = shp_stand($o->ShStand);
    $set = shp_settings($tourId);
    $url = $set ? shp_url('public/order.php?k=' . rawurlencode((string) $set->SgPublicKey)) : shp_url('public/');
    $sent = 0;
    foreach ($rows as $r) {
        $message = array(
            'title' => shp_push_text($r->SyLang, 'ShCusNotifTitle') . ' — ' . $o->ShNumber,
            'body' => shp_push_text($r->SyLang, 'ShCusReadyGo', $stand ? (string) $stand->SdName : ''),
            'url' => $url, 'tag' => 'shp-order-' . intval($o->ShId),
        );
        $code = shp_push_send($r, $message);
        if ($code >= 200 && $code < 300) $sent++;
    }
    return $sent;
}
