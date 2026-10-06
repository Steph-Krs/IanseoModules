<?php
/**
 * public/api/status.php — every order of this phone's customer in ONE request (GET, JSON).
 *
 * Asked by the shop and tracking pages every 10 s (30 s when hidden) while something is being
 * followed; the page stops asking when nothing is. Out: {error: 0, orders: [...], watch: n,
 * who: label}. Orders handed over or cancelled stay a few minutes so that the page sees the
 * change; `all=1` adds the older ones (first load of the tracking page). Identity: the cookie
 * of the visitor or the licensee session — never an id in the address.
 * Reads indexed rows only, and releases the session lock at once.
 */

require_once dirname(__DIR__) . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib/public.php';

shp_api_guard(false);
header('Cache-Control: no-store, private');
$tourId = shp_cus_api_tour($_GET['k'] ?? '');
$customer = shp_customer($tourId);
if (!$customer) shp_json(array('orders' => array(), 'watch' => 0, 'who' => ''));
$r = shp_cus_orders($tourId, $customer, !empty($_GET['all']));
shp_json(array('orders' => $r['orders'], 'watch' => $r['watch'], 'who' => (string) $customer['label']));
