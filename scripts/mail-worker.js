import { config } from "../src/config.js";
import { database } from "../src/db.js";
import { deliverMail } from "../src/mail.js";
const cfg = config(),
  db = database(cfg.db);
if (!cfg.mail.host || !cfg.from || !cfg.notify)
  throw new Error(
    "Configure SMTP_HOST, MAIL_FROM and QUOTE_NOTIFY_EMAIL first.",
  );
let stopped = false;
process.on("SIGINT", () => (stopped = true));
process.on("SIGTERM", () => (stopped = true));
while (!stopped) {
  await deliverMail(db, cfg);
  await db.query("DELETE FROM sessions WHERE expires_at<UTC_TIMESTAMP()");
  await db.query("DELETE FROM request_limits WHERE reset_at<UTC_TIMESTAMP()");
  if (process.argv.includes("--once")) break;
  await new Promise((resolve) => setTimeout(resolve, 15000));
}
await db.close();
