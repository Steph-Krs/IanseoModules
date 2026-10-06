<?php
/**
 * AUTH module — nightly computation of the payer trust index (CLI only).
 *
 * Reads the payments of the competitions ended within the last 24 months, writes the
 * incidents (unpaid, late, rejected payment) and forgets what is settled or too old. Rules:
 * trust-lib.php. Once a night, after the maintenance window:
 *   serveur/cron/ianseo-trust → /etc/cron.d/ianseo-trust
 * One line in the log per run. The administrator can also run it from admin/trust.php.
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
$_SESSION = array();
require_once(dirname(__DIR__) . '/trust-lib.php');

$t = microtime(true);
$st = aut_trust_compute();
$levels = array();
foreach ($st['subjects'] as $l => $n) $levels[] = "$l=$n";
echo '[' . aut_log_time() . '] Trust index (' . aut_trust_mode() . '): ' . $st['tours'] . ' competition(s), '
    . $st['incidents'] . ' incident(s), ' . $st['deleted'] . ' removed' . ($levels ? ', ' . implode(' ', $levels) : '')
    . ', ' . round(microtime(true) - $t, 1) . " s.\n";
exit(0);
