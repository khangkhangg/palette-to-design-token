# Palette server add-on

Deployed inside the companies PHP app's docroot as `palette/` (copy the contents of
this `server/` folder). It only `require`s companies files and never modifies them.
`PALETTE_COMPANIES_DIR` (env) overrides the companies root; default is the parent
directory of this folder.

## Coupling contract (companies symbols used)

- `config.php`: `$conn` (mysqli)
- `includes/functions.php`: `generateCSRFToken`, `verifyCSRFToken`, `getAdminSetting`
- `getAdminSetting('resend_api_key')`: Resend API key; mail is sent only via Resend (`POST https://api.resend.com/emails`) using `pp_http`, never PHP `mail()`
- `includes/ip_rate_limit.php`: `checkRateLimit` (table `api_rate_limits`, migration `013-api-rate-limits.sql`)
- `includes/paypal.php`: `pp_settings`, `pp_ready`, `pp_api`, `pp_http` (test hook `$GLOBALS['pp_http']`)
- Tables owned by companies: `admin_settings` (settings key `palette`, JSON), `api_rate_limits`

If any of these change in companies, this add-on must be updated.

## Schema

`php migrate.php` creates `palette_orders` and `palette_licenses` (idempotent).

## PayPal

- `lib/orders.php`: orders (`palette_create_order`), idempotent capture (`palette_capture`,
  `palette_fulfil`: claim-first `UPDATE ... WHERE status='created'` in a transaction, so a page
  capture racing the webhook issues one key and sends one email) and `palette_webhook`.
- `webhook.php` is registered with PayPal as `https://www.vietnamcos.com/palette/webhook.php`
  (events CHECKOUT.ORDER.APPROVED, PAYMENT.CAPTURE.COMPLETED / REFUNDED / REVERSED). An admin
  opens `setup.php` and presses the button once per PayPal mode; it reuses a webhook already
  registered for that URL and stores `webhook_id` + `webhook_mode` in the `palette` setting.
  Re-run it after switching companies' PayPal between sandbox and live.
- The PayPal app is shared with companies, so each webhook receives the other's events:
  events whose `custom_id` does not start with `palette:` answer 200 `ignored` here, and
  companies' webhook finds no row for palette orders and changes nothing.
- `webhook.php` answers 200 with a short status (including `ignored`, `unknown order`), and
  400 only when PayPal's signature check fails.
- A refund or reversal revokes the order's license; the order row keeps status `captured`.

## Running tests locally

Needs PHP 8.4 (mysqli) and a MariaDB/MySQL server on 127.0.0.1 with database
`palette_test` and user `palette` / password `palette`, plus a checkout of the
companies repo (`PALETTE_COMPANIES_DIR`, default `C:\Projects\companies`).

    php server/tests/run.php

`tests/helpers.php` seeds `$_ENV` (DB_* for the test DB) BEFORE companies'
`config.php` loads, so its real `.env` credentials are never used, and exits 2 unless
`SELECT DATABASE()` ends in `_test`. Each `*-test.php` runs in its own php process.

## Known limitations

- `palette_resend` must run after the HTTP response is flushed (it does DB and Resend work only for existing addresses, which would otherwise leak address existence through response timing).
