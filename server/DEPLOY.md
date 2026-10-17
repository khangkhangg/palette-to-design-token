# Deploy runbook: Palette server add-on and extension release

Documentation only. Run these steps by hand, in order, on the owner's go-ahead. Each step ends with a **Verify** block; do not continue until it passes.

Facts (sources: `C:\Projects\CLAUDE.md`, `courses/docs/INFRASTRUCTURE.md`, `server/README.md`):

- Host: Hetzner **spo2x**, `ssh -i ~/.ssh/spo2x_admin root@100.71.6.80` (Tailscale). didudi is retired; never deploy there.
- Companies docroot: `/var/www/duykhang/companies` (PHP 8.4). The add-on goes to `/var/www/duykhang/companies/palette/`.
- Public URLs: `https://vietnamcos.com/palette/...` (the webhook is registered as `https://www.vietnamcos.com/palette/webhook.php`).
- The add-on changes no companies file. Do not edit anything under `/var/www/duykhang/companies` except the new `palette/` directory.
- Mail goes only through Resend. Never PHP `mail()` (the spo2x IP is blacklisted).
- **Pro + AI** (monthly subscription plus AI insights) adds five prerequisites on top of Pro: companies' AI gateway files and a platform DeepSeek key with the `ai_enabled` switch on (step 1d), a Brave Search API key stored without ever echoing it (step 5b), a PayPal subscription plan for each PayPal mode (steps 6c and 8), the daily reconcile cron (step 4b), and the monitoring greps for the `PALETTE_*` alert prefixes (Known limitations). Until a plan exists for the current PayPal mode, the landing page, checkout and extension show Pro + AI as "Coming soon" and nothing can be bought.
- **Never type a key into a command line, a chat or a ticket.** The runbook reads the Brave key silently (`read -rs`) and pipes it over ssh stdin; every verify prints booleans and lengths only.

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

### 1d. Companies AI gateway (read-only, Pro + AI)

The add-on calls companies' AI gateway helpers (never `ai_chat()`, see `server/README.md` coupling contract). Nothing is changed in companies; these only have to be present and configured.

```bash
$SSH "cd $DOCROOT && ls -l tools/api/ai-gateway.php tools/api/ai-usage.php tools/api/hiring-admin.php"
$SSH "cd $DOCROOT && grep -nE 'function (ai_key_for|ai_provider_request|ai_http_call|ai_provider_parse|ai_log|hiring_setting)\b' tools/api/*.php"
# booleans only, never a key
$SSH "cd $DOCROOT && php -r 'require \"config.php\"; require \"tools/api/hiring-admin.php\"; \$k=trim((string)hiring_setting(\$conn,\"deepseek_key\",\"\")); \$e=defined(\"DEEPSEEK_API_KEY\") && DEEPSEEK_API_KEY!==\"\"; echo \"platform_key_in_settings=\", \$k!==\"\"?\"yes\":\"no\", \" env_key=\", \$e?\"yes\":\"no\", \" ai_enabled=\", (int)hiring_setting(\$conn,\"ai_enabled\",1), PHP_EOL;'"
```

**Verify:**

- The three files exist on production and the six functions are found. If they do not, deploy the companies version that has them first (the add-on never edits companies).
- A platform DeepSeek key is configured: `platform_key_in_settings=yes` (companies admin > hiring settings > AI) **or** `env_key=yes` (`DEEPSEEK_API_KEY` in companies' `.env`). Palette uses `ai_key_for($conn, 0)`, the same platform key as companies' own AI features, so its cost shows up on that key.
- `ai_enabled=1`. `ai_enabled=0` is companies' kill switch for all AI: every palette analysis then answers "The AI isn't available right now. No analysis was used." (nothing is sent to DeepSeek, nothing is logged, nobody is charged). Use it deliberately to stop AI spend.

## 2. Upload (exclude tests and dev files)

`server/tests/` holds the test suite and `dev-router.php`; `README.md` and `DEPLOY.md` are for developers. None of them go to production. `download/*.zip` is excluded so `--delete` never removes the extension zip uploaded in step 7.

```bash
# dry run first, read the file list
rsync -avn --delete \
  --exclude 'tests/' --exclude 'README.md' --exclude 'DEPLOY.md' --exclude '*.log' --exclude '.git*' --exclude 'download/*.zip' \
  -e "ssh -i ~/.ssh/spo2x_admin" server/ root@100.71.6.80:$DOCROOT/palette/
# real run once the list looks right
rsync -av --delete \
  --exclude 'tests/' --exclude 'README.md' --exclude 'DEPLOY.md' --exclude '*.log' --exclude '.git*' --exclude 'download/*.zip' \
  -e "ssh -i ~/.ssh/spo2x_admin" server/ root@100.71.6.80:$DOCROOT/palette/
$SSH "chown -R www-data:www-data $DOCROOT/palette && find $DOCROOT/palette -type d -exec chmod 755 {} + && find $DOCROOT/palette -type f -exec chmod 644 {} +"
```

If `rsync` is missing in Git Bash, use tar over ssh and then run the chown line:

```bash
tar -C server --exclude=tests --exclude=README.md --exclude=DEPLOY.md -cf - . | $SSH "mkdir -p $DOCROOT/palette && tar -C $DOCROOT/palette -xf -"
```

**Verify:**

```bash
$SSH "ls -la $DOCROOT/palette"     # admin-sites.php api.php account.php assets bootstrap.php checkout.php cron-subscriptions.php download index.php lib migrate.php privacy.php setup.php terms.php webhook.php; NO tests, README.md, DEPLOY.md
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
rewrite ^/palette/(checkout|account|privacy|terms)$ /palette/$1.php last;
```

`/palette/` itself (the landing page, `index.php`) needs no rewrite: the vhost's `index index.php` serves it. Check that the `index` directive lists `index.php`.

```bash
$SSH "nginx -t && systemctl reload nginx"
```

**Verify:**

```bash
$SSH "nginx -T 2>/dev/null | grep -nE 'rewrite .*/palette/'"     # the rewrite line, inside the vietnamcos.com server block
curl -s -o /dev/null -w 'checkout %{http_code}\n' https://vietnamcos.com/palette/checkout     # 200
curl -s -o /dev/null -w 'account  %{http_code}\n' https://vietnamcos.com/palette/account      # 200
curl -s -o /dev/null -w 'privacy  %{http_code}\n' https://vietnamcos.com/palette/privacy      # 200
curl -s -o /dev/null -w 'terms    %{http_code}\n' https://vietnamcos.com/palette/terms        # 200
curl -s -o /dev/null -w 'landing  %{http_code}\n' https://vietnamcos.com/palette/             # 200
curl -s -o /dev/null -w 'shot     %{http_code} %{content_type}\n' https://vietnamcos.com/palette/assets/screenshots/1-popup-colours.png   # 200 image/png
curl -s -o /dev/null -w 'ai shot  %{http_code} %{content_type}\n' https://vietnamcos.com/palette/assets/screenshots/6-ai-insights.png     # 200 image/png (the landing page AI section)
curl -s -o /dev/null -w 'cron     %{http_code}\n' https://vietnamcos.com/palette/cron-subscriptions.php        # 404 (CLI only)
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
$SSH "cd $DOCROOT && php -r 'require \"config.php\"; foreach ([\"palette_orders\",\"palette_licenses\",\"palette_rate\",\"palette_ai_usage\",\"palette_ai_cache\",\"palette_sites\",\"palette_site_links\",\"palette_subscriptions\",\"palette_sub_payments\"] as \$t) { \$r=\$conn->query(\"SHOW TABLES LIKE \\\"\$t\\\"\"); echo \$t, \": \", (\$r && \$r->num_rows) ? \"ok\" : \"MISSING\", PHP_EOL; }'"
```

All nine print `ok` (`migrate.php` creates them all; it also adds the plan columns to `palette_licenses`, so re-run it on every release).

## 4b. Daily subscription cron (Pro + AI)

`cron-subscriptions.php` (CLI only) reconciles every live subscription with PayPal once a day: status, `paid_through`, and a missed renewal (only on proof of a new payment). The webhook is the fast path; this is the safety net. Install it as a system cron file with the right owner and mode (cron ignores files that are group-writable or not owned by root):

```bash
$SSH "printf '%s\n' '# Pro + AI: reconcile subscriptions with PayPal daily' '15 3 * * * www-data php /var/www/duykhang/companies/palette/cron-subscriptions.php' > /etc/cron.d/palette && chown root:root /etc/cron.d/palette && chmod 644 /etc/cron.d/palette"
```

The line itself is `15 3 * * * www-data php /var/www/duykhang/companies/palette/cron-subscriptions.php`.

**Verify:**

```bash
$SSH "ls -l /etc/cron.d/palette && cat /etc/cron.d/palette"            # -rw-r--r-- root root, the comment and the one cron line
$SSH "systemctl is-active cron"                                        # active
$SSH "cd $DOCROOT/palette && sudo -u www-data php cron-subscriptions.php"   # palette subscriptions: checked 0, changed 0, failed 0 (before any subscriber exists)
```

The next morning: `$SSH "grep -a 'cron-subscriptions\|CRON.*palette' /var/log/syslog | tail -3"` shows the 03:15 run (the log path depends on the distro; `journalctl -u cron` also works). A run with `failed` above 0 means PayPal calls failed: check the PHP error log.

## 5. Settings (`admin_settings` key `palette`)

Merge into the existing value so a stored `webhook_id` / `webhook_mode` is not wiped (`palette_settings_put` writes only known keys and fills the rest with defaults). `extension_ids` stays empty until step 7. Nothing secret is printed.

```bash
$SSH "cd $DOCROOT/palette && sudo -u www-data php -r 'require \"bootstrap.php\"; \$s=palette_settings(\$conn); \$s[\"price_usd\"]=\"1.49\"; \$s[\"tax_mode\"]=\"none\"; palette_settings_put(\$conn, \$s); \$p=palette_settings(\$conn); \$p[\"search_key\"]=\$p[\"search_key\"]!==\"\"?\"(set)\":\"\"; \$p[\"sub_secret\"]=\$p[\"sub_secret\"]!==\"\"?\"(set)\":\"\"; echo json_encode(\$p), PHP_EOL;'"
```

**Verify:** the printed JSON is `{"price_usd":"1.49","tax_mode":"none","tax_rate":"0.0725","extension_ids":[],"webhook_id":"","webhook_mode":"","ai_price_usd":"3.99","ai_monthly_analyses":100,"ai_monthly_token_cap":600000,"search_provider":"brave","search_key":"","ai_paypal_plan_id":"","ai_paypal_plan_mode":"","ai_paypal_product_id":"","ai_paypal_plan_price":"","sub_secret":""}` on a first run (webhook and plan fields get filled in steps 6 and 8; `search_key` shows `"(set)"` once step 5b ran, never the key; `sub_secret` shows `"(set)"` after the first Pro + AI checkout, never the secret). `curl -s 'https://vietnamcos.com/palette/api.php?action=config'` shows price 1.49, no tax and `ai_enabled:false` until a plan exists. Always print settings through this masked form: `json_encode(palette_settings($conn))` on its own would print the search key and the subscription secret.

### 5b. Brave Search key (Pro + AI)

The key is a secret. The command below reads it **silently** into a shell variable (not echoed, not in shell history because it is never part of a command line), pipes it over ssh stdin, merges it into the existing `palette` setting (read-modify-write, so the webhook and plan ids survive) and prints only the provider, a yes/no and the key length. Create the key in the Brave Search API dashboard first (https://brave.com/search/api/). Tavily is also supported: set `search_provider` to `tavily` in the same way instead of `brave`.

```bash
read -rs -p "Brave Search API key: " SEARCH_KEY; echo
printf '%s' "$SEARCH_KEY" | $SSH "cd $DOCROOT/palette && sudo -u www-data php -r 'require \"bootstrap.php\"; \$k=trim((string)stream_get_contents(STDIN)); if (\$k===\"\") { fwrite(STDERR, \"empty key\n\"); exit(1); } \$s=palette_settings(\$conn); \$s[\"search_provider\"]=\"brave\"; \$s[\"search_key\"]=\$k; palette_settings_put(\$conn, \$s); \$t=palette_settings(\$conn); echo \"search_provider=\", \$t[\"search_provider\"], \" key_set=\", \$t[\"search_key\"]!==\"\"?\"yes\":\"no\", \" length=\", strlen(\$t[\"search_key\"]), PHP_EOL;'"
unset SEARCH_KEY
```

Rotate the key by running the same lines again. To switch search off, store an empty key the same way: analyses still work from the model alone and results carry `search_unavailable`.

**Verify:**

```bash
# a real search through the stored key; prints a count, never the key
$SSH "cd $DOCROOT/palette && sudo -u www-data php -r 'require \"bootstrap.php\"; \$r=palette_search(palette_settings(\$conn), \"specialty coffee roasters\"); echo \$r===null ? \"search UNAVAILABLE\" : \"search ok, \".count(\$r).\" results\", PHP_EOL;'"
curl -s 'https://vietnamcos.com/palette/api.php?action=config' | grep -c search_key        # 0: no API action returns it
```

- `search_provider=brave key_set=yes length=<about 30>`; `search ok, N results` with N above 0. `search UNAVAILABLE` means a wrong key, a wrong provider, no quota or a network block.
- Never run `palette_settings($conn)` through `var_dump`, `print_r` or `json_encode` in a one-off command without masking `search_key` and `sub_secret`.
- **Changing the search or AI provider changes the privacy policy.** The privacy page (`priv_ai` in all 7 `server/lib/i18n/*.php`), the extension's "What gets sent" note (`ai.sentBody`) and the store listings (`docs/store/listing.*.md`, including the Data disclosure) name **Brave Search** and **DeepSeek**. Before switching `search_provider` to `tavily`, or companies' platform AI provider away from DeepSeek, update those texts in every language, bump `PALETTE_PRIVACY_UPDATED`, deploy them first, and re-submit the listings.

### 5c. Pro + AI price and limits

The defaults already match the launch plan: `ai_price_usd` 3.99, `ai_monthly_analyses` 100, `ai_monthly_token_cap` 600000 (a hidden safety cap, roughly 120 to 150 analyses; it only binds on abuse). Landing, checkout, terms and FAQ read the price and the limit from these settings. To change one, use the same read-modify-write as step 5 (set the key, call `palette_settings_put`, print the masked form). A new `ai_price_usd` needs "Create or find the subscription plan" in `setup.php` again (it creates a plan for the new price); subscribers already on the old plan keep the price stored on their subscription (ruling A10a). Until that button is pressed the new price is never shown or sold: Pro + AI shows "Coming soon" everywhere, and the pages keep showing the stored `ai_paypal_plan_price` (ruling A18). When the price or the limit changes, also update the store listings (`docs/store/listing.*.md` name $3.99 and 100 analyses) and re-submit them. The refund and cancel wording in the strings does not name a price, but the French and English `co_refund` line still says $1.49 (see Known limitations).


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

### 6c. Pro + AI subscription pass (sandbox)

Prerequisites: steps 1d and 5b done, and `setup.php` run for the webhook in this mode (item 2 above). Reusing an existing webhook adds the eight `BILLING.SUBSCRIPTION.*` and `PAYMENT.SALE.*` events to it; check the PayPal dashboard lists them. The same 6a / 6b rules apply (6a, the test environment, cannot receive webhooks, so the webhook-driven items below run in the live pass of step 8 there).

1. Open `https://vietnamcos.com/palette/setup.php` as an admin and press **Create or find the subscription plan** (the page has a second button, under "Subscription plan"). Expect a plan id `P-...` and `(sandbox)`.
   - Verify: the step 5 masked print shows `ai_paypal_plan_id` set, `ai_paypal_plan_mode` = `sandbox` and `ai_paypal_plan_price` = `3.99`. **Changing `ai_price_usd` later** (ruling A18) puts Pro + AI back to "Coming soon" until this button is pressed again: it then creates a plan at the new price and stores it in `ai_paypal_plan_price`; existing subscribers keep the price their row was sold at. Pressing the button again answers that the plan was reused; the PayPal dashboard (Subscriptions > Plans) lists one active plan, monthly, USD 3.99.
2. Checklist with a **sandbox buyer**, all must pass:
   - [ ] `https://vietnamcos.com/palette/` shows "Pro + AI, $3.99 a month" in the plans table and a "Get Pro + AI" link instead of "Coming soon"; `.../checkout?lang=en&plan=ai` preselects Pro + AI, total $3.99, no tax lines.
   - [ ] Subscribe: approve in PayPal, the key appears on the page, and the email "Your Site to Design Token Pro + AI key" arrives from palette@vietnamcos.com.
   - [ ] `verify` for that key: `curl -s -X POST -H 'Content-Type: application/json' -d '{"key":"SDT-XXXX-XXXX-XXXX"}' 'https://vietnamcos.com/palette/api.php?action=verify'` returns `valid:true`, `plan:"ai"`, `ai_active:true`, usage 0 of 100.
   - [ ] Extension (its id is in `extension_ids`): enter the key; Pro + AI is active, the AI section says 100 analyses left. Analyse a public site: results render, "used 1 of 100". Analyse the same site again within 7 days: served from the cache, still "used 1".
   - [ ] Quota and failure: with `ai_monthly_analyses` set to 1 for the test, a second distinct site answers the quota message with the reset date; set it back to 100. A model failure costs nothing (the e2e covers it; on a live pass, temporarily set companies `ai_enabled` to 0: Analyse answers "The AI isn't available right now. No analysis was used." and the count stays; set it back to 1).
   - [ ] Renewal: make PayPal produce the next payment with its sandbox subscription simulator (Developer Dashboard > Testing tools; the exact tool depends on PayPal's current dashboard) or let a short sandbox cycle elapse. Expect `PAYMENT.SALE.COMPLETED` delivered with 2xx, `paid_through` moved forward one month, `period_start` reset and usage back to 0 of 100 (verify through `verify`). If neither is available, wait for PayPal to bill and run the cron by hand (step 4b): it records the renewal only on proof of a new payment.
   - [ ] Cancel from the account page: sign in to companies, open `/palette/account`; the key shows "Active" and "Renews <date>". (Within 48 h after a missed renewal date it shows "Renewal pending" instead, never "Expired"; ruling A19.) Select Cancel subscription, confirm in the page: it shows "Cancelled, ends <date>", the email "Your Pro + AI subscription is cancelled" arrives, PayPal shows the subscription cancelled, and the key still verifies `ai_active:true` until `paid_through`. Repeat the cancel signed out with key plus subscribing email, and with a wrong email (answers "Only the owner of this key can cancel its subscription ...").
   - [ ] Lapse: set `paid_through` in the past on the sandbox licence row (or wait). `verify` returns `valid:false`, `revoked:false`, `plan:"ai"`, `ai_active:false`; the extension keeps the key and shows the ended state; a one-time Pro key on the same machine keeps working.
   - [ ] Refund: refund a sandbox sale. `PAYMENT.SALE.REFUNDED` is delivered with 2xx, `verify` returns revoked at once, and the PayPal subscription is cancelled too (billing stops).
   - [ ] Confirm error after approval locks: on a fresh checkout, approve a subscription in PayPal while forcing the confirm call to fail (Chrome DevTools > Network > right-click `api.php?action=subscribe_confirm` > Block request URL, or let the session's form token expire). The page must show "We couldn't confirm your subscription. If you were charged, your key will arrive by email — don't subscribe again.", hide the PayPal button, disable the form and keep the plan cards locked; reloading shows a fresh checkout but the key still arrives by email (ACTIVATED / the first sale fulfils it). Unblock the URL afterwards.
   - [ ] Price change before setup: set `ai_price_usd` to another price (step 5c) **without** pressing the plan button. The landing, checkout, terms and FAQ show Pro + AI as "Coming soon" with the old plan price, never the new one, `api.php?action=config` answers `ai_enabled:false`, and `subscribe_start` answers `err_paused`. Set it back (or press the button to create the new plan) and check Pro + AI is sold again.
   - [ ] Refund + failed cancel + renewal alerts: on a sandbox subscription, refund the sale while the PayPal cancel cannot succeed (for example revoke the app's subscription scope for a minute, or refund in the PayPal dashboard and cancel nothing; the log must show `PALETTE_CANCEL_FAILED subscription=<I-...>`). Then let the next sandbox payment happen (or replay a `PAYMENT.SALE.COMPLETED` for that subscription from the webhook simulator). Expect 2xx, `paid_through` unchanged, the key still revoked, and `PALETTE_PAID_UNFULFILLED sale <id> for <I-...>` plus another cancel attempt in the log. Then cancel with key + subscribing email on `/palette/account` (revoked keys are accepted) and confirm PayPal shows it cancelled; the next daily cron run must not report it again.
   - [ ] Monitoring: `grep -a 'PALETTE_' <FPM error log>` after the pass. `PALETTE_PAID_UNFULFILLED` (a payment that may belong to a palette checkout issued or extended no key) and `PALETTE_CANCEL_FAILED` (an automatic PayPal cancel after a refund failed) must be absent or explained (the checks above produce them on purpose). Alert on every line that starts with `PALETTE_` in production (`PALETTE_MAIL_FAILED` too).
   - [ ] Reports: `/hiring-admin/ai/reports` (companies admin) shows the analyses as their own row for user id -1, feature `palette_ai`, not mixed into guest (user 0) usage.
   - [ ] `/palette/admin-sites.php` (admin) lists the analysed hosts with counters and no user, licence, email or IP column.

Sandbox payments cost nothing; on 6a fix and repeat as needed, on 6b close the window (restore + audit) before fixing. In 6b the window audit above also covers subscriptions: cancel any sandbox subscription still active and revoke its key (`palette_revoke_by_order($conn, 'I-...')` takes the `I-...` subscription id, which is the licence's `order_id`).

## 7. Extension

1. Build: `cd extension && npm run build` (output `extension/dist`; production manifest has no `host_permissions`).
2. Get an extension id, either way:
   - **Unpacked / direct download**: the production build injects `"key"` from `extension/extension-public-key.txt`, so `extension/dist` and the direct zip always load as **`ldaogkdkcojplcghhpbhdbkdpdmgdcin`**, whatever the folder. Build the download with `npm run package:direct` (`extension/site-to-design-token-<version>-direct.zip`) and upload it to `$DOCROOT/palette/download/` as both `site-to-design-token-<version>.zip` and `site-to-design-token-latest.zip` (the landing and checkout pages link the latest name). Verify:

     ```bash
     curl -sI https://vietnamcos.com/palette/download/site-to-design-token-latest.zip | grep -iE '^HTTP|^content-type'   # HTTP/2 200, content-type: application/zip
     ```
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

0. **Owner review of `/palette/terms`.** The terms (`terms_*` in `lib/i18n/*.php`) were drafted without a legal entity name, address or governing law; the page says only "Operated by vietnamcos.com". The owner must read and approve them (and add any of those details) before taking real payments.
1. PayPal must be **live** (companies already sells on it; it changes only if step 6b ran, which restores it). Do not switch modes here: step 1c must print `"mode":"live"` and `"enabled":true`. If it does not, stop and ask the owner: companies' checkout depends on the same settings.
2. Re-run `https://vietnamcos.com/palette/setup.php` as admin: it must register a **live** webhook (`webhook_mode` = `live`). A mode mismatch is logged on every webhook call until you do.
   - Verify: PayPal developer dashboard > Live app > Webhooks lists the URL with the four events.
3. The owner makes one real $1.49 purchase (consent ticked, `tax_mode` none so the total is $1.49). Check: key shown, email from palette@, webhook delivery 2xx and `fulfilled` in the log, extension unlocks Pro.
4. Refund that payment from PayPal (Activity > the payment > Issue refund). Confirm REFUNDED delivered 2xx, `verify` returns revoked, the extension drops Pro at its next re-check.
5. Optional: for California tax, set `tax_mode` to `ca` in the same read-modify-write form as step 5 (US + CA buyers then pay $1.60). Default stays `none`.
6. **Pro + AI live.** The plan is per PayPal mode: the sandbox plan id is ignored once PayPal is live. Run `setup.php` as admin again and press **Create or find the subscription plan**: expect a new plan id and `(live)`; the step 5 masked print then shows `ai_paypal_plan_mode` = `live`. Until this is done the landing page, checkout and extension show Pro + AI as "Coming soon", which is the safe default. Steps 1d (gateway, platform key, `ai_enabled` = 1), 4b (cron) and 5b (Brave key, stored in production, not only in the test environment) must all be done on production first.
7. The owner makes one real $3.99 subscription (consent ticked): key shown, email from palette@, `PAYMENT.SALE.COMPLETED` delivered 2xx, the extension shows Pro + AI, one analysis works. Then cancel it from `/palette/account` and refund that payment from PayPal: REFUNDED delivered 2xx, `verify` returns revoked, and the PayPal subscription shows cancelled.
8. Owner review before real payments: the **subscription and AI sections of `/palette/terms`** (`terms_sub`, `terms_ai`) and **`priv_ai` on `/palette/privacy`** in all seven languages (drafted without legal review, like the rest of the terms).

## 9. Store submission checklist

- [ ] `cd extension && npm run package && npm run package:check` (`package:check` is not part of `npm test`); zip is `extension/site-to-design-token-0.1.0.zip`, manifest version matches.
- [ ] Upload the zip in the Chrome Web Store Developer Dashboard.
- [ ] Listing text from `docs/store/listing.<lang>.md` (en, zh_CN, hi, es, ar, fr, bn).
- [ ] Screenshots from `docs/store/screenshots/` (1280x800 or 640x400) and the promo tile `docs/store/promo-440x280.png`. The store takes **five**: upload `1-popup-colours`, `2-contrast`, `3-side-panel-light`, `5-export` and `6-ai-insights` (the Pro + AI results, made with a stubbed analysis by `npm run screenshots`); `4-side-panel-dark` is used only on the landing page. Known polish: screenshots show host `fernleaf.example`, not a real site.
- [ ] Icon: the 128px icon has no transparent padding (Chrome guidance is about 96px artwork); fix if the reviewer flags it.
- [ ] Privacy policy URL: `https://vietnamcos.com/palette/privacy`.
- [ ] Single purpose: extract a page's colours and fonts and export them as design tokens.
- [ ] Permission justifications: `activeTab` (read only the page the user clicked the icon on), `scripting` (run the extractor in that tab), `storage` (settings and Pro licence state), `sidePanel` (show results in the side panel); `externally_connectable` for `https://vietnamcos.com/palette/*` and `https://www.vietnamcos.com/palette/*` (receive the licence key from the checkout page). No host permissions, no remote code.
- [ ] Data disclosure (Privacy practices tab), from `## Data disclosure` in `docs/store/listing.en.md`: declare **Website content** (title and description, plus the design summary) and **Web history** (the address of the analysed page, without its query string) as collected, both only when the user presses Analyse (user-initiated; Brave Search and DeepSeek process them for us), plus **Authentication information** (the licence key). Tick the three certifications (no sale, no unrelated use, no use for creditworthiness). The permission justifications and the single purpose stay unchanged (no new permission: the extension only calls `vietnamcos.com`, which the build already allows).
- [ ] Pricing note in the description (the `## Pricing note` section of the listing): free to use, with an optional one-time Pro upgrade ($1.49) and an optional Pro + AI subscription ($3.99 a month, cancel any time), both bought on an external website through PayPal (disclosed). Every `listing.<lang>.md` already describes Pro + AI, its price, the cancel rule and the Analyse data flow.
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
4. **Subscriptions.** If any real Pro + AI subscription exists, PayPal keeps billing after the add-on is gone and nobody can cancel from the account page. Cancel (and refund where owed) in PayPal first (Products and plans > Subscriptions), or keep the add-on up. Then remove the cron: `$SSH "rm -f /etc/cron.d/palette"`. To stop only Pro + AI, set companies' `ai_enabled` to 0 (analyses stop, access and billing continue) or clear `ai_paypal_plan_id` in the settings (the pages show Coming soon and nobody can subscribe; existing subscribers are unaffected).
5. The tables `palette_orders` and `palette_licenses` and the `palette` setting can stay. They hold paying customers' keys: do not drop them if any real sale happened (the AI tables, `palette_subscriptions` and `palette_sub_payments` are the same). Existing Pro users keep working on network failure; unpublish or hide the Store listing if sales must stop.

## Known limitations

Accepted in review; none block launch.

- **Renewal grace is server-side only (ruling A16).** The server keeps an active subscription's Pro and AI on for 48 h past `paid_through`, but the extension (left unchanged by the ruling) still treats a verified plan as ended 1 h past its stored `paidThrough`: during a late renewal it shows the ended state (and turns Pro off) until a re-check, every 30 minutes past the end, sees the renewed `paid_through`.

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
- **A lost subscription checkout can't be found automatically** (ruling A10). If the buyer closes the page before `subscribe_confirm` and PayPal's ACTIVATED and PAYMENT.SALE.COMPLETED webhooks are both lost, the server never learns the `I-...` subscription id, and PayPal has no search by `custom_id`, so the daily reconcile cannot see that row (it stays `pending`). Support path: the buyer forwards the PayPal receipt, which carries the transaction (sale) id and the subscription id (`I-...`). In the PayPal developer dashboard (Webhooks events), find that sale's `PAYMENT.SALE.COMPLETED` event and resend it. A full-price sale for a matching checkout issues the key and emails it to the address the buyer gave at checkout, even if the subscription has since been cancelled or suspended in PayPal: it covers the paid month and keeps PayPal's status. Running `palette_sub_confirm($conn, 'I-...')` from a one-off CLI script that requires `bootstrap.php` works only while the subscription is still ACTIVE with a reported payment. A cancelled one answers `err_paypal`, so resend the sale event instead.
- **Alert prefixes for subscriptions.**
  - `PALETTE_PAID_UNFULFILLED sale <id> for <I-...>`: a payment that may belong to a palette checkout issued or extended no key. Causes: underpaid; the subscription no longer matches (for example `plan_overridden`) or its `palette-sub:` custom_id matches no checkout (bad HMAC, wrong plan, missing row); a non-transient PayPal lookup failure; the subscription is EXPIRED; or a renewal on a subscription whose key was refunded or never issued (the automatic cancel is retried; see `PALETTE_CANCEL_FAILED`). Check the sale in PayPal, then refund it or issue a key by hand.
  - `PALETTE_CANCEL_FAILED subscription=<I-...>`: after a refund or reversal (or a later renewal or the daily reconcile retrying it), the automatic PayPal cancel failed. The licence is already revoked; the reconcile retries daily and the owner can cancel with key + email on the account page, but cancel the subscription in PayPal by hand so billing stops.
- **AI calls are logged as user -1** (ruling A8, constant `PALETTE_AI_LOG_USER`). `hiring_ai_usage.user_id` is a signed INT and companies logs guest analysis as user 0 (`ai_guard` sums user 0 across features), so palette traffic is a separate row in `/hiring-admin/ai/reports`, feature `palette_ai`. The key still comes from `ai_key_for($conn, 0)` (the platform key), so palette spend is part of that key's bill. Companies' `ai_enabled = 0` answers every analysis as unavailable with no call, no log row and no charge.
- **Page text is scrubbed, not anonymised** (ruling A9). The title and description lose URLs, email addresses, @handles, unread counters like `(3)` and `[12]` and digit runs of 6 or more before they reach the prompt, the searches, the 7-day shared cache or the site list. A name or address written as plain words in a title is not detected. The privacy page and the store listings promise exactly this (email addresses, links (anything starting with http, https or www, or containing a path), @handles and long digit runs such as phone numbers removed; other words in the title are sent as they are; a bare domain such as "example.com" is not removed), not more. A title like "Jane Smith, 12 Oak St - dentist" is sent as written.
- **Shared result cache.** A result is stored per host, language, colour hash and page text (sha1 of the scrubbed title and description, ruling A13) for 7 days and is served to anyone who analyses the same page; another page of the same host gets its own analysis. The site list keeps only the domain and the brand name (the title column stays NULL, ruling A15), with counters only (no licence, user, email or IP), until deleted by the owner.
- **Analyse rate limits** (per licence 6/minute and 60/hour, per IP 20/hour, in `palette_rate`) and the monthly analyses limit are server-side. Server timeouts (ruling A17): one 40 s budget per analyse; at most 8 s per search, the model gets what is left (never under 5 s), no retry of an unparseable answer with under 15 s left, and an answer that arrives after 40 s is dropped (503, no analysis used). The extension's own timeout is 45 s.
- **Lost pending subscription checkout** is the ruling A10(c) item above; the monitoring for it is `PALETTE_PAID_UNFULFILLED`. The early `custom_id` branch and renewals on subscriptions without a live key log it too (final review findings 1 and 2).
- **Account page after a failed post-refund cancel.** A revoked Pro + AI key shows "Revoked" on `/palette/account` whatever its stored subscription status. While that status is still active or suspended (PayPal may bill), its signed-in owner gets a Cancel subscription button (confirm text and answer say it only stops billing; ruling A19); "Cancel by key" with key + email works too (`sub_cancel` accepts revoked keys).
- **Checkout page script ignores `ai_enabled`.** `config.ai_enabled` is returned by the API but the page decides from what the server rendered (plan cards, Coming soon), so a plan created while a checkout tab is open needs a reload. Plan cards can still be switched while a subscription confirmation is running and, after an approved subscription, a non-network confirm error does not lock the form (a second subscription is possible): deferred, final wave.
- **PayPal JS SDK reload** after switching plan (one-time versus subscription) is covered by stubs only. Check it in step 6c with the real SDK.
- **Refund and cancel wording hard-codes prices in two places.** `co_refund` (and `email_refund`) say $1.49 (French 1,49 $ US); Pro + AI strings do not name a price (they read it from settings). If `price_usd` changes, update those strings and all translations.
- **`setup.php` sends no frame-ancestors / X-Frame-Options** (admin-only page).
- **Extension.** The key input loses focus while an activation is in flight.
- **Key goes to any extension id in `?ext=`.** It is only format-checked; the key is the buyer's own. CORS on `verify` is granted only to ids listed in `extension_ids`, so a new Store id must be added before activation works.
- **Cosmetic.** es/fr store descriptions omit Figma; the fr refund line has a straight apostrophe (matches the server dictionary); the 6 translations still need native-speaker review (step 7).
