<?php
/**
 * staff/api/stock.php — stock of a product or variant (POST, JSON): product, variant, action = switch (+ on) | restock | loss (+ qty, idem). The right 'stock' is checked on the product's own stand.
 */

require_once dirname(__DIR__) . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib/till.php';

shp_api_guard(true);
$me = shp_require_staff();
shp_json(shp_till_stock($me, shp_json_in()));
