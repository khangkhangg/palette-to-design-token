# Stripe payments and licence admin — design

Date: 2026-11-04. Status: approved in conversation, pending review of this document.
Mockups: https://claude.ai/artifact/HNKbx1LvrB8qDuZVHAXF3e (checkout, return, admin list, licence, issue key).

## Goal

Take money now. PayPal is not connected on production; Stripe already is (live, on the companies
site). Buyers pay for Pro ($1.49 once) and Pro + AI ($3.99 a month) by card, Apple Pay or Google
Pay through Stripe Checkout, with the same email-and-key flow as today and no vietnamcos account
needed. The owner gets an admin page to find any licence, change it, and issue keys by hand.

## Owner decisions

- Stripe takes both plans. Pro + AI is a real Stripe subscription.
- The checkout page shows Stripe only for now. Admin chooses the methods checkout offers (Stripe,
  PayPal or both) in Settings; PayPal code stays. Existing PayPal licences and subscriptions keep working
  (webhook, cron, cancel).
- Admin can change a licence's access and payment status, its plan and its Pro + AI end date,
  issue a key and email it, resend a key, and cancel a subscription. Every change is logged.
- Admin login is the existing companies admin session (`$_SESSION['user_role'] === 'admin'`).
  dkhang@gmail.com is already user 1, role admin; nothing to create.
- Stripe connection is managed on the palette admin Settings tab: live and test keys, connect,
  test connection, switch which one checkout uses.
- Unchanged: no tax, no refunds (policy; the owner can still refund in Stripe), Resend for all
  mail, prices from palette settings, 48 h renewal grace.

## Non-goals

- No card form on our pages (Stripe's hosted page only).
- No Stripe Billing products or prices set up in the dashboard; prices are sent per session.
- No change to companies files. No refunds issued from our admin (they are done in Stripe).
- No bulk migration of PayPal subscribers to Stripe (one at a time from admin, by the customer's
  own payment, is in scope).

## Architecture

New and changed files, all under `server/`:

| File | Purpose |
|---|---|
| `lib/stripe.php` | Palette's Stripe layer: settings (`palette_stripe` key), `palette_st_api()` (one HTTP call, replaceable in tests through `$GLOBALS['palette_st_http']`), connect / test connection / webhook registration, signature check. Does not include companies' `includes/stripe.php`; it only reads the shared live key through companies' `st_settings()` when the owner chooses "use the vietnamcos.com key". |
| `lib/stripe_checkout.php` | Create a Checkout session for Pro (mode `payment`) or Pro + AI (mode `subscription`); confirm on return; fulfil (shared with the webhook). |
| `lib/stripe_events.php` | Webhook event handlers (table below) and the reconcile step for the cron. |
| `stripe-webhook.php` | Endpoint: size cap, signature check, dispatch. Same response rules as `webhook.php`. |
| `lib/admin.php` | Admin pages as pure functions (list, licence, issue, settings) and actions, like `palette_admin_sites_page()`. |
| `admin.php` | Router for `/palette/admin`, `?tab=licences|licence&id=…|issue|payments|sites|settings`. |
| `lib/orders.php`, `lib/subscriptions.php` | Gain a `provider` branch where they call PayPal (cancel, reconcile). |
| `checkout.php`, `assets/checkout.js`, `lib/pages.php`, `lib/i18n/*.php` | Stripe button, return handling, provider-neutral copy, Stripe named in privacy and terms. |
| `cron-subscriptions.php` | Also runs the Stripe reconcile. |

The extension changes copy only: "Pay $1.49 with PayPal" and "Billed monthly through PayPal"
become provider-neutral ("Pay $1.49", "Subscribe", "Billed monthly by card"). Version 0.3.0.

## Data

Migration (idempotent, in `lib/db.php`):

- `palette_orders`: `provider ENUM('paypal','stripe','admin') NOT NULL DEFAULT 'paypal'`,
  `stripe_session VARCHAR(80) NULL UNIQUE`, `stripe_payment_intent VARCHAR(80) NULL`.
- `palette_subscriptions`: `provider ENUM('paypal','stripe') NOT NULL DEFAULT 'paypal'`,
  `stripe_customer VARCHAR(80) NULL`, `stripe_session VARCHAR(80) NULL UNIQUE`;
  `subscription_id` widened to `VARCHAR(80)`.
- `palette_licenses`: `source ENUM('paypal','stripe','admin') NULL`,
  `pay_status ENUM('provider','manual_paid','comp','refunded') NOT NULL DEFAULT 'provider'`.
- New `palette_admin_log`: `id, license_id NULL, order_id NULL, admin_user_id, action VARCHAR(40),
  detail JSON, note VARCHAR(500), created_at`. History on the licence page reads this plus payments.
- `palette_licenses.livemode TINYINT(1) NOT NULL DEFAULT 1` (0 = bought in Stripe test mode).
- Settings key `palette_stripe` (JSON, never printed, never sent to the browser in full):
  `{"live": {"source": "shared"|"own", "secret", "webhook_id",
  "webhook_secret", "checked_at", "error"}, "test": {...same, source always "own"}}`.

Existing rows default to `paypal` / `provider`, so nothing changes for them.

## Flow: Pro, one payment

1. `POST api.php action=st_checkout plan=pro email consent lang` (CSRF + rate limit as `create`).
   Creates a `palette_orders` row (`provider='stripe'`, status `created`, amount from settings),
   then a Checkout session: `mode=payment`, one `price_data` line in USD cents,
   `customer_email`, `client_reference_id = 'palette:order:<row id>'`,
   `metadata[palette]=order`, `metadata[row]=<id>`,
   `success_url=/palette/checkout?st={CHECKOUT_SESSION_ID}`, `cancel_url=/palette/checkout?st=cancel`,
   `payment_method_types` left to Stripe (card, Apple Pay and Google Pay follow the dashboard).
   Stores the session id; returns `{url}`; the page redirects.
2. Return: `checkout.php?st=cs_…` asks the API to confirm. The server GETs the session; if
   `payment_status=paid`, it fulfils.
3. Fulfil (shared by return and webhook): the claim-first update
   (`UPDATE … SET status='paid' WHERE id=? AND status='created'`), currency `usd` and
   `amount_total >= amount` checked, then `palette_issue_license(source='stripe')` and the key email.
   The loser of the race finds it done and shows the same key. A mismatch marks the order failed
   and logs `PALETTE_PAID_UNFULFILLED` for the admin's "Paid, no key yet" list.

## Flow: Pro + AI, subscription

1. `action=st_subscribe` creates the `palette_subscriptions` row (`provider='stripe'`, price stored),
   then a session: `mode=subscription`, `price_data` with `recurring[interval]=month`,
   `subscription_data[metadata][palette]=sub`, `[row]=<id>`, the same URLs.
2. Return or `checkout.session.completed`: fetch the session with its subscription expanded;
   check status `active`, currency and amount; store `subscription_id` and `stripe_customer`; issue
   the AI licence with `paid_through = current_period_end` (access until it plus 48 h, as today).

Webhook events (palette's own endpoint, only rows it created; anything else answers 200 "ignored"):

| Event | Action |
|---|---|
| `checkout.session.completed`, `checkout.session.async_payment_succeeded` | Fulfil order or subscription (above). |
| `invoice.paid` | Record in `palette_sub_payments` (idempotent on invoice id); extend `paid_through` to the line's period end; reset the usage period when it moved. |
| `invoice.payment_failed` | Status `suspended` (the existing "payment problem" state; admin shows "Payment failed"); nothing else (the grace keeps access to `paid_through` + 48 h). |
| `customer.subscription.updated` | Sync status and `cancel_at_period_end`. |
| `customer.subscription.deleted` | Status `cancelled`/`expired`; access ends at `paid_through` + 48 h. |
| `charge.refunded` (full), `charge.dispute.created` | Revoke the licence, as PayPal refunds do; cancel the subscription if any. |

Signatures: `Stripe-Signature` with a 5-minute tolerance, checked against the secret of whichever
mode the event says (`livemode`), so test and live share one endpoint.

Cancel: the existing `sub_cancel` action branches on provider; for Stripe it sets
`cancel_at_period_end=true`. "Manage billing" on the return page and the account page opens a
Stripe customer-portal session for that customer (cards, receipts, cancel).

Cron: for Stripe rows, GET each active or past-due subscription (up to 200 a day) and apply the
same rules as `invoice.paid`/`deleted`, in case a webhook was missed.

## Connection settings (admin › Settings)

One card per mode, Live and Test, each showing: status (connected / not connected / error with
Stripe's message), key hint (`sk_live_…a1B2`), webhook status, last checked time.

- Live: choose "Use the vietnamcos.com Stripe account" (reads companies' live key; default) or
  "Use a separate key" (paste). Test: paste `sk_test_…` or `rk_test_…`.
- **Connect**: checks the key's prefix matches the mode, calls `GET /v1/balance` (or account), then
  registers palette's webhook (`/palette/stripe-webhook.php`, the six events) in that mode,
  replacing only the endpoint palette registered before (by stored id), and keeps its signing secret.
- **Test connection**: re-checks the key, GETs the stored webhook endpoint (exists, enabled, right
  URL and events), and reports each check with a pass or fail line. Read-only.
- **Disconnect**: deletes palette's webhook endpoint in that mode and clears the stored key.
- **Test checkout is admins only.** The public checkout always uses Live. A signed-in admin opens
  `/palette/checkout?test=1` (linked from the Test card) to pay with the Test key; the page shows a
  "Test mode — use card 4242 4242 4242 4242" banner. Anyone else asking for `?test=1` gets the
  live page. Keys from test-mode payments are marked `test` (licence column `livemode=0`), expire
  7 days after issue, and show a Test badge in admin. (Replaces a public Live/Test switch, which
  would let anyone get a working key with Stripe's public test card.)
- **Payment methods on checkout**: Stripe only / PayPal only / both, and which shows first when
  both (setting `checkout_methods`, replacing the `paypal_on_checkout` flag; starts as Stripe only).
  A method that is not connected cannot be switched on; the control says why. If every chosen
  method is disconnected, checkout shows "Payments are paused".

Secrets are only ever written, never echoed back; the page receives hints and statuses.

## Admin pages

All require an admin session, CSRF on every POST, and log to `palette_admin_log`.

- **Licences** (list): search by email, key, order id, Stripe or PayPal id; filters plan, status
  (active, past due, revoked, refunded), source; 50 a page. Totals: active Pro, active Pro + AI,
  paid without a key (orders `paid`/`failed` with no licence), revenue this month (Stripe + PayPal
  orders and subscription payments).
- **Licence**: details, payments, history; actions:
  - Access: active / revoked (`revoked_at`).
  - Payment: as provider / mark paid by hand / free (comp) / refunded (records only; the page says
    refunds are made in Stripe).
  - Pro + AI access until (date, +1 month); plan switch Pro ↔ Pro + AI.
  - Resend key email (in the licence's language).
  - Cancel subscription (Stripe or PayPal, at period end).
  - Send billing link (Stripe subscribers): emails a Stripe customer-portal link so the customer
    changes their own card. Admin never sees or enters card details.
  - Move to card (PayPal subscribers): emails a one-use link (HMAC-signed, 7 days) to subscribe by
    Stripe. On payment the same key moves to the Stripe subscription; the Stripe trial is set to
    the PayPal `paid_through` so paid days carry over and nothing is charged twice; the PayPal
    subscription is cancelled. If the PayPal cancel fails, `PALETTE_CANCEL_FAILED` is logged and
    the licence page shows it.
  - Each action takes a reason (required for status, payment and plan changes) and an optional
    "email the customer" tick.
- **Issue a key**: email, plan, reason (paid, key not issued / paid another way / free / replacement),
  optional payment reference. A Stripe `cs_`/`pi_` reference is looked up; if it is a palette
  order, the key is linked to it and the order marked fulfilled. Optional email; the key is shown
  once to copy.
- **Payments**: recent Stripe and PayPal payments, with failed and unfulfilled ones first.
- **Sites**: the existing site list moves here as a tab.

## Checkout page and copy

- One button per plan: "Pay $1.49 by card" / "Subscribe for $3.99 a month". A line under it:
  "Card, Apple Pay or Google Pay on Stripe's secure page. We never see your card."
- "Payments are paused" only when no chosen method is connected (Stripe: the Live connection).
- Pro + AI is offered when Stripe is connected (no PayPal plan needed).
- Return states: paid (key), waiting (Stripe still processing; the page polls for 30 s, then says the
  email will follow), cancelled (back to the form, nothing charged), failed.
- All 7 languages, server and extension. Privacy names Stripe as processor; terms name Stripe and
  PayPal as payment providers.

## Errors and safety

- Never print or log keys, webhook secrets or full card or customer data. Logs use prefixes
  `PALETTE_PAID_UNFULFILLED`, `PALETTE_CANCEL_FAILED`, `PALETTE_MAIL_FAILED`, `PALETTE_STRIPE_ERROR`.
- Webhook: 400 bad signature, 413 over 64 KB, 503 on a transient database or Stripe error (Stripe
  retries), 200 for everything handled or ignored.
- Amount or currency mismatch: no key; order flagged for the admin.
- Admin actions are idempotent and logged; issuing a key for an order that already has one shows
  that key instead of making a second.

## Testing

- Server unit tests (`server/tests`, `palette_test` database, fake HTTP): session creation params,
  return confirm, double fulfil race, mismatch, each webhook event, signature (good, bad, stale,
  test vs live secret), cancel branch, cron reconcile, connect / test connection / disconnect,
  settings never leaking secrets, every admin action and its log row, admin 403 for non-admins,
  CSRF, `?test=1` ignored for non-admins, test keys expiring after 7 days.
- Checkout e2e (Playwright, local PHP, fake Stripe): Stripe-only page, pay redirect, return states,
  admin test-mode banner, 7 languages fit.
- Extension: unit and e2e for the new copy; package check.
- Live rehearsal after deploy: Test mode with 4242 card for both plans, renewal via Stripe's test
  clock or a manual invoice, cancel, refund → revoke; then switch checkout to Live.

## Rollout

1. Deploy server (backup first, migrate) with checkout methods still as today; connect Test and
   rehearse on production through the admin-only test checkout.
2. Owner reviews; connect Live (shared key) and set checkout methods to Stripe only.
3. Publish extension 0.3.0 (direct zip now; Store upload by the owner).
