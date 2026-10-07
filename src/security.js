import crypto from "node:crypto";
import bcrypt from "bcryptjs";
import argon2 from "argon2";
import sanitizeHtml from "sanitize-html";
import session from "express-session";
export const permissions = {
  owner: ["content", "leads", "settings", "users"],
  editor: ["content"],
  sales: ["leads"],
};
export const can = (user, permission) =>
  !!user?.active && !!permissions[user.role]?.includes(permission);
export function httpError(status, message) {
  return Object.assign(new Error(message), { status });
}
export function safeUrl(value) {
  if (!value) return "";
  if (/^\/(?![\/\\])[^\\\s\u0000-\u001f]*$/.test(value)) return value;
  try {
    const u = new URL(value);
    if (["http:", "https:"].includes(u.protocol) && !u.username && !u.password)
      return u.href;
  } catch {}
  throw httpError(
    422,
    "Use a relative path beginning with / or an HTTP(S) URL.",
  );
}
export const cleanHtml = (html) =>
  sanitizeHtml(html || "", {
    allowedTags: [
      "p",
      "br",
      "strong",
      "b",
      "em",
      "i",
      "u",
      "h2",
      "h3",
      "h4",
      "ul",
      "ol",
      "li",
      "blockquote",
      "a",
      "hr",
    ],
    allowedAttributes: { a: ["href", "title"] },
    allowedSchemes: ["http", "https", "mailto", "tel"],
    allowProtocolRelative: false,
  });
export const slugify = (value) =>
  String(value)
    .toLowerCase()
    .normalize("NFKD")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "")
    .slice(0, 150);
export async function verifyPassword(password, hash) {
  if (/^\$2[aby]\$/.test(hash))
    return bcrypt.compare(password, hash.replace(/^\$2y\$/, "$2b$"));
  if (hash.startsWith("$argon2")) return argon2.verify(hash, password);
  return false;
}
export function csrf(req, res, next) {
  req.session.csrf ||= crypto.randomBytes(32).toString("hex");
  res.locals.csrf = req.session.csrf;
  if (!["GET", "HEAD", "OPTIONS"].includes(req.method)) {
    const token = req.get("x-csrf-token") || req.body?.csrf;
    if (
      typeof token !== "string" ||
      !/^[a-f0-9]{64}$/.test(token) ||
      !crypto.timingSafeEqual(Buffer.from(token), Buffer.from(req.session.csrf))
    )
      return next(
        httpError(403, "Invalid request token. Reload the page and try again."),
      );
  }
  next();
}
// All instances share sessions and abuse counters through MySQL, never MemoryStore.
export class SqlSessionStore extends session.Store {
  constructor(db) {
    super();
    this.db = db;
  }
  get(sid, cb) {
    this.db
      .query(
        "SELECT data FROM sessions WHERE sid=? AND expires_at>UTC_TIMESTAMP()",
        [sid],
      )
      .then((r) => cb(null, r[0] ? JSON.parse(r[0].data) : null))
      .catch(cb);
  }
  set(sid, data, cb) {
    const expires = new Date(data.cookie.expires || Date.now() + 8 * 3600000);
    this.db
      .query(
        "INSERT INTO sessions(sid,data,expires_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data),expires_at=VALUES(expires_at)",
        [sid, JSON.stringify(data), expires],
      )
      .then(() => cb?.())
      .catch(cb);
  }
  destroy(sid, cb) {
    this.db
      .query("DELETE FROM sessions WHERE sid=?", [sid])
      .then(() => cb?.())
      .catch(cb);
  }
  touch(sid, data, cb) {
    this.db
      .query("UPDATE sessions SET expires_at=? WHERE sid=?", [
        new Date(data.cookie.expires),
        sid,
      ])
      .then(() => cb?.())
      .catch(cb);
  }
}
export function throttle(db, kind, max, windowSeconds) {
  return async (req, res, next) => {
    try {
      const key = crypto
        .createHash("sha256")
        .update(`${kind}:${req.ip}`)
        .digest("hex");
      const count = await db.transaction(async (tx) => {
        await tx.query(
          "INSERT INTO request_limits(limit_key,hits,reset_at) VALUES(?,0,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE limit_key=VALUES(limit_key)",
          [key, windowSeconds],
        );
        const [row] = await tx.query(
          "SELECT hits, reset_at<=UTC_TIMESTAMP() AS expired FROM request_limits WHERE limit_key=? FOR UPDATE",
          [key],
        );
        await tx.query(
          "UPDATE request_limits SET hits=?,reset_at=IF(?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),reset_at) WHERE limit_key=?",
          [row.expired ? 1 : row.hits + 1, row.expired, windowSeconds, key],
        );
        return row.expired ? 1 : row.hits + 1;
      });
      if (count > max) {
        res.set("Retry-After", String(windowSeconds));
        throw httpError(429, "Too many attempts. Please try again later.");
      }
      next();
    } catch (e) {
      next(e);
    }
  };
}
