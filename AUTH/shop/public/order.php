<?php
/**
 * public/order.php — tracking of the customer's orders: the number in very large digits, the
 * steps, the waiting time, what to do now.
 *
 *   ?k=<public key>
 *
 * Access is by IDENTITY only (cookie of the visitor, or licensee session): the address carries
 * no order number, and no id of the address can show or cancel someone else's order. A phone
 * that has no identity here simply sees "no order". The page then follows the live orders by
 * itself (public/api/status.php) and announces "ready".
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
$customer = shp_customer($tourId);
$orders = $customer ? shp_cus_orders($tourId, $customer, true) : array('orders' => array(), 'watch' => 0);
$tourName = shp_cus_tour_name($tourId);
$shopUrl = shp_url('public/index.php?k=' . rawurlencode($key));

$cfg = shp_page_cfg($tourId, 'shop-' . $key, array(
    'key' => $key, 'page' => 'orders', 'shop_url' => $shopUrl, 'when' => shp_cus_when_cfg($tourId, 'onsite'),
    'sw' => shp_url('public/sw.js'),
    'urls' => array(
        'cancel' => shp_url('public/api/cancel.php'), 'status' => shp_url('public/api/status.php'),
        'seen' => shp_url('public/api/seen.php'), 'push' => shp_url('public/api/push.php'),
    ),
));
$data = array('orders' => $orders['orders'], 'watch' => $orders['watch']);

shp_head(shp_t('ShCusOrdersTitle'), array('layout' => 'app', 'css' => array('shop.css'), 'manifest' => shp_url('public/manifest.php?k=' . rawurlencode($key))));
echo '  <header class="shp-top"><span class="shp-top-title">' . shp_e($tourName) . '</span></header>' . "\n"
    . '  <main class="shp-main" id="cus">' . "\n"
    . '    <h1>' . shp_e(shp_t('ShCusOrdersTitle')) . '</h1>' . "\n"
    . '    <section id="cus-orders" aria-live="polite"><p class="shp-muted">' . shp_e(shp_t('ShLoading')) . '</p></section>' . "\n"
    . '    <p><a class="shp-btn shp-btn-block" href="' . shp_e($shopUrl) . '">' . shp_e(shp_t('ShCusBackShop')) . '</a></p>' . "\n"
    . '  </main>' . "\n"
    . '  <div id="cus-layer"></div>' . "\n"
    . '  ' . shp_json_script('shp-texts', shp_cus_texts())
    . '  ' . shp_json_script('shp-cfg', $cfg)
    . '  ' . shp_json_script('shp-data', $data);
shp_foot(array('shop.js'));
