<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/st-stub.php';

$calls = fn(string $m, string $p) => array_values(array_filter($GLOBALS['st_calls'], fn($x) => $x['method'] === $m && $x['path'] === $p));
$deletes = fn() => count(array_filter($GLOBALS['st_calls'], fn($x) => $x['method'] === 'DELETE'));
$balance = fn(bool $live) => ['status' => 200, 'json' => ['object' => 'balance', 'livemode' => $live]];
$created = ['status' => 200, 'json' => ['id' => 'we_new', 'secret' => 'whsec_new', 'status' => 'enabled']];

ok('events constant', count(PALETTE_STRIPE_EVENTS) === 8 && in_array('charge.dispute.created', PALETTE_STRIPE_EVENTS, true));
ok('webhook url', palette_stripe_webhook_url() === 'https://vietnamcos.com/palette/stripe-webhook.php');

// wrong prefix: no HTTP call
st_stub_reset($conn);
$r = palette_stripe_connect($conn, 'test', 'own', 'sk_live_abc');
ok('connect wrong prefix: error', $r['ok'] === false && $r['error'] !== null && $r['error'] !== '');
ok('connect wrong prefix: no http', count($GLOBALS['st_calls']) === 0);
ok('connect wrong prefix: nothing stored', palette_stripe_settings($conn)['test']['secret'] === '');

// ok registers webhook
st_stub_reset($conn);
$GLOBALS['st_routes']['GET /v1/balance'] = $balance(false);
$GLOBALS['st_routes']['POST /v1/webhook_endpoints'] = $created;
$r = palette_stripe_connect($conn, 'test', 'own', 'sk_test_secretvalue');
$m = palette_stripe_settings($conn)['test'];
ok('connect ok: result', $r['ok'] === true && $r['error'] === null);
ok('connect ok: stored id + secret', $m['webhook_id'] === 'we_new' && $m['webhook_secret'] === 'whsec_new' && $m['secret'] === 'sk_test_secretvalue' && $m['error'] === '' && $m['checked_at'] !== '');
$post = $calls('POST', '/v1/webhook_endpoints')[0]['params'] ?? [];
ok('connect ok: all 8 events', ($post['enabled_events'] ?? []) === PALETTE_STRIPE_EVENTS);
ok('connect ok: url + description + metadata', ($post['url'] ?? '') === palette_stripe_webhook_url() && ($post['description'] ?? '') === 'Site to Design Token (palette)' && ($post['metadata']['palette'] ?? '') === '1');
ok('connect ok: used the given key', str_contains(implode(' ', $calls('GET', '/v1/balance')[0]['headers']), 'sk_test_secretvalue'));

// livemode mismatch
st_stub_reset($conn);
$GLOBALS['st_routes']['GET /v1/balance'] = $balance(true);
$r = palette_stripe_connect($conn, 'test', 'own', 'sk_test_x');
ok('connect livemode mismatch fails', $r['ok'] === false && count($calls('POST', '/v1/webhook_endpoints')) === 0);

// reconnect replaces only the palette endpoint
st_stub_reset($conn);
st_stub_connect($conn, 'test');
$GLOBALS['st_routes']['GET /v1/balance'] = $balance(false);
$GLOBALS['st_routes']['POST /v1/webhook_endpoints'] = $created;
$GLOBALS['st_routes']['DELETE /v1/webhook_endpoints/we_test'] = ['status' => 200, 'json' => ['deleted' => true]];
$r = palette_stripe_connect($conn, 'test', 'own', 'sk_test_new');
ok('reconnect ok', $r['ok'] === true);
ok('reconnect deletes old palette endpoint', count($calls('DELETE', '/v1/webhook_endpoints/we_test')) === 1);
ok('reconnect touches no other endpoint', $deletes() === 1);
ok('reconnect stores new', palette_stripe_settings($conn)['test']['webhook_id'] === 'we_new');

// old endpoint already gone (404) is fine
st_stub_reset($conn);
st_stub_connect($conn, 'test');
$GLOBALS['st_routes']['GET /v1/balance'] = $balance(false);
$GLOBALS['st_routes']['POST /v1/webhook_endpoints'] = $created;
$GLOBALS['st_routes']['DELETE /v1/webhook_endpoints/we_test'] = ['status' => 404, 'json' => ['error' => ['message' => 'No such webhook endpoint']]];
ok('reconnect with 404 on delete continues', palette_stripe_connect($conn, 'test', 'own', 'sk_test_new')['ok'] === true);

// failure keeps old
st_stub_reset($conn);
st_stub_connect($conn, 'test');
$GLOBALS['st_routes']['GET /v1/balance'] = ['status' => 401, 'json' => ['error' => ['message' => 'Invalid API Key provided: sk_test_****new']]];
$r = palette_stripe_connect($conn, 'test', 'own', 'sk_test_new');
$m = palette_stripe_settings($conn)['test'];
ok('connect failure: error returned', $r['ok'] === false && str_contains((string)$r['error'], 'Invalid API Key'));
ok('connect failure: error stored', str_contains($m['error'], 'Invalid API Key'));
ok('connect failure: old connection kept', $m['webhook_secret'] === 'whsec_test' && $m['webhook_id'] === 'we_test' && $m['secret'] === 'sk_test_x');
ok('connect failure: nothing deleted', $deletes() === 0);
$GLOBALS['st_routes']['GET /v1/balance'] = ['status' => 500, 'json' => []];
palette_stripe_connect($conn, 'test', 'own', 'sk_test_new');
ok('connect failure without message: HTTP status', palette_stripe_settings($conn)['test']['error'] === 'HTTP 500');
$GLOBALS['st_routes']['GET /v1/balance'] = ['status' => 401, 'json' => ['error' => ['message' => 'bad key sk_test_abcdef123456 and whsec_zzz']]];
palette_stripe_connect($conn, 'test', 'own', 'sk_test_new');
$e = palette_stripe_settings($conn)['test']['error'];
ok('stored error has no secret', !str_contains($e, 'abcdef123456') && !str_contains($e, 'whsec_zzz'));

// shared live key
st_stub_reset($conn);
$GLOBALS['st_routes']['GET /v1/balance'] = $balance(true);
$GLOBALS['st_routes']['POST /v1/webhook_endpoints'] = $created;
$r = palette_stripe_connect($conn, 'live', 'shared', 'ignored');
ok('shared without companies key fails', $r['ok'] === false && count($GLOBALS['st_calls']) === 0);
$co = $conn->real_escape_string(json_encode(['enabled' => true, 'mode' => 'live', 'secret' => 'sk_live_companies']));
$conn->query("INSERT INTO admin_settings (setting_key, setting_value) VALUES ('stripe', '$co') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
$r = palette_stripe_connect($conn, 'live', 'shared', 'ignored');
$m = palette_stripe_settings($conn)['live'];
ok('shared connect ok', $r['ok'] === true && $m['source'] === 'shared' && $m['secret'] === '' && $m['webhook_secret'] === 'whsec_new');
ok('shared connect used companies key, not param', str_contains(implode(' ', $calls('GET', '/v1/balance')[0]['headers']), 'sk_live_companies'));
$conn->query("DELETE FROM admin_settings WHERE setting_key = 'stripe'");

// own live key
st_stub_reset($conn);
$GLOBALS['st_routes']['GET /v1/balance'] = $balance(true);
$GLOBALS['st_routes']['POST /v1/webhook_endpoints'] = $created;
ok('own live restricted key ok', palette_stripe_connect($conn, 'live', 'own', 'rk_live_abc')['ok'] === true && palette_stripe_settings($conn)['live']['source'] === 'own');

// check
$checkSetup = function (array $ep) use ($conn, $balance) {
    st_stub_reset($conn);
    st_stub_connect($conn, 'test');
    $GLOBALS['st_routes']['GET /v1/balance'] = $balance(false);
    $GLOBALS['st_routes']['GET /v1/webhook_endpoints/we_test'] = ['status' => 200, 'json' => $ep];
};
$good = ['id' => 'we_test', 'status' => 'enabled', 'url' => 'https://vietnamcos.com/palette/stripe-webhook.php', 'enabled_events' => PALETTE_STRIPE_EVENTS];
$labels = ['Key accepted by Stripe', 'Key is in test mode', 'Webhook registered', 'Webhook enabled', 'Webhook points to this site', 'Webhook sends every needed event'];
$by = fn(array $r, string $l) => array_values(array_filter($r['checks'], fn($x) => $x['label'] === $l))[0];
$checkSetup($good);
$r = palette_stripe_check($conn, 'test');
ok('check all pass', $r['ok'] === true && array_column($r['checks'], 'label') === $labels && !in_array(false, array_column($r['checks'], 'ok'), true));
ok('check all pass: error clear', palette_stripe_settings($conn)['test']['error'] === '');
$checkSetup(['status' => 'disabled'] + $good);
$r = palette_stripe_check($conn, 'test');
ok('check disabled webhook', $r['ok'] === false && $by($r, 'Webhook enabled')['ok'] === false && $by($r, 'Webhook registered')['ok'] === true);
ok('check failure stored as error', palette_stripe_settings($conn)['test']['error'] !== '');
ok('check is read-only on Stripe', count(array_filter($GLOBALS['st_calls'], fn($x) => $x['method'] !== 'GET')) === 0);
$checkSetup(['enabled_events' => array_slice(PALETTE_STRIPE_EVENTS, 0, 5)] + $good);
$r = palette_stripe_check($conn, 'test');
ok('check missing events', $r['ok'] === false && $by($r, 'Webhook sends every needed event')['ok'] === false && str_contains($by($r, 'Webhook sends every needed event')['detail'], 'charge.refunded'));
$checkSetup(['enabled_events' => ['*']] + $good);
ok('check wildcard events pass', palette_stripe_check($conn, 'test')['ok'] === true);
$checkSetup(['url' => 'https://example.com/x'] + $good);
ok('check wrong url', $by(palette_stripe_check($conn, 'test'), 'Webhook points to this site')['ok'] === false);
$checkSetup($good);
$GLOBALS['st_routes']['GET /v1/webhook_endpoints/we_test'] = ['status' => 404, 'json' => ['error' => ['message' => 'No such webhook endpoint']]];
$r = palette_stripe_check($conn, 'test');
ok('check endpoint missing', $r['ok'] === false && $by($r, 'Webhook registered')['ok'] === false);
$checkSetup($good);
$GLOBALS['st_routes']['GET /v1/balance'] = $balance(true);
ok('check wrong mode', $by(palette_stripe_check($conn, 'test'), 'Key is in test mode')['ok'] === false);
$checkSetup($good);
$GLOBALS['st_routes']['GET /v1/balance'] = ['status' => 401, 'json' => ['error' => ['message' => 'Invalid API Key']]];
$r = palette_stripe_check($conn, 'test');
ok('check bad key', $r['ok'] === false && $by($r, 'Key accepted by Stripe')['ok'] === false && count($r['checks']) === 6);
st_stub_reset($conn);
$r = palette_stripe_check($conn, 'test');
ok('check unconnected: six failing lines, no http', $r['ok'] === false && count($r['checks']) === 6 && count($GLOBALS['st_calls']) === 0);

// disconnect
st_stub_reset($conn);
st_stub_connect($conn, 'test');
$GLOBALS['st_routes']['DELETE /v1/webhook_endpoints/we_test'] = ['status' => 200, 'json' => ['deleted' => true]];
$r = palette_stripe_disconnect($conn, 'test');
$m = palette_stripe_settings($conn)['test'];
ok('disconnect', $r['ok'] === true && $m['secret'] === '' && $m['webhook_id'] === '' && $m['webhook_secret'] === '' && count($calls('DELETE', '/v1/webhook_endpoints/we_test')) === 1);
st_stub_connect($conn, 'live');
st_stub_connect($conn, 'test');
$GLOBALS['st_routes']['DELETE /v1/webhook_endpoints/we_test'] = ['status' => 404, 'json' => []];
ok('disconnect 404 ok and other mode untouched', palette_stripe_disconnect($conn, 'test')['ok'] === true && palette_stripe_settings($conn)['live']['webhook_secret'] === 'whsec_live');
st_stub_connect($conn, 'test');
$GLOBALS['st_routes']['DELETE /v1/webhook_endpoints/we_test'] = ['status' => 500, 'json' => []];
ok('disconnect on Stripe outage keeps connection', palette_stripe_disconnect($conn, 'test')['ok'] === false && palette_stripe_settings($conn)['test']['webhook_secret'] === 'whsec_test');

// methods
st_stub_reset($conn);
$r = palette_stripe_set_methods($conn, ['stripe']);
ok('methods refuse unconnected stripe', $r['ok'] === false && palette_stripe_settings($conn)['checkout_methods'] === ['stripe']);
ok('methods refuse unready paypal', palette_stripe_set_methods($conn, ['paypal'])['ok'] === false);
ok('methods refuse empty / unknown', palette_stripe_set_methods($conn, [])['ok'] === false && palette_stripe_set_methods($conn, ['bitcoin'])['ok'] === false);
st_stub_connect($conn, 'live');
pp_settings_put($conn, ['enabled' => true, 'client_id' => 'cid', 'secret' => 'sec', 'error' => ''] + pp_settings($conn));
ok('methods order kept', palette_stripe_set_methods($conn, ['paypal', 'stripe'])['ok'] === true && palette_stripe_settings($conn)['checkout_methods'] === ['paypal', 'stripe']);
ok('methods dedupe', palette_stripe_set_methods($conn, ['paypal', 'paypal'])['ok'] === true && palette_stripe_settings($conn)['checkout_methods'] === ['paypal']);
$conn->query("DELETE FROM admin_settings WHERE setting_key = 'paypal'");
ok('methods paypal refused once not ready', palette_stripe_set_methods($conn, ['paypal'])['ok'] === false);
