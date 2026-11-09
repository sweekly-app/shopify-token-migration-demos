import { loadConfig } from "./config.js";
import { createPool } from "./db.js";
import { createTokens } from "./tokens.js";
import { createAdmin } from "./admin.js";
import { createApp } from "./app.js";

const config = loadConfig();
const pool = createPool(config.databaseUrl);
const tokens = createTokens(config);
const graphql = createAdmin(config, pool, tokens);
createApp({ config, pool, tokens, graphql }).listen(Number(process.env.PORT || 3000));
