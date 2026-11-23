<?php
/**
 * Home of the training module: the trainings already created, the way to
 * create one, and, for whoever prepares them, the formats and the archers.
 */

require_once __DIR__ . '/lib/boot.php';
trn_require_admin();

$runs = [];
$rs = safe_r_sql("SELECT RnTournament, RnQualSim, ToCode, ToName, ToWhenFrom, TmName,
        (SELECT COUNT(*) FROM Entries WHERE EnTournament = ToId) AS Archers
    FROM TrainingRuns
    INNER JOIN Tournament ON ToId = RnTournament
    LEFT JOIN TrainingTemplates ON TmId = RnTemplate
    ORDER BY ToWhenFrom DESC, ToCode DESC
    LIMIT 100");
while ($r = safe_fetch($rs)) $runs[] = $r;
$formats = trn_tpl_list();

trn_page_open(trn_text('HomeTitle'));

echo '<p class="trn-lead">' . trn_t('HomeLead') . '</p>';
echo '<p><a class="trn-btn trn-primary" href="create.php">' . trn_t('MenuCreate') . '</a></p>';
if (!$formats) echo '<p class="trn-note">' . trn_t('HomeNoFormat') . '</p>';

echo '<h2>' . trn_t('HomeRuns') . '</h2>';
if (!$runs) {
    echo '<p class="trn-note">' . trn_t('HomeNoRun') . '</p>';
} else {
    echo '<table class="Tabella trn-table"><tr><th>' . trn_t('ColDate') . '</th><th>' . trn_t('ColName') . '</th><th>'
        . trn_t('ColFormat') . '</th><th>' . trn_t('ColArchers') . '</th><th></th></tr>';
    foreach ($runs as $r) {
        $open = $CFG->ROOT_DIR . 'Common/TourOn.php?ToId=' . intval($r->RnTournament) . '&BackTo=' . urlencode(trn_url() . 'dashboard.php');
        echo '<tr><td>' . trn_esc(trn_date($r->ToWhenFrom)) . '</td>'
            . '<td>' . trn_esc($r->ToName) . ' <span class="trn-note">' . trn_esc($r->ToCode) . '</span>'
            . (intval($r->RnQualSim) ? ' <span class="trn-badge">' . trn_t('DuelsOnly') . '</span>' : '') . '</td>'
            . '<td>' . trn_esc((string)$r->TmName) . '</td>'
            . '<td class="Center">' . intval($r->Archers) . '</td>'
            . '<td><a class="trn-btn" href="' . trn_esc($open) . '">' . trn_t('Open') . '</a></td></tr>';
    }
    echo '</table>';
}

echo '<h2>' . trn_t('HomePrepare') . '</h2>';
echo '<p><a class="trn-btn" href="templates.php">' . trn_t('MenuFormats') . '</a> '
    . '<a class="trn-btn" href="archers.php">' . trn_t('MenuArchers') . '</a> '
    . '<a class="trn-btn" href="transfer.php">' . trn_t('MenuTransfer') . '</a></p>';

trn_page_close();
