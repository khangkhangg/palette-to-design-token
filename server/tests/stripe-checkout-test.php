<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/st-stub.php';
ini_set('error_log', sys_get_temp_dir() . '/palette-test.log');
ob_start();
session_start();

function sc_reset(mysqli $c): void
{
    st_stub_reset($c);
    st_stub_connect($c, 'live');
    st_stub_connect($c, 'test');
    $c->query('DELETE FROM palette_rate');
    $_SESSION = [];
}

function sc_order(mysqli $c, string $mode = 'live'): array
{
    $GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_' . $mode . '_' . mt_rand(), 'url' => 'https://checkout.stripe.com/c/pay/x']];
    $r = palette_st_order_start($c, $mode, 'Buyer@Studio.vn', 'en', true);
    $row = palette_order_by($c, 'id', $r['row_id']);
    return [$r, $row];
}

function sc_session(array $row, array $over = []): array
{
    return $over + ['id' => $row['stripe_session'], 'payment_status' => 'paid', 'status' => 'complete', 'currency' => 'usd',
        'amount_total' => 149, 'payment_intent' => 'pi_123', 'client_reference_id' => 'palette:order:' . $row['id'],
        'metadata' => ['palette' => 'order', 'row' => (string)$row['id']], 'livemode' => (int)$row['livemode'] === 1];
}

function sc_count(mysqli $c, string $sql): int
{
    return (int)$c->query($sql)->fetch_row()[0];
}

// ---- start params
sc_reset($conn);
[$r, $row] = sc_order($conn);
$call = $GLOBALS['st_calls'][0] ?? [];
$raw = (string)($call['raw'] ?? '');
ok('start ok', $r['ok'] === true && $r['url'] === 'https://checkout.stripe.com/c/pay/x' && $r['row_id'] > 0);
ok('start params: mode=payment, cents', str_contains($raw, 'mode=payment') && str_contains($raw, 'unit_amount%5D=149') && str_contains($raw, 'currency%5D=usd'));
ok('start params: client_reference_id', str_contains($raw, 'client_reference_id=palette%3Aorder%3A' . $r['row_id']));
ok('start params: metadata + email + quantity', str_contains($raw, 'metadata%5Bpalette%5D=order') && str_contains($raw, 'metadata%5Brow%5D=' . $r['row_id'])
    && str_contains($raw, 'customer_email=buyer%40studio.vn') && str_contains($raw, 'quantity%5D=1'));
ok('start params: success url has the session template', str_contains(urldecode($raw), 'success_url=https://vietnamcos.com/palette/checkout?st={CHECKOUT_SESSION_ID}&lang=en')
    && str_contains(urldecode($raw), 'cancel_url=https://vietnamcos.com/palette/checkout?st=cancel&lang=en'));
ok('start params: both return URLs bring the buyer back on Pro', str_contains(urldecode($raw), 'st={CHECKOUT_SESSION_ID}&lang=en&plan=pro')
    && str_contains(urldecode($raw), 'st=cancel&lang=en&plan=pro'));
ok('start params: uses the live key', in_array('Authorization: Bearer sk_live_x', $call['headers'] ?? [], true));
ok('start row', $row['provider'] === 'stripe' && (int)$row['livemode'] === 1 && $row['status'] === 'created' && $row['amount_usd'] === '1.49'
    && preg_match('/^ST-[0-9A-F]{16}$/', $row['order_id']) === 1 && str_starts_with($row['stripe_session'], 'cs_live_') && $row['email'] === 'buyer@studio.vn');
ok('locale mapping', palette_st_locale('zh-CN') === 'zh' && palette_st_locale('hi') === 'auto' && palette_st_locale('bn') === 'auto' && palette_st_locale('fr') === 'fr');
$GLOBALS['st_calls'] = [];
$GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/y']];
palette_st_order_start($conn, 'test', 'a@b.vn', 'zh-CN', true);
$d = urldecode((string)$GLOBALS['st_calls'][0]['raw']);
ok('test mode: &test=1 on success url, test key, zh locale', str_contains($d, 'lang=zh-CN&plan=pro&test=1') && str_contains($d, 'locale=zh')
    && in_array('Authorization: Bearer sk_test_x', $GLOBALS['st_calls'][0]['headers'], true));

// ---- paused
sc_reset($conn);
$conn->query("DELETE FROM admin_settings WHERE setting_key = 'palette_stripe'");
st_stub_connect($conn, 'test');
$r = palette_st_order_start($conn, 'live', 'a@b.vn', 'en', true);
ok('start paused when live not ready', $r === ['ok' => false, 'error' => 'err_paused'] && !$GLOBALS['st_calls']
    && sc_count($conn, 'SELECT COUNT(*) FROM palette_orders') === 0);

// ---- validation
sc_reset($conn);
$a = palette_st_order_start($conn, 'live', 'nope', 'en', true);
$b = palette_st_order_start($conn, 'live', "a@b.vn\r\nBcc: x@y.z", 'en', true);
$c2 = palette_st_order_start($conn, 'live', 'a@b.vn', 'en', false);
ok('start validation: bad email, CRLF email, no consent', $a['error'] === 'err_email' && $b['error'] === 'err_email' && $c2['error'] === 'err_consent');
ok('start validation: no HTTP call, no row', !$GLOBALS['st_calls'] && sc_count($conn, 'SELECT COUNT(*) FROM palette_orders') === 0);

// ---- Stripe failure
sc_reset($conn);
$GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 0, 'json' => []];
$r = palette_st_order_start($conn, 'live', 'a@b.vn', 'en', true);
ok('start transport failure -> err_stripe, row failed', $r === ['ok' => false, 'error' => 'err_stripe']
    && sc_count($conn, "SELECT COUNT(*) FROM palette_orders WHERE status = 'failed'") === 1);
$GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 400, 'json' => ['error' => ['message' => 'bad']]];
ok('start Stripe 400 -> err_stripe', palette_st_order_start($conn, 'live', 'a@b.vn', 'en', true)['error'] === 'err_stripe');

// ---- checkout mode
ok('test mode admin only', palette_checkout_mode(['user_role' => 'user'], ['test' => '1']) === 'live'
    && palette_checkout_mode([], ['test' => '1']) === 'live'
    && palette_checkout_mode(['user_role' => 'admin'], ['test' => '1']) === 'test'
    && palette_checkout_mode(['user_role' => 'admin'], []) === 'live'
    && palette_checkout_mode(['user_role' => 'admin'], ['test' => '0']) === 'live');

// ---- confirm: paid issues the key and mails once
sc_reset($conn);
[$r, $row] = sc_order($conn);
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 200, 'json' => sc_session($row)];
$res = palette_st_confirm($conn, $row['stripe_session']);
ok('confirm paid: key + plan', $res['state'] === 'paid' && preg_match('/^[A-Z0-9-]{10,}$/', (string)$res['key']) === 1 && $res['plan'] === 'pro' && $res['row'] === (int)$row['id']);
$get = array_values(array_filter($GLOBALS['st_calls'], fn($x) => $x['method'] === 'GET'))[0];
ok('confirm GETs the session in the row mode, expanding subscription', in_array('Authorization: Bearer sk_live_x', $get['headers'], true)
    && str_contains(urldecode($get['url']), 'expand[0]=subscription'));
ok('confirm paid: one mail to the typed address', count($GLOBALS['mails']) === 1 && ($GLOBALS['mails'][0]['to'][0] ?? $GLOBALS['mails'][0]['to'] ?? '') === 'buyer@studio.vn');
$l = $conn->query('SELECT * FROM palette_licenses')->fetch_assoc();
$o2 = palette_order_by($conn, 'id', (int)$row['id']);
ok('licence: stripe source, livemode 1, pro, linked', $l['source'] === 'stripe' && (int)$l['livemode'] === 1 && $l['plan'] === 'pro'
    && $l['order_id'] === $row['order_id'] && (int)$o2['license_id'] === (int)$l['id'] && $o2['status'] === 'captured' && $o2['stripe_payment_intent'] === 'pi_123' && $o2['captured_at'] !== null);
$again = palette_st_confirm($conn, $row['stripe_session']);
ok('confirm again: same key, no second licence or mail', $again['state'] === 'paid' && $again['key'] === $res['key']
    && sc_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 1 && count($GLOBALS['mails']) === 1);

// ---- race: fulfil twice with the same snapshot
sc_reset($conn);
[$r, $row] = sc_order($conn);
$a = palette_st_fulfil_order($conn, $row, sc_session($row));
$b = palette_st_fulfil_order($conn, $row, sc_session($row));
ok('pro fulfil race: one licence, one mail, same key', $a['ok'] && $b['ok'] && $a['already'] === false && $b['already'] === true
    && $a['key'] !== null && $a['key'] === $b['key'] && sc_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 1 && count($GLOBALS['mails']) === 1);

// ---- loser before license_id is written: answers key null after the brief wait
sc_reset($conn);
[$r, $row] = sc_order($conn);
$conn->query("UPDATE palette_orders SET status = 'captured' WHERE id = " . (int)$row['id']);
$t0 = microtime(true);
$b = palette_st_fulfil_order($conn, $row, sc_session($row));
ok('loser without license_id: ok, already, key null, no licence', $b === ['ok' => true, 'key' => null, 'already' => true]
    && sc_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 0 && microtime(true) - $t0 < 2);

// ---- amount mismatch / wrong currency
sc_reset($conn);
[$r, $row] = sc_order($conn);
$f = palette_st_fulfil_order($conn, $row, sc_session($row, ['amount_total' => 100]));
ok('amount mismatch: no licence, order failed', $f['ok'] === false && sc_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 0
    && palette_order_by($conn, 'id', (int)$row['id'])['status'] === 'failed' && !$GLOBALS['mails']);
$log = (string)@file_get_contents(sys_get_temp_dir() . '/palette-test.log');
ok('amount mismatch logs PALETTE_PAID_UNFULFILLED with the order id', str_contains($log, 'PALETTE_PAID_UNFULFILLED order=' . $row['order_id']));
[$r, $row] = sc_order($conn);
$f = palette_st_fulfil_order($conn, $row, sc_session($row, ['currency' => 'vnd']));
ok('wrong currency: failed', $f['ok'] === false && palette_order_by($conn, 'id', (int)$row['id'])['status'] === 'failed');
[$r, $row] = sc_order($conn);
$f = palette_st_fulfil_order($conn, $row, sc_session($row, ['amount_total' => 200]));
ok('overpay accepted', $f['ok'] === true && $f['key'] !== null);

// ---- session binding and unpaid
sc_reset($conn);
[$r, $row] = sc_order($conn);
$st_of = fn() => palette_order_by($conn, 'id', (int)$row['id'])['status'];
$bad = [['client_reference_id' => 'palette:order:999'], ['metadata' => ['row' => '999']], ['id' => 'cs_live_other'], ['livemode' => false]];
$allRefused = true;
foreach ($bad as $over) {
    $f = palette_st_fulfil_order($conn, $row, sc_session($row, $over));
    $allRefused = $allRefused && $f['ok'] === false && empty($f['retry']) && $st_of() === 'created';
}
ok('foreign/livemode-mismatched session: no licence, order still created', $allRefused && sc_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 0 && !$GLOBALS['mails']);
$log = (string)@file_get_contents(sys_get_temp_dir() . '/palette-test.log');
ok('foreign session logged', str_contains($log, 'PALETTE_PAID_UNFULFILLED order=' . $row['order_id'] . ' foreign session'));
$f = palette_st_fulfil_order($conn, $row, sc_session($row, ['payment_status' => 'unpaid']));
ok('unpaid session: retry, order stays created, no licence', $f === ['ok' => false, 'key' => null, 'already' => false, 'retry' => true]
    && $st_of() === 'created' && sc_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 0);
$f = palette_st_fulfil_order($conn, $row, sc_session($row));
ok('same session later paid: licence issued', $f['ok'] === true && $f['key'] !== null && $st_of() === 'captured' && sc_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 1);

// ---- states
sc_reset($conn);
[$r, $row] = sc_order($conn);
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 200, 'json' => sc_session($row, ['payment_status' => 'unpaid', 'status' => 'open'])];
ok('unpaid session -> waiting', palette_st_confirm($conn, $row['stripe_session']) === ['state' => 'waiting'] && sc_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 0);
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 200, 'json' => sc_session($row, ['payment_status' => 'unpaid', 'status' => 'expired'])];
ok('expired session -> failed', palette_st_confirm($conn, $row['stripe_session'])['state'] === 'failed');
ok('unknown session -> unknown', palette_st_confirm($conn, 'cs_live_nothere') === ['state' => 'unknown'] && palette_st_confirm($conn, 'junk!') === ['state' => 'unknown']);

sc_reset($conn);
[$r, $row] = sc_order($conn);
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 0, 'json' => []];
$GLOBALS['st_calls'] = [];
$res = palette_st_confirm($conn, $row['stripe_session']);
$log = (string)@file_get_contents(sys_get_temp_dir() . '/palette-test.log');
ok('transport failure -> err_stripe, logged with order id and no key', $res === ['state' => 'error', 'error' => 'err_stripe']
    && str_contains($log, 'PALETTE_STRIPE_ERROR get session order=' . $row['order_id']) && !str_contains($log, 'sk_live_x'));

// ---- test-mode order issues a livemode=0 licence
sc_reset($conn);
[$r, $row] = sc_order($conn, 'test');
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 200, 'json' => sc_session($row)];
$res = palette_st_confirm($conn, $row['stripe_session']);
$l = $conn->query('SELECT * FROM palette_licenses')->fetch_assoc();
$get = array_values(array_filter($GLOBALS['st_calls'], fn($x) => $x['method'] === 'GET'))[0];
ok('test-mode order: livemode=0 licence, test key used', $res['state'] === 'paid' && (int)$l['livemode'] === 0 && $l['source'] === 'stripe'
    && in_array('Authorization: Bearer sk_test_x', $get['headers'], true) && (int)palette_order_by($conn, 'id', (int)$row['id'])['livemode'] === 0);

// ---- palette_issue_license stays backward compatible
$k = palette_issue_license($conn, 'old@caller.vn', 'ORD-1', 'en');
$l = $conn->query('SELECT source, livemode FROM palette_licenses WHERE id = ' . (int)$k['id'])->fetch_assoc();
ok('issue_license default: source null, livemode 1', $l['source'] === null && (int)$l['livemode'] === 1);

// ---- API
function sc_call(string $action, array $post = []): array
{
    return palette_api_handle(['method' => 'POST', 'action' => $action, 'post' => $post + ['csrf' => generateCSRFToken('palette_checkout')],
        'origin' => '', 'ip' => '203.0.113.9', 'session' => $_SESSION]);
}

sc_reset($conn);
$GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_live_api', 'url' => 'https://checkout.stripe.com/c/pay/api']];
$r = sc_call('st_checkout', ['email' => 'me@x.vn', 'consent' => '1', 'plan' => 'pro', 'lang' => 'en']);
ok('api st_checkout ok', $r['status'] === 200 && $r['body']['ok'] === true && $r['body']['url'] === 'https://checkout.stripe.com/c/pay/api');
$rowId = (int)$conn->query("SELECT id FROM palette_orders WHERE stripe_session = 'cs_live_api'")->fetch_row()[0];
ok('api st_checkout records the row in the session', ($_SESSION['palette_st'] ?? null) === ['o:' . $rowId]);
ok('api st_checkout needs CSRF', palette_api_handle(['method' => 'POST', 'action' => 'st_checkout', 'post' => ['csrf' => 'bad', 'email' => 'me@x.vn', 'consent' => '1'],
    'origin' => '', 'ip' => '1.1.1.1', 'session' => []])['status'] === 419);
ok('api st_checkout GET -> 405', palette_api_handle(['method' => 'GET', 'action' => 'st_checkout', 'post' => [], 'origin' => '', 'ip' => '1.1.1.1', 'session' => []])['status'] === 405);
$r = sc_call('st_checkout', ['email' => 'bad', 'consent' => '1']);
ok('api st_checkout bad email -> 400', $r['status'] === 400 && $r['body']['error'] === 'err_email');
$GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 0, 'json' => []];
$r = sc_call('st_checkout', ['email' => 'me@x.vn', 'consent' => '1']);
ok('api st_checkout Stripe down -> 502 err_stripe', $r['status'] === 502 && $r['body']['error'] === 'err_stripe');
$r = sc_call('st_checkout', ['email' => 'me@x.vn', 'consent' => '1', 'test' => '1']);
$GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_live_api2', 'url' => 'https://checkout.stripe.com/c/pay/api2']];
sc_call('st_checkout', ['email' => 'me@x.vn', 'consent' => '1', 'test' => '1']);
ok('api st_checkout test=1 ignored for non-admin', (int)$conn->query("SELECT livemode FROM palette_orders WHERE stripe_session = 'cs_live_api2'")->fetch_row()[0] === 1);

// key only for the owning session
sc_reset($conn);
[$r, $row] = sc_order($conn);
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 200, 'json' => sc_session($row)];
$_SESSION['csrf_tokens'] = [];
$stranger = sc_call('st_confirm', ['session' => $row['stripe_session']]);
ok('api st_confirm: stranger gets no key', $stranger['status'] === 200 && $stranger['body']['state'] === 'paid' && $stranger['body']['emailed'] === true
    && !array_key_exists('key', $stranger['body']) && !str_contains(json_encode($stranger['body']), (string)$conn->query('SELECT license_key FROM palette_licenses')->fetch_row()[0]));
$_SESSION['palette_st'] = ['o:' . (int)$row['id']];
$owner = sc_call('st_confirm', ['session' => $row['stripe_session']]);
$key = (string)$conn->query('SELECT license_key FROM palette_licenses')->fetch_row()[0];
ok('api st_confirm: owner gets the key', $owner['body']['state'] === 'paid' && $owner['body']['key'] === $key && $owner['body']['plan'] === 'pro' && !isset($owner['body']['row']));
$r = sc_call('st_confirm', ['session' => 'cs_live_zzz']);
ok('api st_confirm unknown / waiting', $r['status'] === 200 && $r['body']['state'] === 'unknown');
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 0, 'json' => []];
sc_reset($conn);
[$r, $row] = sc_order($conn);
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 0, 'json' => []];
$r = sc_call('st_confirm', ['session' => $row['stripe_session']]);
ok('api st_confirm Stripe down -> 502', $r['status'] === 502 && $r['body']['error'] === 'err_stripe');
ok('api st_confirm needs a session param', sc_call('st_confirm', [])['body']['state'] === 'unknown');

// i18n err_stripe present everywhere
$allLangs = true;
foreach (PALETTE_LANGS as $lg) $allLangs = $allLangs && isset((require PALETTE_DIR . "/lib/i18n/$lg.php")['err_stripe']);
ok('err_stripe in every language', $allLangs);

// Task 7: the return page polls st_confirm every 3 s for 30 s (11 calls). The limit is per Stripe session id (40/h),
// with a wide per-IP cap (600/h), so buyers behind one address (an office, a carrier NAT) never 429 each other's polling.
sc_reset($conn);
[$r, $row] = sc_order($conn);
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 200, 'json' => sc_session($row, ['payment_status' => 'unpaid', 'status' => 'open'])];
$codes = [];
for ($i = 0; $i < 35; $i++) $codes[] = sc_call('st_confirm', ['session' => $row['stripe_session']])['status'];
ok('st_confirm: 35 polls of one session from one IP never 429 (old limit was 30/h per IP)', array_unique($codes) === [200]);
for ($i = 0; $i < 5; $i++) sc_call('st_confirm', ['session' => $row['stripe_session']]);
ok('st_confirm: past 40 polls of one session id -> 429', sc_call('st_confirm', ['session' => $row['stripe_session']])['status'] === 429);
ok('st_confirm: another buyer on the same IP is not blocked', sc_call('st_confirm', ['session' => 'cs_live_other1'])['status'] === 200);
