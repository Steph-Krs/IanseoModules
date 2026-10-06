<?php
/**
 * staff/api/search.php — licensees taking part in the competition, by name or licence (GET ?stand=&q=, JSON), to put a sale on their account. Needs the right 'sell' on the stand.
 */

require_once dirname(__DIR__) . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib/till.php';

shp_api_guard(false);
$me = shp_require_staff('sell', intval($_GET['stand'] ?? 0));
shp_json(array('error' => 0, 'rows' => shp_till_search(intval($me->SfTournament), (string) ($_GET['q'] ?? ''))));
