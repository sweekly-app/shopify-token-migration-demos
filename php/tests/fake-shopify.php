<?php
// Router for `php -S`: a local stand-in for the Shopify endpoints with the token rules that matter
// (single-use refresh tokens, one-time legacy exchange, same pair within 7 days). State lives in a JSON file under flock.
declare(strict_types=1);

$stateFile = getenv('FAKE_STATE');
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
const XG = 'urn:ietf:params:oauth:grant-type:token-exchange';
const OFF = 'urn:shopify:params:oauth:token-type:offline-access-token';

function out(int $status, array $b): never { http_response_code($status); header('Content-Type: application/json'); echo json_encode($b); exit; }
function rid(string $p): string { return $p . '_' . bin2hex(random_bytes(8)); }

// Widen the race window before taking the state lock, so concurrent refreshes really overlap.
if ($uri === '/admin/oauth/access_token' && ($body['grant_type'] ?? '') === 'refresh_token') {
    $peek = json_decode((string) @file_get_contents($stateFile), true) ?: [];
    if (!empty($peek['refresh_delay_ms'])) usleep($peek['refresh_delay_ms'] * 1000);
}

$fh = fopen($stateFile, 'c+');
flock($fh, LOCK_EX);
$S = json_decode(stream_get_contents($fh) ?: '', true) ?: ['access' => [], 'refresh' => [], 'exchanged' => [], 'codes' => [], 'calls' => ['refresh' => 0, 'exchange' => 0, 'graphql' => 0], 'refresh_delay_ms' => 0, 'drop_next_exchange' => false];
$save = function () use ($fh, &$S) { ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($S)); fflush($fh); flock($fh, LOCK_UN); };
$pair = function () use (&$S) {
    $a = rid('shpat'); $r = rid('shprt');
    $S['access'][$a] = ['exp' => time() + 3600, 'legacy' => false];
    $S['refresh'][$r] = ['used' => false, 'exp' => time() + 90 * 86400];
    return ['access_token' => $a, 'scope' => 'read_products', 'expires_in' => 3600, 'refresh_token' => $r, 'refresh_token_expires_in' => 90 * 86400];
};
$live = fn ($t) => isset($S['access'][$t]) && ($S['access'][$t]['exp'] === null || $S['access'][$t]['exp'] > time());
$reply = function (int $status, array $b) use ($save) { $save(); out($status, $b); };

if ($uri === '/__test') { // helpers for the test runner
    switch ($body['op']) {
        case 'code': $c = rid('code'); $S['codes'][$c] = true; $reply(200, ['code' => $c]);
        case 'legacy': $t = rid('shpat_legacy'); $S['access'][$t] = ['exp' => null, 'legacy' => true]; $reply(200, ['token' => $t]);
        case 'expire': if (isset($S['access'][$body['token']])) $S['access'][$body['token']]['exp'] = time() - 1; $reply(200, []);
        case 'live': $reply(200, ['live' => $live($body['token'])]);
        case 'set': foreach ($body['values'] as $k => $v) $S[$k] = $v; $reply(200, []);
        case 'calls': $reply(200, $S['calls']);
    }
}
if ($uri === '/admin/oauth/access_token') {
    if (($body['client_id'] ?? '') !== 'key' || ($body['client_secret'] ?? '') !== 'secret') $reply(401, ['error' => 'invalid_client']);
    if (isset($body['code'])) {
        if (empty($S['codes'][$body['code']])) $reply(400, ['error' => 'invalid_request']);
        unset($S['codes'][$body['code']]);
        if (($body['expiring'] ?? '') === '1') $reply(200, $pair());
        $t = rid('shpat_legacy'); $S['access'][$t] = ['exp' => null, 'legacy' => true];
        $reply(200, ['access_token' => $t, 'scope' => 'read_products']);
    }
    if (($body['grant_type'] ?? '') === 'refresh_token') {
        $S['calls']['refresh']++;
        $r = $S['refresh'][$body['refresh_token'] ?? ''] ?? null;
        if (!$r || $r['used'] || $r['exp'] <= time()) $reply(401, ['error' => 'invalid_request']);
        $S['refresh'][$body['refresh_token']]['used'] = true;
        foreach ($S['exchanged'] as $k => $e) if ($e['pair']['refresh_token'] === $body['refresh_token']) $S['exchanged'][$k]['refreshed'] = true;
        $reply(200, $pair());
    }
    if (($body['grant_type'] ?? '') === XG && ($body['subject_token_type'] ?? '') === OFF && ($body['requested_token_type'] ?? '') === OFF && ($body['expiring'] ?? '') === '1') {
        $S['calls']['exchange']++;
        $sub = $body['subject_token'] ?? '';
        if (isset($S['exchanged'][$sub])) {
            $e = $S['exchanged'][$sub];
            if ($e['refreshed'] || time() - $e['at'] > 7 * 86400) $reply(400, ['error' => 'invalid_subject_token']);
            $res = $e['pair'];
        } else {
            if (empty($S['access'][$sub]['legacy'])) $reply(400, ['error' => 'invalid_subject_token']);
            unset($S['access'][$sub]);
            $res = $pair();
            $S['exchanged'][$sub] = ['pair' => $res, 'at' => time(), 'refreshed' => false];
        }
        if ($S['drop_next_exchange']) { $S['drop_next_exchange'] = false; $reply(502, ['error' => 'lost']); }
        $reply(200, $res);
    }
    $reply(400, ['error' => 'unsupported_grant_type']);
}
if (preg_match('#^/admin/api/[\w-]+/graphql\.json$#', $uri)) {
    $S['calls']['graphql']++;
    if (!$live($_SERVER['HTTP_X_SHOPIFY_ACCESS_TOKEN'] ?? '')) $reply(401, ['errors' => '[API] Invalid API key or access token']);
    $reply(200, ['data' => ['products' => ['nodes' => [['id' => 'gid://shopify/Product/1', 'title' => 'Demo']]]]]);
}
$reply(404, ['error' => 'not found']);
