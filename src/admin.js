import { Router } from "express";
import crypto from "node:crypto";
import argon2 from "argon2";
import { z } from "zod";
import { can, httpError, verifyPassword, throttle } from "./security.js";
import {
  resources,
  settingsFields,
  homeFields,
  parseFields,
  leadStatuses,
} from "./admin-fields.js";
import { defaultHome, defaultSettings, defaultServices } from "./content.js";
import { upload, saveMedia, deleteMedia, replaceMedia } from "./media.js";
const id = () => crypto.randomBytes(16).toString("hex");
const accountSchema = z.object({
  username: z
    .string()
    .trim()
    .min(3)
    .max(100)
    .regex(/^[a-zA-Z0-9_.-]+$/),
  password: z.string().min(12).max(128),
  email: z.union([z.email(), z.literal("")]).default(""),
  role: z.enum(["owner", "editor", "sales"]).default("owner"),
});
const saveSession = (req) =>
  new Promise((resolve, reject) =>
    req.session.save((e) => (e ? reject(e) : resolve())),
  );
const regenerate = (req) =>
  new Promise((resolve, reject) =>
    req.session.regenerate((e) => (e ? reject(e) : resolve())),
  );
export function adminRouter(db, cfg) {
  const router = Router();
  const allow = (permission) => (req, res, next) =>
    can(res.locals.user, permission)
      ? next()
      : next(
          httpError(
            403,
            "Your account does not have permission for this action.",
          ),
        );
  const render = (res, title, data = {}) =>
    res.render("admin", {
      title,
      resources,
      leadStatuses,
      fields: {},
      record: {},
      items: [],
      mode: "dashboard",
      ...data,
    });
  router.use((req, res, next) => {
    res.set("Cache-Control", "no-store");
    res.set("X-Robots-Tag", "noindex, nofollow");
    next();
  });
  router.get("/login", async (req, res) => {
    if (res.locals.user) return res.redirect("/admin");
    const [count] = await db.query("SELECT COUNT(*) AS n FROM admins");
    res.render("login", { setup: !count.n, error: "" });
  });
  router.post("/login", throttle(db, "login", 10, 900), async (req, res) => {
    const parsed = z
      .object({
        username: z.string().trim().max(100),
        password: z.string().max(128),
      })
      .parse(req.body);
    const [user] = await db.query(
      "SELECT * FROM admins WHERE username=? AND active=1",
      [parsed.username],
    );
    const fallback =
      "$2b$12$A6Y3fzRjoPTMUbfFmWiMVeSMhWI.hJrcjrPGCvJP1ioTLDHmYB7Eu";
    const valid = await verifyPassword(
      parsed.password,
      user?.password_hash || fallback,
    );
    if (!user || !valid)
      return res.status(401).render("login", {
        setup: false,
        error: "The username or password is incorrect.",
      });
    await regenerate(req);
    req.session.userId = user.id;
    req.session.loginAt = Date.now();
    await saveSession(req);
    res.redirect("/admin");
  });
  router.get("/setup", (req, res) => res.redirect("/admin/login"));
  router.post("/setup", throttle(db, "setup", 5, 900), async (req, res) => {
    if (
      !cfg.setupToken ||
      typeof req.body.setup_token !== "string" ||
      Buffer.byteLength(req.body.setup_token) !==
        Buffer.byteLength(cfg.setupToken) ||
      !crypto.timingSafeEqual(
        Buffer.from(req.body.setup_token),
        Buffer.from(cfg.setupToken),
      )
    )
      throw httpError(403, "The setup token is invalid.");
    const data = accountSchema.parse({ ...req.body, role: "owner" }),
      hash = await argon2.hash(data.password);
    const connection = await db.pool.getConnection();
    try {
      const [[lock]] = await connection.execute(
        "SELECT GET_LOCK('galindos_first_admin',10) AS acquired",
      );
      if (!lock.acquired) throw httpError(409, "Setup is busy. Try again.");
      const [[count]] = await connection.execute(
        "SELECT COUNT(*) AS n FROM admins",
      );
      if (count.n) throw httpError(409, "Setup is already complete.");
      await connection.execute(
        "INSERT INTO admins(username,password_hash,email,role) VALUES(?,?,?,?)",
        [data.username, hash, data.email, "owner"],
      );
    } finally {
      await connection.execute("SELECT RELEASE_LOCK('galindos_first_admin')");
      connection.release();
    }
    res.redirect("/admin/login");
  });
  router.use((req, res, next) =>
    res.locals.user ? next() : res.redirect("/admin/login"),
  );
  router.post("/logout", async (req, res) => {
    await new Promise((resolve, reject) =>
      req.session.destroy((e) => (e ? reject(e) : resolve())),
    );
    res.clearCookie("gb.sid");
    res.redirect("/admin/login");
  });
  router.get("/", async (req, res) => {
    const [projects] = await db.query("SELECT COUNT(*) AS n FROM projects");
    const [leads] = can(res.locals.user, "leads")
      ? await db.query("SELECT COUNT(*) AS n FROM quote_submissions")
      : [{ n: "—" }];
    const [mail] = can(res.locals.user, "leads")
      ? await db.query(
          "SELECT COUNT(*) AS n FROM mail_outbox WHERE sent_at IS NULL",
        )
      : [{ n: "—" }];
    render(res, "Content Dashboard", {
      stats: [
        ["Projects", projects.n],
        ["Quote requests", leads.n],
        ["Pending emails", mail.n],
      ],
    });
  });
  for (const [key, resource] of Object.entries(resources)) {
    const table = resource.table,
      pk = resource.id || "id",
      fields = resource.fields;
    router.get(`/${key}`, allow(resource.permission), async (req, res) =>
      render(res, resource.label, {
        mode: "list",
        key,
        resource,
        items: await db.query(`SELECT * FROM ${table} ORDER BY ${pk}`),
      }),
    );
    router.get(`/${key}/new`, allow(resource.permission), async (req, res) =>
      render(res, `Add ${resource.label}`, {
        mode: "edit",
        key,
        resource,
        fields,
        record: { visible: 1, robots: "index,follow" },
        services: await db.query("SELECT id,name FROM services"),
        media: await db.query("SELECT * FROM media ORDER BY created_at DESC"),
      }),
    );
    router.get(`/${key}/:id`, allow(resource.permission), async (req, res) => {
      const [record] = await db.query(`SELECT * FROM ${table} WHERE ${pk}=?`, [
        req.params.id,
      ]);
      if (!record) throw httpError(404, "Record not found.");
      const gallery =
        key === "projects"
          ? await db.query(
              "SELECT * FROM project_images WHERE project_id=? ORDER BY sort_order,id",
              [record.id],
            )
          : [];
      render(res, `Edit ${resource.label}`, {
        mode: "edit",
        key,
        resource,
        fields,
        record,
        gallery,
        services: await db.query("SELECT id,name FROM services"),
        media: await db.query("SELECT * FROM media ORDER BY created_at DESC"),
      });
    });
    router.post(
      `/${key}/save`,
      allow(resource.permission),
      async (req, res) => {
        const data = parseFields(fields, req.body),
          existing =
            typeof req.body.record_id === "string" ? req.body.record_id : "";
        if (
          key === "projects" &&
          data.service_id &&
          !(
            await db.query("SELECT id FROM services WHERE id=?", [
              data.service_id,
            ])
          ).length
        )
          throw httpError(422, "Select an existing service.");
        await db.transaction(async (tx) => {
          if (existing) {
            const current = await tx.query(
              `SELECT * FROM ${table} WHERE ${pk}=? FOR UPDATE`,
              [existing],
            );
            if (!current.length) throw httpError(404, "Record not found.");
            const cols = Object.keys(data);
            await tx.query(
              `UPDATE ${table} SET ${cols.map((k) => `\`${k}\`=?`).join(",")} WHERE ${pk}=?`,
              [...Object.values(data), existing],
            );
            if (key === "pages" && current[0].slug !== data.slug)
              await tx.query(
                "UPDATE navigation_tabs SET page_slug=? WHERE page_slug=?",
                [data.slug, current[0].slug],
              );
          } else {
            if (pk === "id") data.id = id();
            const cols = Object.keys(data);
            await tx.query(
              `INSERT INTO ${table}(${cols.map((k) => `\`${k}\``).join(",")}) VALUES(${cols.map(() => "?").join(",")})`,
              Object.values(data),
            );
          }
        });
        res.redirect(`/admin/${key}`);
      },
    );
    router.post(
      `/${key}/:id/delete`,
      allow(resource.permission),
      async (req, res) => {
        await db.transaction(async (tx) => {
          if (key === "pages") {
            const [page] = await tx.query("SELECT slug FROM pages WHERE id=?", [
              req.params.id,
            ]);
            if (page)
              await tx.query(
                "UPDATE navigation_tabs SET visible=0 WHERE page_slug=?",
                [page.slug],
              );
          }
          if (key === "services")
            await tx.query(
              "UPDATE projects SET service_id=NULL WHERE service_id=?",
              [req.params.id],
            );
          await tx.query(`DELETE FROM ${table} WHERE ${pk}=?`, [req.params.id]);
        });
        res.redirect(`/admin/${key}`);
      },
    );
  }
  router.post("/projects/:id/gallery", allow("content"), async (req, res) => {
    const data = z
      .object({
        media_id: z.string().length(32),
        alt_text: z.string().max(255).default(""),
        caption: z.string().max(255).default(""),
        image_kind: z.enum(["gallery", "before", "after"]),
        sort_order: z.coerce.number().int().min(-100000).max(100000),
      })
      .parse(req.body);
    const [media] = await db.query("SELECT * FROM media WHERE id=?", [
      data.media_id,
    ]);
    if (!media) throw httpError(422, "Select an existing library image.");
    await db.query(
      "INSERT INTO project_images(project_id,media_id,image_url,alt_text,caption,image_kind,sort_order) VALUES(?,?,?,?,?,?,?)",
      [
        req.params.id,
        data.media_id,
        media.url,
        data.alt_text || media.alt_text,
        data.caption,
        data.image_kind,
        data.sort_order,
      ],
    );
    res.redirect(`/admin/projects/${req.params.id}`);
  });
  router.post(
    "/projects/:id/gallery/:image",
    allow("content"),
    async (req, res) => {
      if (req.body.action === "remove")
        await db.query(
          "DELETE FROM project_images WHERE id=? AND project_id=?",
          [req.params.image, req.params.id],
        );
      else {
        const data = z
          .object({
            sort_order: z.coerce.number().int().min(-100000).max(100000),
            alt_text: z.string().max(255),
            caption: z.string().max(255),
            image_kind: z.enum(["gallery", "before", "after"]),
          })
          .parse(req.body);
        await db.query(
          "UPDATE project_images SET sort_order=?,alt_text=?,caption=?,image_kind=? WHERE id=? AND project_id=?",
          [
            data.sort_order,
            data.alt_text,
            data.caption,
            data.image_kind,
            req.params.image,
            req.params.id,
          ],
        );
      }
      res.redirect(`/admin/projects/${req.params.id}`);
    },
  );
  router.get("/media", allow("content"), async (req, res) =>
    render(res, "Media Library", {
      mode: "media",
      items: await db.query("SELECT * FROM media ORDER BY created_at DESC"),
    }),
  );
  // CSRF is checked from the header before buffering multipart requests.
  router.post("/media/upload", allow("content"), upload, async (req, res) => {
    await saveMedia(db, cfg, req.file, req.body);
    res.status(201).json({ redirect: "/admin/media" });
  });
  router.post(
    "/media/:id/replace",
    allow("content"),
    upload,
    async (req, res) => {
      await replaceMedia(db, cfg, req.params.id, req.file);
      res.json({ redirect: "/admin/media" });
    },
  );
  router.post("/media/:id/save", allow("content"), async (req, res) => {
    const data = z
      .object({
        title: z.string().trim().max(255),
        alt_text: z.string().trim().max(255),
        folder: z.string().trim().max(100),
      })
      .parse(req.body);
    await db.query("UPDATE media SET title=?,alt_text=?,folder=? WHERE id=?", [
      data.title,
      data.alt_text,
      data.folder,
      req.params.id,
    ]);
    res.redirect("/admin/media");
  });
  router.post("/media/:id/delete", allow("content"), async (req, res) => {
    await deleteMedia(db, cfg, req.params.id);
    res.redirect("/admin/media");
  });
  router.post(
    "/settings/images/:key",
    allow("settings"),
    (req, res, next) => {
      if (!["favicon_url", "meta_image_url"].includes(req.params.key))
        return next(httpError(422, "Choose a favicon or social media image."));
      next();
    },
    upload,
    async (req, res) => {
      const key = req.params.key;
      const title =
        key === "favicon_url" ? "Website favicon" : "Social media image";
      const mediaId = await saveMedia(
        db,
        cfg,
        req.file,
        {
          title,
          alt_text: title,
          folder: "Branding",
        },
        {
          settingKey: key,
          ...(key === "meta_image_url"
            ? {
                allowedMimeTypes: [
                  "image/jpeg",
                  "image/png",
                  "image/webp",
                  "image/gif",
                ],
              }
            : {}),
        },
      );
      const [media] = await db.query("SELECT url FROM media WHERE id=?", [
        mediaId,
      ]);
      res.status(201).json({ key, url: media.url, title });
    },
  );
  for (const [key, fields, permission] of [
    ["home", homeFields, "content"],
    ["settings", settingsFields, "settings"],
  ]) {
    router.get(`/${key}`, allow(permission), async (req, res) => {
      const settings = Object.fromEntries(
        (await db.query("SELECT * FROM site_settings")).map((r) => [
          r.setting_key,
          r.setting_value,
        ]),
      );
      const record =
        key === "home"
          ? { ...defaultHome, ...JSON.parse(settings.home || "{}") }
          : { ...defaultSettings, ...settings };
      render(res, key === "home" ? "Homepage Content" : "Settings & Branding", {
        mode: "settings",
        key,
        fields,
        record,
        media: await db.query("SELECT * FROM media ORDER BY created_at DESC"),
      });
    });
    router.post(`/${key}`, allow(permission), async (req, res) => {
      const data = parseFields(fields, req.body);
      await db.transaction(async (tx) => {
        for (const [k, v] of Object.entries(
          key === "home" ? { home: JSON.stringify(data) } : data,
        ))
          await tx.query(
            "INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",
            [k, v],
          );
      });
      res.redirect(`/admin/${key}`);
    });
  }
  router.get("/sections", allow("content"), async (req, res) =>
    render(res, "Homepage Sections", {
      mode: "sections",
      items: await db.query(
        "SELECT * FROM homepage_sections ORDER BY sort_order,section_key",
      ),
    }),
  );
  router.post("/sections/:key", allow("content"), async (req, res) => {
    const data = parseFields(
      { enabled: { type: "checkbox" }, sort_order: { type: "number" } },
      req.body,
    );
    await db.query(
      "UPDATE homepage_sections SET enabled=?,sort_order=? WHERE section_key=?",
      [data.enabled, data.sort_order, req.params.key],
    );
    res.redirect("/admin/sections");
  });
  router.get("/leads", allow("leads"), async (req, res) => {
    const status = z.enum([...leadStatuses, ""]).parse(req.query.status || "");
    const items = await db.query(
      `SELECT q.*,a.username AS assignee FROM quote_submissions q LEFT JOIN admins a ON a.id=q.assigned_to ${status ? "WHERE q.status=?" : ""} ORDER BY submitted_at DESC LIMIT 500`,
      status ? [status] : [],
    );
    render(res, "Quote Requests", { mode: "leads", items, status });
  });
  router.get("/leads/:id", allow("leads"), async (req, res) => {
    const [record] = await db.query(
      "SELECT * FROM quote_submissions WHERE id=?",
      [req.params.id],
    );
    if (!record) throw httpError(404, "Lead not found.");
    render(res, `Request from ${record.name}`, {
      mode: "lead",
      record,
      users: await db.query("SELECT id,username FROM admins WHERE active=1"),
      notes: await db.query(
        "SELECT n.*,a.username FROM lead_notes n JOIN admins a ON a.id=n.author_id WHERE lead_id=? ORDER BY n.created_at DESC",
        [record.id],
      ),
    });
  });
  router.post("/leads/:id", allow("leads"), async (req, res) => {
    const data = z
      .object({
        status: z.enum(leadStatuses),
        assigned_to: z.string().regex(/^\d*$/),
        note: z.string().trim().max(10000),
      })
      .parse(req.body);
    await db.transaction(async (tx) => {
      if (
        !(
          await tx.query(
            "SELECT id FROM quote_submissions WHERE id=? FOR UPDATE",
            [req.params.id],
          )
        ).length
      )
        throw httpError(404, "Lead not found.");
      if (
        data.assigned_to &&
        !(
          await tx.query("SELECT id FROM admins WHERE id=? AND active=1", [
            data.assigned_to,
          ])
        ).length
      )
        throw httpError(422, "Choose an active assignee.");
      await tx.query(
        "UPDATE quote_submissions SET status=?,assigned_to=? WHERE id=?",
        [data.status, data.assigned_to || null, req.params.id],
      );
      if (data.note)
        await tx.query(
          "INSERT INTO lead_notes(lead_id,author_id,note) VALUES(?,?,?)",
          [req.params.id, res.locals.user.id, data.note],
        );
    });
    res.redirect(`/admin/leads/${req.params.id}`);
  });
  router.get("/users", allow("users"), async (req, res) =>
    render(res, "Administrator Accounts", {
      mode: "users",
      items: await db.query(
        "SELECT id,username,email,role,active FROM admins ORDER BY id",
      ),
    }),
  );
  router.post("/users", allow("users"), async (req, res) => {
    const data = accountSchema.parse(req.body);
    await db.query(
      "INSERT INTO admins(username,password_hash,email,role) VALUES(?,?,?,?)",
      [data.username, await argon2.hash(data.password), data.email, data.role],
    );
    res.redirect("/admin/users");
  });
  router.post("/users/:id", allow("users"), async (req, res) => {
    const data = z
      .object({
        role: z.enum(["owner", "editor", "sales"]),
        active: z.preprocess((v) => (v === "1" ? 1 : 0), z.number()),
        password: z
          .union([z.string().min(12).max(128), z.literal("")])
          .default(""),
      })
      .parse(req.body);
    const hash = data.password ? await argon2.hash(data.password) : null;
    await db.transaction(async (tx) => {
      const owners = await tx.query(
        "SELECT id FROM admins WHERE role='owner' AND active=1 ORDER BY id FOR UPDATE",
      );
      if (
        String(res.locals.user.id) === req.params.id &&
        (!data.active || data.role !== "owner")
      )
        throw httpError(422, "You cannot remove your own owner access.");
      if (
        owners.some((u) => String(u.id) === req.params.id) &&
        owners.length === 1 &&
        (!data.active || data.role !== "owner")
      )
        throw httpError(422, "At least one active owner is required.");
      await tx.query(
        "UPDATE admins SET role=?,active=?,password_hash=COALESCE(?,password_hash) WHERE id=?",
        [data.role, data.active, hash, req.params.id],
      );
      if (hash)
        await tx.query(
          "DELETE FROM sessions WHERE JSON_UNQUOTE(JSON_EXTRACT(data,'$.userId'))=?",
          [req.params.id],
        );
    });
    res.redirect("/admin/users");
  });
  router.get("/mail", allow("leads"), async (req, res) =>
    render(res, "Email Delivery", {
      mode: "mail",
      items: await db.query(
        "SELECT id,recipient,subject,attempts,sent_at,last_error FROM mail_outbox ORDER BY id DESC LIMIT 200",
      ),
      mailConfigured: !!(cfg.mail.host && cfg.from && cfg.notify),
    }),
  );
  router.post("/mail/:id/retry", allow("leads"), async (req, res) => {
    await db.query(
      "UPDATE mail_outbox SET attempts=0,available_at=UTC_TIMESTAMP(),locked_until=NULL WHERE id=? AND sent_at IS NULL AND (locked_until IS NULL OR locked_until<UTC_TIMESTAMP())",
      [req.params.id],
    );
    res.redirect("/admin/mail");
  });
  return router;
}
