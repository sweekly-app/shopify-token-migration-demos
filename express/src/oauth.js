import crypto from "node:crypto";

export function authorizeUrl(config, shop, state) {
  const q = new URLSearchParams({ client_id: config.apiKey, scope: config.scopes, redirect_uri: `${config.host}/auth/callback`, state });
  return `${config.shopOrigin(shop)}/admin/oauth/authorize?${q}`;
}

// Shopify signs the callback query: every parameter except hmac (and signature), sorted, joined as k=v&k=v.
export function validQueryHmac(secret, query) {
  const { hmac, signature, ...rest } = query;
  if (typeof hmac !== "string" || !/^[0-9a-f]{64}$/i.test(hmac)) return false;
  const message = Object.keys(rest).sort().map((k) => `${k}=${Array.isArray(rest[k]) ? rest[k].join(",") : rest[k]}`).join("&");
  const digest = crypto.createHmac("sha256", secret).update(message).digest("hex");
  return crypto.timingSafeEqual(Buffer.from(digest, "hex"), Buffer.from(hmac, "hex"));
}
