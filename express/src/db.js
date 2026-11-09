import { readFileSync, readdirSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import pg from "pg";

export function createPool(databaseUrl) {
  return new pg.Pool({ connectionString: databaseUrl, max: 10 });
}

export async function migrate(pool) {
  const dir = join(dirname(fileURLToPath(import.meta.url)), "..", "sql");
  for (const f of readdirSync(dir).filter((n) => n.endsWith(".sql")).sort()) {
    await pool.query(readFileSync(join(dir, f), "utf8"));
  }
}
