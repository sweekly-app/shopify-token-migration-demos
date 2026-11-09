import { test, before, after, beforeEach } from "node:test";
import assert from "node:assert/strict";
import { setup, row, SHOP } from "./helpers.js";
import { ensureFreshToken, saveTokens, ReauthRequired } from "../src/tokens.js";

let ctx;
before(async () => { ctx = await setup(); });
after(async () => { await ctx.teardown(); });
beforeEach(async () => { await ctx.pool.query("DELETE FROM shops"); ctx.fake.refreshDelayMs = 0; });

async function installFresh() {
  const t = await ctx.tokens.codeGrant(SHOP, ctx.fake.issueCode());
  await saveTokens(ctx.pool, SHOP, t);
  return t;
}

test("code grant with expiring=1 stores an access token, a refresh token and both expiries", async () => {
  const t = await installFresh();
  const r = await row(ctx.pool);
  assert.equal(r.access_token, t.access_token);
  assert.equal(r.refresh_token, t.refresh_token);
  assert.ok(r.expires_at > new Date(Date.now() + 3500e3));
  assert.ok(r.refresh_token_expires_at > new Date(Date.now() + 89 * 86400e3));
});

test("a fresh token is used as is; an expiring one is refreshed and the row rotates", async () => {
  const t = await installFresh();
  const n = ctx.fake.calls.refresh;
  assert.equal(await ensureFreshToken(ctx.pool, ctx.tokens, SHOP), t.access_token);
  assert.equal(ctx.fake.calls.refresh, n);
  await ctx.pool.query("UPDATE shops SET expires_at = now() - interval '1 minute'");
  const fresh = await ensureFreshToken(ctx.pool, ctx.tokens, SHOP);
  assert.notEqual(fresh, t.access_token);
  const r = await row(ctx.pool);
  assert.equal(r.access_token, fresh);
  assert.notEqual(r.refresh_token, t.refresh_token);
  assert.ok(ctx.fake.isLive(fresh));
});

test("race: 8 concurrent callers on an expired token cause exactly 1 refresh and all succeed", async () => {
  await installFresh();
  await ctx.pool.query("UPDATE shops SET expires_at = now() - interval '1 minute'");
  ctx.fake.refreshDelayMs = 150;
  const before = ctx.fake.calls.refresh;
  const results = await Promise.all(Array.from({ length: 8 }, () => ensureFreshToken(ctx.pool, ctx.tokens, SHOP)));
  assert.equal(ctx.fake.calls.refresh - before, 1);
  assert.equal(new Set(results).size, 1);
  assert.equal((await row(ctx.pool)).needs_reauth, false);
});

test("control: the same race WITHOUT the lock spends the refresh token once and the other callers get 401", async () => {
  const t = await installFresh();
  ctx.fake.refreshDelayMs = 50;
  const outcomes = await Promise.allSettled(Array.from({ length: 8 }, () => ctx.tokens.refresh(SHOP, t.refresh_token)));
  assert.equal(outcomes.filter((o) => o.status === "fulfilled").length, 1);
  assert.ok(outcomes.filter((o) => o.status === "rejected").every((o) => o.reason.status === 401));
});

test("an expired refresh token marks the shop for re-auth, and the mark is committed", async () => {
  await installFresh();
  const n = ctx.fake.calls.refresh;
  await ctx.pool.query("UPDATE shops SET expires_at = now() - interval '1 minute', refresh_token_expires_at = now() - interval '1 second'");
  await assert.rejects(ensureFreshToken(ctx.pool, ctx.tokens, SHOP), ReauthRequired);
  assert.equal((await row(ctx.pool)).needs_reauth, true);
  assert.equal(ctx.fake.calls.refresh, n);
  await assert.rejects(ensureFreshToken(ctx.pool, ctx.tokens, SHOP), /refresh token is dead/);
});

test("a refresh token already spent elsewhere (401) marks the shop for re-auth", async () => {
  const t = await installFresh();
  await ctx.tokens.refresh(SHOP, t.refresh_token); // spent by "another app instance" that did not save
  await ctx.pool.query("UPDATE shops SET expires_at = now() - interval '1 minute'");
  await assert.rejects(ensureFreshToken(ctx.pool, ctx.tokens, SHOP), /refresh rejected \(401\)/);
  assert.equal((await row(ctx.pool)).needs_reauth, true);
});

test("Admin call retries once after a 401 by forcing a refresh", async () => {
  const t = await installFresh();
  ctx.fake.expireAccess(t.access_token); // Shopify says expired while our row says fresh
  const body = await ctx.graphql(SHOP, "{ products(first: 1) { nodes { id } } }");
  assert.equal(body.data.products.nodes[0].title, "Demo");
  assert.notEqual((await row(ctx.pool)).access_token, t.access_token);
});

test("a shop not yet migrated keeps working on its non-expiring token", async () => {
  const legacy = ctx.fake.issueLegacy();
  await ctx.pool.query("INSERT INTO shops (shop, access_token) VALUES ($1, $2)", [SHOP, legacy]);
  assert.equal(await ensureFreshToken(ctx.pool, ctx.tokens, SHOP), legacy);
  assert.equal((await ctx.graphql(SHOP, "{ shop { name } }")).data.products.nodes.length, 1);
});

test("forced refresh after a 401 is skipped when another process already replaced the token", async () => {
  const t = await installFresh();
  const newer = await ensureFreshToken(ctx.pool, ctx.tokens, SHOP, { force: true });
  const n = ctx.fake.calls.refresh;
  assert.equal(await ensureFreshToken(ctx.pool, ctx.tokens, SHOP, { force: true, staleToken: t.access_token }), newer);
  assert.equal(ctx.fake.calls.refresh, n);
});
