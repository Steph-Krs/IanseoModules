<?php
/**
 * AUTH module — update of the ianseo CORE, without a browser (command line only).
 *
 * Plays exactly what the /Update/ page does: it is only an interface that calls
 * `Update/index-action.php` in AJAX; the real work lives in `Update/UpdateIanseo.php`, which
 * carries NO access check (the ACL is in index-action.php). It can therefore be run here —
 * which lifts the blocker found on the server side: automating /Update/ over HTTP would mean
 * scripting an ADMIN sign-in + TOTP code, hence storing the 2FA secret in clear, which would
 * cancel the point of the 2FA. On the command line, the authorisation is that of the system
 * account, already at least as privileged as an administrator session.
 *
 * What the update does: downloads the differential from ianseo.net, writes / deletes the
 * core files, refreshes the language packs, then triggers the database migrations
 * (`updateChkUp()` → `Common/UpdateDb-check.php`).
 *
 * ⚠️ SEPARATE script, called as a SUB-PROCESS by cron/maintenance.php: UpdateIanseo.php ends
 * with a `JsonOut()` that does `exit` — included directly, it would kill the orchestrator
 * before the maintenance mode is left.
 *
 * The result is not given by the exit code (the core's `exit` is 0) but by the status file
 * TV/Photos/updating.json, which the caller reads back ("error" and "finished" keys).
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Cron script: command line only.');
}

$SKIP_AUTH = 1;
define('HTDOCS', dirname(__DIR__, 4));

// DIRNAME: what index-action.php defines as the folder of the calling script.
// UpdateIanseo.php derives the path of the status file from it (dirname(DIRNAME).'/TV/Photos').
define('DIRNAME', HTDOCS . DIRECTORY_SEPARATOR . 'Update');

require_once(HTDOCS . '/config.php');

// UpdateIanseo.php does RELATIVE includes ("FileList.php", "Language/lib.php") which, on the
// web, resolve from the script's folder. On the command line the script has to move there.
chdir(DIRNAME);

$statusFile = HTDOCS . '/TV/Photos/updating.json';

/** Same implementation as Update/index-action.php (atomic write). */
if (!function_exists('writeStatusFile')) {
    function writeStatusFile($file, $data) {
        file_put_contents($file . '.tmp', json_encode($data));
        rename($file . '.tmp', $file);
    }
}

function uc_out($msg) { echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n"; }

// An update already running? (same guard as the interface, except --force)
$force = in_array('--force', array_slice($argv, 1), true);
if (!$force && is_file($statusFile)) {
    $d = @json_decode((string) @file_get_contents($statusFile));
    if ($d && empty($d->finished)) {
        uc_out('ERROR: an update has been running since ' . ($d->start ?? '?')
            . ' (use --force to override).');
        exit(1);
    }
}

if (!is_writable(dirname($statusFile))) {
    uc_out('ERROR: ' . dirname($statusFile) . ' is not writable.');
    exit(1);
}
if (!is_writable(HTDOCS)) {
    uc_out('ERROR: ' . HTDOCS . ' is not writable — unlock the core files before the update '
        . '(ianseo-unlock).');
    exit(1);
}

// Initial state, exactly as the "getFile" action of the interface.
$JSON = array('error' => 0, 'msg' => '', 'start' => date('Y-m-d H:i:s'), 'status' => '', 'finished' => 0);
writeStatusFile($statusFile, $JSON);

uc_out('Update of the ianseo core (' . (defined('ProgramRelease') ? ProgramRelease : '?') . ') from ' . $CFG->IanseoServer . '…');

$IN_PHP = true;                       // required by UpdateIanseo.php
require_once DIRNAME . '/UpdateIanseo.php';   // ends with JsonOut() → exit
