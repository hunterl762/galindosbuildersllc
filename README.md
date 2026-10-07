# Galindos Builders LLC — Node.js CMS v2

Express 5, EJS server-rendered pages, MySQL/MariaDB and a complete administrator CMS. The approved orange/charcoal construction design, responsive project galleries and sticky navigation are retained. PHP and Apache rewrite rules are no longer required.

## Requirements and local setup

- Node.js 24 or newer; MySQL 8+ or MariaDB 10.4+.
- An existing database and a dedicated database user. Migrations require CREATE, ALTER, INDEX, SELECT, INSERT and UPDATE privileges. The runtime user needs SELECT, INSERT, UPDATE and DELETE only.
- A writable persistent uploads directory. Keep it outside ephemeral release folders when deploying.

1. Run `npm ci`.
2. Copy `.env.example` to `.env`. Set the database credentials, `SITE_URL` and a random `SESSION_SECRET` of at least 32 characters. Generate the secret with `node -e "console.log(require('crypto').randomBytes(48).toString('hex'))"`.
3. Run `npm run migrate` using credentials with schema privileges.
4. Run `npm start`, then open http://localhost:3000.
5. On a new installation, set a random `SETUP_TOKEN`, restart the app, and open `/admin/login`. Submit that token with your new username and a password of at least 12 characters. Once an administrator exists, setup is locked. Remove `SETUP_TOKEN` and restart.

The app deliberately fails when the database is unavailable; it never silently switches to empty JSON storage. Schema changes run through the migration command, not during normal requests.

## Migrating an existing PHP installation

1. Schedule a maintenance window and stop PHP writes. Back up the complete MySQL database, `data/` JSON files and `uploads/` directory before changing anything. Keep the old PHP release for rollback.
2. Install this branch in a separate release directory. Point `.env` at the **same existing database**, using credentials from the old `config/database.php`. Never commit credentials. Set `SITE_URL` to the domain origin, with no subdirectory.
3. Copy the entire old `uploads/` tree, including `projects/` and `branding/`, into `UPLOAD_DIR`. URLs retain `/uploads/...` paths. Existing SVG branding remains usable; new uploads accept raster images/ICO only.
4. If this install has legacy JSON files, copy them into this release's `data/` directory **before migrating**. Run `npm run migrate`, then `npm run import:legacy`, then `npm run migrate` once more to register imported gallery images. The importer also reads `site_store` payloads. It imports a collection only when the normalized destination is empty and never overwrites existing SQL records. Malformed input stops the import rather than silently discarding it.
5. Run `npm run migrate` for SQL-only installs. It creates missing baseline tables and adds columns/tables in place; it never truncates or drops legacy tables. MySQL DDL commits implicitly, so migration steps are individually re-entrant and protected by a database lock. Backups remain the rollback mechanism.
6. Verify existing administrator login, project images, pages, navigation, home content, branding and quote history on staging. PHP bcrypt `$2y$` and Argon2 password hashes remain supported; existing accounts become owners. Assign narrower roles in `/admin/users` after migration.
7. Switch the reverse proxy to Node and start the mail worker if notifications are configured. Permanent redirects preserve public `/index.php`, `/projects.php`, `/project.php?job=...` and `/page.php?slug=...` bookmarks. Saved PHP public navigation URLs are translated at render time. Admin bookmarks move to `/admin/login` and `/admin/*`.

No production database or uploads are bundled in this repository. The automated migration tests use representative old-schema fixtures; validate your actual backup on staging before cutover. For rollback, stop Node and the worker, restore the database/upload backups and switch back to the saved PHP release. Do not run both applications as concurrent writers.

## CMS v2

- **Projects:** client, general contractor, completion date, scope, square footage, type/category, service association, stats, publication/featured controls, SEO and reusable gallery photos. Gallery photos have sort order, alt text, captions and before/after labels. Removing a gallery entry leaves its library image reusable.
- **Media:** upload once, edit titles/alt text/folders, search, reuse URLs throughout the CMS and attach images to multiple projects. Actual file signatures are checked; uploads are limited to 10 MB. In-use media cannot be deleted. Imported legacy files remain on disk when library entries are removed.
- **Homepage:** edit hero/about/contact copy and enable, disable or reorder Hero, About, Values, Services, Projects and Contact sections with numeric ordering.
- **Leads:** New → Contacted → Estimate Scheduled → Quote Sent → Won/Lost, plus preserved Quoted/Closed legacy statuses. Assign requests to active accounts and add dated internal notes. The inbox shows the newest 500 matching requests; all records remain stored.
- **Pages:** dedicated services, service areas and custom pages, HTML content with server-side sanitization, publication controls, CTAs, images and related service projects.
- **SEO:** per-page titles/descriptions, canonical URLs, Open Graph images, robots controls, `/robots.txt`, `/sitemap.xml`, and JSON-LD structured data. Use `home` or `projects` as global SEO keys; other keys use the full path such as `/page/about`. Noindex and unpublished content are omitted from the sitemap. Custom canonical URLs are excluded to avoid duplicate listings.
- **Settings:** company name/contact details, logo, favicon, social image, footer text. Image URL fields offer library URLs; upload branding through Media first.
- **Accounts:** owner (all controls), editor (content/media), sales (leads/email). Permissions are enforced on every request against the current SQL account. Disabled accounts lose access immediately, and password resets invalidate stored sessions. The last owner and your own owner access are protected.

## Email notifications

Set `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, optional SMTP credentials, `MAIL_FROM` and `QUOTE_NOTIFY_EMAIL`. A submitted quote and the company/customer notification jobs are committed in one transaction. Start `npm run mail:worker` as a separate supervised process; `node scripts/mail-worker.js --once` drains a batch once. Delivery uses leased jobs, retry delays and up to eight attempts. Review failures and request retries under `/admin/mail`. Delivery is at least once: an SMTP success followed by a process failure before recording success can send a duplicate. With SMTP unconfigured, quote capture still works and email jobs are not created. Historical quotes are not emailed automatically.

## Production deployment

Set `NODE_ENV=production`, an HTTPS `SITE_URL`, and `TRUST_PROXY` to the exact number of trusted proxies (typically 1). Secure cookies require HTTPS and correct forwarded protocol headers. Restrict access to the Node port to the proxy; use TLS for remote database connections with `DB_SSL=true`. Run the app and worker as a dedicated unprivileged user. Example nginx and systemd files are in `deploy/`; adjust domains, certificate paths, runtime path and service user.

Sessions persist in MySQL with HttpOnly, SameSite=Lax cookies and rolling eight-hour expiry plus a 24-hour absolute login lifetime. Requests use CSRF tokens, prepared SQL statements, validation, sanitization, Helmet/CSP and rate limits. Login/setup/quote limits are shared through MySQL across instances. The general per-minute request limit is per process. Only assets and uploads are served as static files; `.env`, source files and legacy `data/` remain private. The worker cleans expired sessions/rate counters; if email is disabled, schedule those SQL deletes separately. `/healthz` tests database connectivity. Monitor process logs, database availability, disk space and the email outbox. Back up both MySQL and uploads regularly.

## Verification

- `npm run check`: compile every EJS template and check JavaScript syntax.
- `npm test`: password compatibility, roles, input/URL validation, sanitization and configuration tests. Database tests are skipped unless `TEST_DB_PORT` is set.
- `TEST_DB_PORT=3306 TEST_DB_USER=root TEST_DB_PASS=... npm test`: run the full integration suite against a **test-only** server. It creates a unique `galindos_test_*` database, exercises the legacy migration twice, login/session persistence, CMS workflows, CSRF/permissions, media validation/reuse and lead/email behavior, then drops that database. The test user must be allowed to create/drop databases.
- `npm audit --omit=dev`: dependency audit.

GitHub Actions runs these checks with Node 24 and MySQL 8.4. Local integration verification also ran against MariaDB 10.4. No default admin credentials are shipped.
