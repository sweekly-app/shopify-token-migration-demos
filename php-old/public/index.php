<?php
// BEFORE: a typical hand-rolled PHP Shopify app (no framework, no Shopify package).
// What is wrong with it (each item is fixed in ../php):
//   1. The code grant does not ask for an expiring token: the app holds a token that never expires
//      (delisted 2027-01-01, Admin API 403 from 2027-04-01).
//   2. No refresh token, no expiry, no refresh path.
//   3. Shops installed earlier are never exchanged for expiring tokens.
//   4. The webhook HMAC is computed over json_encode(json_decode($body)), not the raw bytes, and compared with !==.
$apiKey = getenv('SHOPIFY_API_KEY');
$secret = getenv('SHOPIFY_API_SECRET');
$host = getenv('HOST');
$pdo = new PDO(getenv('DATABASE_DSN'));
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
session_start();

function post_json($url, $data, $headers = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS => json_encode($data)]);
    $out = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($out, true)];
}

if ($path === '/auth') {
    $shop = $_GET['shop'] ?? '';
    if (!preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop)) { http_response_code(400); exit('bad shop'); }
    $_SESSION['state'] = bin2hex(random_bytes(16));
    header('Location: https://' . $shop . '/admin/oauth/authorize?client_id=' . $apiKey . '&scope=read_products&redirect_uri='
        . urlencode($host . '/auth/callback') . '&state=' . $_SESSION['state']);
    exit;
}

if ($path === '/auth/callback') {
    $params = $_GET;
    $hmac = $params['hmac'] ?? '';
    unset($params['hmac']);
    ksort($params);
    if (hash_hmac('sha256', urldecode(http_build_query($params)), $secret) !== $hmac || ($params['state'] ?? '') !== ($_SESSION['state'] ?? null)) {
        http_response_code(403); exit('bad request');
    }
    $shop = $params['shop'];
    [, $token] = post_json("https://$shop/admin/oauth/access_token", ['client_id' => $apiKey, 'client_secret' => $secret, 'code' => $params['code']]);
    $st = $pdo->prepare('INSERT INTO shops (shop, access_token, scope) VALUES (?, ?, ?) ON CONFLICT (shop) DO UPDATE SET access_token = EXCLUDED.access_token');
    $st->execute([$shop, $token['access_token'], $token['scope']]);
    header('Location: /?shop=' . $shop);
    exit;
}

if ($path === '/api/products') {
    $st = $pdo->prepare('SELECT access_token FROM shops WHERE shop = ?');
    $st->execute([$_GET['shop'] ?? '']);
    $tok = $st->fetchColumn();
    if (!$tok) { http_response_code(401); exit('not installed'); }
    [$status, $body] = post_json('https://' . $_GET['shop'] . '/admin/api/2026-10/graphql.json',
        ['query' => '{ products(first: 5) { nodes { id title } } }'], ['X-Shopify-Access-Token: ' . $tok]);
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

if ($path === '/webhooks') {
    $data = json_decode(file_get_contents('php://input'), true);
    $calc = base64_encode(hash_hmac('sha256', json_encode($data), $secret, true));
    if ($calc !== ($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '')) { http_response_code(401); exit; }
    $topic = $_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '';
    if ($topic === 'app/uninstalled' || $topic === 'shop/redact') {
        $pdo->prepare('DELETE FROM shops WHERE shop = ?')->execute([$_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] ?? '']);
    }
    // customers/data_request and customers/redact: no customer data stored.
    http_response_code(200);
    exit;
}

echo 'Token demo (before)';
