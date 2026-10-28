# Quote form spam protection

This change targets `feature/php-construction-website`, whose homepage contact/quote form posts to `/quote`. The repository's `main` branch only contains README/LICENSE. Confirm this PHP branch matches the deployed release before rollout. There is one public submission handler; service and project CTAs link to this form.

## What changed

- `includes/quote.php` gates the existing quote insert and mail queue transaction with server-side Turnstile Siteverify. It requires strict boolean success, the exact `quote` action, and an exact configured hostname. Tokens must be strings of 1–2048 bytes. Network/HTTP/JSON failures and missing configuration reject submissions. TLS verification stays enabled; connection/total timeouts are 3/10 seconds. No verification results are cached.
- The native POST form includes a Managed Turnstile widget and an offscreen, keyboard-inaccessible `company_website` honeypot. Any nonempty or malformed honeypot is rejected before Siteverify. The secret never appears in HTML. This is a navigating form: errors instruct users to reload and obtain a fresh token; success retains the existing redirect. No AJAX token reuse is introduced.
- The existing SQL-backed, transaction-locked five-attempts-per-hour rate limiter is preserved. The quote handler can use a validated Cloudflare visitor IP when the direct peer matches explicitly trusted proxy CIDRs. Otherwise it uses `REMOTE_ADDR`. It ignores `X-Forwarded-For`. Failed validation and honeypot attempts with valid CSRF count toward this quota; the sixth attempt returns 429 with Retry-After. CSRF rejection still happens first during boot. The existing cron cleans expired counters. Shared NAT users share a quota.
- The CSP permits scripts and frames only from the additional `https://challenges.cloudflare.com` origin. Existing CSRF, field validation, schema, admin permissions, lead history, notifications, email worker and successful redirect remain in place. No schema migration is required.
- Tests cover validation failures, wrong actions/hosts, duplicate-token responses, proxy-header spoofing, IPv4/IPv6 CIDRs, no side effects on rejected POSTs, rate limiting, verified lead/email creation, and existing CMS/admin workflows. CI enables cURL.

## Required production configuration (before deploying)

1. Enable PHP 8.2+ **cURL** with a current CA certificate store. Permit outbound HTTPS to `challenges.cloudflare.com:443`. Retain the existing PHP/MySQL/SMTP settings.
2. Create a **Managed** Turnstile widget in the confirmed Cloudflare account for `galindosbuildersllc.com` and `www.galindosbuildersllc.com` if both serve the form. Confirm the actual frontend hostnames first. Use separate dummy keys for local development; do not add local hostnames to the production widget or backend allowlist. Preserve default no-preclearance behavior.
3. In the hosting platform's private environment settings, or its existing protected `.env`, set:

   ```dotenv
   TURNSTILE_SITE_KEY=<public site key>
   TURNSTILE_SECRET_KEY=<private secret key>
   TURNSTILE_HOSTNAMES=galindosbuildersllc.com,www.galindosbuildersllc.com
   TURNSTILE_TRUSTED_PROXY_CIDRS=
   ```

   Values above are placeholders. Never commit actual secrets, share them in chat, place them in URLs, or bundle them in release archives. The existing env loader reads `.env` directly; it does not auto-load `.env.production`. The installer preserves unrelated settings but does not provide Turnstile key fields. Confirm `.env` remains ignored and blocked from HTTP access. Set `SITE_URL` to the production HTTPS origin and `COOKIE_SECURE=true` as before.
4. Determine what PHP sees in `REMOTE_ADDR`. If Apache already restores the true visitor IP, leave trusted CIDRs empty. If PHP sees a Cloudflare edge IP, set `TURNSTILE_TRUSTED_PROXY_CIDRS` to the current, verified Cloudflare IPv4 and IPv6 proxy networks, comma separated, from https://www.cloudflare.com/ips/. Do not trust every IP or blindly accept incoming headers. If an additional proxy sits between Cloudflare and PHP, have the host restore the visitor IP securely rather than trusting a header forwarded by that proxy. Test two real visitors independently; otherwise all visitors may share an edge-IP quota.
5. Purge any cached homepage HTML after release and bypass full-page caching for pages containing session CSRF tokens. Configure keys and code together: missing configuration hides the form with an unavailable notice and returns 503 to direct POSTs. There is no insecure bypass toggle.

## Validation and rollout

Run `php tests/run.php`. For full database/HTTP regression tests, use a **disposable local** MySQL/MariaDB instance with `TEST_DB_PORT`, `TEST_DB_USER`, and optional `TEST_DB_PASS`; the suite creates and drops a unique test database. The successful transaction test injects a deterministic Siteverify result through a PHP callable; HTTP requests and environment variables cannot select a test verifier. No live email is sent by the suite.

Optionally run `php tests/siteverify.php` with cURL and a working CA store. This contacts the real Siteverify endpoint using only Cloudflare's published dummy keys, checking success, failure and simulated duplicate responses. It does **not** prove real production-token replay prevention.

On staging, use a dedicated widget/secret and exact staging hostname allowlist. Confirm the widget loads under CSP. Submit one fresh real browser token and confirm exactly one lead, the existing success redirect, and the two configured mail jobs. Replay that exact token in the same valid CSRF/session context: it must fail and leave lead/mail counts unchanged. Check missing/expired tokens and honeypot rejection; then confirm the sixth quote attempt per IP returns 429. Verify admin lead assignment/notes and existing mail delivery. Clear only dedicated staging test counters if needed; do not clear live rate-limit data.

Only deploy after confirming the PHP hosting destination, the deployed branch/release, private secret destination, and a backup/rollback path. This change does not deploy the website, create or rotate a production widget, modify Cloudflare DNS/WAF rules, access hosting credentials, or send live mail. Actual production-key and browser success/replay validation remains pending until safe hosting/staging access is confirmed.

References: [server-side validation](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/), [test keys](https://developers.cloudflare.com/turnstile/troubleshooting/testing/), [CSP](https://developers.cloudflare.com/turnstile/reference/content-security-policy/).
