import { config } from "./config.js";
import { database } from "./db.js";
import { createApp } from "./app.js";
const cfg = config(),
  db = database(cfg.db);
await db
  .query("SELECT version FROM schema_migrations WHERE version=?", [
    "002-cms-v2",
  ])
  .then((rows) => {
    if (!rows.length) throw new Error("Run npm run migrate before starting.");
  });
const server = createApp({ db, cfg }).listen(cfg.port, () =>
  console.log(`Galindos Builders listening on port ${cfg.port}`),
);
async function shutdown() {
  server.close(async () => {
    await db.close();
    process.exit(0);
  });
  setTimeout(() => process.exit(1), 10000).unref();
}
process.on("SIGINT", shutdown);
process.on("SIGTERM", shutdown);
