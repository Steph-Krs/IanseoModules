<?php
/**
 * Command-line bootstrap shared by cron.php and worker.php.
 *
 * ianseo is written for web requests: config.php starts a PHP session and reads
 * a few server variables, and the ACL functions decide from the caller's IP.
 * This file gives a command-line run what those expect, then loads the module.
 *
 *   - REMOTE_ADDR is empty while config.php loads, so that its AutoCheckin
 *     redirection (keyed on the visitor's IP) can never fire here, then set to
 *     127.0.0.1: checkFullACL() grants read-write to the machine itself, which
 *     is what a scheduled task on the server is.
 *   - The PHP session gets a fresh random identifier and is destroyed when the
 *     script ends, including after the exit() of JsonOut() or safe_error():
 *     the core stores the ianseo.net credentials in the session during an
 *     upload, and a session file left behind would keep them on disk.
 *
 * Refuses to run under a web server: these scripts are not pages.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Modules/ and Modules/Custom/ each hold a config.php that only relays to the
// real one, hence the Common/ folder that tells the ianseo root apart.
$_aus_root = dirname(__DIR__);
while ($_aus_root !== dirname($_aus_root)
       && !(is_file($_aus_root . '/config.php') && is_dir($_aus_root . '/Common'))) {
    $_aus_root = dirname($_aus_root);
}
if (!defined('HTDOCS')) define('HTDOCS', $_aus_root);
unset($_aus_root);

$_SERVER['REMOTE_ADDR'] = '';
$_SERVER['SERVER_ADDR'] = '127.0.0.1';

session_id('aus' . bin2hex(random_bytes(12)));
register_shutdown_function(function () {
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
});

require_once HTDOCS . '/config.php';

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// config.php silences everything outside debug mode. Fatal errors must reach
// STDERR, where the scheduled task collects them; the core's many notices and
// warnings would only bury them.
ini_set('display_errors', 'stderr');
error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR);

require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/plan.php';
require_once __DIR__ . '/items.php';
require_once __DIR__ . '/sessions.php';
