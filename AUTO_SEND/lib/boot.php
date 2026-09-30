<?php
/**
 * Common bootstrap of every page and endpoint of the scheduled upload module.
 *
 * Finds the ianseo root by walking up to config.php, loads the module's
 * libraries, and creates the tables if they are missing — this is one of the
 * places allowed to do so; menu.php and the scheduled task are not.
 *
 * Access follows the core's upload page: a competition must be open and the
 * visitor needs the right to send to ianseo.net. Opening and closing the
 * scoring additionally needs the right the core's ISK-NG session page asks for.
 */

// Modules/ and Modules/Custom/ each hold a config.php that only relays to the
// real one, hence the Common/ folder that tells the ianseo root apart.
$_aus_root = dirname(__DIR__);
while ($_aus_root !== dirname($_aus_root)
       && !(is_file($_aus_root . '/config.php') && is_dir($_aus_root . '/Common'))) {
    $_aus_root = dirname($_aus_root);
}
if (!defined('HTDOCS')) define('HTDOCS', $_aus_root);
unset($_aus_root);

require_once HTDOCS . '/config.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/plan.php';
require_once __DIR__ . '/items.php';
require_once __DIR__ . '/sessions.php';
require_once __DIR__ . '/status.php';

aus_schema();

/**
 * Web URL of the module folder, with a trailing slash.
 *
 * @return string
 */
function aus_url() {
    global $CFG;
    return $CFG->ROOT_DIR . 'Modules/Custom/' . basename(dirname(__DIR__)) . '/';
}

/**
 * Cache-busting URL of a static asset of the module.
 *
 * @param string $rel Path relative to the module folder.
 * @return string
 */
function aus_asset($rel) {
    $t = @filemtime(dirname(__DIR__) . '/' . $rel);
    return aus_url() . $rel . ($t ? '?v=' . $t : '');
}

/**
 * Stop unless a competition is open and the visitor may send it to ianseo.net.
 *
 * The same checks as Tournament/UploadResults.php.
 */
function aus_require_access() {
    CheckTourSession(true);
    checkFullACL(AclInternetPublish, 'ipSend', AclReadWrite);
}

/**
 * The same test for an endpoint that answers in JSON.
 *
 * @return bool
 */
function aus_has_access() {
    return CheckTourSession() && hasFullACL(AclInternetPublish, 'ipSend', AclReadWrite);
}

/**
 * May the visitor open and close the ISK-NG scoring, as on Api/ISK-NG/Sessions.php?
 *
 * @return bool
 */
function aus_can_manage_sessions() {
    return hasFullACL(AclISKServer, 'iskManagement', AclReadWrite);
}

/**
 * Anti-CSRF token shared by the form and the endpoint of the module.
 *
 * @return string
 */
function aus_token() {
    if (empty($_SESSION['AUS_TOKEN'])) {
        $_SESSION['AUS_TOKEN'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['AUS_TOKEN'];
}

/**
 * Does the request carry the session's anti-CSRF token?
 *
 * @return bool
 */
function aus_token_ok() {
    $t = (string)($_POST['csrf'] ?? '');
    return $t !== '' && hash_equals(aus_token(), $t);
}

/**
 * The strings the browser side needs, published once per page as window.AUS_T.
 *
 * Only the keys starting with "Js": the page's own text is written in PHP.
 *
 * @return string A <script> element.
 */
function aus_js_strings() {
    $out = [];
    foreach (aus_lang_all() as $k => $v) {
        // bytes: comparing an ASCII key prefix.
        if (strpos($k, 'Js') === 0) $out[$k] = $v;
    }
    return '<script>window.AUS_T = '
        . json_encode($out, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . ';</script>';
}
