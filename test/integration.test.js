import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import mysql from "mysql2/promise";
import request from "supertest";
import bcrypt from "bcryptjs";
import { database } from "../src/db.js";
import { config, root } from "../src/config.js";
import { createApp } from "../src/app.js";
import { migrate } from "../scripts/migrate.js";
import { importLegacy } from "../scripts/import-legacy.js";
import { deliverMail } from "../src/mail.js";
const token = (res) => res.text.match(/name="csrf" value="([a-f0-9]+)"/)?.[1];
test(
  "MySQL migration and full CMS workflow",
  { skip: !process.env.TEST_DB_PORT, timeout: 120000 },
  async (t) => {
    const name = `galindos_test_${Date.now()}`;
    const admin = await mysql.createConnection({
      host: "127.0.0.1",
      port: Number(process.env.TEST_DB_PORT),
      user: process.env.TEST_DB_USER || "root",
      password: process.env.TEST_DB_PASS || "",
      multipleStatements: true,
    });
    await admin.query(`CREATE DATABASE \`${name}\` CHARACTER SET utf8mb4`);
    const uploadDir = await fs.mkdtemp(path.join(os.tmpdir(), "gb-uploads-"));
    const cfg = config({
        SESSION_SECRET: "test-secret-".repeat(4),
        SETUP_TOKEN: "setup-secret",
        DB_HOST: "127.0.0.1",
        DB_PORT: process.env.TEST_DB_PORT,
        DB_USER: process.env.TEST_DB_USER || "root",
        DB_PASS: process.env.TEST_DB_PASS || "",
        DB_NAME: name,
        UPLOAD_DIR: uploadDir,
        SMTP_HOST: "fake.smtp",
        MAIL_FROM: "company@example.com",
        QUOTE_NOTIFY_EMAIL: "owner@example.com",
      }),
      db = database(cfg.db);
    try {
      // Start from the actual old schema and representative legacy rows, then migrate twice.
      const original = (
        await fs.readFile(`${root}/database/schema.sql`, "utf8")
      ).replace(/(?:CREATE DATABASE|USE)\b[^;]*;/gi, "");
      await admin.query(`USE \`${name}\`; ${original}`);
      await db.query(
        "INSERT INTO projects(id,slug,name,location,featured) VALUES('legacy','legacy-project','Legacy Project','Maryland',1)",
      );
      await db.query(
        "INSERT INTO project_images(project_id,image_url) VALUES('legacy','/uploads/projects/old.jpg')",
      );
      await db.query(
        "INSERT INTO pages(id,slug,title,content) VALUES('legacy-page','about','About','<h2>Approved content</h2>')",
      );
      await db.query(
        "INSERT INTO site_settings(setting_key,setting_value) VALUES('site_name','Galindos Builders LLC')",
      );
      await migrate(db);
      await migrate(db);
      assert.equal(
        (await db.query("SELECT * FROM projects WHERE id=?", ["legacy"]))[0]
          .name,
        "Legacy Project",
      );
      assert.equal(
        (await db.query("SELECT * FROM project_images"))[0].image_url,
        "/uploads/projects/old.jpg",
      );
      assert.equal((await db.query("SELECT * FROM media")).length, 1);
      const app = createApp({ db, cfg }),
        owner = request.agent(app);
      let page = await owner.get("/admin/login");
      assert.equal(page.status, 200);
      assert.match(page.text, /Create Administrator/);
      let csrf = token(page);
      assert.equal(
        (
          await owner.post("/admin/setup").type("form").send({
            csrf,
            setup_token: "bad",
            username: "owner",
            password: "strong-password-123",
          })
        ).status,
        403,
      );
      assert.equal(
        (
          await owner.post("/admin/setup").type("form").send({
            csrf,
            setup_token: cfg.setupToken,
            username: "owner",
            password: "strong-password-123",
          })
        ).status,
        302,
      );
      assert.equal(
        (
          await owner.post("/admin/setup").type("form").send({
            csrf,
            setup_token: cfg.setupToken,
            username: "another",
            password: "strong-password-123",
          })
        ).status,
        409,
      );
      // Replace with a PHP $2y$ hash and verify the existing account can sign in.
      await db.query("UPDATE admins SET password_hash=? WHERE username=?", [
        (await bcrypt.hash("strong-password-123", 4)).replace("$2b$", "$2y$"),
        "owner",
      ]);
      const before = page.headers["set-cookie"][0].split(";")[0];
      assert.equal(
        (
          await owner
            .post("/admin/login")
            .type("form")
            .send({ csrf, username: "owner", password: "wrong" })
        ).status,
        401,
      );
      const login = await owner
        .post("/admin/login")
        .type("form")
        .send({ csrf, username: "owner", password: "strong-password-123" });
      assert.equal(login.status, 302);
      assert.notEqual(login.headers["set-cookie"][0].split(";")[0], before);
      assert.match(login.headers["set-cookie"][0], /HttpOnly/);
      assert.match(login.headers["set-cookie"][0], /SameSite=Lax/);
      page = await owner.get("/admin");
      csrf = token(page);
      assert.equal(page.status, 200);
      const send = (url, data) =>
        owner
          .post(url)
          .type("form")
          .send({ csrf, ...data });
      assert.equal(
        (
          await owner
            .post("/admin/home")
            .type("form")
            .send({ hero_title: "Missing CSRF" })
        ).status,
        403,
      );
      assert.equal(
        (
          await owner
            .post("/admin/home")
            .type("form")
            .send({ csrf: "é".repeat(64) })
        ).status,
        403,
      );
      for (const url of [
        "/",
        "/projects",
        "/project/legacy-project",
        "/page/about",
        "/services/wood-framing",
        "/robots.txt",
        "/sitemap.xml",
        "/admin/projects",
        "/admin/pages",
        "/admin/services",
        "/admin/service-areas",
        "/admin/navigation",
        "/admin/seo",
        "/admin/media",
        "/admin/home",
        "/admin/sections",
        "/admin/settings",
        "/admin/leads",
        "/admin/mail",
        "/admin/users",
      ]) {
        const res = await owner.get(url);
        assert.equal(res.status, 200, `${url}: ${res.text.slice(0, 200)}`);
      }
      for (const key of [
        "projects",
        "pages",
        "services",
        "service-areas",
        "navigation",
        "seo",
      ])
        assert.equal((await owner.get(`/admin/${key}/new`)).status, 200, key);
      assert.equal(
        (
          await send("/admin/projects/save", {
            slug: "new-case-study",
            name: "New Case Study",
            visible: "1",
            featured: "1",
            client: "Client",
            scope: "Framing scope",
            square_footage: "12345",
            completion_date: "2026-10-01",
            service_id: "wood",
            stats: "2 floors",
          })
        ).status,
        302,
      );
      const [project] = await db.query("SELECT * FROM projects WHERE slug=?", [
        "new-case-study",
      ]);
      assert.equal(project.square_footage, 12345);
      assert.equal(
        (
          await send("/admin/projects/save", {
            slug: "new-case-study",
            name: "Duplicate",
          })
        ).status,
        409,
      );
      assert.equal(
        (await owner.get(`/admin/projects/${project.id}`)).status,
        200,
      );
      assert.equal(
        (
          await send("/admin/pages/save", {
            slug: "custom-page",
            title: "Custom Page",
            visible: "1",
            content: "<h2>Content</h2><script>attack()</script>",
          })
        ).status,
        302,
      );
      assert.equal(
        (
          await send("/admin/service-areas/save", {
            slug: "maryland",
            title: "Maryland",
            visible: "1",
            location: "Maryland",
            content: "<p>Local work</p>",
          })
        ).status,
        302,
      );
      assert.equal((await owner.get("/service-areas/maryland")).status, 200);
      assert.equal(
        (await owner.get("/page/custom-page")).text.includes("attack()"),
        false,
      );
      assert.equal(
        (
          await send("/admin/pages/save", {
            slug: "private-page",
            title: "Private",
            robots: "noindex,follow",
            visible: "1",
          })
        ).status,
        302,
      );
      assert.equal(
        (await owner.get("/sitemap.xml")).text.includes("private-page"),
        false,
      );
      assert.equal(
        (
          await send("/admin/navigation/save", {
            label: "Custom",
            page_slug: "custom-page",
            visible: "1",
          })
        ).status,
        302,
      );
      assert.match((await owner.get("/")).text, /href="\/page\/custom-page"/);
      assert.equal(
        (await send("/admin/sections/values", { sort_order: "99" })).status,
        302,
      );
      assert.equal(
        (await owner.get("/")).text.includes('class="values"'),
        false,
      );
      // Upload checks actual bytes, not the browser's content-type or extension.
      const png = Buffer.from(
        "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nXsAAAAASUVORK5CYII=",
        "base64",
      );
      assert.equal(
        (
          await owner
            .post("/admin/media/upload")
            .set("x-csrf-token", csrf)
            .attach("image", Buffer.from("<script>bad</script>"), {
              filename: "fake.png",
              contentType: "image/png",
            })
        ).status,
        422,
      );
      assert.equal(
        (
          await owner
            .post("/admin/media/upload")
            .attach("image", png, "image.png")
        ).status,
        403,
      );
      assert.equal(
        (
          await owner
            .post("/admin/media/upload")
            .set("x-csrf-token", csrf)
            .field("title", "Reusable")
            .field("alt_text", "A framing photo")
            .attach("image", png, "image.png")
        ).status,
        201,
      );
      const [media] = await db.query(
        "SELECT * FROM media WHERE title='Reusable'",
      );
      assert.equal(
        (
          await owner
            .post(`/admin/media/${media.id}/replace`)
            .set("x-csrf-token", csrf)
            .attach("image", png, "replacement.png")
        ).status,
        200,
      );
      assert.equal(
        (
          await send(`/admin/projects/${project.id}/gallery`, {
            media_id: media.id,
            sort_order: "0",
            image_kind: "before",
            caption: "Before construction",
          })
        ).status,
        302,
      );
      assert.equal(
        (await send(`/admin/media/${media.id}/delete`, {})).status,
        409,
      );
      assert.match(
        (await owner.get("/project/new-case-study")).text,
        /Before construction/,
      );
      assert.equal(
        (
          await send("/admin/settings", {
            site_name: "Galindos Builders LLC",
            header_logo_url: media.url,
            meta_image_url: media.url,
            phone: "555-0100",
          })
        ).status,
        302,
      );
      assert.match(
        (await owner.get("/project/legacy-project")).text,
        /property="og:image"/,
      );
      // Public lead creation and both emails are one transaction.
      const visitor = request.agent(app),
        home = await visitor.get("/");
      const visitorCsrf = token(home);
      assert.equal(
        (
          await visitor.post("/quote").type("form").send({
            csrf: visitorCsrf,
            name: "Customer",
            email: "customer@example.com",
            message: "Residential framing quote please.",
          })
        ).status,
        303,
      );
      const [lead] = await db.query("SELECT * FROM quote_submissions");
      assert.equal(lead.status, "New");
      assert.equal((await db.query("SELECT * FROM mail_outbox")).length, 2);
      assert.equal((await owner.get(`/admin/leads/${lead.id}`)).status, 200);
      assert.equal(
        (
          await send(`/admin/leads/${lead.id}`, {
            status: "Estimate Scheduled",
            assigned_to: "1",
            note: "Call Tuesday",
          })
        ).status,
        302,
      );
      assert.equal(
        (await db.query("SELECT * FROM lead_notes"))[0].note,
        "Call Tuesday",
      );
      const sent = [];
      await deliverMail(db, cfg, {
        sendMail: async (message) => sent.push(message),
      });
      assert.equal(sent.length, 2);
      assert.equal(
        (await db.query("SELECT * FROM mail_outbox WHERE sent_at IS NULL"))
          .length,
        0,
      );
      await db.query(
        "INSERT INTO mail_outbox(recipient,subject,body,available_at) VALUES('test@example.com','Retry','Body',UTC_TIMESTAMP())",
      );
      await deliverMail(db, cfg, {
        sendMail: async () => {
          throw Object.assign(new Error("failure"), { code: "ECONNECTION" });
        },
      });
      const [failed] = await db.query(
        "SELECT * FROM mail_outbox WHERE sent_at IS NULL",
      );
      assert.equal(failed.attempts, 1);
      assert.equal(failed.last_error, "ECONNECTION");
      await send(`/admin/mail/${failed.id}/retry`, {});
      await deliverMail(db, cfg, {
        sendMail: async (message) => sent.push(message),
      });
      assert.equal(sent.length, 3);
      // Session persistence survives another app instance.
      const cookie = page.headers["set-cookie"][0].split(";")[0];
      assert.equal(
        (
          await request(createApp({ db, cfg }))
            .get("/admin")
            .set("Cookie", cookie)
        ).status,
        200,
      );
      assert.equal(
        (
          await send("/admin/users", {
            username: "editor",
            email: "",
            password: "editor-password-123",
            role: "editor",
          })
        ).status,
        302,
      );
      const editor = request.agent(app);
      let editorPage = await editor.get("/admin/login");
      await editor
        .post("/admin/login")
        .type("form")
        .send({
          csrf: token(editorPage),
          username: "editor",
          password: "editor-password-123",
        });
      editorPage = await editor.get("/admin");
      assert.equal((await editor.get("/admin/leads")).status, 403);
      assert.equal((await editor.get("/admin/settings")).status, 403);
      assert.equal((await editor.get("/admin/projects")).status, 200);
      assert.equal(
        (
          await editor
            .post("/admin/users")
            .type("form")
            .send({ csrf: token(editorPage) })
        ).status,
        403,
      );
      assert.equal(
        (await send("/admin/users/1", { role: "sales", active: "1" })).status,
        422,
      );
      assert.equal(
        (await owner.get("/project.php?job=legacy-project")).headers.location,
        "/project/legacy-project",
      );
      assert.equal((await visitor.get("/data/admins.json")).status, 404);
      assert.equal((await visitor.get("/.env")).status, 404);
      assert.equal((await visitor.get("/page/missing")).status, 404);
      await send("/admin/logout", {});
      assert.equal((await owner.get("/admin")).status, 302);
      // Legacy import is conservative when live normalized data already exists.
      await db.query("INSERT INTO site_store(store_key,payload) VALUES(?,?)", [
        "projects",
        JSON.stringify([{ id: "ignored", name: "Ignored", slug: "ignored" }]),
      ]);
      await importLegacy(db, uploadDir);
      assert.equal(
        (await db.query("SELECT * FROM projects WHERE id='ignored'")).length,
        0,
      );
      // An empty destination imports site_store content with galleries and preserves reruns.
      await db.query("DELETE FROM projects");
      await db.query(
        "UPDATE site_store SET payload=? WHERE store_key='projects'",
        [
          JSON.stringify([
            {
              id: "imported",
              name: "Imported",
              slug: "imported",
              images: ["/uploads/projects/imported.jpg"],
              featured: true,
            },
          ]),
        ],
      );
      await importLegacy(db, uploadDir);
      await migrate(db);
      await importLegacy(db, uploadDir);
      assert.equal((await db.query("SELECT * FROM projects")).length, 1);
      assert.equal((await db.query("SELECT * FROM project_images")).length, 1);
      assert.equal(
        (
          await db.query(
            "SELECT * FROM media WHERE url='/uploads/projects/imported.jpg'",
          )
        ).length,
        1,
      );
    } finally {
      await db.close();
      await admin.query(`DROP DATABASE \`${name}\``);
      await admin.end();
      await fs.rm(uploadDir, { recursive: true, force: true });
    }
  },
);
