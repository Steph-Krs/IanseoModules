<?php
/**
 * Teams of the open training, composed or changed at any time: before the
 * qualification round, during it, or once it is over and the coach knows who
 * shot well. The team brackets are filled afterwards, on the ranking page.
 */

require_once __DIR__ . '/lib/boot.php';
$tourId = trn_require_tour();
$run = trn_run($tourId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!trn_token_ok()) {
        trn_flash(trn_text('ErrToken'), 'err');
        trn_redirect('teams.php');
    }
    trn_teams_save($tourId, trn_teams_from_post($_POST));
    if ($run && intval($run->RnQualSim)) trn_simulate_qualification($tourId);
    trn_flash(trn_text('Saved'));
    trn_redirect('teams.php');
}

$kinds = trn_team_kinds($tourId);
$people = [];
foreach (trn_entries($tourId) as $e) {
    $people[(string)$e->EnId] = trim($e->EnFirstName . ' ' . $e->EnName) . ' (' . $e->EnDivision . $e->EnClass . ')';
}

trn_page_open(trn_text('TeamsTitleAll'), ['assets/training.js']);
echo '<p><a class="trn-btn" href="dashboard.php">' . trn_t('BackDashboard') . '</a></p>';

if (!$kinds['team'] && !$kinds['mixed']) {
    echo '<p class="trn-note">' . trn_t('TeamsNone') . '</p>';
} else {
    echo '<p class="trn-lead">' . trn_t('TeamsHelp') . '</p>';
    echo '<form method="post" id="trn-teams-form">' . trn_token_field()
        . trn_teams_editor($kinds, trn_teams($tourId), $people)
        . '<p class="trn-submit"><button type="submit" class="trn-btn trn-primary">' . trn_t('Save') . '</button></p></form>';
}

trn_page_close();
