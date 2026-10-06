<?php
/**
 * staff/api/report.php — cash figures of the day (GET ?stand=, JSON): what this volunteer took by means of payment, their refunds and ceiling, and the whole stand for a manager.
 */

require_once dirname(__DIR__) . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib/till.php';

shp_api_guard(false);
$me = shp_require_staff('', intval($_GET['stand'] ?? 0));
shp_json(array('error' => 0, 'report' => shp_till_report($me, intval($_GET['stand'] ?? 0))));
