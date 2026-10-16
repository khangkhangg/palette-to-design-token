# Deploy runbook: Palette server add-on and extension release

Documentation only. Run these steps by hand, in order, on the owner's go-ahead. Each step ends with a **Verify** block; do not continue until it passes.

Facts (sources: `C:\Projects\CLAUDE.md`, `courses/docs/INFRASTRUCTURE.md`, `server/README.md`):

- Host: Hetzner **spo2x**, `ssh -i ~/.ssh/spo2x_admin root@100.71.6.80` (Tailscale). didudi is retired; never deploy there.
- Companies docroot: `/var/www/duykhang/companies` (PHP 8.4). The add-on goes to `/var/www/duykhang/companies/palette/`.
- Public URLs: `https://vietnamcos.com/palette/...` (the webhook is registered as `https://www.vietnamcos.com/palette/webhook.php`).
- The add-on changes no companies file. Do not edit anything under `/var/www/duykhang/companies` except the new `palette/` directory.
- Mail goes only through Resend. Never PHP `mail()` (the spo2x IP is blacklisted).

Commands are written for Git Bash on this PC (repo root `C:\Projects\Palette Extension`) driving the server through `$SSH "..."`. If you run them on spo2x directly, remove the `$SSH "..."` wrapper (and the backslashes before `$` inside it). Shell variables:

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
$SSH "cd $DOCROOT && ls -l includes/paypal.php includes/functions.php config.php"
$SSH "cd $DOCROOT && grep -nE 'function (pp_settings|pp_ready|pp_api|pp_token|pp_base|pp_http)\b' includes/paypal.php"
$SSH "cd $DOCROOT && grep -nE 'function (generateCSRFToken|verifyCSRFToken|getAdminSetting)\b' includes/functions.php"
$SSH "cd $DOCROOT && grep -cE 'EMAIL_FROM_ADDRESS|EMAIL_FROM_NAME' config.php"

# 1b. PHP 8.4 and PHP-FPM serve vietnamcos.com (needed for the resend timing protection, ruling S4)
$SSH "php -v | head -1"
$SSH "grep -nE 'fastcgi_pass|server_name|root ' /etc/nginx/sites-enabled/vietnamcos.com"
$SSH "ls /run/php/; systemctl list-units 'php*fpm*' --no-legend"
# Cloudflare: which client IP the rate limits key on (ruling S9)
$SSH "nginx -T 2>/dev/null | grep -n real_ip"

# 1c. PayPal settings exist: prints mode and booleans only, never secrets
$SSH "cd $DOCROOT && php -r 'require \"config.php\"; require \"includes/functions.php\"; require \"includes/paypal.php\"; \$s=pp_settings(\$conn); echo json_encode([\"mode\"=>\$s[\"mode\"],\"enabled\"=>\$s[\"enabled\"],\"has_client_id\"=>\$s[\"client_id\"]!==\"\",\"has_secret\"=>\$s[\"secret\"]!==\"\",\"error\"=>\$s[\"error\"]]),PHP_EOL; echo \"resend_key_set=\", getAdminSetting(\"resend_api_key\",\"\")!==\"\" ? \"yes\":\"no\", PHP_EOL;'"
```

**Verify:**

- `includes/paypal.php` exists; all six `pp_*` functions and the three functions.php functions are found; the EMAIL_FROM count is 2 or more. (Rate limits use the add-on's own table `palette_rate`; companies' `checkRateLimit` is not used.)
- `fastcgi_pass` appears in the vietnamcos.com vhost and a `php8.4-fpm` unit (or the matching FPM unit) is `active`. If PHP runs another way, `resend` stays synchronous and leaks address existence by timing (see Known limitations). Decide before going live.
- `real_ip`: either result is fine. **No output**: `REMOTE_ADDR` is a Cloudflare edge, and `palette_client_ip()` uses `CF-Connecting-IP` because the peer is inside Cloudflare's published ranges. **`real_ip_header CF-Connecting-IP` plus `set_real_ip_from` lines for Cloudflare's ranges**: nginx already put the visitor in `REMOTE_ADDR`, which is not a Cloudflare address, so the add-on uses it as is. Stop only if `set_real_ip_from` trusts everyone (`0.0.0.0/0` or `::/0`) or the header is `X-Forwarded-For` from untrusted peers: visitors could then pick their own IP and dodge the limits.
- 1c prints `mode` (sandbox or live), `"enabled":true`, `has_client_id:true`, `has_secret:true`, `resend_key_set=yes`. If PayPal is not configured, fix it in `/companies/admin/settings` first.
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
$SSH "find $DOCROOT/palette \( -name 'dev-router.php' -o -name '*-test.php' \) | wc -l"     # 0
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
$SSH "nginx -T 2>/dev/null | grep -nE 'rewrite .*/palette/'"     # the rewrite line, inside the vietnamcos.com server block
curl -s -o /dev/null -w 'checkout %{http_code}\n' https://vietnamcos.com/palette/checkout     # 200
curl -s -o /dev/null -w 'account  %{http_code}\n' https://vietnamcos.com/palette/account      # 200
curl -s -o /dev/null -w 'privacy  %{http_code}\n' https://vietnamcos.com/palette/privacy      # 200
curl -s -D- 'https://vietnamcos.com/palette/api.php?action=config' | sed -n '1p;/content-type/Ip;$p'   # 200, application/json, {"ok":true,...}
curl -s -o /dev/null -w 'webhook GET %{http_code}\n' https://vietnamcos.com/palette/webhook.php   # anything except 500 (400 or 405 is fine)
```

If a check fails, restore the backup (see Rollback) before debugging. Causes are in `/var/log/nginx/error.log` and the PHP-FPM log.

Check the `www.` host too: PayPal does not follow redirects and the webhook is registered on `www`.

```bash
curl -s -o /dev/null -w 'www webhook %{http_code}\n' https://www.vietnamcos.com/palette/webhook.php   # must NOT be 301, 302 or 404
```

## 4. Database

```bash
$SSH "cd $DOCROOT/palette && sudo -u www-data php migrate.php"     # prints: palette tables ready
```

Idempotent, safe to repeat. `migrate.php` is CLI-only and answers 404 over HTTP.

**Verify:**

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://vietnamcos.com/palette/migrate.php     # 404
$SSH "cd $DOCROOT && php -r 'require \"config.php\"; foreach ([\"palette_orders\",\"palette_licenses\",\"palette_rate\"] as \$t) { \$r=\$conn->query(\"SHOW TABLES LIKE \\\"\$t\\\"\"); echo \$t, \": \", (\$r && \$r->num_rows) ? \"ok\" : \"MISSING\", PHP_EOL; }'"
```

All three print `ok` (`migrate.php` creates all three).

## 5. Settings (`admin_settings` key `palette`)

Merge into the existing value so a stored `webhook_id` / `webhook_mode` is not wiped (`palette_settings_put` writes only known keys and fills the rest with defaults). `extension_ids` stays empty until step 7. Nothing secret is printed.

```bash
$SSH "cd $DOCROOT/palette && sudo -u www-data php -r 'require \"bootstrap.php\"; \$s=palette_settings(\$conn); \$s[\"price_usd\"]=\"1.49\"; \$s[\"tax_mode\"]=\"none\"; palette_settings_put(\$conn, \$s); echo json_encode(palette_settings(\$conn)), PHP_EOL;'"
```

**Verify:** the printed JSON is `{"price_usd":"1.49","tax_mode":"none","tax_rate":"0.0725","extension_ids":[],"webhook_id":"","webhook_mode":""}` (webhook fields get filled in step 6). `curl -s 'https://vietnamcos.com/palette/api.php?action=config'` shows price 1.49 and no tax.

## 6. Sandbox end to end

**Production's PayPal settings are shared with companies** (one `mode`, one `enabled` flag, one client id). Switching production to sandbox puts companies' own paid checkout (premium, ads, launch plans) on sandbox money too. So:

**6a. Preferred: the spo2x test environment** (`/srv/ctest`, nginx on `127.0.0.1:8088`, its own PHP pool and `*_test` databases; it serves courses today, see `courses/docs/test-environment.md`). Deploy companies plus this add-on there against a test database whose companies PayPal settings are sandbox, open it through `ssh -L 8088:127.0.0.1:8088 -i ~/.ssh/spo2x_admin root@100.71.6.80`, and run items 3 to 5 below. The site is bound to 127.0.0.1, so PayPal cannot reach its webhook: skip the webhook items (setup, delivery 2xx, refund revokes) there. They are covered on production by step 8's real purchase and refund. Optionally, after the live switch, send a test event from PayPal developer dashboard > Webhooks simulator to the live webhook: simulator events carry no real signature, so `400 unverified` is the expected answer and only proves the URL reaches the add-on.

**6b. Fallback: on production, in a guarded window.** Use only if 6a is not possible.

```bash
# before: record the mode (step 1c, no secrets) and the window start in DATABASE time
$SSH "cd $DOCROOT && php -r 'require \"config.php\"; echo \$conn->query(\"SELECT NOW()\")->fetch_row()[0], PHP_EOL;'"
WINDOW_START="<the printed time>"
```

- Pausing companies' checkout with `enabled=false` would also pause this add-on (both read `pp_ready`), so it cannot be used here: pick a quiet hour and keep the window short instead.
- Switch to sandbox in `/companies/admin/settings`, run items 1 to 5 below, then **restore** the recorded mode with the live client id and secret, set `enabled` back on, and re-run `setup.php` (it must report `(live)`). Step 1c must print the recorded mode and `"enabled":true`.
- Audit everything created in the window. Companies orders listed here were paid with sandbox money: tell the owner, who reverses their fulfilment by hand. Palette keys from the window still pass `verify` in production: revoke them.

```bash
$SSH "cd $DOCROOT/palette && sudo -u www-data php -r 'require \"bootstrap.php\"; foreach ([\"paypal_orders\",\"palette_orders\"] as \$tb) { \$st=\$conn->prepare(\"SELECT order_id, status, created_at FROM \$tb WHERE created_at >= ? ORDER BY id\"); \$f=\"$WINDOW_START\"; \$st->bind_param(\"s\", \$f); \$st->execute(); foreach (\$st->get_result() as \$r) echo \$tb, \" \", implode(\" \", \$r), PHP_EOL; }'"
$SSH "cd $DOCROOT/palette && sudo -u www-data php -r 'require \"bootstrap.php\"; \$st=\$conn->prepare(\"SELECT order_id FROM palette_orders WHERE created_at >= ?\"); \$f=\"$WINDOW_START\"; \$st->bind_param(\"s\", \$f); \$st->execute(); foreach (\$st->get_result() as \$r) { palette_revoke_by_order(\$conn, \$r[\"order_id\"]); echo \"revoked \", \$r[\"order_id\"], PHP_EOL; }'"
```

Steps of the sandbox pass:

1. Companies PayPal is in **sandbox** mode with sandbox client id and secret (`/companies/admin/settings`, admin login). Step 1c prints `"mode":"sandbox"`.
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

Sandbox payments cost nothing; on 6a fix and repeat as needed, on 6b close the window (restore + audit) before fixing.

## 7. Extension

1. Build: `cd extension && npm run build` (output `extension/dist`; production manifest has no `host_permissions`).
2. Get an extension id, either way:
   - **Unpacked / direct download**: the production build injects `"key"` from `extension/extension-public-key.txt`, so `extension/dist` and the direct zip always load as **`ldaogkdkcojplcghhpbhdbkdpdmgdcin`**, whatever the folder. Build the download with `npm run package:direct` (`extension/site-to-design-token-<version>-direct.zip`) and upload it to `$DOCROOT/palette/download/` as both `site-to-design-token-<version>.zip` and `site-to-design-token-latest.zip` (the checkout page links the latest name).
   - **Web Store, unlisted** (store id differs from the direct id; add both): `npm run package` (strips `"key"`, which the Store rejects), upload `extension/site-to-design-token-0.1.0.zip` in the Developer Dashboard with Visibility = Unlisted, save, and copy the item id (that is the extension id).
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

5. Verify activation from the extension against production: enter a key from step 6 in the popup and confirm Pro turns on; open `https://vietnamcos.com/palette/checkout?ext=$EXT_ID`, buy, and confirm the page hands the key to the extension (`chrome.runtime.sendMessage`, which needs the manifest's `externally_connectable` to match `https://vietnamcos.com/palette/*` and `https://www.vietnamcos.com/palette/*`). A CORS or externally_connectable error in the extension service-worker console means the id was not added or the match is wrong.
6. Owner manual checks (cannot be automated):
   - [ ] `activeTab` real-site flow: click the icon on a live page, colours and fonts extract; install shows no "all sites" permission prompt.
   - [ ] Side panel opens from the popup button and shows the same data.
   - [ ] Switch tab with the panel open: the "click the icon" message appears on a tab not yet activated.
   - [ ] Figma variables import: export, import into Figma, variables arrive with the right names and modes.
   - [ ] Native-speaker review of the 6 non-English translations (zh-CN, hi, es, ar, fr, bn): extension UI, checkout page, emails, store listing.
   - [ ] Real-device RTL check (Arabic): only directional icons mirrored, no clipped text, key field stays LTR.
   - [ ] Offline: with the network off Pro stays on; a revoked key turns Pro off at the next re-check (every 30 days, or clear the stored verified time to force it).

## 8. Go live

1. PayPal must be **live** (companies already sells on it; it changes only if step 6b ran, which restores it). Do not switch modes here: step 1c must print `"mode":"live"` and `"enabled":true`. If it does not, stop and ask the owner: companies' checkout depends on the same settings.
2. Re-run `https://vietnamcos.com/palette/setup.php` as admin: it must register a **live** webhook (`webhook_mode` = `live`). A mode mismatch is logged on every webhook call until you do.
   - Verify: PayPal developer dashboard > Live app > Webhooks lists the URL with the four events.
3. The owner makes one real $1.49 purchase (consent ticked, `tax_mode` none so the total is $1.49). Check: key shown, email from palette@, webhook delivery 2xx and `fulfilled` in the log, extension unlocks Pro.
4. Refund that payment from PayPal (Activity > the payment > Issue refund). Confirm REFUNDED delivered 2xx, `verify` returns revoked, the extension drops Pro at its next re-check.
5. Optional: for California tax, set `tax_mode` to `ca` in the same read-modify-write form as step 5 (US + CA buyers then pay $1.60). Default stays `none`.

## 9. Store submission checklist

- [ ] `cd extension && npm run package && npm run package:check` (`package:check` is not part of `npm test`); zip is `extension/site-to-design-token-0.1.0.zip`, manifest version matches.
- [ ] Upload the zip in the Chrome Web Store Developer Dashboard.
- [ ] Listing text from `docs/store/listing.<lang>.md` (en, zh_CN, hi, es, ar, fr, bn).
- [ ] Screenshots from `docs/store/screenshots/` (1280x800 or 640x400) and the promo tile `docs/store/promo-440x280.png`. Known polish: screenshots show host `127.0.0.1`; the export screenshot caption lacks "Pro".
- [ ] Icon: the 128px icon has no transparent padding (Chrome guidance is about 96px artwork); fix if the reviewer flags it.
- [ ] Privacy policy URL: `https://vietnamcos.com/palette/privacy`.
- [ ] Single purpose: extract a page's colours and fonts and export them as design tokens.
- [ ] Permission justifications: `activeTab` (read only the page the user clicked the icon on), `scripting` (run the extractor in that tab), `storage` (settings and Pro licence state), `sidePanel` (show results in the side panel); `externally_connectable` for `https://vietnamcos.com/palette/*` and `https://www.vietnamcos.com/palette/*` (receive the licence key from the checkout page). No host permissions, no remote code.
- [ ] Data disclosure: the extension sends only the licence key to `vietnamcos.com/palette` for verification; no page content leaves the browser.
- [ ] Pricing note in the description: free to use, with an optional one-time Pro upgrade ($1.49) bought on an external website (in-app purchase via external site, disclosed).
- [ ] After approval, confirm the published id equals the one in `extension_ids`.

## 10. Rollback

```bash
# 1. restore nginx from the step 3 backup (or just delete the one rewrite line)
$SSH "ls -t /root/vietnamcos.com.bak-* | head -1"
$SSH "cp -a \$(ls -t /root/vietnamcos.com.bak-* | head -1) /etc/nginx/sites-enabled/vietnamcos.com && nginx -t && systemctl reload nginx"
$SSH "nginx -T 2>/dev/null | grep -cE 'rewrite .*/palette/'"       # 0

# 2. remove the add-on
$SSH "rm -rf /var/www/duykhang/companies/palette"
curl -s -o /dev/null -w '%{http_code}\n' https://vietnamcos.com/palette/checkout     # 404
```

3. Disable the webhook: PayPal developer dashboard > Webhooks > delete or disable `https://www.vietnamcos.com/palette/webhook.php` in each mode you registered (otherwise PayPal keeps retrying against a 404).
4. The tables `palette_orders` and `palette_licenses` and the `palette` setting can stay. They hold paying customers' keys: do not drop them if any real sale happened. Existing Pro users keep working on network failure; unpublish or hide the Store listing if sales must stop.

## Known limitations

Accepted in review; none block launch.

- **Timing protection needs PHP-FPM.** `resend` answers first and does its work after `fastcgi_finish_request()` (ruling S4). Without it (CLI, `php -S`, mod_php) the work stays synchronous and response time can reveal whether an address bought. Confirm in step 1b.
- **CSRF token per session.** `api.php?action=config` creates the checkout CSRF token once per session and returns the same token afterwards (no rotation), so several checkout or account tabs share it. A new session (cookie expired) needs a page reload (419 otherwise).
- **Any refund revokes.** A refund or reversal, including a partial refund, revokes the licence. The order row keeps status `captured`.
- **Rate limits** live in the add-on's own table `palette_rate` (fixed windows; rows older than a day are purged on about 1 in 50 calls): `verify` 30/min per IP, `resend` 10/hour per IP and 3/hour per email, `create` 20/hour per IP, `claim` 30/hour per signed-in user. The per-email resend cap is global, so a stranger can use up a victim's resends for an hour (accepted trade-off). `capture` has no limit (it needs an approved PayPal order id).
- **Client IP behind Cloudflare.** `CF-Connecting-IP` is trusted only when the peer is in Cloudflare's published ranges, a list hard-coded in `server/lib/rate.php` (`PALETTE_CLOUDFLARE_CIDRS`, from https://www.cloudflare.com/ips-v4 and ips-v6, 2026-10-17). Refresh it yearly; if Cloudflare adds a range first, visitors arriving through it share their edge IP's limits until then.
- **Key shown only to the buying session.** `capture` returns the key only to the session that created the order; any other caller gets `key: null` and the page says the key was emailed.
- **Mail is Resend only.** Missing `resend_api_key` or a non-2xx answer means no email; the buyer still has the on-page key and the account page. A licence email that fails after a capture logs `PALETTE_MAIL_FAILED order=<PayPal order id>`: alert on that prefix in the PHP-FPM error log and resend by hand (the lost-key form works once mail is fixed). `PALETTE_NO_MAIL` must never be defined in production.
- **Webhook edge cases.** A PayPal 5xx on signature verification answers 400, not 503; PayPal retries any non-2xx, so the event is redelivered either way. Transient fulfil/capture failures answer 503; validation failures (underpaid, wrong currency, unknown order) answer 200; an approval whose capture PayPal holds as PENDING answers 200 `pending` and the later PAYMENT.CAPTURE.COMPLETED issues the key. A real event's signature is first verified in step 6b, or with 6a in step 8's live purchase. If the stored webhook mode differs from companies' PayPal mode, every call logs it: re-run `setup.php`.
- **Shared PayPal app.** Both webhooks receive each other's events; non-`palette:` custom_ids are ignored here. The PayPal mode and `enabled` flag are shared too (step 6).
- **Fixed price text.** The refund line hard-codes $1.49 (French: 1,49 $ US); if `price_usd` changes, update the text and translations. The checkout success page links to `account?key=...`, mitigated by `Referrer-Policy: strict-origin-when-cross-origin`; the companies sign-in redirect never carries the key (it waits in the session).
- **`setup.php` sends no frame-ancestors / X-Frame-Options** (admin-only page).
- **Extension.** The key input loses focus while an activation is in flight.
- **Key goes to any extension id in `?ext=`.** It is only format-checked; the key is the buyer's own. CORS on `verify` is granted only to ids listed in `extension_ids`, so a new Store id must be added before activation works.
- **Cosmetic.** es/fr store descriptions omit Figma; the fr refund line has a straight apostrophe (matches the server dictionary); the 6 translations still need native-speaker review (step 7).
