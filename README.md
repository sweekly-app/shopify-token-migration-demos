# Shopify expiring offline token migration: two demo apps

Two small Shopify apps, each in a **before** and an **after** version. Before: the hand-rolled code many small apps still run on non-expiring offline tokens. After: the same app migrated to expiring offline tokens (refresh tokens, a per-shop lock, a one-time exchange of existing shops). MIT licensed, nothing secret in them.

| Folder | Stack | Checker (`offline-token-check`) | Tests |
|---|---|---|---|
| `express-old/` | Express + `pg`, hand-rolled OAuth | 5 FAIL, 1 WARN | none (it is the bug list) |
| `express/` | same, migrated | 0 FAIL, 0 WARN | 18, `npm test` |
| `php-old/` | plain PHP + PDO, no framework, no package | 5 FAIL, 1 WARN | none |
| `php/` | same, migrated | 0 FAIL, 0 WARN | 17, `php tests/run.php` |

`./check-all.sh` runs the checker on all four and exits 1 unless both "before" apps fail and both "after" apps pass.

## What the migration changes (same in both stacks)

1. **Expiring grant:** the install's code grant sends `expiring: "1"`. The callback refuses a response with no refresh token.
2. **Storage:** additive migration `sql/002_expiring_tokens.sql`: `expires_at`, `refresh_token`, `refresh_token_expires_at`, `needs_reauth`, `exchanged_at`. Old rows keep working; `refresh_token IS NULL` marks a shop that still holds a non-expiring token.
3. **Per-shop lock:** every read-refresh-save of a shop's token runs in one transaction holding a per-shop lock. Express: `pg_advisory_xact_lock(hashtext('shop-token:' || shop))`. PHP: `SELECT ... FOR UPDATE` on the shop's row (the demo is Postgres; MySQL InnoDB has the same lock but needs its own upsert). Web requests, workers, cron jobs, the install callback (Express), uninstall and redact webhooks (Express) and the migration script all go through it. Token requests time out at 10 s, below the 15 s `lock_timeout`.
4. **Fresh token before each Admin call:** refresh when the token expires within 5 minutes; on a 401, force one refresh (skipped if another process already replaced that token) and retry once. A 403 is not retried (it can be a missing scope). A dead refresh token (expired, or already spent) sets `needs_reauth`, **commits it**, and the route answers 401 with a re-auth link.
5. **One-time exchange of existing shops:** `migrate-tokens` (dry run by default, `--apply`, `--shop <one test shop>` first; `--shop` with no valid value refuses to run). Under the lock it re-reads the row, exchanges the old token (`urn:ietf:params:oauth:grant-type:token-exchange`, both token types `urn:shopify:params:oauth:token-type:offline-access-token`, `expiring: "1"`), saves the new pair, and only then is the old token gone from the row. A lost response or failed save leaves the old token in place; a re-run within 7 days gets the same pair back. Exit code 1 if any shop failed.
6. **Webhooks:** HMAC over the raw body (Express: `express.raw()` on the route; PHP: `php://input` once), constant-time comparison, 401 on a bad signature. The "before" versions hash a re-encoded body, which breaks on any spacing or key order Shopify sends.
7. **Callback:** state in an HttpOnly cookie (not a server-memory map or PHP session), query HMAC compared in constant time, shop domain validated.
8. **`[network_access]`** declared in `shopify.app.toml` (October 2026 security update).

Request shapes were checked against Shopify's own library, `@shopify/shopify-api` 15.1.1 (`lib/auth/oauth/refresh-token`, `migrate-to-expiring-token`, `oauth`): JSON POST to `https://{shop}/admin/oauth/access_token` with `client_id` and `client_secret`.

## What the tests prove

Both suites run against a local fake of Shopify's token endpoints that enforces the rules that matter: refresh tokens are single use, the legacy exchange is one-time and kills the old token, the same exchange within 7 days returns the same pair until it is refreshed, an expired access token gets 401 on the Admin API.

- Install stores a pair with both expiries; a fresh token is reused; an expiring one rotates the row.
- **Race:** 8 concurrent callers (Express: 8 pool connections; PHP: 8 separate processes) on one expired token make exactly **1** refresh and all succeed. Control test: the same 8 calls without the lock give 1 success and 7 × 401. Removing the lock line from the app code makes the race test fail with 8 refreshes and 7 merchants sent to re-auth (checked by hand on 2026-11-08).
- Expired refresh token and already-spent refresh token both set `needs_reauth`, and it stays set.
- Admin call retries once after a 401.
- A shop not yet migrated keeps working on its old token.
- Migration: dry run changes nothing; `--apply` exchanges and the old token is dead; `--shop` limits the run; re-runs skip migrated shops; **lost response** keeps the old token in the row and the re-run recovers the same pair.
- HTTP: signed install callback stores the pair; bad HMAC or missing state cookie gets 403; a webhook whose raw body has spacing that re-encoding would change still verifies; a wrong signature gets 401 and changes nothing; a dead refresh token gives `401 {reauth}`.

Not covered by tests: a save that fails *after* a successful refresh (the old refresh token is spent and the new pair is lost; the window is one INSERT inside the same transaction; the merchant reopens the app), and the client-credentials fallback (not built).

## Run

```
# Postgres 16 on localhost
psql -h localhost -U postgres -c "create database demos_test" -c "create database demos_php_test"
cd express && npm install && npm run lint && npm test
cd ../php && composer lint   # or: for f in src/*.php public/*.php scripts/*.php tests/*.php; do php -l $f; done
php tests/run.php
./check-all.sh   # needs Node 18+; see the script for a local checker path
```

Running an app for real: set `SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET`, `HOST`, and `DATABASE_URL` (Express) or `DATABASE_DSN` (PHP, a PDO DSN), replace the `REPLACE_WITH_*` values in `shopify.app.toml`, run `npm run db:migrate` / `php scripts/db-migrate.php`, then `npm start` / `php -S 0.0.0.0:3000 -t public public/index.php` (PHP: use a real web server in production). The PHP test hook `SHOP_ORIGIN_FOR_TESTS` is ignored unless `APP_ENV=test`.

## Live run on a development store (2026-11-09)

Both apps were created in Shopify's Dev Dashboard in November 2026, hosted, and installed on a development store with the "before" code (the code grant does not send `expiring=1`). Then each was switched to its migrated version on the same database rows. Times are UTC; no token values were printed or stored outside the app database.

**Does a new app still get a non-expiring offline token? Yes, as of 2026-11-09.** The stored token (38 characters, prefix `shpua_`, scope `read_products`) answered `200` on the Admin API (`{ shop { name } }`, version 2026-10) 68 minutes (PHP) and 69 minutes (Express) after install. An app that does not ask for expiring tokens still receives one that does not expire.

**The prefix does not tell you which kind you hold.** The non-expiring token and the expiring tokens issued later both start with `shpua_`; refresh tokens start with `shprt_`. Check your database for a refresh token and an expiry, not the token prefix.

| Step | Express | PHP |
|---|---|---|
| Switch to the migrated app (additive migration at start) | ok, old row untouched | ok, old row untouched |
| `migrate-tokens` dry run | 1 shop listed, nothing changed | 1 shop listed, nothing changed |
| `migrate-tokens --apply --shop <store>` | 1 exchanged, 0 failed | 1 exchanged, 0 failed |
| Row after the exchange | refresh token set; access token expires in 60 min, refresh token in 90 days | same |
| Admin call with the fresh token | 200 | 200 |
| Access token forced to expire (`expires_at` one minute in the past), next admin call | 200; access and refresh token both replaced, new expiry | 200; both replaced, new expiry |

**Shopify CLI 5.0.1.** `shopify app deploy --allow-updates` with a service-account token (no personal login) released the Express app's configuration. `shopify app security check`: no deterministic findings in either app's source, one low finding for the Express folder (`MISSING_DEPENDENCY_SECURITY_AUTOMATION`: no repository-level dependency update configuration). Note for anyone relying on it: the check's deterministic source rules, including `EXPIRING_OFFLINE_TOKEN`, only analyse apps built on `@shopify/shopify-app-react-router`. For Express and PHP apps it scans the configuration and hands 36 checks to a coding agent; it cannot tell you by itself whether your app has migrated. The tests in this repository and the steps above can.

## Licence and contact

MIT, Copyright (c) 2026 Sweekly Apps LLC. Questions: support@sweekly.app.
