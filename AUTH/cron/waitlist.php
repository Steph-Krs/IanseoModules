<?php
/**
 * AUTH module (booking) — waiting lists (CLI only).
 *
 * Registers the first archer of each waiting list for every place freed. The booking
 * pages already do it when a place frees through them (online cancellation) or when they
 * are opened; this catches places freed in ianseo's own screens (a participant deleted,
 * targets added) while nobody opens a booking page. Every 10 minutes:
 *   serveur/cron/ianseo-waitlist → /etc/cron.d/ianseo-waitlist
 * Silent unless an archer was registered (one line in the log, and WAIT_PROMOTE in the
 * booking journal).
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Cron script: command line only.');
}

$SKIP_AUTH = 1;
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
aut_table_names();   // module updated since the last page opened: see names-lib.php
require_once(dirname(__DIR__) . '/backup-lib.php');
// Never during the nightly window (core files and tables being updated) or a restore.
if (aut_backup_night_running()) exit(0);
$_SESSION = array();   // registration swaps the competition session (bk_with_tournament)
require_once(dirname(__DIR__) . '/booking/lib/waitlist.php');

@set_time_limit(300);
$n = bk_waitlist_sweep();
if ($n > 0) echo '[' . aut_log_time() . '] Waiting lists: ' . $n . ' archer(s) registered.' . "\n";
exit(0);
