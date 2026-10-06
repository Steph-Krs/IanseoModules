<?php
/**
 * shop/cron/purge.php — erasing of the points of sale the day after a competition (command line).
 *
 * Run by the nightly maintenance (cron/maintenance.php) as a sub-process; harmless to run by
 * hand at any time (idempotent). See lib/purge.php for what is erased and when.
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
require_once dirname(__DIR__) . '/lib/purge.php';

$n = shp_purge_due(true);
echo 'Points of sale: ' . intval($n['requests']) . ' expired request(s), ' . intval($n['competitions']) . ' competition(s) over, '
    . intval($n['staff']) . ' volunteer(s) erased or ended, ' . intval($n['guests']) . ' visitor access(es) closed, '
    . intval($n['orphans']) . ' row(s) of deleted competitions.' . "\n";
exit(0);
