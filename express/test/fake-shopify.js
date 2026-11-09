// A local stand-in for the Shopify endpoints the app calls, with the token rules that matter:
// refresh tokens are single use, the legacy exchange is one-time, and within 7 days the same exchange returns the same pair.
import http from "node:http";
import crypto from "node:crypto";

export const TOKEN_EXCHANGE_GRANT = "urn:ietf:params:oauth:grant-type:token-exchange";
export const OFFLINE = "urn:shopify:params:oauth:token-type:offline-access-token";
const id = (p) => `${p}_${crypto.randomBytes(8).toString("hex")}`;

export async function startFakeShopify({ apiKey, apiSecret }) {
  const access = new Map();   // token -> { expiresAt|null, legacy }
  const refresh = new Map();  // token -> { used, expiresAt }
  const exchanged = new Map(); // legacy token -> { pair, at, refreshed }
  const codes = new Map();    // code -> true
  const fake = {
    calls: { refresh: 0, exchange: 0, code: 0, graphql: 0 },
    refreshDelayMs: 0,
    dropNextExchangeResponse: false,
    issueCode() { const c = id("code"); codes.set(c, true); return c; },
    issueLegacy() { const t = id("shpat_legacy"); access.set(t, { expiresAt: null, legacy: true }); return t; },
    expireAccess(t) { const a = access.get(t); if (a) a.expiresAt = Date.now() - 1; },
    isLive(t) { const a = access.get(t); return !!a && (a.expiresAt === null || a.expiresAt > Date.now()); },
  };
  const pair = () => {
    const a = id("shpat"), r = id("shprt");
    access.set(a, { expiresAt: Date.now() + 3600e3, legacy: false });
    refresh.set(r, { used: false, expiresAt: Date.now() + 90 * 86400e3 });
    return { access_token: a, scope: "read_products", expires_in: 3600, refresh_token: r, refresh_token_expires_in: 90 * 86400 };
  };
  const send = (res, status, body) => { res.writeHead(status, { "Content-Type": "application/json" }); res.end(JSON.stringify(body)); };

  const server = http.createServer(async (req, res) => {
    let raw = "";
    for await (const c of req) raw += c;
    const body = raw ? JSON.parse(raw) : {};
    if (req.method === "POST" && req.url === "/admin/oauth/access_token") {
      if (body.client_id !== apiKey || body.client_secret !== apiSecret) return send(res, 401, { error: "invalid_client" });
      if (body.code) {
        fake.calls.code++;
        if (!codes.delete(body.code)) return send(res, 400, { error: "invalid_request" });
        if (body.expiring === "1") return send(res, 200, pair());
        const t = fake.issueLegacy();
        return send(res, 200, { access_token: t, scope: "read_products" });
      }
      if (body.grant_type === "refresh_token") {
        fake.calls.refresh++;
        if (fake.refreshDelayMs) await new Promise((r) => setTimeout(r, fake.refreshDelayMs));
        const r = refresh.get(body.refresh_token);
        if (!r || r.used || r.expiresAt <= Date.now()) return send(res, 401, { error: "invalid_request" });
        r.used = true;
        for (const e of exchanged.values()) if (e.pair.refresh_token === body.refresh_token) e.refreshed = true;
        return send(res, 200, pair());
      }
      if (body.grant_type === TOKEN_EXCHANGE_GRANT && body.subject_token_type === OFFLINE && body.requested_token_type === OFFLINE && body.expiring === "1") {
        fake.calls.exchange++;
        const prior = exchanged.get(body.subject_token);
        let out;
        if (prior) {
          if (prior.refreshed || Date.now() - prior.at > 7 * 86400e3) return send(res, 400, { error: "invalid_subject_token" });
          out = prior.pair;
        } else {
          const a = access.get(body.subject_token);
          if (!a || !a.legacy) return send(res, 400, { error: "invalid_subject_token" });
          access.delete(body.subject_token); // the old token dies with the exchange
          out = pair();
          exchanged.set(body.subject_token, { pair: out, at: Date.now(), refreshed: false });
        }
        if (fake.dropNextExchangeResponse) { fake.dropNextExchangeResponse = false; return send(res, 502, { error: "lost" }); }
        return send(res, 200, out);
      }
      return send(res, 400, { error: "unsupported_grant_type" });
    }
    if (req.method === "POST" && /^\/admin\/api\/[\w-]+\/graphql\.json$/.test(req.url)) {
      fake.calls.graphql++;
      if (!fake.isLive(req.headers["x-shopify-access-token"])) return send(res, 401, { errors: "[API] Invalid API key or access token" });
      return send(res, 200, { data: { products: { nodes: [{ id: "gid://shopify/Product/1", title: "Demo" }] } } });
    }
    send(res, 404, { error: "not found" });
  });
  await new Promise((r) => server.listen(0, "127.0.0.1", r));
  fake.origin = `http://127.0.0.1:${server.address().port}`;
  fake.close = () => new Promise((r) => server.close(r));
  return fake;
}
