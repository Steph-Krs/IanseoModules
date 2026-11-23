<?php
/**
 * Dashboard of a training: every step in the order a coach needs it, each one
 * a button or a link to the core screen that does it, so that no menu has to
 * be searched.
 */

require_once __DIR__ . '/lib/boot.php';
$tourId = trn_require_tour();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!trn_token_ok()) {
        trn_flash(trn_text('ErrToken'), 'err');
        trn_redirect('dashboard.php');
    }
    $act = (string)($_POST['act'] ?? '');
    $ses = intval($_POST['ses'] ?? 0);
    $r = null;
    if ($act === 'replace') trn_unplace_session($tourId, $ses);
    if ($act === 'place' || $act === 'replace') $r = trn_place_session($tourId, $ses);
    if ($act === 'rhythm') {
        $r = trn_set_rhythm($tourId, $ses, intval($_POST['n'] ?? 0));
        trn_flash(trn_text('RhythmChanged'));
    }
    if ($act === 'targets') {
        // Computed again here: what the page showed may be out of date.
        $need = trn_targets_needed($tourId, $ses)['need'];
        if ($need > 0) {
            $r = trn_set_targets($tourId, $ses, $need);
            trn_flash(trn_text('TargetsAdjusted', $need));
        }
    }
    if ($r) {
        trn_flash(trn_text('Placed', $r['placed']));
        if ($r['left']) trn_flash(trn_text('PlacedLeft', $r['left']), 'err');
    }
    trn_redirect('dashboard.php');
}

$tour = safe_fetch(safe_r_sql("SELECT ToCode, ToName, ToWhenFrom, ToWhenTo FROM Tournament WHERE ToId = $tourId"));
$run = trn_run($tourId);
$entries = trn_entries($tourId);
$sessions = trn_sessions($tourId);
$kinds = trn_team_kinds($tourId);
$duelEvents = trn_duel_events($tourId);
$hasInd = $hasTeam = false;
foreach ($duelEvents as $e) { if (intval($e->EvTeamEvent)) $hasTeam = true; else $hasInd = true; }
$root = $CFG->ROOT_DIR;

$step = 0;
$card = function ($title, $body) use (&$step) {
    $step++;
    return '<section class="trn-step"><h2><span class="trn-num">' . $step . '</span> ' . trn_esc($title) . '</h2>' . $body . '</section>';
};
$link = function ($href, $label, $blank = false, $primary = false) {
    return '<a class="trn-btn' . ($primary ? ' trn-primary' : '') . '" href="' . trn_esc($href) . '"' . ($blank ? ' target="PrintOut"' : '') . '>' . trn_esc($label) . '</a> ';
};
$post = function ($act, $ses, $label, $confirm = '') {
    return '<form method="post" class="trn-inline"' . ($confirm !== '' ? ' onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . trn_esc($confirm) . '"' : '') . '>'
        . trn_token_field() . '<input type="hidden" name="act" value="' . $act . '"><input type="hidden" name="ses" value="' . intval($ses) . '">'
        . '<button type="submit" class="trn-btn">' . trn_esc($label) . '</button></form> ';
};

trn_page_open(trn_text('DashTitle'));

echo '<p class="trn-lead"><b>' . trn_esc($tour->ToName) . '</b> - ' . trn_esc(trn_date($tour->ToWhenFrom))
    . ($tour->ToWhenTo !== $tour->ToWhenFrom ? ' / ' . trn_esc(trn_date($tour->ToWhenTo)) : '')
    . ' - <span class="trn-note">' . trn_esc($tour->ToCode) . ($run && $run->TmName ? ' - ' . trn_esc($run->TmName) : '') . '</span>'
    . ($run && intval($run->RnQualSim) ? ' <span class="trn-badge">' . trn_t('DuelsOnly') . '</span>' : '') . '</p>';
if (!$run) echo '<p class="trn-note">' . trn_t('DashNotRun') . '</p>';

// 1. Archers and teams.
$body = '<p>' . trn_t('DashArchers', count($entries)) . '</p><p>' . $link('participants.php', trn_text('MenuParticipants'), false, true);
if ($kinds['team'] || $kinds['mixed']) {
    $t = trn_teams($tourId);
    $body .= $link('teams.php', trn_text('MenuTeams')) . '<span class="trn-note">' . trn_t('DashTeams', count($t['team']) + count($t['mixed'])) . '</span>';
}
echo $card(trn_text('StepArchers'), $body . '</p>');

$sim = $run && intval($run->RnQualSim);
if (!$sim) {
    // 2. Targets.
    $count = [];
    foreach ($entries as $e) {
        $s = intval($e->QuSession);
        $count[$s]['all'] = ($count[$s]['all'] ?? 0) + 1;
        if (intval($e->QuTarget) > 0) $count[$s]['on'] = ($count[$s]['on'] ?? 0) + 1;
    }
    $body = '';
    $rhythms = [1 => 'A', 2 => 'AB', 3 => 'ABC', 4 => 'AB-CD'];
    foreach ($sessions as $o => $ses) {
        $body .= '<p><b>' . trn_esc($ses->SesName !== '' ? $ses->SesName : trn_text('SessionN', $o)) . '</b> : '
            . trn_t('DashPlaced', ['on' => $count[$o]['on'] ?? 0, 'all' => $count[$o]['all'] ?? 0]) . ' '
            . $post('place', $o, trn_text('PlaceDo'))
            . $post('replace', $o, trn_text('PlaceAgain'), trn_text('PlaceAgainConfirm')) . '</p>';
        // Rhythm of the session (changing it places everybody again), and the number of targets
        // adjusted to what the archers need, in one click: never changed on its own.
        $need = ($count[$o]['all'] ?? 0) ? trn_targets_needed($tourId, $o) : ['have' => intval($ses->SesTar4Session), 'need' => 0];
        $body .= '<p>' . trn_t('Rhythm') . ' ';
        foreach ($rhythms as $n => $label) {
            $current = intval($ses->SesAth4Target) === $n;
            $body .= '<form method="post" class="trn-inline"' . ($current ? '' : ' onsubmit="return confirm(this.dataset.confirm)" data-confirm="'
                . trn_esc(trn_text('RhythmConfirm', $label)) . '"') . '>' . trn_token_field()
                . '<input type="hidden" name="act" value="rhythm"><input type="hidden" name="ses" value="' . intval($o) . '">'
                . '<input type="hidden" name="n" value="' . $n . '"><button type="submit" class="trn-btn' . ($current ? ' trn-primary' : '') . '"'
                . ($current ? ' disabled' : '') . '>' . $label . '</button></form> ';
        }
        $body .= ' <span class="trn-note">' . trn_t('TargetsHave', $need['have']) . '</span> ';
        if ($need['need'] > 0 && $need['need'] !== $need['have']) {
            $body .= $post('targets', $o, trn_text('TargetsAdjust', $need['need']));
        }
        $body .= '</p>';
        $off = ($count[$o]['all'] ?? 0) - ($count[$o]['on'] ?? 0);
        if ($off > 0) {
            $body .= '<p class="trn-msg trn-err">' . trn_t($need['need'] > $need['have'] ? 'TargetsMissing' : 'TargetsOff', [
                'off' => $off, 'have' => $need['have'], 'need' => $need['need'], 'rhythm' => $rhythms[intval($ses->SesAth4Target)] ?? intval($ses->SesAth4Target),
            ]) . '</p>';
        }
    }
    $body .= '<p>' . $link($root . 'Modules/DragDropTarget/Qualification/index.php', trn_text('PlaceManual')) . '</p>';
    echo $card(trn_text('StepTargets'), $body);

    // 3. Scorecards.
    $body = '<p>';
    foreach ($sessions as $o => $ses) {
        // With the ISK-NG QR codes and the barcode, as the core's printout ticks them for ISK-Lite.
        $q = http_build_query(['ScoreDraw' => 'Complete', 'ScoreHeader' => 1, 'ScoreLogos' => 1, 'ScoreFlags' => 1,
            'ScorePageHeaderFooter' => 1, 'x_Session' => $o, 'noEmpty' => 1, 'ScoreDist' => [0],
            'ScoreBarcode' => 1, 'QRCode' => ['ISK-NG']]);
        $body .= $link($root . 'Qualification/PDFScore.php?' . $q, trn_text('ScorecardsOf', $ses->SesName !== '' ? $ses->SesName : trn_text('SessionN', $o)), true, true);
    }
    $body .= $link($root . 'Qualification/PrintScore.php', trn_text('MoreOptions')) . '</p>';
    echo $card(trn_text('StepScorecards'), $body);

    // 4. Scores.
    $done = 0;
    foreach ($entries as $e) if (intval($e->QuScore) > 0) $done++;
    $body = '<p>' . trn_t('DashScores', ['done' => $done, 'all' => count($entries)]) . '</p><p>'
        . $link($root . 'Qualification/index.php', trn_text('ManualQualEntry'), false, true)
        . $link($root . 'Api/ISK-NG/Sessions.php', get_text('ManageLockedSessions', 'Api'))
        . $link($root . 'Qualification/PrnIndividualAbs.php', trn_text('QualRanking'), true) . '</p>';
    echo $card(trn_text('StepScores'), $body);
} else {
    echo $card(trn_text('StepQualSim'), '<p>' . trn_t('QualSimDone') . '</p>');
}

// 5. Duels.
if ($duelEvents) {
    $body = '<ul class="trn-events">';
    foreach ($duelEvents as $e) {
        $state = intval($e->EvElimType) !== 0 ? trn_text('EventCore') : (intval($e->EvShootOff) ? trn_text('EventLaunched') : trn_text('EventWaiting'));
        $body .= '<li>' . trn_esc($e->EvCode . ' - ' . $e->EvEventName) . ' : <span class="trn-note">' . trn_esc($state) . '</span></li>';
    }
    $body .= '</ul><p>' . $link('ranking.php', trn_text('MenuRanking'), false, true) . '</p>';
    if ($hasInd) {
        $body .= '<p><b>' . trn_esc(get_text('MenuLM_Individual Finals')) . '</b> : '
            . $link($root . 'Final/Individual/PrintScore.php', get_text('MenuLM_Scorecard Printout'))
            . $link($root . 'Final/Individual/InsertPoint_Bra.php', get_text('MenuLM_Data insert (Bracket view)')) . '</p>';
    }
    if ($hasTeam) {
        $body .= '<p><b>' . trn_esc(get_text('MenuLM_Team Finals')) . '</b> : '
            . $link($root . 'Final/Team/PrintScore.php', get_text('MenuLM_Scorecard Printout'))
            . $link($root . 'Final/Team/InsertPoint_Bra.php', get_text('MenuLM_Data insert (Bracket view)')) . '</p>';
    }
    echo $card(trn_text('StepDuels'), $body);
}

// 6. Results.
$body = '<p>';
if (!$sim) {
    $body .= $link($root . 'Qualification/PrnIndividualAbs.php', trn_text('QualRanking'), true);
    if ($kinds['team'] || $kinds['mixed']) $body .= $link($root . 'Qualification/PrnTeamAbs.php', trn_text('QualRankingTeam'), true);
}
if ($hasInd) {
    $body .= $link($root . 'Final/Individual/PrnBracket.php?ShowTargetNo=1&ShowSchedule=1', get_text('BracketsInd'), true)
        . $link($root . 'Final/Individual/PrnRanking.php', trn_text('FinalRanking'), true);
}
if ($hasTeam) {
    $body .= $link($root . 'Final/Team/PrnBracket.php?ShowTargetNo=1&ShowSchedule=1', get_text('BracketsSq'), true)
        . $link($root . 'Final/Team/PrnRanking.php', trn_text('FinalRankingTeam'), true);
}
echo '<div id="trn-results"></div>' . $card(trn_text('StepResults'), $body . '</p>');

// Menu of this browser.
$full = !empty($_COOKIE['TRN_FULL']);
echo '<p class="trn-note trn-menu-mode">' . trn_t($full ? 'MenuIsFull' : 'MenuIsSimple') . ' '
    . $link('menu-mode.php?full=' . ($full ? 0 : 1), trn_text($full ? 'MenuSimple' : 'MenuFull')) . '</p>';

trn_page_close();
