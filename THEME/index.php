<?php
/**
 * Page of the theme: the mode, the palettes with a preview of each, and, for the
 * administrator, the switch that brings the theme to the pages without a menu.
 *
 * Open to every visitor, like the menu entries: the choice belongs to their
 * browser. The only write this page can make is the administrator's switch,
 * which edits Common/DebugOverrides.php (see lib/overrides.php).
 */

// Modules/ and Modules/Custom/ each hold a config.php that only relays to the
// real one, hence the Common/ folder that tells the ianseo root apart.
$_thm_root = __DIR__;
while ($_thm_root !== dirname($_thm_root)
       && !(is_file($_thm_root . '/config.php') && is_dir($_thm_root . '/Common'))) {
    $_thm_root = dirname($_thm_root);
}
define('HTDOCS', $_thm_root);
unset($_thm_root);

require_once HTDOCS . '/config.php';
require_once __DIR__ . '/lib/lang.php';
require_once __DIR__ . '/lib/theme.php';
require_once __DIR__ . '/lib/overrides.php';

$sharedLib = dirname(__DIR__) . '/_shared/update-lib.php';
if (is_file($sharedLib)) require_once $sharedLib;
$isAdmin = function_exists('upd_is_admin') && upd_is_admin();

if (empty($_SESSION['THM_TOKEN'])) $_SESSION['THM_TOKEN'] = bin2hex(random_bytes(16));

$message = '';
if ($isAdmin && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && hash_equals($_SESSION['THM_TOKEN'], (string)($_POST['csrf'] ?? ''))) {
    $action = (string)($_POST['hook'] ?? '');
    if ($action === 'on' || $action === 'off') {
        $ok = $action === 'on' ? thm_ovr_enable() : thm_ovr_disable();
        $message = '<p class="thm-msg ' . ($ok ? 'thm-ok' : 'thm-ko') . '">' . thm_t($ok ? 'HookDone' : 'HookFailed') . '</p>';
    }
}

/**
 * A small drawing of an ianseo table in a palette's colours.
 *
 * @param array $vars The palette's variables for one mode.
 * @param string $label Mode name, already escaped.
 * @param bool $dark
 * @return string
 */
function thm_preview($vars, $label, $dark) {
    $style = '--p-page:' . $vars['header-light'] . ';--p-bar:' . $vars['header-dark']
        . ';--p-bar-text:' . $vars['row-main-text'] . ';--p-th:' . $vars['bg-cell-header']
        . ';--p-row:' . $vars['row-background'] . ';--p-grid:' . $vars['table-background']
        . ';--p-text:' . ($dark ? $vars['thm-text'] : $vars['header-dark']);
    return '<span class="thm-preview" style="' . thm_esc($style) . '">'
        . '<span class="thm-p-bar">' . $label . '</span>'
        . '<span class="thm-p-th"></span>'
        . '<span class="thm-p-row"><i></i><i></i></span>'
        . '<span class="thm-p-row"><i></i><i></i></span>'
        . '</span>';
}

$choice = thm_choice();
$setUrl = thm_url() . 'set.php';

$PAGE_TITLE = thm_text('ModuleName');
$JS_SCRIPT = ['<link href="' . thm_esc(thm_asset('assets/page.css')) . '" rel="stylesheet" type="text/css">'];
include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';

$html = '<div id="thm">';
$html .= '<h1>' . thm_t('ModuleName') . '</h1>';
$html .= $message;
$html .= '<p class="thm-lead">' . thm_t('Lead') . '</p>';
if (!empty($_SESSION['debug'])) $html .= '<p class="thm-note">' . thm_t('DebugNote') . '</p>';

$html .= '<h2>' . thm_t('ModeTitle') . '</h2>';
$html .= '<div class="thm-modes">';
foreach (thm_modes() as $mode) {
    $current = $choice['mode'] === $mode;
    $html .= '<a class="thm-mode' . ($current ? ' thm-current' : '') . '"'
        . ($current ? ' aria-current="true"' : '')
        . ' href="' . thm_esc($setUrl . '?mode=' . $mode) . '">' . thm_t('Mode_' . $mode) . '</a>';
}
$html .= '</div>';
$html .= '<p class="thm-hint">' . thm_t('ModeHint') . '</p>';

$html .= '<h2>' . thm_t('PaletteTitle') . '</h2>';
$html .= '<p class="thm-hint">' . thm_t('PaletteHint') . '</p>';
$html .= '<div class="thm-palettes">';
$palettes = thm_palettes();
foreach (thm_palette_ids() as $id) {
    $current = $choice['palette'] === $id;
    $html .= '<a class="thm-card' . ($current ? ' thm-current' : '') . '"'
        . ($current ? ' aria-current="true"' : '')
        . ' href="' . thm_esc($setUrl . '?palette=' . $id) . '">'
        . '<span class="thm-card-name">' . thm_t('Palette_' . $id) . '</span>'
        . '<span class="thm-previews">'
        . thm_preview($palettes[$id]['light'], thm_t('Mode_light'), false)
        . thm_preview($palettes[$id]['dark'], thm_t('Mode_dark'), true)
        . '</span></a>';
}
$html .= '</div>';
$html .= '<p class="thm-hint">' . thm_t('StoredNote') . '</p>';

if ($isAdmin) {
    $state = thm_ovr_state();
    $html .= '<h2>' . thm_t('HookTitle') . '</h2>';
    $html .= '<p>' . thm_t('HookText') . '</p>';
    $html .= '<p><b>' . thm_t($state['on'] ? 'HookOn' : 'HookOff') . '</b></p>';
    if ($state['writable']) {
        $html .= '<form method="post" action="">'
            . '<input type="hidden" name="csrf" value="' . thm_esc($_SESSION['THM_TOKEN']) . '">'
            . '<input type="hidden" name="hook" value="' . ($state['on'] ? 'off' : 'on') . '">'
            . '<input type="submit" value="' . thm_t($state['on'] ? 'HookDisable' : 'HookEnable') . '">'
            . '</form>';
    } elseif (!$state['on']) {
        $html .= '<p>' . thm_t('HookNotWritable', 'Common/DebugOverrides.php') . '</p>';
        $html .= '<pre class="thm-code">' . thm_esc(thm_ovr_block()) . '</pre>';
    } else {
        $html .= '<p>' . thm_t('HookNotWritableOff', 'Common/DebugOverrides.php') . '</p>';
    }
}

$html .= '</div>';
echo $html;

include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php';
