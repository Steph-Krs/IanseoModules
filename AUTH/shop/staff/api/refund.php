<?php
/**
 * staff/api/refund.php — refunds an order (POST, JSON): order, amount?, method, reason, idem, then ('' | 'order' | 'line' + line, qty). The right 'refund' and the volunteer's ceilings are checked on the server, inside the refund.
 */

require_once dirname(__DIR__) . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib/till.php';

shp_api_guard(true);
$me = shp_require_staff();
shp_json(shp_till_refund($me, shp_json_in()));
