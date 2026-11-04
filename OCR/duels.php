<?php
/**
 * Reading of duel scorecards (brackets and round robin), one sheet a match: its two cards
 * side by side (Final/Individual and Final/Team PDFScoreMatch.php, Modules/RoundRobin/
 * PdfScorecards.php).
 *
 * The photo is analysed in the browser as on the qualification page (the same script, with
 * assets/js/duel.js for what a match sheet asks); the server gives the round's matches as
 * their sheets print them, and writes and confirms what the operator validates
 * (api/duel.php).
 */

require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/page.php';
require_once __DIR__ . '/lib/duel.php';

CheckTourSession(true);
if (!ocr_duel_access(AclReadOnly)) checkFullACL([AclIndividuals, AclTeams, AclRobin], '', AclReadOnly);

// The rounds, as the core's scorecard printouts list them: today's and those not over first
// (the two boxes, as there; the list follows them).
$tours = ocr_duel_tours(true, true);

$PAGE_TITLE = ocr_text('DuelTitle');
$JS_SCRIPT  = ocr_page_scripts(['assets/js/duel-layout.js', 'assets/js/duel.js']);
include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';

$root = ['data-api' => ocr_url() . 'api/duel.php', 'data-kind' => 'duel'];
// Writing and confirming matches: for those who may enter one kind of them at least.
if (ocr_duel_access(AclReadWrite)) $root['data-duel-write'] = '1';
$html  = ocr_page_root($root);
$html .= '<h1>' . ocr_t('DuelTitle') . '</h1>';
$html .= '<p class="ocr-lead">' . ocr_t('DuelLead') . '</p>';

// --- The round being shot: asked first (every reading depends on its matches).
$html .= '<form id="ocr-find" class="ocr-bar" autocomplete="off">';
$html .= '<label>' . ocr_t('DuelTour') . ' <select id="ocr-tour" required><option value="">' . ocr_t('Choose') . '</option>';
foreach ($tours as $t) $html .= '<option value="' . ocr_esc($t['key']) . '">' . ocr_esc($t['label']) . '</option>';
$html .= '</select></label>';
$html .= '<label class="ocr-option"><input type="checkbox" id="ocr-today" checked> ' . get_text('ScheduleToday', 'Tournament') . '</label>';
$html .= '<label class="ocr-option"><input type="checkbox" id="ocr-unfinished" checked> ' . get_text('ScheduleUnfinished', 'Tournament') . '</label>';
// One match a sheet: its two cards.
$html .= '<input type="hidden" id="ocr-expected" value="2">';
$html .= '</form>';
$html .= '<p id="ocr-setup" class="ocr-msg ocr-msg-info">' . ocr_t('DuelSetupFirst') . '</p>';

$html .= ocr_page_options();
// The camera's automatic and manual modes take a picture, read as an imported one is; the video
// mode (cells read as the video goes) is the qualification page's only.
$html .= ocr_page_body(['auto', 'manual']);
$html .= '</div>';
echo $html;

include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php';
