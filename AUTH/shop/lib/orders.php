<?php
/**
 * lib/orders.php — the order engine: creation, status changes, cancellation, queue of a stand,
 * orders of a customer.
 *
 *                ┌──────── cancel (stock given back; a paid order must be refunded first) ────────┐
 *  placed ──(prep)──► preparing ──► ready ──► delivered                                       cancelled
 *     └──────────────(direct stand)───────────────┘
 *
 * The order status (ShStatus) and the payment state (ShPayState) are two separate axes. The
 * payment journal (BookingLedger, lib/pay.php) is the truth of what was paid.
 *
 * Concurrency, the reason for the transaction in shp_order_create():
 *  - the stand row is locked FIRST (order number), so the orders of one stand are created one
 *    after the other and their stock rows are always taken in the same order (product, variant):
 *    no deadlock between two phones;
 *  - the idempotency key is UNIQUE: the same request sent twice (double tap, network retry)
 *    returns the order created the first time;
 *  - a missing stock rolls the whole order back, number included.
 * A status change is one UPDATE conditioned on the current status: when two volunteers press
 * "Ready" at the same time, one wins and the other gets the fresh state.
 */

if (defined('SHP_ORDERS_LOADED')) return;
define('SHP_ORDERS_LOADED', true);

require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/customer.php';
// The trust index gates deferred payment in shp_order_create(); without it the gate is skipped.
if (is_file(dirname(__DIR__, 2) . '/trust-lib.php')) require_once dirname(__DIR__, 2) . '/trust-lib.php';

define('SHP_MAX_LINES', 50);
define('SHP_MAX_QTY', 99);
define('SHP_SCHEDULE_LEAD', 15);   // minutes before its service time a scheduled order joins the queue
define('SHP_WAIT_MAX', 240);       // longest waiting time a volunteer can announce, in minutes

/** Statuses in their natural order (cancelled apart). */
function shp_order_statuses()
{
    return array('placed', 'preparing', 'ready', 'delivered');
}

/** Label of an order status. */
function shp_status_label($status)
{
    $l = array('placed' => 'ShStPlaced', 'preparing' => 'ShStPreparing', 'ready' => 'ShStReady',
        'delivered' => 'ShStDelivered', 'cancelled' => 'ShStCancelled');
    return isset($l[$status]) ? shp_t($l[$status]) : (string) $status;
}

/** Label of a payment state. */
function shp_pay_state_label($state)
{
    $l = array('unpaid' => 'ShPayUnpaid', 'partial' => 'ShPayPartial', 'paid' => 'ShPayPaid',
        'tab' => 'ShPayTab', 'refunded' => 'ShPayRefunded');
    return isset($l[$state]) ? shp_t($l[$state]) : (string) $state;
}

/** Label of a payment mode. */
function shp_pay_mode_label($mode)
{
    $l = array('now' => 'ShModeNow', 'pickup' => 'ShModePickup', 'tab' => 'ShModeTab', 'online' => 'ShModeOnline');
    return isset($l[$mode]) ? shp_t($l[$mode]) : (string) $mode;
}

/** Error answer with a translated message and a machine code for the page's script. */
function shp_err($code, $key, $arg = null, array $extra = array())
{
    return array_merge($extra, array('error' => 1, 'code' => $code, 'msg' => shp_t($key, $arg)));
}

/** Order row by idempotency key, read on the write connection (sees what was just committed). */
function shp_order_by_idem($tourId, $idem)
{
    $r = safe_fetch(safe_w_sql("SELECT * FROM ShopOrders WHERE ShTournament = " . intval($tourId)
        . " AND ShIdem = " . StrSafe_DB((string) $idem)));
    return $r ?: null;
}

/** Order header row, fresh (write connection). */
function shp_order_row($orderId)
{
    $r = safe_fetch(safe_w_sql("SELECT * FROM ShopOrders WHERE ShId = " . intval($orderId)));
    return $r ?: null;
}

function shp_order_answer($row, $existing)
{
    return array('error' => 0, 'order' => intval($row->ShId), 'number' => (string) $row->ShNumber,
        'total' => (float) $row->ShTotal, 'status' => (string) $row->ShStatus, 'existing' => (bool) $existing);
}

/**
 * A service time asked for an order, local time of the competition: 'YYYY-MM-DD HH:MM' (a 'T'
 * accepted) or 'HH:MM' for today. It must fall on a day of the competition, between 06:00 and
 * 23:59, and at least 5 minutes from now. Returns 'YYYY-MM-DD HH:MM:00', '' when none was asked,
 * false when it is not acceptable.
 */
function shp_wanted_parse($tourId, $value)
{
    $value = trim((string) $value);
    if ($value === '') return '';
    $w = shp_window($tourId);
    if (!$w || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', (string) $w['now'])) return false;
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)) $value = $w['today'] . ' ' . $m[1] . ':' . $m[2];
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{1,2}):(\d{2})$/', $value, $m)) return false;
    $h = intval($m[2]);
    $mi = intval($m[3]);
    if ($h < 6 || $h > 23 || $mi > 59 || $m[1] < $w['from'] || $m[1] > $w['to']) return false;
    $dt = sprintf('%s %02d:%02d:00', $m[1], $h, $mi);
    // Both are local times of the competition: read alike, their difference is right.
    if (strtotime($dt . ' UTC') < strtotime($w['now'] . ' UTC') + 300) return false;
    return $dt;
}

/** Minutes from now (local time of the competition) to a local date-time; negative when past. */
function shp_minutes_to($tourId, $dateTime)
{
    $w = shp_window($tourId);
    if (!$w || (string) $dateTime === '') return 0;
    return (int) ceil((strtotime($dateTime . ' UTC') - strtotime($w['now'] . ' UTC')) / 60);
}

/**
 * Creates an order.
 * $lines    [['product' => id, 'variant' => id (0 if none), 'qty' => n], …]
 * $customer from shp_customer(), or ['kind' => 'COUNTER', 'label' => ''] for a counter sale,
 *           or ['kind' => 'ARCHER', 'licence', 'label'] for a licensee served at the counter
 * $opts     channel   'online' (customer's phone), 'counter' (volunteer), 'preorder'
 *           pay_mode  'now' (paid before preparation / at once), 'pickup', 'tab' (on account);
 *                     empty = the usual one for the stand
 *           idem      UUID sent by the phone (required from a phone; generated otherwise)
 *           staff     volunteer id (counter)
 *           note      free text
 *           deliver_now  counter sale handed over at once
 *           wanted    service time asked (shp_wanted_parse), '' = as soon as possible
 *           wait      minutes announced by the volunteer before it is ready (ShReadyBy)
 * Returns ['error' => 0, 'order', 'number', 'total', 'status', 'existing'] or
 *         ['error' => 1, 'code', 'msg', ('line' => index of the faulty line)].
 */
function shp_order_create($tourId, $standId, array $lines, array $customer, array $opts)
{
    shp_schema();
    $tourId = intval($tourId); $standId = intval($standId);
    $channel = (string) ($opts['channel'] ?? 'online');
    if (!in_array($channel, array('online', 'counter', 'preorder'), true)) return shp_err('bad_request', 'ShErrBadRequest');
    $idem = (string) ($opts['idem'] ?? '');
    if ($idem === '') $idem = shp_idem_new();
    elseif (!shp_idem_ok($idem)) return shp_err('bad_request', 'ShErrBadRequest');
    $idem = strtolower($idem);   // bytes: a UUID is hexadecimal ASCII
    $staffId = intval($opts['staff'] ?? 0);
    $note = mb_substr(trim((string) preg_replace('/[\p{C}]+/u', ' ', (string) ($opts['note'] ?? ''))), 0, 160);
    $deliverNow = !empty($opts['deliver_now']) && $channel === 'counter';

    // The same request again: the first answer, nothing rewritten.
    if ($ex = shp_order_by_idem($tourId, $idem)) return shp_order_answer($ex, true);

    $set = shp_settings($tourId);
    if (!$set || intval($set->SgEnabled) !== 1) return shp_err('shop_off', 'ShErrShopOff');
    $stand = shp_stand($standId);
    if (!$stand || intval($stand->SdTournament) !== $tourId || intval($stand->SdActive) !== 1) {
        return shp_err('stand', 'ShErrStand');
    }
    $win = shp_window($tourId);
    $wanted = $deliverNow ? '' : shp_wanted_parse($tourId, $opts['wanted'] ?? '');
    if ($wanted === false) return shp_err('wanted', 'ShErrWanted');
    $wait = null;
    if (!$deliverNow && isset($opts['wait']) && $opts['wait'] !== '' && $opts['wait'] !== null) {
        $wait = max(0, min(SHP_WAIT_MAX, intval($opts['wait'])));
    }

    // Channel.
    if ($channel === 'online') {
        if (intval($stand->SdOnline) !== 1 || intval($stand->SdOpen) !== 1) return shp_err('stand_closed', 'ShErrStandClosed');
        if (!$win || !$win['local_open']) return shp_err('not_now', 'ShErrNotNow');
    } elseif ($channel === 'counter') {
        if ($staffId <= 0) return shp_err('bad_request', 'ShErrBadRequest');
    } elseif (!$win || !$win['preorder_open']) {
        return shp_err('preorder_closed', 'ShErrPreorderClosed');
    }

    // Customer.
    $kind = (string) ($customer['kind'] ?? 'COUNTER');
    if ($kind !== 'ARCHER' && $kind !== 'GUEST') $kind = 'COUNTER';
    $allowedKinds = array('online' => array('ARCHER', 'GUEST'), 'counter' => array('ARCHER', 'GUEST', 'COUNTER'), 'preorder' => array('ARCHER'));
    if (!in_array($kind, $allowedKinds[$channel], true)) return shp_err('customer', $channel === 'preorder' ? 'ShErrPreorderLogin' : 'ShErrCustomer');
    $licence = ''; $guestId = 0; $label = '';
    if ($kind === 'ARCHER') {
        $licence = bk_clean_licence($customer['licence'] ?? '');
        if (!preg_match('/^[A-Z0-9]{1,25}$/', $licence)) return shp_err('customer', 'ShErrCustomer');
        $label = (string) ($customer['label'] ?? '');
    } elseif ($kind === 'GUEST') {
        $guestId = intval($customer['guest'] ?? 0);
        $g = safe_fetch(safe_r_sql("SELECT SuPseudo, SuBlocked FROM ShopGuests WHERE SuId = $guestId AND SuTournament = $tourId"));
        if (!$g || trim((string) $g->SuPseudo) === '') return shp_err('customer', 'ShErrCustomer');
        if (intval($g->SuBlocked) === 1) return shp_err('blocked', 'ShErrBlocked');
        if ($channel === 'online' && intval($set->SgGuests) !== 1) return shp_err('guests_off', 'ShErrGuestsOff');
        $max = intval($set->SgGuestMaxOpen);
        if ($max > 0) {
            $n = safe_fetch(safe_r_sql("SELECT COUNT(*) AS n FROM ShopOrders WHERE ShTournament = $tourId AND ShGuest = $guestId
                AND ShStatus IN ('placed', 'preparing', 'ready') AND ShPayState IN ('unpaid', 'partial')"));
            if ($n && intval($n->n) >= $max) return shp_err('guest_limit', 'ShErrGuestLimit', $max);
        }
        $label = (string) $g->SuPseudo;
    } else {
        $label = (string) ($customer['label'] ?? '');
    }
    $label = mb_substr(trim((string) preg_replace('/[\p{C}]+/u', ' ', $label)), 0, 60);

    // Payment mode.
    $payMode = (string) ($opts['pay_mode'] ?? '');
    $allowedPay = array(
        'online'   => array_merge(array('now', 'tab'), $stand->SdPayWhen === 'pickup' ? array('pickup') : array()),
        'counter'  => array('now', 'pickup', 'tab'),
        'preorder' => array('tab'),
    );
    if ($payMode === '') {
        $payMode = $channel === 'preorder' ? 'tab' : (($channel === 'online' && $stand->SdPayWhen === 'pickup') ? 'pickup' : 'now');
    }
    if (!in_array($payMode, $allowedPay[$channel], true)) return shp_err('pay_mode', 'ShErrPayMode');
    if ($payMode === 'tab') {
        if ($kind !== 'ARCHER' || intval($set->SgTab) !== 1) return shp_err('tab_refused', 'ShErrTabRefused');
        if (intval($set->SgTrustGate) === 1 && function_exists('aut_trust_gate')) {
            $gate = aut_trust_gate($licence, $tourId, $channel === 'preorder' ? 'preorder' : 'shop_tab');
            if (is_array($gate) && empty($gate['allow'])) {
                return array('error' => 1, 'code' => 'trust', 'msg' => (string) ($gate['msg'] ?? shp_t('ShErrTabRefused')));
            }
        }
    }

    // Lines: merged by product and variant, checked against the catalogue.
    $want = array(); $firstIndex = array();
    foreach (array_values($lines) as $i => $l) {
        if (!is_array($l)) return shp_err('bad_request', 'ShErrBadRequest', null, array('line' => $i));
        $p = intval($l['product'] ?? 0); $v = intval($l['variant'] ?? 0); $q = intval($l['qty'] ?? 0);
        if ($p <= 0 || $q < 1 || $q > SHP_MAX_QTY) return shp_err('qty', 'ShErrQty', SHP_MAX_QTY, array('line' => $i));
        $k = "$p:$v";
        if (!isset($want[$k])) { $want[$k] = array('product' => $p, 'variant' => $v, 'qty' => 0); $firstIndex[$k] = $i; }
        $want[$k]['qty'] += $q;
        if ($want[$k]['qty'] > SHP_MAX_QTY) return shp_err('qty', 'ShErrQty', SHP_MAX_QTY, array('line' => $i));
    }
    if (!$want) return shp_err('empty', 'ShErrEmpty');
    if (count($want) > SHP_MAX_LINES) return shp_err('bad_request', 'ShErrBadRequest');

    $pids = array(); $vids = array();
    foreach ($want as $w) { $pids[$w['product']] = 1; if ($w['variant'] > 0) $vids[$w['variant']] = 1; }
    $products = array();
    $rs = safe_r_sql("SELECT * FROM ShopProducts WHERE SpId IN (" . implode(',', array_keys($pids)) . ")");
    while ($r = safe_fetch($rs)) $products[intval($r->SpId)] = $r;
    $variants = array();
    if ($vids) {
        $rs = safe_r_sql("SELECT * FROM ShopVariants WHERE SwId IN (" . implode(',', array_keys($vids)) . ")");
        while ($r = safe_fetch($rs)) $variants[intval($r->SwId)] = $r;
    }

    $perProduct = array(); $productIndex = array();
    foreach ($want as $k => &$w) {
        $idx = $firstIndex[$k];
        $p = $products[$w['product']] ?? null;
        $flag = $channel === 'preorder' ? 'SpPreorder' : 'SpOnsite';
        if (!$p || intval($p->SpTournament) !== $tourId || intval($p->SpStand) !== $standId
            || intval($p->SpActive) !== 1 || intval($p->$flag) !== 1) {
            return shp_err('product', 'ShErrProduct', null, array('line' => $idx));
        }
        if (intval($p->SpAvailable) !== 1) return shp_err('sold_out', 'ShErrSoldOut', (string) $p->SpName, array('line' => $idx));
        $price = (float) $p->SpPrice; $labelLine = (string) $p->SpName;
        if (trim((string) $p->SpOptionName) !== '') {
            $v = $variants[$w['variant']] ?? null;
            if (!$v || intval($v->SwProduct) !== intval($p->SpId)) return shp_err('variant', 'ShErrVariant', null, array('line' => $idx));
            if (intval($v->SwAvailable) !== 1) return shp_err('sold_out', 'ShErrSoldOut', $p->SpName . ' — ' . $v->SwLabel, array('line' => $idx));
            if ($v->SwPrice !== null) $price = (float) $v->SwPrice;
            $labelLine .= ' — ' . $v->SwLabel;
        } elseif ($w['variant'] !== 0) {
            return shp_err('variant', 'ShErrVariant', null, array('line' => $idx));
        }
        $w['unit'] = round($price, 2);
        $w['label'] = mb_substr($labelLine, 0, 160);
        $w['index'] = $idx;
        $perProduct[$w['product']] = ($perProduct[$w['product']] ?? 0) + $w['qty'];
        if (!isset($productIndex[$w['product']])) $productIndex[$w['product']] = $idx;
    }
    unset($w);

    // Limit per person (per order for an anonymous counter sale).
    foreach ($perProduct as $pid => $qty) {
        $maxper = intval($products[$pid]->SpMaxPer);
        if ($maxper <= 0) continue;
        $already = 0;
        if ($kind === 'ARCHER' || $kind === 'GUEST') {
            $who = $kind === 'ARCHER' ? "ShCustKind = 'ARCHER' AND ShLicence = " . StrSafe_DB($licence) : "ShGuest = $guestId";
            $r = safe_fetch(safe_r_sql("SELECT COALESCE(SUM(SnQty - SnCancelled), 0) AS q FROM ShopOrderLines
                INNER JOIN ShopOrders ON ShId = SnOrder
                WHERE ShTournament = $tourId AND ShStatus <> 'cancelled' AND SnProduct = $pid AND $who"));
            $already = $r ? intval($r->q) : 0;
        }
        if ($already + $qty > $maxper) return shp_err('max_per', 'ShErrMaxPer', $maxper, array('line' => $productIndex[$pid]));
    }

    // Stock rows always taken in the same order.
    uasort($want, function ($a, $b) { return array($a['product'], $a['variant']) <=> array($b['product'], $b['variant']); });

    $now = shp_local_now_sql($tourId);
    safe_w_BeginTransaction();
    safe_w_sql("UPDATE ShopStands SET SdNextNo = LAST_INSERT_ID(SdNextNo + 1) WHERE SdId = $standId");
    $seq = intval(safe_w_last_id());
    if ($seq <= 0) {
        safe_w_Rollback();
        return shp_err('internal', 'ShErrInternal');
    }
    $number = sprintf('%s-%03d', $stand->SdPrefix !== '' ? $stand->SdPrefix : 'A', $seq);
    $ok = safe_w_sql("INSERT INTO ShopOrders SET ShTournament = $tourId, ShStand = $standId,
        ShSeq = $seq, ShNumber = " . StrSafe_DB($number) . ",
        ShCustKind = " . StrSafe_DB($kind) . ", ShLicence = " . StrSafe_DB($licence) . ", ShGuest = $guestId,
        ShCustLabel = " . StrSafe_DB($label) . ", ShChannel = " . StrSafe_DB($channel) . ",
        ShStatus = 'placed', ShPayMode = " . StrSafe_DB($payMode) . ",
        ShPayState = " . StrSafe_DB($payMode === 'tab' ? 'tab' : 'unpaid') . ",
        ShIdem = " . StrSafe_DB($idem) . ", ShNote = " . StrSafe_DB($note) . ",
        ShWantedAt = " . ($wanted !== '' ? StrSafe_DB($wanted) : 'NULL') . ",
        ShReadyBy = " . ($wanted !== '' ? StrSafe_DB($wanted) : ($wait !== null ? "DATE_ADD($now, INTERVAL $wait MINUTE)" : 'NULL')) . ",
        ShCreated = $now, ShByStaff = $staffId", false, array(1062));
    if (!$ok) {
        // Same key sent by a concurrent request, which has just committed: its order.
        safe_w_Rollback();
        $ex = shp_order_by_idem($tourId, $idem);
        return $ex ? shp_order_answer($ex, true) : shp_err('internal', 'ShErrInternal');
    }
    $orderId = intval(safe_w_last_id());

    $total = 0.0;
    foreach ($want as $w) {
        if (!shp_stock_reserve($w['product'], $w['variant'], $w['qty'], $orderId, $tourId, $staffId)) {
            $left = shp_stock_left($w['product'], $w['variant']);
            safe_w_Rollback();
            return shp_err('stock', 'ShErrStock', intval($left), array('line' => $w['index'], 'left' => intval($left)));
        }
        safe_w_sql("INSERT INTO ShopOrderLines SET SnOrder = $orderId, SnProduct = " . $w['product'] . ",
            SnVariant = " . $w['variant'] . ", SnLabel = " . StrSafe_DB($w['label']) . ",
            SnUnit = " . StrSafe_DB(number_format($w['unit'], 2, '.', '')) . ", SnQty = " . $w['qty']);
        $total += $w['unit'] * $w['qty'];
    }
    safe_w_sql("UPDATE ShopOrders SET ShTotal = " . StrSafe_DB(number_format(round($total, 2), 2, '.', ''))
        . ($deliverNow ? ", ShStatus = 'delivered', ShDeliveredAt = $now, ShDelivStaff = $staffId" : "")
        . " WHERE ShId = $orderId");
    safe_w_Commit();

    return array('error' => 0, 'order' => $orderId, 'number' => $number, 'total' => round($total, 2),
        'status' => $deliverNow ? 'delivered' : 'placed', 'existing' => false);
}

/** Is the change $from → $to allowed for a stand of this mode? Forward any step, back one step. */
function shp_transition_allowed($mode, $from, $to)
{
    if ($mode === 'direct') {
        return ($from === 'placed' && $to === 'delivered') || ($from === 'delivered' && $to === 'placed');
    }
    $order = array_flip(shp_order_statuses());
    if (!isset($order[$from], $order[$to])) return false;
    return $order[$to] > $order[$from] || $order[$to] === $order[$from] - 1;
}

/**
 * Moves an order to another status ($to: placed, preparing, ready, delivered). Going back one
 * step undoes a mistake (the "Undo" of the till). An order to be paid first ('now') does not
 * leave 'placed' before it is paid; an order paid now or at pickup is not handed over unpaid.
 * Returns ['error' => 0, 'status'] or an error (code 'changed' with the current 'status' when
 * someone else was quicker).
 */
function shp_order_transition($orderId, $to, $staffId = 0)
{
    shp_schema();
    $o = shp_order_row($orderId);
    if (!$o) return shp_err('order', 'ShErrOrder');
    $from = (string) $o->ShStatus;
    if ($from === 'cancelled') return shp_err('cancelled', 'ShErrCancelled', null, array('status' => $from));
    if ($from === $to) return array('error' => 0, 'status' => $to);
    $stand = shp_stand($o->ShStand);
    if (!$stand || !shp_transition_allowed((string) $stand->SdMode, $from, (string) $to)) {
        return shp_err('transition', 'ShErrTransition', null, array('status' => $from));
    }
    $order = array_flip(shp_order_statuses());
    $forward = $order[$to] > $order[$from];
    $paid = (string) $o->ShPayState === 'paid';
    if ($forward && $from === 'placed' && $o->ShPayMode === 'now' && !$paid) {
        return shp_err('pay_first', 'ShErrPayFirst', null, array('status' => $from));
    }
    if ($to === 'delivered' && in_array($o->ShPayMode, array('now', 'pickup'), true) && !$paid) {
        return shp_err('pay_first', 'ShErrPayFirst', null, array('status' => $from));
    }

    $tourId = intval($o->ShTournament);
    $now = shp_local_now_sql($tourId);
    $staffId = intval($staffId);
    $set = array("ShStatus = " . StrSafe_DB($to));
    if ($forward) {
        if ($to === 'preparing') { $set[] = "ShStartedAt = $now"; $set[] = "ShPrepStaff = $staffId"; }
        if ($to === 'ready')     { $set[] = "ShReadyAt = $now"; $set[] = "ShSeen = 0"; }
        if ($to === 'delivered') { $set[] = "ShDeliveredAt = $now"; $set[] = "ShDelivStaff = $staffId"; }
    } else {
        if ($from === 'preparing') { $set[] = "ShStartedAt = NULL"; $set[] = "ShPrepStaff = 0"; }
        if ($from === 'ready')     { $set[] = "ShReadyAt = NULL"; }
        if ($from === 'delivered') { $set[] = "ShDeliveredAt = NULL"; $set[] = "ShDelivStaff = 0"; }
    }
    safe_w_sql("UPDATE ShopOrders SET " . implode(', ', $set) . " WHERE ShId = " . intval($o->ShId)
        . " AND ShStatus = " . StrSafe_DB($from));
    if (safe_w_affected_rows() < 1) {
        $cur = shp_order_row($orderId);
        return shp_err('changed', 'ShErrChanged', null, array('status' => $cur ? (string) $cur->ShStatus : ''));
    }
    // The customer's phone is told, even locked, when they asked for it (lib/push.php).
    if ($to === 'ready' && $forward) {
        require_once __DIR__ . '/push.php';
        shp_push_order_ready(intval($o->ShId));
    }
    return array('error' => 0, 'status' => (string) $to);
}

/**
 * Sets when an order should be ready: in $minutes from now (0 to SHP_WAIT_MAX). Only while it
 * is still to prepare. The customer sees it as the waiting time.
 */
function shp_order_set_wait($orderId, $minutes)
{
    shp_schema();
    $o = shp_order_row($orderId);
    if (!$o) return shp_err('order', 'ShErrOrder');
    if (!in_array((string) $o->ShStatus, array('placed', 'preparing'), true)) {
        return shp_err('transition', 'ShErrTransition', null, array('status' => (string) $o->ShStatus));
    }
    $m = max(0, min(SHP_WAIT_MAX, intval($minutes)));
    safe_w_sql("UPDATE ShopOrders SET ShReadyBy = DATE_ADD(" . shp_local_now_sql(intval($o->ShTournament)) . ", INTERVAL $m MINUTE)
        WHERE ShId = " . intval($o->ShId) . " AND ShStatus IN ('placed', 'preparing')");
    return array('error' => 0, 'wait' => $m);
}

/**
 * Cancels a whole order and gives its stock back. Refused once handed over, or while money is
 * held on it (refund first: lib/pay.php). A customer may only cancel an order not started.
 */
function shp_order_cancel($orderId, $staffId = 0, $byCustomer = false)
{
    shp_schema();
    $o = shp_order_row($orderId);
    if (!$o) return shp_err('order', 'ShErrOrder');
    $from = (string) $o->ShStatus;
    if ($from === 'cancelled') return array('error' => 0, 'status' => 'cancelled');
    if ($from === 'delivered') return shp_err('delivered', 'ShErrDelivered', null, array('status' => $from));
    if ($byCustomer && $from !== 'placed') return shp_err('started', 'ShErrStarted', null, array('status' => $from));
    if ((float) $o->ShPaid > 0.004) return shp_err('refund_first', 'ShErrRefundFirst', null, array('status' => $from));

    $tourId = intval($o->ShTournament);
    $id = intval($o->ShId);
    safe_w_BeginTransaction();
    safe_w_sql("UPDATE ShopOrders SET ShStatus = 'cancelled', ShCancelledAt = " . shp_local_now_sql($tourId) . ", ShTotal = 0
        WHERE ShId = $id AND ShStatus = " . StrSafe_DB($from) . " AND ShPaid <= 0.004");
    if (safe_w_affected_rows() < 1) {
        safe_w_Rollback();
        $cur = shp_order_row($orderId);
        return shp_err('changed', 'ShErrChanged', null, array('status' => $cur ? (string) $cur->ShStatus : ''));
    }
    $rs = safe_w_sql("SELECT SnId, SnProduct, SnVariant, SnQty, SnCancelled FROM ShopOrderLines
        WHERE SnOrder = $id ORDER BY SnProduct, SnVariant");
    $lines = array();
    while ($r = safe_fetch($rs)) $lines[] = $r;
    foreach ($lines as $l) {
        $q = intval($l->SnQty) - intval($l->SnCancelled);
        if ($q > 0) shp_stock_release($l->SnProduct, $l->SnVariant, $q, $id, $tourId, $staffId);
    }
    safe_w_sql("UPDATE ShopOrderLines SET SnCancelled = SnQty WHERE SnOrder = $id");
    safe_w_Commit();
    return array('error' => 0, 'status' => 'cancelled');
}

/**
 * Cancels $qty of one line (stock given back, total recomputed). The whole order becomes
 * cancelled when nothing is left. Refused when the money already held would exceed the new
 * total (refund the difference first).
 */
function shp_order_cancel_line($orderId, $lineId, $qty, $staffId = 0)
{
    shp_schema();
    $o = shp_order_row($orderId);
    if (!$o) return shp_err('order', 'ShErrOrder');
    $from = (string) $o->ShStatus;
    if ($from === 'cancelled') return shp_err('cancelled', 'ShErrCancelled');
    if ($from === 'delivered') return shp_err('delivered', 'ShErrDelivered');
    $id = intval($o->ShId);
    $l = safe_fetch(safe_w_sql("SELECT * FROM ShopOrderLines WHERE SnId = " . intval($lineId) . " AND SnOrder = $id"));
    if (!$l) return shp_err('order', 'ShErrOrder');
    $left = intval($l->SnQty) - intval($l->SnCancelled);
    $qty = intval($qty);
    if ($qty < 1 || $qty > $left) return shp_err('qty', 'ShErrQty', $left);
    $newTotal = round((float) $o->ShTotal - $qty * (float) $l->SnUnit, 2);
    if ((float) $o->ShPaid > $newTotal + 0.004) return shp_err('refund_first', 'ShErrRefundFirst');

    $tourId = intval($o->ShTournament);
    $now = shp_local_now_sql($tourId);
    safe_w_BeginTransaction();
    safe_w_sql("UPDATE ShopOrderLines SET SnCancelled = SnCancelled + $qty
        WHERE SnId = " . intval($l->SnId) . " AND SnQty - SnCancelled >= $qty");
    if (safe_w_affected_rows() < 1) {
        safe_w_Rollback();
        return shp_err('changed', 'ShErrChanged');
    }
    shp_stock_release($l->SnProduct, $l->SnVariant, $qty, $id, $tourId, $staffId);
    $r = safe_fetch(safe_w_sql("SELECT COALESCE(SUM((SnQty - SnCancelled) * SnUnit), 0) AS t,
        COALESCE(SUM(SnQty - SnCancelled), 0) AS q FROM ShopOrderLines WHERE SnOrder = $id"));
    $empty = intval($r->q) <= 0;
    safe_w_sql("UPDATE ShopOrders SET ShTotal = " . StrSafe_DB(number_format((float) $r->t, 2, '.', ''))
        . ($empty ? ", ShStatus = 'cancelled', ShCancelledAt = $now" : "") . " WHERE ShId = $id");
    safe_w_Commit();
    if (function_exists('shp_order_pay_state')) shp_order_pay_state($id);
    return array('error' => 0, 'status' => $empty ? 'cancelled' : $from, 'total' => (float) $r->t);
}

/* ------------------------------------------------------------------ */
/* Reading orders                                                      */
/* ------------------------------------------------------------------ */

/** Order header row as an array for pages and scripts. */
function shp_order_array($r)
{
    return array(
        'id' => intval($r->ShId), 'tournament' => intval($r->ShTournament), 'stand' => intval($r->ShStand),
        'number' => (string) $r->ShNumber, 'seq' => intval($r->ShSeq),
        'cust_kind' => (string) $r->ShCustKind, 'licence' => (string) $r->ShLicence, 'guest' => intval($r->ShGuest),
        'label' => (string) $r->ShCustLabel, 'channel' => (string) $r->ShChannel, 'status' => (string) $r->ShStatus,
        'pay_mode' => (string) $r->ShPayMode, 'pay_state' => (string) $r->ShPayState,
        'total' => (float) $r->ShTotal, 'paid' => (float) $r->ShPaid,
        'due' => round(max(0, (float) $r->ShTotal - (float) $r->ShPaid), 2),
        'note' => (string) $r->ShNote, 'created' => (string) $r->ShCreated, 'started' => (string) $r->ShStartedAt,
        'ready' => (string) $r->ShReadyAt, 'delivered' => (string) $r->ShDeliveredAt,
        'cancelled' => (string) $r->ShCancelledAt, 'by_staff' => intval($r->ShByStaff),
        'prep_staff' => intval($r->ShPrepStaff), 'deliv_staff' => intval($r->ShDelivStaff),
        'seen' => intval($r->ShSeen) === 1,
        'wanted' => (string) ($r->ShWantedAt ?? ''), 'ready_by' => (string) ($r->ShReadyBy ?? ''), 'lines' => array(),
    );
}

/** Orders matching an SQL condition on ShopOrders, with their lines: two queries in all. */
function shp_orders_fetch($where, $orderBy = 'ShId', $limit = 0)
{
    shp_schema();
    $out = array();
    $rs = safe_r_sql("SELECT * FROM ShopOrders WHERE $where ORDER BY $orderBy" . ($limit > 0 ? " LIMIT " . intval($limit) : ""));
    while ($r = safe_fetch($rs)) $out[intval($r->ShId)] = shp_order_array($r);
    if (!$out) return array();
    $rs = safe_r_sql("SELECT * FROM ShopOrderLines WHERE SnOrder IN (" . implode(',', array_keys($out)) . ") ORDER BY SnId");
    while ($l = safe_fetch($rs)) {
        $q = intval($l->SnQty) - intval($l->SnCancelled);
        $out[intval($l->SnOrder)]['lines'][] = array(
            'id' => intval($l->SnId), 'product' => intval($l->SnProduct), 'variant' => intval($l->SnVariant),
            'label' => (string) $l->SnLabel, 'unit' => (float) $l->SnUnit, 'qty' => intval($l->SnQty),
            'cancelled' => intval($l->SnCancelled), 'amount' => round($q * (float) $l->SnUnit, 2),
        );
    }
    return $out;
}

/** One order with its lines (array), or null. With $tourId, only if it belongs to it. */
function shp_order($orderId, $tourId = 0)
{
    $where = "ShId = " . intval($orderId) . (intval($tourId) > 0 ? " AND ShTournament = " . intval($tourId) : "");
    $o = shp_orders_fetch($where);
    return $o ? reset($o) : null;
}

/**
 * Minutes of preparation of an order at a stand: median of the last ten real durations
 * (started → ready) of the day, otherwise the organiser's estimate.
 */
function shp_prep_minutes($stand)
{
    $tourId = intval($stand->SdTournament);
    $rs = safe_r_sql("SELECT TIMESTAMPDIFF(SECOND, ShStartedAt, ShReadyAt) AS s FROM ShopOrders
        WHERE ShTournament = $tourId AND ShStand = " . intval($stand->SdId) . "
          AND ShStartedAt IS NOT NULL AND ShReadyAt IS NOT NULL AND ShReadyAt >= ShStartedAt
          AND DATE(ShReadyAt) = DATE(" . shp_local_now_sql($tourId) . ")
        ORDER BY ShReadyAt DESC LIMIT 10");
    $d = array();
    while ($r = safe_fetch($rs)) $d[] = intval($r->s);
    if (!$d) return max(1, intval($stand->SdPrepMin));
    sort($d);
    $n = count($d);
    $median = $n % 2 ? $d[intdiv($n, 2)] : ($d[$n / 2 - 1] + $d[$n / 2]) / 2;
    return max(1, (int) round($median / 60));
}

/**
 * What a stand has to do now:
 *   to_pay     phone orders to be paid at the counter before preparation;
 *   queue      orders to prepare or hand over, by service time (the time asked, else the time of
 *              the order), each with 'position' and 'eta' (about how many minutes: the time the
 *              volunteers announced when there is one, else the place in the queue);
 *   ready      orders waiting for their customer;
 *   preorders  what comes later: pre-orders without a service time (handed over on the day,
 *              whatever their status), and orders whose service time is more than
 *              SHP_SCHEDULE_LEAD minutes away — sorted by service time.
 * Plus 'avg' (minutes per order), 'parallel' and 'eta_next' (waiting time of a new order).
 */
function shp_queue($standId)
{
    $stand = is_object($standId) ? $standId : shp_stand($standId);
    $out = array('stand' => 0, 'to_pay' => array(), 'queue' => array(), 'ready' => array(), 'preorders' => array(),
        'avg' => 0, 'parallel' => 1, 'eta_next' => 0);
    if (!$stand) return $out;
    $tourId = intval($stand->SdTournament);
    $out['stand'] = intval($stand->SdId);
    $orders = shp_orders_fetch("ShTournament = $tourId AND ShStand = " . intval($stand->SdId)
        . " AND ShStatus IN ('placed', 'preparing', 'ready')", 'COALESCE(ShWantedAt, ShCreated), ShId');
    $avg = $stand->SdMode === 'direct' ? max(1, intval($stand->SdPrepMin)) : shp_prep_minutes($stand);
    $parallel = max(1, intval($stand->SdParallel));
    $out['avg'] = $avg;
    $out['parallel'] = $parallel;
    foreach ($orders as $o) {
        if ($o['channel'] === 'preorder' && $o['wanted'] === '') { $out['preorders'][] = $o; continue; }
        if ($o['status'] === 'ready') { $out['ready'][] = $o; continue; }
        if ($o['status'] === 'placed' && $o['pay_mode'] === 'now' && $o['pay_state'] !== 'paid') { $out['to_pay'][] = $o; continue; }
        if ($o['status'] === 'placed' && $o['wanted'] !== '' && shp_minutes_to($tourId, $o['wanted']) > SHP_SCHEDULE_LEAD) {
            $out['preorders'][] = $o;
            continue;
        }
        $o['position'] = count($out['queue']) + 1;
        $o['eta'] = $o['ready_by'] !== '' ? max(0, shp_minutes_to($tourId, $o['ready_by']))
            : (int) ceil($o['position'] / $parallel) * $avg;
        $out['queue'][] = $o;
    }
    usort($out['preorders'], function ($a, $b) {
        return array($a['wanted'] === '' ? '9999' : $a['wanted'], $a['id']) <=> array($b['wanted'] === '' ? '9999' : $b['wanted'], $b['id']);
    });
    $out['eta_next'] = (int) ceil((count($out['queue']) + 1) / $parallel) * $avg;
    return $out;
}

/**
 * Orders of a customer at a competition (active ones: not delivered nor cancelled), each with
 * its stand's name and, while waiting, its 'position' and 'eta' in the queue, or 'to_pay'.
 */
function shp_customer_orders($tourId, array $customer, $activeOnly = true)
{
    $tourId = intval($tourId);
    $kind = (string) ($customer['kind'] ?? '');
    if ($kind === 'ARCHER' && !empty($customer['licence'])) {
        $who = "ShCustKind = 'ARCHER' AND ShLicence = " . StrSafe_DB(bk_clean_licence($customer['licence']));
    } elseif ($kind === 'GUEST' && intval($customer['guest'] ?? 0) > 0) {
        $who = "ShGuest = " . intval($customer['guest']);
    } else {
        return array();
    }
    $orders = shp_orders_fetch("ShTournament = $tourId AND $who"
        . ($activeOnly ? " AND ShStatus IN ('placed', 'preparing', 'ready')" : ""), 'ShId DESC', $activeOnly ? 0 : 50);
    if (!$orders) return array();
    $stands = shp_stands($tourId, false);
    $queues = array();
    foreach ($orders as &$o) {
        $s = $stands[$o['stand']] ?? null;
        $o['stand_name'] = $s ? (string) $s->SdName : '';
        $o['to_pay'] = false; $o['position'] = 0; $o['eta'] = 0;
        if (!$s || !in_array($o['status'], array('placed', 'preparing'), true)) continue;
        if (!isset($queues[$o['stand']])) $queues[$o['stand']] = shp_queue($s);
        foreach ($queues[$o['stand']]['to_pay'] as $q) if ($q['id'] === $o['id']) $o['to_pay'] = true;
        foreach ($queues[$o['stand']]['queue'] as $q) {
            if ($q['id'] === $o['id']) { $o['position'] = $q['position']; $o['eta'] = $q['eta']; }
        }
    }
    unset($o);
    return array_values($orders);
}
