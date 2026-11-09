import { ensureFreshToken } from "./tokens.js";

export class AdminApiError extends Error {
  constructor(status, body) {
    super(`Admin API ${status}`);
    this.status = status;
    this.body = body;
  }
}

// Admin GraphQL call with a fresh token. On a 401 it forces one refresh and retries once.
// A 403 is not retried: it can mean a missing scope, so read the message before sending the merchant to re-auth.
export function createAdmin(config, pool, tokens, { fetchImpl = fetch } = {}) {
  async function call(shop, token, query, variables) {
    const res = await fetchImpl(`${config.shopOrigin(shop)}/admin/api/${config.apiVersion}/graphql.json`, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Shopify-Access-Token": token },
      body: JSON.stringify({ query, variables }),
      signal: AbortSignal.timeout(30_000),
    });
    const body = await res.json().catch(() => null);
    return { status: res.status, body };
  }
  return async function graphql(shop, query, variables) {
    const token = await ensureFreshToken(pool, tokens, shop);
    let r = await call(shop, token, query, variables);
    if (r.status === 401) r = await call(shop, await ensureFreshToken(pool, tokens, shop, { force: true, staleToken: token }), query, variables);
    if (r.status !== 200) throw new AdminApiError(r.status, r.body);
    return r.body;
  };
}
