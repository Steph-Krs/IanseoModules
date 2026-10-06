<?php
/**
 * staff/api/sync.php — state of the till for one stand (GET ?stand=&since=, JSON). Asked every 4 s by the till: the stand's orders, catalogue and stock, the volunteer's rights. `since` is the hash of the state the phone already has: while nothing changed the answer is `{error: 0, same: true}`. A withdrawn access answers with its code (revoked, expired…) through shp_require_staff().
 */

require_once dirname(__DIR__) . '/boot.php';
require_once dirname(__DIR__, 2) . '/lib/till.php';

shp_api_guard(false);
$me = shp_require_staff();
$state = shp_till_state($me, intval($_GET['stand'] ?? 0));
if ((string) ($_GET['since'] ?? '') === $state['hash']) shp_json(array('error' => 0, 'same' => true, 'hash' => $state['hash'], 'now' => $state['now']));
shp_json(array('error' => 0, 'state' => $state));
