#!/usr/bin/env bash
# Runs offline-token-check on all four apps: each "before" app must FAIL, each migrated app must pass.
# OFFLINE_TOKEN_CHECK=/path/to/offline-token-check/bin/cli.js overrides the npm package.
set -u
cd "$(dirname "$0")"
check() { if [ -n "${OFFLINE_TOKEN_CHECK:-}" ]; then node "$OFFLINE_TOKEN_CHECK" "$1"; else npx -y offline-token-check "$1"; fi; }
status=0
for d in express-old php-old; do
  if check "$d" >/dev/null; then echo "UNEXPECTED: $d passes"; status=1; else echo "ok: $d fails ($(check "$d" | tail -1))"; fi
done
for d in express php; do
  if check "$d" >/dev/null; then echo "ok: $d passes ($(check "$d" | tail -1))"; else echo "UNEXPECTED: $d fails"; check "$d"; status=1; fi
done
exit $status
