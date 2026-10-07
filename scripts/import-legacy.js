import fs from "node:fs/promises";
import path from "node:path";
import crypto from "node:crypto";
import { pathToFileURL } from "node:url";
import { config, root } from "../src/config.js";
import { database } from "../src/db.js";
import { resources, parseFields } from "../src/admin-fields.js";
import { slugify } from "../src/security.js";
export async function importLegacy(db, directory = path.join(root, "data")) {
  const json = {};
  for (const key of [
    "admins",
    "home",
    "services",
    "tabs",
    "pages",
    "projects",
    "seo",
    "quotes",
  ]) {
    try {
      json[key] = JSON.parse(
        await fs.readFile(path.join(directory, `${key}.json`), "utf8"),
      );
    } catch (e) {
      if (e.code !== "ENOENT") throw e;
    }
    if (json[key] == null) {
      const [row] = await db.query(
        "SELECT payload FROM site_store WHERE store_key=?",
        [key],
      );
      if (row) json[key] = JSON.parse(row.payload);
    }
  }
  // Import each collection only when its normalized destination is empty. No live rows are replaced.
  await db.transaction(async (tx) => {
    for (const [legacy, key] of [
      ["services", "services"],
      ["tabs", "navigation"],
      ["pages", "pages"],
      ["projects", "projects"],
    ]) {
      const resource = resources[key],
        rows = json[legacy];
      if (!rows) continue;
      if (!Array.isArray(rows)) throw new Error(`${legacy} must be an array.`);
      const [count] = await tx.query(
        `SELECT COUNT(*) AS n FROM ${resource.table}`,
      );
      if (count.n) continue;
      for (const [i, row] of rows.entries()) {
        const recordId = row.id || crypto.randomBytes(16).toString("hex");
        const data = parseFields(resource.fields, {
          ...row,
          slug:
            row.slug ||
            `${slugify(row.name || row.title || "item")}-${recordId}`.slice(
              0,
              160,
            ),
          sort_order: row.sort_order ?? row.sort ?? i * 10,
          visible: row.visible === false || row.visible === 0 ? "0" : "1",
          featured: row.featured ? "1" : "0",
        });
        data.id = recordId;
        const cols = Object.keys(data);
        await tx.query(
          `INSERT INTO ${resource.table}(${cols.map((c) => `\`${c}\``).join(",")}) VALUES(${cols.map(() => "?").join(",")})`,
          Object.values(data),
        );
        if (key === "projects")
          for (const [order, url] of (row.images || []).entries())
            await tx.query(
              "INSERT INTO project_images(project_id,image_url,sort_order) VALUES(?,?,?)",
              [recordId, url, order],
            );
      }
    }
    if (json.home)
      await tx.query(
        "INSERT IGNORE INTO site_settings(setting_key,setting_value) VALUES(?,?)",
        ["home", JSON.stringify(json.home)],
      );
    if (json.seo) {
      const [count] = await tx.query("SELECT COUNT(*) AS n FROM seo_settings");
      if (!count.n)
        for (const [key, row] of Object.entries(json.seo)) {
          const data = parseFields(resources.seo.fields, {
            ...row,
            page_key: key,
          });
          const cols = Object.keys(data);
          await tx.query(
            `INSERT INTO seo_settings(${cols.join(",")}) VALUES(${cols.map(() => "?").join(",")})`,
            Object.values(data),
          );
        }
    }
    if (json.admins) {
      const [count] = await tx.query("SELECT COUNT(*) AS n FROM admins");
      if (!count.n)
        for (const admin of json.admins) {
          if (
            !/^\$(2[aby]|argon2)/.test(
              admin.password || admin.password_hash || "",
            )
          )
            throw new Error("Unsupported legacy password hash.");
          await tx.query(
            "INSERT INTO admins(username,password_hash,role) VALUES(?,?,?)",
            [admin.username, admin.password || admin.password_hash, "owner"],
          );
        }
    }
    if (json.quotes) {
      const [count] = await tx.query(
        "SELECT COUNT(*) AS n FROM quote_submissions",
      );
      if (!count.n)
        for (const quote of json.quotes)
          await tx.query(
            "INSERT INTO quote_submissions(name,email,phone,project_type,location,message,status,submitted_at) VALUES(?,?,?,?,?,?,?,?)",
            [
              quote.name || "",
              quote.email || "",
              quote.phone || "",
              quote.project_type || quote.project || "",
              quote.location || "",
              quote.message || "",
              quote.status || "New",
              quote.created_at ? new Date(quote.created_at) : new Date(),
            ],
          );
    }
  });
}
if (
  process.argv[1] &&
  import.meta.url === pathToFileURL(process.argv[1]).href
) {
  const db = database(config().db);
  try {
    await importLegacy(db, process.argv[2] || path.join(root, "data"));
    console.log(
      "Legacy JSON/site_store import completed. Run npm run migrate again to register imported gallery images.",
    );
  } finally {
    await db.close();
  }
}
