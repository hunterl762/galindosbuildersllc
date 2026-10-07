import "dotenv/config";
import path from "node:path";
import { fileURLToPath } from "node:url";
export const root = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  "..",
);
export function config(env = process.env) {
  if (!env.SESSION_SECRET || env.SESSION_SECRET.length < 32)
    throw new Error("SESSION_SECRET must contain at least 32 characters.");
  const siteUrl = new URL(env.SITE_URL || "http://localhost:3000");
  if (
    !["http:", "https:"].includes(siteUrl.protocol) ||
    siteUrl.pathname !== "/" ||
    siteUrl.search ||
    siteUrl.hash
  )
    throw new Error(
      "SITE_URL must be an HTTP(S) origin without a subdirectory.",
    );
  const production = env.NODE_ENV === "production";
  if (production && siteUrl.protocol !== "https:")
    throw new Error("Production SITE_URL must use HTTPS.");
  return {
    production,
    port: Number(env.PORT || 3000),
    siteUrl: siteUrl.origin,
    secret: env.SESSION_SECRET,
    setupToken: env.SETUP_TOKEN || "",
    trustProxy: Number(env.TRUST_PROXY || 0),
    uploadDir: path.resolve(root, env.UPLOAD_DIR || "uploads"),
    db: {
      host: env.DB_HOST || "127.0.0.1",
      port: Number(env.DB_PORT || 3306),
      database: env.DB_NAME || "galindosbuilders",
      user: env.DB_USER || "galindos",
      password: env.DB_PASS || "",
      charset: "utf8mb4",
      timezone: "Z",
      dateStrings: true,
      connectionLimit: 10,
      ...(env.DB_SSL === "true" ? { ssl: { rejectUnauthorized: true } } : {}),
    },
    mail: {
      host: env.SMTP_HOST,
      port: Number(env.SMTP_PORT || 587),
      secure: env.SMTP_SECURE === "true",
      auth: env.SMTP_USER
        ? { user: env.SMTP_USER, pass: env.SMTP_PASS }
        : undefined,
    },
    from: env.MAIL_FROM,
    notify: env.QUOTE_NOTIFY_EMAIL,
  };
}
