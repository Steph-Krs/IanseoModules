<?php
/**
 * lib/archer.php — licensee accounts: identity, sessions.
 *
 * Model taken from Modules/Custom/AUTH (never included: the modules stay standalone): the PHP
 * session only carries a random TOKEN, of which only the SHA-256 hash is stored — a session
 * dump gives no reusable secret. Expiries computed in SQL (NOW()): ianseo changes the MySQL
 * time_zone per competition, never compare with PHP's time().
 *
 * Empty BaPassword = SSO account (sentinel, sign-in relayed to the licensee space): such an
 * account can NOT sign in with a password.
 */

if (defined('BK_ARCHER_LOADED')) return;
define('BK_ARCHER_LOADED', true);

require_once __DIR__ . '/schema.php';

define('BK_SESSION_IDLE_H', 12);   // hours of inactivity before expiry
define('BK_SESSION_ABS_D',  7);    // absolute lifetime in days
define('BK_MAX_LOGIN_FAIL', 8);    // sign-in failures / 15 min
define('BK_MAX_IDENT_FAIL', 10);   // identification failures / 15 min

/* ------------------------------------------------------------------ */
/* Log & rate limiting                                                 */
/* ------------------------------------------------------------------ */

function bk_ip()
{
    return mb_substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
}

function bk_log($event, $user = '')
{
    bk_schema();
    safe_w_sql("INSERT INTO BookingLog (BlEvent, BlUser, BlIP) VALUES ("
        . StrSafe_DB(mb_substr($event, 0, 32)) . ","
        . StrSafe_DB(mb_substr($user, 0, 64)) . ","
        . StrSafe_DB(bk_ip()) . ")");
}

/**
 * Counts the recent failures of a family of events. The filter is on the IP OR the identifier:
 * an attacker changing licence at each try is stopped by the IP, a distributed stuffing on one
 * licence by the identifier.
 */
function bk_too_many($events, $max, $user = '')
{
    bk_schema();
    $in = implode(',', array_map('StrSafe_DB', (array) $events));
    $q = safe_r_sql("SELECT COUNT(*) AS n FROM BookingLog
        WHERE BlEvent IN ($in)
          AND BlWhen > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
          AND (BlIP = " . StrSafe_DB(bk_ip()) . " OR BlUser = " . StrSafe_DB($user) . ")");
    $r = safe_fetch($q);
    return $r && intval($r->n) >= $max;
}

/* ------------------------------------------------------------------ */
/* Licensee base (LookUpEntries, filled by the federation sync)        */
/* ------------------------------------------------------------------ */

/** Normalises a licence number (spaces, case). */
function bk_clean_licence($licence)
{
    // bytes: licence numbers and club codes are ASCII letters and digits
    return strtoupper(preg_replace('/\s+/', '', (string) $licence));
}

/** Tolerant name comparison (case, accents, hyphens, spaces). */
function bk_fold($s)
{
    $s = (string) $s;
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($t !== false) $s = $t;
    }
    return preg_replace('/[^A-Z0-9]/', '', strtoupper($s));
}

/**
 * Record of a licensee in the federation file, by licence number.
 *
 * The identity is proven beforehand by the sign-in to the licensee space: this read no longer
 * authenticates, it only fills in name, given name and club.
 *
 * LookUpEntries naming reminder (checked on the real base): LueFamilyName = FAMILY name,
 * LueName = given name, LueCtrlCode = date of birth, LueCountry = club approval number
 * (LLDDCCC).
 */
function bk_lookup_licence($licence)
{
    $licence = bk_clean_licence($licence);
    if ($licence === '') return null;

    $q = safe_r_sql("SELECT LueCode, LueFamilyName, LueName, LueCtrlCode, LueSex,
                LueCountry, LueCoDescr, LueDivision, LueClass, LueSubClass,
                LueStatus, LueStatusValidUntil, LueIocCode
        FROM LookUpEntries
        WHERE LueCode = " . StrSafe_DB($licence) . "
        ORDER BY LueDefault DESC
        LIMIT 1");
    return safe_fetch($q) ?: null;
}

/* ------------------------------------------------------------------ */
/* Comptes                                                             */
/* ------------------------------------------------------------------ */

function bk_get_archer_by_licence($licence)
{
    bk_schema();
    $q = safe_r_sql("SELECT * FROM BookingArchers WHERE BaLicence = " . StrSafe_DB(bk_clean_licence($licence)));
    return safe_fetch($q) ?: null;
}

function bk_get_archer($id)
{
    bk_schema();
    $q = safe_r_sql("SELECT * FROM BookingArchers WHERE BaId = " . intval($id));
    return safe_fetch($q) ?: null;
}

/**
 * Creates (or refreshes) the account of a licensee whose identity was just proven by the
 * licensee space.
 *
 * BaPassword ALWAYS stays empty: this module handles no password; the account's security is
 * that of the licensee space. Same sentinel as AuthUsers.AuPassword in AUTH.
 *
 * Name, given name and club are realigned on the federation file at every sign-in — an archer
 * changes club between two seasons.
 *
 * Returns the account id, or 0 on failure.
 */
function bk_provision_archer($lue)
{
    bk_schema();
    $lic = bk_clean_licence($lue->LueCode);

    $set = "BaFamilyName = " . StrSafe_DB($lue->LueFamilyName)
         . ", BaName = "     . StrSafe_DB($lue->LueName)
         . ", BaClubCode = " . StrSafe_DB($lue->LueCountry);

    $a = bk_get_archer_by_licence($lic);
    if ($a) {
        safe_w_sql("UPDATE BookingArchers SET $set WHERE BaId = " . intval($a->BaId));
        return intval($a->BaId);
    }

    safe_w_sql("INSERT INTO BookingArchers SET BaLicence = " . StrSafe_DB($lic)
        . ", BaPassword = '', $set");
    $a = bk_get_archer_by_licence($lic);
    return $a ? intval($a->BaId) : 0;
}

/* ------------------------------------------------------------------ */
/* Token sessions                                                      */
/* ------------------------------------------------------------------ */

function bk_session_open($archer)
{
    $token = bin2hex(random_bytes(32));
    safe_w_sql("INSERT INTO BookingSessions (BkArcher, BkTokenHash, BkIP, BkUA) VALUES ("
        . intval($archer->BaId) . ",'" . hash('sha256', $token) . "',"
        . StrSafe_DB(bk_ip()) . ","
        . StrSafe_DB(mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 160)) . ")");
    safe_w_sql("DELETE FROM BookingSessions WHERE BkLastSeen < DATE_SUB(NOW(), INTERVAL 30 DAY)");

    safe_w_sql("UPDATE BookingArchers SET BaLastLogin = NOW() WHERE BaId = " . intval($archer->BaId));

    $_SESSION['BK_Token'] = $token;
}

function bk_current_token_hash()
{
    $t = (string) ($_SESSION['BK_Token'] ?? '');
    // bytes: tokens and hashes are ASCII hex
    return ($t !== '' && strlen($t) === 64) ? hash('sha256', $t) : '';
}

function bk_sessions_revoke($archerId, $exceptTokenHash = null)
{
    $sql = "DELETE FROM BookingSessions WHERE BkArcher = " . intval($archerId);
    if ($exceptTokenHash && preg_match('/^[0-9a-f]{64}$/', $exceptTokenHash)) {
        $sql .= " AND BkTokenHash != '$exceptTokenHash'";
    }
    safe_w_sql($sql);
}

/**
 * "From another account" view on the ARCHER side (server admin, READ ONLY).
 * Reads the session MIRROR set by AUTH (session convention — no call to or require of AUTH, as
 * the modules stay independent): this flag is only written by the admin page
 * (admin/impersonate.php), and the observer (AUTH_User) must still be its author. Returns the
 * flag or null.
 */
function bk_impersonating()
{
    $i = $_SESSION['AUTH_IMPERSONATE'] ?? null;
    if (!is_array($i) || ($i['type'] ?? '') !== 'archer') return null;
    $by = (string) ($i['by'] ?? '');
    if ($by === '' || $by !== (string) ($_SESSION['AUTH_User'] ?? '')) return null;
    return $i;
}

/**
 * The connected licensee, or null. Checks the token again at every call (the account may have
 * been disabled or the session revoked between two requests) and refreshes BkLastSeen at most
 * once a minute.
 */
function bk_current_archer()
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = null;

    // Admin observation: returns the target archer loaded by id, without BookingSessions. Writes
    // are blocked upstream (public/boot.php).
    $imp = bk_impersonating();
    if ($imp) {
        bk_schema();
        $q = safe_r_sql("SELECT BookingArchers.* FROM BookingArchers WHERE BaId=" . intval($imp['archer']), false, true);
        $r = $q ? safe_fetch($q) : null;
        if ($r) { $r->BK_IMP = 1; $cache = $r; }
        return $cache;
    }

    $hash = bk_current_token_hash();
    if ($hash === '') return null;

    bk_schema();
    $q = safe_r_sql("SELECT BookingArchers.*, BkId, BkLastSeen,
            (BkCreated  < DATE_SUB(NOW(), INTERVAL " . BK_SESSION_ABS_D . " DAY)
          OR BkLastSeen < DATE_SUB(NOW(), INTERVAL " . BK_SESSION_IDLE_H . " HOUR)) AS expired,
            (BkLastSeen < DATE_SUB(NOW(), INTERVAL 1 MINUTE)) AS stale
        FROM BookingSessions
        INNER JOIN BookingArchers ON BaId = BkArcher
        WHERE BkTokenHash = '$hash'");
    $r = safe_fetch($q);
    if (!$r) return null;

    if ($r->expired || !$r->BaActive) {
        safe_w_sql("DELETE FROM BookingSessions WHERE BkId = " . intval($r->BkId));
        unset($_SESSION['BK_Token']);
        return null;
    }
    if ($r->stale) {
        safe_w_sql("UPDATE BookingSessions SET BkLastSeen = NOW(), BkIP = "
            . StrSafe_DB(bk_ip()) . " WHERE BkId = " . intval($r->BkId));
    }

    $cache = $r;
    return $cache;
}

function bk_logout()
{
    $hash = bk_current_token_hash();
    if ($hash !== '') {
        safe_w_sql("DELETE FROM BookingSessions WHERE BkTokenHash = '$hash'");
    }
    unset($_SESSION['BK_Token']);
}

/**
 * Archer page to open once signed in, relative to the public folder: the one asked for before
 * signing in (bk_require_archer, within the last 30 minutes), else the archer's home. Read once.
 *
 * Three pages of the points of sale may also be asked for: for a licensee who volunteers, the
 * joining page reached from the organiser's QR code and the volunteers' sign-in page; for a
 * customer, the shop of a competition. Only these exact pages and the exact form of their
 * parameters are accepted — the address is rebuilt from them, never taken as is.
 */
function bk_next_after_login()
{
    $n = $_SESSION['BK_NEXT'] ?? null;
    unset($_SESSION['BK_NEXT']);
    if (!is_array($n) || time() - intval($n['at'] ?? 0) > 1800) return 'index.php';
    $page = (string) ($n['page'] ?? '');
    if (preg_match('#^shop/(staff/(join\.php(\?t=[0-9a-f]{64})?|login\.php\?k=[a-z0-9]{10})|public/index\.php\?k=[a-z0-9]{10}(&s=[0-9]+)?)$#', $page)) {
        return '../../' . $page;
    }
    return preg_match('/^[a-z0-9_-]+\.php(\?[^\r\n\\\\]*)?$/i', $page) ? $page : 'index.php';
}

/* ------------------------------------------------------------------ */
/* CSRF                                                                */
/* ------------------------------------------------------------------ */

function bk_csrf_token()
{
    if (empty($_SESSION['BK_CSRF'])) $_SESSION['BK_CSRF'] = bin2hex(random_bytes(16));
    return $_SESSION['BK_CSRF'];
}

function bk_csrf_field()
{
    return '<input type="hidden" name="bk_csrf" value="' . bk_csrf_token() . '">';
}

function bk_csrf_check()
{
    return isset($_POST['bk_csrf'], $_SESSION['BK_CSRF'])
        && hash_equals($_SESSION['BK_CSRF'], (string) $_POST['bk_csrf']);
}
