<?php
declare(strict_types=1);

function authorize_url(array $config, string $shop, string $state): string {
    $q = http_build_query(['client_id' => $config['api_key'], 'scope' => $config['scopes'], 'redirect_uri' => $config['host'] . '/auth/callback', 'state' => $state]);
    return ($config['shop_origin'])($shop) . '/admin/oauth/authorize?' . $q;
}

/** Shopify signs the callback query: every parameter except hmac and signature, sorted, joined as k=v&k=v (values not re-encoded). */
function valid_query_hmac(string $secret, array $query): bool {
    $hmac = $query['hmac'] ?? '';
    if (!is_string($hmac) || !preg_match('/^[0-9a-f]{64}$/i', $hmac)) return false;
    unset($query['hmac'], $query['signature']);
    ksort($query);
    $pairs = [];
    foreach ($query as $k => $v) $pairs[] = $k . '=' . (is_array($v) ? implode(',', $v) : $v);
    return hash_equals(hash_hmac('sha256', implode('&', $pairs), $secret), strtolower($hmac));
}
