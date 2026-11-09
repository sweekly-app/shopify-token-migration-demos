<?php
declare(strict_types=1);

/** HMAC over the raw bytes Shopify signed (read php://input once, never re-encode it), compared in constant time. */
function valid_webhook_hmac(string $secret, string $rawBody, ?string $header): bool {
    if (!$header) return false;
    return hash_equals(base64_encode(hash_hmac('sha256', $rawBody, $secret, true)), $header);
}

function handle_webhook(PDO $pdo, string $topic, string $shop): void {
    switch ($topic) {
        case 'app/uninstalled':
        case 'shop/redact':
            $pdo->prepare('DELETE FROM shops WHERE shop = ?')->execute([$shop]);
            return;
        case 'customers/data_request':
        case 'customers/redact':
            return; // no customer data stored by this demo
    }
}
