<?php
/**
 * Deployed from Modules/Custom/AUTH/dist/ — compatibility ALIAS.
 *
 * Signing in is now UNIFIED: Modules/Custom/AUTH/login.php (Organiser tab = officers' space,
 * Competitor tab = licensee space). The organiser flow itself lives in lib.php
 * (aut_handle_org_login/…), reused by the unified page. This file only redirects, for the
 * old links and the redirections of the ianseo core.
 */
if (basename(__DIR__) !== 'Authentication') {
    http_response_code(403);
    die('This file must run from Modules/Authentication/ (see admin/deploy.php).');
}
define('HTDOCS', dirname(__DIR__, 2));
require_once(HTDOCS . '/config.php');

header('Location: ' . $CFG->ROOT_DIR . 'Modules/Custom/AUTH/login.php?p=org');
exit;
