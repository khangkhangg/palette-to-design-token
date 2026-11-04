<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/st-stub.php';
ini_set('error_log', sys_get_temp_dir() . '/palette-test.log');
ob_start();
session_start();

function ss_reset(mysqli $c): void
{
    st_stub_reset($c);
    st_stub_connect($c, 'live');
    st_stub_connect($c, 'test');
    $c->query('DELETE FROM palette_rate');
    $_SESSION = [];
}

function ss_start(mysqli $c, string $mode = 'live', ?int $move = null): array
{
    $GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_' . $mode . '_' . mt_rand(), 'url' => 'https://checkout.stripe.com/c/pay/s']];
    $r = palette_st_sub_start($c, $mode, 'Buyer@Studio.vn', 'en', true, $move);
    $row = $r['ok'] ? palette_sub_row($c, 'id', $r['row_id']) : null;
    return [$r, $row];
}

const SS_START = 1760000000;
const SS_END = 1762592000;

function ss_session(array $row, array $subOver = [], array $over = []): array
{
    $sub = $subOver + ['id' => 'sub_123', 'status' => 'active', 'currency' => 'usd', 'metadata' => ['palette' => 'sub', 'row' => (string)$row['id']],
        'current_period_start' => SS_START, 'current_period_end' => SS_END, 'items' => ['data' => [['price' => ['unit_amount' => 399, 'currency' => 'usd']]]]];
    return $over + ['id' => $row['stripe_session'], 'mode' => 'subscription', 'payment_status' => 'paid', 'status' => 'complete', 'currency' => 'usd',
        'customer' => 'cus_123', 'subscription' => $sub, 'client_reference_id' => 'palette:sub:' . $row['id'], 'livemode' => (int)$row['livemode'] === 1];
}

function ss_count(mysqli $c, string $sql): int
{
    return (int)$c->query($sql)->fetch_row()[0];
}

// ---- start params
ss_reset($conn);
[$r, $row] = ss_start($conn);
$raw = (string)($GLOBALS['st_calls'][0]['raw'] ?? '');
$d = urldecode($raw);
ok('sub start ok', $r['ok'] === true && $r['url'] === 'https://checkout.stripe.com/c/pay/s' && $r['row_id'] > 0);
ok('sub start params: mode, monthly, cents, name', str_contains($raw, 'mode=subscription') && str_contains($raw, 'recurring%5D%5Binterval%5D=month')
    && str_contains($raw, 'unit_amount%5D=399') && str_contains($raw, 'currency%5D=usd') && str_contains($d, '[product_data][name]=Site to Design Token Pro + AI'));
ok('sub start params: reference + subscription metadata + email', str_contains($raw, 'client_reference_id=palette%3Asub%3A' . $r['row_id'])
    && str_contains($d, 'subscription_data[metadata][palette]=sub') && str_contains($d, 'subscription_data[metadata][row]=' . $r['row_id'])
    && str_contains($raw, 'customer_email=buyer%40studio.vn') && !str_contains($raw, 'trial_end'));
ok('sub start params: success url + live key', str_contains($d, 'success_url=https://vietnamcos.com/palette/checkout?st={CHECKOUT_SESSION_ID}&lang=en')
    && in_array('Authorization: Bearer sk_live_x', $GLOBALS['st_calls'][0]['headers'], true));
ok('sub start row', $row['provider'] === 'stripe' && $row['plan_id'] === 'stripe' && $row['status'] === 'pending' && $row['price_usd'] === '3.99'
    && (int)$row['livemode'] === 1 && $row['subscription_id'] === null && str_starts_with($row['stripe_session'], 'cs_live_') && $row['move_license_id'] === null);
$GLOBALS['st_calls'] = [];
[$r, $row] = ss_start($conn, 'test');
ok('sub start test mode: test key, livemode 0', in_array('Authorization: Bearer sk_test_x', $GLOBALS['st_calls'][0]['headers'], true)
    && (int)$row['livemode'] === 0 && str_contains(urldecode((string)$GLOBALS['st_calls'][0]['raw']), '&test=1'));
$GLOBALS['st_calls'] = [];
$GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_live_tr', 'url' => 'https://checkout.stripe.com/c/pay/t']];
palette_st_sub_start($conn, 'live', 'a@b.vn', 'en', true, null, '1770000000');
ok('sub start trial_end', str_contains((string)$GLOBALS['st_calls'][0]['raw'], 'subscription_data%5Btrial_end%5D=1770000000'));
[$r, $row] = ss_start($conn, 'live', 77);
ok('sub start stores the move target', (int)$row['move_license_id'] === 77);

// ---- paused / validation / failure
ss_reset($conn);
$conn->query("DELETE FROM admin_settings WHERE setting_key = 'palette_stripe'");
$r = palette_st_sub_start($conn, 'live', 'a@b.vn', 'en', true);
ok('sub start paused', $r === ['ok' => false, 'error' => 'err_paused'] && !$GLOBALS['st_calls'] && ss_count($conn, 'SELECT COUNT(*) FROM palette_subscriptions') === 0);
ss_reset($conn);
$a = palette_st_sub_start($conn, 'live', 'nope', 'en', true);
$b = palette_st_sub_start($conn, 'live', "a@b.vn\r\nBcc: x@y.z", 'en', true);
$c2 = palette_st_sub_start($conn, 'live', 'a@b.vn', 'en', false);
ok('sub start validation', $a['error'] === 'err_email' && $b['error'] === 'err_email' && $c2['error'] === 'err_consent' && !$GLOBALS['st_calls']
    && ss_count($conn, 'SELECT COUNT(*) FROM palette_subscriptions') === 0);
$GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 0, 'json' => []];
$r = palette_st_sub_start($conn, 'live', 'a@b.vn', 'en', true);
ok('sub start Stripe down -> err_stripe, row closed', $r === ['ok' => false, 'error' => 'err_stripe']
    && ss_count($conn, "SELECT COUNT(*) FROM palette_subscriptions WHERE status = 'pending'") === 0);

// ---- confirm issues the ai licence with paid_through = period end
ss_reset($conn);
[$r, $row] = ss_start($conn);
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 200, 'json' => ss_session($row)];
$res = palette_st_confirm($conn, $row['stripe_session']);
ok('sub confirm: paid, plan ai, key, kind', $res['state'] === 'paid' && $res['plan'] === 'ai' && preg_match('/^[A-Z0-9-]{10,}$/', (string)$res['key']) === 1
    && $res['row'] === (int)$row['id'] && $res['kind'] === 's');
$get = array_values(array_filter($GLOBALS['st_calls'], fn($x) => $x['method'] === 'GET'))[0];
ok('sub confirm expands subscription with the row mode key', str_contains(urldecode($get['url']), 'expand[0]=subscription') && in_array('Authorization: Bearer sk_live_x', $get['headers'], true));
$l = $conn->query('SELECT * FROM palette_licenses')->fetch_assoc();
$s2 = palette_sub_row($conn, 'id', (int)$row['id']);
ok('sub licence: ai, stripe, active, period from Stripe in UTC', $l['plan'] === 'ai' && $l['source'] === 'stripe' && (int)$l['livemode'] === 1 && $l['sub_status'] === 'active'
    && $l['subscription_id'] === 'sub_123' && $l['paid_through'] === gmdate('Y-m-d H:i:s', SS_END) && $l['period_start'] === gmdate('Y-m-d H:i:s', SS_START)
    && $l['order_id'] === 'sub_123');
ok('sub row: active, bound, customer, licence linked', $s2['status'] === 'active' && $s2['subscription_id'] === 'sub_123' && $s2['stripe_customer'] === 'cus_123' && (int)$s2['license_id'] === (int)$l['id']);
ok('sub confirm: one mail to the typed address', count($GLOBALS['mails']) === 1 && ($GLOBALS['mails'][0]['to'][0] ?? $GLOBALS['mails'][0]['to'] ?? '') === 'buyer@studio.vn');
$again = palette_st_confirm($conn, $row['stripe_session']);
ok('sub confirm again: same key, no second licence or mail', $again['state'] === 'paid' && $again['key'] === $res['key']
    && ss_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 1 && count($GLOBALS['mails']) === 1);

// period fallback to items.data[0].current_period_*
ss_reset($conn);
[$r, $row] = ss_start($conn);
$sess = ss_session($row, ['current_period_start' => null, 'current_period_end' => null]);
unset($sess['subscription']['current_period_start'], $sess['subscription']['current_period_end']);
$sess['subscription']['items']['data'][0]['current_period_start'] = SS_START;
$sess['subscription']['items']['data'][0]['current_period_end'] = SS_END;
$f = palette_st_fulfil_sub($conn, $row, $sess);
$l = $conn->query('SELECT paid_through FROM palette_licenses')->fetch_assoc();
ok('sub period falls back to the item', $f['ok'] === true && $l['paid_through'] === gmdate('Y-m-d H:i:s', SS_END));

// ---- race
ss_reset($conn);
[$r, $row] = ss_start($conn);
$a = palette_st_fulfil_sub($conn, $row, ss_session($row));
$b = palette_st_fulfil_sub($conn, $row, ss_session($row));
ok('sub fulfil race: one licence, one mail, same key', $a['ok'] && $b['ok'] && $a['already'] === false && $b['already'] === true
    && $a['key'] !== null && $a['key'] === $b['key'] && ss_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 1 && count($GLOBALS['mails']) === 1);

// ---- price mismatch / currency / status
ss_reset($conn);
[$r, $row] = ss_start($conn);
$low = ss_session($row);
$low['subscription']['items']['data'][0]['price']['unit_amount'] = 100;
$f = palette_st_fulfil_sub($conn, $row, $low);
ok('sub price mismatch: no licence, row stays pending, no mail', $f['ok'] === false && empty($f['retry']) && ss_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 0
    && palette_sub_row($conn, 'id', (int)$row['id'])['status'] === 'pending' && !$GLOBALS['mails']);
$log = (string)@file_get_contents(sys_get_temp_dir() . '/palette-test.log');
ok('sub price mismatch logs PALETTE_PAID_UNFULFILLED', str_contains($log, 'PALETTE_PAID_UNFULFILLED sub=' . $row['id']));
$vnd = ss_session($row);
$vnd['subscription']['items']['data'][0]['price']['currency'] = 'vnd';
$vnd['subscription']['currency'] = 'vnd';
$vnd['currency'] = 'vnd';
ok('sub wrong currency refused', palette_st_fulfil_sub($conn, $row, $vnd)['ok'] === false && ss_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 0);
$over = ss_session($row);
$over['subscription']['items']['data'][0]['price']['unit_amount'] = 500;
ok('sub overpay accepted', palette_st_fulfil_sub($conn, $row, $over)['ok'] === true);
ss_reset($conn);
[$r, $row] = ss_start($conn);
$f = palette_st_fulfil_sub($conn, $row, ss_session($row, ['status' => 'canceled']));
ok('sub canceled status refused, no retry', $f['ok'] === false && empty($f['retry']) && ss_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 0);
$f = palette_st_fulfil_sub($conn, $row, ss_session($row, ['status' => 'trialing']));
ok('sub trialing accepted', $f['ok'] === true && $f['key'] !== null);

// ---- incomplete / unpaid -> waiting
ss_reset($conn);
[$r, $row] = ss_start($conn);
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 200, 'json' => ss_session($row, ['status' => 'incomplete'])];
ok('incomplete subscription -> waiting, row pending', palette_st_confirm($conn, $row['stripe_session']) === ['state' => 'waiting']
    && palette_sub_row($conn, 'id', (int)$row['id'])['status'] === 'pending' && ss_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 0);
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 200, 'json' => ss_session($row, [], ['payment_status' => 'unpaid', 'status' => 'open'])];
ok('unpaid session -> waiting', palette_st_confirm($conn, $row['stripe_session']) === ['state' => 'waiting']);
$f = palette_st_fulfil_sub($conn, $row, ss_session($row, [], ['payment_status' => 'unpaid']));
ok('unpaid fulfil: retry, pending', $f === ['ok' => false, 'key' => null, 'already' => false, 'retry' => true] && palette_sub_row($conn, 'id', (int)$row['id'])['status'] === 'pending');
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 200, 'json' => ss_session($row, [], ['payment_status' => 'unpaid', 'status' => 'expired'])];
ok('expired session -> failed', palette_st_confirm($conn, $row['stripe_session'])['state'] === 'failed');
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 200, 'json' => ss_session($row)];
$res = palette_st_confirm($conn, $row['stripe_session']);
ok('same session later active: paid', $res['state'] === 'paid' && $res['key'] !== null);
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 0, 'json' => []];
ss_reset($conn);
[$r, $row] = ss_start($conn);
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 0, 'json' => []];
ok('sub transport failure -> err_stripe', palette_st_confirm($conn, $row['stripe_session']) === ['state' => 'error', 'error' => 'err_stripe']);

// ---- binding
ss_reset($conn);
[$r, $row] = ss_start($conn);
$bad = [
    ['over' => ['client_reference_id' => 'palette:sub:999']],
    ['over' => ['id' => 'cs_live_other']],
    ['over' => ['livemode' => false]],
    ['sub' => ['metadata' => ['palette' => 'sub', 'row' => '999']]],
];
$allRefused = true;
foreach ($bad as $b) {
    $f = palette_st_fulfil_sub($conn, $row, ss_session($row, $b['sub'] ?? [], $b['over'] ?? []));
    $allRefused = $allRefused && $f['ok'] === false && empty($f['retry']) && palette_sub_row($conn, 'id', (int)$row['id'])['status'] === 'pending';
}
ok('sub foreign session: refused, row pending, no licence, no mail', $allRefused && ss_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 0 && !$GLOBALS['mails']);
$log = (string)@file_get_contents(sys_get_temp_dir() . '/palette-test.log');
ok('sub foreign session logged', str_contains($log, 'PALETTE_PAID_UNFULFILLED sub=' . $row['id'] . ' foreign session'));
$noMeta = ss_session($row);
unset($noMeta['subscription']['metadata']);
ok('sub without metadata still binds by client_reference_id', palette_st_fulfil_sub($conn, $row, $noMeta)['ok'] === true);

// ---- move to card: re-points the existing licence
ss_reset($conn);
$old = palette_issue_license($conn, 'buyer@studio.vn', 'I-OLDPAYPAL1', 'en', ['subscription_id' => 'I-OLDPAYPAL1', 'paid_through' => '2026-12-01 00:00:00', 'period_start' => '2026-11-01 00:00:00']);
[$r, $row] = ss_start($conn, 'live', $old['id']);
$f = palette_st_fulfil_sub($conn, $row, ss_session($row));
$ls = $conn->query('SELECT * FROM palette_licenses')->fetch_all(MYSQLI_ASSOC);
$s2 = palette_sub_row($conn, 'id', (int)$row['id']);
ok('move: returns the existing key, no new licence', $f['ok'] === true && $f['key'] === $old['key'] && count($ls) === 1);
ok('move: licence re-pointed to stripe', $ls[0]['subscription_id'] === 'sub_123' && $ls[0]['source'] === 'stripe' && $ls[0]['sub_status'] === 'active'
    && $ls[0]['paid_through'] === gmdate('Y-m-d H:i:s', SS_END) && $ls[0]['period_start'] === gmdate('Y-m-d H:i:s', SS_START) && (int)$ls[0]['livemode'] === 1);
ok('move: row linked to that licence and active', (int)$s2['license_id'] === (int)$old['id'] && $s2['status'] === 'active');

// ---- palette_ai_on
ss_reset($conn);
$settingsBefore = palette_settings($conn);
ok('ai on with stripe only (PayPal unconfigured, live Stripe ready)', palette_ai_on() === true);
$s = palette_stripe_settings($conn);
$s['checkout_methods'] = ['paypal'];
palette_stripe_settings_put($conn, $s);
ok('ai off when stripe is not a checkout method and PayPal is not ready', palette_ai_on() === false);
$s['checkout_methods'] = ['stripe'];
$s['live']['error'] = 'bad key';
palette_stripe_settings_put($conn, $s);
ok('ai off when live Stripe is not ready', palette_ai_on() === false);

// ---- API
function ss_call(string $action, array $post = []): array
{
    return palette_api_handle(['method' => 'POST', 'action' => $action, 'post' => $post + ['csrf' => generateCSRFToken('palette_checkout')],
        'origin' => '', 'ip' => '203.0.113.9', 'session' => $_SESSION]);
}

ss_reset($conn);
$GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_live_apis', 'url' => 'https://checkout.stripe.com/c/pay/apis']];
$r = ss_call('st_checkout', ['email' => 'me@x.vn', 'consent' => '1', 'plan' => 'ai', 'lang' => 'en']);
$sid = (int)$conn->query("SELECT id FROM palette_subscriptions WHERE stripe_session = 'cs_live_apis'")->fetch_row()[0];
ok('api st_checkout plan=ai starts a subscription', $r['status'] === 200 && $r['body']['url'] === 'https://checkout.stripe.com/c/pay/apis' && $sid > 0
    && str_contains((string)$GLOBALS['st_calls'][0]['raw'], 'mode=subscription'));
ok('api st_checkout records s:<id> in the session', ($_SESSION['palette_st'] ?? null) === ['s:' . $sid]);
$GLOBALS['st_routes']['GET /v1/checkout/sessions/cs_live_apis'] = ['status' => 200, 'json' => ss_session(palette_sub_row($conn, 'id', $sid))];
$_SESSION['csrf_tokens'] = [];
$_SESSION['palette_st'] = [];
$stranger = ss_call('st_confirm', ['session' => 'cs_live_apis']);
ok('api sub confirm: stranger gets no key', $stranger['body']['state'] === 'paid' && $stranger['body']['emailed'] === true && !array_key_exists('key', $stranger['body']));
$_SESSION['palette_st'] = ['o:' . $sid];   // an order with the same number must not own the subscription
$wrongKind = ss_call('st_confirm', ['session' => 'cs_live_apis']);
ok('api sub confirm: order id with the same number is not ownership', !array_key_exists('key', $wrongKind['body']));
$_SESSION['palette_st'] = ['s:' . $sid];
$owner = ss_call('st_confirm', ['session' => 'cs_live_apis']);
$key = (string)$conn->query('SELECT license_key FROM palette_licenses')->fetch_row()[0];
ok('api sub confirm: owner gets the key, plan ai', $owner['body']['state'] === 'paid' && $owner['body']['key'] === $key && $owner['body']['plan'] === 'ai'
    && !isset($owner['body']['row']) && !isset($owner['body']['kind']));
