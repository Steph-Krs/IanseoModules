<?php
/**
 * The module's development tools, hidden unless the administrator turns them on
 * ("Become a developer", on the module's update page): the series capture, the cells
 * to check, and the reading page's trial options (record the trial, send the cells in
 * doubt). One setting for the whole installation, a row of the core's Parameters.
 *
 * Read by menu.php, on every ianseo page: a read of a core table, never a write
 * (GetParameter with an empty default writes nothing, and does not stop on an error).
 */

define('OCR_DEV_PARAM', 'OcrDevTools');

/**
 * Whether the development tools are shown on this installation.
 *
 * @return bool
 */
function ocr_dev_on() {
    return GetParameter(OCR_DEV_PARAM) === '1';
}

/**
 * The development tools shown or hidden (the update page, for the administrator).
 *
 * @param bool $on
 */
function ocr_dev_set($on) {
    if ($on) {
        SetParameter(OCR_DEV_PARAM, '1');
    } else {
        DelParameter(OCR_DEV_PARAM);
    }
}

/**
 * A development page opened while the tools are hidden (an old link, a bookmark): what to
 * do instead, and the end of the page.
 *
 * @param string $title The page's title.
 */
function ocr_require_dev($title) {
    global $CFG;
    if (ocr_dev_on()) return;
    $PAGE_TITLE = $title;
    include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';
    echo '<div id="ocr" class="ocr-page"><h1>' . ocr_esc($title) . '</h1><p>' . ocr_t('DevOff') . '</p></div>';
    include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php';
    exit;
}
