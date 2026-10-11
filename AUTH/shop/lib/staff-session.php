<?php
/**
 * lib/staff-session.php — who is holding this phone: the volunteer's own cookie.
 *
 * NOT the PHP session: a volunteer must never be signed out in the middle of a rush because the
 * PHP session expired or the browser was killed. The cookie 'shp_s' carries a random token;
 * only its SHA-256 is stored (ShopStaffSessions) — a database dump gives no usable secret.
 *
 * The session is opened as soon as the join request is made, while the volunteer is still
 * 'pending': the waiting screen asks with it whether the organiser approved, and the same
 * session opens the till afterwards. Until then it opens nothing (shp_current_staff() only
 * returns an ACTIVE volunteer).
 *
 * End of a session: 12 hours without any request, the day after the competition (local time
 * of the competition), the access withdrawn or locked, a password reset. Checked at EVERY
 * request: a withdrawn access stops the next request of the till.
 */

if (defined('SHP_STAFF_SESSION_LOADED')) return;
define('SHP_STAFF_SESSION_LOADED', true);

require_once __DIR__ . '/staff.php';

define('SHP_STAFF_COOKIE', 'shp_s');
define('SHP_TILL_COOKIE', 'shp_tk');   // public key of the last till opened on this phone (not a secret)
define('SHP_STAFF_IDLE_H', 12);      // hours without any request
define('SHP_STAFF_MAX_SESSIONS', 5); // devices of one volunteer (phone changed, tablet…)

/** Opens a session for this volunteer on this phone (the phone's previous one is closed). */
function shp_staff_session_open($staffId)
{
    shp_schema();
    $staffId = intval($staffId);
    shp_staff_session_close();
    list($token, $hash) = shp_token_new();
    safe_w_sql("INSERT INTO ShopStaffSessions SET SkStaff = $staffId, SkTokenHash = '$hash',
        SkCreated = UTC_TIMESTAMP(), SkLastSeen = UTC_TIMESTAMP(),
        SkIP = " . StrSafe_DB(bk_ip()) . ",
        SkUA = " . StrSafe_DB(mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160)));
    // Older devices of the same volunteer beyond the limit (derived table: MySQL refuses a
    // subquery on the table being deleted from).
    safe_w_sql("DELETE FROM ShopStaffSessions WHERE SkStaff = $staffId AND SkId NOT IN (
        SELECT SkId FROM (SELECT SkId FROM ShopStaffSessions WHERE SkStaff = $staffId
            ORDER BY SkId DESC LIMIT " . SHP_STAFF_MAX_SESSIONS . ") AS Kept)");
    safe_w_sql("UPDATE ShopStaff SET SfLastSeen = UTC_TIMESTAMP() WHERE SfId = $staffId");
    // The server decides when the session ends; the cookie only has to outlive it.
    shp_cookie_set(SHP_STAFF_COOKIE, $token, time() + 4 * 86400);
    shp_staff_session_state(true);
}

/** Closes the session of this phone (sign-out). */
function shp_staff_session_close()
{
    $hash = shp_token_hash(shp_cookie_get(SHP_STAFF_COOKIE));
    if ($hash !== '') safe_w_sql("DELETE FROM ShopStaffSessions WHERE SkTokenHash = '$hash'");
    shp_cookie_clear(SHP_STAFF_COOKIE);
    shp_staff_session_state(true);
}

/**
 * State of this phone's volunteer session: ['staff' => row|null, 'reason' => …, 'row' => row].
 * 'staff' is set only for a volunteer allowed to work now. Reasons:
 *   ok          active, may work
 *   signed_out  no session on this phone
 *   pending     join request waiting for the organiser
 *   refused     join request refused
 *   revoked     access withdrawn by the organiser
 *   locked      too many sign-in failures: refused by the sign-in page only; a phone already
 *               signed in keeps working (shp_staff_working), so this state is not returned here
 *   expired     12 h without activity, competition over, password reset elsewhere
 *   shop_off    the organiser switched the points of sale AND the check-in desk off (the till
 *               itself also stops with the points of sale alone: shp_require_staff)
 * 'row' is the volunteer's row whenever one was found (to name the competition).
 */
function shp_staff_session_state($reset = false)
{
    static $state = null;
    if ($reset) { $state = null; return null; }
    if ($state !== null) return $state;
    $state = array('staff' => null, 'reason' => 'signed_out', 'row' => null);

    $hash = shp_token_hash(shp_cookie_get(SHP_STAFF_COOKIE));
    if ($hash === '') return $state;
    shp_schema();
    $r = safe_fetch(safe_r_sql("SELECT ShopStaff.*, SkId,
            (SkLastSeen < DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . SHP_STAFF_IDLE_H . " HOUR)) AS SkIdle,
            (SkLastSeen < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)) AS SkStale,
            (SfCreated IS NULL OR SfCreated <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . SHP_PENDING_MIN . " MINUTE)) AS SfOld
        FROM ShopStaffSessions
        INNER JOIN ShopStaff ON SfId = SkStaff
        WHERE SkTokenHash = '$hash'"));
    if (!$r) return $state;
    $state['row'] = $r;
    $tour = intval($r->SfTournament);
    $w = shp_window($tour);

    if (!$w || $w['purge_due'] || $r->SkIdle || in_array($r->SfStatus, array('ended', 'purged'), true)) {
        safe_w_sql("DELETE FROM ShopStaffSessions WHERE SkId = " . intval($r->SkId));
        $state['reason'] = 'expired';
        return $state;
    }
    if ($r->SfStatus === 'pending') {
        // Not approved in time: the request is dead, the phone must start again.
        $state['reason'] = $r->SfOld ? 'expired' : 'pending';
    } elseif ($r->SfStatus === 'revoked') {
        $state['reason'] = $r->SfApprovedAt === null ? 'refused' : 'revoked';
    } elseif (!shp_staff_working($r)) {
        $state['reason'] = 'expired';
    } elseif (!shp_staff_on($tour)) {
        $state['reason'] = 'shop_off';
    } else {
        $state['reason'] = 'ok';
        $state['staff'] = $r;
    }
    // Last activity, at most once a minute (also while waiting: a request in progress is not idle).
    if ($r->SkStale && in_array($state['reason'], array('ok', 'pending'), true)) {
        safe_w_sql("UPDATE ShopStaffSessions SET SkLastSeen = UTC_TIMESTAMP(), SkIP = " . StrSafe_DB(bk_ip())
            . " WHERE SkId = " . intval($r->SkId));
        safe_w_sql("UPDATE ShopStaff SET SfLastSeen = UTC_TIMESTAMP() WHERE SfId = " . intval($r->SfId));
    }
    return $state;
}

/** The active volunteer holding this phone, or null. */
function shp_current_staff()
{
    $st = shp_staff_session_state();
    return $st['staff'];
}

/** Message for people of a session state (see shp_staff_session_state). */
function shp_staff_reason_msg($reason)
{
    $k = array('signed_out' => 'ShStfErrSignedOut', 'pending' => 'ShStfErrPending', 'refused' => 'ShStfErrRefused',
        'revoked' => 'ShStfErrRevoked', 'locked' => 'ShStfErrLocked', 'expired' => 'ShStfErrExpired',
        'shop_off' => 'ShErrShopOff', 'forbidden' => 'ShStfErrForbidden');
    return shp_t($k[$reason] ?? 'ShStfErrSignedOut');
}

/** Is the caller a script expecting JSON (endpoint of the till) rather than a page? */
function shp_wants_json()
{
    if ((string) ($_SERVER['HTTP_X_SHP'] ?? '') === '1') return true;
    if (stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false) return true;
    return strpos((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/api/') !== false;
}

/**
 * The active volunteer holding this phone, allowed to do $perm on stand $standId ('' / 0 =
 * any). Otherwise stops: JSON envelope {error: 1, code, msg} for an endpoint — codes
 * signed_out, pending, refused, revoked, locked, expired, shop_off, forbidden, which the till
 * shows as they are — or a page telling what to do.
 */
function shp_require_staff($perm = '', $standId = 0)
{
    $st = shp_staff_session_state();
    $reason = $st['reason'];
    // The session may be open for the check-in desk alone: the till needs the stands on.
    if ($st['staff'] && !shp_enabled(intval($st['staff']->SfTournament))) $reason = 'shop_off';
    elseif ($st['staff'] && !shp_staff_can($st['staff'], $perm, $standId)) $reason = 'forbidden';
    if ($st['staff'] && $reason === 'ok') return $st['staff'];

    $http = in_array($reason, array('signed_out', 'expired'), true) ? 401 : 403;
    if (shp_wants_json()) shp_json_error($reason, shp_staff_reason_msg($reason), $http);
    $tour = $st['row'] ? intval($st['row']->SfTournament) : shp_tour_by_key((string) ($_GET['k'] ?? ''));
    shp_staff_denied_page($reason, $tour, $http);
}

/** Page "you cannot use the till", with the way back in when there is one. */
function shp_staff_denied_page($reason, $tourId, $http = 403)
{
    if ($http !== 200 && !headers_sent()) http_response_code(intval($http));
    $title = shp_t('ShStfTillTitle');
    shp_head($title, array('layout' => 'card'));
    echo '  <main class="shp-main"><div class="shp-card"><h1>' . shp_e($title) . '</h1>'
        . shp_msg($reason === 'shop_off' ? 'info' : 'warn', shp_staff_reason_msg($reason));
    $s = $tourId > 0 ? shp_settings($tourId) : null;
    if ($s && in_array($reason, array('signed_out', 'expired', 'locked'), true)) {
        echo '<p><a class="shp-btn shp-btn-primary shp-btn-block" href="'
            . shp_e(shp_url('staff/login.php?k=' . rawurlencode($s->SgPublicKey))) . '">' . shp_e(shp_t('ShStfLoginAgain')) . '</a></p>';
    } elseif ($reason === 'pending') {
        echo '<p><a class="shp-btn shp-btn-primary shp-btn-block" href="' . shp_e(shp_url('staff/join.php')) . '">'
            . shp_e(shp_t('ShStfBackToWait')) . '</a></p>';
    } elseif ($reason === 'signed_out') {
        // Which competition is unknown here: the poster at the stand carries its address.
        echo '<p class="shp-muted">' . shp_e(shp_t('ShStfScanTill')) . '</p>';
    }
    echo "</div></main>\n";
    shp_foot();
    exit;
}
