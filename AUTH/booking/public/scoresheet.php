<?php
/**
 * public/scoresheet.php — individual score sheet, ready to print.
 *
 * The number of ends and arrows comes from DistanceInformation, the distances from
 * TournamentDistances: the sheet follows exactly the format typed by the organiser, with no
 * setting of its own.
 *
 * Shown only when the organiser turned the option on for the competition, and only for the
 * connected archer's registration.
 */
require_once __DIR__ . '/boot.php';
require_once dirname(__DIR__) . '/lib/documents.php';

$archer = bk_require_archer();
$e = bk_doc_entry(intval($_GET['enid'] ?? 0));

if (!$e || bk_clean_licence($e->BrLicence) !== bk_clean_licence($archer->BaLicence)) {
    bk_head(bk_t('Scoresheet'), 'card');
    echo '<div class="bk-card"><h1>' . bk_e(bk_t('Unavailable')) . '</h1>' . bk_msg('err', bk_t('NotYourReg')) . '</div>';
    bk_foot();
    exit;
}
if (empty($e->BcAllowScoresheet)) {
    bk_head(bk_t('Scoresheet'), 'card');
    echo '<div class="bk-card"><h1>' . bk_e(bk_t('Unavailable')) . '</h1>' . bk_msg('err', bk_t('SsOff'))
       . '<p class="bk-alt"><a href="' . bk_e(bk_public_url('registrations.php')) . '">' . bk_e(bk_t('NavMyRegs')) . '</a></p></div>';
    bk_foot();
    exit;
}

$rhythm = bk_doc_rhythm($e->ToId, $e->QuSession);
$dists  = bk_doc_distances($e->ToId, $e->ToType, $e->EnDivision, $e->EnClass);
$face   = bk_doc_face($e->ToId, $e->EnTargetFace);

$css = function ($file) { return '<link rel="stylesheet" href="' . bk_e(bk_public_url('assets/' . $file)) . '?v=' . bk_e(bk_version()) . '">'; };
$out = '<!DOCTYPE html><html lang="' . bk_e(aut_lang_code()) . '"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . bk_e(bk_t('Scoresheet')) . '</title>'
    . $css('bk.css') . $css('print.css') . '</head><body><div id="bk" class="bk-doc">'
    . '<p class="bk-noprint"><a href="' . bk_e(bk_public_url('registrations.php')) . '">' . bk_e(bk_t('BackMyRegs')) . '</a>'
    . ' &nbsp; <button onclick="window.print()" class="bk-btn bk-btn-primary">' . bk_e(bk_t('Print')) . '</button></p>'
    . '<header class="bk-doc-head"><h1>' . bk_e(bk_t('Scoresheet')) . '</h1>'
    . '<p class="bk-doc-comp"><b>' . bk_e($e->ToName) . '</b><br>' . bk_e(bk_date_range($e->ToWhenFrom, $e->ToWhenTo))
    . ($e->ToWhere ? ' — ' . bk_e($e->ToWhere) : '') . '</p></header>';

$out .= '<table class="bk-doc-id">'
    . '<tr><th>' . bk_e(bk_t('ColArcher')) . '</th><td>' . bk_e($e->EnFirstName . ' ' . $e->EnName) . '</td>'
    . '<th>' . bk_e(bk_t('Licence')) . '</th><td>' . bk_e($e->EnCode) . '</td></tr>'
    . '<tr><th>' . bk_e(bk_t('Club')) . '</th><td>' . bk_e($e->CoName ?: $e->CoCode) . '</td>'
    . '<th>' . bk_e(bk_t('SsCategory')) . '</th><td>' . bk_e(($e->DivDescription ?: $e->EnDivision) . ' — ' . ($e->ClDescription ?: $e->EnClass)) . '</td></tr>'
    . '<tr><th>' . bk_e(bk_t('SsDeparture')) . '</th><td>' . intval($e->QuSession) . '</td>'
    . '<th>' . bk_e(bk_t('SsTarget')) . '</th><td>' . (intval($e->QuTarget) > 0
        ? bk_e(intval($e->QuTarget) . $e->QuLetter) : bk_e(bk_t('SsTargetNone'))) . '</td></tr>';
if ($dists || $face) {
    $labels = array();
    foreach ($dists as $d) $labels[] = $d['label'] . ($d['metres'] ? ' (' . $d['metres'] . ' m)' : '');
    $out .= '<tr><th>' . bk_e(bk_t('SsDistance')) . '</th><td>' . bk_e(implode(' · ', $labels) ?: '—') . '</td>'
        . '<th>' . bk_e(bk_t('SsFace')) . '</th><td>' . bk_e($face ?: '—') . '</td></tr>';
}
$out .= '</table>';

if (!$rhythm) $out .= '<p class="bk-empty">' . bk_e(bk_t('SsNoRhythm')) . '</p>';

foreach ($rhythm as $i => $r) {
    $dLabel = $dists[$i]['label'] ?? bk_t('SsDistanceX', $r['dist']);
    $head = '';
    for ($a = 1; $a <= $r['arrows']; $a++) $head .= '<th>' . bk_e(bk_t('SsArrowX', $a)) . '</th>';
    $out .= '<section class="bk-doc-dist"><h2>' . bk_e($dLabel . ' — ' . bk_t('SsEndsOf', array('ends' => $r['ends'], 'arrows' => $r['arrows']))) . '</h2>'
        . '<table class="bk-doc-grid"><tr><th>' . bk_e(bk_t('SsEnd')) . '</th>' . $head
        . '<th>' . bk_e(bk_t('SsEnd')) . '</th><th>' . bk_e(bk_t('SsRunning')) . '</th></tr>';
    for ($v = 1; $v <= $r['ends']; $v++) {
        $out .= '<tr><td class="bk-doc-n">' . $v . '</td>' . str_repeat('<td></td>', $r['arrows']) . '<td></td><td></td></tr>';
    }
    $out .= '<tr class="bk-doc-tot"><td colspan="' . ($r['arrows'] + 1) . '">' . bk_e(bk_t('SsTotalX', $dLabel)) . '</td><td></td><td></td></tr>'
        . '</table></section>';
}

$out .= '<table class="bk-doc-sign"><tr><th>' . bk_e(bk_t('SsSignArcher')) . '</th><th>' . bk_e(bk_t('SsSignScorer')) . '</th>'
    . '<th>10 / 9</th><th>' . bk_e(bk_t('SsTotal')) . '</th></tr>'
    . '<tr>' . str_repeat('<td class="bk-doc-box"></td>', 4) . '</tr></table>'
    . '<p class="bk-doc-foot">' . bk_e(bk_t('SsFoot')) . '</p></div></body></html>';
echo $out;
