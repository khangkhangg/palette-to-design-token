# Stripe payments and licence admin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Buyers pay for Pro and Pro + AI through Stripe Checkout, and the owner manages licences, keys and the Stripe connection from `/palette/admin`.

**Architecture:** Three new server libraries (`lib/stripe.php` connection and HTTP, `lib/stripe_checkout.php` sessions and fulfilment, `lib/stripe_events.php` webhook and reconcile) beside the PayPal ones, a new webhook endpoint, and an admin area (`lib/admin.php` pure page functions + `admin.php` router). Existing PayPal code gains a `provider` branch only where it calls PayPal. The extension changes copy only.

**Tech Stack:** PHP 8.4 + MariaDB (server, tests via `php server/tests/run.php`), TypeScript/Preact/Vitest/Playwright (extension).

**Spec:** `docs/superpowers/specs/2026-11-04-stripe-and-licence-admin-design.md` — read it before any task.

## Global Constraints

- Never modify files in `C:\Projects\companies`. Read companies' Stripe live key only through `st_settings($conn)` (require `PALETTE_COMPANIES_DIR . '/includes/stripe.php'` inside the function that needs it, `require_once`).
- Every Stripe HTTP call goes through `palette_st_api()`; tests replace it with `$GLOBALS['palette_st_http'] = fn(string $method, string $url, array $headers, ?string $body): array` returning `['status' => int, 'json' => array]`. No test may reach api.stripe.com, PayPal, Resend, Brave or DeepSeek.
- Tests seed `$_ENV` through `server/tests/helpers.php` only; never connect with companies' `.env`.
- Mail only through `palette_mail()` (Resend). Never PHP `mail()`.
- Never print, log or return: Stripe secret keys, webhook signing secrets, `palette_sub_secret`, PayPal secret, Resend key. Admin UI shows key hints only (`sk_live_…a1B2`: first 8 + `…` + last 4).
- Log prefixes: `PALETTE_PAID_UNFULFILLED`, `PALETTE_CANCEL_FAILED`, `PALETTE_MAIL_FAILED`, `PALETTE_STRIPE_ERROR`.
- Amounts in USD cents to Stripe (`unit_amount` 149 / 399 from settings `price_usd` / `ai_price_usd` via `palette_cents()`).
- Webhook responses: 400 bad signature, 405 non-POST, 413 over `PALETTE_WEBHOOK_MAX_BYTES`, 503 transient, 200 handled or ignored. Signature tolerance 300 s.
- Admin = `$_SESSION['user_role'] === 'admin'`; admin user id from `$_SESSION['user_id']`. Every admin POST checks a CSRF token for form `palette_admin` and writes a `palette_admin_log` row.
- UI: no emoji; inline SVG icons via `palette_icon()`; all user-facing server copy in all 7 languages (`server/lib/i18n/{en,zh-CN,hi,es,ar,fr,bn}.php`); admin pages are English only.
- Statuses reuse existing enums: paid Stripe orders use `palette_orders.status='captured'`; failed renewals use subscription/licence status `suspended`.
- Never deploy `server/tests/`, `dev-router.php`, README, DEPLOY or download zips.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. Buyer returns from Stripe at the same moment the webhook arrives → exactly one licence and one email (Task 3, test `pro fulfil race`).
2. A Stripe event for a session or subscription palette did not create (companies' own payments reach every endpoint) → 200 "ignored", no row touched (Task 5, test `foreign event ignored`).
3. Someone not signed in as admin opens `?test=1` or posts to admin actions → live checkout / 403, nothing changes (Tasks 7 and 8, tests `test mode admin only`, `admin 403`).
4. A test-mode payment's key used after 7 days, or a test-mode event while only live is connected → key invalid / event refused with 400 (Tasks 1 and 5).
5. Admin issues a key twice for the same Stripe reference, or double-clicks Save → one licence, the second answer shows the existing key (Task 10, test `issue idempotent`).

---

### Task 1: Schema and Stripe connection layer

**Files:**
- Modify: `server/lib/db.php` (migration)
- Modify: `server/lib/plans.php` (`palette_licence_access` honours test-key expiry), `server/lib/license.php` (`palette_verify` selects `livemode`)
- Create: `server/lib/stripe.php`
- Create: `server/tests/st-stub.php` (not `*-test.php`), `server/tests/stripe-test.php`

**Interfaces:**
- Produces:
  - Migration: `palette_orders` + `provider ENUM('paypal','stripe','admin') NOT NULL DEFAULT 'paypal'`, `stripe_session VARCHAR(80) NULL` (unique index `stripe_session`), `stripe_payment_intent VARCHAR(80) NULL`, `livemode TINYINT(1) NOT NULL DEFAULT 1`; `palette_subscriptions` + `provider ENUM('paypal','stripe') NOT NULL DEFAULT 'paypal'`, `stripe_customer VARCHAR(80) NULL`, `stripe_session VARCHAR(80) NULL` (unique), `livemode TINYINT(1) NOT NULL DEFAULT 1`, `subscription_id` → `VARCHAR(80)`; `palette_licenses` + `source ENUM('paypal','stripe','admin') NULL`, `pay_status ENUM('provider','manual_paid','comp','refunded') NOT NULL DEFAULT 'provider'`, `livemode TINYINT(1) NOT NULL DEFAULT 1`, `subscription_id` → `VARCHAR(80)`; `palette_sub_payments.subscription_id` and `sale_id` → `VARCHAR(80)`; new table `palette_admin_log (id INT UNSIGNED AI PK, license_id INT NULL, order_ref VARCHAR(80) NULL, admin_user_id INT NOT NULL, action VARCHAR(40) NOT NULL, detail JSON NULL, note VARCHAR(500) NULL, created_at DATETIME NOT NULL, KEY license_id)`. Widening uses a new helper `palette_widen_column(mysqli $c, string $table, string $column, string $definition): void` (only when the current length is shorter).
  - `const PALETTE_STRIPE_KEY = 'palette_stripe'` (admin_settings row).
  - `palette_stripe_settings(mysqli $c): array` → `['live' => M, 'test' => M, 'checkout_methods' => ['stripe']]` where `M = ['source' => 'shared'|'own', 'secret' => '', 'webhook_id' => '', 'webhook_secret' => '', 'checked_at' => '', 'error' => '']` (test `source` always `own`). `checkout_methods` is an ordered list from `{'stripe','paypal'}`, default `['stripe']`.
  - `palette_stripe_settings_put(mysqli $c, array $s): void`
  - `palette_stripe_secret(mysqli $c, string $mode): string` — live+shared → companies `st_settings($c)['secret']` when its mode is `live`, else `''`; otherwise the stored own secret.
  - `palette_stripe_ready(mysqli $c, string $mode): bool` — secret non-empty, webhook_secret non-empty, error empty.
  - `palette_stripe_public(mysqli $c): array` — per mode `['connected' => bool, 'source', 'key_hint', 'webhook' => bool, 'checked_at', 'error']` plus `checkout_methods`; never a secret.
  - `palette_st_api(mysqli $c, string $mode, string $method, string $path, array $params = []): array` → `['status' => int, 'json' => array]`; form-encodes `$params` (nested arrays as `a[b][c]=`), Bearer auth with `palette_stripe_secret`, `Stripe-Version: 2024-06-20`, 20 s timeout; uses `$GLOBALS['palette_st_http']` when set.
  - `palette_st_signed(string $payload, string $header, string $secret, ?int $now = null): bool` — `t=`/`v1=` HMAC-SHA256 of `"$t.$payload"`, any matching `v1`, `|now - t| <= 300`, `hash_equals`.
- `server/tests/st-stub.php`: `st_stub_reset(mysqli $c): void` (clears palette tables and settings, seeds `resend_api_key`, installs `$GLOBALS['palette_st_http']` routing `"METHOD /v1/path"` → `$GLOBALS['st_routes']`, recording `$GLOBALS['st_calls']`, and Resend mails into `$GLOBALS['mails']` through the existing `$GLOBALS['pp_http']` hook), `st_stub_connect(mysqli $c, string $mode): void` (stores own secret `sk_{mode}_x`, webhook id/secret `whsec_{mode}`), `st_sign(string $payload, string $secret, ?int $t = null): string`.

- [ ] **Step 1: Write failing tests in `server/tests/stripe-test.php`** — `migration idempotent` (run `palette_migrate` twice; columns exist; `palette_admin_log` exists); `settings defaults` (`checkout_methods === ['stripe']`, both modes empty); `public never leaks` (`json_encode(palette_stripe_public($conn))` contains neither `sk_test_x` nor `whsec_test`, and `key_hint === 'sk_test_…st_x'`-style first8…last4); `shared live key` (with companies `stripe` setting mode live, secret `sk_live_shared`, `palette_stripe_secret($conn,'live') === 'sk_live_shared'`; with companies mode test → `''`); `api form-encodes nested` (call with `['line_items' => [['price_data' => ['unit_amount' => 149]]]]`; recorded body contains `line_items%5B0%5D%5Bprice_data%5D%5Bunit_amount%5D=149`, header `Authorization: Bearer sk_test_x`); `signature ok/bad/stale/multi-v1`; `test key expires` (licence `livemode=0`, `created_at` 8 days ago → `palette_verify(...)['valid'] === false`; 6 days ago → true; `livemode=1` 400 days ago → true).
- [ ] **Step 2: Run** `php server/tests/stripe-test.php` — expected: FAIL (undefined functions).
- [ ] **Step 3: Implement** the migration, `palette_widen_column`, `lib/stripe.php` functions above, and in `palette_licence_access` return no access when `livemode` is `0` and `created_at` is more than 7 days old (`palette_verify`'s SELECT adds `livemode`).
- [ ] **Step 4: Run** `php server/tests/run.php` — expected: `ALL PASSED` (existing suites unaffected).
- [ ] **Step 5: Commit** `Stripe: schema, settings and API layer`.

### Task 2: Connect, test connection, disconnect

**Files:**
- Modify: `server/lib/stripe.php`
- Test: `server/tests/stripe-connect-test.php`

**Interfaces:**
- Consumes: Task 1.
- Produces:
  - `const PALETTE_STRIPE_EVENTS = ['checkout.session.completed','checkout.session.async_payment_succeeded','invoice.paid','invoice.payment_failed','customer.subscription.updated','customer.subscription.deleted','charge.refunded','charge.dispute.created']`
  - `palette_stripe_webhook_url(): string` → `https://vietnamcos.com/palette/stripe-webhook.php`
  - `palette_stripe_connect(mysqli $c, string $mode, string $source, string $secret): array` → `['ok' => bool, 'error' => ?string]`. Checks prefix `sk_{mode}_`/`rk_{mode}_` (own) or that the shared key exists in live; `GET /v1/balance` must be 200 with `livemode` matching; deletes the previously stored palette endpoint (`DELETE /v1/webhook_endpoints/{id}`, 404 ignored); `POST /v1/webhook_endpoints` with `url`, `enabled_events[]`, `description=Site to Design Token (palette)`, `metadata[palette]=1`; stores id + secret, `checked_at`, clears `error`. On failure stores `error` (Stripe's message, no secrets) and keeps the old connection untouched.
  - `palette_stripe_check(mysqli $c, string $mode): array` → `['ok' => bool, 'checks' => [['label' => string, 'ok' => bool, 'detail' => string], ...]]` with labels exactly `Key accepted by Stripe`, `Key is in {mode} mode`, `Webhook registered`, `Webhook enabled`, `Webhook points to this site`, `Webhook sends every needed event`; read-only except `checked_at`/`error`.
  - `palette_stripe_disconnect(mysqli $c, string $mode): array` → `['ok' => bool]`; deletes the endpoint (404 ok) and clears that mode.
  - `palette_stripe_set_methods(mysqli $c, array $methods): array` → `['ok' => bool, 'error' => ?string]`; accepts an ordered subset of `stripe`,`paypal`, non-empty; refuses `stripe` unless live is ready and `paypal` unless companies `pp_ready(pp_settings($c))` (use the existing PayPal readiness check the checkout page uses).

- [ ] **Step 1: Failing tests** — `connect wrong prefix` (live key for test mode → error, no HTTP call); `connect ok registers webhook` (routes for balance and webhook create; stored secret = returned `secret`; request had all 8 events and the palette URL); `reconnect replaces only palette endpoint` (old id deleted, companies' id never touched); `connect failure keeps old` (balance 401 → error set, previous webhook secret kept); `check all pass` / `check disabled webhook` (endpoint `status=disabled` → that line fails, `ok=false`) / `check missing events`; `disconnect`; `methods refuse unconnected` and `methods order kept` (`['paypal','stripe']` stored in that order when both ready).
- [ ] **Step 2: Run** `php server/tests/stripe-connect-test.php` — FAIL.
- [ ] **Step 3: Implement** the functions above.
- [ ] **Step 4: Run** `php server/tests/run.php` — `ALL PASSED`.
- [ ] **Step 5: Commit** `Stripe: connect, test connection, disconnect, checkout methods`.

### Task 3: Pro by Stripe (one payment)

**Files:**
- Create: `server/lib/stripe_checkout.php`
- Modify: `server/lib/api.php` (actions `st_checkout`, `st_confirm`)
- Test: `server/tests/stripe-checkout-test.php`

**Interfaces:**
- Consumes: Task 1 (`palette_st_api`, `palette_stripe_ready`), existing `palette_create_order` validation rules, `palette_issue_license`, `palette_license_email`, `palette_mail`.
- Produces:
  - `palette_checkout_mode(array $session, array $query): string` → `'test'` only when `$query['test'] === '1'` and `$session['user_role'] === 'admin'`, else `'live'`.
  - `palette_st_order_start(mysqli $c, string $mode, string $email, string $lang, bool $consent): array` → `['ok' => true, 'url' => string, 'row_id' => int]` or `['ok' => false, 'error' => 'err_email'|'err_consent'|'err_paused'|'err_stripe']`. Inserts `palette_orders` with `order_id = 'ST-' . strtoupper(bin2hex(random_bytes(8)))`, `provider='stripe'`, `livemode`, amount from `palette_total(settings, null, null)`; session params: `mode=payment`, `line_items[0][price_data][currency]=usd`, `[unit_amount]=<cents>`, `[product_data][name]=Site to Design Token Pro`, `quantity=1`, `customer_email`, `client_reference_id=palette:order:<id>`, `metadata[palette]=order`, `metadata[row]=<id>`, `locale` from lang (`zh-CN`→`zh`, `bn`/`hi`→`auto`), `success_url=https://vietnamcos.com/palette/checkout?st={CHECKOUT_SESSION_ID}&lang=<lang>` (+`&test=1` in test mode), `cancel_url=…?st=cancel&lang=<lang>`.
  - `palette_st_confirm(mysqli $c, string $sessionId): array` → `['state' => 'paid', 'key' => string, 'plan' => 'pro'|'ai'] | ['state' => 'waiting'] | ['state' => 'failed'] | ['state' => 'unknown']`. Looks up the row by `stripe_session` in orders then subscriptions (the latter wired in Task 4), GETs `/v1/checkout/sessions/{id}?expand[]=subscription` in the row's mode, then fulfils.
  - `palette_st_fulfil_order(mysqli $c, array $orderRow, array $session): array` → `['ok' => bool, 'key' => ?string, 'already' => bool]`; claim-first `UPDATE palette_orders SET status='captured', captured_at=NOW(), stripe_payment_intent=? WHERE id=? AND status='created'`; checks `payment_status=paid`, `currency=usd`, `amount_total >= amount cents`; on mismatch sets `failed` and logs `PALETTE_PAID_UNFULFILLED order=<order_id>`; issues licence with `source='stripe'`, `livemode` from the row, sets `palette_orders.license_id`, sends the licence email (failure logs `PALETTE_MAIL_FAILED`). The loser reads the existing licence key.
  - API: `st_checkout` (POST, CSRF `palette_checkout`, rate `create:ip` 20/h, params `email`, `consent`, `lang`, `plan=pro|ai`, `test`) → `{ok, url}`; records the row id in `$_SESSION['palette_st']` (last 20). `st_confirm` (POST, param `session`) → the confirm result; the key is returned only when the session id is in `$_SESSION['palette_st']`, else `{state:'paid', emailed:true}`.

- [ ] **Step 1: Failing tests** — `start params` (assert recorded body has `mode=payment`, `unit_amount%5D=149`, `client_reference_id=palette%3Aorder%3A<id>`, success URL with `{CHECKOUT_SESSION_ID}`); `start paused when live not ready`; `start validation` (bad email, CRLF email, no consent → no HTTP call); `test mode admin only` (`palette_checkout_mode(['user_role'=>'user'], ['test'=>'1']) === 'live'`, admin → `'test'`); `confirm paid issues key and mails once`; `pro fulfil race` (call fulfil twice with the same row snapshot → one licence row, one mail, both return the same key); `amount mismatch` (`amount_total=100` → no licence, order `failed`); `unpaid session → waiting`; `unknown session → unknown`; `test-mode order issues livemode=0 licence`; API: `key only for owning session`.
- [ ] **Step 2: Run** `php server/tests/stripe-checkout-test.php` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php server/tests/run.php` — `ALL PASSED`.
- [ ] **Step 5: Commit** `Stripe: Pro checkout, return confirm and fulfilment`.

### Task 4: Pro + AI by Stripe (subscription)

**Files:**
- Modify: `server/lib/stripe_checkout.php`, `server/lib/api.php` (`st_checkout` with `plan=ai`)
- Test: `server/tests/stripe-sub-test.php`

**Interfaces:**
- Consumes: Task 3, `palette_issue_license($c, $email, $orderId, $lang, ['subscription_id','paid_through','period_start'])`, `palette_sub_email`, settings `ai_price_usd`, `ai_monthly_analyses`.
- Produces:
  - `palette_st_sub_start(mysqli $c, string $mode, string $email, string $lang, bool $consent, ?int $moveLicenceId = null, ?string $trialEnd = null): array` → like `palette_st_order_start`. Inserts `palette_subscriptions` (`provider='stripe'`, `plan_id='stripe'`, `price_usd` from `ai_price_usd`, `livemode`). Session: `mode=subscription`, `line_items[0][price_data][recurring][interval]=month`, `unit_amount=<ai cents>`, `product_data[name]=Site to Design Token Pro + AI`, `subscription_data[metadata][palette]=sub`, `[row]=<id>`, `client_reference_id=palette:sub:<id>`; when `$trialEnd` (Task 10) set, `subscription_data[trial_end]=<unix>`.
  - `palette_st_fulfil_sub(mysqli $c, array $subRow, array $session): array` → same shape as order fulfil. Requires the expanded subscription `status` in `active|trialing`, currency usd, `items.data[0].price.unit_amount >= price cents`; claim-first `UPDATE palette_subscriptions SET status='active', subscription_id=?, stripe_customer=? WHERE id=? AND status='pending'`; issues the AI licence with `order_id = subscription id`, `paid_through = current_period_end`, `period_start = current_period_start` (UTC `Y-m-d H:i:s`), `source='stripe'`; sends `palette_sub_email`. When the row carries a move (Task 10) it re-points the existing licence instead of issuing a new one.
  - Pro + AI shown as available when live Stripe is ready (`palette_ai_on()` true if `stripe` in methods and live ready, or the existing PayPal rule).
- [ ] **Step 1: Failing tests** — `sub start params` (`mode=subscription`, `recurring%5D%5Binterval%5D=month`, `unit_amount%5D=399`); `sub confirm issues ai licence with paid_through = period end`; `sub fulfil race`; `sub price mismatch`; `incomplete subscription → waiting`; `ai on with stripe only` (`palette_ai_on()` true with PayPal unconfigured and live Stripe ready).
- [ ] **Step 2: Run** `php server/tests/stripe-sub-test.php` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php server/tests/run.php` — `ALL PASSED`.
- [ ] **Step 5: Commit** `Stripe: Pro + AI subscription checkout`.

### Task 5: Stripe webhook and events

**Files:**
- Create: `server/stripe-webhook.php`, `server/lib/stripe_events.php`
- Test: `server/tests/stripe-webhook-test.php`

**Interfaces:**
- Consumes: Tasks 1, 3, 4; `palette_sub_payments`, `palette_revoke_by_order`, `palette_usage` reset rule used by PayPal renewals (`palette_sub_extend($c, $row, $paidThrough, $periodStart)`).
- Produces:
  - `palette_st_webhook_response(mysqli $c, string $payload, string $sigHeader): array` → `[int $status, string $text]`. Parses JSON, picks the mode from `livemode`, verifies with that mode's `webhook_secret` (missing secret → 400), dispatches by `type`; anything not palette's → `[200, 'ignored']`; exceptions from DB or Stripe → `[503, 'retry']` and `PALETTE_STRIPE_ERROR`.
  - Ownership: a session is palette's when `metadata.palette` is `order`/`sub` and the row exists with that `stripe_session`; an invoice/subscription when `subscription` matches a `palette_subscriptions.subscription_id` with `provider='stripe'`; a charge when its `payment_intent` matches `palette_orders.stripe_payment_intent` or its `invoice`'s subscription matches.
  - Handlers: `checkout.session.*` → fulfil (Tasks 3/4); `invoice.paid` → insert `palette_sub_payments` (`sale_id` = invoice id, idempotent by unique key), `palette_sub_extend` with the line period end/start, status `active`; `invoice.payment_failed` → status `suspended` on subscription and licence; `customer.subscription.updated` → sync `active`/`suspended` (`past_due`,`unpaid`) and keep `cancel_at_period_end` in status `cancelled` when true; `customer.subscription.deleted` → `cancelled` (or `expired` if `paid_through` passed); `charge.refunded` with `amount_refunded >= amount` and `charge.dispute.created` → revoke licence, licence `pay_status='refunded'`, cancel the Stripe subscription immediately (`DELETE /v1/subscriptions/{id}`; failure logs `PALETTE_CANCEL_FAILED`).
  - `stripe-webhook.php`: same envelope as `webhook.php` (405, 413 with one-byte-over read, `HTTP_STRIPE_SIGNATURE`).
- [ ] **Step 1: Failing tests** (payloads signed with `st_sign`) — `bad signature 400`; `stale signature 400`; `test event checked with test secret`; `test event without test connection 400`; `foreign event ignored` (session with `metadata.kind=api` → 200 `ignored`, no rows changed); `completed fulfils once` (webhook then return → one licence); `invoice.paid extends and is idempotent` (same invoice twice → one payment row, `paid_through` = period end); `payment_failed suspends but access holds until paid_through + 48h` (via `palette_verify`); `subscription deleted`; `refund revokes and cancels`; `partial refund ignored`; `dispute revokes`; `db failure 503` (simulate by throwing from a routed stub); `oversized body 413` (endpoint test via function with length guard).
- [ ] **Step 2: Run** `php server/tests/stripe-webhook-test.php` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php server/tests/run.php` — `ALL PASSED`.
- [ ] **Step 5: Commit** `Stripe: webhook endpoint and subscription lifecycle`.

### Task 6: Cancel, billing portal and nightly reconcile

**Files:**
- Modify: `server/lib/subscriptions.php` (`palette_sub_cancel`, `palette_sub_reconcile`), `server/lib/stripe_events.php`, `server/lib/api.php` (action `st_portal`), `server/lib/pages.php` (account page "Manage billing" for Stripe subscriptions)
- Test: `server/tests/stripe-cancel-test.php`

**Interfaces:**
- Consumes: Tasks 4–5.
- Produces:
  - `palette_sub_cancel` branches on `provider`: Stripe → `POST /v1/subscriptions/{id}` `cancel_at_period_end=true`; failure → `['ok'=>false,'error'=>'err_stripe']`. PayPal path unchanged.
  - `palette_st_portal_url(mysqli $c, string $rawKey, ?int $userId, ?string $email): array` → `['ok'=>true,'url'=>string]` or `['ok'=>false,'error'=>'err_invalid_key'|'err_not_owner'|'err_stripe']`; ownership exactly as `palette_sub_cancel`; `POST /v1/billing_portal/sessions` with `customer`, `return_url=https://vietnamcos.com/palette/account`.
  - `palette_st_reconcile(mysqli $c, int $limit = 200): array` → `['checked'=>int,'changed'=>int]`; for Stripe rows in `active|suspended|cancelled` with `paid_through` within the last 3 days or future, GET `/v1/subscriptions/{id}` and apply the same rules as `invoice.paid` (period end moved and latest invoice paid) and `deleted`; called from `palette_sub_reconcile` after the PayPal pass.
  - API `st_portal` (POST, CSRF `palette_account`, params `key`, `email`) → `{ok,url}`.
- [ ] **Step 1: Failing tests** — `cancel stripe sets cancel_at_period_end`; `cancel stripe failure`; `cancel paypal unchanged` (existing subscriptions-test still passes); `portal owner only`; `reconcile extends on missed invoice`; `reconcile marks deleted`; `reconcile limit`.
- [ ] **Step 2: Run** `php server/tests/stripe-cancel-test.php` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php server/tests/run.php` — `ALL PASSED`.
- [ ] **Step 5: Commit** `Stripe: cancel, billing portal and reconcile`.

### Task 7: Checkout page, return states and copy

**Files:**
- Modify: `server/lib/pages.php` (`palette_render_checkout`, `palette_render_privacy`, `palette_render_terms`, `palette_script_strings`), `server/checkout.php`, `server/assets/checkout.js`, `server/assets/checkout.css`, `server/lib/i18n/*.php` (7 files)
- Modify: `server/tests/dev-router.php` (fake Stripe when `PALETTE_FAKE_STRIPE=1`: session create returns `url=/palette/checkout?st=cs_fake_<n>`, GET session returns paid)
- Test: `server/tests/pages-test.php`, `extension/test/e2e/checkout.spec.ts`

**Interfaces:**
- Consumes: Tasks 2–4 (`checkout_methods`, `palette_stripe_ready`, `palette_checkout_mode`, API `st_checkout`/`st_confirm`).
- Produces: the page renders methods in `checkout_methods` order, Stripe as one primary button per plan; PayPal buttons only when `paypal` is in the list. New i18n keys (all 7 languages): `co_pay_card_pro` ("Pay {price} by card"), `co_pay_card_ai` ("Subscribe for {price} a month"), `co_card_note` ("Card, Apple Pay or Google Pay on Stripe's secure page. We never see your card."), `co_test_banner` ("Test mode — use card 4242 4242 4242 4242. Keys from test payments stop working after 7 days."), `co_st_waiting`, `co_st_cancelled` ("Payment cancelled. Nothing was charged."), `co_st_failed`, `co_manage_billing` ("Manage billing"), `co_manage_billing_note`; privacy names Stripe, Inc. as card processor; terms name Stripe and PayPal. Return: `?st=cs_…` → `st_confirm`, polling every 3 s up to 30 s while `waiting`, then `co_st_waiting` (key will be emailed); `?st=cancel` → form with `co_st_cancelled`.
- [ ] **Step 1: Failing tests** — pages-test: `stripe only renders card button, no PayPal`; `both methods in order`; `paused when nothing ready`; `test banner only for admin with test=1`; `privacy mentions Stripe in all 7 languages`; `every new key exists in all 7 languages`. checkout.spec (with `PALETTE_FAKE_STRIPE=1`): `pay by card redirects and shows key`, `cancel returns to form`, `test banner for admin`, `7 languages fit 360 px`.
- [ ] **Step 2: Run** `php server/tests/pages-test.php` and `npm run e2e:checkout` (in `extension/`) — FAIL on new tests.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php server/tests/run.php` and `npm run e2e:checkout` — all pass.
- [ ] **Step 5: Commit** `Checkout: pay by card with Stripe, return states, copy in 7 languages`.

### Task 8: Admin foundation and licence list

**Files:**
- Create: `server/admin.php`, `server/lib/admin.php`, `server/assets/admin.css`
- Modify: `server/admin-sites.php` (redirect 301 to `admin.php?tab=sites`), `server/lib/sites.php` (sites table rendered inside the admin shell)
- Test: `server/tests/admin-test.php`

**Interfaces:**
- Consumes: Task 1 schema.
- Produces:
  - `palette_admin_page(mysqli $c, array $session, array $query, array $post, string $method): array` → `['status','headers','body']` (same shape as `palette_admin_sites_page`); 403 `Forbidden` unless admin; POSTs require `csrf` for form `palette_admin` (reuse `palette_api_csrf_*` pattern) and redirect 303 back with `?ok=`/`?err=`.
  - `palette_admin_log(mysqli $c, int $adminId, string $action, ?int $licenceId, ?string $orderRef, array $detail, ?string $note): void`
  - `palette_admin_licences(mysqli $c, array $filters, int $page): array` → `['rows'=>[...], 'total'=>int]`; filters `q` (email LIKE, exact key, order_id, subscription_id, stripe_session), `plan` (`pro|ai`), `status` (`active|suspended|revoked|refunded|test`), `source` (`stripe|paypal|admin`); 50 a page, newest first. Each row: `id, license_key, email, plan, source, pay_status, livemode, sub_status, paid_through, revoked_at, created_at`.
  - `palette_admin_totals(mysqli $c): array` → `['pro'=>int,'ai'=>int,'unfulfilled'=>int,'revenue_month_cents'=>int]` (`unfulfilled` = orders `captured` with no `license_id` + orders `failed` with a Stripe payment intent; revenue = captured orders + `palette_sub_payments` completed this calendar month UTC, live only).
  - Shell: tabs Licences, Payments, Sites, Settings (`?tab=`), "Issue a key" button, test badge on `livemode=0` rows. Layout per mockup board "Admin: licences".
- [ ] **Step 1: Failing tests** — `admin 403` (no session, user role; GET and POST); `csrf required`; `list search by email/key/session`; `filters`; `totals` (seed: 2 pro, 1 ai, 1 captured without licence, 1 test order excluded from revenue); `sites tab renders`; `html escapes email` (`<script>` in an email is escaped).
- [ ] **Step 2: Run** `php server/tests/admin-test.php` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php server/tests/run.php` — `ALL PASSED`.
- [ ] **Step 5: Commit** `Admin: shell, licence list, totals, sites tab`.

### Task 9: Admin licence page and actions

**Files:**
- Modify: `server/lib/admin.php`, `server/lib/mail.php` (admin-change email, billing-link email, 7 languages via i18n keys `em_admin_change_*`, `em_billing_link_*`)
- Test: `server/tests/admin-licence-test.php`

**Interfaces:**
- Consumes: Tasks 5–6, 8.
- Produces: `?tab=licence&id=N` view (details, payments from `palette_orders`/`palette_sub_payments`, history from `palette_admin_log` merged with payment events, newest first) and POST actions (`action=` value → function):
  - `access` → `palette_admin_set_access(mysqli $c, int $adminId, int $id, bool $active, string $note, bool $notify): array` (revoked_at NULL/NOW()).
  - `payment` → `palette_admin_set_payment(..., string $payStatus /* provider|manual_paid|comp|refunded */, ...)`; `refunded` also revokes.
  - `until` → `palette_admin_set_until(..., string $dateYmd, ...)` (AI only; sets `paid_through` to that date 23:59:59 UTC; status `active` if in future).
  - `plan` → `palette_admin_set_plan(..., string $plan /* pro|ai */, ...)`.
  - `resend` → `palette_admin_resend(..., )` (licence email in the licence's lang).
  - `cancel` → reuses `palette_sub_cancel` path by provider (admin bypasses ownership).
  - `billing_link` → `palette_admin_billing_link(...)` (Stripe subs: portal URL emailed; never shown to admin).
  - All return `['ok'=>bool,'error'=>?string]`; note required (non-empty, ≤500) for access, payment, until and plan; each writes one `palette_admin_log` row with before/after in `detail`; `notify` sends the admin-change email.
- [ ] **Step 1: Failing tests** — one per action: effect on the row, one log row with before/after, note required, notify sends exactly one mail, `refunded revokes`, `until on pro refused`, `billing link only for stripe`, `cancel stripe calls Stripe`, `double submit same state is a no-op without a second log row`.
- [ ] **Step 2: Run** `php server/tests/admin-licence-test.php` — FAIL.
- [ ] **Step 3: Implement** (layout per mockup board "Admin: one licence").
- [ ] **Step 4: Run** `php server/tests/run.php` — `ALL PASSED`.
- [ ] **Step 5: Commit** `Admin: licence page and actions`.

### Task 10: Issue a key, payments tab, move PayPal subscriber to card

**Files:**
- Modify: `server/lib/admin.php`, `server/lib/stripe_checkout.php`, `server/lib/mail.php`, `server/checkout.php`, i18n (7 languages: `em_move_*`, `co_move_*`)
- Test: `server/tests/admin-issue-test.php`

**Interfaces:**
- Consumes: Tasks 3, 4, 8, 9.
- Produces:
  - `palette_admin_issue(mysqli $c, int $adminId, string $email, string $plan, string $reason /* paid_no_key|paid_other|comp|replacement */, string $ref, string $note, bool $notify): array` → `['ok'=>true,'key'=>string,'existing'=>bool]`. A `cs_`/`pi_` ref: look up the palette order by `stripe_session`/`stripe_payment_intent`; if it already has a licence return it with `existing=true`; if found and paid in Stripe (GET session or payment intent), fulfil through `palette_st_fulfil_order` (so it is linked and marked); otherwise issue with `order_id='ADM-'.hex(8)`, `source='admin'`, `pay_status` = `comp` for comp else `manual_paid`. AI plan: `paid_through` = now + 1 month.
  - `palette_admin_ref_lookup(mysqli $c, string $ref): array` → `['found'=>bool,'paid'=>bool,'amount'=>string,'email'=>?string,'date'=>?string]` for the live hint under the field (GET `admin.php?tab=issue&lookup=`).
  - Payments tab: `palette_admin_payments(mysqli $c, int $limit = 100): array` — orders and sub payments, unfulfilled and failed first.
  - Move to card: `palette_admin_move_link(mysqli $c, int $adminId, int $licenceId, string $note): array` (PayPal AI licences only) emails `https://vietnamcos.com/palette/checkout?move=<token>`; token = base64url(`licenceId.expiresUnix`) + `.` + HMAC-SHA256 with `palette_sub_secret`, 7 days, one use (`palette_admin_log` row `move_used`). Checkout with a valid `move` shows the Pro + AI card only, email fixed, and calls `palette_st_sub_start(..., $moveLicenceId, $trialEnd = licence paid_through)`. On fulfil: the licence's `subscription_id`, `source` switch to Stripe, the PayPal subscription is cancelled (`palette_sub_paypal_cancel`; failure logs `PALETTE_CANCEL_FAILED` and the licence page shows it), the key is unchanged.
- [ ] **Step 1: Failing tests** — `issue comp`; `issue idempotent` (same `cs_` ref twice → same key, `existing=true`, one licence); `issue links paid stripe order`; `issue refuses bad email`; `ref lookup`; `payments order`; `move token valid/expired/tampered/used`; `move keeps key and cancels paypal`; `move paypal cancel failure logged`.
- [ ] **Step 2: Run** `php server/tests/admin-issue-test.php` — FAIL.
- [ ] **Step 3: Implement** (layout per mockup board "Admin: issue a key").
- [ ] **Step 4: Run** `php server/tests/run.php` — `ALL PASSED`.
- [ ] **Step 5: Commit** `Admin: issue a key, payments, move PayPal subscriber to card`.

### Task 11: Admin settings tab (Stripe connection)

**Files:**
- Modify: `server/lib/admin.php`
- Test: `server/tests/admin-settings-test.php`

**Interfaces:**
- Consumes: Task 2.
- Produces: `?tab=settings` with a Live card and a Test card (status, key hint, webhook status, last checked, error) and actions `st_connect` (mode, source `shared|own`, secret — the field is never prefilled), `st_check` (renders the check lines from `palette_stripe_check`), `st_disconnect` (confirm step), `methods` (checkboxes + order). Test card links to `/palette/checkout?test=1`. Each action logs to `palette_admin_log` with `detail` free of secrets.
- [ ] **Step 1: Failing tests** — `settings page never contains a secret` (after connect, body lacks `sk_test_x` and `whsec_`); `connect action stores and logs without secret`; `check renders six lines`; `disconnect needs confirm`; `methods refuse unconnected with message`.
- [ ] **Step 2: Run** `php server/tests/admin-settings-test.php` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php server/tests/run.php` — `ALL PASSED`.
- [ ] **Step 5: Commit** `Admin: Stripe connection settings`.

### Task 12: Extension copy, version 0.3.0, docs

**Files:**
- Modify: `extension/src/i18n/*.json` (7), `extension/src/ui/components/PlanSheet.tsx`, `extension/src/license.ts` (only PayPal-named strings or comments that reach the UI), `extension/public/manifest.json`, `extension/package.json`, `server/lib/pages.php` (`PALETTE_EXT_VERSION`), `server/DEPLOY.md` (Stripe steps: deploy, migrate, connect Test, rehearse, connect Live, set methods), `docs/store/SUBMIT.md`, `docs/store/listing.*.md` (payment wording)
- Test: `extension/test/unit/*.test.ts(x)` as affected, `npm run e2e`, `npm run package:check`

**Interfaces:**
- Produces: `plans.payPro` → "Pay $1.49", `plans.subscribe` → "Subscribe", `plans.aiNote` → "Billed monthly by card. Cancel any time; Pro and AI stay on until the end of the month you paid for." (and translations); no UI string names PayPal. Version `0.3.0` everywhere the 0.2.1 check covers.
- [ ] **Step 1: Failing test** — add to an existing i18n unit test: `no locale string mentions PayPal` (every value in every `src/i18n/*.json` fails `/paypal/i`).
- [ ] **Step 2: Run** `npm test` — FAIL.
- [ ] **Step 3: Implement** copy and version.
- [ ] **Step 4: Run** `npx tsc --noEmit && npm test && npm run e2e && npm run package && npm run package:direct && npm run package:check` and `php server/tests/run.php` — all pass.
- [ ] **Step 5: Commit** `Extension 0.3.0: payment copy without PayPal`.
