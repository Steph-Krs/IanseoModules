<?php
/**
 * booking/cron/purge-other.php — erasing of the accounts of archers without an FFTA licence
 * (command line). One month after their last sign-in and their last competition, never while
 * they are registered for a competition not over (lib/other.php, bk_other_purge).
 *
 * Run by the nightly maintenance (cron/maintenance.php) as a sub-process; harmless to run by
 * hand at any time (idempotent).
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Cron script: command line only.');
}

$SKIP_AUTH = 1;
define('HTDOCS', dirname(__DIR__, 5));
require_once(HTDOCS . '/config.php');
require_once dirname(__DIR__, 2) . '/lib.php';
aut_table_names();
require_once dirname(__DIR__) . '/lib/other.php';

echo 'Archers without an FFTA licence: ' . intval(bk_other_purge()) . " account(s) erased.\n";
exit(0);
