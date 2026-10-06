<?php
/**
 * staff/api/accounts.php — accounts owing something at the stands. GET ?stand=&q= lists them; POST (JSON: account, method, amount?, idem, stand) settles one. Needs the right 'cash' on the stand.
 */

require_once dirname(__DIR__) . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib/till.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {   // bytes: a method name
    shp_api_guard(true);
    $in = shp_json_in();
    $me = shp_require_staff('cash', intval($in['stand'] ?? 0));
    shp_json(shp_till_account_pay($me, $in));
}
shp_api_guard(false);
$me = shp_require_staff('cash', intval($_GET['stand'] ?? 0));
shp_json(array('error' => 0, 'rows' => shp_till_accounts($me, (string) ($_GET['q'] ?? ''))));
