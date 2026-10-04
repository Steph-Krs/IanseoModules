<?php
/**
 * lib/ffta.php — sign-in relay to the federation's licensee space (monespace.ffta.fr).
 *
 * SECURITY PRINCIPLE — the link to a licence does NOT come from the typed identifier. The
 * identifier of the licensee space is not always the licence number (it may be a personal
 * identifier chosen by the licensee). The licence is therefore read on the page served AFTER
 * sign-in, that is declared by the federation itself for the open session: the only source a
 * user cannot choose. A licence typed by the archer must NEVER be used to link an account.
 *
 * Corollary: when the licence cannot be read for certain, the sign-in is REFUSED. Better an
 * explicit refusal than an account linked to the wrong archer.
 *
 * This is NOT OAuth: it is a credentials relay (same technique as the AUTH module for
 * dirigeant.ffta.fr, proven in production). The password never leaves the request's memory:
 * never stored, never logged. Long-term advice unchanged: ask the federation for a real OIDC.
 *
 * This file depends on NO other module (AUTH may be absent).
 */

if (defined('BK_FFTA_LOADED')) return;
define('BK_FFTA_LOADED', true);

require_once __DIR__ . '/clock.php';
require_once __DIR__ . '/lang.php';

/** Default base, can be overridden in config.local.json → "sso": {"base": "..."} */
function bk_ffta_base()
{
    $c = bk_local_config();
    $b = $c['sso']['base'] ?? '';
    return $b !== '' ? rtrim($b, '/') : 'https://monespace.ffta.fr';
}

function bk_ffta_enabled()
{
    $c = bk_local_config();
    return !isset($c['sso']['enabled']) || !empty($c['sso']['enabled']);
}

function bk_local_config()
{
    static $cfg = null;
    if (is_null($cfg)) {
        $cfg = array();
        $f = dirname(__DIR__) . '/config.local.json';
        if (is_file($f)) $cfg = json_decode(file_get_contents($f), true) ?: array();
    }
    return $cfg;
}

/* ------------------------------------------------------------------ */
/* Licensee-space session kept (licence certificate)                   */
/*                                                                      */
/* Same principle as AUTH for extranet/dirigeant (FFTA_* session        */
/* convention between modules): the licensee-space session COOKIE opened */
/* at sign-in (never the password) is kept in a 0600 file derived from  */
/* the BK token, destroyed at sign-out. It lets the server relay the PDF */
/* of the certificate without asking for the credentials again; once it */
/* has expired, a direct link is used (the archer signs in to their space). */
/* ------------------------------------------------------------------ */

/** Federation season (1 Sept → 31 Aug, named by its final year). In August → current year. */
function bk_ffta_season()
{
    // Server-zone date: in UTC the season would only roll over at 02:00 on 1 September.
    $d = new DateTime('now', bk_server_tz());
    $y = (int) $d->format('Y');
    return ((int) $d->format('n') >= 9) ? $y + 1 : $y;
}

/** Path of the licensee-space cookie jar, derived from the BK session token (0600). Empty without a session. */
function bk_ffta_cookie_path()
{
    $token = (string) ($_SESSION['BK_Token'] ?? '');
    if ($token === '' || strlen($token) !== 64) return '';
    return sys_get_temp_dir() . '/ffta_esp_' . hash('sha256', 'espace|' . $token) . '.ck';
}

/** Publishes the FFTA_ESPACE_* convention (cookie + base) when the file exists. */
function bk_ffta_espace_publish()
{
    $path = bk_ffta_cookie_path();
    if ($path !== '' && file_exists($path)) {
        $_SESSION['FFTA_ESPACE_COOKIE'] = $path;
        $_SESSION['FFTA_ESPACE_BASE']   = bk_ffta_base();
    } else {
        unset($_SESSION['FFTA_ESPACE_COOKIE'], $_SESSION['FFTA_ESPACE_BASE']);
    }
}

/**
 * Writes the licensee-space session cookie (content returned by bk_ffta_login) into the final
 * file, and remembers the archer's Exalto id. Called AFTER bk_session_open (the path derives
 * from the BK token). The temporary sign-in file is cleaned normally at the end of the request —
 * nothing left behind.
 */
function bk_ffta_espace_store($cookies, $exaltoId, $archerId)
{
    $path = bk_ffta_cookie_path();
    $len  = strlen((string) $cookies);
    if ($path !== '' && (string) $cookies !== '') {
        $w = @file_put_contents($path, (string) $cookies, LOCK_EX);
        @chmod($path, 0600);
        bk_ffta_espace_publish();
        bk_ffta_debug('espace_store: cookie ECRIT (' . var_export($w, true) . ' o) len=' . $len
            . ' exists=' . (file_exists($path) ? '1' : '0'));
    } else {
        bk_ffta_debug('espace_store: NOT WRITTEN path=' . ($path !== '' ? 'ok' : 'VIDE(BK_Token?)') . ' cookies_len=' . $len);
    }
    $exalto = preg_replace('/\D/', '', (string) $exaltoId);
    bk_ffta_debug('espace_store: exaltoId=' . ($exalto !== '' ? $exalto : 'EMPTY') . ' archer=' . intval($archerId));
    if ($exalto !== '' && intval($archerId) > 0) {
        safe_w_sql("UPDATE BK_Archers SET BaExaltoId = " . StrSafe_DB($exalto) . " WHERE BaId = " . intval($archerId));
    }
}

/** Sign-out: destroys the kept licensee-space cookie. */
function bk_ffta_espace_forget()
{
    $path = bk_ffta_cookie_path();
    if ($path !== '' && file_exists($path)) @unlink($path);
    unset($_SESSION['FFTA_ESPACE_COOKIE'], $_SESSION['FFTA_ESPACE_BASE']);
}

/** URL of the PDF licence certificate (…/pdf/p/{idExalto}/{season}). Empty when the id is missing. */
function bk_ffta_attestation_url($exaltoId, $season = null)
{
    $exaltoId = preg_replace('/\D/', '', (string) $exaltoId);
    if ($exaltoId === '') return '';
    if ($season === null) $season = bk_ffta_season();
    return bk_ffta_base() . '/licences/attestations/pdf/p/' . $exaltoId . '/' . intval($season);
}

/**
 * Loads the kept cookies into a curl handle (one Netscape line per cookie, as given by
 * CURLINFO_COOKIELIST). CURLOPT_COOKIEFILE (file read) is NOT used: the curl build that cannot
 * WRITE the jar may also read it badly. The cookie engine is turned on (COOKIEFILE='') then fed
 * cookie by cookie. Returns the number of cookies loaded.
 */
function bk_ffta_cookie_load($ch, $path)
{
    $blob = ($path !== '' && is_file($path)) ? (string) @file_get_contents($path) : '';
    if (trim($blob) === '') return 0;
    curl_setopt($ch, CURLOPT_COOKIEFILE, '');   // turns the engine on, without a file
    $n = 0;
    foreach (explode("\n", $blob) as $line) {
        $line = rtrim($line, "\r");
        if (trim($line) === '') continue;
        curl_setopt($ch, CURLOPT_COOKIELIST, $line);   // also handles the #HttpOnly_ prefix
        $n++;
    }
    return $n;
}

/**
 * Fetches a PDF of the licensee space through the KEPT session cookie (loaded into the engine,
 * never written back → the stored session is not changed).
 * Returns ['pdf'=>bytes] when a real PDF comes back, otherwise ['expired'=>true] (no cookie,
 * session expired, or HTML sign-in page returned).
 */
function bk_ffta_fetch_pdf($url)
{
    $path = bk_ffta_cookie_path();
    if ($path === '' || !is_file($path) || !function_exists('curl_init') || (string) $url === '') {
        bk_ffta_debug('fetch_pdf: no relay — cookie_path=' . ($path !== '' ? 'ok' : 'EMPTY')
            . ' cookie_exists=' . (($path !== '' && is_file($path)) ? '1' : '0') . ' url=' . ($url !== '' ? 'ok' : 'EMPTY'));
        return array('expired' => true);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ianseo-booking)',
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
    ));
    $nck = bk_ffta_cookie_load($ch, $path);
    if ($nck === 0) { curl_close($ch); bk_ffta_debug('fetch_pdf: 0 cookie loaded'); return array('expired' => true); }
    $body  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $eff   = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    $isPdf = ($body !== false && $code === 200)
        && ((stripos($ctype, 'pdf') !== false) || (substr((string) $body, 0, 4) === '%PDF'));
    bk_ffta_debug('fetch_pdf: cookies=' . $nck . ' http=' . $code . ' ctype=' . $ctype . ' url_finale=' . $eff
        . ' taille=' . (is_string($body) ? strlen($body) : 'false') . ' => ' . ($isPdf ? 'PDF OK' : 'NOT a PDF (fallback)'));
    return $isPdf ? array('pdf' => $body) : array('expired' => true);
}

/**
 * Resolves the Exalto id ON DEMAND through the kept cookie (GET /licences, read only). Used when
 * the id was not captured at sign-in (account signed in before the feature, or charter already
 * accepted) while the licensee-space session is still valid. Empty on failure.
 */
function bk_ffta_resolve_exalto()
{
    $path = bk_ffta_cookie_path();
    if ($path === '' || !is_file($path) || !function_exists('curl_init')) return '';
    $ch = curl_init(bk_ffta_base() . '/licences');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ianseo-booking)',
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
    ));
    $nck = bk_ffta_cookie_load($ch, $path);
    $body = ($nck > 0) ? curl_exec($ch) : '';
    bk_ffta_debug('resolve_exalto: cookies=' . $nck . ' http=' . curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);
    return bk_ffta_extract_exalto((string) $body);
}

/**
 * Exalto id from a page of the licensee space. Two sources, by decreasing reliability:
 *  1) the CERTIFICATE LINK itself (…/attestations/pdf/p/{id}/{season}) — always on the "Mes
 *     licences" tab, the reference source;
 *  2) the script "const personne_id = '…'" of the charter acceptance banner — only there while
 *     the charter is not accepted (fallback, disappears afterwards).
 * Empty when neither answers.
 */
function bk_ffta_extract_exalto($html)
{
    $html = (string) $html;
    if (preg_match('#/attestations/pdf/p/(\d{2,12})/#', $html, $m)) return $m[1];
    if (preg_match('/personne_id\s*=\s*[\'"](\d{2,12})[\'"]/', $html, $m)) return $m[1];
    return '';
}

/* ------------------------------------------------------------------ */
/* Debugging (off by default)                                          */
/* ------------------------------------------------------------------ */

/**
 * Turned on WITHOUT touching the code: create the empty file
 * Modules/Custom/AUTH/booking/ffta-debug.on (or "sso":{"debug":true} in config.local.json).
 * Essential the day the federation changes its pages. NEVER logs a password nor an MFA code —
 * only metadata.
 */
function bk_ffta_debug_enabled()
{
    static $on = null;
    if (is_null($on)) {
        $c = bk_local_config();
        $on = is_file(dirname(__DIR__) . '/ffta-debug.on') || !empty($c['sso']['debug']);
    }
    return $on;
}

function bk_ffta_debug($msg)
{
    if (!bk_ffta_debug_enabled()) return;
    @file_put_contents(dirname(__DIR__) . '/ffta-debug.log',
        date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

/** Metadata of a page (type, form, fields) — never its content. */
function bk_ffta_debug_page($html)
{
    if (!bk_ffta_debug_enabled()) return '';
    $html = (string) $html;
    $kind = 'unknown';
    if (preg_match('#/auth/two-factor-challenge#i', $html) ||
        preg_match('/(two[-_]?factor|deux.?[ée]tapes|double.?authentification)/i', $html)) {
        $kind = 'mfa-challenge';
    } elseif (preg_match('#name="password"#i', $html) && preg_match('#name="username"#i', $html)) {
        $kind = 'login-form';
    } elseif (trim($html) !== '') {
        $kind = 'signed-in-page?';
    }
    $action = '';
    if (preg_match('#<form[^>]*action=["\']([^"\']+)["\']#i', $html, $m)) $action = $m[1];
    $names = array();
    if (preg_match_all('#<input\b[^>]*name=["\']([^"\']+)["\']#i', $html, $mm)) {
        $names = array_slice(array_unique($mm[1]), 0, 12);
    }
    return 'type=' . $kind . ' len=' . strlen($html)
        . ' action=' . $action . ' fields=' . implode(',', $names);
}

/* ------------------------------------------------------------------ */
/* Licensee space unavailable (maintenance, outage)                   */
/* ------------------------------------------------------------------ */
/* Same fix as on the AUTH side (aut_ffta_outage), duplicated on purpose: this side runs with
 * $SKIP_AUTH, AUTH's lib.php is not guaranteed here. To merge the day the federation base moves
 * to _shared/.
 * Without it, a federation maintenance sends the user back to /auth/login and the module says
 * "Wrong identifier or password" — the archer looks for the mistake on their side although
 * nothing could be checked.                                           */

function bk_ffta_fold($s)
{
    $s = mb_strtolower((string) $s, 'UTF-8');
    return strtr($s, array('é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','ä'=>'a',
                           'î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c'));
}

/** Does the page carry a usable sign-in form? */
function bk_ffta_is_login_page($html)
{
    return (bool) preg_match('/(name|id)=["\']password["\']|type=["\']password["\']/i', (string) $html);
}

/**
 * '' when the answer is usable, otherwise a displayable message.
 * ⚠ Never call on a signed-in page (`bk_ffta_is_connected`): the word "maintenance" may appear
 * on a healthy page, and a false positive would refuse a valid sign-in.
 */
function bk_ffta_outage($ch, $html, $expected = '')
{
    $space = bk_t('FfSpace');
    $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    if ($code >= 500 || $code == 429 || $code == 408) return bk_ffta_outage_msg($space, $code);

    if (!bk_ffta_is_login_page($html)) {
        $t = bk_ffta_fold($html);
        foreach (array(
            'be right back',                      // Laravel's default 503 page
            'service unavailable', 'temporarily unavailable', 'web server is down',
            'en maintenance', 'maintenance en cours', 'maintenance planifiee',
            'momentanement indisponible', 'temporairement indisponible',
            'site indisponible', 'service indisponible',
        ) as $m) {
            if (strpos($t, $m) !== false) return bk_ffta_outage_msg($space, $code);
        }
        if ($expected === 'login') return bk_ffta_outage_msg($space, $code, true);
    }
    return '';
}

function bk_ffta_outage_msg($space, $code, $unexpected = false)
{
    $tail = ' ' . bk_t('FfNotYourFault');
    if ($unexpected) return bk_t('FfNoLoginPage', $space) . $tail;
    if ($code == 429) return bk_t('FfRateLimited', $space) . $tail;
    if ($code >= 400) {
        return bk_t($code == 503 ? 'FfMaintenanceHttp' : 'FfDownHttp', array('space' => $space, 'code' => $code)) . $tail;
    }
    // Detected by the CONTENT (200 + maintenance page): "HTTP 200" would confuse.
    return bk_t('FfMaintenancePage', $space) . $tail;
}

/* ------------------------------------------------------------------ */
/* Sign-in relay                                                       */
/* ------------------------------------------------------------------ */

/**
 * Tries to sign a licensee in to the federation's licensee space.
 *
 * $identifier: what the archer types — licence number OR personal identifier. Only used to
 * sign in, NEVER to link the account.
 *
 * Returns an array:
 *   ['ok' => true, 'licence' => '0000001B', 'displayName' => 'M NAME Given']
 *   ['ok' => false, 'err' => <code>, 'msg' => <displayable message>]
 * Error codes: NETWORK, UNAVAILABLE (federation maintenance/outage), NO_CSRF, BAD_CREDENTIALS,
 * MFA_NEEDED, MFA_BAD_CODE, NO_LICENCE, AMBIGUOUS_LICENCE.
 *
 * $otp: two-factor authentication code, empty when not asked.
 */
function bk_ffta_login($identifier, $password, $otp = '')
{
    $base = bk_ffta_base();

    $cookieFile = tempnam(sys_get_temp_dir(), 'bk_ck_');
    @chmod($cookieFile, 0600);
    register_shutdown_function(function () use ($cookieFile) {
        if (file_exists($cookieFile)) @unlink($cookieFile);
    });

    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ianseo-booking)',
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ));

    bk_ffta_debug('--- login identifier=' . $identifier . ' otp=' . ($otp !== '' ? 'given' : 'empty') . ' ---');

    // 1) Sign-in page: the Laravel CSRF token is fetched.
    curl_setopt($ch, CURLOPT_URL, $base . '/auth/login');
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    $loginPage = curl_exec($ch);
    bk_ffta_debug('GET /auth/login http=' . curl_getinfo($ch, CURLINFO_HTTP_CODE)
        . ' url=' . curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) . ' ' . bk_ffta_debug_page($loginPage));

    if (!$loginPage || curl_errno($ch)) {
        $e = curl_error($ch);
        curl_close($ch);
        bk_ffta_debug('=> injoignable : ' . $e);
        return array('ok' => false, 'err' => 'NETWORK',
            'msg' => bk_t('FfUnreachable'));
    }

    if (($out = bk_ffta_outage($ch, $loginPage, 'login')) !== '') {
        curl_close($ch);
        bk_ffta_debug('=> unavailability detected on GET /auth/login');
        return array('ok' => false, 'err' => 'UNAVAILABLE', 'msg' => $out);
    }

    $csrf = bk_ffta_csrf($loginPage);
    if (!$csrf) {
        curl_close($ch);
        bk_ffta_debug('=> CSRF NOT FOUND');
        return array('ok' => false, 'err' => 'NO_CSRF',
            'msg' => bk_t('FfPageChanged'));
    }

    // 2) POST of the credentials.
    $post = array('_token' => $csrf, 'username' => $identifier, 'password' => $password);
    curl_setopt_array($ch, array(
        CURLOPT_URL        => $base . '/auth/login',
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
    ));
    $landing = curl_exec($ch);
    $post = null;                       // the password does not outlive the call
    $effUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    bk_ffta_debug('POST /auth/login http=' . curl_getinfo($ch, CURLINFO_HTTP_CODE)
        . ' url=' . $effUrl . ' ' . bk_ffta_debug_page($landing));

    if ($landing === false || curl_errno($ch)) {
        curl_close($ch);
        return array('ok' => false, 'err' => 'NETWORK',
            'msg' => bk_t('FfInterrupted'));
    }

    // Success: the landing page carries the "Me déconnecter" link (checked on a real home page
    // of the licensee space). This POSITIVE marker is safer than the URL heuristic inherited
    // from AUTH, kept as a fallback should the page change.
    $signedIn = bk_ffta_is_connected($landing);
    if (!$signedIn && ($out = bk_ffta_outage($ch, $landing)) !== '') {
        curl_close($ch);
        bk_ffta_debug('=> unavailability detected on POST /auth/login');
        return array('ok' => false, 'err' => 'UNAVAILABLE', 'msg' => $out);
    }

    $stillOnLogin = !$signedIn && (strpos($effUrl, '/auth/login') !== false);
    $isMfa = bk_ffta_is_mfa($landing, $effUrl);

    if ($isMfa) {
        if ($otp === '') {
            curl_close($ch);
            bk_ffta_debug('=> MFA challenge, no code typed');
            return array('ok' => false, 'err' => 'MFA_NEEDED',
                'msg' => bk_t('FfMfaNeeded'));
        }
        $landing = bk_ffta_mfa_step2($ch, $landing, $otp, $base);
        $effUrl  = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        if (!bk_ffta_is_connected($landing) && ($out = bk_ffta_outage($ch, $landing)) !== '') {
            curl_close($ch);
            bk_ffta_debug('=> unavailability detected after the 2nd MFA step');
            return array('ok' => false, 'err' => 'UNAVAILABLE', 'msg' => $out);
        }
        if (bk_ffta_is_mfa($landing, $effUrl)) {
            curl_close($ch);
            bk_ffta_debug('=> MFA code refused');
            return array('ok' => false, 'err' => 'MFA_BAD_CODE',
                'msg' => bk_t('FfMfaBad'));
        }
        $stillOnLogin = !bk_ffta_is_connected($landing) && (strpos($effUrl, '/auth/login') !== false);
        bk_ffta_debug('=> second MFA step accepted');
    }

    if ($stillOnLogin) {
        // Back on /auth/login WITHOUT a form: Laravel shows the form again when the password is
        // refused — anything else is not a credentials refusal (error page, maintenance,
        // portal).
        if (!bk_ffta_is_login_page($landing)) {
            $msg = bk_ffta_outage_msg(bk_t('FfSpace'),
                intval(curl_getinfo($ch, CURLINFO_HTTP_CODE)), true);
            curl_close($ch);
            bk_ffta_debug('=> back on /auth/login without a form: unavailability likely');
            return array('ok' => false, 'err' => 'UNAVAILABLE', 'msg' => $msg);
        }
        curl_close($ch);
        bk_ffta_debug('=> credentials refused');
        return array('ok' => false, 'err' => 'BAD_CREDENTIALS',
            'msg' => bk_t('FfBadCredentials'));
    }

    bk_ffta_debug('=> sign-in accepted, resolving the licence');

    // 3) The licence is resolved ON the open session. This is the link: only the federation
    //    decides which licence it is.
    $found = bk_ffta_extract_licences($landing);
    $page  = (string) $landing;

    // The landing page may be an intermediate redirection without identity: the home page of
    // the licensee space is then tried explicitly.
    if (count($found) !== 1) {
        bk_ffta_debug('licence not resolved on the landing page (' . count($found)
            . ' candidate(s)) — trying the home page');
        curl_setopt_array($ch, array(
            CURLOPT_URL => $base . '/', CURLOPT_HTTPGET => true, CURLOPT_POST => false,
        ));
        $home = curl_exec($ch);
        bk_ffta_debug('GET / http=' . curl_getinfo($ch, CURLINFO_HTTP_CODE)
            . ' url=' . curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) . ' ' . bk_ffta_debug_page($home));
        if ($home && bk_ffta_is_connected($home)) {
            $f2 = bk_ffta_extract_licences($home);
            if (count($f2) === 1) { $found = $f2; $page = (string) $home; }
            elseif (!$found)      { $found = $f2; $page = (string) $home; }
        }
    }

    // Exalto id (for the licence certificate) — only on the success path (licence resolved).
    // First on the signed-in page; when missing (charter already accepted), a request on the
    // "Mes licences" tab where the certificate link is always present.
    $exalto = '';
    $cookies = '';
    if (count($found) === 1) {
        $exalto = bk_ffta_extract_exalto($page);
        if ($exalto === '') {
            curl_setopt_array($ch, array(CURLOPT_URL => $base . '/licences', CURLOPT_HTTPGET => true, CURLOPT_POST => false));
            $lp = curl_exec($ch);
            bk_ffta_debug('GET /licences http=' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ' (resolving the Exalto id)');
            $exalto = bk_ffta_extract_exalto((string) $lp);
        }
        // Session cookies through the curl ENGINE (CURLINFO_COOKIELIST), one per line.
        // ⚠️ Writing the cookie jar to a FILE is broken on curl 8.x Windows (0-byte jar); the
        // engine does return the cookies — ffta_session (HttpOnly) included. This is what
        // makes the certificate relay possible despite that build bug.
        $cl = curl_getinfo($ch, CURLINFO_COOKIELIST);
        $cookies = is_array($cl) ? implode("\n", $cl) : '';
        bk_ffta_debug('cookies captured (engine): ' . (is_array($cl) ? count($cl) : 0) . ' entries');
    }

    curl_close($ch);

    if (!$found) {
        bk_ffta_debug('=> NO readable licence: refused');
        return array('ok' => false, 'err' => 'NO_LICENCE',
            'msg' => bk_t('FfNoLicence'));
    }
    if (count($found) > 1) {
        // Several distinct licences: impossible to decide without risking linking the account
        // to the wrong archer. Refused.
        bk_ffta_debug('=> AMBIGUOUS licences (' . implode(',', $found) . '): refused');
        return array('ok' => false, 'err' => 'AMBIGUOUS_LICENCE',
            'msg' => bk_t('FfAmbiguous'));
    }

    $lic = $found[0];
    bk_ffta_debug('=> licence resolved: ' . $lic);
    // The session cookie (for the certificate) and the Exalto id were captured above, on the
    // success path. The password was never kept.
    return array('ok' => true, 'licence' => $lic, 'displayName' => bk_ffta_extract_name($page),
        'exaltoId' => $exalto, 'cookies' => $cookies);
}

/**
 * Name shown on the signed-in page (e.g. "M NAME Given"), used as a consistency check against
 * the licence file. Empty string when missing.
 */
function bk_ffta_extract_name($html)
{
    $html = (string) $html;
    // Fiche profil : <h6 …>M NOM Prenom<small>…
    if (preg_match('#<h6[^>]*>\s*([^<]{3,80}?)\s*<#u', $html, $m)) {
        $n = trim(preg_replace('/\s+/', ' ', $m[1]));
        if ($n !== '') return $n;
    }
    // Navigation bar: text before the licence badge
    if (preg_match('#>\s*([^<>]{3,80}?)\s*<span[^>]*class=["\'][^"\']*\bbadge\b#u', $html, $m)) {
        $n = trim(preg_replace('/\s+/', ' ', $m[1]));
        if ($n !== '') return $n;
    }
    return '';
}

/** Laravel CSRF token, looked for in the form then in the meta tag. */
function bk_ffta_csrf($html)
{
    foreach (array(
        '/<input[^>]+name=["\']_token["\'][^>]+value=["\']([^"\']+)["\']/',
        '/name=["\']csrf-token["\'][^>]*content=["\']([^"\']+)["\']/',
        '/content=["\']([^"\']+)["\'][^>]*name=["\']csrf-token["\']/',
    ) as $p) {
        if (preg_match($p, (string) $html, $m)) return $m[1];
    }
    return null;
}

/**
 * MFA challenge page (Laravel Fortify)?
 *
 * ⚠️ Trap checked on a real page: the home page of the licensee space shows a "2FA" badge and
 * the text "Authentification deux facteurs non confirmée" when two-factor authentication is
 * NOT on. A plain keyword detection would take a SIGNED-IN page for an MFA challenge, and the
 * sign-in would fail for accounts without 2FA — the majority. Hence:
 *  1. a page carrying the sign-out link is signed in, never a challenge;
 *  2. the pattern leaves out "2fa", "otp" and "deux facteurs" on purpose, too common in the
 *     site's usual layout.
 */
function bk_ffta_is_mfa($html, $url = '')
{
    $html = (string) $html;
    if (bk_ffta_is_connected($html)) return false;
    if (strpos((string) $url, 'two-factor') !== false) return true;
    return (bool) preg_match('#(two[-_]?factor[-_]?challenge|deux.?[ée]tapes|code.?de.?v[ée]rification)#i', $html);
}

/**
 * Second MFA step: sends the code to the challenge form, discovering its action and the name of
 * its field. 'recovery_code' is explicitly LEFT OUT (backup codes, not the app's code). The code
 * is never logged.
 */
function bk_ffta_mfa_step2($ch, $page, $otp, $base)
{
    $csrf = bk_ffta_csrf($page);

    $action = $base . '/auth/two-factor-challenge';
    if (preg_match('#<form[^>]*action=["\']([^"\']+)["\']#i', $page, $m) && $m[1] !== '') {
        $action = (strpos($m[1], 'http') === 0) ? $m[1] : $base . '/' . ltrim($m[1], '/');
    }

    $names = array();
    if (preg_match_all('#<input\b[^>]*>#i', $page, $inp)) {
        foreach ($inp[0] as $tag) {
            if (preg_match('/type=["\'](hidden|submit|password|checkbox|radio)["\']/i', $tag)) continue;
            if (preg_match('/name=["\']([^"\']+)["\']/', $tag, $mm)) $names[] = $mm[1];
        }
    }
    $field = '';
    foreach (array('code', 'otp', 'two_factor_code', 'authenticator_code', 'pin') as $cand) {
        if (in_array($cand, $names, true)) { $field = $cand; break; }
    }
    if ($field === '') {
        foreach ($names as $n) {
            if (stripos($n, 'recovery') !== false || strtolower($n) === '_token') continue;
            if (preg_match('/(code|otp|2fa|pin|digit|chiffre)/i', $n)) { $field = $n; break; }
        }
    }
    if ($field === '') $field = 'code';

    bk_ffta_debug('MFA step2 action=' . $action . ' field=' . $field . ' csrf=' . ($csrf ? 'yes' : 'no'));

    $post = array($field => $otp);
    if ($csrf) $post['_token'] = $csrf;
    curl_setopt_array($ch, array(
        CURLOPT_URL        => $action,
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
    ));
    $res = curl_exec($ch);
    $post = null;
    bk_ffta_debug('MFA step2 POST http=' . curl_getinfo($ch, CURLINFO_HTTP_CODE)
        . ' url=' . curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) . ' ' . bk_ffta_debug_page($res));
    return (string) $res;
}

/**
 * Defence in depth: the name shown by the federation must match the licence file's record for
 * the resolved licence. Catches a wrong licence read, which would link the account to the wrong
 * archer.
 *
 * Tolerant by design: returns true when the name could not be read (no blocking on an unknown
 * page structure); false only when the family name of the federation file is entirely missing
 * from the name shown.
 */
function bk_ffta_name_matches($displayName, $lue)
{
    if ($displayName === '' || !$lue) return true;
    $shown  = bk_fold($displayName);
    $family = bk_fold($lue->LueFamilyName);
    if ($family === '' || $shown === '') return true;
    $ok = (strpos($shown, $family) !== false);
    bk_ffta_debug('name check: shown=' . $displayName . ' file=' . $lue->LueFamilyName
        . ' => ' . ($ok ? 'OK' : 'CONTRADICTION'));
    return $ok;
}

/**
 * POSITIVE marker of an open session: the "Me déconnecter" link of the licensee space. Checked
 * on a real home page. Never settle for "the page is not the sign-in form": an error page or an
 * intermediate redirection would pass that test.
 */
function bk_ffta_is_connected($html)
{
    return (bool) preg_match('#/auth/logout#i', (string) $html);
}

/**
 * Extracts the licence numbers of a page of the licensee space.
 * Federation format: 6 to 7 digits followed by a key letter (e.g. 0000001B).
 *
 * ⚠️ SECURITY function: it decides which archer an account is linked to (the typed identifier
 * does not say — it may be a personal one). It must therefore give a CERTAIN answer or none:
 * the caller refuses the sign-in unless it gets exactly one licence.
 *
 * Patterns by decreasing reliability, taken from a real home page:
 *   1. profile card: "Licencié N°0000001B"
 *   2. nav bar     : <span class="badge …">0000001B</span>
 *   3. fallback    : any licence pattern of the page
 * Stops at the FIRST pattern that answers: the first two name the session's holder explicitly,
 * whereas the fallback would also pick a licence quoted elsewhere on the page (another archer,
 * an example…).
 *
 * The format naturally excludes a club approval number (`LLDDCCC`, 7 digits WITHOUT a final
 * letter) and its Corsican variant (`052A005`).
 */
function bk_ffta_extract_licences($html)
{
    $html = (string) $html;
    $out = array();

    foreach (array(
        '/N[°ºo]\s*(\d{6,7}[A-Za-z])\b/u',
        '/<span[^>]*class=["\'][^"\']*\bbadge\b[^"\']*["\'][^>]*>\s*(\d{6,7}[A-Za-z])\s*</i',
        '/\b(\d{6,7}[A-Za-z])\b/',
    ) as $p) {
        if (preg_match_all($p, $html, $m)) {
            foreach ($m[1] as $v) $out[strtoupper($v)] = true;
            break;
        }
    }
    return array_keys($out);
}
