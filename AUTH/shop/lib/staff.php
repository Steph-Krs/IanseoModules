<?php
/**
 * lib/staff.php — volunteers of the points of sale: who they are, what they may do on which
 * stand, how much they may refund, and the organiser's decisions about them.
 *
 * Rights are given STAND BY STAND (ShopStaffStands): no row = no right on that stand. An
 * ORGANISER volunteer (an organiser holding the till on their own device) has every right on
 * every stand of the competition, without rows and without refund ceiling.
 *
 * The volunteer pages run without any ianseo ACL: shp_staff_can() and the ceilings below are
 * THE server-side guard. Hiding a button is only comfort.
 *
 * Every decision of an organiser (approve, refuse, change rights, revoke, reset a password,
 * unlock) is written in ShopStaffLog with its author. That journal holds no name: erasing a
 * volunteer the day after the competition leaves nothing personal behind.
 *
 * Technical stamps (requests, approvals, last seen) are UTC, compared with UTC_TIMESTAMP():
 * the MySQL connection is in UTC on the volunteer pages and in the competition's zone on the
 * organiser pages.
 */

if (defined('SHP_STAFF_LOADED')) return;
define('SHP_STAFF_LOADED', true);

require_once __DIR__ . '/catalog.php';   // common, stands

define('SHP_PENDING_MIN', 30);        // minutes a join request waits for the organiser
define('SHP_PWD_MIN', 6);             // password of a volunteer without a licence (D13)
define('SHP_PWD_MAX', 72);            // bytes: password_hash() (bcrypt) ignores what follows
define('SHP_LOCK_FAILS', 5);          // consecutive sign-in failures before the account locks
define('SHP_JOIN_MAX_PENDING', 40);   // waiting requests per competition (a QR passed around)

/* ------------------------------------------------------------------ */
/* Rights and presets                                                  */
/* ------------------------------------------------------------------ */

/** Every right, in display order. */
function shp_perms_all()
{
    return array('sell', 'prepare', 'cash', 'refund', 'stock', 'manage');
}

/** Label of a right. */
function shp_perm_label($perm)
{
    $k = array('sell' => 'ShStfPermSell', 'prepare' => 'ShStfPermPrepare', 'cash' => 'ShStfPermCash',
        'refund' => 'ShStfPermRefund', 'stock' => 'ShStfPermStock', 'manage' => 'ShStfPermManage');
    return isset($k[$perm]) ? shp_t($k[$perm]) : (string) $perm;
}

/** Rights as a list (array or comma list in) → comma list in the canonical order. */
function shp_perms_clean($perms)
{
    $in = is_array($perms) ? $perms : explode(',', (string) $perms);
    $in = array_map('trim', $in);
    return implode(',', array_values(array_intersect(shp_perms_all(), $in)));
}

/**
 * Role presets (D12): the organiser picks one, applied to the stands ticked. Refund ceilings
 * (per operation, total over the competition) are the defaults offered, editable.
 */
function shp_staff_presets()
{
    return array(
        'seller'    => array('perms' => 'sell,cash', 'refund_max' => 0, 'refund_total' => 0),
        'preparer'  => array('perms' => 'prepare', 'refund_max' => 0, 'refund_total' => 0),
        'versatile' => array('perms' => 'sell,prepare,cash,stock', 'refund_max' => 0, 'refund_total' => 0),
        'manager'   => array('perms' => 'sell,prepare,cash,refund,stock,manage', 'refund_max' => 20, 'refund_total' => 100),
    );
}

function shp_staff_preset_label($preset)
{
    $k = array('seller' => 'ShStfPresetSeller', 'preparer' => 'ShStfPresetPreparer',
        'versatile' => 'ShStfPresetVersatile', 'manager' => 'ShStfPresetManager');
    return isset($k[$preset]) ? shp_t($k[$preset]) : '';
}

/** Preset matching a list of rights exactly, or '' (custom rights). */
function shp_staff_preset_of($perms)
{
    $perms = shp_perms_clean($perms);
    foreach (shp_staff_presets() as $k => $p) if ($p['perms'] === $perms) return $k;
    return '';
}

/* ------------------------------------------------------------------ */
/* Reading                                                             */
/* ------------------------------------------------------------------ */

/**
 * May this volunteer work? Active, or LOCKED: a lock (too many sign-in failures) only refuses
 * NEW sign-ins. A phone already signed in keeps working — otherwise anybody typing a seller's
 * name five times would sign them out in the middle of a rush. To cut an access: revoke.
 */
function shp_staff_working($s)
{
    return $s && in_array($s->SfStatus, array('active', 'locked'), true);
}

/** A volunteer (row, fresh from the base), or null. */
function shp_staff_get($staffId)
{
    shp_schema();
    $r = safe_fetch(safe_r_sql("SELECT * FROM ShopStaff WHERE SfId = " . intval($staffId)));
    return $r ?: null;
}

/** Row of a volunteer given as a row or an id. */
function shp_staff_row($staff)
{
    return is_object($staff) ? $staff : shp_staff_get($staff);
}

/**
 * Rights of a volunteer, stand by stand: [SdId => ['sell', …]]. Only stands of the volunteer's
 * own competition count. An ORGANISER gets every right on every active stand.
 */
function shp_staff_perms($staff, $fresh = false)
{
    static $cache = array();
    $s = shp_staff_row($staff);
    if (!$s) return array();
    $id = intval($s->SfId);
    if (!$fresh && isset($cache[$id])) return $cache[$id];
    $out = array();
    if ($s->SfKind === 'ORGANISER') {
        foreach (shp_stands(intval($s->SfTournament), false) as $sd => $st) $out[$sd] = shp_perms_all();
    } else {
        $rs = safe_r_sql("SELECT StStand, StPerms FROM ShopStaffStands
            INNER JOIN ShopStands ON SdId = StStand
            WHERE StStaff = $id AND SdTournament = " . intval($s->SfTournament) . "
            ORDER BY SdOrder, SdId");
        while ($r = safe_fetch($rs)) {
            $p = shp_perms_clean($r->StPerms);
            if ($p !== '') $out[intval($r->StStand)] = explode(',', $p);
        }
    }
    return $cache[$id] = $out;
}

/**
 * May this volunteer do $perm on stand $standId? $perm '' = any right; $standId 0 = on at least
 * one stand. Only an approved volunteer (active, or locked: see shp_staff_working) may do
 * anything, and only on a stand of their own competition.
 */
function shp_staff_can($staff, $perm, $standId = 0)
{
    $s = shp_staff_row($staff);
    if (!$s || !shp_staff_working($s)) return false;
    $perm = (string) $perm;
    if ($perm !== '' && !in_array($perm, shp_perms_all(), true)) return false;
    $standId = intval($standId);
    $map = shp_staff_perms($s);
    if ($standId > 0) {
        if (!isset($map[$standId])) return false;
        return $perm === '' || in_array($perm, $map[$standId], true);
    }
    foreach ($map as $perms) {
        if ($perm === '' || in_array($perm, $perms, true)) return true;
    }
    return false;
}

/**
 * Total refunded by a volunteer on their competition (journal lines of kind 'refund' with
 * their id, cancelled or failed ones excluded). $locked: read through the write connection,
 * inside the caller's transaction (see shp_staff_refund_check).
 */
function shp_staff_refunded($staff, $locked = false)
{
    $s = shp_staff_row($staff);
    if (!$s) return 0.0;
    $sql = "SELECT COALESCE(SUM(-BlgAmount), 0) AS n FROM BookingLedger
        WHERE BlgTournament = " . intval($s->SfTournament) . " AND BlgStaff = " . intval($s->SfId) . "
          AND BlgKind = 'refund' AND BlgCancelled = 0 AND BlgStatus <> 'failed'";
    $r = safe_fetch($locked ? safe_w_sql($sql) : safe_r_sql($sql));
    return $r ? round((float) $r->n, 2) : 0.0;
}

/**
 * May this volunteer refund $amount now? Checks the ceilings only (the right 'refund' on the
 * stand is checked by shp_staff_can). Returns ['ok', 'msg', 'left'] — 'left' = what the volunteer
 * may still refund over the competition BEFORE this refund (null = no ceiling, organiser).
 *
 * Reads only. $lock: the refunding endpoint calls this INSIDE its transaction with $lock = true;
 * the volunteer's row is then locked (SELECT … FOR UPDATE), so that two refunds sent at once
 * from two phones cannot both pass the total ceiling.
 */
function shp_staff_refund_check($staff, $amount, $lock = false)
{
    $s = shp_staff_row($staff);
    if ($s && $lock) {
        $s = safe_fetch(safe_w_sql("SELECT * FROM ShopStaff WHERE SfId = " . intval($s->SfId) . " FOR UPDATE")) ?: null;
    }
    if (!shp_staff_working($s)) {
        return array('ok' => false, 'msg' => shp_t('ShStfErrRevoked'), 'left' => 0.0);
    }
    $amount = round((float) $amount, 2);
    if ($amount <= 0) return array('ok' => false, 'msg' => shp_t('ShStfRefundAmount'), 'left' => 0.0);
    if ($s->SfKind === 'ORGANISER') return array('ok' => true, 'msg' => '', 'left' => null);

    $max = round((float) $s->SfRefundMax, 2);
    $total = round((float) $s->SfRefundTotal, 2);
    if ($max <= 0 || $total <= 0) return array('ok' => false, 'msg' => shp_t('ShStfRefundNone'), 'left' => 0.0);
    $tour = intval($s->SfTournament);
    $left = max(0.0, round($total - shp_staff_refunded($s, $lock), 2));
    if ($amount > $max + 0.004) {
        return array('ok' => false, 'msg' => shp_t('ShStfRefundOverMax', shp_money($max, $tour)), 'left' => $left);
    }
    if ($amount > $left + 0.004) {
        return array('ok' => false, 'msg' => shp_t('ShStfRefundOverTotal', shp_money($left, $tour)), 'left' => $left);
    }
    return array('ok' => true, 'msg' => '', 'left' => $left);
}

/**
 * Name of a volunteer as shown in histories and reports: given name and family name, or
 * "Volunteer no. X" once erased. X = rank of the row in its competition: stable because no row
 * is deleted while the competition exists (lib/purge.php); the payment journal writes the same
 * number in BlgBy.
 */
function shp_staff_label($staffId)
{
    static $cache = array();
    $staffId = intval($staffId);
    if ($staffId <= 0) return '';
    if (isset($cache[$staffId])) return $cache[$staffId];
    $s = shp_staff_get($staffId);
    if (!$s) return $cache[$staffId] = shp_t('ShStfNumber', $staffId);
    $name = trim($s->SfGivenName . ' ' . $s->SfFamilyName);
    if ($name === '' && $s->SfKind === 'ORGANISER' && $s->SfAuthUser !== '') $name = $s->SfAuthUser;
    if ($name === '') {
        $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM ShopStaff
            WHERE SfTournament = " . intval($s->SfTournament) . " AND SfId <= $staffId"));
        $name = shp_t('ShStfNumber', $r ? intval($r->n) : $staffId);
    }
    return $cache[$staffId] = $name;
}

/** Kind of a volunteer, for people. */
function shp_staff_kind_label($kind)
{
    $k = array('LICENSEE' => 'ShStfKindLicensee', 'LOCAL' => 'ShStfKindLocal', 'ORGANISER' => 'ShStfKindOrganiser');
    return isset($k[$kind]) ? shp_t($k[$kind]) : (string) $kind;
}

/** Join requests still waiting for the organiser (newest last). */
function shp_staff_pending($tourId)
{
    shp_schema();
    $out = array();
    $rs = safe_r_sql("SELECT SfId, SfKind, SfFamilyName, SfGivenName, SfLicence, SfCode,
            TIMESTAMPDIFF(MINUTE, SfCreated, UTC_TIMESTAMP()) AS Ago
        FROM ShopStaff
        WHERE SfTournament = " . intval($tourId) . " AND SfStatus = 'pending'
          AND SfCreated > DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . SHP_PENDING_MIN . " MINUTE)
        ORDER BY SfId");
    while ($r = safe_fetch($rs)) {
        $out[] = array('id' => intval($r->SfId), 'kind' => $r->SfKind,
            'kind_label' => shp_staff_kind_label($r->SfKind),
            'name' => trim($r->SfGivenName . ' ' . $r->SfFamilyName), 'licence' => (string) $r->SfLicence,
            'code' => (string) $r->SfCode, 'ago' => max(0, intval($r->Ago)));
    }
    return $out;
}

/**
 * The team of a competition: approved volunteers still able to work (active, locked) or whose
 * access was withdrawn (revoked after approval). Rights per stand, ceilings, total refunded,
 * minutes since last seen.
 */
function shp_staff_team($tourId)
{
    shp_schema();
    $tourId = intval($tourId);
    $out = array();
    $rs = safe_r_sql("SELECT *, TIMESTAMPDIFF(MINUTE, SfLastSeen, UTC_TIMESTAMP()) AS Idle
        FROM ShopStaff
        WHERE SfTournament = $tourId AND SfApprovedAt IS NOT NULL
          AND SfStatus IN ('active', 'locked', 'revoked')
        ORDER BY SfStatus = 'revoked', SfKind = 'ORGANISER' DESC, SfFamilyName, SfGivenName, SfId");
    while ($r = safe_fetch($rs)) {
        $perms = array();
        foreach (shp_staff_perms($r, true) as $sd => $p) $perms[] = array('stand' => $sd, 'perms' => $p);
        $out[] = array('id' => intval($r->SfId), 'kind' => $r->SfKind, 'kind_label' => shp_staff_kind_label($r->SfKind),
            'name' => shp_staff_label(intval($r->SfId)), 'licence' => (string) $r->SfLicence,
            'status' => $r->SfStatus, 'rights' => $perms,
            'refund_max' => round((float) $r->SfRefundMax, 2), 'refund_total' => round((float) $r->SfRefundTotal, 2),
            'refunded' => $r->SfKind === 'ORGANISER' ? 0.0 : shp_staff_refunded($r),
            'idle' => $r->Idle === null ? null : max(0, intval($r->Idle)));
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* Journal of the organisers' decisions                                */
/* ------------------------------------------------------------------ */

/** Who decides: the organiser's account (AUTH), or the plain ianseo session. */
function shp_staff_by()
{
    $u = (string) ($_SESSION['AUTH_User'] ?? '');
    return $u !== '' ? mb_substr($u, 0, 64) : 'ianseo';
}

/** Writes a line of ShopStaffLog. $detail must hold no name (erased volunteers stay erased). */
function shp_staff_log($tourId, $staffId, $event, $by, $detail = '')
{
    shp_schema();
    safe_w_sql("INSERT INTO ShopStaffLog SET SjTournament = " . intval($tourId) . ", SjStaff = " . intval($staffId)
        . ", SjEvent = " . StrSafe_DB(mb_substr((string) $event, 0, 16))
        . ", SjBy = " . StrSafe_DB(mb_substr((string) $by, 0, 64))
        . ", SjDetail = " . StrSafe_DB(mb_substr((string) $detail, 0, 160))
        . ", SjWhen = UTC_TIMESTAMP()");
}

/** Rights as written in the journal: "3:sell,cash 5:prepare". */
function shp_staff_rights_text(array $rights)
{
    $t = array();
    foreach ($rights as $sd => $p) $t[] = intval($sd) . ':' . $p;
    return implode(' ', $t);
}

/* ------------------------------------------------------------------ */
/* Organiser's decisions                                               */
/* ------------------------------------------------------------------ */

/**
 * Cleans rights sent by the organiser page: [SdId => rights] keeping only the stands of the
 * competition and known rights; stands without any right are dropped.
 */
function shp_staff_rights_clean($tourId, $rights)
{
    $stands = shp_stands($tourId, false);
    $out = array();
    foreach ((array) $rights as $sd => $p) {
        $sd = intval($sd);
        if (!isset($stands[$sd])) continue;
        $p = shp_perms_clean($p);
        if ($p !== '') $out[$sd] = $p;
    }
    ksort($out);
    return $out;
}

/** A ceiling typed by the organiser: 0 to 9999.99. */
function shp_staff_ceiling($v)
{
    return max(0.0, min(9999.99, round((float) $v, 2)));
}

/** Replaces the rights of a volunteer (already cleaned). */
function shp_staff_rights_write($staffId, array $rights)
{
    $staffId = intval($staffId);
    safe_w_sql("DELETE FROM ShopStaffStands WHERE StStaff = $staffId");
    foreach ($rights as $sd => $p) {
        safe_w_sql("INSERT INTO ShopStaffStands SET StStaff = $staffId, StStand = " . intval($sd) . ", StPerms = " . StrSafe_DB($p));
    }
    shp_staff_perms($staffId, true);
}

/**
 * Approves a waiting request: rights per stand and refund ceilings. Only once, and only while
 * the request is fresh (a request older than SHP_PENDING_MIN is refused: the code on the
 * volunteer's phone was meant to be checked face to face). Returns ['error', 'msg'].
 */
function shp_staff_approve($tourId, $staffId, $rights, $refundMax, $refundTotal, $by)
{
    $tourId = intval($tourId);
    $staffId = intval($staffId);
    $rights = shp_staff_rights_clean($tourId, $rights);
    if (!$rights) return array('error' => 1, 'code' => 'no_stand', 'msg' => shp_t('ShStfErrNoStand'));
    $max = shp_staff_ceiling($refundMax);
    $total = shp_staff_ceiling($refundTotal);
    safe_w_sql("UPDATE ShopStaff SET SfStatus = 'active', SfCode = '', SfFails = 0,
            SfRefundMax = $max, SfRefundTotal = $total,
            SfApprovedBy = " . StrSafe_DB(mb_substr((string) $by, 0, 64)) . ", SfApprovedAt = UTC_TIMESTAMP()
        WHERE SfId = $staffId AND SfTournament = $tourId AND SfStatus = 'pending'
          AND SfCreated > DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . SHP_PENDING_MIN . " MINUTE)");
    if (safe_w_affected_rows() < 1) return array('error' => 1, 'code' => 'gone', 'msg' => shp_t('ShStfErrGone'));
    shp_staff_rights_write($staffId, $rights);
    shp_staff_log($tourId, $staffId, 'approve', $by, shp_staff_rights_text($rights) . " refund $max/$total");
    return array('error' => 0, 'msg' => '');
}

/**
 * Refuses a waiting request. The row stays (status revoked, never approved) so that the
 * waiting phone can say "refused"; the name and password of a volunteer without a licence are
 * erased at once.
 */
function shp_staff_refuse($tourId, $staffId, $by)
{
    $tourId = intval($tourId);
    $staffId = intval($staffId);
    $s = shp_staff_get($staffId);
    if (!$s || intval($s->SfTournament) !== $tourId || $s->SfStatus !== 'pending') {
        return array('error' => 1, 'code' => 'gone', 'msg' => shp_t('ShStfErrGone'));
    }
    $erase = $s->SfKind === 'LOCAL' ? ", SfFamilyName = '', SfGivenName = '', SfPassword = ''" : '';
    safe_w_sql("UPDATE ShopStaff SET SfStatus = 'revoked', SfCode = ''$erase
        WHERE SfId = $staffId AND SfStatus = 'pending'");
    if (safe_w_affected_rows() < 1) return array('error' => 1, 'code' => 'gone', 'msg' => shp_t('ShStfErrGone'));
    safe_w_sql("DELETE FROM ShopStaffStands WHERE StStaff = $staffId");
    shp_staff_log($tourId, $staffId, 'refuse', $by, $s->SfKind);
    return array('error' => 0, 'msg' => '');
}

/** Changes the rights and ceilings of an approved volunteer (not of an organiser). */
function shp_staff_update($tourId, $staffId, $rights, $refundMax, $refundTotal, $by)
{
    $tourId = intval($tourId);
    $staffId = intval($staffId);
    $s = shp_staff_get($staffId);
    if (!$s || intval($s->SfTournament) !== $tourId || !in_array($s->SfStatus, array('active', 'locked'), true)
            || $s->SfKind === 'ORGANISER') {
        return array('error' => 1, 'code' => 'gone', 'msg' => shp_t('ShStfErrGone'));
    }
    $rights = shp_staff_rights_clean($tourId, $rights);
    if (!$rights) return array('error' => 1, 'code' => 'no_stand', 'msg' => shp_t('ShStfErrNoStand'));
    $max = shp_staff_ceiling($refundMax);
    $total = shp_staff_ceiling($refundTotal);
    safe_w_sql("UPDATE ShopStaff SET SfRefundMax = $max, SfRefundTotal = $total WHERE SfId = $staffId");
    shp_staff_rights_write($staffId, $rights);
    shp_staff_log($tourId, $staffId, 'rights', $by, shp_staff_rights_text($rights) . " refund $max/$total");
    return array('error' => 0, 'msg' => '');
}

/**
 * Withdraws the access of a volunteer, at once: the till asks the server every few seconds and
 * shows "access withdrawn". The sessions are kept until the erasing, so that the phone can tell
 * a withdrawn access from a mere sign-out; they no longer open anything.
 */
function shp_staff_revoke($tourId, $staffId, $by)
{
    $tourId = intval($tourId);
    $staffId = intval($staffId);
    safe_w_sql("UPDATE ShopStaff SET SfStatus = 'revoked', SfCode = '', SfPassword = ''
        WHERE SfId = $staffId AND SfTournament = $tourId AND SfStatus IN ('active', 'locked', 'pending')");
    if (safe_w_affected_rows() < 1) return array('error' => 1, 'code' => 'gone', 'msg' => shp_t('ShStfErrGone'));
    safe_w_sql("UPDATE ShopInvites SET SqRevoked = 1 WHERE SqTournament = $tourId AND SqKind = 'reset' AND SqStaff = $staffId");
    shp_staff_log($tourId, $staffId, 'revoke', $by);
    return array('error' => 0, 'msg' => '');
}

/** Unlocks a volunteer locked by too many sign-in failures. */
function shp_staff_unlock($tourId, $staffId, $by)
{
    $tourId = intval($tourId);
    $staffId = intval($staffId);
    safe_w_sql("UPDATE ShopStaff SET SfStatus = 'active', SfFails = 0
        WHERE SfId = $staffId AND SfTournament = $tourId AND SfStatus = 'locked'");
    if (safe_w_affected_rows() < 1) return array('error' => 1, 'code' => 'gone', 'msg' => shp_t('ShStfErrGone'));
    shp_staff_log($tourId, $staffId, 'unlock', $by);
    return array('error' => 0, 'msg' => '');
}

/**
 * The organiser holds the till on this device: their ORGANISER volunteer for the competition
 * (created, or taken back if it was withdrawn). Returns the row, or null.
 */
function shp_staff_organiser($tourId, $by)
{
    shp_schema();
    $tourId = intval($tourId);
    $by = mb_substr((string) $by, 0, 64);
    $name = '';
    $rs = safe_r_sql("SELECT AuName FROM AuthUsers WHERE AuUsername = " . StrSafe_DB($by), false, true);
    if ($rs && ($u = safe_fetch($rs))) $name = trim((string) $u->AuName);
    $s = safe_fetch(safe_r_sql("SELECT * FROM ShopStaff WHERE SfTournament = $tourId AND SfKind = 'ORGANISER'
        AND SfAuthUser = " . StrSafe_DB($by) . " ORDER BY SfId DESC LIMIT 1"));
    $names = "SfFamilyName = " . StrSafe_DB(mb_substr($name, 0, 60)) . ", SfGivenName = ''";
    if ($s) {
        $id = intval($s->SfId);
        safe_w_sql("UPDATE ShopStaff SET SfStatus = 'active', SfFails = 0, $names,
                SfApprovedBy = IF(SfApprovedAt IS NULL, " . StrSafe_DB($by) . ", SfApprovedBy),
                SfApprovedAt = COALESCE(SfApprovedAt, UTC_TIMESTAMP())
            WHERE SfId = $id");
        if ($s->SfStatus !== 'active') shp_staff_log($tourId, $id, 'organiser', $by);
    } else {
        safe_w_sql("INSERT INTO ShopStaff SET SfTournament = $tourId, SfKind = 'ORGANISER',
            SfAuthUser = " . StrSafe_DB($by) . ", $names, SfStatus = 'active',
            SfApprovedBy = " . StrSafe_DB($by) . ", SfApprovedAt = UTC_TIMESTAMP(),
            SfCreated = UTC_TIMESTAMP(), SfLastSeen = UTC_TIMESTAMP()");
        $id = intval(safe_w_last_id());
        shp_staff_log($tourId, $id, 'organiser', $by);
    }
    return shp_staff_get($id);
}

/* ------------------------------------------------------------------ */
/* Joining (volunteer side)                                            */
/* ------------------------------------------------------------------ */

/** 4-digit check code, not already shown by another waiting request of the competition. */
function shp_staff_code_new($tourId)
{
    $taken = array();
    foreach (shp_staff_pending($tourId) as $p) $taken[$p['code']] = true;
    for ($i = 0; $i < 20; $i++) {
        $c = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);   // bytes: digits
        if (!isset($taken[$c])) return $c;
    }
    return $c;
}

/** Too many waiting requests on this competition (a QR code passed around). */
function shp_staff_join_full($tourId)
{
    return count(shp_staff_pending($tourId)) >= SHP_JOIN_MAX_PENDING;
}

/**
 * Join request of a signed-in licensee. Returns ['state' => 'active'|'pending'|'full',
 * 'staff' => row]: a licensee already active on the competition is let in again at once.
 */
function shp_staff_join_licensee($tourId, $archer)
{
    shp_schema();
    $tourId = intval($tourId);
    $archerId = intval($archer->BaId);
    $s = safe_fetch(safe_r_sql("SELECT *, (SfCreated > DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . SHP_PENDING_MIN . " MINUTE)) AS Fresh
        FROM ShopStaff WHERE SfTournament = $tourId AND SfKind = 'LICENSEE' AND SfArcher = $archerId
        ORDER BY SfId DESC LIMIT 1"));
    if ($s && shp_staff_working($s)) return array('state' => 'active', 'staff' => $s);
    if ($s && $s->SfStatus === 'pending' && $s->Fresh) return array('state' => 'pending', 'staff' => $s);
    if (shp_staff_join_full($tourId)) return array('state' => 'full', 'staff' => null);

    $set = "SfLicence = " . StrSafe_DB(mb_substr((string) $archer->BaLicence, 0, 25))
         . ", SfFamilyName = " . StrSafe_DB(mb_substr(mb_strtoupper((string) $archer->BaFamilyName), 0, 60))
         . ", SfGivenName = " . StrSafe_DB(mb_substr((string) $archer->BaName, 0, 60))
         . ", SfStatus = 'pending', SfCode = " . StrSafe_DB(shp_staff_code_new($tourId))
         . ", SfRefundMax = 0, SfRefundTotal = 0, SfFails = 0, SfApprovedBy = '', SfApprovedAt = NULL"
         . ", SfCreated = UTC_TIMESTAMP(), SfLastSeen = UTC_TIMESTAMP()";
    if ($s && in_array($s->SfStatus, array('pending', 'revoked', 'locked', 'ended'), true)) {
        // Same licensee asking again (request expired, access withdrawn): one row per licensee
        // and competition, a NEW decision of the organiser.
        $id = intval($s->SfId);
        safe_w_sql("UPDATE ShopStaff SET $set WHERE SfId = $id");
        safe_w_sql("DELETE FROM ShopStaffStands WHERE StStaff = $id");
        shp_staff_perms($id, true);
    } else {
        safe_w_sql("INSERT INTO ShopStaff SET SfTournament = $tourId, SfKind = 'LICENSEE', SfArcher = $archerId, $set");
        $id = intval(safe_w_last_id());
    }
    return array('state' => 'pending', 'staff' => shp_staff_get($id));
}

/** Name as typed: trimmed, spaces folded, at most $max characters, no control character. */
function shp_staff_name_clean($s, $max = 40)
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', '', (string) $s);
    $s = trim(preg_replace('/\s+/u', ' ', (string) $s));
    return mb_substr($s, 0, $max);
}

/** Name compared loosely (case, accents, spaces), for homonyms and signing in again. */
function shp_staff_name_key($family, $given)
{
    return bk_fold($family) . '|' . bk_fold($given);
}

/** Family name without the complement added for a homonym: "DUPONT (bar)" → "DUPONT". */
function shp_staff_name_base($family)
{
    return trim(preg_replace('/\s*\([^()]*\)\s*$/u', '', (string) $family));
}

/**
 * Join request of a volunteer without a licence. $complement: added to the family name when an
 * exact homonym is already in the team ("Jean DUPONT (bar)"). Returns ['error', 'code', 'msg',
 * 'staff'] — codes: names, password, mismatch, duplicate, full, closed.
 */
function shp_staff_join_local($tourId, $family, $given, $password, $password2, $complement = '')
{
    shp_schema();
    $tourId = intval($tourId);
    $w = shp_window($tourId);
    if (!$w || !$w['local_open']) {
        return array('error' => 1, 'code' => 'closed', 'msg' => shp_t('ShStfJoinLocalClosed', shp_staff_date($w['open_from'] ?? '')));
    }
    $family = mb_strtoupper(shp_staff_name_clean($family));
    $given = mb_convert_case(mb_strtolower(shp_staff_name_clean($given)), MB_CASE_TITLE, 'UTF-8');
    $complement = shp_staff_name_clean($complement, 20);
    if ($family === '' || $given === '' || bk_fold($family) === '' || bk_fold($given) === '') {
        return array('error' => 1, 'code' => 'names', 'msg' => shp_t('ShStfErrNames'));
    }
    $password = (string) $password;
    if (mb_strlen($password) < SHP_PWD_MIN || strlen($password) > SHP_PWD_MAX) {   // bytes: bcrypt limit
        return array('error' => 1, 'code' => 'password', 'msg' => shp_t('ShStfErrPassword', SHP_PWD_MIN));
    }
    if (!hash_equals($password, (string) $password2)) {
        return array('error' => 1, 'code' => 'mismatch', 'msg' => shp_t('ShStfErrPasswordTwice'));
    }
    if ($complement !== '') $family .= ' (' . $complement . ')';

    $key = shp_staff_name_key($family, $given);
    $rs = safe_r_sql("SELECT SfFamilyName, SfGivenName FROM ShopStaff
        WHERE SfTournament = $tourId AND SfKind = 'LOCAL' AND (SfStatus IN ('active', 'locked')
           OR (SfStatus = 'pending' AND SfCreated > DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . SHP_PENDING_MIN . " MINUTE)))");
    while ($r = safe_fetch($rs)) {
        if (shp_staff_name_key($r->SfFamilyName, $r->SfGivenName) === $key) {
            return array('error' => 1, 'code' => 'duplicate', 'msg' => shp_t('ShStfErrHomonym', $given . ' ' . $family));
        }
    }
    if (shp_staff_join_full($tourId)) return array('error' => 1, 'code' => 'full', 'msg' => shp_t('ShStfErrFull'));

    safe_w_sql("INSERT INTO ShopStaff SET SfTournament = $tourId, SfKind = 'LOCAL',
        SfFamilyName = " . StrSafe_DB(mb_substr($family, 0, 60)) . ",
        SfGivenName = " . StrSafe_DB(mb_substr($given, 0, 60)) . ",
        SfPassword = " . StrSafe_DB(password_hash($password, PASSWORD_DEFAULT)) . ",
        SfStatus = 'pending', SfCode = " . StrSafe_DB(shp_staff_code_new($tourId)) . ",
        SfCreated = UTC_TIMESTAMP(), SfLastSeen = UTC_TIMESTAMP()");
    $password = $password2 = null;
    return array('error' => 0, 'code' => '', 'msg' => '', 'staff' => shp_staff_get(intval(safe_w_last_id())));
}

/**
 * New password chosen through a reset QR code: the account is unlocked and every session of
 * the volunteer is closed (the caller opens one on this phone).
 */
function shp_staff_set_password($staffId, $password, $password2)
{
    $password = (string) $password;
    if (mb_strlen($password) < SHP_PWD_MIN || strlen($password) > SHP_PWD_MAX) {   // bytes: bcrypt limit
        return array('error' => 1, 'code' => 'password', 'msg' => shp_t('ShStfErrPassword', SHP_PWD_MIN));
    }
    if (!hash_equals($password, (string) $password2)) {
        return array('error' => 1, 'code' => 'mismatch', 'msg' => shp_t('ShStfErrPasswordTwice'));
    }
    $staffId = intval($staffId);
    safe_w_sql("UPDATE ShopStaff SET SfPassword = " . StrSafe_DB(password_hash($password, PASSWORD_DEFAULT)) . ",
            SfStatus = 'active', SfFails = 0
        WHERE SfId = $staffId AND SfKind = 'LOCAL' AND SfStatus IN ('active', 'locked')");
    if (safe_w_affected_rows() < 1) return array('error' => 1, 'code' => 'gone', 'msg' => shp_t('ShStfErrGone'));
    safe_w_sql("DELETE FROM ShopStaffSessions WHERE SkStaff = $staffId");
    return array('error' => 0, 'code' => '', 'msg' => '');
}

/** A date YYYY-MM-DD for people (the language's short date). */
function shp_staff_date($ymd)
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $ymd, $m)) return (string) $ymd;
    return function_exists('bk_date_fr') ? bk_date_fr("$m[1]-$m[2]-$m[3]") : "$m[3]/$m[2]/$m[1]";
}

/** Name of a competition, for the volunteers' screens. */
function shp_staff_tour_name($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT ToName FROM Tournament WHERE ToId = " . intval($tourId)));
    return $r ? (string) $r->ToName : '';
}
