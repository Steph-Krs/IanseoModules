<?php
// Child process: the core migrations, as index.php runs them after an installation.
require __DIR__ . '/boot.php';

$version = GetParameter('DBUpdate');
require 'Common/UpdateDb-check.php';

echo 'BENCH-OK ' . GetParameter('DBUpdate') . "\n";
