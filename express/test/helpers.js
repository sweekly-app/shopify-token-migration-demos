import { createPool, migrate } from "../src/db.js";
import { createTokens } from "../src/tokens.js";
import { createAdmin } from "../src/admin.js";
import { startFakeShopify } from "./fake-shopify.js";

export const SHOP = "demo-shop.myshopify.com";

export async function setup() {
  const fake = await startFakeShopify({ apiKey: "key", apiSecret: "secret" });
  const config = {
    apiKey: "key", apiSecret: "secret", host: "https://app.example.com", scopes: "read_products", apiVersion: "2026-10",
    shopOrigin: () => fake.origin,
  };
  const pool = createPool(process.env.DATABASE_URL || "postgres://postgres@localhost:5432/demos_test");
  await pool.query("DROP TABLE IF EXISTS shops");
  await migrate(pool);
  const tokens = createTokens(config);
  const graphql = createAdmin(config, pool, tokens);
  return { fake, config, pool, tokens, graphql, async teardown() { await pool.end(); await fake.close(); } };
}

export async function insertLegacy(pool, fake, shop = SHOP) {
  const t = fake.issueLegacy();
  await pool.query("INSERT INTO shops (shop, access_token, scope) VALUES ($1, $2, 'read_products')", [shop, t]);
  return t;
}

export async function row(pool, shop = SHOP) {
  return (await pool.query("SELECT * FROM shops WHERE shop = $1", [shop])).rows[0];
}
