# Galindos Builders LLC — PHP CMS v2

PHP 8.2+ and MySQL/MariaDB construction website with the approved orange/charcoal design, sticky navigation and full administrator CMS. It runs on standard Apache/cPanel PHP hosting. No Node.js process, npm, Passenger or Node app deployment is required.

## Deploy in cPanel

1. Back up the existing database and uploads. Disable the old Node application so it cannot keep writing to the database or claiming the domain.
2. In cPanel, point the domain at a normal PHP document root such as `public_html`. Select PHP 8.2 or newer with PDO MySQL, Fileinfo, DOM, mbstring and OpenSSL. Argon2 password support is required to verify existing Node-created Argon2 accounts.
3. Upload and extract `galindosbuilders-php.zip` directly into that document root. Confirm `index.php` and `.htaccess` are at the root, not inside an extra nested folder. The ZIP includes the email library and excludes local credentials, Git history, Windows modules and Node dependencies.
4. Create `.env` from `.env.example` on the server and set a random `SETUP_TOKEN`. Visit `/installer.php` and unlock it with that token to enter the database hostname, database name, username and password. cPanel names often include the account prefix. Assign the database user to that database with the required permissions. Use the existing database to retain all data; do not reimport `schema.sql` over a live database.
5. Set `SITE_URL=https://your-domain.com` and `COOKIE_SECURE=true`. The application currently supports a domain root, not a subdirectory. Set a random `SETUP_TOKEN` (the existing local token can also be used privately). Make `uploads/` writable by the PHP account, typically permissions 755 or 775 rather than 777.
6. Copy the existing `uploads/projects/`, `uploads/branding/` and `uploads/media/` files into the same paths. Existing images keep their URLs. If there are older `data/*.json` files, copy them before installation; they remain private.
7. Open `https://your-domain.com/installer.php` and unlock it with the setup token. Enter your website URL and database credentials, then optionally configure SMTP hostname, port, username/password, encryption, sender and notification addresses. Test Database and Test SMTP Connection do not save configuration or send email. Re-enter unsaved passwords after testing, then click Save & Install / Update. This saves configuration privately to `.env`, runs additive migrations, imports legacy content only into empty tables and registers gallery media. `/install.php` remains a compatible alias. Existing SQL content is never truncated or dropped. Alternatively run `php scripts/migrate.php --import-legacy` from the project folder in cPanel Terminal.
8. Sign in at `/admin/login`. On a new database the setup screen creates the first owner account with the same setup token. Existing PHP bcrypt, Node bcrypt and Argon2 hashes are preserved. If the server lacks Argon2 support, enable it or use the host terminal recovery command `php scripts/reset-admin.php username`; it reads the new password from stdin rather than command arguments.
9. After installation and initial account creation, remove `SETUP_TOKEN` from the server configuration to disable installer access. Verify projects, galleries, branding, services, custom pages, lead history and roles before reopening the site.

The PHP conversion retains the Node CMS v2 schema and adds a separate `php_sessions` table. Node sessions are not reused; administrators sign in again. `.env` is read directly by PHP, so cPanel does not need an app runner's environment settings. Never place credentials in a public ZIP. Apache must honor `.htaccess` and have mod_rewrite enabled; it protects configuration/source paths and provides clean routes. If you get a database error, check the real MySQL hostname and credentials supplied by your host. Switching runtime does not remove the need for MySQL.

## Features

- Projects/case studies: metadata, completion date, client/general contractor, scope, square footage, type/category, stats, related service, featured/publication controls and ordered galleries with alt text, captions and before/after labels.
- Reusable media: titles, alt text, folders/search, reuse, same-format replacement and deletion protection for in-use images. Actual MIME signatures are checked; uploads are limited to 10 MB. Imported legacy files remain on disk when their unused library entry is removed.
- Settings & Branding: direct logo/favicon/social image uploads, previews, immediate application and reusable URLs; company contact details and footer copy.
- Homepage hero/about/contact editing, section enable/disable and numeric ordering.
- Dedicated services, service areas, sanitized custom page content, navigation, CTAs and publication controls.
- Lead statuses, active-account assignment, dated internal notes and preserved quote history.
- Owner/editor/sales permissions checked against current database accounts; disabled users lose access immediately. Password resets invalidate PHP sessions. The last owner and your own owner access are protected.
- Canonical URLs, Open Graph/social images, robots controls, XML sitemap and JSON-LD. Unpublished and noindex content is omitted from the sitemap.
- Persistent SQL sessions, secure/HttpOnly/SameSite cookies, CSRF, prepared SQL, server validation, sanitized rich HTML, shared login/setup/quote rate limits and security headers.

Public routes: `/`, `/projects`, `/project/:slug`, `/page/:slug`, `/services/:slug`, `/service-areas/:slug`, `/robots.txt`, `/sitemap.xml`, and `/admin/*`. Old public `.php` query URLs redirect to the clean routes, and old admin GET bookmarks redirect to their current equivalents.

## Email delivery

The deployment ZIP bundles PHPMailer. Repository checkouts should run `composer install --no-dev --prefer-dist` first. Set `MAIL_FROM`, `QUOTE_NOTIFY_EMAIL` and SMTP settings in `.env`. SMTP uses verified TLS (STARTTLS on 587, implicit TLS with `SMTP_SECURE=true` on 465). Alternatively choose `MAIL_TRANSPORT=mail` to use the hosting provider's PHP mail transport if enabled.

In cPanel Cron Jobs, run once per minute using the correct PHP executable and absolute project path:

```
* * * * * /usr/local/bin/php /home/ACCOUNT/public_html/scripts/mail-worker.php
```

Adjust the executable to the hosting provider's PHP CLI version. A quote and its company/customer mail jobs are committed together. The worker claims jobs with leases, retries with delays and stops after eight attempts. Review/retry failures under `/admin/mail`. Delivery is at least once, so a crash after SMTP accepts a message can produce a duplicate. With email unconfigured, quotes still save and mail jobs are not created. Historical quotes are not sent automatically. The cron job also cleans expired sessions and rate counters.

## Local development and verification

- Configure `.env`, run `php scripts/migrate.php`, then `php -S 127.0.0.1:8000 router.php`.
- Set local `SITE_URL=http://127.0.0.1:8000` and `COOKIE_SECURE=false`.
- Run `php tests/run.php` for sanitization, URL validation, password compatibility and role checks.
- To exercise migrations and HTTP CMS workflows, set `TEST_DB_PORT`, optional `TEST_DB_USER`/`TEST_DB_PASS`, and run `php tests/run.php` against a disposable MySQL/MariaDB server. It creates and drops a unique test database and starts its own PHP server on port 33080. Never set these variables to a production database server.
- Run `composer audit` and PHP syntax checks. GitHub Actions runs the PHP suite against MySQL 8.4; local verification also runs on MariaDB 10.4.

Back up both SQL and uploads regularly. Keep the previous release for rollback. Migrations are re-entrant and protected by a database lock; MySQL DDL commits implicitly, so restore backups for rollback rather than attempting destructive down migrations. Real production data is not bundled and must be validated on staging before cutover.
