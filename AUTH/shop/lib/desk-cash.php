<?php
/**
 * lib/desk-cash.php — the till of the check-in desk, counted at the end of each departure
 * (desk/cash.php).
 *
 * WHAT is in the till: the lines of the payment journal (BookingLedger) taken AT THE DESK
 * (lib/desk.php, shp_pay_account with no stand: a volunteer, or the organiser who opened the
 * desk on their device — BlgStaff > 0), and the counter lines (cancel, reject) of those lines,
 * wherever they were made, with their negative amount: the till counts what was really taken at
 * the time of the closing. What the organiser records on the Payments page (cheques by post,
 * transfers, a club paying beforehand) never went through the desk's drawer: not in the till.
 * Neither are online payments nor the stands (their own cash report).
 *
 * WHEN: a CLOSING (ShopDeskCloses) takes every line of the till not closed yet and ties it to the
 * departure chosen by the organiser (BookingLedger.BlgDeskClose = DcId). The departures' times
 * are rarely filled in ianseo (2026-11): the organiser says when a departure ends, not the
 * clock. A departure may be closed several times (a late payment after the closing): its till
 * is the sum of its closings. Only the LAST closing may be undone (its lines go back to the
 * open till).
 */

if (defined('SHP_DESK_CASH_LOADED')) return;
define('SHP_DESK_CASH_LOADED', true);

require_once __DIR__ . '/desk.php';

/** SQL condition: the lines of the till of a competition (alias-free, on BookingLedger). */
function shp_cash_where($tourId)
{
    $t = intval($tourId);
    return "BlgTournament = $t AND BlgStand = 0 AND BlgOrder = 0 AND BlgStatus = 'done' AND BlgProvider = 'manual'
        AND (BlgStaff > 0 OR BlgCancels IN (SELECT dk.BlgId FROM BookingLedger dk
            WHERE dk.BlgTournament = $t AND dk.BlgStand = 0 AND dk.BlgOrder = 0 AND dk.BlgStaff > 0 AND dk.BlgCancels = 0))";
}

/**
 * Closings of a competition, oldest first: [DcId => ['id', 'session', 'when', 'by', 'total', 'n']].
 */
function shp_cash_closes($tourId)
{
    shp_schema();
    $tourId = intval($tourId);
    $out = array();
    $rs = safe_r_sql("SELECT DcId, DcSession, DcWhen, DcBy,
            (SELECT IFNULL(SUM(BlgAmount), 0) FROM BookingLedger WHERE BlgDeskClose = DcId AND " . shp_cash_where($tourId) . ") AS Total,
            (SELECT COUNT(*) FROM BookingLedger WHERE BlgDeskClose = DcId AND " . shp_cash_where($tourId) . ") AS N
        FROM ShopDeskCloses WHERE DcTournament = $tourId ORDER BY DcId");
    while ($r = safe_fetch($rs)) {
        $out[intval($r->DcId)] = array('id' => intval($r->DcId), 'session' => intval($r->DcSession), 'when' => (string) $r->DcWhen,
            'by' => (string) $r->DcBy, 'total' => round((float) $r->Total, 2), 'n' => intval($r->N));
    }
    return $out;
}

/**
 * Lines of the till for a view: 'open' (not closed yet) or a departure number (its closings).
 * Oldest first: ['id', 'account', 'kind', 'amount',
 * 'method', 'label', 'when', 'staff', 'by', 'cancelled', 'close'].
 */
function shp_cash_lines($tourId, $view)
{
    shp_schema();
    $tourId = intval($tourId);
    $w = shp_cash_where($tourId);
    if ($view === 'open') {
        $w .= " AND BlgDeskClose = 0";
    } else {
        $w .= " AND BlgDeskClose IN (SELECT DcId FROM ShopDeskCloses WHERE DcTournament = $tourId AND DcSession = " . intval($view) . ")";
    }
    $out = array();
    $rs = safe_r_sql("SELECT BlgId, BlgAccount, BlgKind, BlgAmount, BlgMethod, BlgLabel, BlgWhen, BlgStaff, BlgBy, BlgCancelled, BlgDeskClose
        FROM BookingLedger WHERE $w ORDER BY BlgWhen, BlgId");
    while ($r = safe_fetch($rs)) {
        $out[] = array('id' => intval($r->BlgId), 'account' => (string) $r->BlgAccount, 'kind' => (string) $r->BlgKind,
            'amount' => round((float) $r->BlgAmount, 2), 'method' => (string) $r->BlgMethod, 'label' => (string) $r->BlgLabel,
            'when' => (string) $r->BlgWhen, 'staff' => intval($r->BlgStaff), 'by' => (string) $r->BlgBy,
            'cancelled' => intval($r->BlgCancelled) > 0, 'close' => intval($r->BlgDeskClose));
    }
    return $out;
}

/** Who took a line: the volunteer (their current label), else the organiser's name. */
function shp_cash_who(array $l)
{
    if ($l['staff'] > 0 && function_exists('shp_staff_label')) return shp_staff_label($l['staff']);
    return $l['by'] !== '' ? $l['by'] : shp_t('ShRepOrganiser');
}

/**
 * Totals of lines: ['total', 'n' (payments and refunds, not their counter lines),
 * 'methods' => [method => ['amount', 'n']], 'who' => [label => [method => amount, '' => total]]].
 */
function shp_cash_totals(array $lines)
{
    $t = array('total' => 0.0, 'n' => 0, 'methods' => array(), 'who' => array());
    foreach ($lines as $l) {
        $m = $l['method'];
        $who = shp_cash_who($l);
        if (!isset($t['methods'][$m])) $t['methods'][$m] = array('amount' => 0.0, 'n' => 0);
        if (!isset($t['who'][$who])) $t['who'][$who] = array('' => 0.0);
        if (!isset($t['who'][$who][$m])) $t['who'][$who][$m] = 0.0;
        $t['total'] += $l['amount'];
        $t['methods'][$m]['amount'] += $l['amount'];
        $t['who'][$who][$m] += $l['amount'];
        $t['who'][$who][''] += $l['amount'];
        if ($l['kind'] === 'payment' || $l['kind'] === 'refund') {
            $t['n']++;
            $t['methods'][$m]['n']++;
        }
    }
    $round = function (&$v) { if (is_float($v)) $v = round($v, 2); };
    array_walk_recursive($t, $round);
    return $t;
}

/**
 * Closes the open till on a departure: every line not closed yet (but the ones paid before the
 * desk opens) is tied to it. Returns the closing's id, 0 when there was nothing to close.
 */
function shp_cash_close($tourId, $session, $by)
{
    shp_schema();
    $tourId = intval($tourId);
    $session = intval($session);
    if ($session <= 0 || $session > 255) return 0;
    $now = shp_local_now_sql($tourId);
    safe_w_BeginTransaction();
    // The lines first, then updated by their ids: MySQL refuses an UPDATE whose condition reads
    // the same table (the counter lines of the desk's lines, shp_cash_where).
    $ids = array();
    $rs = safe_w_sql("SELECT BlgId FROM BookingLedger WHERE " . shp_cash_where($tourId) . " AND BlgDeskClose = 0 FOR UPDATE");
    while ($r = safe_fetch($rs)) $ids[] = intval($r->BlgId);
    if (!$ids) {
        safe_w_Rollback();
        return 0;
    }
    safe_w_sql("INSERT INTO ShopDeskCloses SET DcTournament = $tourId, DcSession = $session, DcWhen = $now,
        DcBy = " . StrSafe_DB(mb_substr((string) $by, 0, 64)));
    $id = intval(safe_w_last_id());
    safe_w_sql("UPDATE BookingLedger SET BlgDeskClose = $id WHERE BlgTournament = $tourId AND BlgId IN (" . implode(',', $ids) . ")");
    safe_w_Commit();
    return $id;
}

/** Undoes the LAST closing of a competition: its lines go back to the open till. */
function shp_cash_reopen($tourId, $closeId)
{
    shp_schema();
    $tourId = intval($tourId);
    $closeId = intval($closeId);
    safe_w_BeginTransaction();
    $last = safe_fetch(safe_w_sql("SELECT MAX(DcId) AS id FROM ShopDeskCloses WHERE DcTournament = $tourId FOR UPDATE"));
    if (!$last || intval($last->id) !== $closeId || $closeId <= 0) {
        safe_w_Rollback();
        return false;
    }
    safe_w_sql("UPDATE BookingLedger SET BlgDeskClose = 0 WHERE BlgTournament = $tourId AND BlgDeskClose = $closeId");
    safe_w_sql("DELETE FROM ShopDeskCloses WHERE DcId = $closeId AND DcTournament = $tourId");
    safe_w_Commit();
    return true;
}
