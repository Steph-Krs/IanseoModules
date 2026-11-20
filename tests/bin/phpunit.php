<?php
/**
 * PHPUnit tests of the modules (tests/phpunit/<MODULE>/), on the core prepared by bin/core.php.
 *
 *     php bin/phpunit.php            every module that has tests
 *     php bin/phpunit.php AUTH
 *
 * They call a module's libraries the way its pages do, on a database built from install.sql,
 * the core migrations and the schemas of every module: what a fresh install only creates,
 * these tests use (issue 13 came from such a query). The database is put back in its
 * reference state before each test (phpunit/lib/TestDb.php).
 *
 * PHPUnit runs in a child process: ianseo stops the whole process on the first SQL error
 * (safe_error() then exit, with status 0), so the run only passes when PHPUnit printed its
 * own summary line.
 */
require_once __DIR__ . '/../lib/bench.php';

const UNIT_DB = BENCH_PREFIX . 'phpunit';
$phpunitDir   = dirname(__DIR__) . '/phpunit';

$wanted = array_slice($argv, 1);
$suites = [];
foreach (glob($phpunitDir . '/*', GLOB_ONLYDIR) as $dir) {
    $mod = basename($dir);
    if ($mod === 'lib' || $mod === 'vendor' || ($wanted && !in_array($mod, $wanted, true))) continue;
    $suites[] = $mod;
}
if (!$suites) {
    bench_say('no PHPUnit tests for ' . ($wanted ? implode(', ', $wanted) : 'any module'));
    exit(0);
}

$stamp = bench_prepare_run();
bench_say("ianseo {$stamp['version']} (updated {$stamp['day']} from " . BENCH_PACKAGE . '), PHP ' . PHP_VERSION);

// The database of the tests: a new installation, then the tables of every module.
bench_reference_db(UNIT_DB);
foreach (bench_module_confs() as $mod => $conf) {
    if (empty($conf['schema']['call']) || !is_dir(BENCH_HTDOCS . '/Modules/Custom/' . $mod)) continue;
    $r = bench_child('child-schema.php', BENCH_HTDOCS, UNIT_DB, [$mod]);
    if (!$r['ok']) bench_die("schema of $mod failed:\n" . $r['out']);
}
bench_clone(UNIT_DB, UNIT_DB . '_ref');

$cmd = [PHP_BINARY, '-d', 'auto_prepend_file=' . $phpunitDir . '/bootstrap.php',
        '/opt/bench-php/vendor/phpunit/phpunit/phpunit',
        '-c', $phpunitDir . '/phpunit.xml', '--testsuite', implode(',', $suites)];
$env = getenv();
$env['BENCH_DB']     = UNIT_DB;
$env['BENCH_HTDOCS'] = BENCH_HTDOCS;
$p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $phpunitDir, $env);
if (!is_resource($p)) bench_die('PHPUnit could not start');
$out = '';
while (($line = fgets($pipes[1])) !== false) {
    echo $line;   // live: a long run shows its progress
    $out .= $line;
}
fclose($pipes[1]);
$code = proc_close($p);

bench_drop(UNIT_DB);
bench_drop(UNIT_DB . '_ref');

// "OK (n tests…)" or "Tests: …, Failures: …": PHPUnit ended by itself.
if (!preg_match('/^(OK \(|Tests: \d+)/m', $out)) {
    bench_say("\nFAILED: PHPUnit stopped before its summary (an SQL error in ianseo's safe_error()?), see above.");
    exit(1);
}
bench_say("\n" . ($code === 0 ? 'All PHPUnit tests passed.' : 'FAILED: PHPUnit tests (' . implode(', ', $suites) . ')'));
exit($code === 0 ? 0 : 1);
