import { test, before, after, beforeEach } from "node:test";
import assert from "node:assert/strict";
import crypto from "node:crypto";
import { setup, row, SHOP } from "./helpers.js";
import { createApp } from "../src/app.js";
import { saveTokens } from "../src/tokens.js";

let ctx, server, base;
before(async () => {
  ctx = await setup();
  server = createApp(ctx).listen(0, "127.0.0.1");
  await new Promise((r) => server.once("listening", r));
  base = `http://127.0.0.1:${server.address().port}`;
});
after(async () => { await new Promise((r) => server.close(r)); await ctx.teardown(); });
beforeEach(async () => { await ctx.pool.query("DELETE FROM shops"); });

const sign = (params) => crypto.createHmac("sha256", "secret")
  .update(Object.keys(params).sort().map((k) => `${k}=${params[k]}`).join("&")).digest("hex");

test("install: /auth sets a state cookie, the signed callback stores an expiring pair", async () => {
  const start = await fetch(`${base}/auth?shop=${SHOP}`, { redirect: "manual" });
  assert.equal(start.status, 302);
  const state = new URL(start.headers.get("location")).searchParams.get("state");
  const cookie = start.headers.get("set-cookie").split(";")[0];
  const params = { code: ctx.fake.issueCode(), shop: SHOP, state, timestamp: String(Math.floor(Date.now() / 1000)) };
  const q = new URLSearchParams({ ...params, hmac: sign(params) });
  const cb = await fetch(`${base}/auth/callback?${q}`, { redirect: "manual", headers: { cookie } });
  assert.equal(cb.status, 302);
  const r = await row(ctx.pool);
  assert.ok(r.refresh_token && r.expires_at);
});

test("callback with a bad hmac or a missing state cookie is refused", async () => {
  const params = { code: "x", shop: SHOP, state: "s", timestamp: "1" };
  const q = new URLSearchParams({ ...params, hmac: "0".repeat(64) });
  assert.equal((await fetch(`${base}/auth/callback?${q}`, { headers: { cookie: "oauth_state=s" } })).status, 403);
  const q2 = new URLSearchParams({ ...params, hmac: sign(params) });
  assert.equal((await fetch(`${base}/auth/callback?${q2}`)).status, 403);
});

function webhook(topic, rawBody, secret = "secret") {
  const hmac = crypto.createHmac("sha256", secret).update(rawBody).digest("base64");
  return fetch(`${base}/webhooks`, {
    method: "POST", body: rawBody,
    headers: { "Content-Type": "application/json", "X-Shopify-Topic": topic, "X-Shopify-Shop-Domain": SHOP, "X-Shopify-Hmac-Sha256": hmac },
  });
}

test("webhook HMAC is checked on the raw bytes (spacing that JSON.stringify would change still verifies)", async () => {
  const t = await ctx.tokens.codeGrant(SHOP, ctx.fake.issueCode());
  await saveTokens(ctx.pool, SHOP, t);
  const raw = '{"shop_id": 1,  "shop_domain": "demo-shop.myshopify.com"}';
  assert.notEqual(JSON.stringify(JSON.parse(raw)), raw);
  assert.equal((await webhook("shop/redact", raw)).status, 200);
  assert.equal(await row(ctx.pool), undefined);
});

test("webhook with a wrong signature gets 401 and changes nothing", async () => {
  const t = await ctx.tokens.codeGrant(SHOP, ctx.fake.issueCode());
  await saveTokens(ctx.pool, SHOP, t);
  assert.equal((await webhook("app/uninstalled", "{}", "wrong")).status, 401);
  assert.ok(await row(ctx.pool));
});

test("/api/products answers 401 with a re-auth link once the refresh token is dead", async () => {
  const t = await ctx.tokens.codeGrant(SHOP, ctx.fake.issueCode());
  await saveTokens(ctx.pool, SHOP, t);
  await ctx.pool.query("UPDATE shops SET needs_reauth = true");
  const res = await fetch(`${base}/api/products?shop=${SHOP}`);
  assert.equal(res.status, 401);
  assert.equal((await res.json()).reauth, `/auth?shop=${SHOP}`);
});
