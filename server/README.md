# Palette server add-on

Deployed inside the companies PHP app's docroot as `palette/` (copy the contents of
this `server/` folder). It only `require`s companies files and never modifies them.
`PALETTE_COMPANIES_DIR` (env) overrides the companies root; default is the parent
directory of this folder.

## Coupling contract (companies symbols used)

- `config.php`: `$conn` (mysqli), `EMAIL_FROM_ADDRESS`, `EMAIL_FROM_NAME`
- `includes/functions.php`: `generateCSRFToken`, `verifyCSRFToken`, `getAdminSetting`
- `includes/ip_rate_limit.php`: `checkRateLimit` (table `api_rate_limits`, migration `013-api-rate-limits.sql`)
- `includes/paypal.php`: `pp_settings`, `pp_ready`, `pp_api`, `pp_http` (test hook `$GLOBALS['pp_http']`)
- Tables owned by companies: `admin_settings` (settings key `palette`, JSON), `api_rate_limits`

If any of these change in companies, this add-on must be updated.

## Schema

`php migrate.php` creates `palette_orders` and `palette_licenses` (idempotent).

## Running tests locally

Needs PHP 8.4 (mysqli) and a MariaDB/MySQL server on 127.0.0.1 with database
`palette_test` and user `palette` / password `palette`, plus a checkout of the
companies repo (`PALETTE_COMPANIES_DIR`, default `C:\Projects\companies`).

    php server/tests/run.php

`tests/helpers.php` seeds `$_ENV` (DB_* for the test DB) BEFORE companies'
`config.php` loads, so its real `.env` credentials are never used, and exits 2 unless
`SELECT DATABASE()` ends in `_test`. Each `*-test.php` runs in its own php process.
