<?php
/**
 * Live page of a training: while it is open, the simulated competitors shoot.
 *
 * ianseo tells no module when a score arrives, so the fictitious competitors
 * and the athletes of a World Archery competition move only when this page
 * asks the server, every few seconds (lib/live.php). The page says so in large
 * letters: a coach would not think of it.
 *
 * POST ajax=1 (with the form token): one tick, then the state as JSON.
 * POST act=all / act=pace: show every qualification arrow now / follow the archers again.
 */

require_once __DIR__ . '/lib/boot.php';
$tourId = trn_require_tour();
$run = trn_run($tourId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    if (!trn_token_ok()) JsonOut(['error' => 1, 'msg' => trn_text('ErrToken')]);
    // The tick can take a moment: other pages of this browser must not wait for it.
    session_write_close();
    $tick = trn_live_tick($tourId);
    JsonOut(['error' => 0, 'msg' => '', 'tick' => $tick, 'time' => date('H:i:s'), 'state' => trn_live_state($tourId)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!trn_token_ok()) {
        trn_flash(trn_text('ErrToken'), 'err');
        trn_redirect('live.php');
    }
    $act = (string)($_POST['act'] ?? '');
    if ($run && ($act === 'all' || $act === 'pace')) {
        safe_w_sql("UPDATE TrainingRuns SET RnRevealAll = " . ($act === 'all' ? 1 : 0) . " WHERE RnTournament = $tourId");
        trn_flash(trn_text($act === 'all' ? 'LiveAllDone' : 'LivePaceDone'));
    }
    trn_redirect('live.php');
}

$ghosts = trn_ghosts($tourId);
$wa = $fict = 0;
foreach ($ghosts as $g) { if (intval($g->GhKind) === 1) $wa++; else $fict++; }

trn_page_open(trn_text('LiveTitle'), ['assets/live.js']);
echo '<p><a class="trn-btn" href="dashboard.php">' . trn_t('BackDashboard') . '</a></p>';

echo '<div class="trn-live-alert"><b>' . trn_t('LiveKeepOpen') . '</b><br>' . trn_t('LiveKeepOpenWhy') . '</div>';

if (!$ghosts) {
    echo '<p class="trn-note">' . trn_t('LiveNone') . ' <a class="trn-btn" href="participants.php#trn-ghosts">' . trn_t('GhostsTitle') . '</a></p>';
}
echo '<p class="trn-lead">' . trn_t('LiveWho', ['fict' => $fict, 'wa' => $wa]) . '</p>';

if ($run && !intval($run->RnQualSim) && $ghosts) {
    $all = intval($run->RnRevealAll) === 1;
    echo '<p>' . trn_t($all ? 'LiveAllOn' : 'LivePaceOn') . ' <form method="post" class="trn-inline" onsubmit="return confirm(this.dataset.confirm)" data-confirm="'
        . trn_esc(trn_text($all ? 'LivePaceConfirm' : 'LiveAllConfirm')) . '">' . trn_token_field()
        . '<input type="hidden" name="act" value="' . ($all ? 'pace' : 'all') . '"><button type="submit" class="trn-btn">'
        . trn_t($all ? 'LivePace' : 'LiveAll') . '</button></form></p>';
}

echo '<p class="trn-live-status" id="trn-live-status" data-csrf="' . trn_esc(trn_token()) . '" data-tour="' . intval($tourId) . '">'
    . trn_t('LiveStarting') . '</p>';
// Events shown: chosen on this browser (kept by the browser, per competition), all by default.
echo '<details class="trn-card" id="trn-live-pick"><summary>' . trn_t('LiveEvents') . '</summary>'
    . '<p class="trn-note">' . trn_t('LiveEventsHelp') . '</p><div class="trn-columns" id="trn-live-events"></div>'
    . '<p><button type="button" class="trn-btn" data-all="1">' . trn_t('LiveEventsAll') . '</button> '
    . '<button type="button" class="trn-btn" data-all="0">' . trn_t('LiveEventsNone') . '</button></p></details>';
echo '<p class="trn-msg trn-err" id="trn-live-pending" hidden><span></span> <a class="trn-btn trn-primary" href="shootoffs.php">' . trn_t('SoGo') . '</a></p>';
echo '<div id="trn-live-duels"></div><div id="trn-live-qual"></div>';

trn_page_close();
