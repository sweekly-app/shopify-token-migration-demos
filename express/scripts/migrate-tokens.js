// Usage: node scripts/migrate-tokens.js [--apply] [--shop <shop>.myshopify.com]
// Without --apply it only lists the shops it would exchange.
import { loadConfig, SHOP_RE } from "../src/config.js";
import { createPool } from "../src/db.js";
import { createTokens } from "../src/tokens.js";
import { migrateTokens } from "../src/migrate-tokens.js";

const args = process.argv.slice(2);
const apply = args.includes("--apply");
const i = args.indexOf("--shop");
const shop = i >= 0 ? args[i + 1] : null;
if (i >= 0 && !SHOP_RE.test(shop || "")) {
  console.error("--shop needs a value like my-store.myshopify.com (refusing to run on every shop)");
  process.exit(2);
}
const config = loadConfig();
const pool = createPool(config.databaseUrl);
const report = await migrateTokens(pool, createTokens(config), { apply, shop });
await pool.end();
process.exitCode = report.failed.length ? 1 : 0;
