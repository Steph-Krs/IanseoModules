<?php
/**
 * lib/common.php — what every part of the points of sale needs: settings of a competition, its
 * public key, its date window, amounts, cookies, and the guard of the JSON endpoints.
 *
 * The public and volunteer pages run with $SKIP_AUTH (no ianseo ACL at all): every endpoint
 * calls shp_api_guard() first, then checks who is asking (customer or volunteer) itself.
 *
 * Cross-site requests: the JSON endpoints require a custom header (X-Shp: 1) and a JSON body.
 * A form or a link of another site cannot add such a header, and a script of another site would
 * need a CORS preflight, which shp_api_guard() refuses. This does not depend on the PHP session,
 * which may have expired while a volunteer's phone was asleep.
 */

if (defined('SHP_COMMON_LOADED')) return;
define('SHP_COMMON_LOADED', true);

require_once __DIR__ . '/schema.php';
require_once dirname(__DIR__, 2) . '/booking/lib/archer.php';   // bk_impersonating, bk_ip, bk_current_archer

/** HTML escape. */
function shp_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Address of a page of the points of sale, from the site root. */
function shp_url($path = '')
{
    global $CFG;
    return $CFG->ROOT_DIR . 'Modules/Custom/AUTH/shop/' . ltrim((string) $path, '/');
}

/** Full address (scheme and host), for a QR code. */
function shp_abs_url($path = '')
{
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return (shp_https() ? 'https' : 'http') . '://' . $host . shp_url($path);
}

function shp_https()
{
    // bytes: a server flag, ASCII
    return !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
}

/** SQL subquery giving the time zone of a competition, for bk_local_now_sql(). */
function shp_tz_sql($tourId)
{
    return '(SELECT ToTimeZone FROM Tournament WHERE ToId = ' . intval($tourId) . ')';
}

/** SQL expression: current local date-time of a competition. */
function shp_local_now_sql($tourId)
{
    return bk_local_now_sql(shp_tz_sql($tourId));
}

/* ------------------------------------------------------------------ */
/* Settings of a competition                                           */
/* ------------------------------------------------------------------ */

/** New public key: 10 characters, without the ones read wrongly (0/o, 1/l/i). */
function shp_key_new()
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $max = strlen($alphabet) - 1;   // bytes: ASCII alphabet
    $k = '';
    for ($i = 0; $i < 10; $i++) $k .= $alphabet[random_int(0, $max)];
    return $k;
}

/** Settings row of a competition (object), or null when the shop was never set up there. */
function shp_settings($tourId, $fresh = false)
{
    static $cache = array();
    $tourId = intval($tourId);
    if ($tourId <= 0) return null;
    if ($fresh || !array_key_exists($tourId, $cache)) {
        shp_schema();
        $r = safe_fetch(safe_r_sql("SELECT * FROM ShopSettings WHERE SgTournament = $tourId"));
        $cache[$tourId] = $r ?: null;
    }
    return $cache[$tourId];
}

/** Settings of a competition, created (disabled, with a public key) when missing. */
function shp_settings_ensure($tourId)
{
    $tourId = intval($tourId);
    $s = shp_settings($tourId, true);
    if ($s) return $s;
    if (!safe_fetch(safe_r_sql("SELECT ToId FROM Tournament WHERE ToId = $tourId"))) return null;
    // A key already taken by another competition (very unlikely) is drawn again; the primary
    // key makes a concurrent creation for the same competition harmless.
    for ($i = 0; $i < 5; $i++) {
        safe_w_sql("INSERT IGNORE INTO ShopSettings SET SgTournament = $tourId,
            SgPublicKey = " . StrSafe_DB(shp_key_new()) . ",
            SgCreated = " . shp_local_now_sql($tourId) . ",
            SgUpdated = " . shp_local_now_sql($tourId));
        $s = shp_settings($tourId, true);
        if ($s) return $s;
    }
    return null;
}

/**
 * Saves settings of a competition. Keys of $d (all optional): enabled, desk (check-in desk),
 * guests, tab, trust_gate (booleans), preorder_until ('' = none, 'YYYY-MM-DD HH:MM', the 'T' of a datetime-local input
 * accepted), guest_max_open (0-20), notice. Returns the fresh settings.
 */
function shp_settings_save($tourId, array $d)
{
    $tourId = intval($tourId);
    if (!shp_settings_ensure($tourId)) return null;
    $set = array();
    foreach (array('enabled' => 'SgEnabled', 'desk' => 'SgDesk', 'guests' => 'SgGuests', 'tab' => 'SgTab', 'trust_gate' => 'SgTrustGate') as $k => $col) {
        if (array_key_exists($k, $d)) $set[] = "$col = " . (empty($d[$k]) ? 0 : 1);
    }
    if (array_key_exists('preorder_until', $d)) {
        $v = str_replace('T', ' ', trim((string) $d['preorder_until']));
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/', $v, $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[4] < 24 && (int) $m[5] < 60) {
            $set[] = "SgPreorderUntil = " . StrSafe_DB("$m[1]-$m[2]-$m[3] $m[4]:$m[5]:00");
        } else {
            $set[] = "SgPreorderUntil = NULL";
        }
    }
    if (array_key_exists('guest_max_open', $d)) $set[] = "SgGuestMaxOpen = " . max(0, min(20, intval($d['guest_max_open'])));
    if (array_key_exists('notice', $d)) $set[] = "SgNotice = " . StrSafe_DB(mb_substr(trim((string) $d['notice']), 0, 255));
    if ($set) {
        $set[] = "SgUpdated = " . shp_local_now_sql($tourId);
        safe_w_sql("UPDATE ShopSettings SET " . implode(', ', $set) . " WHERE SgTournament = $tourId");
    }
    return shp_settings($tourId, true);
}

/** Draws a new public key: the printed QR codes stop working (asked by the organiser). */
function shp_settings_new_key($tourId)
{
    $tourId = intval($tourId);
    if (!shp_settings_ensure($tourId)) return '';
    for ($i = 0; $i < 5; $i++) {
        $k = shp_key_new();
        safe_w_sql("UPDATE IGNORE ShopSettings SET SgPublicKey = " . StrSafe_DB($k) . " WHERE SgTournament = $tourId");
        if (safe_w_affected_rows() > 0) { shp_settings($tourId, true); return $k; }
    }
    return '';
}

function shp_enabled($tourId)
{
    $s = shp_settings($tourId);
    return $s && intval($s->SgEnabled) === 1;
}

/** Check-in desk switched on (desk/, lib/desk.php), with or without the stands. */
function shp_desk_on($tourId)
{
    $s = shp_settings($tourId);
    return $s && intval($s->SgDesk ?? 0) === 1;
}

/** Volunteers may join and sign in: the stands or the check-in desk are switched on. */
function shp_staff_on($tourId)
{
    return shp_enabled($tourId) || shp_desk_on($tourId);
}

/** Competition of a public key, or 0. Says nothing about whether the shop is switched on. */
function shp_tour_by_key($key)
{
    $key = (string) $key;
    if (!preg_match('/^[a-z0-9]{10}$/', $key)) return 0;
    shp_schema();
    $r = safe_fetch(safe_r_sql("SELECT SgTournament FROM ShopSettings WHERE SgPublicKey = " . StrSafe_DB($key)));
    return $r ? intval($r->SgTournament) : 0;
}

/**
 * Date window of a competition, in its local time:
 *   from, to      first and last day (YYYY-MM-DD)
 *   today, now    local date and date-time
 *   local_open    volunteers without a licence may join: from the day before to the last day
 *   live          during the competition days
 *   purge_due     from the day after the last day: volunteer accounts and nicknames are erased
 *   preorder_open pre-orders accepted (deadline set and not passed)
 * Null when the competition does not exist.
 */
function shp_window($tourId, $fresh = false)
{
    static $cache = array();
    $tourId = intval($tourId);
    if (!$fresh && isset($cache[$tourId])) return $cache[$tourId];
    shp_schema();
    $now = shp_local_now_sql($tourId);
    $r = safe_fetch(safe_r_sql("SELECT ToWhenFrom, ToWhenTo,
            DATE_SUB(ToWhenFrom, INTERVAL 1 DAY) AS OpenFrom,
            DATE_ADD(ToWhenTo, INTERVAL 1 DAY) AS PurgeFrom,
            $now AS LocalNow, DATE($now) AS Today,
            (SgPreorderUntil IS NOT NULL AND SgPreorderUntil >= $now) AS PreorderOpen
        FROM Tournament
        LEFT JOIN ShopSettings ON SgTournament = ToId
        WHERE ToId = $tourId"));
    if (!$r) return null;
    $from = (string) $r->ToWhenFrom; $to = (string) $r->ToWhenTo; $today = (string) $r->Today;
    $valid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && $from > '0000-00-00'
          && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && $to >= $from;
    // ISO dates compare as strings.
    return $cache[$tourId] = array(
        'from' => $from, 'to' => $to, 'today' => $today, 'now' => (string) $r->LocalNow,
        'local_open' => $valid && $today >= (string) $r->OpenFrom && $today <= $to,
        'live' => $valid && $today >= $from && $today <= $to,
        'purge_due' => $valid && $today >= (string) $r->PurgeFrom,
        'open_from' => (string) $r->OpenFrom, 'purge_from' => (string) $r->PurgeFrom,
        'preorder_open' => (bool) $r->PreorderOpen,
    );
}

/** An amount in the competition's currency, written as the core writes it. */
function shp_money($amount, $tourId)
{
    return bk_eur($amount, false, intval($tourId));
}

/* ------------------------------------------------------------------ */
/* Tokens and cookies                                                  */
/* ------------------------------------------------------------------ */

/** New random token: [token (64 hex), its SHA-256]. Only the hash is ever stored. */
function shp_token_new()
{
    $t = bin2hex(random_bytes(32));
    return array($t, hash('sha256', $t));
}

/** SHA-256 of a token read from a cookie or an address, '' when it is not a token. */
function shp_token_hash($token)
{
    $token = (string) $token;
    return preg_match('/^[0-9a-f]{64}$/', $token) ? hash('sha256', $token) : '';
}

/** Cookies of the points of sale only travel to their own folder. */
function shp_cookie_path()
{
    global $CFG;
    return $CFG->ROOT_DIR . 'Modules/Custom/AUTH/shop/';
}

/**
 * HttpOnly, Secure over HTTPS, SameSite=Lax: Strict would drop the cookie when the phone's
 * camera app opens a QR address, which is exactly how volunteers and customers arrive.
 */
function shp_cookie_set($name, $value, $expires)
{
    $_COOKIE[$name] = (string) $value;
    if (headers_sent()) return false;
    return setcookie($name, (string) $value, array(
        'expires' => intval($expires), 'path' => shp_cookie_path(),
        'secure' => shp_https(), 'httponly' => true, 'samesite' => 'Lax',
    ));
}

function shp_cookie_get($name)
{
    return isset($_COOKIE[$name]) && is_string($_COOKIE[$name]) ? $_COOKIE[$name] : '';
}

function shp_cookie_clear($name)
{
    unset($_COOKIE[$name]);
    if (headers_sent()) return false;
    return setcookie($name, '', array(
        'expires' => 1, 'path' => shp_cookie_path(),
        'secure' => shp_https(), 'httponly' => true, 'samesite' => 'Lax',
    ));
}

/* ------------------------------------------------------------------ */
/* JSON endpoints                                                      */
/* ------------------------------------------------------------------ */

/** The server administrator is looking at someone else's space: read only. */
function shp_impersonating()
{
    return function_exists('bk_impersonating') && bk_impersonating();
}

/**
 * First call of every JSON endpoint of the public and volunteer sides. Refuses a CORS
 * preflight, a request without the X-Shp header, and — for a write — anything but a JSON POST,
 * or any write while the administrator looks at another account. Then releases the PHP session
 * lock (a slow request must not block the other requests of the same phone), unless asked.
 */
function shp_api_guard($write = true, $keepSession = false)
{
    // bytes: HTTP method names are ASCII
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        http_response_code(405);
        exit;
    }
    if ((string) ($_SERVER['HTTP_X_SHP'] ?? '') !== '1') shp_json_error('bad_request', shp_t('ShErrBadRequest'), 400);
    if ($write) {
        if ($method !== 'POST') shp_json_error('bad_request', shp_t('ShErrBadRequest'), 405);
        if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
            shp_json_error('bad_request', shp_t('ShErrBadRequest'), 415);
        }
        if (shp_impersonating()) shp_json_error('read_only', shp_t('ShErrReadOnly'), 403);
    }
    if (!$keepSession && session_status() === PHP_SESSION_ACTIVE) session_write_close();
}

/** Body of a JSON request, as an array (empty when absent or unreadable). */
function shp_json_in()
{
    static $in = null;
    if ($in !== null) return $in;
    $raw = (string) @file_get_contents('php://input', false, null, 0, 65536);
    $d = json_decode($raw, true);
    return $in = is_array($d) ? $d : array();
}

/** Sends a JSON answer (envelope: error = 0 on success) and ends the script. */
function shp_json(array $data)
{
    if (!isset($data['error'])) $data['error'] = 0;
    JsonOut($data);
}

/** Sends a JSON error: error = 1, a machine code for the page's script, a message for people. */
function shp_json_error($code, $msg = '', $http = 200, array $extra = array())
{
    if ($http !== 200 && !headers_sent()) http_response_code(intval($http));
    JsonOut(array_merge($extra, array('error' => 1, 'code' => (string) $code, 'msg' => (string) $msg)));
}

/** Idempotency key sent by a phone: a UUID. */
function shp_idem_ok($s)
{
    return is_string($s) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
}

/** Server-side key, for a write that no phone repeats (tests, migrations). */
function shp_idem_new()
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    // bytes: hexadecimal digits
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}
