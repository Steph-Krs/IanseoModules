<?php
/**
 * AUTH module — backup of the ianseo database and files (CLI only).
 *
 * Normally run by cron/maintenance.php: "--local" inside the maintenance window, right
 * before the core update, then "--upload" once the site has reopened. Can also be run
 * by hand (both phases in a row):
 *   sudo -u www-data php /var/www/ianseo/Modules/Custom/AUTH/cron/backup.php
 *
 * Exit code (read by maintenance.php):
 *   0 = done (off-site copy done, or not configured)
 *   1 = local backup FAILED → the core update must not run
 *       (--upload: no local backup to send)
 *   2 = local backup done, off-site copy failed
 *   3 = backup disabled in the config
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Script cron : exécution en ligne de commande uniquement.');
}

$SKIP_AUTH = 1;
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once(dirname(__DIR__) . '/backup-lib.php');

@set_time_limit(0);

$say = function ($msg) { echo '[' . aut_log_time() . '] ' . $msg . "\n"; };

$args = array_slice($argv, 1);
$mode = in_array('--local', $args, true) ? 'local' : (in_array('--upload', $args, true) ? 'upload' : 'all');

if (!aut_backup_config()['enabled']) {
    $say('Sauvegarde désactivée (config.local.json → backup.enabled).');
    exit(3);
}

$r = aut_backup_run($say, $mode);

if (!$r['local_ok']) {
    if ($mode !== 'upload') aut_log('BACKUP_FAIL', 'cron', 'cli');
    exit(1);
}
if ($r['remote'] === 'fail') {
    aut_log('BACKUP_REMOTE_FAIL', 'cron', 'cli');
    exit(2);
}
if ($mode !== 'upload') aut_log('BACKUP_OK', 'cron', 'cli');
elseif ($r['remote'] === 'ok') aut_log('BACKUP_REMOTE_OK', 'cron', 'cli');
exit(0);
