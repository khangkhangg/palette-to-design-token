# Deploy runbook: Palette server add-on and extension release

Documentation only. Run these steps by hand, in order, on the owner's go-ahead. Each step ends with a **Verify** block; do not continue until it passes.

Facts (sources: `C:\Projects\CLAUDE.md`, `courses/docs/INFRASTRUCTURE.md`, `server/README.md`):

- Host: Hetzner **spo2x**, `ssh -i ~/.ssh/spo2x_admin root@100.71.6.80` (Tailscale). didudi is retired; never deploy there.
- Companies docroot: `/var/www/duykhang/companies` (PHP 8.4). The add-on goes to `/var/www/duykhang/companies/palette/`.
- Public URLs: `https://vietnamcos.com/palette/...` (the webhook is registered as `https://www.vietnamcos.com/palette/webhook.php`).
- The add-on changes no companies file. Do not edit anything under `/var/www/duykhang/companies` except the new `palette/` directory.
- Mail goes only through Resend. Never PHP `mail()` (the spo2x IP is blacklisted).

Shell variables used below (Git Bash on Windows, repo root `C:\Projects\Palette Extension`):

```bash
SSH="ssh -i ~/.ssh/spo2x_admin root@100.71.6.80"
DOCROOT=/var/www/duykhang/companies
```

## 0. Local checks before anything

```bash
export PATH="/c/Program Files/nodejs:$PATH"
php server/tests/run.php          # PHP 8.4 CLI; needs the local palette_test DB
(cd extension && npm test && npx tsc --noEmit)
git status --short                # clean tree on the release commit
```

**Verify:** all green. (Server tests refuse to run unless the DB name ends in `_test`.)

## 1. Pre-flight on spo2x (read-only)

```bash
# 1a. companies has the PayPal helper and the symbols the coupling contract needs
$SSH "cd $DOCROOT && ls -l includes/paypal.php includes/functions.php includes/ip_rate_limit.php config.php"
$SSH "cd $DOCROOT && grep -nE 'function (pp_settings|pp_ready|pp_api|pp_token|pp_base|pp_http)\b' includes/paypal.php"
$SSH "cd $DOCROOT && grep -nE 'function (generateCSRFToken|verifyCSRFToken|getAdminSetting)\b' includes/functions.php"
$SSH "cd $DOCROOT && grep -nE 'function checkRateLimit\b' includes/ip_rate_limit.php"
$SSH "cd $DOCROOT && grep -cE 'EMAIL_FROM_ADDRESS|EMAIL_FROM_NAME' config.php"

# 1b. PHP 8.4 and PHP-FPM serve vietnamcos.com (needed for the resend timing protection, ruling S4)
$SSH "php -v | head -1"
$SSH "grep -nE 'fastcgi_pass|server_name|root ' /etc/nginx/sites-enabled/vietnamcos.com"
$SSH "ls /run/php/; systemctl list-units 'php*fpm*' --no-legend"

# 1c. PayPal settings exist: prints mode and booleans only, never secrets
$SSH "cd $DOCROOT && php -r 'require \"config.php\"; require \"includes/functions.php\"; require \"includes/paypal.php\"; \$s=pp_settings(\$conn); echo json_encode([\"mode\"=>\$s[\"mode\"],\"enabled\"=>\$s[\"enabled\"],\"has_client_id\"=>\$s[\"client_id\"]!==\"\",\"has_secret\"=>\$s[\"secret\"]!==\"\",\"error\"=>\$s[\"error\"]]),PHP_EOL; echo \"resend_key_set=\", getAdminSetting(\"resend_api_key\",\"\")!==\"\" ? \"yes\":\"no\", PHP_EOL;'"
```

**Verify:**

- `includes/paypal.php` exists; all six `pp_*` functions, the three functions.php functions and `checkRateLimit` are found; the EMAIL_FROM count is 2 or more.
- `fastcgi_pass` appears in the vietnamcos.com vhost and a `php8.4-fpm` unit (or the matching FPM unit) is `active`. If PHP runs another way, `resend` stays synchronous and leaks address existence by timing (see Known limitations). Decide before going live.
- 1c prints `mode` (sandbox or live), `has_client_id:true`, `has_secret:true`, `resend_key_set=yes`. If PayPal is not configured, fix it in `/companies/admin/settings` first.
- Resend: the sender `palette@vietnamcos.com` must be on the verified `vietnamcos.com` domain (directory@ already sends from it). Check Resend dashboard > Domains.

## 2. Upload (exclude tests and dev files)

`server/tests/` holds the test suite and `dev-router.php`; `README.md` and `DEPLOY.md` are for developers. None of them go to production.

```bash
# dry run first, read the file list
rsync -avn --delete \
  --exclude 'tests/' --exclude 'README.md' --exclude 'DEPLOY.md' --exclude '*.log' --exclude '.git*' \
  -e "ssh -i ~/.ssh/spo2x_admin" server/ root@100.71.6.80:$DOCROOT/palette/
# real run once the list looks right
rsync -av --delete \
  --exclude 'tests/' --exclude 'README.md' --exclude 'DEPLOY.md' --exclude '*.log' --exclude '.git*' \
  -e "ssh -i ~/.ssh/spo2x_admin" server/ root@100.71.6.80:$DOCROOT/palette/
$SSH "chown -R www-data:www-data $DOCROOT/palette && find $DOCROOT/palette -type d -exec chmod 755 {} + && find $DOCROOT/palette -type f -exec chmod 644 {} +"
```

If `rsync` is missing in Git Bash, use tar over ssh and then run the chown line:

```bash
tar -C server --exclude=tests --exclude=README.md --exclude=DEPLOY.md -cf - . | $SSH "mkdir -p $DOCROOT/palette && tar -C $DOCROOT/palette -xf -"
```

**Verify:**

```bash
$SSH "ls -la $DOCROOT/palette"     # api.php account.php assets bootstrap.php checkout.php lib migrate.php privacy.php setup.php webhook.php; NO tests, README.md, DEPLOY.md
$SSH "find $DOCROOT/palette ( -name 'dev-router.php' -o -name '*-test.php' ) | wc -l"     # 0
$SSH "stat -c '%U:%G' $DOCROOT/palette $DOCROOT/palette/api.php"                          # www-data:www-data
$SSH "grep -rn PALETTE_NO_MAIL $DOCROOT/palette | grep -v lib/mail.php"                   # prints nothing
curl -s -o /dev/null -w '%{http_code}\n' https://vietnamcos.com/palette/tests/            # 404
curl -s -o /dev/null -w '%{http_code}\n' https://vietnamcos.com/palette/tests/run.php     # 404
curl -s -o /dev/null -w '%{http_code}\n' https://vietnamcos.com/palette/DEPLOY.md         # 404
```

## 3. nginx rewrite

Edit the REAL file `/etc/nginx/sites-enabled/vietnamcos.com`. On spo2x the files in `sites-enabled` are copies, not symlinks: edits to `sites-available` do nothing. The rewrite must be at **server level** (directly inside the `server { }` block that serves vietnamcos.com, not inside a `location`): a `location = /x { rewrite ... }` did not route. Never use `location ^~`.

```bash
$SSH "ls -l /etc/nginx/sites-enabled/vietnamcos.com"     # a regular file (first char '-'), not a symlink
$SSH "cp -a /etc/nginx/sites-enabled/vietnamcos.com /root/vietnamcos.com.bak-\$(date +%Y%m%d-%H%M%S) && ls -l /root/vietnamcos.com.bak-*"
$SSH "nano /etc/nginx/sites-enabled/vietnamcos.com"
```

Add this single line at server level in the HTTPS `server` block that serves vietnamcos.com (for example right after `root ...;` / `index ...;`, before the first `location`). Add it to the `www.` block too if that is a separate content-serving block:

```nginx
rewrite ^/palette/(checkout|account|privacy)$ /palette/$1.php last;
```

```bash
$SSH "nginx -t && systemctl reload nginx"
```

**Verify:**

```bash
$SSH "nginx -T 2>/dev/null | grep -n palette"     # the rewrite line, inside the vietnamcos.com server block
curl -s -o /dev/null -w 'checkout %{http_code}\n' https://vietnamcos.com/palette/checkout     # 200
curl -s -o /dev/null -w 'account  %{http_code}\n' https://vietnamcos.com/palette/account      # 200
curl -s -o /dev/null -w 'privacy  %{http_code}\n' https://vietnamcos.com/palette/privacy      # 200
curl -s -D- 'https://vietnamcos.com/palette/api.php?action=config' | sed -n '1p;/content-type/Ip;$p'   # 200, application/json, {"ok":true,...}
curl -s -o /dev/null -w 'webhook GET %{http_code}\n' https://vietnamcos.com/palette/webhook.php   # anything except 500 (400 or 405 is fine)
```

If a check fails, restore the backup (see Rollback) before debugging. Causes are in `/var/log/nginx/error.log` and the PHP-FPM log.

## 4. Database

```bash
$SSH "cd $DOCROOT/palette && sudo -u www-data php migrate.php"     # prints: palette tables ready
```

Idempotent, safe to repeat. `migrate.php` is CLI-only and answers 404 over HTTP.

**Verify:**

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://vietnamcos.com/palette/migrate.php     # 404
$SSH "cd $DOCROOT && php -r 'require \"config.php\"; foreach ([\"palette_orders\",\"palette_licenses\",\"api_rate_limits\"] as \$t) { \$r=\$conn->query(\"SHOW TABLES LIKE \\\"\$t\\\"\"); echo \$t, \": \", (\$r && \$r->num_rows) ? \"ok\" : \"MISSING\", PHP_EOL; }'"
```

All three print `ok`. If `api_rate_limits` is MISSING, companies migration `013-api-rate-limits.sql` was never applied: apply it through the normal companies process, not from this add-on.

## 5. Settings (`admin_settings` key `palette`)

Merge into the existing value so a stored `webhook_id` / `webhook_mode` is not wiped (`palette_settings_put` writes only known keys and fills the rest with defaults). `extension_ids` stays empty until step 7. Nothing secret is printed.

```bash
$SSH "cd $DOCROOT/palette && sudo -u www-data php -r 'require \"bootstrap.php\"; palette_settings_put(\$conn, [\"price_usd\"=>\"1.49\",\"tax_mode\"=>\"none\",\"extension_ids\"=>[]] + palette_settings(\$conn)); echo json_encode(palette_settings(\$conn)), PHP_EOL;'"
```

**Verify:** the printed JSON is `{"price_usd":"1.49","tax_mode":"none","tax_rate":"0.0725","extension_ids":[],"webhook_id":"","webhook_mode":""}` (webhook fields get filled in step 6). `curl -s 'https://vietnamcos.com/palette/api.php?action=config'` shows price 1.49 and no tax.

## 6. Sandbox end to end

1. Make sure companies PayPal is in **sandbox** mode with sandbox client id and secret: `/companies/admin/settings` (admin login). Step 1c should print `"mode":"sandbox"`.
2. Open `https://vietnamcos.com/palette/setup.php` signed in as an admin (non-admins get 403) and press the button. Expect "Registered a new webhook" (or "Reused the existing webhook") with the webhook id and `(sandbox)`.
   - Verify: PayPal developer dashboard > Sandbox app > Webhooks lists `https://www.vietnamcos.com/palette/webhook.php` with CHECKOUT.ORDER.APPROVED, PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.REFUNDED, PAYMENT.CAPTURE.REVERSED. Re-run the step 5 print: `webhook_id` set, `webhook_mode` = `sandbox`.
3. Open `https://vietnamcos.com/palette/checkout?lang=en` in Chrome with DevTools Console open (Preserve log on). Tick the consent box (the PayPal button stays disabled until then) and buy with a **sandbox buyer** (developer dashboard > Sandbox accounts > personal).
4. Checklist, all must pass:
   - [ ] Key shown on the page as `SDT-XXXX-XXXX-XXXX`; Copy works; "Link to account" present.
   - [ ] Email received at the buyer address, sent **from palette@vietnamcos.com** (Resend dashboard > Emails shows delivered).
   - [ ] Chrome DevTools Console on the checkout page shows **no Content-Security-Policy violations** ("Refused to load/connect/frame ..."). The CSP is `default-src 'self'` plus PayPal hosts; `font-src` and `worker-src` fall back to `'self'`. If the real PayPal SDK reports a violation, widen the CSP in `palette_page_headers()` (`server/lib/pages.php`) **only for the reported host and directive**, commit, re-upload (step 2), re-test.
   - [ ] PayPal developer dashboard > Webhooks > this webhook > events for the purchase: delivery **2xx** and signature verification succeeded. Server log (wherever FPM `error_log` goes, for example `/var/log/php8.4-fpm.log` or the nginx error log) mentions `fulfilled` or `already`, never `unverified`. Events for companies' own orders show as `ignored`: expected, the PayPal app is shared.
   - [ ] `verify` returns valid for the new key: `curl -s -X POST -H 'Content-Type: application/json' -d '{"key":"SDT-XXXX-XXXX-XXXX"}' 'https://vietnamcos.com/palette/api.php?action=verify'`
   - [ ] "Email me my key again" answers "If a key exists for that address, we sent it." and the email arrives.
   - [ ] Refund the sandbox capture (sandbox business account > Activity > Refund). Within a minute PAYMENT.CAPTURE.REFUNDED is delivered with 2xx and `verify` for the key now returns **revoked**.
   - [ ] `/palette/account?key=...` while signed in to companies lists the key and claim works.
5. Repeat one purchase with `?lang=ar` and check the page lays out right to left.

Sandbox data costs nothing; fix and repeat as needed.

## 7. Extension

1. Build: `cd extension && npm run build` (output `extension/dist`; production manifest has no `host_permissions`).
2. Get an extension id, either way:
   - **Unpacked**: `chrome://extensions` > Developer mode > Load unpacked > `extension/dist`. The id is on the card; it changes if the folder path changes.
   - **Web Store, unlisted** (recommended, stable id): `npm run package`, upload `extension/site-to-design-token-0.1.0.zip` in the Developer Dashboard with Visibility = Unlisted, save, and copy the item id (that is the extension id).
3. Add the id to settings (merge, do not overwrite):

```bash
EXT_ID=<32-character extension id>
$SSH "cd $DOCROOT/palette && sudo -u www-data php -r 'require \"bootstrap.php\"; \$s=palette_settings(\$conn); \$s[\"extension_ids\"]=array_values(array_unique(array_merge(\$s[\"extension_ids\"], [\"$EXT_ID\"]))); palette_settings_put(\$conn, \$s); echo json_encode(palette_settings(\$conn)[\"extension_ids\"]), PHP_EOL;'"
```

4. Verify the CORS gate:

```bash
curl -si -X OPTIONS -H "Origin: chrome-extension://$EXT_ID" -H 'Access-Control-Request-Method: POST' 'https://vietnamcos.com/palette/api.php?action=verify' | grep -i access-control     # origin echoed
curl -si -X OPTIONS -H "Origin: chrome-extension://aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" -H 'Access-Control-Request-Method: POST' 'https://vietnamcos.com/palette/api.php?action=verify' | grep -ci access-control-allow-origin   # 0
```

5. Verify activation from the extension against production: enter a key from step 6 in the popup and confirm Pro turns on; open `https://vietnamcos.com/palette/checkout?ext=$EXT_ID`, buy, and confirm the page hands the key to the extension (`chrome.runtime.sendMessage`, which needs the manifest's `externally_connectable` to match `https://vietnamcos.com/*`). A CORS or externally_connectable error in the extension service-worker console means the id was not added or the match is wrong.
6. Owner manual checks (cannot be automated):
   - [ ] `activeTab` real-site flow: click the icon on a live page, colours and fonts extract; install shows no "all sites" permission prompt.
   - [ ] Side panel opens from the popup button and shows the same data.
   - [ ] Switch tab with the panel open: the "click the icon" message appears on a tab not yet activated.
   - [ ] Figma variables import: export, import into Figma, variables arrive with the right names and modes.
   - [ ] Native-speaker review of the 6 non-English translations (zh-CN, hi, es, ar, fr, bn): extension UI, checkout page, emails, store listing.
   - [ ] Real-device RTL check (Arabic): only directional icons mirrored, no clipped text, key field stays LTR.
   - [ ] Offline: with the network off Pro stays on; a revoked key turns Pro off at the next re-check (every 30 days, or clear the stored verified time to force it).

## 8. Go live

1. `/companies/admin/settings`: switch PayPal to **live** with live client id and secret. Step 1c prints `"mode":"live"`.
2. Re-run `https://vietnamcos.com/palette/setup.php` as admin: it must register a **live** webhook (`webhook_mode` = `live`). A mode mismatch is logged on every webhook call until you do.
   - Verify: PayPal developer dashboard > Live app > Webhooks lists the URL with the four events.
3. The owner makes one real $1.49 purchase (consent ticked, `tax_mode` none so the total is $1.49). Check: key shown, email from palette@, webhook delivery 2xx and `fulfilled` in the log, extension unlocks Pro.
4. Refund that payment from PayPal (Activity > the payment > Issue refund). Confirm REFUNDED delivered 2xx, `verify` returns revoked, the extension drops Pro at its next re-check.
5. Optional: for California tax, set `tax_mode` to `ca` with the step 5 command (US + CA buyers then pay $1.60). Default stays `none`.

## 9. Store submission checklist

- [ ] `cd extension && npm run package && npm run package:check` (`package:check` is not part of `npm test`); zip is `extension/site-to-design-token-0.1.0.zip`, manifest version matches.
- [ ] Upload the zip in the Chrome Web Store Developer Dashboard.
- [ ] Listing text from `docs/store/listing.<lang>.md` (en, zh_CN, hi, es, ar, fr, bn).
- [ ] Screenshots from `docs/store/screenshots/` (1280x800 or 640x400) and the promo tile `docs/store/promo-440x280.png`. Known polish: screenshots show host `127.0.0.1`; the export screenshot caption lacks "Pro".
- [ ] Icon: the 128px icon has no transparent padding (Chrome guidance is about 96px artwork); fix if the reviewer flags it.
- [ ] Privacy policy URL: `https://vietnamcos.com/palette/privacy`.
- [ ] Single purpose: extract a page's colours and fonts and export them as design tokens.
- [ ] Permission justifications: `activeTab` (read only the page the user clicked the icon on), `scripting` (run the extractor in that tab), `storage` (settings and Pro licence state), `sidePanel` (show results in the side panel); `externally_connectable` for `https://vietnamcos.com/*` (receive the licence key from the checkout page). No host permissions, no remote code.
- [ ] Data disclosure: the extension sends only the licence key to `vietnamcos.com/palette` for verification; no page content leaves the browser.
- [ ] Pricing note in the description: free to use, with an optional one-time Pro upgrade ($1.49) bought on an external website (in-app purchase via external site, disclosed).
- [ ] After approval, confirm the published id equals the one in `extension_ids`.

## 10. Rollback

```bash
# 1. restore nginx from the step 3 backup (or just delete the one rewrite line)
$SSH "ls -t /root/vietnamcos.com.bak-* | head -1"
$SSH "cp -a \$(ls -t /root/vietnamcos.com.bak-* | head -1) /etc/nginx/sites-enabled/vietnamcos.com && nginx -t && systemctl reload nginx"
$SSH "nginx -T 2>/dev/null | grep -c palette"       # 0

# 2. remove the add-on
$SSH "rm -rf /var/www/duykhang/companies/palette"
curl -s -o /dev/null -w '%{http_code}\n' https://vietnamcos.com/palette/checkout     # 404
```

3. Disable the webhook: PayPal developer dashboard > Webhooks > delete or disable `https://www.vietnamcos.com/palette/webhook.php` in each mode you registered (otherwise PayPal keeps retrying against a 404).
4. The tables `palette_orders` and `palette_licenses` and the `palette` setting can stay. They hold paying customers' keys: do not drop them if any real sale happened. Existing Pro users keep working on network failure; unpublish or hide the Store listing if sales must stop.

## Known limitations

Accepted in review; none block launch.

- **Timing protection needs PHP-FPM.** `resend` answers first and does its work after `fastcgi_finish_request()` (ruling S4). Without it (CLI, `php -S`, mod_php) the work stays synchronous and response time can reveal whether an address bought. Confirm in step 1b.
- **CSRF token per session.** `api.php?action=config` issues the checkout CSRF token and rotates it on every GET, so two checkout tabs in one session invalidate each other (the older tab gets 419 and must reload).
- **Any refund revokes.** A refund or reversal, including a partial refund, revokes the licence. The order row keeps status `captured`.
- **Resend cap can be abused.** The per-email limit (3/hour) is global, so a stranger can use up a victim's resends for an hour (accepted trade-off). `resend` is also 10/hour/IP; `verify` is 30/min/IP.
- **No rate limit on `claim` and `capture`.**
- **Mail is Resend only.** Missing `resend_api_key` or a non-2xx answer means no email (logged); the buyer still has the on-page key and the account page. `PALETTE_NO_MAIL` must never be defined in production.
- **Webhook edge cases.** A PayPal 5xx on signature verification answers 400, not 503; transient fulfil/capture failures answer 503 so PayPal redelivers; validation failures (underpaid, wrong currency, unknown order) answer 200. Verify a real sandbox event's signature before going live (step 6). If the stored webhook mode differs from companies' PayPal mode, every call logs it: re-run `setup.php`.
- **Shared PayPal app.** Both webhooks receive each other's events; non-`palette:` custom_ids are ignored here.
- **Fixed price text.** The refund line hard-codes $1.49; if `price_usd` changes, update the text and translations. Keys appear in URLs (`account?key=`), mitigated by `Referrer-Policy: strict-origin-when-cross-origin`.
- **`setup.php` sends no frame-ancestors / X-Frame-Options** (admin-only page).
- **Extension re-verify quirks (open final-wave item).** A 200 verify response lacking `ok:true`/valid fields (for example a proxy oddity) is treated as invalid and removes Pro at the next re-check instead of counting as a network failure; a missing verified time (NaN) re-checks on every open; the key input loses focus while busy; the client rejects keys without dashes.
- **Key goes to any extension id in `?ext=`.** It is only format-checked; the key is the buyer's own. CORS on `verify` is granted only to ids listed in `extension_ids`, so a new Store id must be added before activation works.
- **Cosmetic.** es/fr store descriptions omit Figma; the fr refund line has a straight apostrophe (matches the server dictionary); the 6 translations still need native-speaker review (step 7).
