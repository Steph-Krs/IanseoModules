<?php
/**
 * Common bootstrap of every page and endpoint of the scorecard reader.
 *
 * Finds the ianseo root by walking up to config.php instead of counting
 * directory levels, then loads the core's libraries the module relies on and its
 * own. The module owns no table, so there is no schema to create here.
 *
 * Access follows the core's own scorecard check (Modules/Barcodes): a competition
 * must be open, and the visitor needs the qualification round. Reading and
 * comparing need read access; writing arrows needs read-write access.
 */

// Modules/ and Modules/Custom/ each hold a config.php that only relays to the
// real one, hence the Common/ folder that tells the ianseo root apart.
$_ocr_root = dirname(__DIR__);
while ($_ocr_root !== dirname($_ocr_root)
       && !(is_file($_ocr_root . '/config.php') && is_dir($_ocr_root . '/Common'))) {
    $_ocr_root = dirname($_ocr_root);
}
if (!defined('HTDOCS')) define('HTDOCS', $_ocr_root);
unset($_ocr_root);

require_once HTDOCS . '/config.php';
require_once 'Common/Lib/ArrTargets.inc.php';
require_once 'Common/Fun_Sessions.inc.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/qualif.php';
require_once __DIR__ . '/dev.php';

/**
 * Web URL of the module folder, with a trailing slash.
 *
 * @return string
 */
function ocr_url() {
    global $CFG;
    return $CFG->ROOT_DIR . 'Modules/Custom/' . basename(dirname(__DIR__)) . '/';
}

/**
 * Cache-busting URL of a static asset of the module.
 *
 * @param string $rel Path relative to the module folder.
 * @return string Such as ".../assets/js/vision.js?v=1716903254".
 */
function ocr_asset($rel) {
    $t = @filemtime(dirname(__DIR__) . '/' . $rel);
    return ocr_url() . $rel . ($t ? '?v=' . $t : '');
}

/**
 * A worker's script, its version the newest of the module's scripts: a worker loads its
 * own (importScripts) with that version, or the browser may keep serving an older copy of
 * them after an update.
 */
function ocr_worker($rel) {
    $t = 0;
    foreach (glob(dirname(__DIR__) . '/assets/js/*.js') ?: [] as $f) $t = max($t, (int)@filemtime($f));
    return ocr_url() . $rel . ($t ? '?v=' . $t : '');
}

/**
 * Stop unless a competition is open and the visitor may see its qualification round.
 */
function ocr_require_access() {
    CheckTourSession(true);
    checkFullACL(AclQualification, '', AclReadOnly);
}

/**
 * The same test for an endpoint that answers in JSON, where an HTML error page
 * would only be a parse error for the script that called it.
 *
 * @param int $level AclReadOnly or AclReadWrite.
 * @return bool
 */
function ocr_has_access($level = AclReadOnly) {
    return CheckTourSession() && hasFullACL(AclQualification, '', $level);
}

/**
 * Anti-CSRF token of the endpoints that write.
 *
 * @return string
 */
function ocr_token() {
    if (empty($_SESSION['OCR_TOKEN'])) {
        $_SESSION['OCR_TOKEN'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['OCR_TOKEN'];
}

/**
 * Does the request carry the session's anti-CSRF token?
 *
 * @return bool
 */
function ocr_token_ok() {
    $t = (string)($_POST['csrf'] ?? '');
    return $t !== '' && hash_equals(ocr_token(), $t);
}

/**
 * The strings the browser side needs, published once per page as window.OCR_T.
 *
 * The whole table is small, so it is published entire rather than through a
 * second list of keys that would have to be kept in step with the script.
 *
 * @return string A <script> element.
 */
function ocr_js_strings() {
    return '<script>window.OCR_T = '
        . json_encode(ocr_lang_all(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . ';</script>';
}
