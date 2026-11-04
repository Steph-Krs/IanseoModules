<?php
/**
 * What the reading pages share (qualification.php, duels.php): their scripts, their root's
 * attributes (the networks, the workers, the endpoints), the development options, and the
 * capture and result sections. Each page adds what it reads and how it is set up.
 */

/**
 * The scripts of a reading page, its own (an adaptation of the reading: duel.js) before the
 * reading's.
 *
 * @param array $own Paths of the page's own scripts, relative to the module.
 * @return array For $JS_SCRIPT.
 */
function ocr_page_scripts($own = []) {
    $list = ['vision', 'barcode', 'layout', 'nn', 'recognizer', 'solver', 'pool', 'text', 'vendor/jsQR', 'qr', 'orb', 'follow', 'live', 'secure'];
    $out = [
        // A phone or a tablet lays the page out at its own width (else as a desktop page shrunk:
        // tiny buttons, a sheet hard to frame).
        '<meta name="viewport" content="width=device-width, initial-scale=1">',
        '<link rel="stylesheet" href="' . ocr_esc(ocr_asset('assets/ocr.css')) . '">',
        ocr_js_strings(),
    ];
    foreach ($list as $js) $out[] = '<script src="' . ocr_esc(ocr_asset('assets/js/' . $js . '.js')) . '"></script>';
    foreach ($own as $js) $out[] = '<script src="' . ocr_esc(ocr_asset($js)) . '"></script>';
    $out[] = '<script src="' . ocr_esc(ocr_asset('assets/js/qualification.js')) . '"></script>';
    return $out;
}

/**
 * The opening of a reading page's root, with what its script needs: the networks, the
 * workers, the token, the development endpoints when shown.
 *
 * @param array $attrs The page's own attributes (name => value, escaped here).
 * @return string HTML.
 */
function ocr_page_root($attrs) {
    $attrs += [
        'data-tour-code'    => (string)($_SESSION['TourCode'] ?? ''),
        'data-arrow-model'  => ocr_asset('assets/model/arrow.json'),
        'data-digit-model'  => ocr_asset('assets/model/digit.json'),
        'data-text-model'   => ocr_asset('assets/model/text.json'),
        'data-live-worker'  => ocr_worker('assets/js/live-worker.js'),
        'data-read-worker'  => ocr_worker('assets/js/read-worker.js'),
        'data-csrf'         => ocr_token(),
    ];
    // The trial's recording and the cells in doubt sent: development tools, when shown.
    if (ocr_dev_on()) {
        $attrs['data-trace-api'] = ocr_url() . 'api/trace.php';
        $attrs['data-labels-api'] = ocr_url() . 'api/labels.php';
    }
    if (is_file(dirname(__DIR__) . '/assets/model/number.json')) $attrs['data-number-model'] = ocr_asset('assets/model/number.json');
    $html = '<div id="ocr" class="ocr-page"';
    foreach ($attrs as $k => $v) $html .= ' ' . $k . '="' . ocr_esc($v) . '"';
    return $html . '>';
}

/** The development options, when the administrator has shown the tools (lib/dev.php). */
function ocr_page_options() {
    if (!ocr_dev_on()) return '';
    $html = '<details id="ocr-options" class="ocr-options"><summary>' . ocr_t('OptionsTitle') . '</summary>';
    foreach (['Marks' => 'marks', 'Record' => 'record', 'Doubts' => 'doubts'] as $key => $opt) {
        $html .= '<label class="ocr-option"><input type="checkbox" data-opt="' . $opt . '"> ' . ocr_t('Opt' . $key) . '</label>';
    }
    return $html . '</details>';
}

/**
 * The capture (the camera in one of three modes, or a picture imported; one window shows the
 * live view or the picture read, folded away once a picture is taken) and the cards read.
 *
 * @param array $modes The camera's modes the page offers (the duel page: no video, its cards read
 *                     from a picture).
 * @return string HTML, the page's main part (hidden until the page is set up).
 */
function ocr_page_body($modes = ['auto', 'manual', 'video']) {
    $html = '<div class="ocr-main" id="ocr-main" hidden>';
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
        if (!in_array($mode, $modes, true)) continue;
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

    // The sheet's cards side by side, in their order on the sheet; what their colours and signs tell.
    $html .= '<section class="ocr-card ocr-result">';
    $html .= '<div id="ocr-auto" class="ocr-auto" hidden>'
        . '<label class="ocr-option" title="' . ocr_t('AutoArcherHint') . '"><input type="checkbox" data-auto="archer"> ' . ocr_t('AutoArcher') . '</label>'
        . '<label class="ocr-option" title="' . ocr_t('AutoSheetHint') . '"><input type="checkbox" data-auto="sheet"> ' . ocr_t('AutoSheet') . '</label>'
        . '<label class="ocr-option ocr-size">' . ocr_t('CropSize') . ' <input type="range" id="ocr-crop-size" min="20" max="50" step="2" value="30"></label></div>';
    $html .= '<div id="ocr-sheet" class="ocr-cards" hidden></div>';
    $html .= '<details id="ocr-cards-legend" class="ocr-legend-box" hidden></details>';
    $html .= '<div id="ocr-archer"></div>';
    $html .= '<div id="ocr-cells"></div>';
    $html .= '</section>';
    return $html . '</div>';
}
