import fs from "node:fs/promises";
import { pathToFileURL } from "node:url";
import { root, config } from "../src/config.js";
import { database } from "../src/db.js";
import { slugify } from "../src/security.js";
import { defaultServices, defaultTabs } from "../src/content.js";
export async function migrate(db) {
  const connection = await db.pool.getConnection();
  try {
    const [[lock]] = await connection.execute(
      "SELECT GET_LOCK('galindos_cms_migrations',30) AS acquired",
    );
    if (!lock.acquired) throw new Error("Another migration is running.");
    const run = async (sql, params = []) =>
      (await connection.execute(sql, params))[0];
    // Retain the original schema as the baseline. No CREATE DATABASE or USE privileges required.
    const schema = (await fs.readFile(`${root}/database/schema.sql`, "utf8"))
      .replace(/^--.*$/gm, "")
      .replace(/(?:CREATE DATABASE|USE)\b[^;]*;/gi, "");
    for (const sql of schema
      .split(";")
      .map((s) => s.trim())
      .filter(Boolean))
      await run(sql);
    await run(
      "CREATE TABLE IF NOT EXISTS schema_migrations(version VARCHAR(100) PRIMARY KEY,applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
    );
    async function column(table, name, definition) {
      const rows = await run(
        "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?",
        [table, name],
      );
      if (!rows.length)
        await run(
          `ALTER TABLE \`${table}\` ADD COLUMN \`${name}\` ${definition}`,
        );
    }
    // Each DDL step is re-entrant: MySQL DDL commits implicitly, so an interrupted run can resume.
    for (const [table, columns] of Object.entries({
      admins: {
        role: "VARCHAR(20) NOT NULL DEFAULT 'owner'",
        active: "TINYINT(1) NOT NULL DEFAULT 1",
        email: "VARCHAR(255) NULL",
      },
      projects: {
        visible: "TINYINT(1) NOT NULL DEFAULT 1",
        completion_date: "DATE NULL",
        client: "VARCHAR(255) NULL",
        contractor: "VARCHAR(255) NULL",
        scope: "TEXT NULL",
        square_footage: "INT UNSIGNED NULL",
        project_type: "VARCHAR(160) NULL",
        service_id: "VARCHAR(100) NULL",
        featured_image: "VARCHAR(700) NULL",
        stats: "TEXT NULL",
        seo_title: "VARCHAR(255) NULL",
        seo_description: "TEXT NULL",
        canonical_url: "VARCHAR(700) NULL",
        og_image: "VARCHAR(700) NULL",
        robots: "VARCHAR(40) NOT NULL DEFAULT 'index,follow'",
      },
      project_images: {
        media_id: "VARCHAR(32) NULL",
        caption: "VARCHAR(255) NULL",
        image_kind: "VARCHAR(20) NOT NULL DEFAULT 'gallery'",
      },
      services: {
        slug: "VARCHAR(160) NULL",
        content: "LONGTEXT NULL",
        hero_text: "TEXT NULL",
        featured_image: "VARCHAR(700) NULL",
        seo_title: "VARCHAR(255) NULL",
        seo_description: "TEXT NULL",
        canonical_url: "VARCHAR(700) NULL",
        og_image: "VARCHAR(700) NULL",
        robots: "VARCHAR(40) NOT NULL DEFAULT 'index,follow'",
      },
      pages: {
        featured_image: "VARCHAR(700) NULL",
        canonical_url: "VARCHAR(700) NULL",
        og_image: "VARCHAR(700) NULL",
        robots: "VARCHAR(40) NOT NULL DEFAULT 'index,follow'",
      },
      seo_settings: {
        canonical_url: "VARCHAR(700) NULL",
        og_image: "VARCHAR(700) NULL",
        robots: "VARCHAR(40) NOT NULL DEFAULT 'index,follow'",
      },
      quote_submissions: {
        assigned_to: "BIGINT UNSIGNED NULL",
        updated_at:
          "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
      },
    }))
      for (const [name, definition] of Object.entries(columns))
        await column(table, name, definition);
    const tables = await fs.readFile(
      `${root}/database/migrations/002-cms-v2.sql`,
      "utf8",
    );
    for (const sql of tables
      .replace(/^--.*$/gm, "")
      .split(";")
      .map((s) => s.trim())
      .filter(Boolean))
      await run(sql);
    const alreadyMigrated = await run(
      "SELECT version FROM schema_migrations WHERE version='002-cms-v2'",
    );
    if (!alreadyMigrated.length) {
      const [serviceCount] = await run("SELECT COUNT(*) AS n FROM services");
      const legacyServices = await run(
        "SELECT store_key FROM site_store WHERE store_key='services'",
      );
      const hasJsonServices = await fs
        .access(`${root}/data/services.json`)
        .then(
          () => true,
          () => false,
        );
      if (!serviceCount.n && !legacyServices.length && !hasJsonServices)
        for (const [i, s] of defaultServices.entries())
          await run(
            "INSERT INTO services(id,slug,name,description,sort_order) VALUES(?,?,?,?,?)",
            [s.id, s.slug, s.name, s.description, i * 10],
          );
      const [tabCount] = await run("SELECT COUNT(*) AS n FROM navigation_tabs");
      const legacyTabs = await run(
        "SELECT store_key FROM site_store WHERE store_key='tabs'",
      );
      const hasJsonTabs = await fs.access(`${root}/data/tabs.json`).then(
        () => true,
        () => false,
      );
      if (!tabCount.n && !legacyTabs.length && !hasJsonTabs)
        for (const [i, t] of defaultTabs.entries())
          await run(
            "INSERT INTO navigation_tabs(id,label,url,sort_order) VALUES(?,?,?,?)",
            [t.label.toLowerCase(), t.label, t.url, i * 10],
          );
    }
    const services = await run(
      "SELECT id,name FROM services WHERE slug IS NULL OR slug=?",
      [""],
    );
    for (const s of services)
      await run("UPDATE services SET slug=? WHERE id=?", [
        `${slugify(s.name) || "service"}-${s.id}`.slice(0, 160),
        s.id,
      ]);
    const index = await run(
      "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND INDEX_NAME='services_slug_unique'",
    );
    if (!index.length)
      await run("CREATE UNIQUE INDEX services_slug_unique ON services(slug)");
    for (const [i, key] of [
      "hero",
      "about",
      "values",
      "services",
      "projects",
      "contact",
    ].entries())
      await run(
        "INSERT IGNORE INTO homepage_sections(section_key,enabled,sort_order) VALUES(?,1,?)",
        [key, i * 10],
      );
    // Reuse legacy gallery URLs instead of relocating any uploaded files.
    const images = await run("SELECT DISTINCT image_url FROM project_images");
    for (const image of images) {
      const id = (await import("node:crypto"))
        .createHash("sha256")
        .update(image.image_url)
        .digest("hex")
        .slice(0, 32);
      await run(
        "INSERT IGNORE INTO media(id,url,title,alt_text,folder,mime_type) VALUES(?,?,?,?,?,?)",
        [id, image.image_url, "Imported project image", "", "Legacy", "legacy"],
      );
      await run(
        "UPDATE project_images SET media_id=? WHERE image_url=? AND media_id IS NULL",
        [id, image.image_url],
      );
    }
    await run(
      "INSERT IGNORE INTO schema_migrations(version) VALUES('002-cms-v2')",
    );
  } finally {
    await connection.execute("SELECT RELEASE_LOCK('galindos_cms_migrations')");
    connection.release();
  }
}
if (
  process.argv[1] &&
  import.meta.url === pathToFileURL(process.argv[1]).href
) {
  const db = database(config().db);
  try {
    await migrate(db);
    console.log("Compatible CMS v2 migrations completed.");
  } finally {
    await db.close();
  }
}
