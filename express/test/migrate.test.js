import { test, before, after, beforeEach } from "node:test";
import assert from "node:assert/strict";
import { setup, insertLegacy, row, SHOP } from "./helpers.js";
import { migrateTokens } from "../src/migrate-tokens.js";

let ctx;
const quiet = () => {};
before(async () => { ctx = await setup(); });
after(async () => { await ctx.teardown(); });
beforeEach(async () => { await ctx.pool.query("DELETE FROM shops"); });

test("dry run lists legacy shops and changes nothing", async () => {
  const legacy = await insertLegacy(ctx.pool, ctx.fake);
  const n = ctx.fake.calls.exchange;
  const rep = await migrateTokens(ctx.pool, ctx.tokens, { log: quiet });
  assert.deepEqual(rep.candidates, [SHOP]);
  assert.equal(ctx.fake.calls.exchange, n);
  assert.equal((await row(ctx.pool)).access_token, legacy);
});

test("apply exchanges the legacy token, saves the pair, and the old token is dead", async () => {
  const legacy = await insertLegacy(ctx.pool, ctx.fake);
  const rep = await migrateTokens(ctx.pool, ctx.tokens, { apply: true, log: quiet });
  assert.deepEqual(rep.exchanged, [SHOP]);
  const r = await row(ctx.pool);
  assert.ok(r.refresh_token && r.expires_at && r.exchanged_at);
  assert.equal(ctx.fake.isLive(legacy), false);
  assert.equal((await ctx.graphql(SHOP, "{ shop { name } }")).data.products.nodes.length, 1);
});

test("--shop limits the run to one shop; a second run skips migrated shops", async () => {
  await insertLegacy(ctx.pool, ctx.fake, "a-shop.myshopify.com");
  await insertLegacy(ctx.pool, ctx.fake, "b-shop.myshopify.com");
  const one = await migrateTokens(ctx.pool, ctx.tokens, { apply: true, shop: "a-shop.myshopify.com", log: quiet });
  assert.deepEqual(one.exchanged, ["a-shop.myshopify.com"]);
  const rest = await migrateTokens(ctx.pool, ctx.tokens, { apply: true, log: quiet });
  assert.deepEqual(rest.exchanged, ["b-shop.myshopify.com"]);
  const again = await migrateTokens(ctx.pool, ctx.tokens, { apply: true, log: quiet });
  assert.deepEqual(again.candidates, []);
});

test("lost response: the old token stays in the row and a re-run recovers the same pair", async () => {
  const legacy = await insertLegacy(ctx.pool, ctx.fake);
  ctx.fake.dropNextExchangeResponse = true;
  const first = await migrateTokens(ctx.pool, ctx.tokens, { apply: true, log: quiet });
  assert.equal(first.failed.length, 1);
  assert.equal((await row(ctx.pool)).access_token, legacy); // nothing discarded
  assert.equal(ctx.fake.isLive(legacy), false); // ...although Shopify already killed it
  const second = await migrateTokens(ctx.pool, ctx.tokens, { apply: true, log: quiet });
  assert.deepEqual(second.exchanged, [SHOP]);
  assert.equal((await ctx.graphql(SHOP, "{ shop { name } }")).data.products.nodes.length, 1);
});
