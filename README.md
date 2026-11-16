# MediStock Intelligence

Smart Hospital Inventory & Resource Management System. It is built with native OOP PHP 8.2, PDO, MySQL/MariaDB, Bootstrap 5, vanilla JavaScript and Chart.js, and integrates Google Gemini.

The full design (architecture, schema, workflows, permissions, AI design and roadmap) is in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

**New here?** Read the [complete project guide](docs/PROJECT_GUIDE.md). It covers every module, the roles, how the intelligence and AI work, deployment and troubleshooting.

## Status

| Phase | Scope | Status |
|---|---|---|
| 1 | Foundation: framework core, full schema, authentication, roles and permissions, departments, locations, settings, audit log, dashboard | ✅ Done |
| 2 | Core inventory: products, categories, units and packs, batches, receiving, FEFO issuing through transit, receipt confirmation, movement ledger, reconciliation | ✅ Done |
| 3 | Department management: department levels, requests and approvals, transfers and returns, consumption, adjustments, blind stock counts, emergency reservations, batch recalls | ✅ Done |
| 4 | Procurement: suppliers and quotes, purchase requests (including from low stock), purchase orders with approval and PDF, goods receipts against POs, supplier performance | ✅ Done |
| 5 | Intelligence: stock-out forecasting, FEFO-aware expiry risk, smart redistribution, anomaly detection, scheduled alerts, cold-chain monitoring, demo history generator | ✅ Done |
| 6 | QR codes and barcodes: product and batch codes, label sheets (print and PDF), camera, USB and manual scanning, scan-to-add in forms, scan-driven counts | ✅ Done |
| 7 | Analytics and reports: KPI overview, 9 reports with date, department, category and grouping filters, charts, PDF and CSV export (formula-injection safe) | ✅ Done |
| 8 | Gemini AI inventory assistant: floating widget and full page, 17 permission-checked tools, draft proposals only, conversation history, rate limits, model fallback | ✅ Done |
| 9 | Hardening: query and index tuning, versioned analytics cache, OWASP review, route-security and data-integrity tests, append-only ledger triggers, least-privilege DB grants, `system:check`, deployment guide | ✅ Done |
| 10 | Role dashboards: one dashboard each for the Administrator, Management (view and approve only), Inventory Manager, Procurement, Pharmacist and Storekeeper, each with assistant prompts suited to the role | ✅ Done |

## Requirements

- XAMPP (or Apache + PHP 8.2+ + MySQL 8 / MariaDB 10.4+) with `mod_rewrite` and `AllowOverride All`
- PHP extensions: pdo_mysql, mbstring, curl, fileinfo, gd, openssl
- Composer

## Installation (XAMPP, Linux)

```bash
cd /opt/lampp/htdocs/MediStock
composer install
cp .env.example .env                                  # then edit DB_*, APP_URL, APP_TIMEZONE
/opt/lampp/bin/php bin/console key:generate           # paste the value into APP_KEY
/opt/lampp/bin/php bin/console migrate                # creates the database and all tables
/opt/lampp/bin/php bin/console db:seed                # roles, permissions, departments, categories, units, settings, admin
chmod -R a+rwX storage                                # Apache must be able to write logs and sessions
```

Open <http://localhost/MediStock> and sign in as **admin** with the password **Admin@12345**. You will be asked to change it straight away.

`db:seed --demo-users` also creates one demo account per role, for testing permissions. All of them use the password `Demo@12345` and must change it at first sign-in: `manager`, `inventory`, `pharmacist` (Pharmacy), `maternity` (storekeeper, Maternity) and `procurement`. **Do not create demo users in production.**

> Use XAMPP's PHP (`/opt/lampp/bin/php`) for console commands so the CLI matches the web server version.

### Keeping secrets out of the web root

Only `public/` should be reachable from the browser.

- **Recommended:** create an Apache VirtualHost whose `DocumentRoot` is `/opt/lampp/htdocs/MediStock/public`.
- **Fallback (default XAMPP):** the root `.htaccess` rewrites every request into `public/` and returns 403 for `app/`, `config/`, `storage/`, `vendor/`, `.env` and other internal paths.

For production, serve the app over HTTPS. Session cookies become `Secure` automatically, and HSTS is sent.

## Console commands

```
php bin/console migrate            Run pending migrations (creates the DB if missing)
php bin/console migrate:status     Show which migrations have run
php bin/console migrate:fresh      Drop everything and re-migrate (asks for confirmation)
php bin/console db:seed            Seed reference data (safe to re-run)
php bin/console user:create        Create a user interactively
php bin/console key:generate       Generate an APP_KEY
php bin/console stock:reconcile    Check stock balances against the movement ledger (schedule nightly)
php bin/console alerts:run         Scan stock, expiry, deliveries, approvals and anomalies (schedule every 15 min)
php bin/console system:check       Health / deployment check; exits 1 if anything FAILs
php bin/console demo:generate      DEVELOPMENT ONLY: back-dated demo history
```

Optional development data: `db:seed --demo-data` registers 30 common hospital products with pack sizes (no stock).

### Scheduled jobs (cron)

```
*/15 * * * *  /opt/lampp/bin/php /opt/lampp/htdocs/MediStock/bin/console alerts:run
15 2 * * *    /opt/lampp/bin/php /opt/lampp/htdocs/MediStock/bin/console stock:reconcile
```

Without cron, alerts still run, throttled to once every 15 minutes, whenever someone opens the dashboard.

### Demo history (development only)

```
php bin/console demo:generate --days=365
```

This generates a year of realistic, back-dated receipts, weekly department top-ups and daily consumption. It includes seasonal peaks, weekday patterns, a few slow-moving lots and occasional spikes, so the forecasts, expiry risk, anomalies and charts have data to work with. Everything goes through the normal stock services, so the ledger stays consistent. It refuses to run unless `APP_ENV=development`.

## How the intelligence works (Phase 5)

- **Stock-out forecast:**
  - Average daily use = 60% of the last 30 days + 40% of the last 90 days. With 14–89 days of history it is a plain average; under 14 days there is no forecast.
  - Safety stock = 1.65 × σ × √lead time (about a 95% service level).
  - Reorder point = use × lead time + safety stock.
  - Suggested quantity covers the lead time plus the review period, net of usable stock and stock on order.
  - Every forecast lists its assumptions and a confidence level.
- **Expiry risk:** batches are simulated earliest-expiry-first at each location, using that location's recent usage. Example: 500 units expiring in 30 days at 5 per day → about 350 at risk. Expired stock is listed separately and is never suggested for use.
- **Redistribution:** departments with surplus are matched with departments that are short. A suggestion only creates a *draft* transfer, which still needs approval.
- **Anomalies:** consumption spikes (mean + kσ), repeated losses, frequent adjusters, count discrepancies, stock stuck in transit, and loss surges.

## Tests

```bash
/opt/lampp/bin/php vendor/bin/phpunit
```

Integration tests use the configured database. Each test runs inside a transaction that is rolled back afterwards. The permission tests need the demo users (`db:seed --demo-users`). The analytics cache is switched off during tests (`CACHE_ENABLED=false` in `phpunit.xml`), so rolled-back test data never reaches the live cache.

The suite has 110 tests. They cover the stock ledger and FEFO, department workflows, procurement, intelligence maths, codes, reports, AI tool safety, the route table and database integrity:

- **Route security.** Every route must require sign-in and a known `can:` permission, except the login, logout and password-change routes.
- **Data integrity.** The ledger and audit trail cannot be changed. Balances cannot go negative. Every table is InnoDB with the expected foreign keys. Balances match the ledger.

## Deployment (production)

1. **Server.** Use Apache 2.4 with `mod_rewrite`, `mod_headers` and `AllowOverride All`, PHP 8.2+ (the extensions listed above), and MySQL 8 or MariaDB 10.4+.
2. **Code.** Copy the project without `.env`, `storage/*` contents or `tests/`, then run `composer install --no-dev --optimize-autoloader`.
3. **Virtual host.** Point `DocumentRoot` at `public/` and serve only over HTTPS:
   ```apache
   <VirtualHost *:443>
       ServerName stock.hospital.example
       DocumentRoot /var/www/medistock/public
       <Directory /var/www/medistock/public>
           AllowOverride All
           Require all granted
       </Directory>
       SSLEngine on
       SSLCertificateFile    /etc/ssl/certs/medistock.crt
       SSLCertificateKeyFile /etc/ssl/private/medistock.key
   </VirtualHost>
   <VirtualHost *:80>
       ServerName stock.hospital.example
       Redirect permanent / https://stock.hospital.example/
   </VirtualHost>
   ```
4. **Environment.** Copy `.env.example` to `.env`, then set the following:
   - `APP_ENV=production` and `APP_DEBUG=false`;
   - `APP_URL=https://…`;
   - your timezone;
   - a new `APP_KEY`;
   - the database credentials;
   - `GEMINI_API_KEY` (optional; without it, everything except the assistant works).
5. **Database.** Run `php bin/console migrate` and `php bin/console db:seed` without `--demo-*`, using the migration account. Then apply [`database/production_grants.sql`](database/production_grants.sql) after changing its passwords. The app then runs as `medistock_app`, which can only SELECT and INSERT on `stock_movements` and `audit_logs`. Migration 0013 adds triggers that refuse any UPDATE or DELETE on those tables.
6. **Permissions.** Run these commands (the web-server user is `www-data` on Debian/Ubuntu and `daemon` on XAMPP):
   ```bash
   chown -R root:www-data /var/www/medistock
   chmod 640 .env && chgrp www-data .env
   chmod -R 2775 storage && chgrp -R www-data storage
   ```
   Run cron jobs as the web-server user, so files they create stay writable for Apache.
7. **Cron.** Install the two jobs under "Scheduled jobs" above.
8. **Verify.** Run `php bin/console system:check`. It must report 0 failures. Then sign in as `admin` and change the password.
9. **Backups.** Take a nightly `mysqldump --single-transaction --routines --triggers medistock`. Also back up `storage/uploads/` and `.env`, keep copies off the server, and test a restore regularly.

### Production checklist

- [ ] HTTPS only; HTTP redirects to HTTPS.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, and a new `APP_KEY`.
- [ ] `.env` is mode 640 and owned by the web-server group. It is never committed and never inside `public/`.
- [ ] The app connects as `medistock_app`, not `root`.
- [ ] No demo users or demo data. The admin has changed the default password; `system:check` reports "Default passwords: none".
- [ ] Cron runs `alerts:run` and the nightly `stock:reconcile`.
- [ ] Nightly backups exist and a restore has been tested.
- [ ] `system:check` reports 0 failures.

## Performance

- **Analytics cache.** Forecasts, expiry risk, redistribution, anomalies and the report overview and the dashboard charts are cached in `storage/cache/data` as JSON for up to 10 minutes. Each cache key includes a data version and today's date. Any stock movement, purchase order change, department level, product edit or settings change bumps the version once the database transaction commits, so users never see stale figures after a change.
- **Indexes.** Covering indexes on `stock_movements` and `stock_balances` (migration 0012) serve the consumption and balance queries the intelligence and dashboard pages use.
- **Timings.** With about 47,000 ledger rows of demo data, pages render in 80–200 ms. A cold hospital-wide forecast takes about 0.4 s; once cached it is instant.

## Project structure

```
app/Core          Framework classes (router, request/response, DB, session, validator, gate, views)
app/Controllers   Thin HTTP controllers (Admin/, Auth/, Api via routes/api.php)
app/Services      Business rules and transactions
app/Repositories  All SQL (PDO prepared statements)
app/Middleware    Security headers, session, CSRF, authentication, permissions
config/           App, database, security, AI, permissions catalogue, navigation
database/         Numbered SQL migrations and idempotent PHP seeders
public/           Front controller and assets (the only web-accessible directory)
resources/views   PHP templates (layouts, partials, pages)
routes/           web.php (pages) and api.php (JSON)
storage/          Logs, sessions, uploads, reports (not web-accessible)
tests/            PHPUnit unit and integration tests
```

## How stock works (Phase 2)

- **Ledger first.** Every change writes `stock_movements` (append-only), and `StockService` updates `stock_balances` in the same transaction, with row locks.
- **Base units.** Quantities are stored in the product's base unit. Packs such as boxes and cartons are converted automatically.
- **FEFO.** Issues take the earliest-expiring usable batch first. Expired, recalled, quarantined and reserved stock is never allocated.
- **Transit.** An issue moves stock from the store to *In Transit*. The receiving department confirms what arrived. Any shortfall stays visible until the issuer returns it to the source.
- **Safeguards.**
  - Expired stock cannot be received.
  - Cold-chain products can only go into cold-chain locations.
  - Each receipt carries a single-use token, so double submissions are ignored.
- **Reconciliation.** Balances are compared with the ledger, and any differences are reported but never corrected automatically.

## Codes and scanning (Phase 6)

- **Payloads:** product labels carry `MSP:<product code>` and batch labels `MSB:<batch code>`. Each label has a QR code and a Code 128 barcode. Manufacturer GTIN/EAN barcodes registered on pack units are recognised too.
- **Scanning:** the **Scan** page and the "Scan" buttons on receive, issue, transfer and consumption forms use the device camera (html5-qrcode). A USB barcode scanner or typed code works everywhere. Expired, recalled and quarantined batches are flagged and refused.
- **Camera access:** the camera needs **HTTPS**, or `localhost`. On other devices on the network, use HTTPS or a USB scanner.

## Approvals (Phase 3)

Every stock-changing request needs a second person. The requester can never approve their own request.

| Workflow | Raised by (permission) | Approved by (permission) |
|---|---|---|
| Stock request | `requests.create` (own department) | `requests.approve`; issued by `stock.issue` |
| Transfer / return to store | `transfers.create` | `transfers.approve` |
| Adjustment (damage, loss, expiry, write-off) | `adjustments.request` | `adjustments.approve` |
| Stock count variances | `counts.perform` | `counts.approve` (a different person) |
| Emergency reservation | `reservations.manage` | `reservations.approve` |
| Purchase request | `purchase_requests.create` | `purchase_requests.approve` |
| Purchase order | `purchase_orders.manage` | `purchase_orders.approve`; received with `goods_receipts.create` |

With the default roles, approving a stock count needs a **second** Inventory Manager account, or you can grant `counts.approve` to another role in Admin → Roles.

## AI assistant (Phase 8)

```
User question → Gemini (only the tools this user may use) → function call
  → ToolRegistry: permission check + argument validation + department-scoped queries
  → structured, labelled results (actual / estimate / proposal) → Gemini → answer → safe HTML
```

- **No SQL from the model.** 17 fixed PHP tools; unknown or out-of-range arguments are rejected. Department filters always come from the user's own access.
- **Proposals only.** `prepare_purchase_request` creates a *draft* PR, and `prepare_stock_transfer` creates a transfer *awaiting approval*. The AI cannot approve, issue, receive or adjust anything.
- **Safety:**
  - The system instruction forbids invented figures, and estimates are labelled.
  - Tool data is marked as untrusted data, never instructions (tested against a prompt injection hidden in a product name).
  - Monetary values are hidden from users without valuation access, and the model is told so explicitly.
  - Model output is rendered through an escaping Markdown subset.
- **Limits:** per-user messages per minute and per day (System settings → AI), 2,000-character questions, at most 5 tool rounds per question.
- **Logging:** questions, tool calls (validated arguments, status, row count) and answers go to `ai_messages`, and proposals also go to the audit log.
- **Configuration (`.env`):** `GEMINI_API_KEY`, `GEMINI_MODEL` (primary), `GEMINI_FALLBACK_MODELS` (tried when the primary is overloaded or times out), `GEMINI_THINKING_LEVEL` (`low` keeps replies fast) and `GEMINI_TIMEOUT`. The key is read only on the server.
- **Independence:** MediStock has its own client, configuration, tools and conversation tables. It has no connection to any other project.

## Production notes

- `.env` must be readable by the web-server user. On this XAMPP development machine it is mode 644. Web access to it is blocked by `.htaccess` (403). In production, use mode 640 with the web-server group (see Deployment).
- The Gemini API key lives only in `.env`. It is never sent to the browser.

## Security summary

- Passwords are hashed with `password_hash()`. A password change is forced for temporary passwords.
- The session ID is regenerated at sign-in. Sessions have idle (30 min) and absolute (10 h) timeouts and are bound to the browser.
- Sign-in is rate-limited per username and per IP, and an account locks after repeated failures.
- Every POST requires a CSRF token. The Content-Security-Policy allows no inline scripts. All output is escaped with `e()`.
- Every route declares a permission. Department-level roles are filtered in SQL, not only hidden in the UI.
- Segregation of duties and lockout prevention are enforced in the services.
- An append-only audit log records sign-ins, administrative changes and denied access attempts.
- The stock ledger and the audit log are protected in the database itself, by triggers and by a grant that allows only INSERT, as well as by the application.
- Post-login redirects accept only same-application paths (`//host` and `/\\host` are rejected). `X-Powered-By` is removed. CSP, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy` and HSTS (on HTTPS) are sent.
- Uploads are checked by their real MIME type and image decoding, then stored outside the web root under random names. CSV exports are protected against formula injection.
- All SQL uses bound parameters. The only interpolated identifiers come from code and are checked against a strict pattern.
