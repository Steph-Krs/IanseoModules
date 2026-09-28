<?php
/**
 * Page of the scorecard splitter: the options, the archive, and the list of clubs.
 *
 * The options are those of the core's printout that matter for scorecards
 * handed out beforehand (sessions, distances, header, flags, barcode), under the
 * core's own labels, plus the choice of hiding the target number. The archive
 * holds one PDF per club or one per archer; each club's PDF can also be opened
 * on its own from the list. The page only reads the competition.
 */

require_once __DIR__ . '/lib/boot.php';

scs_require_access();

/**
 * A text of the core's language files, escaped for HTML.
 *
 * The core's strings are plain text that may hold a bare "&"; entities already
 * written in them are left as they are.
 *
 * @param string $key
 * @param string $module
 * @return string
 */
function scs_core_t($key, $module = 'Common') {
    return htmlspecialchars(get_text($key, $module), ENT_QUOTES, 'UTF-8', false);
}

/**
 * A checkbox with its label.
 *
 * @param string $name Field name.
 * @param string $label Label, already escaped.
 * @param bool $checked
 * @param string $value
 * @return string
 */
function scs_checkbox($name, $label, $checked, $value = '1') {
    return '<label class="scs-check"><input type="checkbox" name="' . scs_esc($name) . '" value="' . scs_esc($value) . '"'
        . ($checked ? ' checked' : '') . '> ' . $label . '</label>';
}

$sessions = scs_sessions();
$comp     = scs_competition();
$summary  = scs_summary($sessions);
$totals   = $summary['totals'];

$PAGE_TITLE = scs_text('ModuleName');
$JS_SCRIPT  = [
    '<link rel="stylesheet" href="' . scs_esc(scs_asset('assets/splitter.css')) . '">',
    scs_js_strings(),
    '<script src="' . scs_esc(scs_asset('assets/splitter.js')) . '"></script>',
];
include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';

$html  = '<div id="scs" class="scs-page" data-api="' . scs_esc(scs_url() . 'api/build.php') . '"'
    . ' data-pdf="' . scs_esc(scs_url() . 'pdf.php') . '">';
$html .= '<h1>' . scs_t('ModuleName') . '</h1>';
$html .= '<p class="scs-lead">' . scs_t('Lead') . '</p>';

if ($comp['field']) {
    $html .= '<div class="scs-msg scs-msg-warn">' . scs_t('NotTargetArchery') . '</div>';
} elseif (!$totals['cards']) {
    $html .= '<div class="scs-msg scs-msg-warn">' . scs_t('NothingToPrint') . '</div>';
} else {
    // --- What will not be printed, and why ---------------------------------------
    foreach (['outside' => 'WarnOutside', 'twice' => 'WarnTwice', 'unplaced' => 'WarnUnplaced'] as $k => $text) {
        if ($summary[$k]) $html .= '<div class="scs-msg scs-msg-warn">' . scs_t($text, $summary[$k]) . '</div>';
    }

    // --- Options -----------------------------------------------------------------
    $html .= '<form id="scs-form" class="scs-card" autocomplete="off">';
    $html .= '<input type="hidden" name="csrf" value="' . scs_esc(scs_token()) . '">';
    $html .= '<h2>' . scs_t('OptionsTitle') . '</h2>';
    $html .= '<div class="scs-grid">';

    $html .= '<fieldset><legend>' . scs_t('SessionsTitle') . '</legend>';
    foreach ($sessions as $order => $s) {
        $n = $totals['sessions'][$order] ?? 0;
        $html .= scs_checkbox('Sessions[]', scs_esc($s['descr'])
            . ' <span class="scs-hint">' . scs_t('SessionCards', $n) . '</span>', $n > 0, (string)$order);
    }
    $html .= '</fieldset>';

    $html .= '<fieldset><legend>' . scs_core_t('Distance', 'Tournament') . '</legend>';
    $html .= scs_checkbox('ScoreDist[]', scs_core_t('NoDistance', 'Tournament'), false, '0');
    for ($d = 1; $d <= $comp['distances']; $d++) {
        $html .= scs_checkbox('ScoreDist[]', (string)$d, true, (string)$d);
    }
    $html .= '</fieldset>';

    $html .= '<fieldset><legend>' . scs_t('LayoutTitle') . '</legend>';
    $html .= scs_checkbox('ScorePageHeaderFooter', scs_core_t('ScorePageHeaderFooter', 'Tournament'), true);
    $html .= '<div class="scs-sub">' . scs_checkbox('HideHeaderText', scs_t('HideHeaderText'), false) . '</div>';
    $html .= scs_checkbox('ScoreHeader', scs_core_t('ScoreTournament', 'Tournament'), false);
    $html .= scs_checkbox('ScoreLogos', scs_core_t('ScoreLogos', 'Tournament'), false);
    $html .= scs_checkbox('ScoreFlags', scs_core_t('ScoreFlags', 'Tournament'), true);
    $html .= scs_checkbox('GetArcInfo', scs_core_t('GetArcInfo', 'Tournament'), false);
    // Unlike the core, the barcode is off and the QR codes on by default: these
    // scorecards are handed out to be scored on a device, not read by a scanner.
    if (module_exists('Barcodes')) {
        $html .= scs_checkbox('ScoreBarcode', scs_core_t('ScoreBarcode', 'Tournament'), false);
    }
    if (getModuleParameter('ISK-NG', 'UsePersonalDevices', '')) {
        $html .= scs_checkbox('ScoreQrPersonal', scs_core_t('UsePersonalDevices-Print', 'Api'), true);
    }
    foreach (scs_qr_apis() as $api) {
        $html .= scs_checkbox('QRCode[]', scs_core_t($api . '-QRCode', 'Api'), true, $api);
    }
    $html .= scs_checkbox('HideTarget', scs_t('HideTarget'), true);
    $html .= '<p class="scs-hint">' . scs_t('HideTargetHint') . '</p>';
    $html .= '</fieldset>';

    $html .= '</div>';

    // --- Archive -----------------------------------------------------------------
    $html .= '<h2>' . scs_t('ArchiveTitle') . '</h2>';
    $html .= '<div class="scs-modes">'
        . '<label class="scs-check"><input type="radio" name="Mode" value="club" checked> ' . scs_t('ModeClub') . '</label>'
        . '<label class="scs-check"><input type="radio" name="Mode" value="archer"> ' . scs_t('ModeArcher') . '</label>'
        . '</div>';
    if (class_exists('ZipArchive')) {
        $html .= '<div class="scs-run">'
            . '<button type="button" id="scs-zip" class="scs-btn scs-btn-primary">' . scs_t('ZipButton') . '</button>'
            . '<progress id="scs-progress" max="1" value="0" hidden></progress>'
            . '<span id="scs-status" role="status"></span>'
            . '</div>';
    } else {
        $html .= '<div class="scs-msg scs-msg-warn">' . scs_t('ErrZip') . '</div>';
    }
    $html .= '</form>';

    // --- Clubs -------------------------------------------------------------------
    $pdfIcon = $CFG->ROOT_DIR . 'Common/Images/pdf.gif';
    $html .= '<div class="scs-card">';
    $html .= '<h2>' . scs_t('ClubsTitle', count($summary['clubs'])) . '</h2>';
    $html .= '<p class="scs-hint">' . scs_t('ClubsHint') . '</p>';
    $html .= '<div class="scs-scroll"><table class="scs-table"><thead><tr>'
        . '<th>' . scs_t('ColCode') . '</th><th>' . scs_t('ColClub') . '</th>'
        . '<th class="scs-num">' . scs_t('ColArchers') . '</th>';
    foreach ($sessions as $s) $html .= '<th class="scs-num">' . scs_esc($s['descr']) . '</th>';
    $html .= '<th class="scs-num">' . scs_t('ColCards') . '</th><th>' . scs_t('ColPdf') . '</th></tr></thead><tbody>';

    foreach ($summary['clubs'] as $club) {
        $name  = $club['code'] === '' ? scs_t('NoClub') : scs_esc($club['name']);
        $html .= '<tr><td>' . scs_esc($club['code']) . '</td><td>' . $name . '</td>'
            . '<td class="scs-num">' . $club['archers'] . '</td>';
        foreach (array_keys($sessions) as $order) {
            $html .= '<td class="scs-num">' . ($club['sessions'][$order] ?: '') . '</td>';
        }
        $label = scs_t('ClubPdf', $club['code'] === '' ? scs_text('NoClub') : $club['code']);
        $html .= '<td class="scs-num">' . $club['cards'] . '</td>'
            . '<td><a class="scs-pdf" target="_blank" href="#" data-key="' . scs_esc($club['file']) . '" title="' . $label . '">'
            . '<img src="' . scs_esc($pdfIcon) . '" alt="' . $label . '"></a></td></tr>';
    }

    $html .= '</tbody><tfoot><tr><td></td><td>' . scs_t('Total') . '</td>'
        . '<td class="scs-num">' . $totals['archers'] . '</td>';
    foreach (array_keys($sessions) as $order) {
        $html .= '<td class="scs-num">' . $totals['sessions'][$order] . '</td>';
    }
    $html .= '<td class="scs-num">' . $totals['cards'] . '</td><td></td></tr></tfoot></table></div>';
    $html .= '</div>';
}

$html .= '</div>';
echo $html;

include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php';
