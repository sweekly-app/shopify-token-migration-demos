<?php
// Usage: php scripts/migrate-tokens.php [--apply] [--shop <shop>.myshopify.com]
// Without --apply it only lists the shops it would exchange.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/MigrateTokens.php';

$args = array_slice($argv, 1);
$i = array_search('--shop', $args, true);
$only = $i !== false ? ($args[$i + 1] ?? '') : null;
if ($only !== null && !preg_match(SHOP_RE, $only)) {
    fwrite(STDERR, "--shop needs a value like my-store.myshopify.com (refusing to run on every shop)\n");
    exit(2);
}
$config = demo_config();
$pdo = demo_pdo($config);
$report = migrate_tokens($pdo, new Tokens($config, $pdo), in_array('--apply', $args, true), $only);
exit($report['failed'] ? 1 : 0);
