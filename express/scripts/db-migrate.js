import { loadConfig } from "../src/config.js";
import { createPool, migrate } from "../src/db.js";

const pool = createPool(loadConfig().databaseUrl);
await migrate(pool);
await pool.end();
console.log("schema up to date");
