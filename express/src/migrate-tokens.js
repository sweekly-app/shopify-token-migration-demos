import { withShopLock, saveTokens, ShopifyTokenError } from "./tokens.js";

// Exchanges every shop that still holds a non-expiring token (refresh_token IS NULL).
// Safe to re-run: migrated shops are skipped, and the old token stays in the row until the new pair is saved.
// If Shopify answered but the save failed (or the response was lost), re-running within 7 days returns the same pair.
export async function migrateTokens(pool, tokens, { apply = false, shop = null, log = console.log } = {}) {
  const { rows } = await pool.query(
    "SELECT shop FROM shops WHERE refresh_token IS NULL AND needs_reauth = false AND ($1::text IS NULL OR shop = $1) ORDER BY shop",
    [shop],
  );
  const report = { candidates: rows.map((r) => r.shop), exchanged: [], failed: [], skipped: [] };
  if (!apply) {
    for (const r of rows) log(`dry-run: would exchange ${r.shop}`);
    log(`dry-run: ${rows.length} shop(s). Re-run with --apply (try --shop <one test shop> first).`);
    return report;
  }
  for (const { shop: s } of rows) {
    try {
      await withShopLock(pool, s, async (db) => {
        // Re-read under the lock: another process may have migrated this shop meanwhile.
        const { rows: cur } = await db.query("SELECT access_token, refresh_token FROM shops WHERE shop = $1", [s]);
        if (!cur[0] || cur[0].refresh_token) { report.skipped.push(s); return; }
        const t = await tokens.exchangeLegacy(s, cur[0].access_token);
        if (!t.refresh_token) throw new Error("exchange answered without a refresh_token");
        await saveTokens(db, s, t, { exchanged: true });
        report.exchanged.push(s);
        log(`exchanged ${s}`);
      });
    } catch (e) {
      const why = e instanceof ShopifyTokenError ? `HTTP ${e.status} ${JSON.stringify(e.body)}` : e.message;
      report.failed.push({ shop: s, why });
      log(`FAILED ${s}: ${why}. Old token kept; re-run within 7 days to recover the same pair.`);
    }
  }
  log(`done: ${report.exchanged.length} exchanged, ${report.skipped.length} skipped, ${report.failed.length} failed`);
  return report;
}
