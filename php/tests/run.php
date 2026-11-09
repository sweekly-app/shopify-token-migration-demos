<?php
// Test runner: php tests/run.php. Needs Postgres (DATABASE_DSN, default the local demos_php_test database).
declare(strict_types=1);

$root = dirname(__DIR__);
$dsn = getenv('DATABASE_DSN') ?: 'pgsql:host=localhost;port=5432;dbname=demos_php_test;user=postgres';
$state = tempnam(sys_get_temp_dir(), 'fakeshopify');
$fakePort = 20000 + random_int(0, 9999);
$appPort = $fakePort + 1;
$fakeOrigin = "http://127.0.0.1:$fakePort";
$env = ['SHOPIFY_API_KEY' => 'key', 'SHOPIFY_API_SECRET' => 'secret', 'HOST' => 'https://app.example.com', 'DATABASE_DSN' => $dsn,
    'APP_ENV' => 'test', 'SHOP_ORIGIN_FOR_TESTS' => $fakeOrigin, 'FAKE_STATE' => $state, 'PHP_CLI_SERVER_WORKERS' => '8', 'PATH' => getenv('PATH')];
foreach ($env as $k => $v) putenv("$k=$v");
$procs = [];
$serve = function (array $cmd) use (&$procs, $env, $root) {
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
    $procs[] = $p;
};
$serve([PHP_BINARY, '-S', "127.0.0.1:$fakePort", 'tests/fake-shopify.php']);
$serve([PHP_BINARY, '-S', "127.0.0.1:$appPort", '-t', 'public', 'public/index.php']);
register_shutdown_function(function () use (&$procs, $state) { foreach ($procs as $p) proc_terminate($p); @unlink($state); });
foreach ([$fakePort, $appPort] as $port) {
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
}

require $root . '/src/bootstrap.php';
require $root . '/src/MigrateTokens.php';
$config = demo_config();
$pdo = demo_pdo($config);
$pdo->exec('DROP TABLE IF EXISTS shops');
demo_migrate($pdo);
$tokens = new Tokens($config, $pdo);
$admin = new Admin($config, $tokens);
const SHOP = 'demo-shop.myshopify.com';

function http(string $method, string $url, ?string $body = null, array $headers = []): array {
    $ch = curl_init($url);
    $respHeaders = [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$respHeaders) { if (str_contains($h, ':')) { [$k, $v] = explode(':', $h, 2); $respHeaders[strtolower(trim($k))][] = trim($v); } return strlen($h); }]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $raw, $respHeaders];
}
function fake(string $op, array $args = []): array {
    global $fakeOrigin;
    return json_decode(http('POST', "$fakeOrigin/__test", json_encode(['op' => $op] + $args), ['Content-Type: application/json'])[1], true);
}
function row(string $shop = SHOP): ?array {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM shops WHERE shop = ?'); $st->execute([$shop]);
    return $st->fetch() ?: null;
}
function install(): array {
    global $tokens;
    $t = $tokens->codeGrant(SHOP, fake('code')['code']);
    $tokens->save(SHOP, $t);
    return $t;
}
function legacy(string $shop = SHOP): string {
    global $pdo;
    $t = fake('legacy')['token'];
    $pdo->prepare("INSERT INTO shops (shop, access_token, scope) VALUES (?, ?, 'read_products')")->execute([$shop, $t]);
    return $t;
}
function ok(bool $cond, string $msg = 'assertion failed'): void { if (!$cond) throw new RuntimeException($msg); }
function throws(callable $fn, string $class, string $pattern = '//'): void {
    try { $fn(); } catch (Throwable $e) { ok($e instanceof $class && preg_match($pattern, $e->getMessage()) === 1, 'wrong error: ' . get_class($e) . ' ' . $e->getMessage()); return; }
    throw new RuntimeException("expected $class");
}

$tests = [];
$tests['code grant with expiring=1 stores access token, refresh token and both expiries'] = function () {
    $t = install(); $r = row();
    ok($r['access_token'] === $t['access_token'] && $r['refresh_token'] === $t['refresh_token']);
    ok(strtotime($r['expires_at']) > time() + 3500 && strtotime($r['refresh_token_expires_at']) > time() + 89 * 86400);
};
$tests['a fresh token is used as is; an expiring one is refreshed and the row rotates'] = function () use ($tokens, $pdo) {
    $t = install(); $n = fake('calls')['refresh'];
    ok($tokens->ensureFresh(SHOP) === $t['access_token'] && fake('calls')['refresh'] === $n);
    $pdo->exec("UPDATE shops SET expires_at = now() - interval '1 minute'");
    $fresh = $tokens->ensureFresh(SHOP);
    ok($fresh !== $t['access_token'] && row()['access_token'] === $fresh && row()['refresh_token'] !== $t['refresh_token']);
    ok(fake('live', ['token' => $fresh])['live']);
};
$tests['race: 8 PHP processes on an expired token cause exactly 1 refresh and all succeed'] = function () use ($pdo, $root, $env) {
    install();
    $pdo->exec("UPDATE shops SET expires_at = now() - interval '1 minute'");
    fake('set', ['values' => ['refresh_delay_ms' => 200]]);
    $n = fake('calls')['refresh'];
    $at = sprintf('%.3f', microtime(true) + 0.8);
    $ps = [];
    for ($i = 0; $i < 8; $i++) $ps[] = proc_open([PHP_BINARY, 'tests/race-worker.php', SHOP, $at], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], $root, $env);
    $outs = [];
    foreach ($ps as $i => $p) { $outs[] = stream_get_contents($pipes[$i][1]); proc_close($p); }
    fake('set', ['values' => ['refresh_delay_ms' => 0]]);
    ok(fake('calls')['refresh'] - $n === 1, 'refreshes: ' . (fake('calls')['refresh'] - $n) . ' ' . implode(' | ', $outs));
    ok(count(array_filter($outs, fn ($o) => str_starts_with($o, 'OK '))) === 8, implode(' | ', $outs));
    ok(count(array_unique($outs)) === 1 && !row()['needs_reauth']);
};
$tests['control: the same race WITHOUT the lock spends the refresh token once and the rest get 401'] = function () use ($fakeOrigin) {
    $t = install();
    fake('set', ['values' => ['refresh_delay_ms' => 100]]);
    $mh = curl_multi_init(); $hs = [];
    for ($i = 0; $i < 8; $i++) {
        $h = curl_init("$fakeOrigin/admin/oauth/access_token");
        curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['client_id' => 'key', 'client_secret' => 'secret', 'grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token']])]);
        curl_multi_add_handle($mh, $h); $hs[] = $h;
    }
    do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running);
    $codes = array_map(fn ($h) => curl_getinfo($h, CURLINFO_HTTP_CODE), $hs);
    fake('set', ['values' => ['refresh_delay_ms' => 0]]);
    ok(count(array_filter($codes, fn ($c) => $c === 200)) === 1 && count(array_filter($codes, fn ($c) => $c === 401)) === 7, implode(',', $codes));
};
$tests['an expired refresh token marks the shop for re-auth, and the mark is committed'] = function () use ($tokens, $pdo) {
    install(); $n = fake('calls')['refresh'];
    $pdo->exec("UPDATE shops SET expires_at = now() - interval '1 minute', refresh_token_expires_at = now() - interval '1 second'");
    throws(fn () => $tokens->ensureFresh(SHOP), ReauthRequired::class, '/refresh token expired/');
    ok(row()['needs_reauth'] === true && fake('calls')['refresh'] === $n);
    throws(fn () => $tokens->ensureFresh(SHOP), ReauthRequired::class, '/refresh token is dead/');
};
$tests['a refresh token already spent elsewhere (401) marks the shop for re-auth'] = function () use ($tokens, $pdo) {
    $t = install();
    $tokens->refresh(SHOP, $t['refresh_token']);
    $pdo->exec("UPDATE shops SET expires_at = now() - interval '1 minute'");
    throws(fn () => $tokens->ensureFresh(SHOP), ReauthRequired::class, '/refresh rejected \(401\)/');
    ok(row()['needs_reauth'] === true);
};
$tests['Admin call retries once after a 401 by forcing a refresh'] = function () use ($admin) {
    $t = install();
    fake('expire', ['token' => $t['access_token']]);
    ok($admin->graphql(SHOP, '{ shop { name } }')['data']['products']['nodes'][0]['title'] === 'Demo');
    ok(row()['access_token'] !== $t['access_token']);
};
$tests['a shop not yet migrated keeps working on its non-expiring token'] = function () use ($tokens, $admin) {
    $l = legacy();
    ok($tokens->ensureFresh(SHOP) === $l);
    ok(count($admin->graphql(SHOP, '{ shop { name } }')['data']['products']['nodes']) === 1);
};
$quiet = fn (string $m) => null;
$tests['migrate dry run lists legacy shops and changes nothing'] = function () use ($pdo, $tokens, $quiet) {
    $l = legacy(); $n = fake('calls')['exchange'];
    $rep = migrate_tokens($pdo, $tokens, false, null, $quiet);
    ok($rep['candidates'] === [SHOP] && fake('calls')['exchange'] === $n && row()['access_token'] === $l);
};
$tests['migrate --apply exchanges, saves the pair, and the old token is dead'] = function () use ($pdo, $tokens, $admin, $quiet) {
    $l = legacy();
    $rep = migrate_tokens($pdo, $tokens, true, null, $quiet);
    ok($rep['exchanged'] === [SHOP]);
    $r = row();
    ok($r['refresh_token'] && $r['expires_at'] && $r['exchanged_at']);
    ok(!fake('live', ['token' => $l])['live']);
    ok(count($admin->graphql(SHOP, '{ shop { name } }')['data']['products']['nodes']) === 1);
};
$tests['migrate --shop limits the run; a second run skips migrated shops'] = function () use ($pdo, $tokens, $quiet) {
    legacy('a-shop.myshopify.com'); legacy('b-shop.myshopify.com');
    ok(migrate_tokens($pdo, $tokens, true, 'a-shop.myshopify.com', $quiet)['exchanged'] === ['a-shop.myshopify.com']);
    ok(migrate_tokens($pdo, $tokens, true, null, $quiet)['exchanged'] === ['b-shop.myshopify.com']);
    ok(migrate_tokens($pdo, $tokens, true, null, $quiet)['candidates'] === []);
};
$tests['lost response: the old token stays in the row and a re-run recovers the same pair'] = function () use ($pdo, $tokens, $admin, $quiet) {
    $l = legacy();
    fake('set', ['values' => ['drop_next_exchange' => true]]);
    ok(count(migrate_tokens($pdo, $tokens, true, null, $quiet)['failed']) === 1);
    ok(row()['access_token'] === $l && !fake('live', ['token' => $l])['live']);
    ok(migrate_tokens($pdo, $tokens, true, null, $quiet)['exchanged'] === [SHOP]);
    ok(count($admin->graphql(SHOP, '{ shop { name } }')['data']['products']['nodes']) === 1);
};
$app = "http://127.0.0.1:$appPort";
$sign = fn (array $p) => hash_hmac('sha256', implode('&', array_map(fn ($k) => "$k={$p[$k]}", (function ($p) { $k = array_keys($p); sort($k); return $k; })($p))), 'secret');
$tests['install over HTTP: /auth sets a state cookie, the signed callback stores an expiring pair'] = function () use ($app, $sign) {
    [$s, , $h] = http('GET', "$app/auth?shop=" . SHOP);
    ok($s === 302, "status $s");
    parse_str(parse_url($h['location'][0], PHP_URL_QUERY), $q);
    $cookie = explode(';', $h['set-cookie'][0])[0];
    $p = ['code' => fake('code')['code'], 'shop' => SHOP, 'state' => $q['state'], 'timestamp' => (string) time()];
    [$s2] = http('GET', "$app/auth/callback?" . http_build_query($p + ['hmac' => $sign($p)]), null, ["Cookie: $cookie"]);
    ok($s2 === 302, "callback status $s2");
    ok((bool) row()['refresh_token']);
};
$tests['callback with a bad hmac or no state cookie is refused'] = function () use ($app, $sign) {
    $p = ['code' => 'x', 'shop' => SHOP, 'state' => 's', 'timestamp' => '1'];
    ok(http('GET', "$app/auth/callback?" . http_build_query($p + ['hmac' => str_repeat('0', 64)]), null, ['Cookie: oauth_state=s'])[0] === 403);
    ok(http('GET', "$app/auth/callback?" . http_build_query($p + ['hmac' => $sign($p)]))[0] === 403);
};
$hook = fn (string $topic, string $raw, string $secret = 'secret') => http('POST', "$app/webhooks", $raw, ['Content-Type: application/json', "X-Shopify-Topic: $topic",
    'X-Shopify-Shop-Domain: ' . SHOP, 'X-Shopify-Hmac-Sha256: ' . base64_encode(hash_hmac('sha256', $raw, $secret, true))])[0];
$tests['webhook HMAC is checked on the raw bytes (spacing json_encode would change still verifies)'] = function () use ($hook) {
    install();
    $raw = '{"shop_id": 1,  "shop_domain": "demo-shop.myshopify.com"}';
    ok(json_encode(json_decode($raw, true)) !== $raw);
    ok($hook('shop/redact', $raw) === 200 && row() === null);
};
$tests['webhook with a wrong signature gets 401 and changes nothing'] = function () use ($hook) {
    install();
    ok($hook('app/uninstalled', '{}', 'wrong') === 401 && row() !== null);
};
$tests['/api/products answers 401 with a re-auth link once the refresh token is dead'] = function () use ($app, $pdo) {
    install();
    $pdo->exec('UPDATE shops SET needs_reauth = true');
    [$s, $b] = http('GET', "$app/api/products?shop=" . SHOP);
    ok($s === 401 && json_decode($b, true)['reauth'] === '/auth?shop=' . SHOP, "status $s");
};

$fail = 0;
foreach ($tests as $name => $fn) {
    $pdo->exec('DELETE FROM shops');
    try { $fn(); echo "ok   $name\n"; } catch (Throwable $e) { $fail++; echo "FAIL $name\n     " . $e->getMessage() . "\n"; }
}
echo "\n" . (count($tests) - $fail) . ' of ' . count($tests) . " passed\n";
exit($fail ? 1 : 0);
