<?php
/**
 * Update and uninstall page of the scorecard reader, and the switch of its development
 * tools ("Become a developer", lib/dev.php).
 *
 * Checking the repository, updating the module and the shared library, installing
 * sibling modules, uninstalling: the bricks of _shared/update-ui.php, so every module
 * behaves and looks the same; the page put together here for its own section (as
 * GUIDE's is).
 *
 * Deliberately NOT booted through lib/boot.php: this page must stay reachable
 * when the module itself is broken, since updating is how it gets repaired.
 */

// Modules/ and Modules/Custom/ each hold a config.php that only relays to the
// real one, hence the Common/ folder that tells the ianseo root apart.
$_ocr_root = dirname(__DIR__);
while ($_ocr_root !== dirname($_ocr_root)
       && !(is_file($_ocr_root . '/config.php') && is_dir($_ocr_root . '/Common'))) {
    $_ocr_root = dirname($_ocr_root);
}
define('HTDOCS', $_ocr_root);
unset($_ocr_root);

require_once HTDOCS . '/config.php';
require_once dirname(__DIR__) . '/lib/lang.php';
require_once dirname(__DIR__, 2) . '/_shared/update-ui.php';
// Guarded as the rest of the page: an update half done must not take the repair away.
if (is_file(dirname(__DIR__) . '/lib/dev.php')) require_once dirname(__DIR__) . '/lib/dev.php';

// AclRoot alone is not enough with an account module: upd_admin_guard() also
// requires the server administrator view.
upd_admin_guard();

$moduleDir = dirname(__DIR__);
$cfg = upd_load_config($moduleDir);
$messages = [];
upd_install_handle($cfg, $messages);

$devAction = (string)($_POST['action'] ?? '');
if (function_exists('ocr_dev_set') && ($devAction === 'ocr-dev-on' || $devAction === 'ocr-dev-off')) {
    ocr_dev_set($devAction === 'ocr-dev-on');
    $messages[] = ['ok', upd_esc(ocr_text($devAction === 'ocr-dev-on' ? 'DevMsgOn' : 'DevMsgOff'))];
}

$mod = upd_module_handle($cfg, $moduleDir, $messages);
// Read after the handler: an update replaces version.json.
$localVer = upd_local_version($moduleDir);
$othersState = upd_others_state($cfg);

$PAGE_TITLE = ocr_text('UpdateTitle');
include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';

$page = upd_ui_styles()
    . '<h1>' . upd_esc(ocr_text('UpdateTitle')) . '</h1>'
    . '<p><a href="' . upd_esc($CFG->ROOT_DIR . 'Modules/Custom/' . basename($moduleDir) . '/qualification.php') . '">← '
    . upd_esc(ocr_text('BackToPage')) . '</a></p>'
    . upd_ui_messages($messages)
    . upd_ui_source($cfg, $localVer, $localVer['version'] ?? null)
    . upd_ui_module_block($cfg, $mod['checkResult'], $mod['sharedCheck']);

// "Become a developer": the series capture, the cells to check and the reading page's trial
// options, shown for the whole installation.
if (function_exists('ocr_dev_on')) {
    $on = ocr_dev_on();
    $page .= '<div class="upd-section"><h2>' . upd_esc(ocr_text('DevHeading')) . '</h2>'
        . '<p style="font-size:13px;margin:0 0 10px">'
        . ($on ? '<span class="upd-badge upd-ok">&#10003; ' . upd_esc(ocr_text('DevStateOn')) . '</span>'
               : '<span class="upd-badge upd-update">' . upd_esc(ocr_text('DevStateOff')) . '</span>')
        . '</p><form method="post" style="display:inline">'
        . '<input type="hidden" name="action" value="' . ($on ? 'ocr-dev-off' : 'ocr-dev-on') . '">'
        . '<button type="submit" class="upd-btn upd-btn-check">' . upd_esc(ocr_text($on ? 'DevTurnOff' : 'DevTurnOn')) . '</button></form>'
        . '<p class="upd-hint">' . upd_esc(ocr_text('DevHint')) . '</p></div>';
}

$page .= upd_ui_others_block($cfg, $othersState, basename($moduleDir))
    . upd_ui_danger_zone($moduleDir);
echo $page;

include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php';
