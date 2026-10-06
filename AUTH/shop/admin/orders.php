<?php
/**
 * admin/orders.php — the orders of the open competition, live (organiser).
 *
 * Meant to be left open on a screen or a phone during the competition: where does it get stuck?
 * One card per point of sale (open or closed, orders to collect, being prepared, ready and not
 * collected, the longest wait, volunteers seen lately), then the list of orders with filters
 * (stand, status, payment, number or name). The page script asks this same file for ONE JSON
 * answer every 10 seconds and draws everything from it.
 *
 * Reading needs the read-only right; acting (cancel an order, force a step) needs read-write and
 * uses the engine's own functions, as the organiser ($staffId = 0). Cancelling an order that was
 * paid refunds it first (shp_refund) with the means and reason given on the page.
 *
 * ianseo look and ACL of the core; JSON with the X-Shp header, CSRF token of the session in the
 * body of a write.
 */
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries', AclReadOnly);
$canWrite = hasFullACL(AclParticipants, 'pEntries', AclReadWrite);

require_once dirname(__DIR__) . '/lib/report.php';
require_once dirname(__DIR__) . '/lib/pay.php';
require_once dirname(__DIR__) . '/lib/admin-ui.php';

shp_schema();

$TOUR = intval($_SESSION['TourId']);
$SELF = shp_url('admin/orders.php');
$settings = shp_settings($TOUR);
$enabled = $settings && intval($settings->SgEnabled) === 1;

$STATUS_FILTERS = array('open', 'placed', 'preparing', 'ready', 'delivered', 'cancelled', 'all');
$PAY_FILTERS = array('all', 'unpaid', 'paid', 'tab', 'refunded');

/** What the page script draws: stands, then the filtered list. */
function so_state($tour, $standId, $status, $pay, $q)
{
    $stands = array();
    $live = shp_rep_live($tour);
    foreach (shp_stands($tour, false) as $sd => $s) {
        $stands[] = array('id' => $sd, 'name' => shp_rep_stand_name($s), 'kind' => shp_stand_kinds()[$s->SdKind] ?? (string) $s->SdKind,
            'open' => intval($s->SdOpen) === 1, 'online' => intval($s->SdOnline) === 1, 'active' => intval($s->SdActive) === 1,
            'mode' => (string) $s->SdMode) + $live[$sd];
    }
    $list = shp_rep_orders($tour, $standId, $status, $pay, $q);
    return array('stands' => $stands, 'orders' => $list['rows'], 'total' => $list['total'], 'shown' => count($list['rows']));
}

/** Filters read from the request, checked. */
function so_filters()
{
    global $STATUS_FILTERS, $PAY_FILTERS, $TOUR;
    $status = (string) ($_GET['status'] ?? 'open');
    $pay = (string) ($_GET['pay'] ?? 'all');
    $stand = intval($_GET['stand'] ?? 0);
    if ($stand > 0 && !isset(shp_stands($TOUR, false)[$stand])) $stand = 0;
    return array($stand, in_array($status, $STATUS_FILTERS, true) ? $status : 'open', in_array($pay, $PAY_FILTERS, true) ? $pay : 'all',
        mb_substr((string) ($_GET['q'] ?? ''), 0, 40));
}

/* ================================================================== */
/* JSON for the page script                                            */
/* ================================================================== */
if ((string) ($_SERVER['HTTP_X_SHP'] ?? '') === '1') {
    // bytes: HTTP method names are ASCII
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        session_write_close();
        if (!$enabled) shp_json_error('shop_off', shp_t('ShErrShopOff'));
        shp_json(array('state' => so_state($TOUR, ...so_filters())));
    }
    if ($method !== 'POST' || stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        shp_json_error('bad_request', shp_t('ShErrBadRequest'), 400);
    }
    $in = shp_json_in();
    if (!hash_equals(bk_csrf_token(), (string) ($in['csrf'] ?? ''))) shp_json_error('session', shp_t('ShRepErrSession'), 403);
    session_write_close();
    if (!$canWrite) shp_json_error('forbidden', shp_t('ShRepErrReadOnly'), 403);
    if (shp_impersonating()) shp_json_error('read_only', shp_t('ShErrReadOnly'), 403);
    if (!$enabled) shp_json_error('shop_off', shp_t('ShErrShopOff'));

    $id = intval($in['id'] ?? 0);
    $o = $id > 0 ? shp_order($id, $TOUR) : null;   // an order of another competition does not exist here
    if (!$o) shp_json_error('order', shp_t('ShErrOrder'));
    switch ((string) ($in['act'] ?? '')) {
        case 'status':
            $to = (string) ($in['to'] ?? '');
            $r = in_array($to, shp_order_statuses(), true) ? shp_order_transition($id, $to, 0) : shp_err('bad_request', 'ShErrBadRequest');
            break;
        case 'cancel':
            $r = array('error' => 0);
            // Money held on the order is given back first, with the means and the reason typed.
            if (shp_order_paid($id, $TOUR) > 0.004) {
                $refund = is_array($in['refund'] ?? null) ? $in['refund'] : array();
                $r = shp_refund($id, null, (string) ($refund['method'] ?? ''), 0, (string) ($refund['idem'] ?? ''), (string) ($refund['reason'] ?? ''));
            }
            if (empty($r['error'])) $r = shp_order_cancel($id, 0, false);
            break;
        default:
            shp_json_error('bad_request', shp_t('ShErrBadRequest'), 400);
    }
    $out = array('error' => empty($r['error']) ? 0 : 1, 'code' => (string) ($r['code'] ?? ''), 'msg' => (string) ($r['msg'] ?? ''),
        'state' => so_state($TOUR, ...so_filters()));
    shp_json($out);
}

/* ================================================================== */
/* Page                                                                */
/* ================================================================== */
$PAGE_TITLE = shp_t('MnuOrders') . ' — ' . shp_t('MnuTitle');
$JS_SCRIPT = array(
    '<meta name="viewport" content="width=device-width, initial-scale=1">',
    '<link rel="stylesheet" href="' . shp_e(shp_asset_url('admin.css')) . '">',
    '<link rel="stylesheet" href="' . shp_e(shp_asset_url('reports.css')) . '">',
);
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');

echo '<div id="shpadm" class="sr-live"><h1>' . shp_e(shp_t('ShRepOrdersTitle')) . '</h1>' . shp_adm_nav('orders.php');

if (!$enabled) {
    echo shp_adm_msg('warn', array(shp_t('ShRepShopOff'))) . '<p><a class="sa-btn sa-primary" href="' . shp_e(shp_adm_url('index.php')) . '">'
        . shp_e(shp_t('MnuSettings')) . '</a></p></div>';
    include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
    exit;
}

echo '<p class="sa-lead">' . shp_e(shp_t('ShRepOrdersLead')) . '</p>'
    . ($canWrite ? '' : shp_adm_msg('info', array(shp_t('ShRepReadOnlyNote'))))
    . '<div id="so-flash" aria-live="polite"></div>'
    . '<div id="so-stands" class="sr-stands"></div>'
    . '<div class="sa-card"><div class="sr-filters" id="so-filters"></div>'
    . '<div id="so-list" class="sr-list"><p class="sa-muted">' . shp_e(shp_t('ShLoading')) . '</p></div>'
    . '<p class="sa-hint" id="so-foot"></p></div>';

$methods = array();
foreach (shp_pay_methods() as $code => $label) if ($code !== 'tab') $methods[] = array('code' => $code, 'label' => $label);

[$f_stand, $f_status, $f_pay, $f_q] = so_filters();
echo shp_json_script('shp-texts', shp_ts(array(
        'ShRepStandOpen', 'ShRepStandClosed', 'ShRepToPay', 'ShRepPrep', 'ShRepReady', 'ShRepPre', 'ShRepWait', 'ShRepReadyWait',
        'ShRepStaffSeen', 'ShRepMinutes', 'ShRepFilterStand', 'ShRepFilterStatus', 'ShRepFilterPay', 'ShRepFilterSearch',
        'ShRepAllStands', 'ShRepStatusOpen', 'ShRepStatusAll', 'ShRepPayAll', 'ShRepPayUnpaid', 'ShRepColOrder', 'ShRepColStand',
        'ShRepColCustomer', 'ShRepColStatus', 'ShRepColPay', 'ShRepColTotal', 'ShRepColSince', 'ShRepNoOrders',
        'ShRepShown', 'ShRepMoveTo', 'ShRepCancelOrder', 'ShRepCancelConfirm', 'ShRepCancelPaidTitle', 'ShRepRefundMethod',
        'ShRepRefundReason', 'ShRepRefundGo', 'ShRepBack', 'ShRepDone', 'ShRepPreorder', 'ShRepNote', 'ShRepUpdated',
        'ShStPlaced', 'ShStPreparing', 'ShStReady', 'ShStDelivered', 'ShStCancelled', 'ShPayUnpaid', 'ShPayPartial', 'ShPayPaid',
        'ShPayTab', 'ShPayRefunded',     )) + shp_base_texts())
    . shp_json_script('shp-cfg', shp_page_cfg($TOUR, 'orders-admin', array(
        'self' => $SELF, 'csrf' => bk_csrf_token(), 'can_write' => $canWrite, 'methods' => $methods,
        'filters' => array('stand' => $f_stand, 'status' => $f_status, 'pay' => $f_pay, 'q' => $f_q),
        'state' => so_state($TOUR, $f_stand, $f_status, $f_pay, $f_q), 'poll' => 10000,
    )));
echo '<script src="' . shp_e(shp_asset_url('shp.js')) . '"></script>'
    . '<script src="' . shp_e(shp_asset_url('orders-admin.js')) . '"></script>';
echo '</div>';

include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php');
