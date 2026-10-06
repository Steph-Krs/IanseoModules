<?php
/**
 * staff/api/pay.php — collects an order (POST, JSON): order, method (or 'tab'), amount?, idem, then_deliver?. The right 'cash' is checked on the order's own stand.
 */

require_once dirname(__DIR__) . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib/till.php';

shp_api_guard(true);
$me = shp_require_staff();
shp_json(shp_till_pay($me, shp_json_in()));
