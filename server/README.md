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
- `lib/http.php` `palette_http(method, url, headers, body, timeoutSec)`: palette's own HTTP call with a caller-chosen timeout (companies' `pp_http` hard-codes 25 s). Same `['status','json']` shape, status 0 on transport failure; delegates to `$GLOBALS['pp_http']` when that stub is set. Used by the search adapter (8 s).
- `tools/api/ai-usage.php` + `tools/api/ai-gateway.php` (which require `tools/api/hiring-admin.php` for `hiring_setting`; all three only define constants and functions, nothing runs at include time): `ai_key_for($conn, 0)` (platform key and model from `hiring_settings.deepseek_key` / `ai_cloud_model`, else `DEEPSEEK_API_KEY`), `ai_provider_request` (with `json`, `max_tokens` 1800, `temperature` 0.3), `ai_http_call` (30 s; wrapped by `palette_ai_transport`, test hook `$GLOBALS['palette_ai_transport']`), `ai_provider_parse`, `ai_log($conn, PALETTE_AI_LOG_USER, 'palette_ai', usage, meta)` (rows in `hiring_ai_usage`, so calls show in /hiring-admin/ai/reports as their own row). `PALETTE_AI_LOG_USER` is **-1**, not 0 (ruling A8): companies logs guest JD analysis as user 0 and `ai_guard` sums user 0 across features, so palette traffic would exhaust the guest quota and blur the reports; the key still comes from `ai_key_for($conn, 0)`. `hiring_setting('ai_enabled')` = 0 makes every palette AI call answer `disabled` (no HTTP call, no log row), shown as AI unavailable. Never `ai_chat()`: it applies the per-user monthly cap to user 0, which every subscriber would share. Used by `lib/ai.php`.
- Tables owned by companies and read/written through those helpers: `hiring_settings`, `hiring_user_ai_keys`, `hiring_ai_models`, `hiring_ai_usage`.
- Table owned by companies: `admin_settings` (settings key `palette`, JSON). Rate limits do not use companies' `checkRateLimit` (its purge is not scoped per endpoint, ruling S8): see `palette_rate` below.

If any of these change in companies, this add-on must be updated.

### AI insights and subscription contract (Pro + AI)

What the extension, the pages and operations rely on, in one place. Each item is explained in the sections below and in DEPLOY.md.

- **AI gateway helpers** (companies, called only from `lib/ai.php`, never modified): `ai_key_for($conn, 0)`, `ai_provider_request`, `ai_http_call` (through `palette_ai_transport`), `ai_provider_parse`, `ai_log($conn, PALETTE_AI_LOG_USER, 'palette_ai', ...)`, plus `hiring_setting($conn, 'ai_enabled' | 'ai_cloud_model')`. Never `ai_chat()`.
- **Log user `-1`.** `PALETTE_AI_LOG_USER` is -1 (ruling A8), so palette calls are their own row in `/hiring-admin/ai/reports` and never use the guest (user 0) quota. The key itself is the platform key (`ai_key_for($conn, 0)`).
- **Kill switch.** `hiring_settings.ai_enabled = 0` makes every analysis answer `err_ai_unavailable` (503) with no HTTP call, no log row and no charge.
- **Companies prerequisites on production:** `tools/api/ai-gateway.php`, `tools/api/ai-usage.php` and `tools/api/hiring-admin.php` present, a platform DeepSeek key (hiring settings `deepseek_key` or `DEEPSEEK_API_KEY` in `.env`), `ai_enabled = 1` (DEPLOY.md step 1d).
- **Palette settings** (`admin_settings` key `palette`, merged read-modify-write): `ai_price_usd` (3.99), `ai_monthly_analyses` (100), `ai_monthly_token_cap` (600000, hidden), `search_provider` (`brave` | `tavily`), `search_key` (a secret: no API action returns it, nothing prints it; DEPLOY.md step 5b sets it without echoing), `ai_paypal_plan_id`, `ai_paypal_plan_mode`, `ai_paypal_product_id`. The landing, checkout, terms and FAQ read the price and the limit from these, never from a constant.
- **Request field names** (`analyse` body, anything else is dropped): `key`, `url`, `title`, `description`, `lang`, `design` = `{colours: [{role, hex}], fonts, type_sizes, spacing_base, radius, contrast: {pass, fail, failing: [{fg, bg, ratio}]}}`.
- **Result field names** (spec section 5.3): `sector`, `tags`, `verdict` (`behind` | `on_par` | `competitive`), `summary`, `colours: [{hex, title, meaning}]`, `fit: {level: strong | ok | weak, reason}`, `works`, `improve`, `sites: [{name, domain, url, why, relation: competitor | similar, source: web | ai}]`, `search_unavailable`. The envelope is `{ok, result, cached, usage: {used, limit, resets_on}}`; `verify` for `plan: 'ai'` keys adds `plan`, `ai_active`, `paid_through`, `sub_status` and `usage`.
- **Error keys the extension maps** (`extension/src/ai.ts`): `err_bad_request` (400, also 413) -> bad request, `err_not_ai` (403, any other 403) -> not AI, `err_ended` (403, `ended_on`) -> ended, `api_rate_limited` (429) -> rate limited, `err_quota` (429, `resets_on`, `usage`) -> quota, `err_ai_unavailable` and anything else (503 ...) -> unavailable; a 200 that does not fit the schema -> invalid. Subscription and cancel errors the pages show: `err_paused`, `err_retry`, `err_paypal`, `err_csrf`, `err_not_owner`, `err_invalid_key`.
- **Alert prefixes** (PHP error log; alert on every line starting `PALETTE_`): `PALETTE_MAIL_FAILED`, `PALETTE_PAID_UNFULFILLED`, `PALETTE_CANCEL_FAILED`.
- **Cron:** `15 3 * * * www-data php /var/www/duykhang/companies/palette/cron-subscriptions.php` (DEPLOY.md step 4b).

## Schema

`php migrate.php` creates `palette_orders`, `palette_licenses` and `palette_rate` (idempotent).

`palette_rate(bucket, window_start, count)` backs `palette_rate_hit($bucket, $max, $windowSec, ?$now)` in `lib/rate.php`:
fixed windows, an atomic `INSERT ... ON DUPLICATE KEY UPDATE count = count + 1`, and a purge of its own rows older than a
day on about 1 in 50 calls. Buckets: `verify:ip:` 30/60 s, `resend:ip:` 10/h, `resend:email:<sha1>` 3/h, `create:ip:` 20/h,
`claim:user:` 30/h, `sub:start:ip:` 10/h, `sub:confirm:ip:` 30/h, `sub:cancel:ip:` 10/h, `ai:min:<license id>` 6/60 s, `ai:hour:<license id>` 60/h, `ai:ip:` 20/h.

AI insights tables: `palette_ai_usage` (lib/usage.php), `palette_ai_cache(host, lang, colour_hash, page_hash, result_json, created_at)`
(lib/analyse.php: 7-day TTL, expired rows purged on every store) and the private site list `palette_sites` +
`palette_site_links` (lib/sites.php: aggregate counters only, no licence, user, email or IP is stored).

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
- Subscriptions (Pro + AI, `lib/subscriptions.php`): `subscribe_start` reserves a `palette_subscriptions` row (`pending`)
  and returns the plan id + `custom_id = palette-sub:<row id>`; `palette_sub_fulfil` is claim-first like orders
  (`UPDATE ... WHERE status='pending'` in a transaction) and issues a new `plan 'ai'` key (`order_id` = the `I-...`
  subscription id, `paid_through` = PayPal's `next_billing_time`, `period_start` = now, PHP time per ruling A4).
  The row stores `price_usd` at start (ruling A10); every amount check uses it, so a later price change never lapses
  existing subscribers. A key is issued only with proof of payment at that price: PayPal's `last_payment`, or an
  amount-checked PAYMENT.SALE.COMPLETED. A subscription with `plan_overridden` (the JS SDK lets the browser send its own
  billing cycles with our plan id) is never fulfilled or extended. A full-price first sale also fulfils a subscription
  already CANCELLED or SUSPENDED in PayPal. The key then covers the paid month (`next_billing_time` if still ahead,
  else the sale time + 1 month) and takes PayPal's status. A matching paid sale that still issues no key logs
  `PALETTE_PAID_UNFULFILLED`. A refund that arrives before the first sale closes the pending row and records the sale
  as refunded, so the later sale answers `already` and issues no key.
  `BILLING.SUBSCRIPTION.*` and `PAYMENT.SALE.*` events go to `palette_sub_event`: ACTIVATED fulfils a pending row
  (`pending` when PayPal reports no payment yet); SALE.COMPLETED records the sale (`sale_id` unique; a duplicate answers
  `already`), fulfils first if the row is still pending (503 `retry` while PayPal still shows APPROVED), then moves
  `paid_through` forward only and `period_start` to the sale time only when later (resets usage); sales below the row's
  price in USD are logged and ignored. CANCELLED / SUSPENDED / PAYMENT.FAILED / EXPIRED change the status only
  (`already` when nothing changed; cancelled and expired are never overridden by a late suspension); SALE.REFUNDED /
  REVERSED revoke the licence (subscription found through `palette_sub_payments` by sale id, else `billing_agreement_id`,
  else `GET /v1/payments/sale/{id}`) and then cancel the PayPal subscription, best effort (ruling A10). Subscriptions
  whose `custom_id` is not `palette-sub:` are ignored. Licences are never deleted.
- `cron-subscriptions.php` (CLI only, 404 over HTTP) runs `palette_sub_reconcile` daily: up to 200 active, suspended or
  still-paid cancelled subscriptions, least recently updated first. It syncs status, and catches a missed renewal only on
  proof of a new payment: ACTIVE, `failed_payments_count` 0, `last_payment` at the row's price and more than a day after
  the licence's current `period_start`.

## Running tests locally

Needs PHP 8.4 (mysqli) and a MariaDB/MySQL server on 127.0.0.1 with database
`palette_test` and user `palette` / password `palette`, plus a checkout of the
companies repo (`PALETTE_COMPANIES_DIR`, default `C:\Projects\companies`).

    php server/tests/run.php

`tests/helpers.php` seeds `$_ENV` (DB_* for the test DB) BEFORE companies'
`config.php` loads, so its real `.env` credentials are never used, and exits 2 unless
`SELECT DATABASE()` ends in `_test`. Each `*-test.php` runs in its own php process.
It also seeds `DEEPSEEK_API_KEY` empty (companies' `.env` holds the real platform AI key, which `ai_key_for` would
otherwise fall back to), and `companies_ai_tables($conn)` (`tests/companies-ai-tables.php`, shared with the dev router) creates the companies AI tables (copied from companies
migrations 045, 048, 049, 059) in the test database.

## Known limitations

- `palette_resend` must run after the HTTP response is flushed (it does DB and Resend work only for existing addresses, which would otherwise leak address existence through response timing).
- A licence email that fails after a capture logs `PALETTE_MAIL_FAILED order=<id>` (alert on it; see DEPLOY.md Known limitations).
- Pro + AI: the deferred monitoring and account-page gaps, the user -1 logging, page-text scrubbing and the lost pending checkout are listed in DEPLOY.md Known limitations.

## JSON API (`api.php?action=...`)

`api.php` is a thin adapter over `palette_api_handle()` in `lib/api.php` (tested by `tests/api-test.php`).
Actions: `config` (GET), `create`, `capture`, `resend`, `claim` (POST, CSRF form `palette_checkout`, token in `csrf`), `verify` (POST, no CSRF, 30/min/IP).
Errors are `{ok:false, error:<i18n key>, message:<localised>}`; 419 bad CSRF, 429 rate limited, 503 `err_retry` when a paid order's key is not ready yet, 202 `co_err_confirm` when PayPal holds the capture as PENDING (the key is issued by the PAYMENT.CAPTURE.COMPLETED webhook).
`create` stores the PayPal order id in `$_SESSION['palette_orders']`; `capture` returns the key only for those ids, otherwise `{ok:true, key:null, email_masked, message}` (the key was emailed).
`verify` sends CORS headers only for `chrome-extension://<id>` origins whose id is in the `extension_ids` setting; the client IP is `palette_client_ip($_SERVER)`: `CF-Connecting-IP` only when `REMOTE_ADDR` is inside Cloudflare's published ranges (`PALETTE_CLOUDFLARE_CIDRS`, refresh yearly), else `REMOTE_ADDR`; never `X-Forwarded-For`.
`resend` answers first and runs `palette_resend` after `fastcgi_finish_request()` (ruling S4); on servers without it (CLI, `php -S`) the work stays synchronous.
`subscribe_start` (CSRF `palette_checkout`, `sub:start:ip:` 10/h; `{email, consent, lang}` -> `{ok, plan_id, custom_id}`, 503 `err_paused` when no plan exists for the current PayPal mode) stores the row id in `$_SESSION['palette_subs']` (last 20).
`subscribe_confirm` (`sub:confirm:ip:` 30/h; `{subscription_id}` matching `I-[A-Z0-9]{6,32}`) GETs the subscription and checks custom_id, plan (not overridden) and last payment: 202 `{ok, pending:true}` while APPROVAL_PENDING / APPROVED or while PayPal reports no payment yet (the key is then emailed when the sale arrives), 503 `err_retry` on a PayPal outage, else the key, which only the session in `palette_subs` sees (others get `key: null` and the emailed message).
`sub_cancel` (CSRF form `palette_account`, `sub:cancel:ip:` 10/h; `{key, email?}`) needs the signed-in user who claimed the key or the subscribing email (case-insensitive), 403 `err_not_owner` otherwise; PayPal 204 or 422 (already cancelled) both count as cancelled; answers `{ok, ends_on, message}` and emails a confirmation. Access stays until `paid_through`.

`analyse` (POST JSON, no CSRF, same CORS and preflight as `verify`; bodies over 16 KB answer 413) runs `palette_analyse()` in
`lib/analyse.php`. Request: `{key, url, title, description, lang, design}` where `design` is
`{colours: [{role, hex}] (<= 10), fonts: [string] (<= 3), type_sizes: [number] (<= 12), spacing_base: number|null,
radius: [number] (<= 6), contrast: {pass, fail, failing: [{fg, bg, ratio}] (<= 6)}}`; anything else is dropped before
the prompt. `url` must be a public http(s) page (`palette_host`), path <= 300 characters; only the origin and the first path segment, when it is <= 24 characters with no digits or @, are used (ruling A14; anything else becomes `/`).
`title` and `description` pass through `palette_scrub_text` (ruling A9: URLs, emails, @handles, unread counters, runs of
6+ digits removed) before the prompt, the searches, the shared cache and the site list (which keeps only the domain
and the brand name taken from the scrubbed title; its `title` column stays NULL, ruling A15).
Order: per-IP limit -> input -> licence (`plan = 'ai'`, not revoked, `paid_through >= now`, or `paid_through + 48 h >= now` while `sub_status = 'active'`, ruling A16) -> per-licence limits -> cache (same host, lang,
sorted-hex sha1 and sha1 of the scrubbed title + "\n" + description (ruling A13) within 7 days: `cached: true`, no analysis used) -> reserve one analysis -> two searches
(`"<brand>" competitors`, `<description or title> similar websites`; both unavailable = AI only with
`result.search_unavailable: true`) -> AI call (tokens always recorded) -> validation -> cache, site list -> 200
`{ok, result, cached, usage: {used, limit, resets_on}}`. A failed call or invalid answer releases the analysis.
Errors: 400 `err_bad_request` (also malformed key), 403 `err_not_ai` (unknown, pro or revoked key), 403 `err_ended`
(+ `ended_on`; null with the `err_ended_nodate` message when there is no `paid_through`), 429 `api_rate_limited`, 429 `err_quota` (+ `resets_on`, `usage`; analyses or token cap), 503
`err_ai_unavailable`.

`admin-sites.php` (served as `/palette/admin-sites.php`, admin session `$_SESSION['user_role'] === 'admin'`, else 403) lists
the 200 most-seen sites; `?format=csv` downloads them with formula-leading cells (`= + - @`, tab, CR) prefixed by `'`.

## Pages (`index.php`, `checkout.php`, `account.php`, `privacy.php`, `terms.php`)

Served as `/palette/` (landing, via the vhost's `index index.php`), `/palette/checkout`, `/palette/account`,
`/palette/privacy` and `/palette/terms` (extensionless: the vhost needs a rewrite to the `.php` file). Each file is a
thin adapter over a pure renderer in `lib/pages.php` (`palette_render_landing/checkout/account/privacy/terms(array $ctx):
string`, tested by `tests/pages-test.php`). The landing and checkout pages share section helpers
(`palette_section_hero_actions/features/plans/install`); the plans table has three columns (Free, Pro, Pro + AI): the Pro + AI
header shows its monthly price once a PayPal plan exists for the current mode (`palette_ai_on()`) and "Coming soon" until
then, and so does the "AI insights" section of the landing page (heading, three bullets, the results screenshot
`6-ai-insights.png` with a per-language alt `lp_shot_6`, a Pro + AI link). The FAQ has seven questions (what Pro + AI is,
how to cancel). The terms add `terms_sub` (subscription) and `terms_ai` (analyses limit from settings, fair use, AI output
may be wrong); the privacy page adds `priv_ai` (AI insights, Brave Search and DeepSeek, 7-day shared cache). Screenshots are
`assets/screenshots/` (copies of `docs/store/screenshots/`, regenerate with `npm run screenshots` in `extension/`: the sixth
shot uses a stubbed analysis, and the Web Store takes only five of the six);
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

Builds the test extension (`dist-e2e`), then runs `test/e2e/checkout.spec.ts` and `test/e2e/ai-router.spec.ts` (the side panel's AI insights posting `action=analyse`) against `php -S` with `tests/dev-router.php` (PHP from `$PHP_BIN`, else the
winget PHP 8.4, else `php`). The router seeds `$_ENV` with the `palette_test` database before companies'
`config.php` loads (under the `cli-server` SAPI it reads `DB_HOST_REMOTE`, which is also seeded), refuses to serve
unless the database name ends in `_test`, and with `PALETTE_DEV_PP_STUB=1` stubs PayPal REST and Resend and adds
`/__dev/seed`, `/__dev/login`, `/__dev/license`. The PayPal JS SDK is replaced in the browser by the spec.
`PALETTE_DEV_AI_STUB=1` (also `_test` only) answers web search and the AI model locally so `action=analyse` works end to
end, and extends the dev routes: `/__dev/seed?ext=<id>` (dev AI + search keys, AI tables cleared, extension id allowed),
`/__dev/license?plan=ai[&ended=1]`, `/__dev/login?user_id=1&role=admin`. A title containing `dev-ai-fail` makes the
model answer 503 and a query containing `dev-search-down` makes search answer 500 (use a host that is not cached yet).
It is separate from `npm run e2e` (which needs no PHP or database). **Do not deploy `tests/`.**
