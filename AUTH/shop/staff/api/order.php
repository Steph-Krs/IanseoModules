<?php
/**
 * staff/api/order.php — an action on an order (POST, JSON): action = status (+ to) | cancel | cancel_line (+ line, qty) | undo. The right is checked on the order's own stand.
 */

require_once dirname(__DIR__) . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib/till.php';

shp_api_guard(true);
$me = shp_require_staff();
shp_json(shp_till_order_action($me, shp_json_in()));
