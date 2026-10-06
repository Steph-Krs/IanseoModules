<?php
/**
 * public/api/seen.php — the customer has seen "your order is ready" (POST JSON), so that a page
 * reloaded later does not announce it again. In: {k, order: id}. Out: {error: 0}.
 */

require_once dirname(__DIR__) . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib/public.php';

shp_api_guard(true);
$in = shp_json_in();
$tourId = shp_cus_api_tour($in['k'] ?? '');
$customer = shp_customer($tourId);
$o = $customer ? shp_order(intval($in['order'] ?? 0), $tourId) : null;
if (!$o || !shp_cus_owns($o, $customer)) shp_json_error('order', shp_t('ShErrOrder'), 404);
safe_w_sql("UPDATE ShopOrders SET ShSeen = 1 WHERE ShId = " . intval($o['id']) . " AND ShTournament = $tourId");
shp_json(array('error' => 0));
