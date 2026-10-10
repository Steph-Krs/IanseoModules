<?php
/**
 * booking/cron/sync-fed.php — licensee files of the foreign federations that publish one
 * (command line; lib/fedlic.php): Italy, Canada, Slovenia, Baltic countries. Each file is
 * downloaded from the address the ianseo team keeps in LookUpPaths and replaces the former one —
 * unless it holds fewer than 90 % of its licences of the day before, or cannot be read: then the
 * former one stays. The accounts of archers without an FFTA licence are refreshed afterwards.
 *
 * Run by the nightly maintenance (cron/maintenance.php) as a sub-process; harmless to run by
 * hand at any time. The administrator does the same from the server settings page, where the
 * state of each file and the journal are shown (bk_fed_sync_all). Exit 1 when no file could be
 * loaded at all.
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
require_once dirname(__DIR__) . '/lib/fedlic.php';
ini_set('memory_limit', '512M');

$_SESSION = array();
$res = bk_fed_sync_all();
foreach ($res['states'] as $src => $state) {
    $r = bk_fed_state()[$src];
    echo "$src: $state" . ($r ? " ({$r->BfsCount} licences loaded" . ($r->BfsPending ? ", {$r->BfsPending} in the file refused" : '')
        . ($r->BfsMessage !== '' ? ", {$r->BfsMessage}" : '') . ')' : '') . "\n";
}
echo 'Accounts refreshed from a file: ' . intval($res['accounts']) . ".\n";
$ok = count(array_filter($res['states'], function ($s) { return $s === 'ok'; }));
exit($ok === 0 && in_array('fail', $res['states'], true) ? 1 : 0);
