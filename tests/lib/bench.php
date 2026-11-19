<?php
/**
 * Shared helpers of the test bench. Runs inside the bench container only (see
 * tests/docker/), against the throwaway database server of the compose file.
 *
 * Paths inside the container:
 *   /src            the repository, mounted read-only: the bench never writes to it
 *   /cache          downloads and the updated core, kept between runs
 *   /opt/ianseo     the ianseo used by a run: a copy of the cached core + the modules
 */

const BENCH_PREFIX = 'ianseo_test_';
// Overridable to run the bench outside its container (a development machine with its
// own throwaway database server). The container uses the defaults.
define('BENCH_SRC', getenv('BENCH_SRC') ?: '/src');
define('BENCH_CACHE', getenv('BENCH_CACHE') ?: '/cache');
define('BENCH_HTDOCS', getenv('BENCH_RUN_DIR') ?: '/opt/ianseo');

/**
 * The official package published on ianseo.net. A new installation starts from it and
 * reaches the latest release through the core's own update. Changes only when ianseo
 * repackages (the 2025-02-10 package is updated to 2026-10-26 by the update system).
 */
const BENCH_PACKAGE        = 'Ianseo_20250210.zip';
const BENCH_PACKAGE_SHA256 = 'a70a4acc1aebb1f9d01860e5e5dc15bcc949cf4488f1c44f72cc1a57d528f341';
const BENCH_PACKAGE_URL    = 'https://www.ianseo.net/Release/' . BENCH_PACKAGE;

/**
 * @param string $msg
 */
function bench_say($msg)
{
    echo $msg . "\n";
}

/**
 * @param string $msg
 */
function bench_die($msg)
{
    fwrite(STDERR, "ERROR: $msg\n");
    exit(1);
}

/**
 * @return mysqli Root connection to the database server of the compose file.
 */
function bench_db()
{
    static $db = null;
    if ($db) return $db;
    mysqli_report(MYSQLI_REPORT_OFF);
    $host = getenv('BENCH_DB_HOST') ?: 'db';
    $pass = getenv('BENCH_DB_PASS') ?: 'bench';
    // The server container may still be starting.
    for ($i = 0; $i < 90; $i++) {
        $db = @new mysqli($host, getenv('BENCH_DB_USER') ?: 'root', $pass);
        if (!$db->connect_errno) break;
        $db = null;
        sleep(1);
    }
    if (!$db) bench_die("database server $host unreachable");
    $db->set_charset('utf8mb4');
    // The session mode of ianseo's own connections (Common/Fun_DB.inc.php).
    $db->query("SET SESSION sql_mode = 'NO_UNSIGNED_SUBTRACTION'");
    return $db;
}

/**
 * @param string $sql
 * @return mysqli_result|bool
 */
function bench_q($sql)
{
    $r = bench_db()->query($sql);
    if ($r === false) {
        throw new RuntimeException(bench_db()->error . "\n  in: " . substr($sql, 0, 300));
    }
    return $r;
}

/**
 * @param string $name
 * @return string The name, checked: only throwaway databases are ever touched.
 */
function bench_safe_name($name)
{
    if (strpos($name, BENCH_PREFIX) !== 0 || !preg_match('/^[a-z0-9_]+$/', $name)) {
        throw new RuntimeException("Refusing to touch database \"$name\".");
    }
    return $name;
}

/**
 * Create an empty database with the server's own defaults: what a new installation gets.
 *
 * @param string $name
 */
function bench_create($name)
{
    $name = bench_safe_name($name);
    bench_q("DROP DATABASE IF EXISTS `$name`");
    bench_q("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
}

/**
 * @param string $name
 */
function bench_drop($name)
{
    bench_q('DROP DATABASE IF EXISTS `' . bench_safe_name($name) . '`');
}

/**
 * Load Install/install.sql the way install_blank_db() of Install/index.php does: lines
 * starting with "--" skipped, each line trimmed, a statement ends with ";".
 *
 * @param string $htdocs
 * @param string $db
 */
function bench_install_sql($htdocs, $db)
{
    bench_db()->select_db(bench_safe_name($db));
    $query = '';
    foreach (file($htdocs . '/Install/install.sql') as $line) {
        if (substr($line, 0, 2) === '--') continue;
        if (trim($line) !== '') $query .= trim($line);
        if ($query !== '' && substr($query, -1) === ';') {
            bench_q($query);
            $query = '';
        }
    }
    if ($query !== '' && substr($query, -1) === ';') bench_q($query);
}

/**
 * @param string $db
 * @return string[] Base tables of the database.
 */
function bench_tables($db)
{
    $out = [];
    $r   = bench_q("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . bench_safe_name($db) . "' AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
    while ($row = $r->fetch_row()) $out[] = $row[0];
    return $out;
}

/**
 * Copy a database built by the bench. CREATE TABLE ... LIKE is safe here: the source
 * was itself built from install.sql, never copied from a development database.
 *
 * @param string $from
 * @param string $to
 */
function bench_clone($from, $to)
{
    bench_create($to);
    foreach (bench_tables($from) as $t) {
        bench_q("CREATE TABLE `$to`.`$t` LIKE `$from`.`$t`");
        bench_q("INSERT INTO `$to`.`$t` SELECT * FROM `$from`.`$t`");
    }
}

/**
 * Structure (columns, collations, indexes) and content of one table.
 *
 * @param string $db
 * @param string $table
 * @return string
 */
function bench_fingerprint($db, $table)
{
    $db  = bench_safe_name($db);
    $t   = bench_db()->real_escape_string($table);
    $sig = '';
    $r   = bench_q("SELECT COLUMN_NAME, COLUMN_TYPE, IFNULL(COLLATION_NAME,''), IS_NULLABLE, IFNULL(COLUMN_DEFAULT,'~')
                    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$db' AND TABLE_NAME = '$t' ORDER BY ORDINAL_POSITION");
    while ($row = $r->fetch_row()) $sig .= implode('|', $row) . "\n";
    $r   = bench_q("SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE
                    FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '$db' AND TABLE_NAME = '$t' ORDER BY INDEX_NAME, SEQ_IN_INDEX");
    while ($row = $r->fetch_row()) $sig .= implode('|', $row) . "\n";
    $r   = bench_db()->query("CHECKSUM TABLE `$db`.`" . str_replace('`', '', $table) . "`");
    if ($r && ($row = $r->fetch_row())) $sig .= 'checksum=' . $row[1];
    return md5($sig);
}

/**
 * Run a script of tests/lib/ in a child PHP process pointed at a database. A child,
 * because ianseo stops the whole process on the first SQL error (header + exit in
 * safe_error()), and because a new process is a new session: running a schema
 * function in two children is exactly "a second session".
 *
 * @param string $script File of tests/lib/.
 * @param string $htdocs The ianseo the child loads.
 * @param string $db
 * @param string[] $args
 * @return array ['ok' => bool, 'out' => string]
 */
function bench_child($script, $htdocs, $db, array $args = [])
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $script);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);

    $env = getenv();
    $env['BENCH_DB']     = $db;
    $env['BENCH_HTDOCS'] = $htdocs;
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($p)) return ['ok' => false, 'out' => 'could not start PHP'];
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);

    // The marker, not the exit code: safe_error() exits with status 0.
    return ['ok' => $code === 0 && preg_match('/^BENCH-OK\b/m', $out) === 1, 'out' => trim($out)];
}

/**
 * The configuration ianseo reads (Common/config.inc.php): every connection goes to the
 * database named by BENCH_DB, so a child process can only reach the one it was given.
 *
 * @param string $htdocs
 */
function bench_write_config($htdocs)
{
    $php = <<<'PHP'
<?php
// Written by the test bench: the database comes from the environment of the process.
$CFG->R_HOST = $CFG->W_HOST = getenv('BENCH_DB_HOST') ?: 'db';
$CFG->R_USER = $CFG->W_USER = getenv('BENCH_DB_USER') ?: 'root';
$CFG->R_PASS = $CFG->W_PASS = getenv('BENCH_DB_PASS') ?: 'bench';
$CFG->DB_NAME = getenv('BENCH_DB') ?: 'ianseo_test_none';
$CFG->ROOT_DIR = '/';
PHP;
    file_put_contents($htdocs . '/Common/config.inc.php', $php . "\n");
}

/**
 * @param string $htdocs
 * @return string "2026-10-26 rev 16"
 */
function bench_core_version($htdocs)
{
    $v = preg_match('/define\s*\(\s*"ProgramVersion"\s*,\s*"([^"]+)"/', (string)@file_get_contents($htdocs . '/Common/Globals.inc.php'), $m) ? $m[1] : '?';
    $b = preg_match("/'ProgramBuild'\s*,\s*'([^']+)'/", (string)@file_get_contents($htdocs . '/Common/distro.inc.php'), $m) ? $m[1] : '?';
    return "$v $b";
}

/**
 * Copy a directory tree.
 *
 * @param string $from
 * @param string $to
 * @param string[] $skip Names skipped at any level.
 */
function bench_copy($from, $to, array $skip = [])
{
    if (!is_dir($to)) mkdir($to, 0777, true);
    foreach (scandir($from) as $f) {
        if ($f === '.' || $f === '..' || in_array($f, $skip, true)) continue;
        if (is_dir("$from/$f") && !is_link("$from/$f")) {
            bench_copy("$from/$f", "$to/$f", $skip);
        } else {
            copy("$from/$f", "$to/$f");
        }
    }
}

/**
 * @param string $dir
 */
function bench_rmdir($dir)
{
    if (!is_dir($dir)) return;
    $real = str_replace('\\', '/', realpath($dir));
    $ok   = false;
    foreach ([BENCH_CACHE, BENCH_HTDOCS] as $root) {
        $root = str_replace('\\', '/', realpath($root) ?: $root);
        if ($real === $root || strpos($real, rtrim($root, '/') . '/') === 0) $ok = true;
    }
    if (!$ok) {
        throw new RuntimeException("Refusing to remove $dir");
    }
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        is_dir("$dir/$f") && !is_link("$dir/$f") ? bench_rmdir("$dir/$f") : unlink("$dir/$f");
    }
    rmdir($dir);
}
