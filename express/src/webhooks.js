import crypto from "node:crypto";
import { withShopLock } from "./tokens.js";

// HMAC over the raw bytes Shopify signed. The route must use express.raw(), never express.json().
export function validWebhookHmac(secret, rawBody, header) {
  if (typeof header !== "string" || !Buffer.isBuffer(rawBody)) return false;
  const digest = crypto.createHmac("sha256", secret).update(rawBody).digest();
  const given = Buffer.from(header, "base64");
  return given.length === digest.length && crypto.timingSafeEqual(given, digest);
}

export async function handleWebhook(pool, topic, shop) {
  switch (topic) {
    case "app/uninstalled":
    case "shop/redact":
      // The token is useless after uninstall; shop/redact (48 h later) must leave nothing.
      // Under the shop lock, so a refresh in flight can't re-insert the row after the delete.
      await withShopLock(pool, shop, (db) => db.query("DELETE FROM shops WHERE shop = $1", [shop]));
      return;
    case "customers/data_request":
    case "customers/redact":
      // This demo stores no customer data, so there is nothing to export or delete.
      return;
    default:
      return;
  }
}
