<?php
/**
 * Bootstrap of a child process: ianseo loaded from BENCH_HTDOCS on the database named
 * by BENCH_DB, then checked to really be on it.
 */
if (PHP_SAPI !== 'cli') exit;

$benchDb     = (string)getenv('BENCH_DB');
$benchHtdocs = (string)getenv('BENCH_HTDOCS');
if (!preg_match('/^ianseo_test_[a-z0-9_]+$/', $benchDb) || !is_file($benchHtdocs . '/config.php')) {
    fwrite(STDERR, "BENCH: missing or invalid BENCH_DB / BENCH_HTDOCS\n");
    exit(3);
}

chdir($benchHtdocs);
if (!defined('HTDOCS')) define('HTDOCS', $benchHtdocs);
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$SKIP_AUTH = 1;

require_once $benchHtdocs . '/config.php';

$benchRow = safe_fetch(safe_r_sql("SELECT DATABASE() AS d"));
if (!$benchRow || $benchRow->d !== $benchDb) {
    fwrite(STDERR, "BENCH: connected to the wrong database, stopping\n");
    exit(3);
}
if (!isset($_SESSION)) $_SESSION = [];

// ianseo hides its errors outside debug mode, and safe_error() exits without a word
// that survives the command line. Say what stopped the run.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', 'stderr');
register_shutdown_function(function () {
    global $WRIT_CON, $READ_CON;
    foreach (['write' => $WRIT_CON, 'read' => $READ_CON] as $side => $con) {
        if ($con instanceof mysqli && $con->errno) {
            fwrite(STDERR, "BENCH: last SQL error ($side connection): {$con->errno} {$con->error}\n");
        }
    }
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fwrite(STDERR, "BENCH: PHP fatal error: {$e['message']} in {$e['file']}:{$e['line']}\n");
    }
});
