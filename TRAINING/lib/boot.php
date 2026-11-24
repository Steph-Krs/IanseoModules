<?php
/**
 * Common bootstrap of every page and endpoint of the training module.
 *
 * Finds the ianseo root by walking up to config.php instead of counting
 * directory levels, loads the module's libraries, and creates the tables if
 * they are missing — this is one of the places allowed to do so, menu.php is
 * not. Access control is decided by each page: trn_require_admin() for the
 * reference formats and the regular archers, trn_require_tour() for the pages
 * that work on the open competition.
 */

$_trn_root = dirname(__DIR__);
while ($_trn_root !== dirname($_trn_root)
       && !(is_file($_trn_root . '/config.php') && is_dir($_trn_root . '/Common'))) {
    $_trn_root = dirname($_trn_root);
}
if (!defined('HTDOCS')) define('HTDOCS', $_trn_root);
unset($_trn_root);

require_once HTDOCS . '/config.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/template.php';
require_once __DIR__ . '/archers.php';
require_once __DIR__ . '/entries.php';
require_once __DIR__ . '/teams.php';
require_once __DIR__ . '/duels.php';
require_once __DIR__ . '/transfer.php';
require_once __DIR__ . '/ghosts.php';
require_once __DIR__ . '/results.php';

trn_schema();

/**
 * Web URL of the module folder, with a trailing slash.
 *
 * @return string
 */
function trn_url() {
    global $CFG;
    return $CFG->ROOT_DIR . 'Modules/Custom/' . basename(dirname(__DIR__)) . '/';
}

/**
 * Cache-busting URL of a static asset of the module.
 *
 * @param string $rel Path relative to the module folder.
 * @return string
 */
function trn_asset($rel) {
    $t = @filemtime(dirname(__DIR__) . '/' . $rel);
    return trn_url() . $rel . ($t ? '?v=' . $t : '');
}

/**
 * Stop unless the visitor may create competitions: the reference formats and
 * the regular archers belong to no competition, and creating a training is
 * creating a competition, so the right is the one ianseo asks for that.
 */
function trn_require_admin() {
    if (function_exists('possibleFeature') && possibleFeature(AclRoot, AclReadWrite)) return;
    checkFullACL(AclRoot, '', AclReadWrite);
}

/**
 * Stop unless a competition is open and the visitor may enter its scores.
 *
 * @return int The open competition.
 */
function trn_require_tour() {
    CheckTourSession(true);
    checkFullACL(AclQualification, '', AclReadWrite);
    return intval($_SESSION['TourId']);
}

/**
 * Anti-CSRF token shared by the forms of the module.
 *
 * @return string
 */
function trn_token() {
    if (empty($_SESSION['TRN_TOKEN'])) {
        $_SESSION['TRN_TOKEN'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['TRN_TOKEN'];
}

/**
 * Does the request carry the session's anti-CSRF token?
 *
 * @return bool
 */
function trn_token_ok() {
    $t = (string)($_POST['csrf'] ?? '');
    return $t !== '' && hash_equals(trn_token(), $t);
}

/**
 * Hidden field carrying the anti-CSRF token.
 *
 * @return string
 */
function trn_token_field() {
    return '<input type="hidden" name="csrf" value="' . trn_esc(trn_token()) . '">';
}

/**
 * Redirect to a page of the module and stop.
 *
 * @param string $page Page and query, relative to the module folder.
 */
function trn_redirect($page) {
    header('Location: ' . trn_url() . $page);
    exit;
}

/**
 * Keep a message for the next page.
 *
 * @param string $text Already translated text.
 * @param string $kind 'ok' or 'err'.
 */
function trn_flash($text, $kind = 'ok') {
    $_SESSION['TRN_FLASH'][] = [$kind, $text];
}

/**
 * The messages kept for this page, as HTML, and forget them.
 *
 * @return string
 */
function trn_flash_html() {
    $out = '';
    foreach ($_SESSION['TRN_FLASH'] ?? [] as $m) {
        $out .= '<div class="trn-msg trn-' . ($m[0] === 'err' ? 'err' : 'ok') . '">' . trn_esc($m[1]) . '</div>';
    }
    unset($_SESSION['TRN_FLASH']);
    return $out;
}

/**
 * Open a page of the module: core header, stylesheet, title.
 *
 * @param string $title Translated title.
 * @param array $scripts Extra module scripts, relative to the module folder.
 */
function trn_page_open($title, $scripts = []) {
    global $CFG, $PAGE_TITLE, $JS_SCRIPT, $IncludeJquery;
    $PAGE_TITLE = $title;
    $JS_SCRIPT = ['<link rel="stylesheet" href="' . trn_esc(trn_asset('assets/training.css')) . '">'];
    $JS_SCRIPT[] = '<script>window.TRN_T = '
        . json_encode(trn_lang_all(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . ';</script>';
    foreach ($scripts as $s) {
        $JS_SCRIPT[] = '<script src="' . trn_esc(trn_asset($s)) . '"></script>';
    }
    $IncludeJquery = true;
    include $CFG->DOCUMENT_PATH . 'Common/Templates/head.php';
    echo '<div id="trn">';
    echo '<h1>' . trn_esc($title) . '</h1>';
    echo trn_flash_html();
}

/**
 * Close a page of the module.
 */
function trn_page_close() {
    global $CFG;
    echo '</div>';
    include $CFG->DOCUMENT_PATH . 'Common/Templates/tail.php';
}

/**
 * Date of the database in the format of the user's language.
 *
 * @param string $date Y-m-d.
 * @return string
 */
function trn_date($date) {
    $ts = strtotime((string)$date);
    if (!$ts || (string)$date === '0000-00-00') return '';
    return date(trn_text('DateFmt'), $ts);
}
