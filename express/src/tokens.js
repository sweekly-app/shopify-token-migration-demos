// Expiring offline tokens: code grant, refresh, one-time exchange, and a per-shop lock.
// Request shapes follow @shopify/shopify-api 15.x (lib/auth/oauth/*): JSON POST to /admin/oauth/access_token.
// Access tokens last about 1 hour; refresh tokens about 90 days and are single use.

export const TOKEN_EXCHANGE_GRANT = "urn:ietf:params:oauth:grant-type:token-exchange";
export const OFFLINE_TOKEN_TYPE = "urn:shopify:params:oauth:token-type:offline-access-token";
const REFRESH_MARGIN_MS = 5 * 60 * 1000; // refresh 5 minutes before expiry

export class ShopifyTokenError extends Error {
  constructor(status, body) {
    super(`token request failed: ${status} ${typeof body === "string" ? body : JSON.stringify(body)}`);
    this.status = status;
    this.body = body;
  }
}

export class ReauthRequired extends Error {
  constructor(shop, why) {
    super(`${shop} must reopen the app: ${why}`);
    this.shop = shop;
  }
}

export function createTokens(config, { fetchImpl = fetch } = {}) {
  async function post(shop, fields) {
    const res = await fetchImpl(`${config.shopOrigin(shop)}/admin/oauth/access_token`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ client_id: config.apiKey, client_secret: config.apiSecret, ...fields }),
      // Below lock_timeout (15 s): a hung request must not hold the shop lock and a pool connection for long.
      signal: AbortSignal.timeout(10_000),
    });
    const text = await res.text();
    let body;
    try { body = JSON.parse(text); } catch { body = text; }
    if (!res.ok) throw new ShopifyTokenError(res.status, body);
    if (!body || !body.access_token) throw new ShopifyTokenError(res.status, "no access_token in response");
    return body;
  }

  return {
    // Code grant at install. expiring=1 is the change that matters.
    codeGrant: (shop, code) => post(shop, { code, expiring: "1" }),
    refresh: (shop, refreshToken) => post(shop, { grant_type: "refresh_token", refresh_token: refreshToken }),
    // One-time and irreversible: the old token stops working once Shopify answers.
    // The same request within 7 days returns the same pair (until it is refreshed), which is how a lost response is recovered.
    exchangeLegacy: (shop, legacyToken) => post(shop, {
      grant_type: TOKEN_EXCHANGE_GRANT,
      subject_token: legacyToken,
      subject_token_type: OFFLINE_TOKEN_TYPE,
      requested_token_type: OFFLINE_TOKEN_TYPE,
      expiring: "1",
    }),
  };
}

export function rowFromResponse(t, now = Date.now()) {
  return {
    access_token: t.access_token,
    scope: t.scope ?? null,
    expires_at: t.expires_in ? new Date(now + t.expires_in * 1000) : null,
    refresh_token: t.refresh_token ?? null,
    refresh_token_expires_at: t.refresh_token_expires_in ? new Date(now + t.refresh_token_expires_in * 1000) : null,
  };
}

export async function saveTokens(db, shop, t, { exchanged = false } = {}) {
  const r = rowFromResponse(t);
  await db.query(
    `INSERT INTO shops (shop, access_token, scope, expires_at, refresh_token, refresh_token_expires_at, needs_reauth, exchanged_at)
     VALUES ($1, $2, $3, $4, $5, $6, false, CASE WHEN $7 THEN now() END)
     ON CONFLICT (shop) DO UPDATE SET access_token = $2, scope = COALESCE($3, shops.scope), expires_at = $4,
       refresh_token = $5, refresh_token_expires_at = $6, needs_reauth = false,
       exchanged_at = COALESCE(CASE WHEN $7 THEN now() END, shops.exchanged_at)`,
    [shop, r.access_token, r.scope, r.expires_at, r.refresh_token, r.refresh_token_expires_at, exchanged],
  );
}

// Runs fn(client) inside a transaction holding a Postgres advisory lock for this shop only.
// Every process (web, workers, scripts) that reads, refreshes or replaces a shop's token goes through here,
// so two processes never spend the same single-use refresh token.
export async function withShopLock(pool, shop, fn) {
  const client = await pool.connect();
  let broken;
  try {
    await client.query("BEGIN");
    await client.query("SET LOCAL lock_timeout = '15s'");
    await client.query("SELECT pg_advisory_xact_lock(hashtext('shop-token:' || $1))", [shop]);
    const out = await fn(client);
    await client.query("COMMIT");
    return out;
  } catch (e) {
    // If ROLLBACK itself fails the connection is broken: release(err) destroys it instead of returning it to the pool.
    await client.query("ROLLBACK").catch((rollbackErr) => { broken = rollbackErr; });
    throw e;
  } finally {
    client.release(broken);
  }
}

// Returns a usable access token for shop, refreshing it if it expires within 5 minutes.
// force (after a 401): refresh even if the row looks fresh, unless the row no longer holds staleToken
// (another process already refreshed, so its new token is returned instead of spending another refresh).
// A dead refresh token is recorded (needs_reauth) and committed before ReauthRequired is thrown.
export async function ensureFreshToken(pool, tokens, shop, { force = false, staleToken = null, now = Date.now } = {}) {
  const out = await withShopLock(pool, shop, async (db) => {
    const { rows } = await db.query("SELECT * FROM shops WHERE shop = $1", [shop]);
    const row = rows[0];
    if (!row) return { reauth: "not installed" };
    if (row.needs_reauth) return { reauth: "refresh token is dead" };
    // Not migrated yet: still a non-expiring token. Keep using it; scripts/migrate-tokens.js exchanges it.
    if (!row.refresh_token) return { token: row.access_token };
    const fresh = row.expires_at && row.expires_at.getTime() - now() > REFRESH_MARGIN_MS;
    const mustRefresh = force && (staleToken === null || row.access_token === staleToken);
    if (fresh && !mustRefresh) return { token: row.access_token };
    const markDead = async (why) => {
      await db.query("UPDATE shops SET needs_reauth = true WHERE shop = $1", [shop]);
      return { reauth: why };
    };
    if (row.refresh_token_expires_at && row.refresh_token_expires_at.getTime() <= now()) return markDead("refresh token expired");
    let t;
    try {
      t = await tokens.refresh(shop, row.refresh_token);
    } catch (e) {
      // 400/401 on refresh means the refresh token was already used or revoked: only the merchant reopening the app fixes it.
      // Anything else (5xx, network) is transient: the row stays as is and the caller may retry.
      if (e instanceof ShopifyTokenError && (e.status === 400 || e.status === 401)) return markDead(`refresh rejected (${e.status})`);
      throw e;
    }
    // If this save failed, the new pair would be lost and the old refresh token is already spent: keep it in this transaction.
    await saveTokens(db, shop, t);
    return { token: t.access_token };
  });
  if (out.reauth) throw new ReauthRequired(shop, out.reauth);
  return out.token;
}
