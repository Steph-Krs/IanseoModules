<?php
/**
 * desk/api/todo.php — the volunteers' list of a post (GET ?post=reg|equip&state=todo|done
 * &session=&division=&class=&event=): who is left to check, or was checked, with the filters
 * that still have somebody. Right of the post: registry (reg or pay), judges (equip).
 */

require_once dirname(__DIR__, 2) . '/staff/boot.php';
require_once dirname(__DIR__, 2) . '/lib/desk.php';

shp_api_guard(false);
$post = (string) ($_GET['post'] ?? 'equip') === 'reg' ? 'reg' : 'equip';
$me = shp_require_desk($post === 'equip' ? 'equip' : '');
if ($post === 'reg' && !shp_desk_can($me, 'reg') && !shp_desk_can($me, 'pay')) shp_json_error('forbidden', shp_t('DkErrForbidden'), 403);
shp_json(array('error' => 0) + shp_desk_list(intval($me->SfTournament), $post, array(
    'state' => (string) ($_GET['state'] ?? 'todo'),
    'session' => (string) ($_GET['session'] ?? ''), 'division' => (string) ($_GET['division'] ?? ''),
    'class' => (string) ($_GET['class'] ?? ''), 'event' => (string) ($_GET['event'] ?? ''))));
