<?php
/**
 * lib/invite.php — QR codes given by the organiser to the volunteers.
 *
 *   enrol  "join the team": 30 minutes, reusable by every volunteer while valid, one at a time
 *          per competition (a new one replaces it), can be stopped at any moment;
 *   reset  "choose a new password": 10 minutes, single use, for one volunteer without a licence.
 *
 * Random token, only its SHA-256 is stored; the token itself only exists in the address of the
 * QR code shown on the organiser's screen. Stamps in UTC (technical).
 *
 * A QR code alone opens nothing: an enrolment still needs the organiser to approve the request,
 * after checking face to face the 4-digit code shown on the volunteer's phone.
 */

if (defined('SHP_INVITE_LOADED')) return;
define('SHP_INVITE_LOADED', true);

require_once __DIR__ . '/staff.php';

define('SHP_INVITE_ENROL_MIN', 30);
define('SHP_INVITE_RESET_MIN', 10);

/**
 * New QR code. $kind 'enrol' (stops the previous one of the competition) or 'reset' ($staffId:
 * an active or locked volunteer without a licence; stops their previous reset code).
 * Returns ['error', 'msg', 'token', 'url', 'seconds', 'id'].
 */
function shp_invite_create($tourId, $kind, $by, $staffId = 0)
{
    shp_schema();
    $tourId = intval($tourId);
    $staffId = intval($staffId);
    if (!shp_staff_on($tourId)) return array('error' => 1, 'code' => 'shop_off', 'msg' => shp_t('ShErrShopOff'));
    $w = shp_window($tourId);
    if (!$w || $w['purge_due']) return array('error' => 1, 'code' => 'over', 'msg' => shp_t('ShStfErrOver'));

    if ($kind === 'reset') {
        $s = shp_staff_get($staffId);
        if (!$s || intval($s->SfTournament) !== $tourId || $s->SfKind !== 'LOCAL'
                || !in_array($s->SfStatus, array('active', 'locked'), true)) {
            return array('error' => 1, 'code' => 'gone', 'msg' => shp_t('ShStfErrGone'));
        }
        $minutes = SHP_INVITE_RESET_MIN;
        $maxUses = 1;
        safe_w_sql("UPDATE ShopInvites SET SqRevoked = 1 WHERE SqTournament = $tourId AND SqKind = 'reset' AND SqStaff = $staffId");
    } elseif ($kind === 'enrol') {
        $minutes = SHP_INVITE_ENROL_MIN;
        $maxUses = 0;
        $staffId = 0;
        safe_w_sql("UPDATE ShopInvites SET SqRevoked = 1 WHERE SqTournament = $tourId AND SqKind = 'enrol'");
    } else {
        return array('error' => 1, 'code' => 'bad_request', 'msg' => shp_t('ShErrBadRequest'));
    }

    list($token, $hash) = shp_token_new();
    safe_w_sql("INSERT INTO ShopInvites SET SqTournament = $tourId, SqKind = " . StrSafe_DB($kind) . ",
        SqTokenHash = '$hash', SqStaff = $staffId, SqCreatedBy = " . StrSafe_DB(mb_substr((string) $by, 0, 64)) . ",
        SqCreated = UTC_TIMESTAMP(), SqExpires = DATE_ADD(UTC_TIMESTAMP(), INTERVAL $minutes MINUTE),
        SqUses = 0, SqMaxUses = $maxUses, SqRevoked = 0");
    $id = intval(safe_w_last_id());
    if ($kind === 'reset') shp_staff_log($tourId, $staffId, 'reset', $by);
    return array('error' => 0, 'msg' => '', 'id' => $id, 'token' => $token,
        'url' => shp_invite_url($token), 'seconds' => $minutes * 60);
}

/** Address encoded in the QR code. */
function shp_invite_url($token)
{
    return shp_abs_url('staff/join.php?t=' . $token);
}

/**
 * Checks a token read from the address. Returns ['ok' => bool, 'reason' => 'unknown' |
 * 'expired' | 'revoked' | 'used' | 'shop_off', 'invite' => row]. Reads only.
 */
function shp_invite_check($token)
{
    $hash = shp_token_hash($token);
    if ($hash === '') return array('ok' => false, 'reason' => 'unknown', 'invite' => null);
    shp_schema();
    $r = safe_fetch(safe_r_sql("SELECT *, (SqExpires <= UTC_TIMESTAMP()) AS SqOver,
            GREATEST(0, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), SqExpires)) AS SqLeft
        FROM ShopInvites WHERE SqTokenHash = '$hash'"));
    if (!$r) return array('ok' => false, 'reason' => 'unknown', 'invite' => null);
    $reason = '';
    if ($r->SqRevoked) $reason = 'revoked';
    elseif ($r->SqOver) $reason = 'expired';
    elseif (intval($r->SqMaxUses) > 0 && intval($r->SqUses) >= intval($r->SqMaxUses)) $reason = 'used';
    elseif (!shp_staff_on(intval($r->SqTournament))) $reason = 'shop_off';
    return array('ok' => $reason === '', 'reason' => $reason, 'invite' => $r);
}

/**
 * Counts one use of a code, atomically: false when it was used up, stopped or expired in the
 * meantime (two phones using one reset code at the same instant: only one goes through).
 */
function shp_invite_use($inviteId)
{
    safe_w_sql("UPDATE ShopInvites SET SqUses = SqUses + 1
        WHERE SqId = " . intval($inviteId) . " AND SqRevoked = 0 AND SqExpires > UTC_TIMESTAMP()
          AND (SqMaxUses = 0 OR SqUses < SqMaxUses)");
    return safe_w_affected_rows() > 0;
}

/** Stops the codes of a kind on a competition ("Stop the invitation"). */
function shp_invite_stop($tourId, $kind = 'enrol', $by = '')
{
    shp_schema();
    $kind = $kind === 'reset' ? 'reset' : 'enrol';
    safe_w_sql("UPDATE ShopInvites SET SqRevoked = 1
        WHERE SqTournament = " . intval($tourId) . " AND SqKind = '$kind' AND SqRevoked = 0 AND SqExpires > UTC_TIMESTAMP()");
    return safe_w_affected_rows();
}

/** Seconds left on the current enrolment code of a competition (0 = none running). */
function shp_invite_running($tourId)
{
    shp_schema();
    $r = safe_fetch(safe_r_sql("SELECT MAX(TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), SqExpires)) AS n FROM ShopInvites
        WHERE SqTournament = " . intval($tourId) . " AND SqKind = 'enrol' AND SqRevoked = 0 AND SqExpires > UTC_TIMESTAMP()"));
    return $r ? max(0, intval($r->n)) : 0;
}

/** Message for people of a refused code. */
function shp_invite_reason_msg($reason)
{
    $k = array('unknown' => 'ShStfInvUnknown', 'expired' => 'ShStfInvExpired', 'revoked' => 'ShStfInvRevoked',
        'used' => 'ShStfInvUsed', 'shop_off' => 'ShErrShopOff');
    return shp_t($k[$reason] ?? 'ShStfInvUnknown');
}
