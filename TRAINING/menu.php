<?php
/**
 * Menu entries of the training module, and the simplified menu of a training.
 *
 * get_which_menu() includes this file on EVERY ianseo page, which is what makes
 * it dangerous: an error here takes the whole installation down. So it writes
 * nothing, reads the module's table only through the non-fatal $force argument
 * of safe_r_sql(), and calls no optional function without function_exists().
 *
 * Three things are done here:
 *   - "Create a training" in the Competition menu, under the core's "New" when
 *     no competition is open, under "Close" otherwise;
 *   - the module's entries in the Modules menu;
 *   - on a training created with the simplified menu, unless this browser asked
 *     for the full menu (cookie TRN_FULL, set by menu-mode.php), the whole menu
 *     is replaced by the few screens a coach needs. This file is the last
 *     custom menu included (directory order), so nothing is added after it.
 */

require_once __DIR__ . '/lib/lang.php';

$_trn_url = $CFG->ROOT_DIR . 'Modules/Custom/' . basename(__DIR__) . '/';
$_trn_can = !isset($acl) || !function_exists('subFeatureAcl') || subFeatureAcl($acl, AclRoot, '') >= AclReadWrite
    || (function_exists('possibleFeature') && possibleFeature(AclRoot, AclReadWrite));
$_trn_create = trn_esc(trn_text('MenuCreate')) . '|' . $_trn_url . 'create.php';

// "Create a training" in the Competition menu, keys of the menu kept.
if ($_trn_can && isset($ret['COMP']) && is_array($ret['COMP'])) {
    $_trn_after = !empty($on) ? 'Common/TourOff.php' : 'Tournament/index.php?New=';
    $_trn_new = [];
    $_trn_done = false;
    foreach ($ret['COMP'] as $_trn_k => $_trn_v) {
        if (is_int($_trn_k)) $_trn_new[] = $_trn_v; else $_trn_new[$_trn_k] = $_trn_v;
        if (!$_trn_done && is_string($_trn_v) && strpos($_trn_v, $_trn_after) !== false) {
            $_trn_new[] = $_trn_create;
            $_trn_done = true;
        }
    }
    if (!$_trn_done) $_trn_new[] = $_trn_create;
    $ret['COMP'] = $_trn_new;
}

// Is the open competition a training, and with which menu?
$_trn_run = null;
if (!empty($on) && intval($_SESSION['TourId'] ?? 0) > 0) {
    $_trn_rs = safe_r_sql("SELECT RnSimple FROM TrainingRuns WHERE RnTournament = " . intval($_SESSION['TourId']), false, true);
    if ($_trn_rs && ($_trn_row = safe_fetch($_trn_rs))) $_trn_run = $_trn_row;
}
$_trn_full = !empty($_COOKIE['TRN_FULL']);

if ($_trn_run && intval($_trn_run->RnSimple) && !$_trn_full) {
    // Duels exist? Read from the core's Events, always present.
    $_trn_duels = [0 => false, 1 => false];
    $_trn_rs = safe_r_sql("SELECT DISTINCT EvTeamEvent FROM Events
        WHERE EvTournament = " . intval($_SESSION['TourId']) . " AND EvFinalFirstPhase > 0", false, true);
    while ($_trn_rs && ($_trn_row = safe_fetch($_trn_rs))) $_trn_duels[intval($_trn_row->EvTeamEvent)] = true;

    $_trn_menu = [trn_esc(trn_text('ModuleName'))];
    $_trn_menu[] = trn_esc(trn_text('MenuDashboard')) . '|' . $_trn_url . 'dashboard.php';
    $_trn_menu[] = trn_esc(trn_text('MenuLive')) . '|' . $_trn_url . 'live.php';
    $_trn_menu[] = trn_esc(trn_text('MenuParticipants')) . '|' . $_trn_url . 'participants.php';
    $_trn_menu[] = MENU_DIVIDER;
    $_trn_menu[] = get_text('MenuLM_Scorecard Printout') . '|' . $CFG->ROOT_DIR . 'Qualification/PrintScore.php';
    $_trn_menu[] = trn_esc(trn_text('ManualQualEntry')) . '|' . $CFG->ROOT_DIR . 'Qualification/index.php';
    $_trn_menu[] = get_text('ManageLockedSessions', 'Api') . '|' . $CFG->ROOT_DIR . 'Api/ISK-NG/Sessions.php';
    if ($_trn_duels[0] || $_trn_duels[1]) {
        $_trn_menu[] = MENU_DIVIDER;
        $_trn_menu[] = trn_esc(trn_text('MenuRanking')) . '|' . $_trn_url . 'ranking.php';
        $_trn_menu[] = trn_esc(trn_text('MenuShootoffs')) . '|' . $_trn_url . 'shootoffs.php';
    }
    if ($_trn_duels[0]) {
        $_trn_menu[] = get_text('MenuLM_Individual Finals') . ' - ' . get_text('MenuLM_Scorecard Printout') . '|' . $CFG->ROOT_DIR . 'Final/Individual/PrintScore.php';
        $_trn_menu[] = get_text('MenuLM_Individual Finals') . ' - ' . get_text('MenuLM_Data insert (Bracket view)') . '|' . $CFG->ROOT_DIR . 'Final/Individual/InsertPoint_Bra.php';
    }
    if ($_trn_duels[1]) {
        $_trn_menu[] = get_text('MenuLM_Team Finals') . ' - ' . get_text('MenuLM_Scorecard Printout') . '|' . $CFG->ROOT_DIR . 'Final/Team/PrintScore.php';
        $_trn_menu[] = get_text('MenuLM_Team Finals') . ' - ' . get_text('MenuLM_Data insert (Bracket view)') . '|' . $CFG->ROOT_DIR . 'Final/Team/InsertPoint_Bra.php';
    }
    $_trn_menu[] = MENU_DIVIDER;
    $_trn_menu[] = trn_esc(trn_text('MenuResults')) . '|' . $_trn_url . 'dashboard.php#trn-results';
    $_trn_menu[] = trn_esc(trn_text('MenuFollowup')) . '|' . $_trn_url . 'followup.php';
    $_trn_menu[] = MENU_DIVIDER;
    $_trn_menu[] = trn_esc(trn_text('MenuFull')) . '|' . $_trn_url . 'menu-mode.php?full=1';

    $ret = [
        'COMP' => [
            get_text('MenuLM_Competition'),
            get_text('MenuLM_Open') . '|' . $CFG->ROOT_DIR . 'index.php',
            get_text('MenuLM_Close') . '|' . $CFG->ROOT_DIR . 'Common/TourOff.php',
        ],
        'TRAINING' => $_trn_menu,
    ];
    if ($_trn_can) $ret['COMP'][] = $_trn_create;
    $ret['COMP'][] = MENU_DIVIDER;
    $ret['COMP'][] = get_text('MenuLM_Select Language') . '|' . $CFG->ROOT_DIR . 'Language/index.php';
} else {
    if (!isset($ret['MODS'])) $ret['MODS'] = [get_text('MenuLM_Modules')];
    if (!isset($ret['MODS']['TRAINING'])) $ret['MODS']['TRAINING'][] = trn_esc(trn_text('ModuleName'));
    if ($_trn_can) $ret['MODS']['TRAINING'][] = trn_esc(trn_text('MenuHome')) . '|' . $_trn_url . 'index.php';
    if ($_trn_can) $ret['MODS']['TRAINING'][] = trn_esc(trn_text('MenuFollowup')) . '|' . $_trn_url . 'followup.php';
    if (!empty($on) && intval($_SESSION['TourId'] ?? 0) > 0) {
        $ret['MODS']['TRAINING'][] = trn_esc(trn_text('MenuDashboard')) . '|' . $_trn_url . 'dashboard.php';
    }
    if ($_trn_run) $ret['MODS']['TRAINING'][] = trn_esc(trn_text('MenuLive')) . '|' . $_trn_url . 'live.php';
    if ($_trn_run) $ret['MODS']['TRAINING'][] = trn_esc(trn_text('MenuShootoffs')) . '|' . $_trn_url . 'shootoffs.php';
    if ($_trn_run && intval($_trn_run->RnSimple)) {
        $ret['MODS']['TRAINING'][] = trn_esc(trn_text('MenuSimple')) . '|' . $_trn_url . 'menu-mode.php?full=0';
    }
    if ($_trn_can) {
        $ret['MODS']['TRAINING'][] = trn_esc(trn_text('MenuFormats')) . '|' . $_trn_url . 'templates.php';
        $ret['MODS']['TRAINING'][] = trn_esc(trn_text('MenuArchers')) . '|' . $_trn_url . 'archers.php';
        $ret['MODS']['TRAINING'][] = trn_esc(trn_text('MenuTransfer')) . '|' . $_trn_url . 'transfer.php';
    }
    // Updating or removing a module is for the server administrator. With an
    // account module, AclRoot is granted to every signed-in organiser outside a
    // competition, hence the additional AUTH_ROOT test read straight from the
    // session, which keeps this module independent of any account module.
    if (isset($acl) && function_exists('subFeatureAcl') && subFeatureAcl($acl, AclRoot, '') >= AclReadWrite
        && (empty($_SESSION['AUTH_User']) || !empty($_SESSION['AUTH_ROOT']))) {
        $ret['MODS']['TRAINING'][] = trn_esc(trn_text('MenuUpdate')) . '|' . $_trn_url . 'admin/update.php';
    }
}
unset($_trn_url, $_trn_can, $_trn_create, $_trn_after, $_trn_new, $_trn_done, $_trn_k, $_trn_v,
      $_trn_run, $_trn_rs, $_trn_row, $_trn_full, $_trn_duels, $_trn_menu);
