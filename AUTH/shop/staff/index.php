<?php
/**
 * staff/index.php — the till of the volunteers: one page, one script (assets/pos.js) that draws
 * Sell, Orders, Stock and Cash from the state of the stand and asks the server again every 4 s
 * (staff/api/sync.php). The page only carries the first state, the texts and the addresses;
 * everything the volunteer may do is checked again by each endpoint.
 */

require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/till.php';
require_once dirname(__DIR__) . '/lib/desk.php';

// staff/index.php?k=<public key> is the address of the till of a competition (QR code poster at
// the stand, home screen of the phone). A phone that is not, or no longer, signed in to that
// competition goes to its sign-in page instead of a dead end; without a key, the last till
// opened on this phone, then the competition opened in ianseo, tell which one it is.
$key = (string) ($_GET['k'] ?? '');
$keyTour = shp_tour_by_key($key);
$st = shp_staff_session_state();
$inTour = $st['reason'] === 'ok' && ($keyTour === 0 || intval($st['staff']->SfTournament) === $keyTour);
// A volunteer of the check-in desk alone (no right at the stands, or the stands switched off):
// the desk is their page. Every address of the volunteers (sign-in, joining) ends here.
if ($inTour) {
    $t = intval($st['staff']->SfTournament);
    if ((!shp_enabled($t) || !shp_staff_can($st['staff'], '')) && shp_desk_on($t) && shp_desk_can($st['staff'])) {
        header('Location: ' . shp_url('desk/index.php'));
        exit;
    }
}
if (!$inTour && in_array($st['reason'], array('ok', 'signed_out', 'expired'), true)) {
    $tour = $keyTour ?: ($st['row'] ? intval($st['row']->SfTournament) : 0);
    if ($tour <= 0) $tour = shp_tour_by_key(shp_cookie_get(SHP_TILL_COOKIE));
    if ($tour <= 0 && intval($_SESSION['TourId'] ?? 0) > 0 && shp_staff_on(intval($_SESSION['TourId']))) $tour = intval($_SESSION['TourId']);
    $set = $tour > 0 ? shp_settings($tour) : null;
    if ($set && shp_staff_on($tour)) {
        header('Location: ' . shp_url('staff/login.php?k=' . rawurlencode((string) $set->SgPublicKey)));
        exit;
    }
}
$me = shp_require_staff();
$TOUR = intval($me->SfTournament);
$state = shp_till_state($me, 0);
$tillKey = (string) shp_settings($TOUR)->SgPublicKey;
shp_cookie_set(SHP_TILL_COOKIE, $tillKey, time() + 30 * 86400);

$texts = array(
    'ShLoading', 'ShPayPaid', 'ShPayPartial', 'ShPayRefunded', 'ShPayTab', 'ShPosAccountBtn',
    'ShPosAccountLeft', 'ShPosAccountPaid', 'ShPosAccountPay', 'ShPosAccountWhole', 'ShPosAddStock',
    'ShPosAlertOff', 'ShPosAlertOn', 'ShPosAll', 'ShPosAskManager', 'ShPosAvailable', 'ShPosBack',
    'ShPosBackTo', 'ShPosCancelOrder', 'ShPosCashAccounts', 'ShPosCashCount', 'ShPosCashGiven',
    'ShPosCashMine', 'ShPosCashNet', 'ShPosCashNone', 'ShPosCashRefunds', 'ShPosCashStand',
    'ShPosChangeToGive', 'ShPosChanged', 'ShPosChooseVariant', 'ShPosClear', 'ShPosCloseIt',
    'ShPosClosed', 'ShPosClosedNote', 'ShPosCollect', 'ShPosCollectHand', 'ShPosCollectNow',
    'ShPosCollectOrder', 'ShPosCollected', 'ShPosConfirm', 'ShPosConfirmCash', 'ShPosCustomer',
    'ShPosCustomerPh', 'ShPosEdit', 'ShPosExact', 'ShPosFilterLater', 'ShPosFilterQueue',
    'ShPosFilterReady', 'ShPosFilterRecent', 'ShPosFilterToPay', 'ShPosFrom', 'ShPosGiveBack',
    'ShPosHandOver', 'ShPosItems', 'ShPosLeft', 'ShPosLess', 'ShPosLicensee', 'ShPosLoss',
    'ShPosLowStock', 'ShPosMaxPer', 'ShPosMinutes', 'ShPosMissing', 'ShPosMore', 'ShPosNewOrder',
    'ShPosNoAccount', 'ShPosNoOrders', 'ShPosNoPayWay', 'ShPosNoResult', 'ShPosNoRight',
    'ShPosNoStand', 'ShPosNote', 'ShPosNothingToSell', 'ShPosNow', 'ShPosOk', 'ShPosOnAccountOf',
    'ShPosOnline', 'ShPosOnlyLeft', 'ShPosOpen', 'ShPosOpenIt', 'ShPosOrderCancelled',
    'ShPosOrderTitle', 'ShPosOut', 'ShPosPaidAmount', 'ShPosPayFailed', 'ShPosPayPickup',
    'ShPosPayTab', 'ShPosPreorder', 'ShPosQty', 'ShPosQtyTitleLoss', 'ShPosQtyTitleRestock',
    'ShPosQuit', 'ShPosReady', 'ShPosReasonLeft', 'ShPosReasonMistake', 'ShPosReasonPh',
    'ShPosReasonSoldOut', 'ShPosReceived', 'ShPosRefund', 'ShPosRefundAmount', 'ShPosRefundCancel',
    'ShPosRefundLeft', 'ShPosRefundMax', 'ShPosRefundMethod', 'ShPosRefundNoLimit',
    'ShPosRefundReason', 'ShPosRefundSure', 'ShPosRefundTitle', 'ShPosRefundYes', 'ShPosRefunded',
    'ShPosRemove', 'ShPosRemoveLine', 'ShPosRemoveStock', 'ShPosRest', 'ShPosRestock',
    'ShPosSaleNumber', 'ShPosSearchAccount', 'ShPosSearchOrders', 'ShPosSearchPh', 'ShPosSending',
    'ShPosSoldOut', 'ShPosStand', 'ShPosStart', 'ShPosStockSaved', 'ShPosStockTitle',
    'ShPosTabCash', 'ShPosTabOrders', 'ShPosTabSell', 'ShPosTabStock', 'ShPosTicket',
    'ShPosToCollect', 'ShPosTotal', 'ShPosUndoSale', 'ShPosUndone', 'ShPosUnknown',
    'ShPosUnlimited', 'ShPosWaitLabel', 'ShPosWaitLess', 'ShPosWaitMore', 'ShPosWantedAsk', 'ShPosWantedAt',
    'ShPosWantedNone', 'ShStCancelled', 'ShStDelivered', 'ShStPlaced', 'ShStPreparing', 'ShStReady',
    'ShStfErrRevoked', 'ShStfLoginAgain', 'DkMenu');
$texts = shp_ts($texts) + shp_base_texts();

$title = shp_t('ShStfTillTitle');
shp_head($title, array('layout' => 'app', 'css' => array('pos.css'), 'manifest' => shp_url('staff/manifest.php?k=' . rawurlencode($tillKey))));
echo '  <div id="pos" class="pos"><p class="shp-muted pos-loading">' . shp_e(shp_t('ShLoading')) . "</p></div>\n"
    . '  <noscript><div class="shp-card">' . shp_msg('err', shp_t('ShPosNeedsScript')) . "</div></noscript>\n"
    . shp_json_script('shp-texts', $texts)
    . shp_json_script('shp-state', $state)
    . shp_json_script('shp-cfg', shp_page_cfg($TOUR, 'till-' . intval($me->SfId), array(
        'sync' => shp_url('staff/api/sync.php'), 'sell' => shp_url('staff/api/sell.php'),
        'order' => shp_url('staff/api/order.php'), 'pay' => shp_url('staff/api/pay.php'),
        'refund' => shp_url('staff/api/refund.php'), 'stock' => shp_url('staff/api/stock.php'),
        'stand' => shp_url('staff/api/stand.php'), 'search' => shp_url('staff/api/search.php'),
        'accounts' => shp_url('staff/api/accounts.php'), 'report' => shp_url('staff/api/report.php'),
        'logout' => shp_url('staff/api/logout.php'),
        'desk' => shp_desk_on($TOUR) && shp_desk_can($me) ? shp_url('desk/index.php') : '',
        'login' => shp_url('staff/login.php?k=' . rawurlencode($tillKey)), 'key' => $tillKey,
        'title' => $title)));
shp_foot(array('pos.js'));
