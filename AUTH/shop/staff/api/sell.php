<?php
/**
 * staff/api/sell.php — a counter sale, collected in the same request (POST, JSON): stand, lines, customer, pay, note, idem. The right 'sell' on the stand is required; 'cash' too when the sale is collected now.
 */

require_once dirname(__DIR__) . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib/till.php';

shp_api_guard(true);
$in = shp_json_in();
$me = shp_require_staff('sell', intval($in['stand'] ?? 0));
shp_json(shp_till_sell($me, $in));
