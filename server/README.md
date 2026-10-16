# Palette server add-on

Deployed inside the companies PHP app's docroot as `palette/` (copy the contents of
this `server/` folder). It only `require`s companies files and never modifies them.
`PALETTE_COMPANIES_DIR` (env) overrides the companies root; default is the parent
directory of this folder.

## Coupling contract (companies symbols used)

- `config.php`: `$conn` (mysqli)
- `includes/functions.php`: `generateCSRFToken`, `verifyCSRFToken`, `getAdminSetting`
- `getAdminSetting('resend_api_key')`: Resend API key; mail is sent only via Resend (`POST https://api.resend.com/emails`) using `pp_http`, never PHP `mail()`
- `includes/paypal.php`: `pp_settings`, `pp_ready`, `pp_api`, `pp_token`, `pp_base`, `pp_http` (test hook `$GLOBALS['pp_http']`; `pp_token` + `pp_base` are used for the two calls that need a raw body or extra headers: capture with `PayPal-Request-Id`, and verify-webhook-signature with the event spliced in byte for byte)
- Table owned by companies: `admin_settings` (settings key `palette`, JSON). Rate limits do not use companies' `checkRateLimit` (its purge is not scoped per endpoint, ruling S8): see `palette_rate` below.

If any of these change in companies, this add-on must be updated.

## Schema

`php migrate.php` creates `palette_orders`, `palette_licenses` and `palette_rate` (idempotent).

`palette_rate(bucket, window_start, count)` backs `palette_rate_hit($bucket, $max, $windowSec, ?$now)` in `lib/rate.php`:
fixed windows, an atomic `INSERT ... ON DUPLICATE KEY UPDATE count = count + 1`, and a purge of its own rows older than a
day on about 1 in 50 calls. Buckets: `verify:ip:` 30/60 s, `resend:ip:` 10/h, `resend:email:<sha1>` 3/h, `create:ip:` 20/h,
`claim:user:` 30/h.

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
- `webhook.php` answers 200 with a short status (including `ignored`, `unknown order`, and
  validation failures such as an underpaid capture), 400 when PayPal's signature check fails,
  503 `retry` on a transient failure (a database error while fulfilling, or PayPal timing out /
  answering 429 or 5xx during capture) so PayPal redelivers, and 413 for bodies over 64 KB.
- When the stored `webhook_mode` differs from companies' current PayPal mode, every webhook
  call logs it; re-run `setup.php`.
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
- A licence email that fails after a capture logs `PALETTE_MAIL_FAILED order=<id>` (alert on it; see DEPLOY.md Known limitations).

## JSON API (`api.php?action=...`)

`api.php` is a thin adapter over `palette_api_handle()` in `lib/api.php` (tested by `tests/api-test.php`).
Actions: `config` (GET), `create`, `capture`, `resend`, `claim` (POST, CSRF form `palette_checkout`, token in `csrf`), `verify` (POST, no CSRF, 30/min/IP).
Errors are `{ok:false, error:<i18n key>, message:<localised>}`; 419 bad CSRF, 429 rate limited, 503 `err_retry` when a paid order's key is not ready yet, 202 `co_err_confirm` when PayPal holds the capture as PENDING (the key is issued by the PAYMENT.CAPTURE.COMPLETED webhook).
`create` stores the PayPal order id in `$_SESSION['palette_orders']`; `capture` returns the key only for those ids, otherwise `{ok:true, key:null, email_masked, message}` (the key was emailed).
`verify` sends CORS headers only for `chrome-extension://<id>` origins whose id is in the `extension_ids` setting; the client IP is `palette_client_ip($_SERVER)`: `CF-Connecting-IP` only when `REMOTE_ADDR` is inside Cloudflare's published ranges (`PALETTE_CLOUDFLARE_CIDRS`, refresh yearly), else `REMOTE_ADDR`; never `X-Forwarded-For`.
`resend` answers first and runs `palette_resend` after `fastcgi_finish_request()` (ruling S4); on servers without it (CLI, `php -S`) the work stays synchronous.

## Pages (`index.php`, `checkout.php`, `account.php`, `privacy.php`, `terms.php`)

Served as `/palette/` (landing, via the vhost's `index index.php`), `/palette/checkout`, `/palette/account`,
`/palette/privacy` and `/palette/terms` (extensionless: the vhost needs a rewrite to the `.php` file). Each file is a
thin adapter over a pure renderer in `lib/pages.php` (`palette_render_landing/checkout/account/privacy/terms(array $ctx):
string`, tested by `tests/pages-test.php`). The landing and checkout pages share section helpers
(`palette_section_hero_actions/features/plans/install`); screenshots are `assets/screenshots/` (copies of
`docs/store/screenshots/`, regenerate with `npm run screenshots` in `extension/`);
behaviour is in `assets/checkout.js` (vanilla, no build), styles in `assets/checkout.css` (light + dark, logical
properties for Arabic), Geist self-hosted under `assets/fonts/` (OFL). No external fonts, no inline scripts.

- Language: `?lang=` when it is one of the 7, else the best `Accept-Language` match, else `en`; the switcher is
  plain links that keep the page (and a valid `?ext=` / `?key=`). Strings are `lib/i18n/*.php` (`page_*`, `co_*`,
  `acct_*`, `priv_*`).
- Every page sends `palette_page_headers()`: CSP `default-src 'self'` allowing PayPal's script/frame/connect/img
  hosts only, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`.
- Checkout loads `https://www.paypal.com/sdk/js?client-id=<id>&currency=USD&intent=capture&components=buttons`
  after `api.php?action=config`; the consent checkbox gates the button (`actions.disable()/enable()`);
  createOrder -> `create`, onApprove -> `capture` -> key, Copy, "Link to account" (`/palette/account?key=...`), and,
  when opened with `?ext=<extension id>`, `chrome.runtime.sendMessage(ext, {type:'license', key})`.
  With `tax_mode = ca` it shows a country (US / other) and US state select and the CA total.
- Account: signed out -> link to `/companies/?auth=login&redirect=/palette/account` (a `?key` waits in
  `$_SESSION['palette_pending_key']`, never in the login URL); signed in (`$_SESSION['user_id']`) -> the user's keys in
  full with copy buttons, and a claim form prefilled from `?key` or, once, from the pending session key.

### Checkout e2e

    cd extension && npm run e2e:checkout

Runs `test/e2e/checkout.spec.ts` against `php -S` with `tests/dev-router.php` (PHP from `$PHP_BIN`, else the
winget PHP 8.4, else `php`). The router seeds `$_ENV` with the `palette_test` database before companies'
`config.php` loads (under the `cli-server` SAPI it reads `DB_HOST_REMOTE`, which is also seeded), refuses to serve
unless the database name ends in `_test`, and with `PALETTE_DEV_PP_STUB=1` stubs PayPal REST and Resend and adds
`/__dev/seed`, `/__dev/login`, `/__dev/license`. The PayPal JS SDK is replaced in the browser by the spec.
It is separate from `npm run e2e` (which needs no PHP or database). **Do not deploy `tests/`.**
