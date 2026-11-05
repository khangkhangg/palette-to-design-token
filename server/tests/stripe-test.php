<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/st-stub.php';

st_stub_reset($conn);

// migration idempotent
palette_migrate($conn);
palette_migrate($conn);
foreach ([['palette_orders', 'provider'], ['palette_orders', 'stripe_session'], ['palette_orders', 'stripe_payment_intent'], ['palette_orders', 'livemode'],
          ['palette_subscriptions', 'provider'], ['palette_subscriptions', 'stripe_customer'], ['palette_subscriptions', 'stripe_session'], ['palette_subscriptions', 'livemode'],
          ['palette_licenses', 'source'], ['palette_licenses', 'pay_status'], ['palette_licenses', 'livemode']] as [$t, $col]) {
    ok("migration idempotent: $t.$col", palette_column_exists($conn, $t, $col));
}
ok('migration idempotent: palette_admin_log', palette_table_exists($conn, 'palette_admin_log'));
$len = function (string $t, string $col) use ($conn): int {
    $st = $conn->prepare('SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->bind_param('ss', $t, $col);
    $st->execute();
    $n = (int)$st->get_result()->fetch_row()[0];
    $st->close();
    return $n;
};
ok('widened subscription ids', $len('palette_licenses', 'subscription_id') === 80 && $len('palette_subscriptions', 'subscription_id') === 80
    && $len('palette_sub_payments', 'subscription_id') === 80 && $len('palette_sub_payments', 'sale_id') === 80);

// settings defaults
$s = palette_stripe_settings($conn);
ok('settings defaults: checkout_methods', $s['checkout_methods'] === ['stripe']);
ok('settings defaults: both modes empty', $s['live']['secret'] === '' && $s['test']['secret'] === '' && $s['live']['source'] === 'shared' && $s['test']['source'] === 'own');
ok('settings defaults: not ready', !palette_stripe_ready($conn, 'test') && !palette_stripe_ready($conn, 'live'));

// public never leaks
st_stub_connect($conn, 'test');
$pub = palette_stripe_public($conn);
$json = json_encode($pub);
ok('public never leaks: no secret', !str_contains($json, 'sk_test_x') && !str_contains($json, 'whsec_test'));
ok('public: key_hint', $pub['test']['key_hint'] === 'sk_test_…st_x');
ok('public: connected + webhook', $pub['test']['connected'] === true && $pub['test']['webhook'] === true && $pub['live']['connected'] === false);
ok('public: ready', palette_stripe_ready($conn, 'test'));
ok('public: checkout_methods', $pub['checkout_methods'] === ['stripe']);

// shared live key
$put = function (array $v) use ($conn): void {
    $j = json_encode($v);
    $st = $conn->prepare("INSERT INTO admin_settings (setting_key, setting_value) VALUES ('stripe', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $st->bind_param('s', $j);
    $st->execute();
    $st->close();
};
$put(['enabled' => true, 'mode' => 'live', 'secret' => 'sk_live_shared']);
ok('shared live key: live mode', palette_stripe_secret($conn, 'live') === 'sk_live_shared');
$put(['enabled' => true, 'mode' => 'test', 'secret' => 'sk_test_shared']);
ok('shared live key: companies in test mode -> empty', palette_stripe_secret($conn, 'live') === '');
ok('shared: test secret is own', palette_stripe_secret($conn, 'test') === 'sk_test_x');

// api form-encodes nested
$GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_1']];
$r = palette_st_api($conn, 'test', 'POST', 'checkout/sessions', ['line_items' => [['price_data' => ['unit_amount' => 149]]]]);
$call = $GLOBALS['st_calls'][0] ?? [];
ok('api: result', $r['status'] === 200 && $r['json']['id'] === 'cs_1');
ok('api form-encodes nested', str_contains((string)($call['raw'] ?? ''), 'line_items%5B0%5D%5Bprice_data%5D%5Bunit_amount%5D=149'));
ok('api: bearer + version', in_array('Authorization: Bearer sk_test_x', $call['headers'] ?? [], true) && in_array('Stripe-Version: 2024-06-20', $call['headers'] ?? [], true));

// signature
$p = '{"id":"evt_1"}';
$now = 1800000000;
ok('signature ok', palette_st_signed($p, st_sign($p, 'whsec_test', $now), 'whsec_test', $now));
ok('signature bad', !palette_st_signed($p, st_sign($p, 'whsec_other', $now), 'whsec_test', $now));
ok('signature tampered payload', !palette_st_signed($p . ' ', st_sign($p, 'whsec_test', $now), 'whsec_test', $now));
ok('signature stale', !palette_st_signed($p, st_sign($p, 'whsec_test', $now - 301), 'whsec_test', $now));
ok('signature within tolerance', palette_st_signed($p, st_sign($p, 'whsec_test', $now - 300), 'whsec_test', $now));
$good = st_sign($p, 'whsec_test', $now);
ok('signature multi-v1', palette_st_signed($p, 't=' . $now . ',v1=' . str_repeat('0', 64) . ',' . substr($good, strpos($good, ',') + 1), 'whsec_test', $now));
ok('signature empty', !palette_st_signed($p, '', 'whsec_test', $now) && !palette_st_signed($p, $good, '', $now));

// test key expires
$mk = function (int $livemode, int $days, string $key) use ($conn): void {
    $at = date('Y-m-d H:i:s', time() - $days * 86400);
    $st = $conn->prepare("INSERT INTO palette_licenses (license_key, email, order_id, lang, created_at, livemode) VALUES (?, 'a@b.vn', ?, 'en', ?, ?)");
    $o = 'o' . $key;
    $st->bind_param('sssi', $key, $o, $at, $livemode);
    $st->execute();
    $st->close();
};
$mk(0, 8, 'SDT-AAAA-AAAA-AAAA');
$mk(0, 6, 'SDT-BBBB-BBBB-BBBB');
$mk(1, 400, 'SDT-CCCC-CCCC-CCCC');
ok('test key expires: 8 days', palette_verify($conn, 'SDT-AAAA-AAAA-AAAA', '10.0.0.1')['valid'] === false);
ok('test key expires: 6 days', palette_verify($conn, 'SDT-BBBB-BBBB-BBBB', '10.0.0.1')['valid'] === true);
ok('test key expires: live 400 days', palette_verify($conn, 'SDT-CCCC-CCCC-CCCC', '10.0.0.1')['valid'] === true);
st_stub_reset($conn);
