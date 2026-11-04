<?php
/**
 * Reading of qualification scorecards: capture, grid, identification, cells.
 *
 * The photo is analysed in the browser (assets/js/vision.js and its companions);
 * the server only gives the competition's archers (to tell whose card it is from its
 * barcode, or its printed target and name) and what ianseo holds for one of them.
 */

require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/page.php';

ocr_require_access();

$tour = (int)$_SESSION['TourId'];
$rs = safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId = $tour");
$numDist = ($r = safe_fetch($rs)) ? max(1, (int)$r->ToNumDist) : 1;
safe_free_result($rs);

// The scorecards on a printed sheet, as the core lays them out for a session's archers per
// target (Common/Lib/ScorecardsLib.php): two side by side up to two archers, three for three,
// five or six, four (two by two, or side by side) otherwise.
$sessions = [];
$perSheet = [];
$rs = safe_r_sql("SELECT SesOrder, SesName, SesAth4Target FROM Session WHERE SesTournament = $tour AND SesType = 'Q' ORDER BY SesOrder");
while ($r = safe_fetch($rs)) {
    $ath = (int)$r->SesAth4Target;
    $sessions[(int)$r->SesOrder] = $r->SesName !== '' ? $r->SesName : (string)$r->SesOrder;
    $perSheet[(int)$r->SesOrder] = $ath <= 2 ? 2 : (in_array($ath, [3, 5, 6], true) ? 3 : 4);
}
safe_free_result($rs);
$sheetCounts = $perSheet ? array_values(array_unique($perSheet)) : [1, 2, 3, 4];
sort($sheetCounts);

$PAGE_TITLE = ocr_text('PageTitle');
$JS_SCRIPT  = ocr_page_scripts();
include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';

$root = ['data-api' => ocr_url() . 'api/qualif.php'];
// Writing scores into ianseo: for those who may enter them.
if (ocr_has_access(AclReadWrite)) $root['data-score-api'] = ocr_url() . 'api/score.php';
$html  = ocr_page_root($root);
$html .= '<h1>' . ocr_t('PageTitle') . '</h1>';
$html .= '<p class="ocr-lead">' . ocr_t('Lead') . '</p>';

// --- What the scorecards are: asked first, in this order (the session, its distance, the
// archers on each printed sheet, as the session's targets call for). The rest of the page shows
// once all three are given: every reading depends on them.
$choose = '<option value="">' . ocr_t('Choose') . '</option>';
$html .= '<form id="ocr-find" class="ocr-bar" autocomplete="off">';
if ($sessions) {
    $html .= '<label>' . ocr_t('Session') . ' <select id="ocr-session" required>' . (count($sessions) > 1 ? $choose : '');
    foreach ($sessions as $order => $name) $html .= '<option value="' . $order . '" data-sheet="' . $perSheet[$order] . '">' . ocr_esc($name) . '</option>';
    $html .= '</select></label>';
}
$html .= '<label>' . ocr_t('Distance') . ' <select id="ocr-dist" required>' . ($numDist > 1 ? $choose : '');
for ($d = 1; $d <= $numDist; $d++) $html .= '<option value="' . $d . '">' . $d . '</option>';
$html .= '</select></label>';
// Archers per sheet: only what the core can print for this competition's sessions (one
// session: given at once).
$html .= '<label>' . ocr_t('Expected') . ' <select id="ocr-expected" required>' . (count($sheetCounts) > 1 ? $choose : '');
foreach ($sheetCounts as $n) $html .= '<option value="' . $n . '">' . $n . '</option>';
$html .= '</select></label>';
$html .= '</form>';
$html .= '<p id="ocr-setup" class="ocr-msg ocr-msg-info">' . ocr_t('SetupFirst') . '</p>';

$html .= ocr_page_options();
$html .= ocr_page_body();
$html .= '</div>';
echo $html;

include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php';
