<?php
/**
 * public/api/cancel.php — a customer cancels their own order (POST JSON).
 *
 * In:  {k, order: id}   Out: {error: 0, status: 'cancelled'} or {error: 1, code, msg, status?}
 * Only while the order is still "received" and nothing is paid on it; the stock goes back to
 * the shelf. The order must belong to the customer of this phone: any other id gets "not found".
 */

require_once dirname(__DIR__) . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib/public.php';

shp_api_guard(true);
$in = shp_json_in();
$tourId = shp_cus_api_tour($in['k'] ?? '');
$customer = shp_customer($tourId);
$o = $customer ? shp_order(intval($in['order'] ?? 0), $tourId) : null;
if (!$o || !shp_cus_owns($o, $customer)) shp_json_error('order', shp_t('ShErrOrder'), 404);

$res = shp_order_cancel(intval($o['id']), 0, true);
if ($res['error'] && ($res['code'] ?? '') === 'refund_first') $res['msg'] = shp_t('ShCusCancelPaid');
shp_json($res);
