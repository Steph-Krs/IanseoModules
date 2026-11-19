<?php
/**
 * Child process: made-up data of one module, from tests/fixtures/<MODULE>.php, so that
 * its pages have something to show. Runs once the module's tables exist; receives the
 * made-up competition in BENCH_TOUR. Data is always invented: no real name or number.
 */
$benchModule = basename($argv[1] ?? '');
$benchFile   = dirname(__DIR__) . '/fixtures/' . $benchModule . '.php';
if (!is_file($benchFile)) {
    fwrite(STDERR, "no tests/fixtures/$benchModule.php\n");
    exit(2);
}

require __DIR__ . '/boot.php';

$benchTour = (int)getenv('BENCH_TOUR');
require $benchFile;

echo "BENCH-OK\n";
