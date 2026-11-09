<?php
declare(strict_types=1);

require_once __DIR__ . '/Tokens.php';
require_once __DIR__ . '/Admin.php';
require_once __DIR__ . '/Oauth.php';
require_once __DIR__ . '/Webhooks.php';

const SHOP_RE = '/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/';

function demo_config(): array {
    $need = ['SHOPIFY_API_KEY', 'SHOPIFY_API_SECRET', 'HOST', 'DATABASE_DSN'];
    foreach ($need as $k) if (!getenv($k)) throw new RuntimeException("missing env: $k");
    // Tests only: a local fake of the Shopify endpoints. Ignored unless APP_ENV=test.
    $origin = getenv('APP_ENV') === 'test' ? (getenv('SHOP_ORIGIN_FOR_TESTS') ?: null) : null;
    return [
        'api_key' => getenv('SHOPIFY_API_KEY'),
        'api_secret' => getenv('SHOPIFY_API_SECRET'),
        'host' => rtrim(getenv('HOST'), '/'),
        'dsn' => getenv('DATABASE_DSN'),
        'scopes' => 'read_products',
        'api_version' => '2026-10',
        'shop_origin' => fn (string $shop): string => $origin ?? "https://$shop",
    ];
}

function demo_pdo(array $config): PDO {
    return new PDO($config['dsn'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

function demo_migrate(PDO $pdo): void {
    $files = glob(__DIR__ . '/../sql/*.sql');
    sort($files);
    foreach ($files as $f) $pdo->exec(file_get_contents($f));
}
