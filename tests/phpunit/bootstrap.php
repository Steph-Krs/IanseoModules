<?php
/**
 * ianseo loaded for PHPUnit, on the command line, on the database named by BENCH_DB.
 *
 * Loaded as auto_prepend_file by bin/phpunit.php, hence in the global scope like a page:
 * ianseo reads $CFG, $INFO… as globals, and PHPUnit would include its bootstrap inside a method.
 */
if (defined('BENCH_PHPUNIT_BOOTSTRAP')) return;
define('BENCH_PHPUNIT_BOOTSTRAP', true);

$_SERVER += array(
    'SERVER_NAME' => 'localhost', 'SERVER_PORT' => 80, 'REQUEST_SCHEME' => 'http',
    'REMOTE_ADDR' => '127.0.0.1', 'REQUEST_METHOD' => 'GET',
    'PHP_SELF' => '/index.php', 'REQUEST_URI' => '/index.php',
);

// ianseo from BENCH_HTDOCS, checked to be connected to BENCH_DB (the same boot as the
// child processes of the bench).
require dirname(__DIR__) . '/lib/boot.php';

// The tests want every warning and notice; PHPUnit turns them into failures.
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

// On an SQL error, ianseo's safe_error() prints the message then calls exit, which stops
// PHPUnit in the middle of the run: bin/phpunit.php sees the missing summary line.

require_once __DIR__ . '/lib/TestDb.php';
require_once __DIR__ . '/lib/Fixtures.php';
// lib/IanseoTestCase.php needs PHPUnit's classes: phpunit.xml loads it, once PHPUnit is up.
