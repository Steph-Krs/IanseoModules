<?php
/**
 * Child process: the schema function(s) of one module, as its pages run them on the
 * first page of a new session. Declared in tests/modules/<MODULE>.json:
 *     "schema": { "require": ["lib/schema.php"], "call": ["rep_schema"] }
 */
$benchModule = basename($argv[1] ?? '');
$benchConf   = json_decode((string)@file_get_contents(dirname(__DIR__) . '/modules/' . $benchModule . '.json'), true);
if (empty($benchConf['schema']['call'])) {
    fwrite(STDERR, "no \"schema\" entry in tests/modules/$benchModule.json\n");
    exit(2);
}

require __DIR__ . '/boot.php';

$benchDir = HTDOCS . '/Modules/Custom/' . $benchModule . '/';
foreach ($benchConf['schema']['require'] ?? [] as $benchFile) {
    require_once $benchDir . $benchFile;
}
foreach ($benchConf['schema']['call'] as $benchFn) {
    if (!function_exists($benchFn)) {
        fwrite(STDERR, "function $benchFn() not found\n");
        exit(2);
    }
    $benchFn();
}

echo "BENCH-OK\n";
