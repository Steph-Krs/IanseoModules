<?php
/**
 * Common bootstrap of every page and endpoint of the live draw module.
 *
 * Finds the ianseo root by walking up to config.php instead of counting
 * directory levels, loads the module's libraries, and creates the tables if
 * they are missing — this is one of the places allowed to do so, menu.php is
 * not. Access control is NOT decided here: the preparation and control pages
 * call tir_require_admin(), while the screens are opened by a secret link and
 * need no ianseo session at all.
 */

$_tir_root = dirname(__DIR__);
while ($_tir_root !== dirname($_tir_root) && !is_file($_tir_root . '/config.php')) {
    $_tir_root = dirname($_tir_root);
}
if (!defined('HTDOCS')) define('HTDOCS', $_tir_root);
unset($_tir_root);

require_once HTDOCS . '/config.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/store.php';

tir_schema();

/**
 * Web URL of the module folder, with a trailing slash.
 *
 * @return string
 */
function tir_url() {
    global $CFG;
    return $CFG->ROOT_DIR . 'Modules/Custom/' . basename(dirname(__DIR__)) . '/';
}

/**
 * Cache-busting suffix for a static asset of the module.
 *
 * @param string $rel Path relative to the module folder.
 * @return string Such as "?v=1716903254".
 */
function tir_asset($rel) {
    $t = @filemtime(dirname(__DIR__) . '/' . $rel);
    return tir_url() . $rel . ($t ? '?v=' . $t : '');
}

/**
 * Stop unless the visitor may prepare and run draws.
 *
 * A draw belongs to no competition, so the right that fits is the one ianseo
 * asks for to create a competition: AclRoot, read-write.
 */
function tir_require_admin() {
    checkFullACL(AclRoot, '', AclReadWrite);
}

/**
 * Anti-CSRF token shared by the forms and the write endpoints of the module.
 *
 * @return string
 */
function tir_token() {
    if (empty($_SESSION['TIR_TOKEN'])) {
        $_SESSION['TIR_TOKEN'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['TIR_TOKEN'];
}

/**
 * Does the request carry the session's anti-CSRF token?
 *
 * @return bool
 */
function tir_token_ok() {
    $t = (string)($_POST['csrf'] ?? $_GET['csrf'] ?? '');
    return $t !== '' && hash_equals(tir_token(), $t);
}

/**
 * The strings the browser side needs, published once per page as window.TIR_T.
 *
 * The whole table is small, so it is published entire rather than through a
 * second list of keys that would have to be kept in step with the scripts.
 *
 * @return string A <script> element.
 */
function tir_js_strings() {
    return '<script>window.TIR_T = '
        . json_encode(tir_lang_all(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . ';</script>';
}
