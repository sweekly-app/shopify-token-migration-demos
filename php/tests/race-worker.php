<?php
// One process of the race test: waits for the start time, then asks for a fresh token.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$config = demo_config();
$pdo = demo_pdo($config);
$tokens = new Tokens($config, $pdo);
time_sleep_until((float) $argv[2]);
try { echo 'OK ' . $tokens->ensureFresh($argv[1]); } catch (Throwable $e) { echo 'ERR ' . $e->getMessage(); }
