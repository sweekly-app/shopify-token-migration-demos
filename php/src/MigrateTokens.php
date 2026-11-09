<?php
declare(strict_types=1);

/**
 * Exchanges every shop that still holds a non-expiring token (refresh_token IS NULL). Safe to re-run:
 * migrated shops are skipped, and the old token stays in the row until the new pair is saved. If Shopify answered
 * but the save failed (or the response was lost), re-running within 7 days returns the same pair.
 */
function migrate_tokens(PDO $pdo, Tokens $tokens, bool $apply = false, ?string $only = null, ?callable $log = null): array {
    $log ??= fn (string $m) => fwrite(STDOUT, $m . PHP_EOL);
    $st = $pdo->prepare('SELECT shop FROM shops WHERE refresh_token IS NULL AND needs_reauth = false AND (CAST(:only AS TEXT) IS NULL OR shop = :only2) ORDER BY shop');
    $st->execute([':only' => $only, ':only2' => $only]);
    $shops = $st->fetchAll(PDO::FETCH_COLUMN);
    $report = ['candidates' => $shops, 'exchanged' => [], 'skipped' => [], 'failed' => []];
    if (!$apply) {
        foreach ($shops as $s) $log("dry-run: would exchange $s");
        $log('dry-run: ' . count($shops) . ' shop(s). Re-run with --apply (try --shop <one test shop> first).');
        return $report;
    }
    foreach ($shops as $s) {
        try {
            $tokens->withShopLock($s, function (?array $row) use ($s, $tokens, &$report, $log) {
                if (!$row || $row['refresh_token']) { $report['skipped'][] = $s; return null; }
                $t = $tokens->exchangeLegacy($s, $row['access_token']);
                if (empty($t['refresh_token'])) throw new RuntimeException('exchange answered without a refresh_token');
                $tokens->save($s, $t, true);
                $report['exchanged'][] = $s;
                $log("exchanged $s");
                return null;
            });
        } catch (Throwable $e) {
            $why = $e instanceof ShopifyTokenError ? "HTTP {$e->status} " . json_encode($e->body) : $e->getMessage();
            $report['failed'][] = ['shop' => $s, 'why' => $why];
            $log("FAILED $s: $why. Old token kept; re-run within 7 days to recover the same pair.");
        }
    }
    $log(sprintf('done: %d exchanged, %d skipped, %d failed', count($report['exchanged']), count($report['skipped']), count($report['failed'])));
    return $report;
}
