# Site to Design Token — design

Date: 2026-10-16 · Status: approved 2026-10-16 · Mockups: https://claude.ai/artifact/XmJqpKxSAe3W8qDo4e4anH
Repo: https://github.com/khangkhangg/palette-to-design-token

## 1. What we are building

A browser extension that reads any website's design system — colours grouped
into roles, typography, spacing, radius, shadows — checks its contrast, and
exports it as design tokens a developer (or their AI coding assistant) can drop
straight into code.

- **Who:** designers and frontend developers worldwide.
- **Why they pick it over Site Palette (palette.site):** it exports a *design
  system*, not a swatch list; it writes a `DESIGN.md` built for AI coding tools;
  it checks contrast with fixes; it costs $1.49 once.
- **Success:** a user opens the extension on a site and, in under a minute, has
  that site's tokens in their project or in their AI tool's context.
- **Usage pattern (owner's estimate):** ~90% of buyers use it once. This drives
  the refund policy (§9) and the price.

## 2. Decisions taken in brainstorming

| Topic | Decision |
|---|---|
| Model | Free basic + **$1.49 one-time** Pro unlock |
| Payment | PayPal, reusing the keys saved at vietnamcos.com/companies/admin/settings |
| Checkout URL | `https://vietnamcos.com/palette/checkout` |
| Unlock | License key by email; optionally linked to a vietnamcos account later |
| UI | Popup (quick) **and** side panel (full); light and dark, user switchable |
| Languages | 7 most-spoken: English, 中文 (Simplified), हिन्दी, Español, العربية (RTL), Français, বাংলা |
| Refunds | None once purchased; buyer agrees at checkout |
| Name | **Site to Design Token** (not "Palette": that is the competitor's brand) |
| Source | `C:\Projects\Palette Extension` → github.com/khangkhangg/palette-to-design-token — extension **and** server code |
| Tooling | Node 24 LTS (installed 2026-10-16 via winget) |

## 3. Open decision

**Tax (affects slice 4 only).** The owner wants tax collected because all
revenue is taxed as personal income. Those are different taxes: income tax is
owed on the $1.49 whether or not a tax line is shown, and money collected as
"sales tax" is not revenue — it must be paid over to the state, and California
does not tax electronically delivered software (CDTFA Regulation 1502), so
there is nothing to pay it over to. If the goal is to cover income tax, raise
the price (e.g. $1.99). To be confirmed with the owner and an accountant before
slice 4; slices 1–3 do not depend on it. If a California line is kept: 7.25% on
buyers whose PayPal address is in California only, computed server-side.

## 4. Scope

**In v1:** everything in the mockups — extraction, roles, contrast, the five
export formats + zip, free/Pro gating, saved sites, license purchase and
activation, theme switch, 7 languages, Chrome Web Store package (runs on Chrome,
Edge, Brave; not Opera, which lacks chrome.sidePanel).

**Not in v1:** Firefox/Safari builds, a vietnamcos admin screen for Palette
sales (query the table; add later), team licenses, scanning pages behind
logins the user has not opened, comparing two sites, colour-blindness simulation.

## 5. Free vs Pro

| Feature | Free | Pro |
|---|---|---|
| Colours with roles, copy one value | yes | yes |
| "Copy hex list" | yes | yes |
| Type / spacing / radius / shadows | view | view + export |
| Contrast pass/fail | yes | yes + suggested fixes |
| Export CSS / Tailwind / Figma / JSON / DESIGN.md / zip | — | yes |
| Saved sites | last 3 | unlimited |

Gating is in the client. A determined user can patch an unpacked copy; at $1.49
that is accepted — no obfuscation or DRM.

## 6. Extension architecture

Manifest V3, TypeScript, Vite, Preact (small, component UI), no remote code.

```
extension/
  manifest.json            permissions: activeTab, scripting, storage, sidePanel
                           externally_connectable: https://vietnamcos.com/palette/*
  src/extract/             runs in the page (injected on demand)
    collect.ts             walk visible elements → raw style samples
    colour.ts              parse, OKLab clustering, roles
    type.ts spacing.ts radius.ts shadow.ts
    contrast.ts            WCAG ratio, effective background, fix search
  src/model/tokens.ts      the one Tokens type every exporter takes
  src/export/              pure functions Tokens → string
    css.ts tailwind.ts figma.ts dtcg.ts designmd.ts zip.ts
  src/ui/                  Preact: popup + side panel share components
    theme.ts i18n.ts       theme tokens (light/dark), translations
  src/license/             activate, store, re-check
  src/background.ts        service worker: inject, messaging, license from checkout
  _locales/<lang>/messages.json   store listing name/description only
  src/i18n/<lang>.json            UI strings
```

**Permissions are minimal:** `activeTab` + `scripting` inject the extractor only
into the tab the user clicked on — no "read all your data on all websites"
warning, no background access to browsing.

### 6.1 Extraction (`src/extract/`)

1. **Collect.** Walk the DOM (including open shadow roots); skip invisible
   elements (`display:none`, zero size, `visibility:hidden`, opacity 0) and cap
   at 4,000 elements. For each: `getComputedStyle` colour, background, border,
   SVG fill/stroke, font family/size/weight/line-height, padding/margin/gap,
   radius, box-shadow; plus visible area and text length.
2. **Colours.** Weight each colour by area (backgrounds) or characters (text).
   Merge near-identical colours in OKLab (ΔE < 2.5). Drop colours under 0.5%
   weight unless used on interactive elements.
3. **Roles** (heuristic, every guess editable by the user in v1.1, not v1):
   - *surface* — the background colours covering the most area, light→dark.
   - *text* — the text colours with the most characters.
   - *primary* — the most-used chromatic colour on links/buttons/nav.
   - *accent* — the next chromatic colour whose hue differs > 30° from primary.
   - *border* — the most-used border colours.
   - anything left over → *other*, still exported.
4. **Type.** Group by family; build the scale from distinct sizes (± 0.5px),
   name by rank (display, h1…h3, body, small, caption) using size and frequency.
   Report web font names as declared; no font files are downloaded.
5. **Spacing.** Histogram of padding/margin/gap; detect a base (4 or 8) when
   ≥ 70% of values are multiples; emit the scale.
6. **Radius / shadow.** Distinct values by frequency, top 4 each.
7. **Contrast.** For each text sample: the effective background is the first
   ancestor with an opaque background colour; if a background image or gradient
   is in the way, the pair is marked "can't check" rather than guessed. WCAG 2.x
   ratio; AA 4.5 (3.0 for large text), AAA 7.0. **Fix (Pro):** move the brand
   colour's OKLCH lightness in the smaller direction until it passes AA, keeping
   hue and chroma; show before/after.

Performance target: < 1.5 s on a typical marketing page; the work runs in the
page then posts one plain-object result back.

### 6.2 Exporters (`src/export/`)

All are pure `(tokens, options) → string` functions and are unit-tested against
golden files.

| Format | File | Shape |
|---|---|---|
| CSS | `tokens.css` | `:root { --color-primary: …; --font-display: …; --space-2: … }` |
| Tailwind | `tailwind.css` (v4 `@theme`) or `tailwind.config.js` (v3) | user picks version |
| Figma | `figma-variables.json` | W3C Design Tokens (DTCG) JSON with collection/modes, for Figma's variable import and Tokens Studio — exact import path verified in implementation |
| JSON | `tokens.json` | W3C DTCG (`$type`, `$value`) |
| DESIGN.md | `DESIGN.md` | headed sections: colour roles table with "use for", type scale, spacing, radius, shadows, contrast rules (failing pairs + fixes), and short instructions to the AI ("use only these tokens") |
| All | `<site>-tokens.zip` | the five files (fflate) |

"Include" toggles (colours, typography, spacing, radius, shadows) filter the
Tokens object before export. Copy uses the clipboard; download uses an object
URL with `<a download>`.

### 6.3 UI

- **Popup (400×600):** header (site, counts, language, re-scan), tabs Colours ·
  Type · Spacing · Contrast, footer "Copy hex list" + "Export tokens". A button
  opens the side panel.
- **Side panel:** the full design-system view and the Export screen.
- **Theme:** Light / Dark / System (default System), in a settings menu; stored
  in `chrome.storage.local`; both themes built from one set of CSS variables.
- **Language:** defaults to the browser language if it is one of the 7, else
  English; user can switch. Own JSON dictionaries rather than `chrome.i18n`,
  because `chrome.i18n` cannot be switched at runtime. Arabic sets `dir="rtl"`;
  layouts use logical CSS properties so they mirror. Fonts: system stacks with
  Noto fallbacks for Devanagari, Bengali, Arabic, CJK (no bundled fonts beyond
  the UI face).
- **Icons:** inline SVG, no emoji (house rule).
- **Saved sites:** last scan per origin in `chrome.storage.local`; free keeps 3
  (oldest dropped), Pro unlimited.

## 7. Licensing and payment

### 7.1 Flow

1. User clicks **Unlock** → extension opens
   `https://vietnamcos.com/palette/checkout?lang=<lang>` in a tab.
2. Page: email field, **"I understand purchases are final and not refundable"**
   checkbox (required), PayPal button. Button creates an order via
   `api/palette.php?action=create` (email) → PayPal order for **1.49 USD**.
3. Buyer approves in PayPal → page calls `action=capture` → server captures
   (existing `pp_capture` path), then **issues a license**: key
   `SDT-XXXX-XXXX-XXXX` (Crockford base32, 60 bits random), stored, emailed.
4. The page shows the key and sends it to the extension with
   `chrome.runtime.sendMessage(EXTENSION_ID, {type: 'license', key})`
   (allowed by `externally_connectable`). If the extension does not answer, the
   page says "paste this key into the extension".
5. Extension calls `action=verify` (key) → `{valid, email_masked}` → stores
   `{key, verifiedAt}`; Pro is on.
6. **Optional link:** a signed-in vietnamcos user enters their key on the
   success page or `/palette/account` → `action=claim` sets `user_id`. A signed-in
   user can then see and copy their key there.
7. **Lost key:** `action=resend` (email) emails every key for that address.
   Always answers "if a key exists, we sent it" (no address probing).

Re-check: the extension re-verifies at most once every 30 days when online; a
network failure keeps Pro on. A revoked key (chargeback) turns Pro off at the
next check.

### 7.2 Server (`server/` in this repo, deployed beside companies)

The server code lives in this repo under `server/` and is deployed to
`/var/www/duykhang/companies/palette/` on spo2x, so it is served at
`vietnamcos.com/palette/…` from the companies docroot. **It changes no
companies file.** It `require`s companies' `config.php` (DB connection, session),
`includes/functions.php` (CSRF, mail) and `includes/paypal.php`, and uses only
its generic helpers: `pp_settings`, `pp_ready`, `pp_api`, `pp_token`, `pp_http`
(the test stub hook). That coupling is listed in `server/README.md`; a change
to those signatures in companies breaks Palette.

```
server/
  checkout.php      /palette/checkout (nginx rewrite if extensionless .php is not served)
  account.php       claim / view keys for a signed-in vietnamcos user
  api.php           create, capture, verify, resend, claim
  webhook.php       PayPal webhook for Palette orders
  setup.php         admin-only: registers webhook.php with PayPal, stores its id
  lib/palette.php   orders, licenses, key generation, mail, rate limits
  lib/i18n/*.php    checkout page strings, 7 languages
  migrate.php       creates the tables (run on deploy)
  tests/
```

- **Orders:** own table `palette_orders` (`order_id` unique, `email`,
  `amount_usd`, `tax_usd`, `status` created|captured|failed, `capture_id`,
  `consent_at`, `created_at`, `captured_at`) — `paypal_orders` is untouched.
  Amount fixed at 1.49 USD (no VND conversion). `custom_id = "palette:<id>"`.
- **Capture** mirrors `pp_capture`/`pp_fulfil`: capture server-side, check
  amount and currency, claim the row with `UPDATE … WHERE status='created'`
  (so page and webhook fulfil once), then issue the license and email it.
- **Webhook:** a PayPal app can hold several webhooks, so Palette registers its
  own URL (`/palette/webhook.php`) from `setup.php` and keeps its id in
  `admin_settings` key `palette`. Events also reach the companies webhook, which
  answers "unknown order" for Palette orders — harmless. Signature verified with
  PayPal as companies does.
- **Licenses:** table `palette_licenses`: `id, license_key (unique), email,
  user_id NULL, order_id, lang, created_at, revoked_at NULL`. Key format
  `SDT-XXXX-XXXX-XXXX`.
- **CORS** for `verify` allows `chrome-extension://<EXTENSION_ID>` only.
- **Rate limits:** `verify` 30/min/IP, `resend` 3/hour/email and 10/hour/IP.
- The checkout page is translated into the same 7 languages (`?lang=`).
- **Deploy:** copy `server/` to spo2x (per `courses/docs/INFRASTRUCTURE.md`),
  run `migrate.php`, then `setup.php` once per PayPal mode (sandbox, live).

## 8. Price and fees

$1.49 USD. PayPal standard (~3.49% + $0.49) leaves ≈ $0.95. Apply for PayPal
micropayments pricing before launch (owner action; no code change).

## 9. Refund policy

Shown on the checkout page, the unlock screen and the store listing:
"All sales are final. Because Pro unlocks instantly and costs $1.49, we do not
offer refunds after purchase." The required checkbox records consent (stored
with the order). This also covers the EU rule that buyers of instantly delivered
digital content can waive withdrawal by explicit consent. PayPal disputes
remain possible; a chargeback revokes the key.

## 10. Error handling

- Pages the extension cannot scan (`chrome://`, Web Store, PDF viewer): a clear
  "This page can't be scanned" state, not an error.
- Pages with almost no styles: show what was found and say so.
- Checkout: PayPal failure messages from `pp_*` shown in the page language;
  capture failure after payment → the webhook finishes it and the email still
  arrives; the page says so.
- Verify offline → keep the stored state; retry later.

## 11. Testing

- **Unit (Vitest, jsdom):** colour parsing/clustering, role heuristics,
  contrast ratios against WCAG reference pairs, fix search, every exporter
  against golden files, i18n key completeness (every language has every key),
  license storage/gating.
- **Extraction fixtures:** 6 saved static pages (light marketing site, dark
  app, gradient hero, Tailwind site, Arabic RTL site, near-empty page) with
  expected roles.
- **E2E (Playwright, unpacked extension in Chromium):** scan fixture → popup
  shows roles; export each format; free user hits the Pro gate; activate a key
  (server mocked); theme switch persists; Arabic layout mirrors.
- **Server (PHP tests in `server/tests/`, stubbing PayPal through
  `$GLOBALS['pp_http']` as companies' `tests/paypal-test.php` does; PHP is not
  installed on this PC, so they run on the spo2x test environment or after a
  local `winget install PHP.PHP.8.3`):** create → capture → license issued once; webhook
  path; amount mismatch rejected; verify/resend/claim; rate limits. Then the
  PayPal **sandbox** end-to-end on the spo2x test environment before live.

## 12. Delivery slices

Each slice is usable and shipped/tested before the next.

1. Extraction + popup Colours/Contrast tabs, light/dark, English.
2. Type/spacing/radius/shadows + side panel.
3. Exporters + Export screen (gated behind a dev flag).
4. Server: licenses, checkout page, PayPal sandbox; extension activation.
5. Six more languages + RTL.
6. Store package: icons, screenshots, listing in 7 languages, privacy policy
   (the extension sends nothing except the license key to vietnamcos.com).
