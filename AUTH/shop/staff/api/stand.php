<?php
/**
 * staff/api/stand.php — opens or closes a stand (POST, JSON): stand, open. Needs the right 'manage' on it.
 */

require_once dirname(__DIR__) . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib/till.php';

shp_api_guard(true);
$in = shp_json_in();
$me = shp_require_staff('manage', intval($in['stand'] ?? 0));
shp_json(shp_till_stand_open($me, intval($in['stand'] ?? 0), !empty($in['open'])));
