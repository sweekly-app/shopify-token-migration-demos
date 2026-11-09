<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$config = demo_config();
$pdo = demo_pdo($config);
$tokens = new Tokens($config, $pdo);
$admin = new Admin($config, $tokens);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function respond(int $status, string $body = '', string $type = 'text/plain'): never {
    http_response_code($status);
    header("Content-Type: $type");
    echo $body;
    exit;
}

try {
    if ($path === '/webhooks' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        if (!valid_webhook_hmac($config['api_secret'], $raw, $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? null)) respond(401);
        $shop = $_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] ?? '';
        if (!preg_match(SHOP_RE, $shop)) respond(400);
        handle_webhook($pdo, $_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '', $shop);
        respond(200);
    }

    if ($path === '/auth') {
        $shop = (string) ($_GET['shop'] ?? '');
        if (!preg_match(SHOP_RE, $shop)) respond(400, 'bad shop');
        $state = bin2hex(random_bytes(16));
        setcookie('oauth_state', $state, ['expires' => time() + 600, 'path' => '/auth', 'httponly' => true, 'secure' => true, 'samesite' => 'Lax']);
        header('Location: ' . authorize_url($config, $shop, $state), true, 302);
        exit;
    }

    if ($path === '/auth/callback') {
        $shop = (string) ($_GET['shop'] ?? '');
        $state = $_COOKIE['oauth_state'] ?? null;
        if (!preg_match(SHOP_RE, $shop) || !$state || !hash_equals($state, (string) ($_GET['state'] ?? ''))) respond(403, 'bad state');
        if (!valid_query_hmac($config['api_secret'], $_GET)) respond(403, 'bad hmac');
        $t = $tokens->codeGrant($shop, (string) ($_GET['code'] ?? ''));
        if (empty($t['refresh_token'])) throw new RuntimeException('Shopify returned a non-expiring token: is expiring=1 sent?');
        $tokens->save($shop, $t);
        setcookie('oauth_state', '', ['expires' => 1, 'path' => '/auth']);
        header('Location: /?shop=' . urlencode($shop), true, 302);
        exit;
    }

    if ($path === '/api/products') {
        $shop = (string) ($_GET['shop'] ?? '');
        if (!preg_match(SHOP_RE, $shop)) respond(400, 'bad shop');
        try {
            respond(200, json_encode($admin->graphql($shop, '{ products(first: 5) { nodes { id title } } }')), 'application/json');
        } catch (ReauthRequired) {
            respond(401, json_encode(['reauth' => "/auth?shop=$shop"]), 'application/json');
        } catch (AdminApiError $e) {
            respond(502, json_encode(['status' => $e->status, 'body' => $e->body]), 'application/json');
        }
    }

    respond(200, 'Token demo (after): expiring offline tokens.');
} catch (Throwable $e) {
    error_log((string) $e);
    respond(500, 'error');
}
