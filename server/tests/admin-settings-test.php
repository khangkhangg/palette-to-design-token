<?php
// Admin > Settings: Stripe connection cards, actions, checkout methods.
require __DIR__ . '/helpers.php';
require __DIR__ . '/st-stub.php';

$admin = ['user_id' => 1, 'user_role' => 'admin', 'csrf_tokens' => ['palette_admin' => str_repeat('a', 64)]];
$csrf = str_repeat('a', 64);
$sess = fn() => array_merge($admin, $_SESSION ?? []);
$post = fn(array $p) => palette_admin_page($conn, $sess(), [], ['csrf' => $csrf] + $p, 'POST');
$get = fn(array $q = []) => palette_admin_page($conn, $sess(), ['tab' => 'settings'] + $q, [], 'GET');
$loc = fn(array $r) => (string)($r['headers']['Location'] ?? '');
$logs = fn() => implode("\n", array_map('json_encode', $conn->query('SELECT action, detail, note, order_ref FROM palette_admin_log')->fetch_all(MYSQLI_ASSOC)));
$balance = fn(bool $live) => ['status' => 200, 'json' => ['object' => 'balance', 'livemode' => $live]];
$_SESSION = [];

st_stub_reset($conn);

// ---- empty page
$r = $get();
ok('settings: renders in shell, not a placeholder', $r['status'] === 200 && !str_contains($r['body'], 'Coming in a later task') && str_contains($r['body'], 'Issue a key'));
ok('settings: both cards, not connected', substr_count($r['body'], 'Not connected') >= 2 && str_contains($r['body'], 'Live') && str_contains($r['body'], 'Test'));
ok('settings: help text and keys link', str_contains($r['body'], 'dashboard.stripe.com/apikeys') && str_contains($r['body'], 'Balance') && str_contains($r['body'], 'Webhook endpoints'));
ok('settings: key field is a password, off, never prefilled', preg_match('/<input[^>]*type="password"[^>]*name="secret"[^>]*autocomplete="off"/', $r['body']) === 1
    && !preg_match('/name="secret"[^>]*value="[^"]/', $r['body']));
ok('settings: live source radios, shared by default', str_contains($r['body'], 'Use the vietnamcos.com Stripe account') && str_contains($r['body'], 'Use a separate key')
    && preg_match('/value="shared"[^>]*checked/', $r['body']) === 1);
ok('settings: test checkout link', str_contains($r['body'], '/palette/checkout?test=1') && str_contains($r['body'], 'Open the test checkout'));

// ---- connect (test), then no secret anywhere
$GLOBALS['st_routes']['GET /v1/balance'] = $balance(false);
$GLOBALS['st_routes']['POST /v1/webhook_endpoints'] = ['status' => 200, 'json' => ['id' => 'we_new', 'secret' => 'whsec_new', 'status' => 'enabled']];
$res = $post(['action' => 'st_connect', 'mode' => 'test', 'source' => 'own', 'secret' => 'sk_test_x1234567']);
ok('connect: 303 back to settings with ok code', $res['status'] === 303 && str_contains($loc($res), 'tab=settings') && str_contains($loc($res), 'ok=st_connected'));
ok('connect: stored', palette_stripe_settings($conn)['test']['webhook_secret'] === 'whsec_new');
ok('connect: URL carries no secret', !str_contains($loc($res), 'sk_test') && !str_contains($loc($res), 'whsec'));
$r = palette_admin_page($conn, $sess(), ['tab' => 'settings', 'ok' => 'st_connected'], [], 'GET');
ok('settings page never contains a secret', !str_contains($r['body'], 'sk_test_x1234567') && !str_contains($r['body'], 'whsec_'));
ok('settings: Connected pill, key hint, webhook registered, last checked', str_contains($r['body'], 'Connected') && str_contains($r['body'], 'sk_test_…4567')
    && str_contains($r['body'], 'Webhook: registered') && str_contains($r['body'], 'Last checked'));
ok('settings: ok flash shows', str_contains($r['body'], 'Stripe is connected.'));
ok('connect action logs without secret', str_contains($logs(), 'st_connect') && !preg_match('/sk_|rk_|whsec_/', $logs()));
$d = json_decode($conn->query("SELECT detail FROM palette_admin_log WHERE action = 'st_connect'")->fetch_row()[0], true);
ok('connect log detail: mode, source, ok', ($d['mode'] ?? '') === 'test' && ($d['source'] ?? '') === 'own' && ($d['ok'] ?? null) === true);

// ---- connect failures: error shown from session, escaped, masked, not in URL
$GLOBALS['st_routes']['GET /v1/balance'] = ['status' => 401, 'json' => ['error' => ['message' => 'Invalid API Key provided: sk_test_****<b>z']]];
$res = $post(['action' => 'st_connect', 'mode' => 'test', 'source' => 'own', 'secret' => 'sk_test_bad99999']);
ok('connect failure: redirects, no free text in URL', $res['status'] === 303 && !str_contains(urldecode($loc($res)), 'Invalid'));
$r = $get();
ok('connect failure: message shown, escaped, no secret', str_contains($r['body'], 'Invalid API Key') && !str_contains($r['body'], '<b>z') && !str_contains($r['body'], 'sk_test_bad99999'));
ok('connect failure: error pill', str_contains($r['body'], 'Error'));
ok('connect failure: flash gone on next load, card keeps stored error', substr_count($get()['body'], 'Invalid API Key') === 1);
ok('connect failure log has no secret', !preg_match('/sk_|rk_|whsec_/', $logs()));
$res = $post(['action' => 'st_connect', 'mode' => 'test', 'source' => 'own', 'secret' => 'sk_live_wrong']);
ok('connect wrong-prefix: error shown', str_contains($get()['body'], 'not a test-mode'));
$res = $post(['action' => 'st_connect', 'mode' => 'bogus', 'source' => 'own', 'secret' => 'x']);
ok('connect bad mode: err code', str_contains($loc($res), 'err=st_bad_mode'));

// ---- check renders six lines
st_stub_reset($conn);
st_stub_connect($conn, 'test');
$GLOBALS['st_routes']['GET /v1/balance'] = $balance(false);
$GLOBALS['st_routes']['GET /v1/webhook_endpoints/we_test'] = ['status' => 200, 'json' => ['status' => 'enabled', 'url' => palette_stripe_webhook_url(), 'enabled_events' => PALETTE_STRIPE_EVENTS]];
$_SESSION = [];
$res = $post(['action' => 'st_check', 'mode' => 'test']);
ok('check: redirects without result in URL', $res['status'] === 303 && str_contains($loc($res), 'tab=settings') && !str_contains($loc($res), 'Key'));
$r = $get();
ok('check renders six lines', substr_count($r['body'], 'class="check-line') === 6 && str_contains($r['body'], 'Key accepted by Stripe') && str_contains($r['body'], 'Webhook sends every needed event'));
ok('check: pass icons and no fail', substr_count($r['body'], 'check-pass') === 6 && !str_contains($r['body'], 'check-fail'));
ok('check: shown once', !str_contains($get()['body'], 'Key accepted by Stripe'));
$GLOBALS['st_routes']['GET /v1/webhook_endpoints/we_test'] = ['status' => 404, 'json' => ['error' => ['message' => 'No such']]];
$post(['action' => 'st_check', 'mode' => 'test']);
$r = $get();
ok('check: failing lines marked', str_contains($r['body'], 'check-fail') && str_contains($r['body'], 'check-pass'));
ok('check logs, no secret', str_contains($logs(), 'st_check') && !preg_match('/sk_|rk_|whsec_/', $logs()));

// ---- disconnect needs confirm
st_stub_reset($conn);
st_stub_connect($conn, 'live');
$GLOBALS['st_routes']['DELETE /v1/webhook_endpoints/we_live'] = ['status' => 200, 'json' => ['deleted' => true]];
$_SESSION = [];
$res = $post(['action' => 'st_disconnect', 'mode' => 'live']);
ok('disconnect without confirm: nothing called, still connected', palette_stripe_ready($conn, 'live') && count($GLOBALS['st_calls']) === 0);
$r = $get();
ok('disconnect asks to confirm', str_contains($r['body'], 'Disconnect Live? Checkout stops taking card payments.') && str_contains($r['body'], 'name="confirm"'));
ok('confirm step shown once', !str_contains($get()['body'], 'Disconnect Live? Checkout'));
$res = $post(['action' => 'st_disconnect', 'mode' => 'live', 'confirm' => '1']);
ok('disconnect confirmed: cleared, ok code', !palette_stripe_ready($conn, 'live') && str_contains($loc($res), 'ok=st_disconnected') && palette_stripe_settings($conn)['live']['webhook_id'] === '');
ok('disconnect logged without secret', str_contains($logs(), 'st_disconnect') && !preg_match('/sk_|rk_|whsec_/', $logs()));

st_stub_reset($conn);
st_stub_connect($conn, 'live');
$GLOBALS['st_routes']['DELETE /v1/webhook_endpoints/we_live'] = ['status' => 403, 'json' => ['error' => ['message' => 'no permission']]];
$res = $post(['action' => 'st_disconnect', 'mode' => 'live', 'confirm' => '1']);
ok('disconnect refused by Stripe: err code, nothing changed', str_contains($loc($res), 'err=st_disconnect_failed') && palette_stripe_ready($conn, 'live'));
$r = palette_admin_page($conn, $sess(), ['tab' => 'settings', 'err' => 'st_disconnect_failed'], [], 'GET');
ok('disconnect failure message', str_contains($r['body'], 'Stripe refused or failed; nothing was changed. Try again or check the key') && str_contains($r['body'], 'webhook permission.'));

// ---- methods
st_stub_reset($conn);
$_SESSION = [];
$res = $post(['action' => 'methods', 'methods' => ['stripe', 'paypal'], 'order' => 'paypal_first']);
ok('methods refuse unconnected', $res['status'] === 303 && !str_contains($loc($res), 'ok=') && palette_stripe_settings($conn)['checkout_methods'] === ['stripe']);
ok('methods refusal shows the reason', str_contains($get()['body'], 'Stripe is not connected in live mode.'));
$res = $post(['action' => 'methods', 'order' => 'stripe_first']);
ok('methods none chosen refused', str_contains($get()['body'], 'Choose at least one payment method.'));
st_stub_connect($conn, 'live');
$res = $post(['action' => 'methods', 'methods' => ['stripe'], 'order' => 'stripe_first']);
ok('methods saved', str_contains($loc($res), 'ok=methods_saved') && palette_stripe_settings($conn)['checkout_methods'] === ['stripe']);
pp_settings_put($conn, ['enabled' => true, 'client_id' => 'cid', 'secret' => 'psec', 'error' => ''] + pp_settings($conn));
$post(['action' => 'methods', 'methods' => ['stripe', 'paypal'], 'order' => 'paypal_first']);
ok('methods order: PayPal first', palette_stripe_settings($conn)['checkout_methods'] === ['paypal', 'stripe']);
ok('methods form reflects saved order', preg_match('/value="paypal_first" selected/', $get()['body']) === 1);
$post(['action' => 'methods', 'methods' => ['stripe', 'paypal'], 'order' => 'stripe_first']);
ok('methods order: Stripe first', palette_stripe_settings($conn)['checkout_methods'] === ['stripe', 'paypal']);
ok('methods logged without secret', str_contains($logs(), '"action":"methods"') && !preg_match('/sk_|rk_|whsec_/', $logs()));

// ---- csrf still applies
$r = palette_admin_page($conn, $sess(), [], ['action' => 'st_check', 'mode' => 'test', 'csrf' => 'bad'], 'POST');
ok('settings actions need csrf', $r['status'] === 403);

// ---- cleanup
st_stub_reset($conn);
$conn->query("DELETE FROM admin_settings WHERE setting_key = 'paypal'");
$conn->query('DELETE FROM palette_admin_log');
$_SESSION = [];
