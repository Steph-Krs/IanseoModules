<?php
/**
 * public/index.php — the shop of a competition, on the customer's phone.
 *
 *   ?k=<public key>[&s=<stand id>]   (the address of the QR codes of admin/posters.php)
 *
 * The key finds the competition; an unknown key and a shop that is switched off give the SAME
 * page, so that nothing tells whether a key exists. No ianseo ACL here ($SKIP_AUTH): the page
 * only shows what the customer may see, and every action goes through public/api/*, which
 * checks everything again. The page is a shell: catalogue, basket and tracking are built by
 * assets/shop.js from the JSON blocks below (texts come from the server, never from the script).
 */

require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/public.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: same-origin');

$key = (string) ($_GET['k'] ?? '');
$tourId = shp_tour_by_key($key);
if ($tourId <= 0 || !shp_enabled($tourId)) {
    shp_page_message(shp_t('ShUnavailableTitle'), shp_t('ShUnavailable'), 'info', 404);
}
$set = shp_settings($tourId);
$mode = shp_cus_mode($tourId);
$customer = shp_customer($tourId);
$tab = shp_cus_tab_state($tourId, $set, $customer, $mode['mode']);
$catalog = $mode['mode'] === 'closed'
    ? shp_cus_catalog($tourId, 'onsite', $tab)
    : shp_cus_catalog($tourId, $mode['mode'], $tab);
$orders = $customer ? shp_cus_orders($tourId, $customer, true) : array('orders' => array(), 'watch' => 0);
$tourName = shp_cus_tour_name($tourId);

// A message above the shop when nothing can be ordered from the phone (browsing stays possible).
$banner = '';
if ($mode['mode'] === 'closed') {
    $banner = $mode['why'] === 'over' ? shp_t('ShCusOver')
        : ($mode['why'] === 'before' ? shp_t('ShCusOpensOn', bk_date_fr($mode['date'])) : shp_t('ShErrNotNow'));
} elseif ($mode['mode'] === 'preorder') {
    $banner = shp_t('ShCusPreorderBanner', bk_date_time((string) $set->SgPreorderUntil));
}

$loginUrl = shp_url('public/login.php?k=' . rawurlencode($key));
$cfg = shp_page_cfg($tourId, 'shop-' . $key, array(
    'key' => $key, 'mode' => $mode['mode'], 'stand' => intval($_GET['s'] ?? 0), 'when' => shp_cus_when_cfg($tourId, $mode['mode']),
    'sw' => shp_url('public/sw.js'),
    'guests' => intval($set->SgGuests) === 1, 'tab' => $tab,
    'customer' => $customer ? array('kind' => $customer['kind'], 'label' => $customer['label']) : null,
    'urls' => array(
        'cart' => shp_url('public/api/cart-check.php'), 'order' => shp_url('public/api/order.php'),
        'cancel' => shp_url('public/api/cancel.php'), 'status' => shp_url('public/api/status.php'),
        'seen' => shp_url('public/api/seen.php'), 'login' => $loginUrl, 'push' => shp_url('public/api/push.php'),
    ),
));
$data = array('stands' => $catalog['stands'], 'products' => $catalog['products'],
    'orders' => $orders['orders'], 'watch' => $orders['watch']);

shp_head($tourName, array('layout' => 'app', 'css' => array('shop.css'), 'manifest' => shp_url('public/manifest.php?k=' . rawurlencode($key))));
echo '  <header class="shp-top"><span class="shp-top-title">' . shp_e($tourName) . '</span></header>' . "\n"
    . '  <nav class="cus-nav" id="cus-nav" aria-label="' . shp_e(shp_t('ShBrand')) . '">'
    . '<button type="button" class="cus-nav-btn on" data-view="shop">' . shp_e(shp_t('ShCusNavShop')) . '</button>'
    . '<button type="button" class="cus-nav-btn" data-view="orders">' . shp_e(shp_t('ShCusNavOrders'))
    . ' <span class="cus-count shp-hidden" id="cus-count"></span></button></nav>' . "\n"
    . '  <main class="shp-main" id="cus">' . "\n";
if (trim((string) $set->SgNotice) !== '') echo '    ' . shp_msg('info', (string) $set->SgNotice) . "\n";
if ($banner !== '') echo '    ' . shp_msg('warn', $banner) . "\n";
echo '    <section id="cus-shop" aria-live="polite"><p class="shp-muted">' . shp_e(shp_t('ShLoading')) . '</p></section>' . "\n"
    . '    <section id="cus-orders" class="shp-hidden" aria-live="polite"></section>' . "\n"
    . '  </main>' . "\n"
    . '  <div id="cus-bar" class="cus-bar shp-hidden"></div>' . "\n"
    . '  <div id="cus-layer"></div>' . "\n"
    . '  ' . shp_json_script('shp-texts', shp_cus_texts())
    . '  ' . shp_json_script('shp-cfg', $cfg)
    . '  ' . shp_json_script('shp-data', $data);
shp_foot(array('shop.js'));
