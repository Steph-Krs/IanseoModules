<?php
/**
 * Cells to check: the readings the page could not make sure of, a few dozen at a time,
 * each with the value read already typed in. The person checking corrects those that
 * are wrong and validates the whole lot with Enter; what they typed is what the reading
 * networks learn from next.
 */

require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/labels.php';

ocr_require_access();
checkFullACL(AclQualification, '', AclReadWrite);
ocr_require_dev(ocr_text('VerifyTitle'));

$PAGE_TITLE = ocr_text('VerifyTitle');
$JS_SCRIPT  = [
    '<link rel="stylesheet" href="' . ocr_esc(ocr_asset('assets/ocr.css')) . '">',
    ocr_js_strings(),
    '<script src="' . ocr_esc(ocr_asset('assets/js/verify.js')) . '"></script>',
];
include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';

$html  = '<div id="ocr" class="ocr-page" data-api="' . ocr_esc(ocr_url() . 'api/labels.php') . '"'
    . ' data-csrf="' . ocr_esc(ocr_token()) . '">';
$html .= '<h1>' . ocr_t('VerifyTitle') . '</h1>';
$html .= '<p class="ocr-lead">' . ocr_t('VerifyLead') . '</p>';
$html .= '<p class="ocr-hint">' . ocr_t('VerifyKeys') . '</p>';
$html .= '<form id="ocr-verify" class="ocr-card" autocomplete="off">';
$html .= '<div class="ocr-counter" id="ocr-verify-count" role="status" aria-live="polite"></div>';
$html .= '<div id="ocr-verify-cells" class="ocr-verify-cells"></div>';
$html .= '<div class="ocr-buttons"><button type="submit" class="ocr-btn ocr-btn-primary" id="ocr-verify-ok">'
    . ocr_t('VerifySave') . '</button></div>';
$html .= '</form></div>';
echo $html;

include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php';
