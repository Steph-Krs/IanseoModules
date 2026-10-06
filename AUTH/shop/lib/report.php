<?php
/**
 * lib/report.php — figures of the points of sale of a competition: live state of the stands,
 * sales, collections, refunds, stock. READ ONLY: nothing here writes anywhere.
 *
 * Every sum comes from the lines of the orders (ShopOrderLines, cancelled quantities left out)
 * or from the payment journal (BookingLedger), never from the ShPaid / ShPayState caches. The
 * journal part of the food & shop is what carries a stand or an order (BlgStand / BlgOrder > 0):
 * the registrations of BOOKING share the journal but are not in these figures. Only lines
 * 'done' count (a payment still pending at an online provider is not money held).
 *
 * The number of queries does not depend on the size of the competition: one grouped query per
 * figure, the rest is arithmetic on the groups.
 *
 * A filter ($f, from shp_rep_filter()) selects a day (local date of the competition, '' = the
 * whole competition) and a stand (0 = all). Orders are dated by their creation, journal lines
 * by their own time stamp; both are local times of the competition.
 */

if (defined('SHP_REPORT_LOADED')) return;
define('SHP_REPORT_LOADED', true);

require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/stock.php';
require_once dirname(__DIR__, 2) . '/booking/lib/payment.php';   // bk_payment_methods, bk_ledger_kinds
if (is_file(__DIR__ . '/staff.php')) require_once __DIR__ . '/staff.php';

/** A date typed or posted (YYYY-MM-DD), '' when it is not one. */
function shp_rep_day($d)
{
    $d = trim((string) $d);
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $d : '';
}

/**
 * Filter of a report: ['tour', 'day' ('' = whole competition), 'stand' (0 = all)]. A stand that
 * is not of the competition becomes 0.
 */
function shp_rep_filter($tourId, $day = '', $standId = 0)
{
    $tourId = intval($tourId);
    $standId = intval($standId);
    if ($standId > 0 && !isset(shp_stands($tourId, false)[$standId])) $standId = 0;
    return array('tour' => $tourId, 'day' => shp_rep_day($day), 'stand' => $standId);
}

/** SQL condition on ShopOrders for a filter. */
function shp_rep_orders_where(array $f)
{
    $w = "ShTournament = " . intval($f['tour']);
    if ($f['stand'] > 0) $w .= " AND ShStand = " . intval($f['stand']);
    if ($f['day'] !== '') $w .= " AND DATE(ShCreated) = " . StrSafe_DB($f['day']);
    return $w;
}

/** SQL condition on BookingLedger for a filter: the food & shop lines that count as money held. */
function shp_rep_ledger_where(array $f)
{
    $w = "BlgTournament = " . intval($f['tour']) . " AND BlgStatus = 'done' AND (BlgStand > 0 OR BlgOrder > 0)";
    if ($f['stand'] > 0) $w .= " AND BlgStand = " . intval($f['stand']);
    if ($f['day'] !== '') $w .= " AND DATE(BlgWhen) = " . StrSafe_DB($f['day']);
    return $w;
}

/** Name of a stand for people (its name, else its kind). */
function shp_rep_stand_name($stand)
{
    if (!$stand) return '';
    $n = trim((string) $stand->SdName);
    return $n !== '' ? $n : (shp_stand_kinds()[$stand->SdKind] ?? (string) $stand->SdKind);
}

/** Who did a journal line or an order step: the volunteer's name (a number once erased), or the organiser. */
function shp_rep_staff_name($staffId)
{
    $staffId = intval($staffId);
    if ($staffId <= 0) return shp_t('ShRepOrganiser');
    return function_exists('shp_staff_label') ? shp_staff_label($staffId) : shp_t('ShRepStaffN', $staffId);
}

/** Rounds a sum read from SQL to the cent. */
function shp_rep_num($v)
{
    return round((float) $v, 2);
}

/* ------------------------------------------------------------------ */
/* Live state (orders page)                                            */
/* ------------------------------------------------------------------ */

/**
 * What every stand has to do right now, in two queries: [SdId => ['to_pay', 'prep', 'ready',
 * 'pre' (pre-orders to hand over), 'wait' (minutes the oldest order waits for the stand, null
 * when none), 'ready_wait' (minutes the oldest ready order waits for its customer), 'staff'
 * (volunteers seen in the last 10 minutes)]]. Same split as shp_queue().
 */
function shp_rep_live($tourId)
{
    shp_schema();
    $tourId = intval($tourId);
    $now = shp_local_now_sql($tourId);
    $out = array();
    $rs = safe_r_sql("SELECT ShStand,
            COALESCE(SUM(ShChannel <> 'preorder' AND ShStatus = 'placed' AND ShPayMode = 'now' AND ShPayState <> 'paid'), 0) AS ToPay,
            COALESCE(SUM(ShChannel <> 'preorder' AND ShStatus IN ('placed', 'preparing')
                AND NOT (ShStatus = 'placed' AND ShPayMode = 'now' AND ShPayState <> 'paid')), 0) AS Prep,
            COALESCE(SUM(ShChannel <> 'preorder' AND ShStatus = 'ready'), 0) AS Ready,
            COALESCE(SUM(ShChannel = 'preorder'), 0) AS Pre,
            MAX(IF(ShChannel <> 'preorder' AND ShStatus IN ('placed', 'preparing'), TIMESTAMPDIFF(MINUTE, ShCreated, $now), NULL)) AS Wait,
            MAX(IF(ShChannel <> 'preorder' AND ShStatus = 'ready', TIMESTAMPDIFF(MINUTE, ShReadyAt, $now), NULL)) AS ReadyWait
        FROM ShopOrders
        WHERE ShTournament = $tourId AND ShStatus IN ('placed', 'preparing', 'ready')
        GROUP BY ShStand");
    while ($r = safe_fetch($rs)) {
        $out[intval($r->ShStand)] = array('to_pay' => intval($r->ToPay), 'prep' => intval($r->Prep), 'ready' => intval($r->Ready),
            'pre' => intval($r->Pre), 'wait' => $r->Wait === null ? null : max(0, intval($r->Wait)),
            'ready_wait' => $r->ReadyWait === null ? null : max(0, intval($r->ReadyWait)), 'staff' => 0);
    }
    $blank = array('to_pay' => 0, 'prep' => 0, 'ready' => 0, 'pre' => 0, 'wait' => null, 'ready_wait' => null, 'staff' => 0);
    foreach (shp_stands($tourId, false) as $sd => $s) {
        if (!isset($out[$sd])) $out[$sd] = $blank;
    }

    // Volunteers seen lately (the till writes SfLastSeen, a UTC stamp): those with a right on a
    // stand, plus the organisers holding a till, who have every right everywhere. A locked
    // volunteer keeps working on a phone that was already signed in.
    $organisers = 0;
    $rs = safe_r_sql("SELECT StStand, COUNT(*) AS N FROM ShopStaffStands
        INNER JOIN ShopStaff ON SfId = StStaff
        WHERE SfTournament = $tourId AND SfStatus IN ('active', 'locked') AND SfKind <> 'ORGANISER' AND StPerms <> ''
          AND SfLastSeen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)
        GROUP BY StStand");
    while ($r = safe_fetch($rs)) {
        if (isset($out[intval($r->StStand)])) $out[intval($r->StStand)]['staff'] = intval($r->N);
    }
    $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS N FROM ShopStaff WHERE SfTournament = $tourId AND SfKind = 'ORGANISER'
        AND SfStatus IN ('active', 'locked') AND SfLastSeen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)"));
    if ($r) $organisers = intval($r->N);
    foreach ($out as $sd => $v) $out[$sd]['staff'] += $organisers;
    return $out;
}

/** Local time of an order step for a list: the hour today, day and hour otherwise. */
function shp_rep_time($dt, $today)
{
    $dt = (string) $dt;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/', $dt, $m)) return '';
    $hm = $m[4] . ':' . $m[5];
    return "$m[1]-$m[2]-$m[3]" === $today ? $hm : $m[3] . '/' . $m[2] . ' ' . $hm;
}

/**
 * Orders of a competition for the live list, newest first, each with its lines in one short
 * text. $q: order number, customer name or licence. Returns ['rows' => [...], 'total' => matching].
 * $status: open (placed, preparing, ready), all, or one status. $pay: all, unpaid (unpaid or
 * partial), paid, tab, refunded.
 */
function shp_rep_orders($tourId, $standId, $status, $pay, $q, $limit = 200)
{
    shp_schema();
    $tourId = intval($tourId);
    $w = "ShTournament = $tourId";
    if (intval($standId) > 0) $w .= " AND ShStand = " . intval($standId);
    if ($status === 'open') $w .= " AND ShStatus IN ('placed', 'preparing', 'ready')";
    elseif (in_array($status, shp_order_statuses(), true) || $status === 'cancelled') $w .= " AND ShStatus = " . StrSafe_DB($status);
    if ($pay === 'unpaid') $w .= " AND ShPayState IN ('unpaid', 'partial')";
    elseif (in_array($pay, array('paid', 'tab', 'refunded'), true)) $w .= " AND ShPayState = " . StrSafe_DB($pay);
    $q = mb_substr(trim((string) preg_replace('/[\p{C}]+/u', ' ', (string) $q)), 0, 40);
    if ($q !== '') {
        $like = StrSafe_DB('%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $q) . '%');
        $w .= " AND (ShNumber LIKE $like OR ShCustLabel LIKE $like OR ShLicence LIKE $like)";
    }
    $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS N FROM ShopOrders WHERE $w"));
    $total = $r ? intval($r->N) : 0;
    $orders = shp_orders_fetch($w, 'ShId DESC', intval($limit));
    $stands = shp_stands($tourId, false);
    $win = shp_window($tourId);
    $today = $win ? substr((string) $win['today'], 0, 10) : '';   // bytes: an ISO date
    $rows = array();
    foreach ($orders as $o) {
        $lines = array();
        foreach ($o['lines'] as $l) {
            $left = $l['qty'] - $l['cancelled'];
            if ($left > 0) $lines[] = $left . ' × ' . $l['label'];
        }
        $stand = $stands[$o['stand']] ?? null;
        $mode = $stand ? (string) $stand->SdMode : 'direct';
        $moves = array();
        if (!in_array($o['status'], array('cancelled'), true)) {
            foreach (shp_order_statuses() as $to) {
                if ($to !== $o['status'] && shp_transition_allowed($mode, $o['status'], $to)) $moves[] = $to;
            }
        }
        $cust = $o['label'];
        if ($cust === '') $cust = shp_t($o['cust_kind'] === 'GUEST' ? 'ShRepCustGuest' : ($o['cust_kind'] === 'ARCHER' ? 'ShRepCustArcher' : 'ShRepCustCounter'));
        $rows[] = array(
            'id' => $o['id'], 'number' => $o['number'], 'stand' => $o['stand'], 'stand_name' => shp_rep_stand_name($stand),
            'status' => $o['status'], 'status_label' => shp_status_label($o['status']),
            'pay_state' => $o['pay_state'], 'pay_label' => shp_pay_state_label($o['pay_state']),
            'pay_mode' => $o['pay_mode'], 'mode_label' => shp_pay_mode_label($o['pay_mode']),
            'total' => $o['total'], 'paid' => $o['paid'], 'customer' => $cust, 'channel' => $o['channel'],
            'created' => shp_rep_time($o['created'], $today), 'lines' => implode(', ', $lines), 'note' => $o['note'],
            'moves' => $moves, 'can_cancel' => !in_array($o['status'], array('delivered', 'cancelled'), true),
        );
    }
    return array('rows' => $rows, 'total' => $total);
}

/* ------------------------------------------------------------------ */
/* Days and closings                                                   */
/* ------------------------------------------------------------------ */

/** Days (YYYY-MM-DD, oldest first) with an order or a journal line of the stands. */
function shp_rep_days($tourId)
{
    shp_schema();
    $tourId = intval($tourId);
    $days = array();
    $rs = safe_r_sql("SELECT DATE(ShCreated) AS D FROM ShopOrders WHERE ShTournament = $tourId AND ShCreated IS NOT NULL
        UNION
        SELECT DATE(BlgWhen) FROM BookingLedger WHERE BlgTournament = $tourId AND BlgStatus = 'done'
            AND (BlgStand > 0 OR BlgOrder > 0) AND BlgWhen IS NOT NULL
        ORDER BY D");
    while ($r = safe_fetch($rs)) if ($r->D !== null) $days[] = (string) $r->D;
    return array_values(array_unique($days));
}

/**
 * Every (day, stand) with some activity, for the list of cash closings: ['day', 'stand',
 * 'orders', 'sales', 'cash' (net cash of the journal), 'net' (net of every means)], by day then
 * in the stands' order. Two grouped queries.
 */
function shp_rep_closings($tourId)
{
    shp_schema();
    $tourId = intval($tourId);
    $map = array();
    $rs = safe_r_sql("SELECT DATE(ShCreated) AS D, ShStand, COUNT(*) AS N, COALESCE(SUM(ShTotal), 0) AS T
        FROM ShopOrders WHERE ShTournament = $tourId AND ShCreated IS NOT NULL AND ShStatus <> 'cancelled'
        GROUP BY D, ShStand");
    while ($r = safe_fetch($rs)) {
        $map[$r->D . '|' . intval($r->ShStand)] = array('day' => (string) $r->D, 'stand' => intval($r->ShStand),
            'orders' => intval($r->N), 'sales' => shp_rep_num($r->T), 'cash' => 0.0, 'net' => 0.0);
    }
    $rs = safe_r_sql("SELECT DATE(BlgWhen) AS D, BlgStand, SUM(IF(BlgMethod = 'cash', BlgAmount, 0)) AS C, SUM(BlgAmount) AS A
        FROM BookingLedger WHERE BlgTournament = $tourId AND BlgStatus = 'done' AND BlgStand > 0 AND BlgWhen IS NOT NULL
        GROUP BY D, BlgStand");
    while ($r = safe_fetch($rs)) {
        $k = $r->D . '|' . intval($r->BlgStand);
        if (!isset($map[$k])) $map[$k] = array('day' => (string) $r->D, 'stand' => intval($r->BlgStand), 'orders' => 0, 'sales' => 0.0, 'cash' => 0.0, 'net' => 0.0);
        $map[$k]['cash'] = shp_rep_num($r->C);
        $map[$k]['net'] = shp_rep_num($r->A);
    }
    $order = array_flip(array_keys(shp_stands($tourId, false)));
    uasort($map, function ($a, $b) use ($order) {
        return strcmp($a['day'], $b['day']) ?: (($order[$a['stand']] ?? 999999) <=> ($order[$b['stand']] ?? 999999));
    });
    return array_values($map);
}

/* ------------------------------------------------------------------ */
/* Sales                                                               */
/* ------------------------------------------------------------------ */

/**
 * Orders and sales of a filter: ['orders', 'sales' (value of the lines not cancelled), 'tab_orders',
 * 'tab_amount' (put on an account), 'cancelled_orders', 'cancelled_qty' (articles cancelled in
 * orders that went on), 'cancelled_value' (value of everything cancelled)].
 */
function shp_rep_summary(array $f)
{
    shp_schema();
    $w = shp_rep_orders_where($f);
    $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS N, COALESCE(SUM(ShTotal), 0) AS T,
            COALESCE(SUM(ShPayMode = 'tab'), 0) AS TabN, COALESCE(SUM(IF(ShPayMode = 'tab', ShTotal, 0)), 0) AS TabT
        FROM ShopOrders WHERE $w AND ShStatus <> 'cancelled'"));
    $out = array('orders' => intval($r->N), 'sales' => shp_rep_num($r->T), 'tab_orders' => intval($r->TabN),
        'tab_amount' => shp_rep_num($r->TabT), 'cancelled_orders' => 0, 'cancelled_qty' => 0, 'cancelled_value' => 0.0);
    $r = safe_fetch(safe_r_sql("SELECT COUNT(DISTINCT IF(ShStatus = 'cancelled', ShId, NULL)) AS Co,
            COALESCE(SUM(IF(ShStatus <> 'cancelled', SnCancelled, 0)), 0) AS Cq,
            COALESCE(SUM(SnCancelled * SnUnit), 0) AS Cv
        FROM ShopOrderLines INNER JOIN ShopOrders ON ShId = SnOrder
        WHERE $w AND SnCancelled > 0"));
    if ($r) {
        $out['cancelled_orders'] = intval($r->Co);
        $out['cancelled_qty'] = intval($r->Cq);
        $out['cancelled_value'] = shp_rep_num($r->Cv);
    }
    // Orders cancelled before anything was ordered have no line to count: whole cancelled orders
    // are counted from the header when the lines query could not see them.
    $r = safe_fetch(safe_r_sql("SELECT COUNT(*) AS N FROM ShopOrders WHERE $w AND ShStatus = 'cancelled'"));
    if ($r) $out['cancelled_orders'] = max($out['cancelled_orders'], intval($r->N));
    return $out;
}

/**
 * Quantities and amounts by article (one row per stand, label and unit price): [['stand',
 * 'product', 'variant', 'label', 'unit', 'category', 'qty', 'amount']], in the stands' order,
 * then by category and label. Cancelled quantities are left out.
 */
function shp_rep_sales(array $f)
{
    shp_schema();
    $rs = safe_r_sql("SELECT ShStand, SnProduct, SnVariant, SnLabel, SnUnit, COALESCE(SpCategory, '') AS Cat,
            SUM(SnQty - SnCancelled) AS Q, SUM((SnQty - SnCancelled) * SnUnit) AS A
        FROM ShopOrderLines
        INNER JOIN ShopOrders ON ShId = SnOrder
        LEFT JOIN ShopProducts ON SpId = SnProduct
        WHERE " . shp_rep_orders_where($f) . " AND ShStatus <> 'cancelled' AND SnQty > SnCancelled
        GROUP BY ShStand, SnProduct, SnVariant, SnLabel, SnUnit, Cat
        ORDER BY ShStand, Cat, SnLabel, SnUnit");
    $rows = array();
    while ($r = safe_fetch($rs)) {
        $rows[] = array('stand' => intval($r->ShStand), 'product' => intval($r->SnProduct), 'variant' => intval($r->SnVariant),
            'label' => (string) $r->SnLabel, 'unit' => (float) $r->SnUnit, 'category' => (string) $r->Cat,
            'qty' => intval($r->Q), 'amount' => shp_rep_num($r->A));
    }
    $order = array_flip(array_keys(shp_stands($f['tour'], false)));
    usort($rows, function ($a, $b) use ($order) {
        return (($order[$a['stand']] ?? 999999) <=> ($order[$b['stand']] ?? 999999))
            ?: strcasecmp($a['category'], $b['category']) ?: strcasecmp($a['label'], $b['label']) ?: ($a['unit'] <=> $b['unit']);
    });
    return $rows;
}

/** Sales rows summed by category (across stands): [['category', 'qty', 'amount']], best first. */
function shp_rep_by_category(array $sales)
{
    $cat = array();
    foreach ($sales as $s) {
        $k = $s['category'];
        if (!isset($cat[$k])) $cat[$k] = array('category' => $k, 'qty' => 0, 'amount' => 0.0);
        $cat[$k]['qty'] += $s['qty'];
        $cat[$k]['amount'] = round($cat[$k]['amount'] + $s['amount'], 2);
    }
    usort($cat, function ($a, $b) { return $b['amount'] <=> $a['amount'] ?: strcasecmp($a['category'], $b['category']); });
    return $cat;
}

/** Sales rows summed by stand: [SdId => ['qty', 'amount']]. */
function shp_rep_by_stand(array $sales)
{
    $out = array();
    foreach ($sales as $s) {
        if (!isset($out[$s['stand']])) $out[$s['stand']] = array('qty' => 0, 'amount' => 0.0);
        $out[$s['stand']]['qty'] += $s['qty'];
        $out[$s['stand']]['amount'] = round($out[$s['stand']]['amount'] + $s['amount'], 2);
    }
    return $out;
}

/** Orders and amount by hour of creation, to spot the peaks: [['day', 'hour', 'orders', 'amount']]. */
function shp_rep_hours(array $f)
{
    shp_schema();
    $rs = safe_r_sql("SELECT DATE(ShCreated) AS D, HOUR(ShCreated) AS H, COUNT(*) AS N, COALESCE(SUM(ShTotal), 0) AS T
        FROM ShopOrders WHERE " . shp_rep_orders_where($f) . " AND ShStatus <> 'cancelled' AND ShCreated IS NOT NULL
        GROUP BY D, H ORDER BY D, H");
    $rows = array();
    while ($r = safe_fetch($rs)) {
        $rows[] = array('day' => (string) $r->D, 'hour' => intval($r->H), 'orders' => intval($r->N), 'amount' => shp_rep_num($r->T));
    }
    return $rows;
}

/* ------------------------------------------------------------------ */
/* Money                                                               */
/* ------------------------------------------------------------------ */

/** A group of the cash figures: what was collected, refunded, cancelled or rejected, and the net. */
function shp_rep_cash_blank()
{
    return array('payment' => 0.0, 'refund' => 0.0, 'other' => 0.0, 'net' => 0.0, 'lines' => 0);
}

function shp_rep_cash_add(array &$g, $kind, $amount, $n)
{
    $key = $kind === 'payment' ? 'payment' : ($kind === 'refund' ? 'refund' : 'other');
    $g[$key] = round($g[$key] + $amount, 2);
    $g['net'] = round($g['net'] + $amount, 2);
    $g['lines'] += $n;
}

/**
 * Journal figures of a filter in ONE grouped query, as three views of the same lines:
 * ['total', 'methods' => [code => group], 'stands' => [SdId => group], 'staff' => [SfId or 0 =>
 * group]]. A group: payment (collected), refund (negative), other (cancellations and rejections,
 * signed), net (the sum of the three — what the journal holds), lines. A cancellation keeps the
 * stand and the means of the line it cancels, so the cash of a stand stays right.
 */
function shp_rep_cash(array $f)
{
    shp_schema();
    $out = array('total' => shp_rep_cash_blank(), 'methods' => array(), 'stands' => array(), 'staff' => array());
    $rs = safe_r_sql("SELECT BlgStand, BlgMethod, BlgKind, BlgStaff, COUNT(*) AS N, COALESCE(SUM(BlgAmount), 0) AS A
        FROM BookingLedger WHERE " . shp_rep_ledger_where($f) . "
        GROUP BY BlgStand, BlgMethod, BlgKind, BlgStaff");
    while ($r = safe_fetch($rs)) {
        $a = shp_rep_num($r->A);
        $n = intval($r->N);
        $m = (string) $r->BlgMethod;
        $s = intval($r->BlgStand);
        $p = intval($r->BlgStaff);
        if (!isset($out['methods'][$m])) $out['methods'][$m] = shp_rep_cash_blank();
        if (!isset($out['stands'][$s])) $out['stands'][$s] = shp_rep_cash_blank();
        if (!isset($out['staff'][$p])) $out['staff'][$p] = shp_rep_cash_blank();
        shp_rep_cash_add($out['total'], $r->BlgKind, $a, $n);
        shp_rep_cash_add($out['methods'][$m], $r->BlgKind, $a, $n);
        shp_rep_cash_add($out['stands'][$s], $r->BlgKind, $a, $n);
        shp_rep_cash_add($out['staff'][$p], $r->BlgKind, $a, $n);
    }
    // Methods in the order of the journal's means, then the unknown ones.
    $known = function_exists('bk_payment_methods') ? array_keys(bk_payment_methods()) : array();
    $sorted = array();
    foreach ($known as $k) if (isset($out['methods'][$k])) $sorted[$k] = $out['methods'][$k];
    foreach ($out['methods'] as $k => $g) if (!isset($sorted[$k])) $sorted[$k] = $g;
    $out['methods'] = $sorted;
    $order = array_flip(array_keys(shp_stands($f['tour'], false)));
    uksort($out['stands'], function ($a, $b) use ($order) { return ($order[$a] ?? 999999) <=> ($order[$b] ?? 999999); });
    uasort($out['staff'], function ($a, $b) { return $b['net'] <=> $a['net']; });
    return $out;
}

/** Label of a means of payment of the journal ('' = not given). */
function shp_rep_method_label($code)
{
    $m = function_exists('bk_payment_methods') ? bk_payment_methods() : array();
    if ($code === '') return shp_t('ShRepNoMethod');
    return $m[$code] ?? (string) $code;
}

/**
 * Refunds of a filter, oldest first: [['id', 'when', 'stand', 'order' (number), 'amount'
 * (negative), 'method', 'label' (with the reason), 'staff' (id), 'cancelled' (entered by mistake
 * and cancelled)]].
 */
function shp_rep_refunds(array $f)
{
    shp_schema();
    $rs = safe_r_sql("SELECT BlgId, BlgWhen, BlgStand, BlgAmount, BlgMethod, BlgLabel, BlgStaff, BlgCancelled, ShNumber
        FROM BookingLedger LEFT JOIN ShopOrders ON ShId = BlgOrder
        WHERE " . shp_rep_ledger_where($f) . " AND BlgKind = 'refund'
        ORDER BY BlgWhen, BlgId");
    $rows = array();
    while ($r = safe_fetch($rs)) {
        $rows[] = array('id' => intval($r->BlgId), 'when' => (string) $r->BlgWhen, 'stand' => intval($r->BlgStand),
            'order' => (string) $r->ShNumber, 'amount' => shp_rep_num($r->BlgAmount), 'method' => (string) $r->BlgMethod,
            'label' => (string) $r->BlgLabel, 'staff' => intval($r->BlgStaff), 'cancelled' => intval($r->BlgCancelled) > 0);
    }
    return $rows;
}

/** Net of the food & shop part of the journal for a competition (the figure the cash view must equal). */
function shp_rep_journal_net($tourId)
{
    shp_schema();
    $r = safe_fetch(safe_r_sql("SELECT COALESCE(SUM(BlgAmount), 0) AS A FROM BookingLedger
        WHERE BlgTournament = " . intval($tourId) . " AND BlgStatus = 'done' AND (BlgStand > 0 OR BlgOrder > 0)"));
    return $r ? shp_rep_num($r->A) : 0.0;
}

/* ------------------------------------------------------------------ */
/* Stock                                                               */
/* ------------------------------------------------------------------ */

/**
 * Limited stocks of a competition (one stand or all): [['stand', 'product', 'variant', 'label',
 * 'initial' (entered or corrected by hand), 'restock', 'sold' (net of cancellations), 'lost',
 * 'left', 'low']]. initial + restock − sold − lost = left. Unlimited products have no stock to
 * report. Not filtered by day: a stock is a state, not a flow of the day.
 */
function shp_rep_stock($tourId, $standId = 0)
{
    shp_schema();
    $tourId = intval($tourId);
    $moves = array();
    $rs = safe_r_sql("SELECT SmProduct, SmVariant, SmReason, SUM(SmDelta) AS D FROM ShopStockMoves
        WHERE SmTournament = $tourId GROUP BY SmProduct, SmVariant, SmReason");
    while ($r = safe_fetch($rs)) {
        $k = intval($r->SmProduct) . ':' . intval($r->SmVariant);
        if (!isset($moves[$k])) $moves[$k] = array('initial' => 0, 'restock' => 0, 'sold' => 0, 'lost' => 0);
        $d = intval($r->D);
        if ($r->SmReason === 'order' || $r->SmReason === 'cancel') $moves[$k]['sold'] -= $d;
        elseif ($r->SmReason === 'loss') $moves[$k]['lost'] -= $d;
        elseif ($r->SmReason === 'restock') $moves[$k]['restock'] += $d;
        else $moves[$k]['initial'] += $d;
    }
    $rs = safe_r_sql("SELECT SpId, SpStand, SpCategory, SpName, SpStock, SpStockAlert, SpOptionName, SwId, SwLabel, SwStock
        FROM ShopProducts
        INNER JOIN ShopStands ON SdId = SpStand
        LEFT JOIN ShopVariants ON SwProduct = SpId
        WHERE SpTournament = $tourId" . (intval($standId) > 0 ? " AND SpStand = " . intval($standId) : "") . "
        ORDER BY SdOrder, SdId, SpOrder, SpId, SwOrder, SwId");
    $rows = array();
    while ($r = safe_fetch($rs)) {
        $variant = $r->SwId !== null ? intval($r->SwId) : 0;
        $stock = $variant > 0 ? $r->SwStock : $r->SpStock;
        if ($stock === null) continue;
        if ($variant === 0 && trim((string) $r->SpOptionName) !== '') continue;   // its stock is on its variants
        $m = $moves[intval($r->SpId) . ':' . $variant] ?? array('initial' => 0, 'restock' => 0, 'sold' => 0, 'lost' => 0);
        $alert = $r->SpStockAlert === null ? null : intval($r->SpStockAlert);
        $rows[] = array('stand' => intval($r->SpStand), 'product' => intval($r->SpId), 'variant' => $variant,
            'label' => (string) $r->SpName . ($variant > 0 ? ' — ' . $r->SwLabel : ''), 'category' => (string) $r->SpCategory,
            'initial' => $m['initial'], 'restock' => $m['restock'], 'sold' => $m['sold'], 'lost' => $m['lost'],
            'left' => intval($stock), 'low' => $alert !== null && intval($stock) <= $alert);
    }
    return $rows;
}

/* ------------------------------------------------------------------ */
/* Accounts still open                                                 */
/* ------------------------------------------------------------------ */

/**
 * People who put something on their account and still owe it: ['rows' => shp_accounts_open() rows,
 * 'remaining' => their sum]. Empty when the payments part is not installed.
 */
function shp_rep_open_accounts($tourId)
{
    $out = array('rows' => array(), 'remaining' => 0.0);
    if (!function_exists('shp_accounts_open')) {
        $file = __DIR__ . '/pay.php';
        if (is_file($file)) require_once $file;
    }
    if (!function_exists('shp_accounts_open')) return $out;
    $out['rows'] = shp_accounts_open($tourId);
    foreach ($out['rows'] as $a) $out['remaining'] = round($out['remaining'] + $a['remaining'], 2);
    return $out;
}

/* ------------------------------------------------------------------ */
/* CSV                                                                 */
/* ------------------------------------------------------------------ */

/**
 * A cell of an Excel-friendly CSV (separator ';'). Text typed by customers can start with =, +,
 * - or @, which a spreadsheet would run as a formula: such a cell is prefixed with an apostrophe.
 */
function shp_csv_text($v)
{
    $v = str_replace(array("\r", "\n", "\t"), ' ', (string) $v);
    if ($v !== '' && strpos('=+-@', $v[0]) !== false) $v = "'" . $v;   // bytes: the first character is ASCII
    return '"' . str_replace('"', '""', $v) . '"';
}

/** An amount or quantity for the CSV, with the decimal separator of the visitor's language. */
function shp_csv_num($n, $decimals = 2)
{
    return number_format((float) $n, $decimals, bk_number_seps()['dec'], '');
}

/** One CSV line ready to print: cells already formatted. */
function shp_csv_line(array $cells)
{
    return implode(';', $cells) . "\r\n";
}

/**
 * Sends the lines sold (one CSV line per order line) and ends the script. UTF-8 with a byte-order
 * mark, so that Excel reads the accents; separator ';' as Excel in French expects.
 */
function shp_rep_csv_lines(array $f, $filename)
{
    shp_schema();
    $stands = shp_stands($f['tour'], false);
    $rs = safe_r_sql("SELECT ShNumber, ShStand, ShCreated, ShChannel, ShStatus, ShPayMode, ShCustKind, ShCustLabel,
            COALESCE(SpCategory, '') AS Cat, SnLabel, SnUnit, SnQty, SnCancelled
        FROM ShopOrderLines
        INNER JOIN ShopOrders ON ShId = SnOrder
        LEFT JOIN ShopProducts ON SpId = SnProduct
        WHERE " . shp_rep_orders_where($f) . "
        ORDER BY ShCreated, ShId, SnId");
    shp_csv_headers($filename);
    echo shp_csv_line(array_map('shp_csv_text', array(shp_t('ShRepColDate'), shp_t('ShRepColHour'), shp_t('ShRepColStand'),
        shp_t('ShRepColOrder'), shp_t('ShRepColChannel'), shp_t('ShRepColStatus'), shp_t('ShRepColPayMode'),
        shp_t('ShRepColCustomer'), shp_t('ShRepColCategory'), shp_t('ShRepColProduct'), shp_t('ShRepColUnit'),
        shp_t('ShRepColQtyOrdered'), shp_t('ShRepColQtyCancelled'), shp_t('ShRepColQtySold'), shp_t('ShRepColAmount'))));
    while ($r = safe_fetch($rs)) {
        $sold = $r->ShStatus === 'cancelled' ? 0 : intval($r->SnQty) - intval($r->SnCancelled);
        [$date, $hour] = shp_csv_when($r->ShCreated);
        echo shp_csv_line(array(shp_csv_text($date), shp_csv_text($hour), shp_csv_text(shp_rep_stand_name($stands[intval($r->ShStand)] ?? null)),
            shp_csv_text($r->ShNumber), shp_csv_text(shp_rep_channel_label($r->ShChannel)), shp_csv_text(shp_status_label($r->ShStatus)),
            shp_csv_text(shp_pay_mode_label($r->ShPayMode)), shp_csv_text($r->ShCustLabel), shp_csv_text($r->Cat),
            shp_csv_text($r->SnLabel), shp_csv_num($r->SnUnit), intval($r->SnQty), intval($r->SnCancelled), $sold,
            shp_csv_num($sold * (float) $r->SnUnit)));
    }
    exit;
}

/** Sends the journal lines of the stands (collections, refunds, cancellations) and ends the script. */
function shp_rep_csv_cash(array $f, $filename)
{
    shp_schema();
    $stands = shp_stands($f['tour'], false);
    $kinds = bk_ledger_kinds();
    $rs = safe_r_sql("SELECT BlgId, BlgWhen, BlgStand, BlgKind, BlgMethod, BlgAmount, BlgLabel, BlgStaff, BlgCancelled, ShNumber
        FROM BookingLedger LEFT JOIN ShopOrders ON ShId = BlgOrder
        WHERE " . shp_rep_ledger_where($f) . "
        ORDER BY BlgWhen, BlgId");
    shp_csv_headers($filename);
    echo shp_csv_line(array_map('shp_csv_text', array(shp_t('ShRepColDate'), shp_t('ShRepColHour'), shp_t('ShRepColStand'),
        shp_t('ShRepColOrder'), shp_t('ShRepColKind'), shp_t('ShRepColMethod'), shp_t('ShRepColAmount'), shp_t('ShRepColLabel'),
        shp_t('ShRepColStaff'), shp_t('ShRepColCancelled'))));
    while ($r = safe_fetch($rs)) {
        [$date, $hour] = shp_csv_when($r->BlgWhen);
        echo shp_csv_line(array(shp_csv_text($date), shp_csv_text($hour), shp_csv_text(shp_rep_stand_name($stands[intval($r->BlgStand)] ?? null)),
            shp_csv_text($r->ShNumber), shp_csv_text($kinds[$r->BlgKind] ?? $r->BlgKind), shp_csv_text(shp_rep_method_label((string) $r->BlgMethod)),
            shp_csv_num($r->BlgAmount), shp_csv_text($r->BlgLabel), shp_csv_text(shp_rep_staff_name($r->BlgStaff)),
            shp_csv_text(intval($r->BlgCancelled) > 0 ? shp_t('ShRepYes') : '')));
    }
    exit;
}

/** Label of an order's channel (phone, counter, pre-order). */
function shp_rep_channel_label($channel)
{
    $l = array('online' => 'ShRepChanOnline', 'counter' => 'ShRepChanCounter', 'preorder' => 'ShRepChanPreorder');
    return isset($l[$channel]) ? shp_t($l[$channel]) : (string) $channel;
}

/** [date dd/mm/yyyy, hour hh:mm] of a stored local date-time. */
function shp_csv_when($dt)
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/', (string) $dt, $m)) return array('', '');
    return array($m[3] . '/' . $m[2] . '/' . $m[1], $m[4] . ':' . $m[5]);
}

function shp_csv_headers($filename)
{
    $filename = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $filename);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, no-store');
    echo "\u{FEFF}";   // byte-order mark: without it Excel reads the file as ANSI and breaks the accents
}
