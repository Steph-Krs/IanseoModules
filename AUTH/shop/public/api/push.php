<?php
/**
 * public/api/push.php — notifications on the customer's phone (Web Push, lib/push.php).
 *
 *   GET  ?k=<public key>                         → {key}: the server's public key ('' if none)
 *   POST {k, action: 'subscribe', sub}           the subscription of this browser, for the
 *                                                customer of this phone (cookie or licensee)
 *   POST {k, action: 'unsubscribe', endpoint}
 *
 * A subscription only ever belongs to an identified customer: a phone without one is refused.
 */

require_once dirname(__DIR__) . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib/public.php';
require_once dirname(__DIR__, 2) . '/lib/push.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {   // bytes: a method name
    shp_api_guard(false);
    shp_cus_api_tour($_GET['k'] ?? '');
    $keys = shp_push_keys();
    shp_json(array('key' => $keys ? $keys['public'] : ''));
}
shp_api_guard(true);
$in = shp_json_in();
$tourId = shp_cus_api_tour($in['k'] ?? '');
$customer = shp_customer($tourId);
if (!$customer) shp_json_error('customer', shp_t('ShErrCustomer'));
$action = (string) ($in['action'] ?? '');
if ($action === 'subscribe') {
    shp_json(shp_push_subscribe($tourId, $customer, is_array($in['sub'] ?? null) ? $in['sub'] : array(), mb_substr(aut_lang_code(), 0, 2)));
}
if ($action === 'unsubscribe') shp_json(shp_push_unsubscribe($tourId, $customer, (string) ($in['endpoint'] ?? '')));
shp_json_error('bad_request', shp_t('ShErrBadRequest'), 400);
