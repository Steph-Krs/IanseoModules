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
    die('Script cron : exécution en ligne de commande uniquement.');
}

$SKIP_AUTH = 1;
define('HTDOCS', dirname(__DIR__, 4));
require_once(HTDOCS . '/config.php');
require_once(dirname(__DIR__) . '/lib.php');
require_once(dirname(__DIR__) . '/backup-lib.php');
// Never during the nightly window (core files and tables being updated) or a restore.
if (aut_backup_night_running()) exit(0);
$_SESSION = array();   // registration swaps the competition session (bk_with_tournament)
require_once(dirname(__DIR__) . '/booking/lib/waitlist.php');

@set_time_limit(300);
$n = bk_waitlist_sweep();
if ($n > 0) echo '[' . aut_log_time() . '] Listes d\'attente : ' . $n . ' archer(s) inscrit(s).' . "\n";
exit(0);
