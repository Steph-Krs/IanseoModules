<?php
/**
 * Check the modules on the core prepared by bin/core.php.
 *
 *     php bin/check.php            every module of tests/modules/
 *     php bin/check.php AUTH TIRAGE
 *
 * For each module described in tests/modules/<MODULE>.json:
 *
 *   Fresh install ("schema"): on a copy of a database built from Install/install.sql and
 *   the core migrations, the schema function(s) of the module
 *     - run to the end,
 *     - create every table of "tables" in version.json, spelled exactly as declared
 *       (table names are case-sensitive on Linux),
 *     - and a second run, which is a second session, changes neither the structure nor
 *       the content of those tables. This also catches a migration placed before the
 *       CREATE TABLE of its own table, which a development database never shows.
 *
 *   Scripts ("scripts"): test scripts of the module, run with PHP; exit code 0 = passed.
 */
require_once __DIR__ . '/../lib/bench.php';

$stamp = json_decode((string)@file_get_contents(BENCH_CACHE . '/core/stamp.json'), true);
if (!$stamp) bench_die('no prepared core: run bin/core.php first');

$server = bench_q("SELECT VERSION()")->fetch_row()[0];
$lower  = bench_q("SELECT @@lower_case_table_names")->fetch_row()[0];
// What a database created with CHARACTER SET utf8mb4 really gets: MariaDB 11 no longer
// reports its default in information_schema.COLLATIONS (character_set_collations).
bench_create(BENCH_PREFIX . 'probe');
$coll = bench_q("SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . BENCH_PREFIX . "probe'")->fetch_row()[0];
bench_drop(BENCH_PREFIX . 'probe');
bench_say("ianseo {$stamp['version']} (updated {$stamp['day']} from " . BENCH_PACKAGE . ')');
bench_say("database server $server, utf8mb4 default collation $coll, lower_case_table_names=$lower");

// The ianseo of this run: the updated core, then the modules of the repository.
bench_rmdir(BENCH_HTDOCS);
bench_copy(BENCH_CACHE . '/core/htdocs', BENCH_HTDOCS);
bench_write_config(BENCH_HTDOCS);
foreach (array_merge(glob(BENCH_SRC . '/*/module.json'), [BENCH_SRC . '/_shared/version.json']) as $marker) {
    $mod = basename(dirname($marker));
    if (is_file($marker)) bench_copy(dirname($marker), BENCH_HTDOCS . '/Modules/Custom/' . $mod, ['.git', 'node_modules']);
}

// The reference database, as a new installation has it.
$core = BENCH_PREFIX . 'reference';
bench_create($core);
bench_install_sql(BENCH_HTDOCS, $core);
$r = bench_child('child-migrate.php', BENCH_HTDOCS, $core);
if (!$r['ok']) bench_die("core migrations failed:\n" . $r['out']);
$coreTables = bench_tables($core);
bench_say('reference database: ' . count($coreTables) . ' core tables, DBUpdate '
    . (preg_match('/^BENCH-OK (.+)$/m', $r['out'], $m) ? trim($m[1]) : '?'));

$wanted = array_slice($argv, 1);
$files  = glob(dirname(__DIR__) . '/modules/*.json');
$failed = [];

foreach ($files as $file) {
    $mod = basename($file, '.json');
    if ($wanted && !in_array($mod, $wanted, true)) continue;
    $conf = json_decode((string)file_get_contents($file), true);
    if (!is_array($conf)) {
        $failed[] = $mod;
        bench_say("\n== $mod\n  FAIL  $file is not valid JSON");
        continue;
    }
    if (!is_dir(BENCH_HTDOCS . '/Modules/Custom/' . $mod)) {
        bench_say("\n== $mod\n  skipped: module not in this repository");
        continue;
    }
    bench_say("\n== $mod");
    $bad = 0;

    if (!empty($conf['schema']['call'])) {
        $bad += bench_check_fresh($mod, $conf, $core);
    }
    foreach ($conf['scripts'] ?? [] as $script) {
        $out  = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BENCH_HTDOCS . '/Modules/Custom/' . $script) . ' 2>&1', $out, $code);
        $last = '';
        for ($i = count($out) - 1; $i >= 0 && $last === ''; $i--) $last = trim($out[$i], " \t═=");
        if ($code === 0) {
            bench_say("  ok    $script — $last");
        } else {
            $bad++;
            bench_say("  FAIL  $script (exit code $code)");
            foreach (array_slice($out, -40) as $l) bench_say('        ' . $l);
        }
    }
    if ($bad) $failed[] = $mod;
}

bench_drop($core);
bench_say("\n" . ($failed ? 'FAILED: ' . implode(', ', $failed) : 'All checks passed.'));
exit($failed ? 1 : 0);

/**
 * @param string $mod
 * @param array $conf
 * @param string $core Reference database.
 * @return int Number of failures.
 */
function bench_check_fresh($mod, array $conf, $core)
{
    $version  = json_decode((string)@file_get_contents(BENCH_HTDOCS . "/Modules/Custom/$mod/version.json"), true);
    $legacy   = $conf['schema']['legacy_tables'] ?? [];
    // Old names kept in "tables" so that uninstalling an old installation drops them
    // too: a new installation never creates them.
    $declared = array_values(array_filter((array)($version['tables'] ?? []), function ($t) use ($legacy) {
        foreach ($legacy as $p) if (fnmatch($p, $t)) return false;
        return true;
    }));

    $db = BENCH_PREFIX . 'fresh_' . strtolower(preg_replace('/[^A-Za-z0-9]/', '', $mod));
    bench_clone($core, $db);
    $before = bench_tables($db);
    $bad    = 0;

    $r = bench_child('child-schema.php', BENCH_HTDOCS, $db, [$mod]);
    if (!$r['ok']) {
        bench_say('  FAIL  fresh install: the schema stopped before the end');
        foreach (explode("\n", $r['out']) as $l) if (trim($l) !== '') bench_say('        ' . $l);
        bench_drop($db);
        return 1;
    }

    $created = array_values(array_diff(bench_tables($db), $before));
    foreach ($declared as $t) {
        if (in_array($t, $created, true)) continue;
        $bad++;
        $other = array_values(array_filter($created, function ($c) use ($t) { return strcasecmp($c, $t) === 0; }));
        bench_say($other
            ? "  FAIL  table $t created as {$other[0]}: Linux sees two different names"
            : "  FAIL  table $t declared in version.json, not created");
    }
    foreach ($created as $t) {
        $known = false;
        foreach ($declared as $d) if (strcasecmp($d, $t) === 0) $known = true;
        if (!$known) bench_say("  note  table $t created, absent from \"tables\" of version.json");
    }

    $fp = [];
    foreach ($created as $t) $fp[$t] = bench_fingerprint($db, $t);
    $r = bench_child('child-schema.php', BENCH_HTDOCS, $db, [$mod]);
    if (!$r['ok']) {
        $bad++;
        bench_say('  FAIL  fresh install: the second run (second session) stopped before the end');
        foreach (explode("\n", $r['out']) as $l) if (trim($l) !== '') bench_say('        ' . $l);
    } else {
        $changed = array_values(array_filter($created, function ($t) use ($db, $fp) { return bench_fingerprint($db, $t) !== $fp[$t]; }));
        if ($changed) {
            $bad++;
            bench_say('  FAIL  fresh install: the second run (second session) changed ' . implode(', ', $changed)
                . ' — a migration runs before its table exists, or rewrites data on every session');
        }
    }
    if (!$bad) bench_say('  ok    fresh install — ' . count($created) . ' table(s), second run changes nothing');
    bench_drop($db);
    return $bad;
}
