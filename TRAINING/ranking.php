<?php
/**
 * From the ranking to the brackets: one list per event with duels, in the
 * order of the qualification (ties next to each other), rearranged by dragging
 * or with the arrows, then "Launch the duels" fills the bracket.
 *
 * With a simulated qualification round every archer has one point: the order
 * is entirely the coach's choice.
 */

require_once __DIR__ . '/lib/boot.php';
$tourId = trn_require_tour();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!trn_token_ok()) {
        trn_flash(trn_text('ErrToken'), 'err');
        trn_redirect('ranking.php');
    }
    $event = (string)($_POST['event'] ?? '');
    $team = !empty($_POST['team']);
    $order = array_filter(explode(',', (string)($_POST['order'] ?? '')), 'strlen');
    $ok = $team ? trn_duel_launch_team($tourId, $event, $order) : trn_duel_launch_ind($tourId, $event, $order);
    trn_flash($ok ? trn_text('DuelsLaunched', $event) : trn_text('ErrLaunch', $event), $ok ? 'ok' : 'err');
    trn_redirect('ranking.php#ev-' . rawurlencode(($team ? 'T' : 'I') . $event));
}

$events = trn_duel_events($tourId);

trn_page_open(trn_text('RankingTitle'), ['assets/training.js']);
echo '<p><a class="trn-btn" href="dashboard.php">' . trn_t('BackDashboard') . '</a></p>';
echo '<p class="trn-lead">' . trn_t('RankingLead') . '</p>';

if (!$events) echo '<p class="trn-note">' . trn_t('RankingNone') . '</p>';

foreach ($events as $e) {
    $team = intval($e->EvTeamEvent) === 1;
    $id = ($team ? 'T' : 'I') . $e->EvCode;
    echo '<section class="trn-step" id="ev-' . trn_esc($id) . '"><h2>' . trn_esc($e->EvCode . ' - ' . $e->EvEventName) . '</h2>';
    if (intval($e->EvElimType) !== 0) {
        $page = $team ? 'Final/Team/AbsTeam.php' : 'Final/Individual/AbsIndividual.php';
        echo '<p class="trn-note">' . trn_t('RankingCore') . ' <a class="trn-btn" href="' . trn_esc($CFG->ROOT_DIR . $page) . '">'
            . trn_t('OpenCore') . '</a></p></section>';
        continue;
    }
    $list = $team ? trn_duel_list_team($tourId, $e->EvCode) : trn_duel_list_ind($tourId, $e->EvCode);
    if (!$list) {
        echo '<p class="trn-note">' . trn_t($team ? 'RankingNoTeam' : 'RankingNoArcher') . '</p></section>';
        continue;
    }
    $first = intval($e->EvFirstQualified);
    $last = $first + intval($e->EvNumQualified) - 1;
    echo '<p class="trn-note">' . trn_t('RankingQualified', ['n' => intval($e->EvNumQualified), 'all' => count($list)])
        . (intval($e->EvShootOff) ? ' <span class="trn-badge">' . trn_t('EventLaunched') . '</span>' : '') . '</p>';
    echo '<ol class="trn-sortable" data-first="' . $first . '" data-last="' . $last . '">';
    $prev = null;
    foreach ($list as $a) {
        $tie = $prev !== null && $prev === $a['score'] && $a['score'] > 0;
        echo '<li draggable="true" data-key="' . trn_esc($a['key']) . '"><span class="trn-handle" aria-hidden="true">&#8942;&#8942;</span> '
            . '<b>' . trn_esc($a['label']) . '</b> <span class="trn-note">' . trn_esc($a['club']) . '</span>'
            . '<span class="trn-score">' . intval($a['score']) . ($tie ? ' <span class="trn-tie">' . trn_t('Tie') . '</span>' : '') . '</span>'
            . ' <button type="button" class="trn-up" title="' . trn_t('MoveUp') . '">&#9650;</button>'
            . '<button type="button" class="trn-down" title="' . trn_t('MoveDown') . '">&#9660;</button></li>';
        $prev = $a['score'];
    }
    echo '</ol>';
    echo '<form method="post" class="trn-launch"' . (intval($e->EvShootOff) ? ' onsubmit="return confirm(this.dataset.confirm)" data-confirm="'
        . trn_esc(trn_text('RelaunchConfirm', $e->EvCode)) . '"' : '') . '>' . trn_token_field()
        . '<input type="hidden" name="event" value="' . trn_esc($e->EvCode) . '"><input type="hidden" name="team" value="' . ($team ? 1 : 0) . '">'
        . '<input type="hidden" name="order" value="">'
        . '<button type="submit" class="trn-btn trn-primary">' . trn_t(intval($e->EvShootOff) ? 'Relaunch' : 'Launch') . '</button></form>';
    echo '</section>';
}

trn_page_close();
