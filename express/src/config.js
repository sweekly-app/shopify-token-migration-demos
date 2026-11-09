export function loadConfig(env = process.env) {
  const need = ["SHOPIFY_API_KEY", "SHOPIFY_API_SECRET", "HOST", "DATABASE_URL"];
  const missing = need.filter((k) => !env[k]);
  if (missing.length) throw new Error(`missing env: ${missing.join(", ")}`);
  return {
    apiKey: env.SHOPIFY_API_KEY,
    apiSecret: env.SHOPIFY_API_SECRET,
    host: env.HOST.replace(/\/$/, ""),
    databaseUrl: env.DATABASE_URL,
    scopes: "read_products",
    apiVersion: "2026-10",
    // Where a shop's endpoints live. Tests point this at a local fake; production always uses https://{shop}.
    shopOrigin: (shop) => `https://${shop}`,
  };
}

export const SHOP_RE = /^[a-z0-9][a-z0-9-]*\.myshopify\.com$/;
