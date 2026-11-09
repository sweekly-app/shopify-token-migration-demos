<?php
declare(strict_types=1);

final class AdminApiError extends RuntimeException {
    public function __construct(public readonly int $status, public readonly mixed $body) {
        parent::__construct("Admin API $status");
    }
}

/** Admin GraphQL with a fresh token; one forced refresh and retry on 401. A 403 is not retried (it can be a missing scope). */
final class Admin {
    public function __construct(private array $config, private Tokens $tokens) {}

    private function call(string $shop, string $token, string $query, array $variables): array {
        $ch = curl_init(($this->config['shop_origin'])($shop) . '/admin/api/' . $this->config['api_version'] . '/graphql.json');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Shopify-Access-Token: ' . $token],
            CURLOPT_POSTFIELDS => json_encode(['query' => $query, 'variables' => (object) $variables]),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, $raw === false ? null : json_decode($raw, true)];
    }

    public function graphql(string $shop, string $query, array $variables = []): array {
        $token = $this->tokens->ensureFresh($shop);
        [$status, $body] = $this->call($shop, $token, $query, $variables);
        if ($status === 401) [$status, $body] = $this->call($shop, $this->tokens->ensureFresh($shop, true, $token), $query, $variables);
        if ($status !== 200) throw new AdminApiError($status, $body);
        return $body;
    }
}
