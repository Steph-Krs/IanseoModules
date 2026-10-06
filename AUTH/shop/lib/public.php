<?php
/**
 * lib/public.php — what the customers' pages share: which way a competition can be ordered from
 * (during it, beforehand, not at all), what a customer may do, the catalogue and the orders as
 * the pages' scripts read them.
 *
 * Nothing here trusts the page: every endpoint calls these functions again.
 */

if (defined('SHP_PUBLIC_LOADED')) return;
define('SHP_PUBLIC_LOADED', true);

require_once __DIR__ . '/orders.php';   // common, catalog, stock, customer
require_once __DIR__ . '/ui.php';
// Trust level of the payer: guards "on account" and pre-orders (orders.php only uses it when loaded).
if (is_file(dirname(__DIR__, 2) . '/trust-lib.php')) require_once dirname(__DIR__, 2) . '/trust-lib.php';

/** Minutes during which a handed over or cancelled order stays in the live answer. */
define('SHP_CUS_RECENT_MIN', 15);

/**
 * How can this competition be ordered from right now?
 *   mode 'onsite'    orders from the phone, during the competition (from the day before);
 *   mode 'preorder'  beforehand, until the deadline the organiser set;
 *   mode 'closed'    neither: 'why' says 'over' (competition finished), 'before' (not yet, 'date'
 *                    = first day orders open) or 'none'.
 */
function shp_cus_mode($tourId)
{
    $w = shp_window($tourId);
    $out = array('mode' => 'closed', 'why' => 'none', 'date' => '');
    if (!$w || $w['to'] === '' || $w['from'] <= '0000-00-00') return $out;
    if ($w['today'] > $w['to']) return array('mode' => 'closed', 'why' => 'over', 'date' => '');
    if ($w['preorder_open'] && !$w['live']) return array('mode' => 'preorder', 'why' => '', 'date' => '');
    if ($w['local_open']) return array('mode' => 'onsite', 'why' => '', 'date' => '');
    return array('mode' => 'closed', 'why' => 'before', 'date' => (string) $w['open_from']);
}

/** Name of a competition. */
function shp_cus_tour_name($tourId)
{
    $r = safe_fetch(safe_r_sql("SELECT ToName FROM Tournament WHERE ToId = " . intval($tourId)));
    return $r ? (string) $r->ToName : '';
}

/**
 * May this customer put an order on their account? Shown in advance so that the option is greyed
 * out with its reason; the engine checks it again at the order.
 * ['show' => the option exists for this customer, 'allowed' => bool, 'msg' => reason when refused]
 */
function shp_cus_tab_state($tourId, $settings, $customer, $mode)
{
    $out = array('show' => false, 'allowed' => false, 'msg' => '');
    if (!$settings || intval($settings->SgTab) !== 1 || !$customer || $customer['kind'] !== 'ARCHER') return $out;
    $out['show'] = true;
    $out['allowed'] = true;
    if (intval($settings->SgTrustGate) === 1 && function_exists('aut_trust_gate')) {
        $g = aut_trust_gate($customer['licence'], intval($tourId), $mode === 'preorder' ? 'preorder' : 'shop_tab');
        if (is_array($g) && empty($g['allow'])) {
            $out['allowed'] = false;
            $out['msg'] = (string) ($g['msg'] ?? '') !== '' ? (string) $g['msg'] : shp_t('ShErrTabRefused');
        }
    }
    return $out;
}

/** Does this order belong to this customer? */
function shp_cus_owns(array $order, array $customer)
{
    if ($customer['kind'] === 'ARCHER') {
        return $order['cust_kind'] === 'ARCHER' && $order['licence'] !== '' && $order['licence'] === $customer['licence'];
    }
    if ($customer['kind'] === 'GUEST') {
        return $order['cust_kind'] === 'GUEST' && $order['guest'] > 0 && $order['guest'] === intval($customer['guest']);
    }
    return false;
}

/**
 * Catalogue as the shop page reads it: the stands that can be ordered from, and their products.
 * Onsite: stands open to phone orders (open or not: a closed one is shown "closed"). Pre-order:
 * the stands that have something to pre-order. Plain lists (their order matters).
 */
function shp_cus_catalog($tourId, $mode, array $tab)
{
    $context = $mode === 'preorder' ? 'preorder' : 'onsite';
    $products = shp_catalog($tourId, 0, $context);
    $withProducts = array();
    foreach ($products as $p) $withProducts[$p['stand']] = true;

    $stands = array();
    foreach (shp_stands($tourId, true) as $s) {
        $id = intval($s->SdId);
        if ($mode === 'preorder') {
            if (empty($withProducts[$id])) continue;
            $open = true;
            $pay = array('tab');
        } else {
            if (intval($s->SdOnline) !== 1) continue;
            $open = intval($s->SdOpen) === 1;
            $pay = array((string) $s->SdPayWhen === 'pickup' ? 'pickup' : 'now');
            if ($tab['show']) $pay[] = 'tab';
        }
        $stands[] = array('id' => $id, 'name' => (string) $s->SdName, 'mode' => (string) $s->SdMode,
            'open' => $open, 'pay' => $pay);
    }
    $ids = array();
    foreach ($stands as $s) $ids[$s['id']] = true;

    $outProducts = array();
    foreach ($products as $p) {
        if (empty($ids[$p['stand']])) continue;
        $cap = $p['maxper'] > 0 ? min($p['maxper'], SHP_MAX_QTY) : SHP_MAX_QTY;
        $variants = array();
        foreach ($p['variants'] as $v) {
            $variants[] = array('id' => $v['id'], 'label' => $v['label'], 'price' => $v['price'],
                'available' => (bool) $v['available'],
                'cap' => $v['stock'] === null ? SHP_MAX_QTY : max(0, min(SHP_MAX_QTY, $v['stock'])));
        }
        $outProducts[] = array('id' => $p['id'], 'stand' => $p['stand'], 'category' => $p['category'],
            'name' => $p['name'], 'description' => $p['description'], 'price' => $p['price'],
            'option' => $p['option'], 'available' => (bool) $p['available'], 'low' => (bool) $p['low'],
            'maxper' => $cap, 'cap' => $p['option'] !== '' || $p['stock'] === null ? SHP_MAX_QTY : max(0, min(SHP_MAX_QTY, $p['stock'])),
            'variants' => $variants);
    }
    return array('stands' => $stands, 'products' => $outProducts);
}

/**
 * One order as the customer's page reads it. No other customer's data: no label, no staff.
 * can_cancel: still "received" and nothing paid on it. watch: worth asking the server about
 * (a pre-order waiting for the day is not).
 */
function shp_cus_order_view(array $o, array $stands)
{
    $s = $stands[$o['stand']] ?? null;
    $lines = array();
    foreach ($o['lines'] as $l) {
        $q = $l['qty'] - $l['cancelled'];
        if ($q > 0) $lines[] = array('label' => $l['label'], 'qty' => $q, 'amount' => $l['amount']);
    }
    $active = in_array($o['status'], array('placed', 'preparing', 'ready'), true);
    // Waiting for its service time; an order still to pay at the counter says so first.
    $later = $o['status'] === 'placed' && $o['wanted'] !== '' && empty($o['to_pay']) && shp_minutes_to($o['tournament'], $o['wanted']) > SHP_SCHEDULE_LEAD;
    return array(
        'id' => $o['id'], 'number' => $o['number'], 'stand_name' => (string) ($o['stand_name'] ?? ''),
        'stand_mode' => $s ? (string) $s->SdMode : 'prep',
        'status' => $o['status'], 'channel' => $o['channel'], 'pay_mode' => $o['pay_mode'], 'pay_state' => $o['pay_state'],
        'total' => $o['total'], 'paid' => $o['paid'], 'to_pay' => !empty($o['to_pay']),
        'position' => intval($o['position'] ?? 0), 'eta' => intval($o['eta'] ?? 0),
        'can_cancel' => $o['status'] === 'placed' && $o['paid'] <= 0.004,
        'seen' => (bool) $o['seen'], 'active' => $active,
        'watch' => $active && !($o['channel'] === 'preorder' && $o['status'] === 'placed'),
        'lines' => $lines, 'created' => $o['created'], 'wanted' => $o['wanted'], 'later' => $later,
    );
}

/**
 * Orders of a customer for the page: the live ones, those finished in the last minutes and, with
 * $history, the older ones too. ['orders' => [...newest first], 'watch' => how many to follow].
 */
function shp_cus_orders($tourId, array $customer, $history = false)
{
    $all = shp_customer_orders($tourId, $customer, false);
    $stands = shp_stands($tourId, false);
    $w = shp_window($tourId);
    $cut = $w ? date('Y-m-d H:i:s', strtotime($w['now'] . ' UTC') - SHP_CUS_RECENT_MIN * 60) : '';
    $out = array(); $watch = 0;
    foreach ($all as $o) {
        $live = in_array($o['status'], array('placed', 'preparing', 'ready'), true);
        if (!$live && !$history) {
            $when = $o['status'] === 'cancelled' ? $o['cancelled'] : $o['delivered'];
            if ($cut === '' || (string) $when < $cut) continue;
        }
        $v = shp_cus_order_view($o, $stands);
        if ($v['watch']) $watch++;
        $out[] = $v;
    }
    return array('orders' => $out, 'watch' => $watch);
}

/** Competition of the public key of a request, with its settings; ends with the answer if unusable. */
function shp_cus_api_tour($key)
{
    $tourId = shp_tour_by_key($key);
    if ($tourId <= 0 || !shp_enabled($tourId)) shp_json_error('shop_off', shp_t('ShErrShopOff'), 404);
    return $tourId;
}

/** The texts every customer script needs. */
function shp_cus_texts()
{
    return shp_ts(array(
        'ShStPlaced', 'ShStPreparing', 'ShStReady', 'ShStDelivered', 'ShStCancelled',
        'ShPayUnpaid', 'ShPayPartial', 'ShPayPaid', 'ShPayTab', 'ShPayRefunded',
        'ShModeNow', 'ShModePickup', 'ShModeTab', 'ShErrInternal', 'ShErrEmpty',
        'ShCusNavShop', 'ShCusNavOrders', 'ShCusClosedNow', 'ShCusSoldOut', 'ShCusLow', 'ShCusAdd', 'ShCusLess',
        'ShCusEmptyStand', 'ShCusBasket', 'ShCusItemOne', 'ShCusItemMany', 'ShCusOrderBtn', 'ShCusSending',
        'ShCusSheetTitle', 'ShCusTotal', 'ShCusWho', 'ShCusOrderingAs', 'ShCusPseudo', 'ShCusPseudoPh', 'ShCusLogin',
        'ShCusLoginNeeded', 'ShCusPay', 'ShCusPayNowHint', 'ShCusPayPickupHint', 'ShCusPayTabHint', 'ShCusNote', 'ShCusNotePh',
        'ShCusRemove', 'ShCusTakeLeft', 'ShCusPlaced', 'ShCusPreorderRecap', 'ShCusMaxPer',
        'ShCusOrdersTitle', 'ShCusNoOrders', 'ShCusBackShop', 'ShCusPast', 'ShCusCancel', 'ShCusCancelSure',
        'ShCusCancelYes', 'ShCusCancelNo', 'ShCusCancelPaid', 'ShCusCancelled', 'ShCusInstrToPay', 'ShCusInstrQueue',
        'ShCusInstrDirect', 'ShCusInstrPreorder', 'ShCusInstrPreparing', 'ShCusInstrReady', 'ShCusInstrDelivered',
        'ShCusInstrCancelled', 'ShCusSuffixPickup', 'ShCusSuffixTab', 'ShCusReadyTitle', 'ShCusReadyGo', 'ShCusReadyOk',
        'ShCusNotifBtn', 'ShCusNotifOn', 'ShCusNotifTitle', 'ShCusWait', 'ShCusChanged',
        'ShCusWhen', 'ShCusWhenAsap', 'ShCusWhenNoTime', 'ShCusWhenAt', 'ShCusWhenDay', 'ShCusWhenTime',
        'ShCusWantedAt', 'ShCusInstrScheduled', 'ShCusPushBtn', 'ShCusPushOn', 'ShCusPushIos', 'ShErrWanted',
    )) + shp_base_texts();
}

/**
 * What the customer pages need to offer a service time and the notifications: the days that
 * can be chosen (YYYY-MM-DD: the competition's, from today on), the local time of the
 * competition now, and the addresses of the push endpoint and of the service worker.
 */
function shp_cus_when_cfg($tourId, $mode)
{
    $w = shp_window($tourId);
    $days = array();
    if ($w && $w['from'] > '0000-00-00' && $w['to'] >= $w['from']) {
        // Every day of the competition, however long; the bound only stops a mistyped end year.
        for ($d = $w['from']; $d <= $w['to'] && count($days) < 400; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if ($mode === 'preorder' || $d >= $w['today']) $days[] = $d;
        }
    }
    return array('days' => $days, 'now' => $w ? (string) $w['now'] : '', 'lead' => SHP_SCHEDULE_LEAD);
}
