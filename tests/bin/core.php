<?php
/**
 * Prepare the ianseo core the way a new installation gets it:
 *
 *   1. the official package from ianseo.net, checked against its SHA-256;
 *   2. installed: Install/install.sql, then the database migrations;
 *   3. brought to the latest release by the core's own update system (the "Update
 *      ianseo" page), which downloads the difference from ianseo.net.
 *
 * The updated core is kept in /cache/core and reused for the rest of the UTC day: at most
 * one download a day from ianseo.net, whatever the number of runs. --force redoes it.
 *
 * No module is present while the core is updated: the update sends ianseo.net the list
 * of the files of the installation, which is then the official one only.
 *
 *     php bin/core.php [--force]
 */
require_once __DIR__ . '/../lib/bench.php';

$force = in_array('--force', $argv, true);
$dir   = BENCH_CACHE . '/core';
$stamp = $dir . '/stamp.json';
$today = gmdate('Y-m-d');

$s = json_decode((string)@file_get_contents($stamp), true);
if (!$force && $s && $s['day'] === $today && $s['package'] === BENCH_PACKAGE && is_file($dir . '/htdocs/config.php')) {
    bench_say("ianseo {$s['version']} (updated today from " . BENCH_PACKAGE . ', cached)');
    exit(0);
}

// 1. The package.
$zip = BENCH_CACHE . '/' . BENCH_PACKAGE;
if (!is_file($zip) || hash_file('sha256', $zip) !== BENCH_PACKAGE_SHA256) {
    bench_say('Downloading ' . BENCH_PACKAGE_URL);
    $in = @fopen(BENCH_PACKAGE_URL, 'r');
    if (!$in) bench_die('ianseo.net unreachable: the environment could not be built (this is not a test failure)');
    $part = $zip . '.part';
    file_put_contents($part, $in);
    fclose($in);
    if (hash_file('sha256', $part) !== BENCH_PACKAGE_SHA256) {
        @unlink($part);
        bench_die(BENCH_PACKAGE . ' does not match its SHA-256: ianseo republished it under the same name, or the download was altered. Check, then update BENCH_PACKAGE_SHA256 in tests/lib/bench.php.');
    }
    rename($part, $zip);
}

$new = BENCH_CACHE . '/core.new';
bench_rmdir($new);
mkdir($new . '/htdocs', 0777, true);
$z = new ZipArchive();
if ($z->open($zip) !== true || !$z->extractTo($new . '/htdocs')) bench_die('cannot unpack ' . BENCH_PACKAGE);
$z->close();
$htdocs = $new . '/htdocs';
bench_say('Package ' . BENCH_PACKAGE . ': ianseo ' . bench_core_version($htdocs));

// 2. Installed, as the installer does.
$db = BENCH_PREFIX . 'update';
bench_write_config($htdocs);
bench_create($db);
bench_install_sql($htdocs, $db);
$r = bench_child('child-migrate.php', $htdocs, $db);
if (!$r['ok']) bench_die("migrations of the package failed:\n" . $r['out']);

// 3. Updated by the core's own update system.
bench_say('Updating through the core update system…');
bench_child('child-update.php', $htdocs, $db);
$status = json_decode((string)@file_get_contents($htdocs . '/TV/Photos/updating.json'), true);
if (!$status || !empty($status['error']) || empty($status['finished'])) {
    $msg = trim(strip_tags(str_replace(['<br>', '<br/>', '</div>'], "\n", (string)($status['msg'] ?? $status['status'] ?? ''))));
    bench_die("the core update failed (this is not a test failure):\n" . $msg);
}
$r = bench_child('child-migrate.php', $htdocs, $db);
if (!$r['ok']) bench_die("migrations after the update failed:\n" . $r['out']);

unlink($htdocs . '/Common/config.inc.php');
bench_drop($db);

$version = bench_core_version($htdocs);
bench_rmdir($dir);
rename($new, $dir);
file_put_contents($stamp, json_encode(['day' => $today, 'package' => BENCH_PACKAGE, 'version' => $version]));
bench_say("ianseo $version (updated from " . BENCH_PACKAGE . ')');
