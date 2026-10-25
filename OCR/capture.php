<?php
/**
 * Series capture of scorecards: the camera stays on, and every scorecard held in
 * front of it is photographed as soon as its grid is found and stays still; the next
 * one can follow at once. The photos are kept on the server for the open competition,
 * to be read later or downloaded as one archive.
 */

require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/capture.php';

ocr_require_access();
checkFullACL(AclQualification, '', AclReadWrite);
ocr_require_dev(ocr_text('CaptureTitle'));
ocr_capture_purge();

$count = count(ocr_capture_list());

$PAGE_TITLE = ocr_text('CaptureTitle');
$JS_SCRIPT  = [
    '<link rel="stylesheet" href="' . ocr_esc(ocr_asset('assets/ocr.css')) . '">',
    ocr_js_strings(),
    '<script src="' . ocr_esc(ocr_asset('assets/js/vision.js')) . '"></script>',
    '<script src="' . ocr_esc(ocr_asset('assets/js/secure.js')) . '"></script>',
    '<script src="' . ocr_esc(ocr_asset('assets/js/capture.js')) . '"></script>',
];
include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';

$html  = '<div id="ocr" class="ocr-page" data-api="' . ocr_esc(ocr_url() . 'api/capture.php') . '"'
    . ' data-worker="' . ocr_esc(ocr_worker('assets/js/barcode-worker.js')) . '"'
    . ' data-csrf="' . ocr_esc(ocr_token()) . '">';
$html .= '<h1>' . ocr_t('CaptureTitle') . '</h1>';
$html .= '<p class="ocr-lead">' . ocr_t('CaptureLead', OCR_CAPTURE_DAYS) . '</p>';
$html .= '<div id="ocr-secure" class="ocr-msg ocr-msg-warn" hidden></div>';

$html .= '<div class="ocr-main">';
$html .= '<section class="ocr-card ocr-capture">';
$html .= '<div class="ocr-buttons">'
    . '<button type="button" id="ocr-start" class="ocr-btn ocr-btn-primary">' . ocr_t('CaptureStart') . '</button>'
    . '<button type="button" id="ocr-shot-now" class="ocr-btn" hidden>' . ocr_t('CaptureNow') . '</button>'
    . '<button type="button" id="ocr-stop" class="ocr-btn" hidden>' . ocr_t('CameraStop') . '</button>'
    . '<label class="ocr-check"><input type="checkbox" id="ocr-sound" checked> ' . ocr_t('CaptureSound') . '</label>'
    . '</div>';
// What works everywhere, plain http included: the phone's own camera, one card at a
// time, and photos already taken, many at once.
$html .= '<div class="ocr-buttons">'
    . '<label class="ocr-btn" id="ocr-photo-label">' . ocr_t('CapturePhoto')
    . '<input type="file" id="ocr-photo" accept="image/*" capture="environment" hidden></label>'
    . '<label class="ocr-btn" id="ocr-files-label">' . ocr_t('CaptureFiles')
    . '<input type="file" id="ocr-files" accept="image/*" multiple hidden></label>'
    . '</div>';
$html .= '<div class="ocr-live-wrap"><video id="ocr-video" playsinline muted hidden></video>'
    . '<canvas id="ocr-overlay" class="ocr-overlay" hidden></canvas></div>';
$html .= '<div id="ocr-state" class="ocr-state" role="status" aria-live="polite"></div>';
$html .= '</section>';

$html .= '<section class="ocr-card ocr-result">';
$html .= '<div class="ocr-counter"><span id="ocr-count">' . $count . '</span> ' . ocr_t('CaptureStored') . '</div>';
$html .= '<div id="ocr-thumbs" class="ocr-thumbs"></div>';
$html .= '<div class="ocr-buttons">'
    . '<a class="ocr-btn" id="ocr-zip" href="' . ocr_esc(ocr_url() . 'api/capture.php?action=zip') . '">' . ocr_t('CaptureZip') . '</a>'
    . '<button type="button" class="ocr-btn" id="ocr-clear">' . ocr_t('CaptureClear') . '</button>'
    . '</div>';
$html .= '<p class="ocr-hint">' . ocr_t('CaptureHint') . '</p>';
$html .= '</section>';
$html .= '</div></div>';
echo $html;

include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php';
