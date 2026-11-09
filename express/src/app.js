import crypto from "node:crypto";
import express from "express";
import { SHOP_RE } from "./config.js";
import { authorizeUrl, validQueryHmac } from "./oauth.js";
import { saveTokens, withShopLock, ReauthRequired } from "./tokens.js";
import { validWebhookHmac, handleWebhook } from "./webhooks.js";
import { AdminApiError } from "./admin.js";

const STATE_COOKIE = "oauth_state";

function readCookie(req, name) {
  const m = (req.get("cookie") || "").split(/;\s*/).find((c) => c.startsWith(`${name}=`));
  return m ? decodeURIComponent(m.slice(name.length + 1)) : null;
}

export function createApp({ config, pool, tokens, graphql }) {
  const app = express();

  // Webhooks first, with the raw body: the HMAC is over the exact bytes Shopify sent.
  // Express 4 does not catch rejected promises: every async route passes errors to next().
  app.post("/webhooks", express.raw({ type: "*/*", limit: "1mb" }), async (req, res, next) => {
    try {
      if (!validWebhookHmac(config.apiSecret, req.body, req.get("x-shopify-hmac-sha256"))) return res.sendStatus(401);
      const shop = req.get("x-shopify-shop-domain");
      if (!SHOP_RE.test(shop || "")) return res.sendStatus(400);
      await handleWebhook(pool, req.get("x-shopify-topic"), shop);
      res.sendStatus(200);
    } catch (e) { next(e); }
  });

  app.get("/auth", (req, res) => {
    const shop = String(req.query.shop || "");
    if (!SHOP_RE.test(shop)) return res.status(400).send("bad shop");
    const state = crypto.randomBytes(16).toString("hex");
    res.set("Set-Cookie", `${STATE_COOKIE}=${state}; Path=/auth; HttpOnly; Secure; SameSite=Lax; Max-Age=600`);
    res.redirect(authorizeUrl(config, shop, state));
  });

  app.get("/auth/callback", async (req, res, next) => {
    try {
      const shop = String(req.query.shop || "");
      const state = readCookie(req, STATE_COOKIE);
      if (!SHOP_RE.test(shop) || !state || state !== req.query.state) return res.status(403).send("bad state");
      if (!validQueryHmac(config.apiSecret, req.query)) return res.status(403).send("bad hmac");
      const t = await tokens.codeGrant(shop, String(req.query.code || ""));
      if (!t.refresh_token) throw new Error("Shopify returned a non-expiring token: is expiring=1 sent?");
      await withShopLock(pool, shop, (db) => saveTokens(db, shop, t)); // a refresh in flight can't overwrite or kill the new install
      res.set("Set-Cookie", `${STATE_COOKIE}=; Path=/auth; Max-Age=0`);
      res.redirect(`/?shop=${encodeURIComponent(shop)}`);
    } catch (e) { next(e); }
  });

  app.get("/api/products", async (req, res, next) => {
    const shop = String(req.query.shop || "");
    if (!SHOP_RE.test(shop)) return res.status(400).send("bad shop");
    try {
      const body = await graphql(shop, "{ products(first: 5) { nodes { id title } } }");
      res.json(body);
    } catch (e) {
      if (e instanceof ReauthRequired) return res.status(401).json({ reauth: `/auth?shop=${shop}` });
      if (e instanceof AdminApiError) return res.status(502).json({ status: e.status, body: e.body });
      next(e);
    }
  });

  app.get("/", (req, res) => res.type("text").send("Token demo (after): expiring offline tokens."));

  app.use((err, req, res, _next) => {
    console.error(err);
    res.status(500).send("error");
  });
  return app;
}
