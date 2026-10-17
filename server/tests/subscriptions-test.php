<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/pp-stub.php';
ini_set('error_log', sys_get_temp_dir() . '/palette-test.log');
// A non-UTC zone, so PayPal's Z times really are converted to server-local DATETIMEs.
date_default_timezone_set('Asia/Ho_Chi_Minh');
// CLI has no session; start one once (output buffered) so companies' CSRF helpers use $_SESSION as-is.
ob_start();
session_start();

const SUB = 'I-SUBA1B2C3D4';
const HDRS = ['paypal-auth-algo' => 'SHA256withRSA', 'paypal-cert-url' => 'https://api.paypal.com/cert.pem',
              'paypal-transmission-id' => 'tid-1', 'paypal-transmission-sig' => 'sig==', 'paypal-transmission-time' => '2026-10-16T00:00:00Z'];

function iso(int $t): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $t);
}

function local(int $t): string
{
    return date('Y-m-d H:i:s', $t);
}

function s_reset(mysqli $c): void
{
    stub_reset($c);
    foreach (['palette_subscriptions', 'palette_sub_payments', 'palette_ai_usage', 'palette_rate'] as $t) $c->query("DELETE FROM $t");
    palette_settings_put($c, ['ai_paypal_plan_id' => 'P-AI', 'ai_paypal_plan_mode' => 'sandbox', 'ai_paypal_plan_price' => '3.99', 'webhook_id' => 'WH-PALETTE', 'webhook_mode' => 'sandbox']);
    $GLOBALS['pp_routes']['POST /v1/notifications/verify-webhook-signature'] = ['status' => 200, 'json' => ['verification_status' => 'SUCCESS']];
    $GLOBALS["mails"] = [];
    $_SESSION = [];
}

// PayPal's subscription JSON.
function pp_sub(int $rowId, string $status = 'ACTIVE', ?int $next = null, array $over = []): array
{
    $next ??= time() + 30 * 86400;
    return array_replace_recursive(['id' => SUB, 'status' => $status, 'plan_id' => 'P-AI', 'custom_id' => palette_sub_custom_id($GLOBALS['conn'], $rowId),
        'subscriber' => ['email_address' => 'payer@paypal.test'],
        'billing_info' => ['next_billing_time' => iso($next), 'last_payment' => ['amount' => ['value' => '3.99', 'currency_code' => 'USD'], 'time' => iso(time() - 60)]]], $over);
}

function route_sub(array|callable $json, int $status = 200, string $id = SUB): void
{
    $GLOBALS['pp_routes']['GET /v1/billing/subscriptions/' . $id] = is_callable($json) ? $json : ['status' => $status, 'json' => $json];
}

function start(mysqli $c, string $email = 'Buyer@Studio.vn', string $lang = 'fr'): int
{
    $r = palette_sub_start($c, $email, $lang, true);
    return row_of($r['custom_id']);
}

function row_of(string $customId): int
{
    return preg_match('/^palette-sub:(\d+):[0-9a-f]{16}$/', $customId, $m) ? (int)$m[1] : 0;
}

// Runs $fn with error_log going to a fresh file; returns what was logged.
function logged(callable $fn): string
{
    $log = sys_get_temp_dir() . '/palette-sub-alert-test.log';
    @unlink($log);
    ini_set('error_log', $log);
    try { $fn(); } finally { ini_set('error_log', sys_get_temp_dir() . '/palette-test.log'); }
    return (string)@file_get_contents($log);
}

function stored_secret(mysqli $c): ?string
{
    return $c->query("SELECT setting_value FROM admin_settings WHERE setting_key = 'palette_sub_secret'")->fetch_row()[0] ?? null;
}

function sub_row(mysqli $c, int $id): array
{
    return $c->query("SELECT * FROM palette_subscriptions WHERE id = $id")->fetch_assoc();
}

function ai_lic(mysqli $c): ?array
{
    return $c->query("SELECT * FROM palette_licenses WHERE plan = 'ai' ORDER BY id DESC LIMIT 1")->fetch_assoc() ?: null;
}

function payments(mysqli $c): int
{
    return (int)$c->query('SELECT COUNT(*) FROM palette_sub_payments')->fetch_row()[0];
}

function sale(string $saleId, int $created, string $total = '3.99', string $sub = SUB): array
{
    return ['id' => $saleId, 'billing_agreement_id' => $sub, 'amount' => ['total' => $total, 'currency' => 'USD'], 'state' => 'completed', 'create_time' => iso($created)];
}

function call(string $method, string $action, array $post = [], array $over = []): array
{
    return palette_api_handle($over + ['method' => $method, 'action' => $action, 'post' => $post,
        'origin' => '', 'ip' => '203.0.113.9', 'session' => $_SESSION]);
}

function active_sub(mysqli $c): array   // a fulfilled subscription: [row id, licence]
{
    $id = start($c);
    route_sub(pp_sub($id));
    palette_sub_confirm($c, SUB);
    return [$id, ai_lic($c)];
}

// ---- schema: indexes deferred from Task 1
$idx = fn(string $t, string $col) => (int)$conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '$t' AND COLUMN_NAME = '$col' AND SEQ_IN_INDEX = 1")->fetch_row()[0];
ok('index on palette_licenses.subscription_id', $idx('palette_licenses', 'subscription_id') >= 1);
ok('index on palette_subscriptions.license_id', $idx('palette_subscriptions', 'license_id') >= 1);
palette_migrate($conn);
ok('migrate twice: indexes not duplicated', $idx('palette_licenses', 'subscription_id') === 1 && $idx('palette_subscriptions', 'license_id') === 1);

// ---- start
s_reset($conn);
ok('start: bad email', palette_sub_start($conn, 'nope', 'en', true) === ['ok' => false, 'error' => 'err_email']);
ok('start: header injection email', palette_sub_start($conn, "a@b.co\nBcc: x@y.z", 'en', true) === ['ok' => false, 'error' => 'err_email']);
ok('start: no consent', palette_sub_start($conn, 'a@b.co', 'en', false) === ['ok' => false, 'error' => 'err_consent']);
palette_settings_put($conn, ['ai_paypal_plan_id' => ''] + palette_settings($conn));
ok('start: no plan id -> err_paused', palette_sub_start($conn, 'a@b.co', 'en', true) === ['ok' => false, 'error' => 'err_paused']);
palette_settings_put($conn, ['ai_paypal_plan_id' => 'P-AI', 'ai_paypal_plan_mode' => 'live'] + palette_settings($conn));
ok('start: plan made in another mode -> err_paused', palette_sub_start($conn, 'a@b.co', 'en', true) === ['ok' => false, 'error' => 'err_paused']);
palette_settings_put($conn, ['ai_paypal_plan_mode' => 'sandbox'] + palette_settings($conn));
stub_paypal($conn, false);
ok('start: PayPal paused -> err_paused', palette_sub_start($conn, 'a@b.co', 'en', true) === ['ok' => false, 'error' => 'err_paused']);
stub_paypal($conn, true);
palette_settings_put($conn, ['ai_price_usd' => '4.99'] + palette_settings($conn));
ok('A18: price changed but setup not re-run -> not ready, err_paused', palette_ai_plan_ready(palette_settings($conn), pp_settings($conn)) === false
    && palette_sub_start($conn, 'a@b.co', 'en', true) === ['ok' => false, 'error' => 'err_paused']);
palette_settings_put($conn, ['ai_price_usd' => '3.990'] + palette_settings($conn));
ok('A18: the same price written differently still matches', palette_ai_plan_ready(palette_settings($conn), pp_settings($conn)) === true);
palette_settings_put($conn, ['ai_paypal_plan_price' => ''] + palette_settings($conn));
ok('A18: a plan without a stored price (setup from before A18) -> not ready', palette_ai_plan_ready(palette_settings($conn), pp_settings($conn)) === false);
palette_settings_put($conn, ['ai_price_usd' => '3.99', 'ai_paypal_plan_price' => '3.99'] + palette_settings($conn));
ok('start: nothing stored on errors', (int)$conn->query('SELECT COUNT(*) FROM palette_subscriptions')->fetch_row()[0] === 0);
$r = palette_sub_start($conn, ' Buyer@Studio.vn ', 'fr', true);
$id = row_of((string)($r['custom_id'] ?? ''));
$row = sub_row($conn, $id);
ok('start ok: plan id + custom id palette-sub:<id>:<hmac16>', $r['ok'] === true && $r['plan_id'] === 'P-AI' && $id > 0
    && $r['custom_id'] === 'palette-sub:' . $id . ':' . substr(hash_hmac('sha256', (string)$id, stored_secret($conn)), 0, 16));
$secret = stored_secret($conn);
ok('A20: the HMAC secret lives in admin_settings palette_sub_secret (64 hex), not in the palette settings', preg_match('/^[0-9a-f]{64}$/', (string)$secret) === 1
    && palette_sub_custom_id($conn, $id) === $r['custom_id'] && !array_key_exists('sub_secret', palette_settings($conn))
    && !str_contains((string)getAdminSetting('palette'), (string)$secret));
palette_migrate($conn);
palette_migrate($conn);
ok('A20: migrate twice keeps the same secret', stored_secret($conn) === $secret);
// never generated lazily in a request: without the row, minting a custom_id fails and creates nothing
$conn->query("DELETE FROM admin_settings WHERE setting_key = 'palette_sub_secret'");
$threw = false;
try { palette_sub_custom_id($conn, $id); } catch (RuntimeException $e) { $threw = true; }
ok('A20: no secret row -> RuntimeException, nothing generated in the request', $threw && stored_secret($conn) === null);
// an older secret inside the 'palette' blob is carried over, so already-minted custom ids keep working
$conn->query("UPDATE admin_settings SET setting_value = JSON_SET(setting_value, '$.sub_secret', '" . str_repeat('cd', 32) . "') WHERE setting_key = 'palette'");
palette_migrate($conn);
ok('A20: migrate carries the blob sub_secret over', stored_secret($conn) === str_repeat('cd', 32));
$conn->query("DELETE FROM admin_settings WHERE setting_key = 'palette_sub_secret'");
$conn->query("UPDATE admin_settings SET setting_value = JSON_REMOVE(setting_value, '$.sub_secret') WHERE setting_key = 'palette'");
palette_migrate($conn);
ok('A20: migrate with no secret anywhere creates a fresh 64-hex one', preg_match('/^[0-9a-f]{64}$/', (string)stored_secret($conn)) === 1 && stored_secret($conn) !== str_repeat('cd', 32));
$conn->query("REPLACE INTO admin_settings (setting_key, setting_value) VALUES ('palette_sub_secret', '" . $secret . "')");
ok('start: pending row', $row['status'] === 'pending' && $row['email'] === 'buyer@studio.vn' && $row['lang'] === 'fr'
    && $row['plan_id'] === 'P-AI' && $row['subscription_id'] === null && $row['consent_at'] !== null && $row['price_usd'] === '3.99' && $GLOBALS['pp_calls'] === []);

// ---- API subscribe_start
s_reset($conn);
$good = ['email' => 'buyer@studio.vn', 'consent' => '1', 'lang' => 'es'];
ok('subscribe_start without csrf -> 419', call('POST', 'subscribe_start', $good)['status'] === 419);
ok('subscribe_start GET -> 405', call('GET', 'subscribe_start')['status'] === 405);
$tok = generateCSRFToken('palette_checkout');
$r = call('POST', 'subscribe_start', $good + ['csrf' => $tok]);
ok('subscribe_start ok', $r['status'] === 200 && $r['body']['ok'] === true && $r['body']['plan_id'] === 'P-AI'
    && preg_match('/^palette-sub:(\d+):[0-9a-f]{16}$/', $r['body']['custom_id'], $m) === 1);
ok('config never returns the subscription secret', !str_contains(json_encode(call('GET', 'config')), (string)stored_secret($conn)));
ok('A20: subscribe_start never returns the secret', !str_contains(json_encode($r), (string)stored_secret($conn)));
ok('subscribe_start remembers the row in the session', ($_SESSION['palette_subs'] ?? null) === [(int)$m[1]]);
ok('subscribe_start errors: consent 400', ($e = call('POST', 'subscribe_start', ['email' => 'a@b.co', 'csrf' => $tok]))['status'] === 400 && $e['body']['error'] === 'err_consent');
palette_settings_put($conn, ['ai_paypal_plan_id' => ''] + palette_settings($conn));
ok('subscribe_start paused 503', ($e = call('POST', 'subscribe_start', $good + ['csrf' => $tok]))['status'] === 503 && $e['body']['error'] === 'err_paused');
palette_settings_put($conn, ['ai_paypal_plan_id' => 'P-AI'] + palette_settings($conn));
$conn->query('DELETE FROM palette_rate');
for ($i = 0; $i < 10; $i++) call('POST', 'subscribe_start', $good + ['csrf' => $tok]);
ok('subscribe_start rate limited after 10/hour/IP', call('POST', 'subscribe_start', $good + ['csrf' => $tok])['status'] === 429);
ok('session keeps the last 20 rows', count($_SESSION['palette_subs']) === 11);
for ($i = 0; $i < 12; $i++) call('POST', 'subscribe_start', $good + ['csrf' => $tok], ['ip' => '198.51.100.' . $i]);
ok('session capped at 20', count($_SESSION['palette_subs']) === 20);

// ---- confirm: refusals
s_reset($conn);
$id = start($conn);
$GLOBALS['pp_calls'] = [];
ok('confirm: bad id format, no call', palette_sub_confirm($conn, 'I-abc')['ok'] === false && $GLOBALS['pp_calls'] === []
    && palette_sub_confirm($conn, '../x')['error'] === 'err_bad_request');
route_sub(pp_sub($id, 'ACTIVE', null, ['plan_id' => 'P-CHEAP']));
ok('confirm: wrong plan id -> no key', palette_sub_confirm($conn, SUB)['ok'] === false && license_count($conn) === 0);
route_sub(pp_sub($id, 'ACTIVE', null, ['custom_id' => 'palette:' . $id]));
ok('confirm: foreign custom_id -> no key', palette_sub_confirm($conn, SUB)['ok'] === false && license_count($conn) === 0);
route_sub(pp_sub($id + 999));
ok('confirm: custom_id for a missing row -> no key', palette_sub_confirm($conn, SUB)['ok'] === false && license_count($conn) === 0);
foreach (['palette-sub:' . $id => 'no hmac (guessable)', 'palette-sub:' . $id . ':0000000000000000' => 'wrong hmac',
          'palette-sub:' . $id . ':' . substr(palette_sub_custom_id($conn, $id + 1), -16) => 'another row\'s hmac'] as $cid => $why) {
    route_sub(pp_sub($id, 'ACTIVE', null, ['custom_id' => $cid]));
    ok("confirm: custom_id with $why -> no key", palette_sub_confirm($conn, SUB)['ok'] === false && license_count($conn) === 0);
}
route_sub(pp_sub($id, 'ACTIVE', null, ['billing_info' => ['last_payment' => ['amount' => ['value' => '0.99']]]]));
ok('confirm: paid less than the price -> no key', palette_sub_confirm($conn, SUB)['ok'] === false && license_count($conn) === 0);
route_sub(pp_sub($id, 'ACTIVE', null, ['billing_info' => ['last_payment' => ['amount' => ['currency_code' => 'EUR']]]]));
ok('confirm: wrong currency -> no key', palette_sub_confirm($conn, SUB)['ok'] === false && license_count($conn) === 0);
route_sub(pp_sub($id, 'APPROVAL_PENDING'));
$r = palette_sub_confirm($conn, SUB);
ok('confirm: APPROVAL_PENDING -> pending', $r['ok'] === true && !empty($r['pending']) && !isset($r['key']) && license_count($conn) === 0);
route_sub(pp_sub($id, 'APPROVED'));
ok('confirm: APPROVED -> pending', !empty(palette_sub_confirm($conn, SUB)['pending']) && license_count($conn) === 0);
route_sub(pp_sub($id, 'CANCELLED'));
ok('confirm: CANCELLED -> error', palette_sub_confirm($conn, SUB)['ok'] === false && license_count($conn) === 0);
route_sub([], 503);
$r = palette_sub_confirm($conn, SUB);
ok('confirm: PayPal 503 -> retry', $r['ok'] === false && !empty($r['retry']) && license_count($conn) === 0);
route_sub(['name' => 'RESOURCE_NOT_FOUND'], 404);
$r = palette_sub_confirm($conn, SUB);
ok('confirm: 404 -> error, no retry', $r['ok'] === false && empty($r['retry']));
ok('confirm: row still pending, no mail', sub_row($conn, $id)['status'] === 'pending' && $GLOBALS["mails"] === []);

// ---- confirm: ACTIVE fulfils once
$next = time() + 31 * 86400;
route_sub(pp_sub($id, 'ACTIVE', $next));
$before = time();
$r = palette_sub_confirm($conn, SUB);
$lic = ai_lic($conn);
ok('confirm ACTIVE: key returned', $r['ok'] === true && preg_match(PALETTE_KEY_RE, (string)$r['key']) === 1 && $r['key'] === $lic['license_key']
    && $r['email_masked'] === 'b***@studio.vn' && $r['row_id'] === $id);
ok('licence: plan ai, subscription, active', $lic['plan'] === 'ai' && $lic['subscription_id'] === SUB && $lic['sub_status'] === 'active'
    && $lic['email'] === 'buyer@studio.vn' && $lic['lang'] === 'fr' && $lic['revoked_at'] === null);
ok('licence: paid_through = next_billing_time (PHP local time)', $lic['paid_through'] === local($next));
ok('licence: period_start = now', abs(strtotime($lic['period_start']) - $before) <= 2);
$row = sub_row($conn, $id);
ok('row: active, bound to the subscription and licence', $row['status'] === 'active' && $row['subscription_id'] === SUB && (int)$row['license_id'] === (int)$lic['id']);
ok('one email to the buyer', count($GLOBALS["mails"]) === 1 && $GLOBALS["mails"][0]["to"] === ["buyer@studio.vn"]
    && $GLOBALS["mails"][0]['subject'] === palette_t('fr', 'email_sub_subject') && str_contains($GLOBALS["mails"][0]['html'], $r['key']));
$r2 = palette_sub_confirm($conn, SUB);
ok('confirm again: same key, already, still one licence + email', $r2['ok'] === true && $r2['key'] === $r['key'] && !empty($r2['already'])
    && license_count($conn) === 1 && count($GLOBALS["mails"]) === 1);
ok('verify: plan ai, ai_active', ($v = palette_verify($conn, $r['key'], '10.0.0.1'))['valid'] === true && $v['plan'] === 'ai' && $v['ai_active'] === true);
$h = $GLOBALS["mails"][0]['html'];
ok('email: manage link, refund line, Pro key line, includes', str_contains($h, 'https://vietnamcos.com/palette/account')
    && str_contains($h, palette_h(palette_t('fr', 'email_sub_refund'))) && str_contains($h, palette_h(palette_t('fr', 'email_sub_pro_key')))
    && str_contains($h, palette_h(palette_t('fr', 'email_sub_inc_ai', ['count' => 100]))));

// a racing fulfiller that read the row while it was still pending loses the claim
$stale = ['status' => 'pending', 'subscription_id' => null, 'license_id' => null] + sub_row($conn, $id);
$r3 = palette_sub_fulfil($conn, $stale, pp_sub($id, 'ACTIVE', $next));
ok('stale pending row: claim lost -> already, same key, no second licence or email', $r3['ok'] === true && !empty($r3['already'])
    && $r3['key'] === $r['key'] && license_count($conn) === 1 && count($GLOBALS["mails"]) === 1);

// a second ACTIVE subscription (different id) can't reuse a fulfilled row
route_sub(pp_sub($id, 'ACTIVE', null, ['id' => 'I-OTHER12345']), 200, 'I-OTHER12345');
ok('confirm: other subscription id on a bound row -> refused', palette_sub_confirm($conn, 'I-OTHER12345')['ok'] === false && license_count($conn) === 1);

// confirm then ACTIVATED webhook -> still one licence
ok('ACTIVATED after confirm -> already', palette_sub_event($conn, 'BILLING.SUBSCRIPTION.ACTIVATED', pp_sub($id, 'ACTIVE', $next)) === 'already'
    && license_count($conn) === 1 && count($GLOBALS["mails"]) === 1);

// ---- proof of payment (review Critical 1)
s_reset($conn);
$id = start($conn);
$unpaid = pp_sub($id);
unset($unpaid['billing_info']['last_payment']);
route_sub($unpaid);
$r = palette_sub_confirm($conn, SUB);
ok('ACTIVE without last_payment: confirm pending, no licence', $r['ok'] === true && !empty($r['pending']) && license_count($conn) === 0);
ok('ACTIVE without last_payment: ACTIVATED -> pending, no licence', palette_sub_event($conn, 'BILLING.SUBSCRIPTION.ACTIVATED', $unpaid) === 'pending'
    && license_count($conn) === 0 && sub_row($conn, $id)['status'] === 'pending' && $GLOBALS['mails'] === []);
ok('then SALE.COMPLETED at the full price fulfils (the sale is the proof)', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-P1', time() - 30)) === 'renewed'
    && license_count($conn) === 1 && payments($conn) === 1 && count($GLOBALS['mails']) === 1 && ai_lic($conn)['subscription_id'] === SUB);
s_reset($conn);
$id = start($conn);
$over = pp_sub($id, 'ACTIVE', null, ['plan_overridden' => true]);
route_sub($over);
ok('plan_overridden: confirm refused', palette_sub_confirm($conn, SUB)['ok'] === false && license_count($conn) === 0);
ok('plan_overridden: ACTIVATED ignored', palette_sub_event($conn, 'BILLING.SUBSCRIPTION.ACTIVATED', $over) === 'ignored' && license_count($conn) === 0);
$log = logged(fn() => ok('plan_overridden: even a full-price sale never fulfils', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-O1', time())) === 'ignored'
    && license_count($conn) === 0 && payments($conn) === 0));
ok('#2: plan_overridden sale on a palette checkout logs PALETTE_PAID_UNFULFILLED', str_contains($log, 'PALETTE_PAID_UNFULFILLED') && str_contains($log, 'SALE-O1'));
foreach (['wrong plan' => pp_sub($id, 'ACTIVE', null, ['plan_id' => 'P-CHEAP']), 'forged hmac' => pp_sub($id, 'ACTIVE', null, ['custom_id' => 'palette-sub:' . $id . ':ffffffffffffffff']),
          'missing row' => pp_sub($id + 500)] as $why => $s) {
    route_sub($s);
    $log = logged(fn() => $GLOBALS['ev'] = palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-M' . strlen($why), time())));
    ok("#2: sale whose subscription carries palette-sub: but $why -> ignored + PALETTE_PAID_UNFULFILLED", $GLOBALS['ev'] === 'ignored'
        && str_contains($log, 'PALETTE_PAID_UNFULFILLED') && license_count($conn) === 0);
}
route_sub(['name' => 'RESOURCE_NOT_FOUND'], 404);
$log = logged(fn() => $GLOBALS['ev'] = palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-404', time())));
ok('#2: sale whose subscription lookup fails for good -> ignored + PALETTE_PAID_UNFULFILLED', $GLOBALS['ev'] === 'ignored' && str_contains($log, 'PALETTE_PAID_UNFULFILLED'));
route_sub(pp_sub(0, 'ACTIVE', null, ['custom_id' => 'company:7']));
$log = logged(fn() => palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-CO', time(), '9.00')));
ok('#2: a companies subscription sale stays silent', !str_contains($log, 'PALETTE_PAID_UNFULFILLED'));
// a paid first sale reaching us after the buyer already stopped autopay still issues the key (re-review Important)
foreach (['CANCELLED' => 'cancelled', 'SUSPENDED' => 'suspended'] as $ppSt => $local) {
    s_reset($conn);
    $id = start($conn);
    $s = pp_sub($id, $ppSt, null, ['billing_info' => ['next_billing_time' => $ppSt === 'SUSPENDED' ? iso(time() - 86400) : null]]);
    unset($s['billing_info']['last_payment']);
    route_sub($s);
    $paidT = time() - 3600;
    $ev = palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-' . $ppSt, $paidT));
    $l = ai_lic($conn);
    ok("sale on a $ppSt pending row -> key issued", $ev === 'renewed' && license_count($conn) === 1 && count($GLOBALS['mails']) === 1 && payments($conn) === 1);
    ok("$ppSt: paid_through = sale time + 1 month, status $local", $l['paid_through'] === date('Y-m-d H:i:s', strtotime(local($paidT) . ' +1 month'))
        && $l['sub_status'] === $local && sub_row($conn, $id)['status'] === $local);
}
// a matching paid sale that still cannot be fulfilled raises the alert prefix
s_reset($conn);
$id = start($conn);
route_sub(pp_sub($id, 'EXPIRED'));
$log = sys_get_temp_dir() . '/palette-unfulfilled-test.log';
@unlink($log);
ini_set('error_log', $log);
ok('sale on an EXPIRED pending row -> ignored', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-E1', time())) === 'ignored' && license_count($conn) === 0);
palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-E2', time(), '1.00'));
$logged = (string)@file_get_contents($log);
ini_set('error_log', sys_get_temp_dir() . '/palette-test.log');
ok('unfulfillable and underpaid pending sales log PALETTE_PAID_UNFULFILLED', substr_count($logged, 'PALETTE_PAID_UNFULFILLED') === 2
    && str_contains($logged, 'SALE-E1') && str_contains($logged, 'SALE-E2'));

// a first sale while PayPal still shows APPROVED is redelivered, not dropped (review Important 3)
s_reset($conn);
$id = start($conn);
route_sub(pp_sub($id, 'APPROVED'));
ok('sale while APPROVED -> retry, nothing recorded', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-A1', time() - 30)) === 'retry'
    && payments($conn) === 0 && license_count($conn) === 0);
route_sub(pp_sub($id));
ok('redelivered once ACTIVE -> fulfilled', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-A1', time() - 30)) === 'renewed' && license_count($conn) === 1);
// the row keeps the price it was sold at (ruling A10)
palette_settings_put($conn, ['ai_price_usd' => '9.99', 'ai_paypal_plan_price' => '9.99'] + palette_settings($conn));
route_sub(pp_sub($id, 'ACTIVE', time() + 61 * 86400, ['billing_info' => ['last_payment' => ['amount' => ['value' => '3.99']]]]));
ok('price raised later: renewal at the old price still renews', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-A2', time() + 5)) === 'renewed'
    && ai_lic($conn)['paid_through'] === local(time() + 61 * 86400));
ok('below the row price -> ignored', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-A3', time() + 6, '3.98')) === 'ignored');
$id2 = start($conn);
ok('new checkouts store the new price', sub_row($conn, $id2)['price_usd'] === '9.99');
palette_settings_put($conn, ['ai_price_usd' => '9.990', 'ai_paypal_plan_price' => '9.99'] + palette_settings($conn));
ok('A18: the row price is the stored plan price', sub_row($conn, start($conn))['price_usd'] === '9.99');

// ---- API subscribe_confirm: key only to the creating session
s_reset($conn);
$tok = generateCSRFToken('palette_checkout');
$r = call('POST', 'subscribe_start', ['email' => 'buyer@studio.vn', 'consent' => '1', 'csrf' => $tok]);
$id = row_of($r['body']['custom_id']);
route_sub(pp_sub($id, 'APPROVED'));
$r = call('POST', 'subscribe_confirm', ['subscription_id' => SUB, 'csrf' => $tok]);
ok('API confirm pending -> 202 pending', $r['status'] === 202 && $r['body']['ok'] === true && $r['body']['pending'] === true && !isset($r['body']['key']));
ok('API confirm bad id -> 400', call('POST', 'subscribe_confirm', ['subscription_id' => 'x', 'csrf' => $tok])['status'] === 400);
ok('API confirm without csrf -> 419', call('POST', 'subscribe_confirm', ['subscription_id' => SUB])['status'] === 419);
route_sub([], 503);
ok('API confirm transient -> 503 err_retry', ($e = call('POST', 'subscribe_confirm', ['subscription_id' => SUB, 'csrf' => $tok]))['status'] === 503 && $e['body']['error'] === 'err_retry');
route_sub(pp_sub($id));
$mine = $_SESSION;
$_SESSION = ['csrf_tokens' => ['palette_checkout' => $tok]];   // a different session that knows the subscription id
$r = call('POST', 'subscribe_confirm', ['subscription_id' => SUB, 'csrf' => $tok]);
ok('API confirm from another session: key null, emailed message', $r['status'] === 200 && $r['body']['ok'] === true && $r['body']['key'] === null
    && $r['body']['message'] === strtr(palette_t('en', 'co_key_emailed'), ['{email}' => 'b***@studio.vn']));
$_SESSION = $mine;
$r = call('POST', 'subscribe_confirm', ['subscription_id' => SUB, 'csrf' => $tok]);
ok('API confirm from the creating session: key', $r['status'] === 200 && $r['body']['key'] === ai_lic($conn)['license_key'] && license_count($conn) === 1);

// ---- SALE.COMPLETED before ACTIVATED
s_reset($conn);
$id = start($conn);
$next = time() + 30 * 86400;
route_sub(pp_sub($id, 'ACTIVE', $next));
$ev = palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-1', time() - 120));
$lic = ai_lic($conn);
ok('sale before activation: fulfils', $ev === 'renewed' && license_count($conn) === 1 && $lic['paid_through'] === local($next) && sub_row($conn, $id)['status'] === 'active');
ok('sale before activation: payment recorded, one email', payments($conn) === 1 && count($GLOBALS["mails"]) === 1);
$pay = $conn->query("SELECT * FROM palette_sub_payments WHERE sale_id = 'SALE-1'")->fetch_assoc();
ok('payment row', $pay['subscription_id'] === SUB && $pay['amount_usd'] === '3.99' && $pay['status'] === 'completed' && $pay['paid_at'] === local(time() - 120));
ok('then ACTIVATED -> already, still one licence', palette_sub_event($conn, 'BILLING.SUBSCRIPTION.ACTIVATED', pp_sub($id, 'ACTIVE', $next)) === 'already'
    && license_count($conn) === 1 && count($GLOBALS["mails"]) === 1);
$ps = ai_lic($conn)['period_start'];
ok('first sale earlier than fulfilment: period_start unchanged', $ps === $lic['period_start']);

// duplicate SALE.COMPLETED
$snap = ai_lic($conn);
$GLOBALS['pp_calls'] = [];
ok('duplicate sale -> already', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-1', time() - 120)) === 'already');
ok('duplicate sale: no change, no PayPal call', ai_lic($conn) === $snap && payments($conn) === 1 && $GLOBALS['pp_calls'] === []);

// ---- ACTIVATED webhook fulfils a pending row
s_reset($conn);
$id = start($conn);
$GLOBALS['pp_calls'] = [];
ok('ACTIVATED fulfils', palette_sub_event($conn, 'BILLING.SUBSCRIPTION.ACTIVATED', pp_sub($id)) === 'activated' && license_count($conn) === 1
    && count($GLOBALS["mails"]) === 1);
ok('ACTIVATED twice -> already', palette_sub_event($conn, 'BILLING.SUBSCRIPTION.ACTIVATED', pp_sub($id)) === 'already' && license_count($conn) === 1);
s_reset($conn);
$id = start($conn);
ok('ACTIVATED with a wrong plan -> ignored', palette_sub_event($conn, 'BILLING.SUBSCRIPTION.ACTIVATED', pp_sub($id, 'ACTIVE', null, ['plan_id' => 'P-X'])) === 'ignored' && license_count($conn) === 0);
ok('companies subscription (other custom_id) -> ignored', palette_sub_event($conn, 'BILLING.SUBSCRIPTION.ACTIVATED', pp_sub($id, 'ACTIVE', null, ['custom_id' => 'company:7'])) === 'ignored'
    && palette_sub_event($conn, 'BILLING.SUBSCRIPTION.CANCELLED', ['id' => 'I-COMPANY0001', 'status' => 'CANCELLED']) === 'ignored' && license_count($conn) === 0);
ok('unknown sale (no billing agreement) -> ignored', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', ['id' => 'S-X', 'amount' => ['total' => '9.00', 'currency' => 'USD']]) === 'ignored');
route_sub(pp_sub(0, 'ACTIVE', null, ['custom_id' => 'company:7']), 200, 'I-COMPANY0001');
ok('companies subscription sale -> ignored', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('S-C', time(), '9.00', 'I-COMPANY0001')) === 'ignored' && payments($conn) === 0);

// ---- renewals
s_reset($conn);
[$id, $lic] = active_sub($conn);
$settings = palette_settings($conn);
palette_usage_reserve($conn, $lic, $settings);
palette_usage_reserve($conn, $lic, $settings);
ok('before renewal: 2 used', palette_usage_get($conn, ai_lic($conn), $settings)['used'] === 2);
// the first sale delivered just after confirm fulfilled: paid_through may move, the usage period must not (MIN_GAP)
$ps0 = ai_lic($conn)['period_start'];
route_sub(pp_sub($id, 'ACTIVE', time() + 31 * 86400));
ok('MIN_GAP: a sale within a day of the period start -> renewed, period_start unchanged, usage kept', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-F', time() + 60)) === 'renewed'
    && ai_lic($conn)['period_start'] === $ps0 && palette_usage_get($conn, ai_lic($conn), $settings)['used'] === 2 && ai_lic($conn)['paid_through'] === local(time() + 31 * 86400));
// a month on, the real renewal
$conn->query("UPDATE palette_licenses SET period_start = '" . local(time() - 31 * 86400) . "' WHERE id = " . (int)$lic['id']);
$conn->query("UPDATE palette_ai_usage SET period_start = '" . local(time() - 31 * 86400) . "' WHERE license_id = " . (int)$lic['id']);
$paidAt = time() + 5;
$next2 = time() + 61 * 86400;
route_sub(pp_sub($id, 'ACTIVE', $next2));
ok('renewal -> renewed', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-2', $paidAt)) === 'renewed');
$l2 = ai_lic($conn);
ok('renewal: paid_through moves to the new next_billing_time', $l2['paid_through'] === local($next2));
ok('renewal: period_start = sale time, usage resets to 0', $l2['period_start'] === local($paidAt) && palette_usage_get($conn, $l2, $settings)['used'] === 0);
ok('renewal: GET subscription called', count(calls_to('GET', '/v1/billing/subscriptions/' . SUB)) >= 1);
// an older sale delivered late: never moves paid_through or the period backwards
route_sub(pp_sub($id, 'ACTIVE', time() + 10 * 86400));
ok('late older sale -> renewed (recorded)', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-OLD', time() - 86400)) === 'renewed' && payments($conn) === 3);
$l3 = ai_lic($conn);
ok('paid_through never moves backwards', $l3['paid_through'] === local($next2) && $l3['period_start'] === local($paidAt));
// amount below the price
ok('sale below the price -> ignored, not recorded', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-LOW', time() + 9, '1.00')) === 'ignored' && payments($conn) === 3);
ok('sale in another currency -> ignored', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', ['amount' => ['total' => '3.99', 'currency' => 'EUR']] + sale('SALE-EUR', time() + 9)) === 'ignored' && payments($conn) === 3);
// transient GET -> retry, nothing recorded (redelivery will work)
route_sub([], 503);
ok('renewal with PayPal down -> retry', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-3', time() + 10)) === 'retry' && payments($conn) === 3);
ok('webhook maps retry to 503', palette_webhook_response($conn, HDRS, json_encode(['id' => 'WH-1', 'event_type' => 'PAYMENT.SALE.COMPLETED',
    'resource' => sale('SALE-3', time() + 10)])) === [503, 'retry']);
route_sub(pp_sub($id, 'ACTIVE', time() + 92 * 86400));
ok('redelivery after the outage renews', palette_webhook_response($conn, HDRS, json_encode(['id' => 'WH-1', 'event_type' => 'PAYMENT.SALE.COMPLETED',
    'resource' => sale('SALE-3', time() + 10)])) === [200, 'renewed'] && payments($conn) === 4);

// ---- ruling A20: renewal-ignore paths on a bound row alert; the binding, not the HMAC, proves a bound renewal
s_reset($conn);
[$id, $lic] = active_sub($conn);
route_sub(pp_sub($id, 'ACTIVE', time() + 40 * 86400));
$log = logged(fn() => palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-A20-LOW', time() + 5, '1.00')));
ok('A20: bound renewal paid below the row price -> PALETTE_PAID_UNFULFILLED', str_contains($log, 'PALETTE_PAID_UNFULFILLED sale SALE-A20-LOW for ' . SUB) && str_contains($log, 'below the price'));
route_sub([], 404);
$log = logged(fn() => palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-A20-404', time() + 6)));
ok('A20: bound renewal, non-transient lookup failure -> PALETTE_PAID_UNFULFILLED', str_contains($log, 'PALETTE_PAID_UNFULFILLED sale SALE-A20-404 for ' . SUB) && str_contains($log, 'lookup failed'));
route_sub(pp_sub($id, 'ACTIVE', null, ['plan_id' => 'P-OTHER']));
$log = logged(fn() => palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-A20-PLAN', time() + 7)));
ok('A20: bound renewal that no longer matches (other plan) -> ignored + PALETTE_PAID_UNFULFILLED', str_contains($log, 'PALETTE_PAID_UNFULFILLED sale SALE-A20-PLAN for ' . SUB)
    && str_contains($log, 'no longer matches') && payments($conn) === 0);
route_sub(pp_sub($id, 'ACTIVE', null, ['plan_overridden' => true]));
$log = logged(fn() => palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-A20-OVR', time() + 8)));
ok('A20: bound renewal with plan_overridden -> PALETTE_PAID_UNFULFILLED', str_contains($log, 'PALETTE_PAID_UNFULFILLED sale SALE-A20-OVR for ' . SUB) && payments($conn) === 0);
// the secret changes (restore from backup, manual edit): a renewal on the bound row still renews; its custom_id no longer verifies
$oldCid = palette_sub_custom_id($conn, $id);
$keptSecret = stored_secret($conn);
$conn->query("UPDATE admin_settings SET setting_value = '" . bin2hex(random_bytes(32)) . "' WHERE setting_key = 'palette_sub_secret'");
ok('A20 setup: the old custom_id no longer verifies', palette_sub_custom_row($conn, ['custom_id' => $oldCid]) === false);
$conn->query("UPDATE palette_licenses SET period_start = '" . local(time() - 31 * 86400) . "' WHERE id = " . (int)$lic['id']);
route_sub(pp_sub($id, 'ACTIVE', time() + 45 * 86400, ['custom_id' => $oldCid]));
ok('A20: bound renewal after a secret change -> renewed', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-A20-SEC', time() + 9)) === 'renewed'
    && ai_lic($conn)['paid_through'] === local(time() + 45 * 86400));
route_sub(pp_sub($id, 'ACTIVE', null, ['custom_id' => $oldCid, 'plan_id' => 'P-OTHER']));
ok('A20: the binding skips only the HMAC: a bound renewal on another plan is still refused', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-A20-SEC2', time() + 10)) === 'ignored');
// an unbound subscription id presenting the old custom_id is still refused (no binding, bad HMAC)
route_sub(pp_sub($id, 'ACTIVE', null, ['id' => 'I-OTHERSUB0001', 'custom_id' => $oldCid]), 200, 'I-OTHERSUB0001');
ok('A20: an unbound subscription with a stale HMAC matches nothing', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-A20-UNB', time() + 11, '3.99', 'I-OTHERSUB0001')) === 'ignored');
ok('A20: a status event on the bound subscription after a secret change still applies', palette_sub_event($conn, 'BILLING.SUBSCRIPTION.SUSPENDED', pp_sub($id, 'SUSPENDED', null, ['custom_id' => $oldCid])) === 'suspended'
    && sub_row($conn, $id)['status'] === 'suspended');
$conn->query("UPDATE admin_settings SET setting_value = '$keptSecret' WHERE setting_key = 'palette_sub_secret'");

// ---- status events
s_reset($conn);
[$id, $lic] = active_sub($conn);
$res = fn(string $st) => pp_sub($id, $st);
ok('SUSPENDED -> suspended', palette_sub_event($conn, 'BILLING.SUBSCRIPTION.SUSPENDED', $res('SUSPENDED')) === 'suspended'
    && ai_lic($conn)['sub_status'] === 'suspended' && sub_row($conn, $id)['status'] === 'suspended');
ok('suspended keeps access until paid_through', palette_verify($conn, $lic['license_key'], '10.0.0.2')['ai_active'] === true && ai_lic($conn)['paid_through'] === $lic['paid_through']);
ok('CANCELLED -> cancelled', palette_sub_event($conn, 'BILLING.SUBSCRIPTION.CANCELLED', $res('CANCELLED')) === 'cancelled' && ai_lic($conn)['sub_status'] === 'cancelled');
ok('cancelled keeps access', palette_verify($conn, $lic['license_key'], '10.0.0.3')['ai_active'] === true);
ok('CANCELLED again -> already', palette_sub_event($conn, 'BILLING.SUBSCRIPTION.CANCELLED', $res('CANCELLED')) === 'already');
ok('late PAYMENT.FAILED after cancel: stays cancelled', palette_sub_event($conn, 'BILLING.SUBSCRIPTION.PAYMENT.FAILED', $res('CANCELLED')) === 'already'
    && ai_lic($conn)['sub_status'] === 'cancelled');
ok('EXPIRED -> expired', palette_sub_event($conn, 'BILLING.SUBSCRIPTION.EXPIRED', $res('EXPIRED')) === 'expired' && ai_lic($conn)['sub_status'] === 'expired'
    && sub_row($conn, $id)['status'] === 'expired');
ok('licence never deleted', license_count($conn) === 1);
s_reset($conn);
[$id, $lic] = active_sub($conn);
ok('PAYMENT.FAILED -> suspended', palette_sub_event($conn, 'BILLING.SUBSCRIPTION.PAYMENT.FAILED', pp_sub($id, 'ACTIVE')) === 'suspended' && ai_lic($conn)['sub_status'] === 'suspended');
route_sub(pp_sub($id, 'ACTIVE', time() + 62 * 86400));
palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-R', time() + 5));
ok('payment after suspension reactivates', ai_lic($conn)['sub_status'] === 'active' && sub_row($conn, $id)['status'] === 'active');
ok('custom_id mismatch with the bound row -> ignored', palette_sub_event($conn, 'BILLING.SUBSCRIPTION.CANCELLED', pp_sub($id + 1, 'CANCELLED')) === 'ignored'
    && ai_lic($conn)['sub_status'] === 'active');
// renewal lag (ruling A16): an active subscription keeps AI for 48 h past paid_through
$conn->query("UPDATE palette_licenses SET paid_through = '" . local(time() - 60) . "'");
$v = palette_verify($conn, $lic['license_key'], '10.0.0.4');
ok('A16: active, just past paid_through: verify still valid + ai_active', $v['valid'] === true && $v['ai_active'] === true && $v['sub_status'] === 'active');
$conn->query("UPDATE palette_licenses SET sub_status = 'cancelled'");
$v = palette_verify($conn, $lic['license_key'], '10.0.0.4');
ok('A16: cancelled, just past paid_through: no grace', $v['valid'] === false && $v['ai_active'] === false);
$conn->query("UPDATE palette_licenses SET sub_status = 'active', paid_through = '" . local(time() - 49 * 3600) . "'");
// lapsed: verify keeps plan ai with ai_active false (ruling A3)
$v = palette_verify($conn, $lic['license_key'], '10.0.0.4');
ok('after paid_through + 48 h: plan ai, ai_active false, not revoked', $v['plan'] === 'ai' && $v['ai_active'] === false && $v['revoked'] === false);

// ---- refunds and reversals
s_reset($conn);
route_sub(pp_sub(start($conn)));
palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-1', time() - 30));
$lic = ai_lic($conn);
ok('refund of a palette sale -> revoked', palette_sub_event($conn, 'PAYMENT.SALE.REFUNDED', ['id' => 'REF-1', 'sale_id' => 'SALE-1', 'amount' => ['total' => '3.99', 'currency' => 'USD']]) === 'revoked');
ok('refund: licence revoked, verify says revoked', ai_lic($conn)['revoked_at'] !== null && palette_verify($conn, $lic['license_key'], '10.0.0.5')['revoked'] === true);
ok('refund: payment marked refunded', $conn->query("SELECT status FROM palette_sub_payments WHERE sale_id = 'SALE-1'")->fetch_row()[0] === 'refunded');
$GLOBALS['pp_routes']['GET /v1/payments/sale/SALE-COMPANY'] = ['status' => 200, 'json' => ['id' => 'SALE-COMPANY', 'state' => 'refunded']];   // a companies sale: no billing agreement
ok('refund of an unknown sale -> ignored', palette_sub_event($conn, 'PAYMENT.SALE.REFUNDED', ['id' => 'REF-9', 'sale_id' => 'SALE-COMPANY']) === 'ignored');
s_reset($conn);
[$id, $lic] = active_sub($conn);
ok('reversal of an unrecorded sale via billing_agreement_id -> revoked', palette_sub_event($conn, 'PAYMENT.SALE.REVERSED',
    ['id' => 'SALE-MISSED', 'billing_agreement_id' => SUB, 'state' => 'reversed']) === 'revoked' && ai_lic($conn)['revoked_at'] !== null);
s_reset($conn);
[$id, $lic] = active_sub($conn);
route_sub(pp_sub($id));
palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-9', time() - 30));
ok('reversal by sale id (resource id) -> revoked', palette_webhook_response($conn, HDRS, json_encode(['id' => 'WH-2', 'event_type' => 'PAYMENT.SALE.REVERSED',
    'resource' => ['id' => 'SALE-9', 'state' => 'reversed']])) === [200, 'revoked'] && ai_lic($conn)['revoked_at'] !== null
    && $conn->query("SELECT status FROM palette_sub_payments WHERE sale_id = 'SALE-9'")->fetch_row()[0] === 'reversed');
$ptBefore = ai_lic($conn)['paid_through'];
route_sub(pp_sub($id, 'ACTIVE', time() + 62 * 86400));
$log = logged(fn() => $GLOBALS['ev'] = palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-10', time() + 5)));
ok('#1: a renewal on a revoked licence (refund cancel failed) -> unfulfilled, not extended, still revoked', $GLOBALS['ev'] === 'unfulfilled'
    && ai_lic($conn)['revoked_at'] !== null && ai_lic($conn)['paid_through'] === $ptBefore);
ok('#1: it alerts (PALETTE_PAID_UNFULFILLED) and retries the PayPal cancel (PALETTE_CANCEL_FAILED when it fails again)',
    str_contains($log, 'PALETTE_PAID_UNFULFILLED') && str_contains($log, 'SALE-10') && str_contains($log, 'PALETTE_CANCEL_FAILED subscription=' . SUB)
    && count(calls_to('POST', '/v1/billing/subscriptions/' . SUB . '/cancel')) >= 1);
ok('#1: the payment is recorded, so a redelivery answers already', payments($conn) === 2
    && palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-10', time() + 5)) === 'already');
$GLOBALS['pp_routes']['POST /v1/billing/subscriptions/' . SUB . '/cancel'] = ['status' => 204, 'json' => null];
$GLOBALS['pp_calls'] = [];
$log = logged(fn() => $GLOBALS['ev'] = palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-11', time() + 6)));
ok('#1: when the retried cancel works the row is cancelled', $GLOBALS['ev'] === 'unfulfilled' && sub_row($conn, $id)['status'] === 'cancelled'
    && count(calls_to('POST', '/v1/billing/subscriptions/' . SUB . '/cancel')) === 1 && !str_contains($log, 'PALETTE_CANCEL_FAILED'));
// a refund also cancels the PayPal subscription (ruling A10), and a sale we never recorded is resolved by GET
s_reset($conn);
[$id, $lic] = active_sub($conn);
$GLOBALS['pp_routes']['GET /v1/payments/sale/SALE-X'] = ['status' => 200, 'json' => ['id' => 'SALE-X', 'billing_agreement_id' => SUB, 'state' => 'refunded']];
$GLOBALS['pp_routes']['POST /v1/billing/subscriptions/' . SUB . '/cancel'] = ['status' => 204, 'json' => null];
ok('refund of an unrecorded sale (sale_id only) -> GET sale -> revoked', palette_sub_event($conn, 'PAYMENT.SALE.REFUNDED', ['id' => 'REF-X', 'sale_id' => 'SALE-X']) === 'revoked'
    && ai_lic($conn)['revoked_at'] !== null && count(calls_to('GET', '/v1/payments/sale/SALE-X')) === 1);
$cc = calls_to('POST', '/v1/billing/subscriptions/' . SUB . '/cancel');
ok('refund cancels the PayPal subscription', count($cc) === 1 && $cc[0]['body'] === ['reason' => 'Payment refunded or reversed']
    && sub_row($conn, $id)['status'] === 'cancelled' && ai_lic($conn)['sub_status'] === 'cancelled');
s_reset($conn);
[$id, $lic] = active_sub($conn);
$GLOBALS['pp_routes']['GET /v1/payments/sale/SALE-Y'] = ['status' => 503, 'json' => []];
ok('sale lookup outage -> retry, not revoked yet', palette_sub_event($conn, 'PAYMENT.SALE.REFUNDED', ['id' => 'REF-Y', 'sale_id' => 'SALE-Y']) === 'retry'
    && ai_lic($conn)['revoked_at'] === null);
$GLOBALS['pp_routes']['POST /v1/billing/subscriptions/' . SUB . '/cancel'] = ['status' => 500, 'json' => []];
$log = sys_get_temp_dir() . '/palette-cancel-failed-test.log';
@unlink($log);
ini_set('error_log', $log);
ok('PayPal cancel failing never blocks the revoke', palette_sub_event($conn, 'PAYMENT.SALE.REVERSED', ['id' => 'SALE-Z', 'billing_agreement_id' => SUB]) === 'revoked'
    && ai_lic($conn)['revoked_at'] !== null && sub_row($conn, $id)['status'] === 'active');
ini_set('error_log', sys_get_temp_dir() . '/palette-test.log');
ok('failed cancel logs PALETTE_CANCEL_FAILED', str_contains((string)@file_get_contents($log), 'PALETTE_CANCEL_FAILED subscription=' . SUB));

// a refund processed before the first sale is recorded: the later sale must not issue a key (re-review minor)
s_reset($conn);
$id = start($conn);
route_sub(pp_sub($id));
$GLOBALS['pp_routes']['POST /v1/billing/subscriptions/' . SUB . '/cancel'] = ['status' => 204, 'json' => null];
ok('refund before the sale -> checkout closed', palette_sub_event($conn, 'PAYMENT.SALE.REFUNDED',
    ['id' => 'REF-RB', 'sale_id' => 'SALE-RB', 'billing_agreement_id' => SUB, 'amount' => ['total' => '3.99', 'currency' => 'USD']]) === 'revoked');
$pr = $conn->query("SELECT * FROM palette_sub_payments WHERE sale_id = 'SALE-RB'")->fetch_assoc();
ok('refund before the sale: refunded payment recorded, row bound and cancelled, PayPal cancel called', $pr && $pr['status'] === 'refunded' && $pr['subscription_id'] === SUB
    && sub_row($conn, $id)['status'] === 'cancelled' && sub_row($conn, $id)['subscription_id'] === SUB
    && count(calls_to('POST', '/v1/billing/subscriptions/' . SUB . '/cancel')) === 1);
ok('then the redelivered sale -> already, no key', palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-RB', time() - 60)) === 'already' && license_count($conn) === 0);
ok('then ACTIVATED / confirm -> no key', palette_sub_event($conn, 'BILLING.SUBSCRIPTION.ACTIVATED', pp_sub($id)) === 'already'
    && palette_sub_confirm($conn, SUB)['ok'] === false && license_count($conn) === 0 && $GLOBALS['mails'] === []);
ok('webhook still ignores companies order events', palette_webhook($conn, HDRS, json_encode(['event_type' => 'PAYMENT.CAPTURE.COMPLETED',
    'resource' => ['id' => 'C1', 'custom_id' => 'company:1']])) === 'ignored');
// refund before the sale whose PayPal cancel failed: the next month's sale must alert and retry the cancel, not "renew" (#1)
s_reset($conn);
$id = start($conn);
route_sub(pp_sub($id));
$GLOBALS['pp_routes']['POST /v1/billing/subscriptions/' . SUB . '/cancel'] = ['status' => 500, 'json' => []];
logged(fn() => palette_sub_event($conn, 'PAYMENT.SALE.REFUNDED', ['id' => 'REF-RC', 'sale_id' => 'SALE-RC', 'billing_agreement_id' => SUB, 'amount' => ['total' => '3.99', 'currency' => 'USD']]));
ok('#1 setup: closed row without a licence', sub_row($conn, $id)['status'] === 'cancelled' && sub_row($conn, $id)['license_id'] === null);
$GLOBALS['pp_calls'] = [];
$log = logged(fn() => $GLOBALS['ev'] = palette_sub_event($conn, 'PAYMENT.SALE.COMPLETED', sale('SALE-RC2', time() + 30 * 86400)));
ok('#1: renewal on a closed row with no licence -> unfulfilled, no key, alert + cancel retried', $GLOBALS['ev'] === 'unfulfilled' && license_count($conn) === 0
    && str_contains($log, 'PALETTE_PAID_UNFULFILLED') && str_contains($log, 'PALETTE_CANCEL_FAILED subscription=' . SUB)
    && count(calls_to('POST', '/v1/billing/subscriptions/' . SUB . '/cancel')) === 1);

// ---- customer cancel
s_reset($conn);
[$id, $lic] = active_sub($conn);
$conn->query('UPDATE palette_licenses SET user_id = 42 WHERE id = ' . (int)$lic['id']);
$GLOBALS["mails"] = [];
$GLOBALS['pp_routes']['POST /v1/billing/subscriptions/' . SUB . '/cancel'] = ['status' => 204, 'json' => null];
ok('cancel by a stranger -> not owner, no PayPal call', palette_sub_cancel($conn, $lic['license_key'], 7, 'other@x.co') === ['ok' => false, 'error' => 'err_not_owner']
    && calls_to('POST', '/v1/billing/subscriptions/') === []);
ok('cancel with no identity -> not owner', palette_sub_cancel($conn, $lic['license_key'], null, null)['error'] === 'err_not_owner');
ok('cancel bad key -> invalid', palette_sub_cancel($conn, 'SDT-0000-0000-0000', 42, null) === ['ok' => false, 'error' => 'err_invalid_key']);
$r = palette_sub_cancel($conn, $lic['license_key'], 42, null);
$cc = calls_to('POST', '/v1/billing/subscriptions/' . SUB . '/cancel');
ok('cancel by the signed-in owner -> ok, ends_on = paid_through', $r === ['ok' => true, 'ends_on' => substr($lic['paid_through'], 0, 10), 'revoked' => false]);
ok('PayPal cancel called with the reason', count($cc) === 1 && $cc[0]['body'] === ['reason' => 'Cancelled by customer']);
ok('cancel: status cancelled, access kept', ai_lic($conn)['sub_status'] === 'cancelled' && sub_row($conn, $id)['status'] === 'cancelled'
    && ai_lic($conn)['paid_through'] === $lic['paid_through'] && ai_lic($conn)['revoked_at'] === null);
ok('cancel: confirmation email with the end date', count($GLOBALS["mails"]) === 1 && $GLOBALS["mails"][0]['subject'] === palette_t('fr', 'email_cancel_subject')
    && str_contains($GLOBALS["mails"][0]['html'], substr($lic['paid_through'], 0, 10)) && str_contains($GLOBALS["mails"][0]['html'], 'https://www.paypal.com/myaccount/autopay/'));
$GLOBALS['pp_calls'] = [];
ok('cancel again: ok, no second PayPal call or email', palette_sub_cancel($conn, $lic['license_key'], 42, null)['ok'] === true
    && $GLOBALS['pp_calls'] === [] && count($GLOBALS["mails"]) === 1);
s_reset($conn);
[$id, $lic] = active_sub($conn);
$GLOBALS['pp_routes']['POST /v1/billing/subscriptions/' . SUB . '/cancel'] = ['status' => 422, 'json' => ['details' => [['issue' => 'SUBSCRIPTION_STATUS_INVALID']]]];
ok('cancel by key + email (case-insensitive), PayPal 422 already cancelled -> ok', palette_sub_cancel($conn, $lic['license_key'], null, ' BUYER@studio.VN ')['ok'] === true
    && ai_lic($conn)['sub_status'] === 'cancelled');
s_reset($conn);
[$id, $lic] = active_sub($conn);
$GLOBALS['pp_routes']['POST /v1/billing/subscriptions/' . SUB . '/cancel'] = ['status' => 500, 'json' => []];
ok('cancel: PayPal failure -> err_paypal, status unchanged', palette_sub_cancel($conn, $lic['license_key'], null, 'buyer@studio.vn') === ['ok' => false, 'error' => 'err_paypal']
    && ai_lic($conn)['sub_status'] === 'active');
ok('cancel: a pro key -> invalid', palette_sub_cancel($conn, palette_issue_license($conn, 'buyer@studio.vn', 'ORD-1', 'en')['key'], null, 'buyer@studio.vn')['error'] === 'err_invalid_key');
// a refunded (revoked) key whose PayPal cancel failed can still stop the billing (#1)
s_reset($conn);
[$id, $lic] = active_sub($conn);
logged(fn() => palette_sub_event($conn, 'PAYMENT.SALE.REVERSED', ['id' => 'SALE-RV', 'billing_agreement_id' => SUB]));   // cancel unrouted -> fails
ok('#1 setup: revoked, row still active', ai_lic($conn)['revoked_at'] !== null && sub_row($conn, $id)['status'] === 'active');
$GLOBALS['pp_routes']['POST /v1/billing/subscriptions/' . SUB . '/cancel'] = ['status' => 204, 'json' => null];
$GLOBALS['pp_calls'] = [];
ok('#1: revoked key, stranger -> not owner, no PayPal call', palette_sub_cancel($conn, $lic['license_key'], null, 'x@y.co')['error'] === 'err_not_owner' && $GLOBALS['pp_calls'] === []);
$r = palette_sub_cancel($conn, $lic['license_key'], null, 'buyer@studio.vn');
ok('#1: revoked key, owner -> cancelled in PayPal and here', $r['ok'] === true && count(calls_to('POST', '/v1/billing/subscriptions/' . SUB . '/cancel')) === 1
    && sub_row($conn, $id)['status'] === 'cancelled' && ai_lic($conn)['revoked_at'] !== null);
ok('A19: cancel result flags a revoked key', ($r['revoked'] ?? null) === true);

// A19: the API answers a revoked key's cancel with the stop-billing message, not "stay on until"
s_reset($conn);
[$id, $lic] = active_sub($conn);
$conn->query("UPDATE palette_licenses SET revoked_at = NOW() WHERE id = " . (int)$lic['id']);
$GLOBALS['pp_routes']['POST /v1/billing/subscriptions/' . SUB . '/cancel'] = ['status' => 204, 'json' => null];
$_SESSION = [];
$r = call('POST', 'sub_cancel', ['key' => $lic['license_key'], 'email' => 'buyer@studio.vn', 'csrf' => generateCSRFToken('palette_account'), 'lang' => 'fr']);
ok('A19: API sub_cancel on a revoked key -> sub_cancel_ok_revoked', $r['status'] === 200 && $r['body']['message'] === palette_t('fr', 'sub_cancel_ok_revoked'));
$conn->query('DELETE FROM palette_rate');

// API sub_cancel: palette_account CSRF, 403 for strangers
s_reset($conn);
[$id, $lic] = active_sub($conn);
$GLOBALS['pp_routes']['POST /v1/billing/subscriptions/' . SUB . '/cancel'] = ['status' => 204, 'json' => null];
$_SESSION = [];
$ck = generateCSRFToken('palette_checkout');
ok('API sub_cancel with the checkout token -> 419', call('POST', 'sub_cancel', ['key' => $lic['license_key'], 'email' => 'buyer@studio.vn', 'csrf' => $ck])['status'] === 419);
$ak = generateCSRFToken('palette_account');
ok('API sub_cancel GET -> 405', call('GET', 'sub_cancel')['status'] === 405);
$r = call('POST', 'sub_cancel', ['key' => $lic['license_key'], 'email' => 'someone@else.co', 'csrf' => $ak], ['session' => ['user_id' => 9]]);
ok('API sub_cancel by a stranger -> 403', $r['status'] === 403 && $r['body']['error'] === 'err_not_owner' && ai_lic($conn)['sub_status'] === 'active');
$r = call('POST', 'sub_cancel', ['key' => $lic['license_key'], 'email' => 'buyer@studio.vn', 'csrf' => $ak, 'lang' => 'es']);
ok('API sub_cancel by key + email -> 200 ends_on + message', $r['status'] === 200 && $r['body']['ok'] === true && $r['body']['ends_on'] === substr($lic['paid_through'], 0, 10)
    && $r['body']['message'] === palette_t('es', 'sub_cancel_ok', ['date' => substr($lic['paid_through'], 0, 10)]));
ok('A19: cancel of a live key is not flagged revoked', palette_sub_cancel($conn, $lic['license_key'], null, 'buyer@studio.vn')['revoked'] === false);
ok('API sub_cancel bad key -> 400', call('POST', 'sub_cancel', ['key' => 'nope', 'csrf' => $ak])['status'] === 400);
$conn->query('DELETE FROM palette_rate');
for ($i = 0; $i < 10; $i++) call('POST', 'sub_cancel', ['key' => 'nope', 'csrf' => $ak]);
ok('API sub_cancel rate limited after 10/hour/IP', call('POST', 'sub_cancel', ['key' => 'nope', 'csrf' => $ak])['status'] === 429);
$tok = generateCSRFToken('palette_checkout');
for ($i = 0; $i < 30; $i++) call('POST', 'subscribe_confirm', ['subscription_id' => 'x', 'csrf' => $tok]);
ok('API subscribe_confirm rate limited after 30/hour/IP', call('POST', 'subscribe_confirm', ['subscription_id' => 'x', 'csrf' => $tok])['status'] === 429);

// ---- reconcile
s_reset($conn);
[$id, $lic] = active_sub($conn);
$settings = palette_settings($conn);
// a month passed without the renewal webhook: the licence still shows last month's period
$oldStart = time() - 31 * 86400;
$lid = (int)$lic['id'];
$setPeriod = fn(int $start, int $paid) => $conn->query("UPDATE palette_licenses SET period_start = '" . local($start) . "', paid_through = '" . local($paid) . "' WHERE id = $lid");
$setPeriod($oldStart, time() + 3600);
$lic = ai_lic($conn);
palette_usage_reserve($conn, $lic, $settings);
$next3 = time() + 30 * 86400;
$paid3 = time() - 60;
// last month's payment (within a day of the current period start) is no proof of a new one
route_sub(pp_sub($id, 'ACTIVE', $next3, ['billing_info' => ['last_payment' => ['time' => iso($oldStart + 3600)]]]));
ok('reconcile: last month\'s payment does not extend or reset', palette_sub_reconcile($conn)['changed'] === 0
    && ai_lic($conn)['paid_through'] === local(time() + 3600) && ai_lic($conn)['period_start'] === local($oldStart));
// PayPal retrying a failed renewal: still ACTIVE, an earlier payment, failed_payments_count 1
route_sub(pp_sub($id, 'ACTIVE', $next3, ['billing_info' => ['last_payment' => ['time' => iso($paid3)], 'failed_payments_count' => 1]]));
ok('reconcile: failed payments pending -> no extension', palette_sub_reconcile($conn)['changed'] === 0 && ai_lic($conn)['period_start'] === local($oldStart));
route_sub(pp_sub($id, 'ACTIVE', $next3, ['billing_info' => ['last_payment' => ['time' => iso($paid3)], 'failed_payments_count' => 0]]));
$r = palette_sub_reconcile($conn);
$l = ai_lic($conn);
ok('reconcile fixes a missed renewal', $r['checked'] === 1 && $r['changed'] === 1 && $l['paid_through'] === local($next3) && $l['period_start'] === local($paid3)
    && palette_usage_get($conn, $l, $settings)['used'] === 0);
ok('reconcile again: nothing changed', palette_sub_reconcile($conn) === ['checked' => 1, 'changed' => 0, 'failed' => 0]);
route_sub(pp_sub($id, 'SUSPENDED', time() + 90 * 86400, ['billing_info' => ['last_payment' => ['time' => iso(time() + 3 * 86400)]]]));
ok('reconcile syncs SUSPENDED without extending', palette_sub_reconcile($conn)['changed'] === 1 && ai_lic($conn)['sub_status'] === 'suspended'
    && sub_row($conn, $id)['status'] === 'suspended' && ai_lic($conn)['paid_through'] === local($next3));
$setPeriod($oldStart, time() + 3600);
route_sub(pp_sub($id, 'ACTIVE', time() + 90 * 86400, ['billing_info' => ['last_payment' => ['amount' => ['value' => '0.50'], 'time' => iso($paid3)]]]));
palette_sub_reconcile($conn);
ok('reconcile: underpaid last payment does not extend', ai_lic($conn)['paid_through'] === local(time() + 3600));
$setPeriod($paid3, $next3);
route_sub([], 503);
ok('reconcile: PayPal down -> failed counted, no change', palette_sub_reconcile($conn) === ['checked' => 1, 'changed' => 0, 'failed' => 1]);
route_sub(pp_sub($id, 'CANCELLED', null, ['billing_info' => ['next_billing_time' => null]]));
palette_sub_reconcile($conn);
ok('reconcile syncs CANCELLED', ai_lic($conn)['sub_status'] === 'cancelled' && ai_lic($conn)['paid_through'] === local($next3));
$conn->query("UPDATE palette_licenses SET paid_through = '" . local(time() - 60) . "'");
$GLOBALS['pp_calls'] = [];
ok('reconcile skips cancelled subscriptions past paid_through', palette_sub_reconcile($conn)['checked'] === 0 && $GLOBALS['pp_calls'] === []);
s_reset($conn);
[$id, $lic] = active_sub($conn);
$conn->query("UPDATE palette_licenses SET revoked_at = NOW()");
start($conn);   // pending rows are not reconciled either
$GLOBALS['pp_calls'] = [];
$conn->query("UPDATE palette_subscriptions SET status = 'cancelled' WHERE id = $id");
ok('reconcile skips revoked licences already cancelled, and pending rows', palette_sub_reconcile($conn)['checked'] === 0 && $GLOBALS['pp_calls'] === []);
// revoked but still billing (the refund's cancel failed): reconcile retries the cancel, never extends (#1)
$conn->query("UPDATE palette_subscriptions SET status = 'active' WHERE id = $id");
$ptRev = ai_lic($conn)['paid_through'];
route_sub(pp_sub($id, 'ACTIVE', time() + 90 * 86400, ['billing_info' => ['last_payment' => ['time' => iso(time() + 3 * 86400)]]]));
$GLOBALS['pp_routes']['POST /v1/billing/subscriptions/' . SUB . '/cancel'] = ['status' => 500, 'json' => []];
$log = logged(fn() => $GLOBALS['rc'] = palette_sub_reconcile($conn));
ok('#1: reconcile checks a revoked row still ACTIVE in PayPal and retries the cancel (logs PALETTE_CANCEL_FAILED)', $GLOBALS['rc']['checked'] === 1
    && count(calls_to('POST', '/v1/billing/subscriptions/' . SUB . '/cancel')) === 1 && str_contains($log, 'PALETTE_CANCEL_FAILED subscription=' . SUB)
    && ai_lic($conn)['paid_through'] === $ptRev && sub_row($conn, $id)['status'] === 'active');
$GLOBALS['pp_routes']['POST /v1/billing/subscriptions/' . SUB . '/cancel'] = ['status' => 204, 'json' => null];
palette_sub_reconcile($conn);
ok('#1: once the cancel works the row is cancelled and leaves the reconcile set', sub_row($conn, $id)['status'] === 'cancelled'
    && ai_lic($conn)['paid_through'] === $ptRev && palette_sub_reconcile($conn)['checked'] === 0);
$conn->query("UPDATE palette_subscriptions SET status = 'active' WHERE id = $id");
route_sub(pp_sub($id, 'CANCELLED'));
$GLOBALS['pp_calls'] = [];
palette_sub_reconcile($conn);
ok('#1: a revoked row PayPal already cancelled is synced without a cancel call', sub_row($conn, $id)['status'] === 'cancelled'
    && calls_to('POST', '/v1/billing/subscriptions/') === []);
// batch of 200, oldest updated first
s_reset($conn);
$t0 = local(time() - 86400);
$conn->query("INSERT INTO palette_licenses (license_key, email, order_id, lang, created_at, plan, subscription_id, sub_status, paid_through, period_start)
              VALUES ('SDT-AAAA-AAAA-AAAA', 'a@b.co', 'x', 'en', '$t0', 'ai', 'I-BATCH0000', 'active', '" . local(time() + 86400) . "', '$t0')");
$lid = (int)$conn->insert_id;
for ($i = 1; $i <= 205; $i++) {
    $u = local(time() - 1000 + $i);
    $conn->query("INSERT INTO palette_subscriptions (subscription_id, email, lang, status, plan_id, license_id, consent_at, created_at, updated_at)
                  VALUES ('I-BATCH" . sprintf('%04d', $i) . "', 'a@b.co', 'en', 'active', 'P-AI', $lid, '$t0', '$t0', '$u')");
}
$GLOBALS['pp_routes'] = [];
$GLOBALS['pp_calls'] = [];
$r = palette_sub_reconcile($conn);
$got = array_map(fn($x) => substr($x['path'], strlen('/v1/billing/subscriptions/')), calls_to('GET', '/v1/billing/subscriptions/'));
ok('reconcile: at most 200 per run, oldest updated first', $r['checked'] === 200 && count($got) === 200 && $got[0] === 'I-BATCH0001' && $got[199] === 'I-BATCH0200');
$GLOBALS['pp_calls'] = [];
palette_sub_reconcile($conn);
$got = array_map(fn($x) => substr($x['path'], strlen('/v1/billing/subscriptions/')), calls_to('GET', '/v1/billing/subscriptions/'));
ok('reconcile: next run starts with the ones not yet checked', array_slice($got, 0, 5) === ['I-BATCH0201', 'I-BATCH0202', 'I-BATCH0203', 'I-BATCH0204', 'I-BATCH0205']);

// ---- emails: 7 languages, no emoji, RTL for Arabic
$keys = ['email_sub_subject', 'email_sub_title', 'email_sub_intro', 'email_sub_includes', 'email_sub_inc_pro', 'email_sub_inc_ai', 'email_sub_manage',
         'email_sub_refund', 'email_sub_pro_key', 'email_cancel_subject', 'email_cancel_title', 'email_cancel_body', 'email_cancel_paypal',
         'err_not_owner', 'sub_cancel_ok'];
$bad = [];
foreach (PALETTE_LANGS as $lg) {
    $strs = palette_strings($lg);
    foreach ($keys as $k) {
        $v = $strs[$k] ?? '';
        if (!is_string($v) || trim($v) === '' || preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{25A0}-\x{25FF}\x{2B00}-\x{2BFF}]/u', $v)) $bad[] = "$lg:$k";
    }
    if ($lg !== 'en' && ($strs['email_sub_intro'] ?? '') === palette_strings('en')['email_sub_intro']) $bad[] = "$lg:untranslated";
}
ok('subscription strings present in 7 languages, translated, no emoji', $bad === []);
$m = palette_sub_email('SDT-AAAA-BBBB-CCCC', 'ar', 100);
ok('ar subscription email is RTL and keeps the key LTR', str_contains($m['html'], 'dir="rtl"') && str_contains($m['html'], 'dir="ltr"') && $m['subject'] === palette_t('ar', 'email_sub_subject'));
$m = palette_sub_cancel_email('2026-11-17', 'bn');
ok('cancel email carries the date and the PayPal backstop', str_contains($m['html'], '2026-11-17') && str_contains($m['html'], 'https://www.paypal.com/myaccount/autopay/'));

// ---- cron: CLI only
$src = (string)@file_get_contents(__DIR__ . '/../cron-subscriptions.php');
ok('cron runs the reconcile', str_contains($src, 'palette_sub_reconcile($conn)'));
$port = 18000 + mt_rand(0, 999);
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__)], [1 => ['file', sys_get_temp_dir() . '/palette-cron-srv.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/palette-cron-srv.log', 'a']], $pipes);
$code = 0;
for ($i = 0; $i < 50 && $code === 0; $i++) {
    usleep(100000);
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
    $body = @file_get_contents("http://127.0.0.1:$port/cron-subscriptions.php", false, $ctx);
    if (isset($http_response_header[0]) && preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0], $mm)) $code = (int)$mm[1];
}
proc_terminate($proc);
proc_close($proc);
ok('cron over HTTP -> 404', $code === 404 && $body === '');

s_reset($conn);
unset($GLOBALS['pp_http']);
$conn->query("DELETE FROM admin_settings WHERE setting_key IN ('palette', 'paypal', 'resend_api_key')");
