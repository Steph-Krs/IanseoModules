<?php
/**
 * desk/index.php — the check-in desk, on a volunteer's phone or the organiser's computer: one
 * page, one script (assets/desk.js) drawing the search, an archer's file and the judges' view
 * from the JSON endpoints of desk/api/. The page only carries the texts, the addresses and the
 * rights; every endpoint checks the right it needs again (lib/desk.php).
 *
 * desk/index.php?k=<public key> is the stable address of the desk of a competition (the key of
 * the points of sale): a phone that is not, or no longer, signed in goes to the volunteers'
 * sign-in page, which brings it back here. ?a=<account> opens an archer's file at once (links
 * of the organiser's list).
 */

require_once dirname(__DIR__) . '/staff/boot.php';
require_once dirname(__DIR__) . '/lib/desk.php';

$key = (string) ($_GET['k'] ?? '');
$keyTour = shp_tour_by_key($key);
$st = shp_staff_session_state();
$inTour = $st['reason'] === 'ok' && ($keyTour === 0 || intval($st['staff']->SfTournament) === $keyTour);
if (!$inTour && in_array($st['reason'], array('ok', 'signed_out', 'expired'), true)) {
    $tour = $keyTour ?: ($st['row'] ? intval($st['row']->SfTournament) : 0);
    $set = $tour > 0 ? shp_settings($tour) : null;
    if ($set && shp_desk_on($tour)) {
        header('Location: ' . shp_url('staff/login.php?k=' . rawurlencode((string) $set->SgPublicKey) . '&to=desk'));
        exit;
    }
}
$me = shp_require_desk();
$TOUR = intval($me->SfTournament);
$deskKey = (string) shp_settings($TOUR)->SgPublicKey;

$texts = shp_ts(array(
    'DkTitle', 'DkSearchPh', 'DkSearchHelp', 'DkScan', 'DkScanTitle', 'DkScanHelp', 'DkScanFail', 'DkNoResult', 'DkMoreResults',
    'DkTabSearch', 'DkTabTodo', 'DkBack', 'DkLicence', 'DkClub', 'DkBorn', 'DkEntries', 'DkSession', 'DkTarget', 'DkNoTarget',
    'DkWeapon', 'DkEvents', 'DkStatus', 'DkRegTitle', 'DkRegOk', 'DkRegKo', 'DkEquipTitle', 'DkEquipOk', 'DkEquipKo', 'DkUndo',
    'DkUndoSure', 'DkStateNone', 'DkStateOk', 'DkStateKo', 'DkReason', 'DkReasonPh', 'DkStatus9', 'DkPowerTitle', 'DkPowerPh',
    'DkPowerAdd', 'DkPowerNone', 'DkPowerUnit', 'DkNotesTitle', 'DkNotePh', 'DkNoteAdd', 'DkNotesNone', 'DkPostReg', 'DkPostEquip',
    'DkPayTitle', 'DkPayReg', 'DkPayShop', 'DkPayPaid', 'DkPayLeft', 'DkPayNothing', 'DkPayAmount', 'DkPayDone', 'DkPayConfirm',
    'DkHistory', 'DkKindOk', 'DkKindKo', 'DkKindUndo', 'DkSaved', 'DkTodoLeft', 'DkTodoNone', 'DkAllSessions', 'DkAllWeapons',
    'DkAllClasses', 'DkAllEvents', 'DkFilterSession',
    'DkFilterWeapon', 'DkFilterClass', 'DkFilterEvent', 'DkTill', 'DkOf', 'DkErrArcher',
    'ShPosQuit', 'ShPosConfirm', 'ShStfErrRevoked', 'ShStfLoginAgain',
)) + shp_base_texts();

$methods = array();
foreach (shp_desk_pay_methods() as $code => $label) $methods[] = array('code' => $code, 'label' => $label);
$rights = shp_desk_rights($me);
$title = shp_t('DkTitle');
shp_head($title, array('layout' => 'app', 'css' => array('desk.css')));
echo '  <div id="desk" class="dk"><p class="shp-muted dk-pad">' . shp_e(shp_t('ShLoading')) . "</p></div>\n"
    . '  <noscript><div class="shp-card">' . shp_msg('err', shp_t('ShPosNeedsScript')) . "</div></noscript>\n"
    . shp_json_script('shp-texts', $texts)
    . shp_json_script('shp-cfg', shp_page_cfg($TOUR, 'desk-' . intval($me->SfId), array(
        'search' => shp_url('desk/api/search.php'), 'file' => shp_url('desk/api/file.php'),
        'act' => shp_url('desk/api/act.php'), 'todo' => shp_url('desk/api/todo.php'),
        'logout' => shp_url('staff/api/logout.php'),
        'login' => shp_url('staff/login.php?k=' . rawurlencode($deskKey) . '&to=desk'),
        'till' => shp_enabled($TOUR) && shp_staff_can($me, '') ? shp_url('staff/index.php') : '',
        'tour' => shp_staff_tour_name($TOUR), 'me' => shp_staff_label(intval($me->SfId)),
        'rights' => $rights, 'methods' => $methods,
        'open' => shp_desk_account_ok((string) ($_GET['a'] ?? '')) ? (string) $_GET['a'] : '',
        'title' => $title)));
shp_foot(array('desk.js'));
