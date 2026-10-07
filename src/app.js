import express from "express";
import session from "express-session";
import helmet from "helmet";
import compression from "compression";
import { rateLimit } from "express-rate-limit";
import { z } from "zod";
import { root } from "./config.js";
import { csrf, SqlSessionStore, can, httpError, throttle } from "./security.js";
import { siteContent, metadata } from "./content.js";
import { adminRouter } from "./admin.js";
import { queueQuoteMail } from "./mail.js";
const xml = (value) =>
  String(value).replace(
    /[<>&"']/g,
    (c) =>
      ({
        "<": "&lt;",
        ">": "&gt;",
        "&": "&amp;",
        '"': "&quot;",
        "'": "&apos;",
      })[c],
  );
export function createApp({ db, cfg, sessionStore = new SqlSessionStore(db) }) {
  const app = express();
  app.disable("x-powered-by");
  app.set("trust proxy", cfg.trustProxy);
  app.set("view engine", "ejs");
  app.set("views", `${root}/views`);
  app.use(
    helmet({
      contentSecurityPolicy: {
        directives: {
          defaultSrc: ["'self'"],
          scriptSrc: ["'self'"],
          styleSrc: [
            "'self'",
            "'unsafe-inline'",
            "https://fonts.googleapis.com",
          ],
          fontSrc: ["'self'", "https://fonts.gstatic.com"],
          imgSrc: ["'self'", "https:", "data:"],
          upgradeInsecureRequests: cfg.production ? [] : null,
        },
      },
      strictTransportSecurity: cfg.production ? undefined : false,
    }),
  );
  app.use(compression());
  app.get("/healthz", async (req, res) => {
    await db.query("SELECT 1");
    res.json({ status: "ok" });
  });
  app.use(
    "/assets",
    express.static(`${root}/assets`, { maxAge: "1d", dotfiles: "deny" }),
  );
  app.get("/admin/admin.css", (req, res) =>
    res.sendFile(`${root}/admin/admin.css`),
  );
  app.use(
    "/uploads",
    express.static(cfg.uploadDir, {
      dotfiles: "deny",
      index: false,
      setHeaders(res, file) {
        res.set("Content-Security-Policy", "default-src 'none'; sandbox");
        if (!/\.(jpe?g|png|webp|gif|ico|svg)$/i.test(file))
          res.set("Content-Disposition", "attachment");
      },
    }),
  );
  app.use(
    rateLimit({
      windowMs: 60 * 1000,
      limit: 300,
      standardHeaders: "draft-8",
      legacyHeaders: false,
    }),
  );
  app.use(express.urlencoded({ extended: false, limit: "150kb" }));
  app.use(express.json({ limit: "150kb" }));
  app.use(
    session({
      name: "gb.sid",
      secret: cfg.secret,
      store: sessionStore,
      resave: false,
      saveUninitialized: false,
      rolling: true,
      cookie: {
        httpOnly: true,
        sameSite: "lax",
        secure: cfg.production,
        maxAge: 8 * 3600000,
      },
    }),
  );
  app.use(csrf);
  app.use(async (req, res, next) => {
    res.locals.user = null;
    res.locals.can = can;
    if (req.session.userId) {
      if (
        !req.session.loginAt ||
        Date.now() - req.session.loginAt > 24 * 3600000
      ) {
        delete req.session.userId;
      } else {
        const [user] = await db.query(
          "SELECT id,username,role,active FROM admins WHERE id=? AND active=1",
          [req.session.userId],
        );
        res.locals.user = user || null;
      }
    }
    next();
  });
  app.use("/admin", adminRouter(db, cfg));
  // Bookmarks from the PHP site keep working, with permanent redirects to Express routes.
  for (const old of [
    "/index.php",
    "/projects.php",
    "/project.php",
    "/project",
    "/page.php",
    "/page",
  ])
    app.get(old, (req, res) => {
      const target = old.includes("projects")
        ? "/projects"
        : old.includes("project")
          ? `/project/${encodeURIComponent(req.query.job || "")}`
          : old.includes("page")
            ? `/page/${encodeURIComponent(req.query.slug || "")}`
            : "/";
      res.redirect(301, target);
    });
  const render = async (req, res, view, item = {}, status = 200) => {
    const site = await siteContent(db);
    res.status(status).render(view, {
      ...site,
      item,
      meta: metadata(site, cfg, req.path, item),
      sent: req.query.sent === "1",
    });
  };
  app.get("/", (req, res) => render(req, res, "home"));
  app.get("/projects", (req, res) =>
    render(req, res, "projects", {
      title: "Projects",
      description:
        "Explore framing and construction projects completed by Galindos Builders LLC.",
    }),
  );
  app.get("/project/:slug", async (req, res) => {
    const site = await siteContent(db),
      item = site.projects.find((p) => p.slug === req.params.slug);
    if (!item) throw httpError(404, "Project not found.");
    res.render("project", {
      ...site,
      item,
      meta: metadata(site, cfg, req.path, item),
    });
  });
  for (const [route, table] of [
    ["page", "pages"],
    ["services", "services"],
    ["service-areas", "service_areas"],
  ])
    app.get(`/${route}/:slug`, async (req, res) => {
      const [item] = await db.query(
        `SELECT * FROM ${table} WHERE slug=? AND visible=1`,
        [req.params.slug],
      );
      if (!item) throw httpError(404, "Page not found.");
      await render(req, res, "page", {
        ...item,
        title: item.title || item.name,
        route,
      });
    });
  app.post("/quote", throttle(db, "quotes", 5, 3600), async (req, res) => {
    const lead = z
      .object({
        name: z.string().trim().min(2).max(255),
        email: z.email().max(255),
        phone: z.string().trim().max(80).default(""),
        project_type: z.string().trim().max(160).default(""),
        location: z.string().trim().max(255).default(""),
        message: z.string().trim().min(10).max(10000),
      })
      .parse(req.body);
    await db.transaction(async (tx) => {
      await tx.query(
        "INSERT INTO quote_submissions(name,email,phone,project_type,location,message,status) VALUES(?,?,?,?,?,?,'New')",
        Object.values(lead),
      );
      await queueQuoteMail(tx, cfg, lead);
    });
    res.redirect(303, "/?sent=1#contact");
  });
  app.get("/robots.txt", (req, res) =>
    res
      .type("text/plain")
      .send(
        `User-agent: *\nDisallow: /admin/\nSitemap: ${cfg.siteUrl}/sitemap.xml\n`,
      ),
  );
  app.get("/sitemap.xml", async (req, res) => {
    const site = await siteContent(db),
      pages = await db.query("SELECT * FROM pages WHERE visible=1");
    const entries = [
      ["/", {}],
      ["/projects", {}],
      ...site.projects.map((p) => [
        `/project/${encodeURIComponent(p.slug)}`,
        p,
      ]),
      ...pages.map((p) => [`/page/${encodeURIComponent(p.slug)}`, p]),
      ...site.services.map((p) => [
        `/services/${encodeURIComponent(p.slug)}`,
        p,
      ]),
      ...site.areas.map((p) => [
        `/service-areas/${encodeURIComponent(p.slug)}`,
        p,
      ]),
    ];
    res.type("application/xml").send(
      `<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${entries
        .filter(([url, item]) => {
          const meta = metadata(site, cfg, url, item);
          return (
            !meta.robots.includes("noindex") &&
            meta.canonical === cfg.siteUrl + url
          );
        })
        .map(([url]) => `<url><loc>${xml(cfg.siteUrl + url)}</loc></url>`)
        .join("")}</urlset>`,
    );
  });
  app.use((req, res, next) =>
    next(httpError(404, "The page you requested could not be found.")),
  );
  app.use((err, req, res, next) => {
    if (res.headersSent) return next(err);
    let status = err.status || 500,
      message = err.message;
    if (err instanceof z.ZodError) {
      status = 422;
      message = err.issues
        .map((i) => `${i.path.join(".")}: ${i.message}`)
        .join("; ");
    }
    if (err.code === "ER_DUP_ENTRY") {
      status = 409;
      message = "That username, slug or key already exists.";
    }
    if (err.code === "LIMIT_FILE_SIZE") {
      status = 413;
      message = "Image must be under 10 MB.";
    }
    if (status >= 500) {
      console.error("Request failed:", err.code || err.name);
      message = "We could not complete the request. Please try again later.";
    }
    res.status(status).render("error", { status, message });
  });
  return app;
}
