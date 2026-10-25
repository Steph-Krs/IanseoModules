<?php
/**
 * Reading of qualification scorecards: capture, grid, identification, cells.
 *
 * The photo is analysed in the browser (assets/js/vision.js and its companions);
 * the server only gives the competition's archers (to tell whose card it is from its
 * barcode, or its printed target and name) and what ianseo holds for one of them.
 */

require_once __DIR__ . '/lib/boot.php';

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
$JS_SCRIPT  = [
    // A phone or a tablet lays the page out at its own width (else as a desktop page shrunk:
    // tiny buttons, a sheet hard to frame).
    '<meta name="viewport" content="width=device-width, initial-scale=1">',
    '<link rel="stylesheet" href="' . ocr_esc(ocr_asset('assets/ocr.css')) . '">',
    ocr_js_strings(),
    '<script src="' . ocr_esc(ocr_asset('assets/js/vision.js')) . '"></script>',
    '<script src="' . ocr_esc(ocr_asset('assets/js/barcode.js')) . '"></script>',
    '<script src="' . ocr_esc(ocr_asset('assets/js/layout.js')) . '"></script>',
    '<script src="' . ocr_esc(ocr_asset('assets/js/nn.js')) . '"></script>',
    '<script src="' . ocr_esc(ocr_asset('assets/js/recognizer.js')) . '"></script>',
    '<script src="' . ocr_esc(ocr_asset('assets/js/solver.js')) . '"></script>',
    '<script src="' . ocr_esc(ocr_asset('assets/js/pool.js')) . '"></script>',
    '<script src="' . ocr_esc(ocr_asset('assets/js/text.js')) . '"></script>',
    '<script src="' . ocr_esc(ocr_asset('assets/js/vendor/jsQR.js')) . '"></script>',
    '<script src="' . ocr_esc(ocr_asset('assets/js/qr.js')) . '"></script>',
    '<script src="' . ocr_esc(ocr_asset('assets/js/orb.js')) . '"></script>',
    '<script src="' . ocr_esc(ocr_asset('assets/js/follow.js')) . '"></script>',
    '<script src="' . ocr_esc(ocr_asset('assets/js/live.js')) . '"></script>',
    '<script src="' . ocr_esc(ocr_asset('assets/js/secure.js')) . '"></script>',
    '<script src="' . ocr_esc(ocr_asset('assets/js/qualification.js')) . '"></script>',
];
include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';

$html  = '<div id="ocr" class="ocr-page" data-api="' . ocr_esc(ocr_url() . 'api/qualif.php') . '"'
    . ' data-tour-code="' . ocr_esc((string)($_SESSION['TourCode'] ?? '')) . '"'
    . ' data-arrow-model="' . ocr_esc(ocr_asset('assets/model/arrow.json')) . '"'
    . ' data-digit-model="' . ocr_esc(ocr_asset('assets/model/digit.json')) . '"'
    . ' data-text-model="' . ocr_esc(ocr_asset('assets/model/text.json')) . '"'
    . ' data-live-worker="' . ocr_esc(ocr_worker('assets/js/live-worker.js')) . '"'
    . ' data-read-worker="' . ocr_esc(ocr_worker('assets/js/read-worker.js')) . '"'
    . ' data-csrf="' . ocr_esc(ocr_token()) . '"'
    // The trial's recording and the cells in doubt sent: development tools, when shown.
    . (ocr_dev_on() ? ' data-trace-api="' . ocr_esc(ocr_url() . 'api/trace.php') . '"'
        . ' data-labels-api="' . ocr_esc(ocr_url() . 'api/labels.php') . '"' : '')
    // Writing scores into ianseo: for those who may enter them.
    . (ocr_has_access(AclReadWrite) ? ' data-score-api="' . ocr_esc(ocr_url() . 'api/score.php') . '"' : '')
    . (is_file(__DIR__ . '/assets/model/number.json') ? ' data-number-model="' . ocr_esc(ocr_asset('assets/model/number.json')) . '"' : '')
    . '>';
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

// --- Options, for the module's development, when shown (the reading's own settings are each mode's) ---
if (ocr_dev_on()) {
    $html .= '<details id="ocr-options" class="ocr-options"><summary>' . ocr_t('OptionsTitle') . '</summary>';
    foreach (['Marks' => 'marks', 'Record' => 'record', 'Doubts' => 'doubts'] as $key => $opt) {
        $html .= '<label class="ocr-option"><input type="checkbox" data-opt="' . $opt . '"> ' . ocr_t('Opt' . $key) . '</label>';
    }
    $html .= '</details>';
}

$html .= '<div class="ocr-main" id="ocr-main" hidden>';

// --- Capture: the camera in one of three modes, or a picture imported; one window shows
// the live view or the picture read (folded away once a picture is taken).
$html .= '<section class="ocr-card ocr-capture" id="ocr-capture">';
// The bar: the modes and a picture to import (left), the window folded or not (a triangle in
// the middle), the camera's own buttons (right: they stay on view when the window is folded).
$picto = function ($id, $key, $svg, $extra = '') {
    return '<button type="button" id="' . $id . '" class="ocr-btn ocr-picto"' . $extra . ' title="' . ocr_t($key) . '" aria-label="' . ocr_t($key) . '">' . $svg . '</button>';
};
// Take the picture: a viewfinder; back to live: a video camera; stop: the camera struck out (its
// body cut along the stroke by a mask, whatever the button's background, a dark theme's too).
$svgShot = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M2.5 7 V2.5 H7 M13 2.5 H17.5 V7 M17.5 13 V17.5 H13 M7 17.5 H2.5 V13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="10" cy="10" r="3"/></svg>';
$svgCamera = '<rect x="2" y="5" width="11" height="10" rx="1.6"/><path d="M14 8.6 L18 6.2 V13.8 L14 11.4 Z"/>';
$svgLive = '<svg viewBox="0 0 20 20" aria-hidden="true">' . $svgCamera . '</svg>';
$svgStop = '<svg viewBox="0 0 20 20" aria-hidden="true"><defs><mask id="ocr-stop-cut"><rect width="20" height="20" fill="#fff"/>'
    . '<path d="M3 2.5 L17.5 17" stroke="#000" stroke-width="4.4" stroke-linecap="round"/></mask></defs>'
    . '<g mask="url(#ocr-stop-cut)">' . $svgCamera . '</g>'
    . '<path d="M3 2.5 L17.5 17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
$svgFold = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 14 L10 5 L17 14 Z"/></svg>';
$svgTurn = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3 a7 7 0 1 1 -6.6 4.7" fill="none" stroke="currentColor" stroke-width="2.2"/><path d="M1 5 L6 3.5 L5 9 Z"/></svg>';
$svgMirror = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M9 3 L9 17 L2 17 Z"/><path d="M11 3 L11 17 L18 17 Z" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>';
$html .= '<div class="ocr-capture-bar">';
$html .= '<span class="ocr-bar-left"><span id="ocr-modes" class="ocr-modes" hidden>';
foreach (['auto' => 'ModeAuto', 'manual' => 'ModeManual', 'video' => 'ModeVideo'] as $mode => $key) {
    $html .= '<button type="button" class="ocr-btn ocr-mode" data-mode="' . $mode . '" title="' . ocr_t($key . 'Hint') . '">' . ocr_t($key) . '</button>';
}
$html .= '</span>';
$html .= '<label class="ocr-btn" id="ocr-file-label">' . ocr_t('ChoosePhoto')
    . '<input type="file" id="ocr-file" accept="image/*" capture="environment" hidden></label></span>';
$html .= '<span class="ocr-bar-mid">' . $picto('ocr-fold', 'ViewFold', $svgFold) . '</span>';
$html .= '<span class="ocr-bar-right" id="ocr-live-buttons" hidden>'
    . '<span id="ocr-rec" class="ocr-rec" hidden></span>'
    . $picto('ocr-shot', 'TakePhoto', $svgShot, ' hidden')
    . $picto('ocr-back', 'BackToLive', $svgLive, ' hidden')
    . $picto('ocr-cam-stop', 'CameraStop', $svgStop)
    . '</span>';
$html .= '</div>';
$html .= '<p id="ocr-cam-hint" class="ocr-hint" hidden>' . ocr_t('CameraInsecure') . '</p>';
$html .= '<div id="ocr-secure" class="ocr-msg ocr-msg-warn" hidden></div>';
$html .= '<div class="ocr-fold-body">';
// Over the view, in its top corners: turned a quarter (four times: as it was) and mirrored,
// for a camera mounted another way (what is shown only).
$html .= '<div id="ocr-drop" class="ocr-view">'
    . '<div id="ocr-live" class="ocr-live-wrap" hidden><video id="ocr-video" playsinline muted></video>'
    . '<canvas id="ocr-live-overlay" class="ocr-overlay"></canvas></div>'
    . '<canvas id="ocr-canvas" hidden></canvas>'
    . $picto('ocr-turn', 'ViewTurn', $svgTurn, ' data-corner="left"')
    . $picto('ocr-mirror', 'ViewMirror', $svgMirror, ' data-corner="right"')
    . '<p class="ocr-hint" id="ocr-drop-hint">' . ocr_t('DropHint') . '</p></div>';
$html .= '<div id="ocr-live-state" class="ocr-state" hidden></div>';
// What is drawn over the picture (the last three: what is being looked at).
$html .= '<details class="ocr-legend-box"><summary>' . ocr_t('LegendTitle') . '</summary><ul id="ocr-legend" class="ocr-legend">'
    . '<li><span class="ocr-key ocr-key-grid"></span>' . ocr_t('LegendGrid') . '</li>'
    . '<li><span class="ocr-key ocr-key-sure"></span>' . ocr_t('LegendSure') . '</li>'
    . '<li><span class="ocr-key ocr-key-empty"></span>' . ocr_t('LegendEmpty') . '</li>'
    . '<li><span class="ocr-key ocr-key-wrong"></span>' . ocr_t('LegendWrong') . '</li>'
    . '<li><span class="ocr-key ocr-key-blank"></span>' . ocr_t('LegendBlankTotal') . '</li>'
    . '<li><span class="ocr-key ocr-key-check"></span>' . ocr_t('LegendCheckTotal') . '</li>'
    . '<li><span class="ocr-key ocr-key-marks"></span>' . ocr_t('LegendMarks') . '</li>'
    . '<li class="ocr-legend-marks"><span class="ocr-key ocr-key-doubt"></span>' . ocr_t('LegendDoubt') . '</li>'
    . '<li class="ocr-legend-marks"><span class="ocr-key ocr-key-reading"></span>' . ocr_t('LegendReading') . '</li>'
    . '<li class="ocr-legend-marks"><span class="ocr-key ocr-key-header"></span>' . ocr_t('LegendHeader') . '</li>'
    . '</ul></details>';
$html .= '</div>';
// What concerns no card (the camera, a picture without any grid): the cards say the rest.
$html .= '<div id="ocr-status" class="ocr-status" role="status" aria-live="polite" hidden></div>';
$html .= '</section>';

// --- Archer, reading -------------------------------------------------------------
$html .= '<section class="ocr-card ocr-result">';
// The sheet's cards side by side, in the target's letter order; what their colours and signs tell.
$html .= '<div id="ocr-auto" class="ocr-auto" hidden>'
    . '<label class="ocr-option" title="' . ocr_t('AutoArcherHint') . '"><input type="checkbox" data-auto="archer"> ' . ocr_t('AutoArcher') . '</label>'
    . '<label class="ocr-option" title="' . ocr_t('AutoSheetHint') . '"><input type="checkbox" data-auto="sheet"> ' . ocr_t('AutoSheet') . '</label>'
    . '<label class="ocr-option ocr-size">' . ocr_t('CropSize') . ' <input type="range" id="ocr-crop-size" min="20" max="50" step="2" value="30"></label></div>';
$html .= '<div id="ocr-sheet" class="ocr-cards" hidden></div>';
$html .= '<details id="ocr-cards-legend" class="ocr-legend-box" hidden></details>';
$html .= '<div id="ocr-archer"></div>';
$html .= '<div id="ocr-cells"></div>';
$html .= '</section>';

$html .= '</div></div>';
echo $html;

include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php';
