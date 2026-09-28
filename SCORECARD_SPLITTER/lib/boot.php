<?php
/**
 * Common bootstrap of every page and endpoint of the scorecard splitter.
 *
 * Finds the ianseo root by walking up to config.php instead of counting
 * directory levels, then loads the core's scorecard drawing class and the
 * module's libraries. The module owns no table and writes nothing to the
 * database, so there is no schema to create here.
 *
 * Access follows the core's own scorecard printout: a competition must be open,
 * and the visitor needs read access to the qualification round.
 */

$_scs_root = dirname(__DIR__);
while ($_scs_root !== dirname($_scs_root) && !is_file($_scs_root . '/config.php')) {
    $_scs_root = dirname($_scs_root);
}
if (!defined('HTDOCS')) define('HTDOCS', $_scs_root);
unset($_scs_root);

require_once HTDOCS . '/config.php';
require_once 'Common/pdf/ScorePDF.inc.php';
require_once 'Common/Fun_FormatText.inc.php';
require_once 'Common/Fun_Sessions.inc.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/cards.php';
require_once __DIR__ . '/render.php';
require_once __DIR__ . '/jobs.php';

/**
 * Web URL of the module folder, with a trailing slash.
 *
 * @return string
 */
function scs_url() {
    global $CFG;
    return $CFG->ROOT_DIR . 'Modules/Custom/' . basename(dirname(__DIR__)) . '/';
}

/**
 * Cache-busting URL of a static asset of the module.
 *
 * @param string $rel Path relative to the module folder.
 * @return string Such as ".../assets/splitter.js?v=1716903254".
 */
function scs_asset($rel) {
    $t = @filemtime(dirname(__DIR__) . '/' . $rel);
    return scs_url() . $rel . ($t ? '?v=' . $t : '');
}

/**
 * Stop unless a competition is open and the visitor may print its scorecards.
 *
 * The same checks as Qualification/PrintScore.php, so that whoever can print the
 * core's scorecards can use the module, and nobody else.
 */
function scs_require_access() {
    CheckTourSession(true);
    checkFullACL(AclQualification, '', AclReadOnly);
}

/**
 * The same test for an endpoint that answers in JSON, where an HTML error page
 * would only be a parse error for the script that called it.
 *
 * @return bool
 */
function scs_has_access() {
    return CheckTourSession() && hasFullACL(AclQualification, '', AclReadOnly);
}

/**
 * Anti-CSRF token of the archive endpoint.
 *
 * @return string
 */
function scs_token() {
    if (empty($_SESSION['SCS_TOKEN'])) {
        $_SESSION['SCS_TOKEN'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['SCS_TOKEN'];
}

/**
 * Does the request carry the session's anti-CSRF token?
 *
 * @return bool
 */
function scs_token_ok() {
    $t = (string)($_POST['csrf'] ?? '');
    return $t !== '' && hash_equals(scs_token(), $t);
}

/**
 * The strings the browser side needs, published once per page as window.SCS_T.
 *
 * The whole table is small, so it is published entire rather than through a
 * second list of keys that would have to be kept in step with the script.
 *
 * @return string A <script> element.
 */
function scs_js_strings() {
    return '<script>window.SCS_T = '
        . json_encode(scs_lang_all(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . ';</script>';
}
