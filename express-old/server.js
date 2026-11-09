// BEFORE: a typical hand-rolled Express Shopify app from 2019-2025.
// What is wrong with it (each item is fixed in ../express):
//   1. The code grant does not ask for an expiring token, so the app holds a token that never expires.
//      Such apps are delisted on 2027-01-01 and their Admin API calls get 403 from 2027-04-01.
//   2. There is no refresh token, no expiry and no refresh path.
//   3. Shops installed before the switch are never exchanged for expiring tokens.
//   4. The webhook HMAC is computed over JSON.stringify(req.body), not the raw bytes Shopify signed.
import crypto from "node:crypto";
import express from "express";
import pg from "pg";

const { SHOPIFY_API_KEY, SHOPIFY_API_SECRET, HOST, DATABASE_URL, PORT = 3000 } = process.env;
const SCOPES = "read_products";
const API_VERSION = "2026-10";
const pool = new pg.Pool({ connectionString: DATABASE_URL });
const app = express();
app.use(express.json());

const states = new Map();

app.get("/auth", (req, res) => {
  const shop = String(req.query.shop || "");
  if (!/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/.test(shop)) return res.status(400).send("bad shop");
  const state = crypto.randomBytes(16).toString("hex");
  states.set(state, shop);
  const url = `https://${shop}/admin/oauth/authorize?client_id=${SHOPIFY_API_KEY}&scope=${SCOPES}&redirect_uri=${encodeURIComponent(HOST + "/auth/callback")}&state=${state}`;
  res.redirect(url);
});

app.get("/auth/callback", async (req, res) => {
  const { shop, code, state, hmac, ...rest } = req.query;
  if (states.get(state) !== shop) return res.status(403).send("bad state");
  states.delete(state);
  const message = Object.keys({ shop, code, state, ...rest }).sort()
    .map((k) => `${k}=${({ shop, code, state, ...rest })[k]}`).join("&");
  const digest = crypto.createHmac("sha256", SHOPIFY_API_SECRET).update(message).digest("hex");
  if (digest !== hmac) return res.status(403).send("bad hmac");

  const r = await fetch(`https://${shop}/admin/oauth/access_token`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ client_id: SHOPIFY_API_KEY, client_secret: SHOPIFY_API_SECRET, code }),
  });
  const { access_token, scope } = await r.json();
  await pool.query(
    "INSERT INTO shops (shop, access_token, scope) VALUES ($1, $2, $3) ON CONFLICT (shop) DO UPDATE SET access_token = $2, scope = $3",
    [shop, access_token, scope],
  );
  res.redirect(`/?shop=${shop}`);
});

app.get("/api/products", async (req, res) => {
  const { rows } = await pool.query("SELECT access_token FROM shops WHERE shop = $1", [req.query.shop]);
  if (!rows.length) return res.status(401).send("not installed");
  const r = await fetch(`https://${req.query.shop}/admin/api/${API_VERSION}/graphql.json`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-Shopify-Access-Token": rows[0].access_token },
    body: JSON.stringify({ query: "{ products(first: 5) { nodes { id title } } }" }),
  });
  res.status(r.status).json(await r.json());
});

app.post("/webhooks", async (req, res) => {
  const digest = crypto.createHmac("sha256", SHOPIFY_API_SECRET).update(JSON.stringify(req.body)).digest("base64");
  if (digest !== req.get("x-shopify-hmac-sha256")) return res.status(401).send("bad hmac");
  const topic = req.get("x-shopify-topic");
  const shop = req.get("x-shopify-shop-domain");
  if (topic === "app/uninstalled" || topic === "shop/redact") await pool.query("DELETE FROM shops WHERE shop = $1", [shop]);
  // customers/data_request and customers/redact: this app stores no customer data.
  res.sendStatus(200);
});

app.get("/", (req, res) => res.send("Token demo (before)"));
app.listen(PORT);
