# AI insights (Pro + AI subscription): design

Date: 2026-10-17 · Status: draft for owner review
Mockups: https://claude.ai/artifact/XmJqpKxSAe3W8qDo4e4anH, row 3, boards G–J
Builds on: `2026-10-16-palette-to-design-token-design.md` (the extension, licences, PayPal one-time checkout)

## 1. What we are building

A new **AI insights** section at the bottom of the extension's side panel. For the site the user is looking at, it answers three questions:

1. **Which sites should I look at?** These are competitors and look-alikes in the same sector, found with a web search and the AI.
2. **Is this design good, bad or competitive** for its sector, and why? The answer is a verdict, what works and what could be better.
3. **What do the colours say,** and do they fit this kind of business?

It is sold as a monthly plan, **Pro + AI at $3.99 a month**, which includes everything in Pro. Every site analysed, and every competitor found, is saved to a **private list** on the server, so a future directory feature can build on it.

**Success:** a subscriber presses one button and, in about 15 seconds, gets a useful, honest read of the site and 3–6 sites worth opening. Each site's link works, and each is labelled by where it came from.

## 2. Decisions taken with the owner

| Topic | Decision |
|---|---|
| Plan | **Pro + AI, $3.99/month**, includes Pro. Pro stays **$1.49 once**. |
| Unit sold | **Analyses**, not tokens: **100 analyses a month** (setting, can change). A hidden monthly token cap per subscriber is a safety net (§7). |
| Consent | AI runs only when the user presses **Analyse**, each time. A "What gets sent" note shows until the user hides it. The privacy policy and Store data disclosure are updated. |
| Competitors | **Web search plus AI memory.** Every site is labelled **Web** (found in the search results) or **AI** (from the model's memory: "check it"). |
| Saving sites | **Private list only**: server tables; nothing published. Pushing sites into the directory is a later, separate feature. |
| AI provider | The companies **AI gateway's platform key** (DeepSeek) via its low-level helpers, so usage appears in the existing AI reports (§6.3). |
| Payment | PayPal **Subscriptions**, on the shared PayPal app. |
| Refunds | All sales final. Cancelling stops the next renewal. Pro and AI stay on until the end of the month already paid. |

## 3. Scope

**In:**
- the side-panel AI section (states G–I);
- the upgrade sheet (J);
- plan choice on the checkout page;
- PayPal subscription purchase, renewal, failure, cancel and refund handling;
- subscription licences and a usage meter;
- the analyse API, the web search, the AI call and result caching;
- the private site list;
- an account-page subscription panel (status, renews/ends on, Cancel);
- updated privacy text, terms, listing and data disclosure;
- all 7 languages, with the AI answering in the user's UI language.

**Out:**
- publishing the site list to the directory;
- teams and multiple seats;
- yearly plans;
- California tax on subscriptions (tax_mode applies to the one-time Pro only; noted in §11);
- AI in the popup (side panel only);
- screenshots or page HTML sent to the AI (only the design summary).

## 4. User experience (mockups G–J)

- **G · Not subscribed:** the AI card explains the three things it does, shows "Pro + AI · 100 analyses a month · $3.99/month", and has a **See Pro + AI** button. It appears for free and Pro users alike.
- **J · Upgrade:** two plan cards, Pro + AI (default) and Pro. The PayPal button opens the checkout page (`/palette/checkout?plan=ai&lang=…&ext=…`) in a new tab. "Already have a key? Enter it" is there too.
- **H · Ready:** shows the usage bar ("63 of 100 analyses left · resets 1 Nov") and the "What gets sent" note, with "Don't show this note again" stored locally. The button reads **Analyse \<host\>**, with "Uses 1 analysis".
- **Running:** a progress state ("Searching the web…" then "Asking the AI…") and Cancel. A timeout after 45 s gives a friendly error, and no analysis is charged.
- **I · Results:**
  - sector tags;
  - the verdict on a 3-step scale (behind / on par / competitive) plus a 2–3 sentence summary;
  - "What the colours say": up to 4 of the site's main colours, each with its meaning;
  - fit for the sector (strong / ok / weak) with a reason;
  - Works / Could be better: up to 3 each;
  - "Check these sites": 3–6 rows with name, domain, why, and a Web/AI badge, each opening the site in a new tab;
  - the footer "AI can be wrong · analysed \<date\> · used 1 of 100".
  - The last result per site is kept in the browser and shown again without spending an analysis. **Run again** spends one.
- **Out of analyses:** "You've used all 100 analyses this month. Resets \<date\>." The button is disabled, and no upsell appears beyond the plan.
- **Subscription ended or payment failed:** the AI card returns to G with "Your Pro + AI plan ended on \<date\>". Pro goes too, unless the user also bought the one-time Pro.

## 5. Architecture

```
extension side panel ──POST /palette/api.php?action=analyse──▶ server (palette add-on)
  {key, url, title, description, design summary, lang}         │ verify licence (plan ai, active, quota)
                                                               │ cache lookup (domain, 7 days)
                                                               │ web search (2 queries)
                                                               │ AI call (companies gateway helpers, platform key)
                                                               │ validate JSON, label sources, store usage + sites
◀── {result, usage {used, limit, resets_on}} ──────────────────┘
```

No new extension permissions. The extension already calls vietnamcos.com over CORS for `verify`, and its `extension_ids` allowlist applies here too.

### 5.1 What the extension sends (and nothing more)

- **The page:** `url` with the query string and fragment stripped (origin plus path only), `title` (≤ 200 chars), and `meta description` (≤ 300 chars).
- **The design summary:** the role colours (hex plus role, up to 10), fonts (families, ≤ 3), type sizes, spacing base, radius, the contrast pass/fail counts and failing pairs. All of it comes from the existing Tokens model.
- **Language and key:** `lang` (the UI language) and `key`.

The page HTML, the text and other tabs are never sent.

### 5.2 Server: analyse

1. **Validate.** Check the licence: `plan = 'ai'`, status `active` or paid-through ≥ now. Check quota: analyses used this period < limit, and tokens used this period < the token cap. Check rate limits: 6 analyses/min and 60/hour per licence, and 20/hour per IP.
2. **Cache.** If any user analysed the same host (lowercased, leading `www.` stripped) in the same language within 7 days, and the design summary's colour set is unchanged, return the cached result. It is marked `cached: true` and **does not use an analysis**.
3. **Web search.** Two queries, built server-side from title, description and domain: `"<brand or title>" competitors`, and `<sector guess from title/description> <product words> brand website`. Keep the top 8 results from each (title, url, snippet). The provider is configurable (§6.2).
4. **AI call.**
   - **Input:** one system prompt plus a user message. The page fields and search snippets are wrapped as untrusted data and must never be followed as instructions.
   - **Output:** the model must answer in `lang`, as JSON matching the schema in §5.3. `max_tokens` 1,800; temperature 0.3.
5. **Validate output.**
   - Parse the JSON and drop anything outside the schema.
   - Lowercase and validate domains, and drop the site's own domain.
   - A site is labelled **web** only if its domain appears in the search results; otherwise **ai**.
   - Build links server-side as `https://<domain>/`.
   - Cap the lists.
   - If parsing fails: one retry with "return valid JSON only". If that fails too, return an error and charge nothing.
6. **Record** usage (one analysis plus real tokens from the provider's usage block), the AI log row (§6.3), the result cache, and the private site list (§8).
7. **Respond** with `{ok, result, cached, usage: {used, limit, resets_on}}`.

### 5.3 Result schema (what the UI renders)

```json
{
  "sector": "Specialty coffee", "tags": ["Online shop", "Premium, craft"],
  "verdict": "behind|on_par|competitive", "summary": "2–3 sentences",
  "colours": [{"hex": "#1F5E4B", "title": "Deep green: natural, trusted", "meaning": "1–2 sentences"}],
  "fit": {"level": "strong|ok|weak", "reason": "1 sentence"},
  "works": ["≤3 short items"], "improve": ["≤3 short items"],
  "sites": [{"name": "…", "domain": "example.com", "why": "≤80 chars", "relation": "competitor|similar", "source": "web|ai"}]
}
```

`colours[].hex` must be one of the hex values that were sent; any others are dropped.

## 6. Server components (in `server/`, same rules: no companies file changes)

### 6.1 Licences become plans

`palette_licenses` gains these columns:

| Column | Type / values |
|---|---|
| `plan` | `'pro'` (default) or `'ai'` |
| `subscription_id` | NULL or PayPal `I-…` id |
| `sub_status` | `active`, `cancelled`, `suspended`, `expired` |
| `paid_through` | DATETIME |
| `period_start` | DATETIME |

- **Access rule:**
  - Pro is on when a non-revoked `pro` licence exists, or an `ai` licence has `paid_through ≥ now`.
  - AI is on when an `ai` licence has `paid_through ≥ now`.
- **Upgrading.** A Pro buyer who subscribes keeps their Pro key. The subscription issues a **new** `SDT-` key with `plan ai`, emailed and handed to the extension the same way. The extension stores the AI key and keeps the Pro key as a fallback. The email and the account page explain this.
- **verify** returns `plan`, `ai_active`, `paid_through`, and `usage {used, limit, resets_on}` when ai.

### 6.2 Settings (`admin_settings` 'palette', merged as today)

- **Price and limits:** `ai_price_usd` "3.99", `ai_monthly_analyses` 100, `ai_monthly_token_cap` 600000.
- **Web search:** `search_provider` is `"brave"` or `"tavily"`, and `search_key`. It is a secret: never printed, and set through a CLI command in DEPLOY.
- **PayPal:** `ai_paypal_plan_id` and `ai_paypal_plan_mode`, filled by `setup.php` (§6.4).
- **Provider recommendation:** **Brave Search API** for independent web results at a predictable per-query price. Tavily is the alternative. Both sit behind one `palette_search(string $q): array` adapter, and the one in use is a setting. *Prices change; confirm on the provider's site before choosing.*

### 6.3 AI calls through the companies gateway

The add-on uses these helpers from `companies/tools/api/ai-gateway.php` and `ai-usage.php`:
- `ai_key_for($conn, 0)`: the platform key and model;
- `ai_provider_request`, `ai_http_call`, `ai_provider_parse`;
- `ai_log($conn, 0, 'palette_ai', $usage, $meta)`, so every call appears in the existing **/hiring-admin/ai/reports**.

It does **not** call `ai_chat()`, because that applies the per-user monthly cap to user 0, which every subscriber would share. This adds to the README coupling contract. If no platform key is configured, analyse answers "AI is unavailable right now" and charges nothing.

### 6.4 PayPal subscriptions

- **Setup.** `setup.php` (admin) creates, once per mode:
  - a PayPal **catalog product** ("Site to Design Token Pro + AI");
  - a **billing plan**: monthly, USD 3.99, `auto_bill_outstanding=true`, `payment_failure_threshold=1`.
  - It stores the plan id and mode, and adds the subscription events to the palette webhook (below).
- **Checkout** (`/palette/checkout?plan=ai`):
  - The page loads the PayPal JS SDK with `vault=true&intent=subscription`; the one-time plan keeps `intent=capture`.
  - The SDK can't mix the two in one page load, so the page loads the right one for the chosen plan, and switching plans reloads the SDK.
  - `createSubscription` sends the server's plan id plus `custom_id = palette-sub:<pending row id>`.
  - On approval the page calls `action=subscribe_confirm`. The server GETs the subscription, checks the plan id, status ACTIVE and amount, then issues the `ai` licence (claim-first, idempotent like orders), emails the key and shows it.
- **Webhook events** (verified as today):

  | Event | Effect |
  |---|---|
  | `BILLING.SUBSCRIPTION.ACTIVATED` | Fulfil if not already fulfilled. |
  | `PAYMENT.SALE.COMPLETED` (each renewal) | Set `paid_through = next_billing_time` (from a GET on the subscription), reset the usage period, record the payment. |
  | `BILLING.SUBSCRIPTION.CANCELLED` | `sub_status=cancelled`; access stays until `paid_through`. |
  | `BILLING.SUBSCRIPTION.SUSPENDED` / `PAYMENT.FAILED` | `sub_status=suspended`; access stays until `paid_through`, no extension. |
  | `BILLING.SUBSCRIPTION.EXPIRED` | Expired. |
  | `PAYMENT.SALE.REFUNDED` / `REVERSED` | Revoke the `ai` licence immediately. |

  Unknown or non-`palette-sub:` custom ids are ignored, as today. Transient failures answer 503.
- **Cancel:**
  - The account page shows plan, status, "renews on" or "ends on", and **Cancel subscription**.
  - That button POSTs `action=sub_cancel` (CSRF, signed-in owner of the key, or a key + email check).
  - The server calls `POST /v1/billing/subscriptions/{id}/cancel` and shows "ends on \<paid_through\>".
  - The cancellation email links to PayPal's own "manage automatic payments" as a backstop.
- **New tables:**
  - `palette_subscriptions(id, subscription_id UNIQUE, email, lang, status, plan_id, license_id, consent_at, created_at, …)`;
  - `palette_sub_payments(id, subscription_id, sale_id UNIQUE, amount_usd, status, paid_at)`.

### 6.5 Usage metering

- **Table:** `palette_ai_usage(license_id, period_start, analyses, tokens_in, tokens_out, PRIMARY KEY(license_id, period_start))`.
- **Period:** the period starts at the subscription's last payment (`period_start`) and resets on each renewal. Before the first renewal, it is the subscription start.
- **Counting:** a cached result counts 0 analyses and 0 tokens. A failed call counts 0 analyses, but its tokens still count toward the token cap.
- **Running out:** hitting the analyses limit or the token cap blocks analyse with a clear message until reset.

### 6.6 Result cache

`palette_ai_cache(domain, lang, colour_hash, result_json, created_at, PRIMARY KEY(domain, lang, colour_hash))`, 7-day TTL, purged opportunistically.

## 7. Cost and margin (estimates, to confirm)

- **One analysis:** about 3–5k tokens, plus 2 searches.
- **DeepSeek:** at its published per-token prices, 100 analyses costs roughly a few cents.
- **Search:** the dominant cost. At roughly $3–5 per 1,000 queries, 100 analyses is about $0.60–1.00 when every one misses the cache. Cache hits cost nothing.
- **PayPal:** about $0.63 of each $3.99 payment.
- **Margin:** about $2.30–3.30 per subscriber per month at full usage, more in practice.
- **Token cap:** 600k/month per subscriber is about 120–150 analyses, so it only binds on abuse.

## 8. Private site list ("save to my directory later")

- **Tables:**
  - `palette_sites(id, domain UNIQUE, name, title, sector, tags_json, country NULL, colours_json, fonts_json, first_seen, last_seen, analyses_count, suggested_count)`;
  - `palette_site_links(site_id, related_site_id, relation competitor|similar, source web|ai, seen_count, PRIMARY KEY(site_id, related_site_id, relation))`.
- **What is written:**
  - every analysed site: upserted, with counters incremented;
  - every suggested site: upserted with name and domain only, `suggested_count++`, plus a link row.
- **Admin access:** a read-only admin view (`/palette/admin-sites.php`, admin session) lists the most-seen sites with a CSV export. This is enough to judge the data later.
- **Privacy:** no user identifiers are stored with sites; only aggregate counts.

## 9. Extension changes

- **Side panel:** the AI section (G, H, running, I, out-of-quota, ended), a new `ai.ts` client (`analyse`, `getUsage`), result rendering in all 7 languages, and the last-result cache in `chrome.storage.local` (per domain, ≤ 30 entries).
- **Upgrade and checkout:** the UnlockSheet becomes the plan chooser (J). Checkout links carry `plan`.
- **Licence:** stores `plan`, `paid_through` and `usage`. `recheckIfDue` for `ai` licences runs daily, not every 30 days (it needs `paid_through`). Network failure keeps the last known state until `paid_through` + 3 days.
- **Settings menu:** shows "Pro + AI · renews \<date\>" or "ends \<date\>" with "Manage subscription", which opens `/palette/account`.

## 10. Privacy, policy and Store

- **Privacy page:** the extension sends nothing unless the user presses Analyse. When they do, it sends the URL (no query string), the title, the description and the design summary to vietnamcos.com, a web-search provider and an AI provider (DeepSeek), to produce the analysis. Results are cached for 7 days. Site names and domains are kept in an aggregate list with no user identifiers.
- **Terms:** the subscription, monthly billing through PayPal, cancelling any time with access until the end of the paid month, no refunds, and the analyses limit (it may change with notice).
- **Store data disclosure:** add "Website content" (title, description) and "Web history" (the URL of the page analysed, on the user's action only). The "Single purpose" statement stays the same: it extracts and explains a page's design.
- **Landing page:** the Free / Pro / Pro + AI table and an AI section with a results screenshot.

## 11. Error handling and edge cases

- **Search provider down:** analyse falls back to AI-only (every site labelled `ai`) and shows "Web search unavailable; suggestions are from AI only".
- **AI provider down or no key:** an error, no charge.
- **Bad AI output:** a retry, then an error, no charge.
- **Site types:**
  - private or intranet hosts (localhost, IPs, `.local`): refused client-side and server-side;
  - adult or gambling sites: analysed like any other (no special casing in v1);
  - very new or unknown brands: the AI is instructed to say it is unsure rather than invent, and empty site lists are fine.
- **Billing events:**
  - **Renewal arrives late:** access lasts until `paid_through`; the payment event moves it forward.
  - **Webhook missed:** a daily cron (`palette/cron-subscriptions.php`, CLI) GETs every active or suspended subscription and reconciles `paid_through` and status.
- **California tax:** not applied to subscriptions (§3). If the owner later wants it, PayPal plans support a tax percentage per plan; that is a follow-up.

## 12. Testing

- **Server (PHP, local MariaDB, PayPal, search and AI stubbed):**
  - plans and access rule;
  - subscribe_confirm idempotency;
  - every webhook event, including out-of-order delivery (renewal before activation);
  - cancel;
  - usage period reset on renewal;
  - quota and token cap;
  - cache hit charges nothing;
  - search-down fallback;
  - output validation (foreign hex, own domain, web/ai labelling, schema junk, prompt-injection strings in the title);
  - rate limits;
  - site-list upserts;
  - cron reconciliation;
  - the admin view needs admin;
  - 7-language strings.
- **Extension (Vitest):**
  - each AI section state;
  - the client and the timeout without charge;
  - the local last-result cache;
  - the plan chooser;
  - licence plan handling and the daily re-check;
  - the settings menu states.
- **E2E (Playwright):**
  - the AI section against the dev router with stubs, from Analyse to results rendered;
  - the checkout plan switch reloading the SDK, with the stub subscription flow;
  - the account page cancel.
- **Manual (owner, sandbox then live):** one real subscription, one renewal (sandbox simulation), a cancel, a refund.

## 13. Delivery slices

1. **Server foundations:** plans in licences, subscription tables, usage, cache, site list, settings, verify returns plan and usage.
2. **AI analyse:** search adapter, gateway call, validation, caching, metering, site list, admin view.
3. **Subscriptions:** setup.php plan creation, checkout plan switch, subscribe_confirm, webhooks, cancel, cron, account panel.
4. **Extension:** AI section, plan chooser, licence plan handling, settings menu.
5. **Policy and marketing:** privacy, terms, landing and checkout copy, store listing and disclosure, 7 languages.
6. **Deploy:** DEPLOY runbook additions; the owner sets the search key and creates the plan in sandbox, then live.

## 14. Open items for the owner

- Confirm **100 analyses a month**.
- Pick the web-search provider (**Brave recommended**) and create its API key.
- PayPal must be live and connected first (the current blocker for Pro too).
- Review the subscription wording in the terms.
