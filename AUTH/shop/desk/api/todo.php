<?php
/**
 * desk/api/todo.php — the judges' view (GET ?session=&division=&class=&event=): archers whose
 * equipment is not checked yet, with the filters that still have somebody left. Right equip.
 */

require_once dirname(__DIR__, 2) . '/staff/boot.php';
require_once dirname(__DIR__, 2) . '/lib/desk.php';

shp_api_guard(false);
$me = shp_require_desk('equip');
shp_json(array('error' => 0) + shp_desk_todo(intval($me->SfTournament), array(
    'session' => (string) ($_GET['session'] ?? ''), 'division' => (string) ($_GET['division'] ?? ''),
    'class' => (string) ($_GET['class'] ?? ''), 'event' => (string) ($_GET['event'] ?? ''))));
