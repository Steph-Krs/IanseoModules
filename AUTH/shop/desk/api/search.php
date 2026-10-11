<?php
/**
 * desk/api/search.php — archers of the competition matching a search (GET ?q=): names, licence,
 * club, in any order, or a scanned back-number QR code. Any right at the desk.
 */

require_once dirname(__DIR__, 2) . '/staff/boot.php';
require_once dirname(__DIR__, 2) . '/lib/desk.php';

shp_api_guard(false);
$me = shp_require_desk();
shp_json(array('error' => 0) + shp_desk_search(intval($me->SfTournament), (string) ($_GET['q'] ?? '')));
