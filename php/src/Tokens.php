<?php
declare(strict_types=1);

// Expiring offline tokens: code grant, refresh, one-time exchange, and a per-shop row lock.
// Request shapes follow Shopify's own library (@shopify/shopify-api 15.x): JSON POST to /admin/oauth/access_token.
// Access tokens last about 1 hour; refresh tokens about 90 days and are single use.

final class ShopifyTokenError extends RuntimeException {
    public function __construct(public readonly int $status, public readonly mixed $body) {
        parent::__construct("token request failed: $status " . (is_string($body) ? $body : json_encode($body)));
    }
}

final class ReauthRequired extends RuntimeException {}

final class Tokens {
    public const TOKEN_EXCHANGE_GRANT = 'urn:ietf:params:oauth:grant-type:token-exchange';
    public const OFFLINE_TOKEN_TYPE = 'urn:shopify:params:oauth:token-type:offline-access-token';
    private const REFRESH_MARGIN = 300; // seconds before expiry

    public function __construct(private array $config, private PDO $pdo) {}

    private function post(string $shop, array $fields): array {
        $url = ($this->config['shop_origin'])($shop) . '/admin/oauth/access_token';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['client_id' => $this->config['api_key'], 'client_secret' => $this->config['api_secret']] + $fields),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new ShopifyTokenError(0, "network: $err");
        $body = json_decode($raw, true) ?? $raw;
        if ($status < 200 || $status >= 300) throw new ShopifyTokenError($status, $body);
        if (!is_array($body) || empty($body['access_token'])) throw new ShopifyTokenError($status, 'no access_token in response');
        return $body;
    }

    /** Code grant at install. expiring=1 is the change that matters. */
    public function codeGrant(string $shop, string $code): array {
        return $this->post($shop, ['code' => $code, 'expiring' => '1']);
    }

    public function refresh(string $shop, string $refreshToken): array {
        return $this->post($shop, ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /** One-time and irreversible. The same request within 7 days returns the same pair (until refreshed). */
    public function exchangeLegacy(string $shop, string $legacyToken): array {
        return $this->post($shop, [
            'grant_type' => self::TOKEN_EXCHANGE_GRANT,
            'subject_token' => $legacyToken,
            'subject_token_type' => self::OFFLINE_TOKEN_TYPE,
            'requested_token_type' => self::OFFLINE_TOKEN_TYPE,
            'expiring' => '1',
        ]);
    }

    public function save(string $shop, array $t, bool $exchanged = false): void {
        $now = time();
        $iso = fn (?int $s) => $s ? gmdate('Y-m-d\TH:i:s\Z', $now + $s) : null;
        $st = $this->pdo->prepare(
            'INSERT INTO shops (shop, access_token, scope, expires_at, refresh_token, refresh_token_expires_at, needs_reauth, exchanged_at)
             VALUES (:shop, :at, :scope, :exp, :rt, :rexp, false, CASE WHEN :ex1 THEN now() END)
             ON CONFLICT (shop) DO UPDATE SET access_token = EXCLUDED.access_token, scope = COALESCE(EXCLUDED.scope, shops.scope),
               expires_at = EXCLUDED.expires_at, refresh_token = EXCLUDED.refresh_token,
               refresh_token_expires_at = EXCLUDED.refresh_token_expires_at, needs_reauth = false,
               exchanged_at = COALESCE(CASE WHEN :ex2 THEN now() END, shops.exchanged_at)'
        );
        $st->bindValue(':shop', $shop);
        $st->bindValue(':at', $t['access_token']);
        $st->bindValue(':scope', $t['scope'] ?? null);
        $st->bindValue(':exp', $iso($t['expires_in'] ?? null));
        $st->bindValue(':rt', $t['refresh_token'] ?? null);
        $st->bindValue(':rexp', $iso($t['refresh_token_expires_in'] ?? null));
        $st->bindValue(':ex1', $exchanged, PDO::PARAM_BOOL);
        $st->bindValue(':ex2', $exchanged, PDO::PARAM_BOOL);
        $st->execute();
    }

    /**
     * Runs $fn($row) in a transaction holding SELECT ... FOR UPDATE on the shop's row (Postgres; MySQL InnoDB has the same row lock but needs its own upsert syntax).
     * Every process (web, cron, workers, scripts) that refreshes or replaces a shop's token goes through here,
     * so two processes never spend the same single-use refresh token. $fn returns ['token' => ...] or ['reauth' => why].
     */
    public function withShopLock(string $shop, callable $fn): mixed {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("SET LOCAL lock_timeout = '15s'"); // curl times out at 10 s; waiters give up instead of piling up
            $st = $this->pdo->prepare('SELECT * FROM shops WHERE shop = ? FOR UPDATE');
            $st->execute([$shop]);
            $out = $fn($st->fetch() ?: null);
            $this->pdo->commit();
            return $out;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * A usable access token, refreshed when it expires within 5 minutes. $force (after a 401) refreshes even a fresh-looking
     * row, unless the row no longer holds $staleToken (another process already refreshed: its token is returned).
     */
    public function ensureFresh(string $shop, bool $force = false, ?string $staleToken = null): string {
        $out = $this->withShopLock($shop, function (?array $row) use ($shop, $force, $staleToken) {
            if (!$row) return ['reauth' => 'not installed'];
            if ($row['needs_reauth']) return ['reauth' => 'refresh token is dead'];
            if (!$row['refresh_token']) return ['token' => $row['access_token']]; // not migrated yet
            $expires = $row['expires_at'] ? strtotime($row['expires_at']) : 0;
            $mustRefresh = $force && ($staleToken === null || $row['access_token'] === $staleToken);
            if (!$mustRefresh && $expires - time() > self::REFRESH_MARGIN) return ['token' => $row['access_token']];
            $markDead = function (string $why) use ($shop) {
                $this->pdo->prepare('UPDATE shops SET needs_reauth = true WHERE shop = ?')->execute([$shop]);
                return ['reauth' => $why];
            };
            if ($row['refresh_token_expires_at'] && strtotime($row['refresh_token_expires_at']) <= time()) return $markDead('refresh token expired');
            try {
                $t = $this->refresh($shop, $row['refresh_token']);
            } catch (ShopifyTokenError $e) {
                // 400/401: the refresh token was already used or revoked. Anything else is transient: keep the row.
                if ($e->status === 400 || $e->status === 401) return $markDead("refresh rejected ({$e->status})");
                throw $e;
            }
            $this->save($shop, $t);
            return ['token' => $t['access_token']];
        });
        if (isset($out['reauth'])) throw new ReauthRequired("$shop must reopen the app: {$out['reauth']}");
        return $out['token'];
    }
}
