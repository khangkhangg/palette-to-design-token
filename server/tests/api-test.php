<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/pp-stub.php';
ini_set('error_log', sys_get_temp_dir() . '/palette-test.log');
// CLI has no session; start one once (output buffered) so companies' CSRF helpers use $_SESSION as-is.
ob_start();
session_start();

const EXT = 'abcdefghijklmnopabcdefghijklmnop';
const OTHER_EXT = 'ponmlkjihgfedcbaponmlkjihgfedcba';

function api_reset(mysqli $c): void
{
    stub_reset($c);
    $c->query('TRUNCATE api_rate_limits');
    palette_settings_put($c, ['extension_ids' => [EXT]]);
    $_SESSION = [];
}

function call(string $method, string $action, array $post = [], array $over = []): array
{
    return palette_api_handle($over + ['method' => $method, 'action' => $action, 'post' => $post,
        'origin' => '', 'ip' => '203.0.113.9', 'session' => $_SESSION]);
}

function csrf(): string
{
    return generateCSRFToken('palette_checkout');
}

function lic(mysqli $c, string $email = 'buyer@studio.vn'): string
{
    return palette_issue_license($c, $email, 'ORD-' . mt_rand(), 'en')['key'];
}

function acao(array $r): ?string
{
    return $r['headers']['Access-Control-Allow-Origin'] ?? null;
}

// ---- headers on every response
api_reset($conn);
$r = call('GET', 'config');
ok('json headers', ($r['headers']['Content-Type'] ?? '') === 'application/json; charset=utf-8'
    && ($r['headers']['Cache-Control'] ?? '') === 'no-store' && ($r['headers']['X-Content-Type-Options'] ?? '') === 'nosniff');
$r = call('GET', 'nope');
ok('unknown action 404', $r['status'] === 404 && $r['body']['error'] === 'err_unknown_action' && ($r['headers']['Cache-Control'] ?? '') === 'no-store');
ok('405 GET on POST action', call('GET', 'create')['status'] === 405 && call('GET', 'verify')['status'] === 405);
ok('405 POST on config', ($r = call('POST', 'config'))['status'] === 405 && $r['body']['error'] === 'err_method');

// ---- config
$r = call('GET', 'config');
$b = $r['body'];
ok('config ready', $r['status'] === 200 && $b['ok'] === true && $b['enabled'] === true && $b['client_id'] === 'cid'
    && $b['price'] === '1.49' && $b['tax_mode'] === 'none' && preg_match('/^[0-9a-f]{64}$/', $b['csrf']) === 1);
ok('config csrf is the session token', $b['csrf'] === $_SESSION['csrf_tokens']['palette_checkout']);
$b2 = call('GET', 'config')['body'];
ok('config reuses the session token (no rotation)', $b2['csrf'] === $b['csrf']);
ok('an earlier page\'s token still verifies', verifyCSRFToken($b['csrf'], 'palette_checkout'));
$_SESSION = [];
$b3 = call('GET', 'config')['body'];
ok('config generates a token when missing', preg_match('/^[0-9a-f]{64}$/', $b3['csrf']) === 1 && $b3['csrf'] !== $b['csrf']);
ok('config never leaks the secret', !str_contains(json_encode($r), '"sec"') && !isset($b['secret']));
stub_paypal($conn, false);
$b = call('GET', 'config')['body'];
ok('config paused: disabled, no client_id', $b['enabled'] === false && !array_key_exists('client_id', $b));
stub_paypal($conn, true);
palette_settings_put($conn, ['tax_mode' => 'ca']);
ok('config tax_mode ca', call('GET', 'config')['body']['tax_mode'] === 'ca');
palette_settings_put($conn, ['tax_mode' => 'none']);

// ---- CSRF
api_reset($conn);
$good = ['email' => 'buyer@studio.vn', 'consent' => '1', 'lang' => 'es'];
ok('create without csrf -> 419', ($r = call('POST', 'create', $good))['status'] === 419 && $r['body']['error'] === 'err_csrf' && $r['body']['ok'] === false);
csrf();
ok('create bad csrf -> 419', call('POST', 'create', $good + ['csrf' => 'wrong'])['status'] === 419);
ok('capture bad csrf -> 419', call('POST', 'capture', ['order_id' => 'X', 'csrf' => 'wrong'])['status'] === 419);
ok('resend bad csrf -> 419', call('POST', 'resend', ['email' => 'a@b.co', 'csrf' => 'wrong'])['status'] === 419);
ok('claim bad csrf -> 419', call('POST', 'claim', ['key' => 'SDT-0000-0000-0000', 'csrf' => 'wrong'], ['session' => ['user_id' => 5] + $_SESSION])['status'] === 419);
ok('csrf must be a string', call('POST', 'create', $good + ['csrf' => ['x']])['status'] === 419);

// ---- create
api_reset($conn);
$t = csrf();
$GLOBALS['pp_routes']['POST /v2/checkout/orders'] = ['status' => 201, 'json' => ['id' => 'PPORD1', 'status' => 'CREATED']];
$r = call('POST', 'create', $good + ['csrf' => $t, 'country' => 'US', 'region' => 'CA']);
ok('create ok', $r['status'] === 200 && $r['body'] === ['ok' => true, 'order_id' => 'PPORD1', 'total' => '1.49']);
ok('create stored order in request lang', (order_row($conn, 'PPORD1')['lang'] ?? '') === 'es');
$r = call('POST', 'create', ['email' => 'nope', 'consent' => '1', 'csrf' => $t]);
ok('create bad email -> 400 localised', $r['status'] === 400 && $r['body']['error'] === 'err_email' && $r['body']['message'] === palette_t('en', 'err_email'));
$r = call('POST', 'create', ['email' => 'bad', 'consent' => '1', 'lang' => 'fr', 'csrf' => $t]);
ok('message uses request lang', $r['body']['message'] === palette_t('fr', 'err_email'));
$r = call('POST', 'create', ['email' => 'a@b.co', 'consent' => '0', 'csrf' => $t]);
ok('create no consent -> 400', $r['status'] === 400 && $r['body']['error'] === 'err_consent');
$r = call('POST', 'create', ['email' => ['x'], 'consent' => '1', 'csrf' => $t]);
ok('create array email -> 400', $r['status'] === 400);
stub_paypal($conn, false);
$r = call('POST', 'create', $good + ['csrf' => $t]);
ok('create paused -> 503', $r['status'] === 503 && $r['body']['error'] === 'err_paused');
stub_paypal($conn, true);
unset($GLOBALS['pp_routes']['POST /v2/checkout/orders']);
$r = call('POST', 'create', $good + ['csrf' => $t]);
ok('create paypal failure -> 502', $r['status'] === 502 && $r['body']['error'] === 'err_paypal');

// ---- capture
api_reset($conn);
$t = csrf();
make_order($conn, 'PPORD2');
$GLOBALS['pp_routes']['POST /v2/checkout/orders/PPORD2/capture'] = ['status' => 201, 'json' => pp_completed('PPORD2')];
$r = call('POST', 'capture', ['order_id' => 'PPORD2', 'csrf' => $t]);
ok('capture ok', $r['status'] === 200 && $r['body']['ok'] === true && preg_match(PALETTE_KEY_RE, $r['body']['key']) === 1
    && $r['body']['email_masked'] === 'b***@studio.vn');
ok('capture body has only ok/key/email_masked', array_keys($r['body']) === ['ok', 'key', 'email_masked']);
ok('capture missing order_id -> 400', call('POST', 'capture', ['csrf' => $t])['status'] === 400);
$r = call('POST', 'capture', ['order_id' => 'NOT-AN-ORDER!', 'csrf' => $t]);
ok('capture bad order id -> 502 err_paypal', $r['status'] === 502 && $r['body']['error'] === 'err_paypal');
make_order($conn, 'PPORD3');
$GLOBALS['pp_routes']['POST /v2/checkout/orders/PPORD3/capture'] = ['status' => 503, 'json' => []];
$r = call('POST', 'capture', ['order_id' => 'PPORD3', 'csrf' => $t, 'lang' => 'es']);
ok('capture retry -> 503 err_retry', $r['status'] === 503 && $r['body']['ok'] === false && $r['body']['error'] === 'err_retry'
    && $r['body']['message'] === palette_t('es', 'err_retry') && !isset($r['body']['retry']));

// ---- resend
api_reset($conn);
$t = csrf();
lic($conn, 'known@studio.vn');
$GLOBALS['mails'] = [];
$known = call('POST', 'resend', ['email' => 'known@studio.vn', 'csrf' => $t]);
ok('resend known: 200 generic, defer returned, nothing sent yet', $known['status'] === 200
    && $known['body'] === ['ok' => true, 'message' => palette_t('en', 'api_resend_ok')]
    && is_callable($known['defer'] ?? null) && $GLOBALS['mails'] === []);
$known['defer']();
ok('running defer sends the mail', count($GLOBALS['mails']) === 1);
$unknown = call('POST', 'resend', ['email' => 'nobody@studio.vn', 'csrf' => $t]);
ok('resend unknown: identical response', $unknown['status'] === $known['status'] && $unknown['body'] === $known['body'] && $unknown['headers'] === $known['headers']);
$GLOBALS['mails'] = [];
$unknown['defer']();
ok('unknown address: defer sends nothing', $GLOBALS['mails'] === []);
$bad = call('POST', 'resend', ['email' => 'not an email', 'csrf' => $t]);
ok('resend invalid input: same 200 body', $bad['status'] === 200 && $bad['body'] === $known['body'] && is_callable($bad['defer'] ?? null));
$arr = call('POST', 'resend', ['email' => ['x'], 'csrf' => $t]);
ok('resend array input: same 200 body', $arr['status'] === 200 && $arr['body'] === $known['body']);
$arr['defer']();
$es = call('POST', 'resend', ['email' => 'x@y.co', 'lang' => 'es', 'csrf' => $t]);
ok('resend message localised', $es['body']['message'] === palette_t('es', 'api_resend_ok'));
ok('no defer on non-resend', !array_key_exists('defer', call('GET', 'config')));

// ---- claim
api_reset($conn);
$t = csrf();
$k = lic($conn);
$r = call('POST', 'claim', ['key' => $k, 'csrf' => $t]);
ok('claim without login -> 403 err_login', $r['status'] === 403 && $r['body']['error'] === 'err_login');
$S = ['user_id' => 77] + $_SESSION;
$r = call('POST', 'claim', ['key' => strtolower($k), 'csrf' => $t], ['session' => $S]);
ok('claim ok', $r['status'] === 200 && $r['body'] === ['ok' => true]);
ok('claim linked user', (int)$conn->query("SELECT user_id FROM palette_licenses WHERE license_key='$k'")->fetch_row()[0] === 77);
$r = call('POST', 'claim', ['key' => 'SDT-0000-0000-0000', 'csrf' => $t], ['session' => $S]);
ok('claim unknown key -> 400 err_invalid_key', $r['status'] === 400 && $r['body']['error'] === 'err_invalid_key');
$r = call('POST', 'claim', ['key' => $k, 'csrf' => $t], ['session' => ['user_id' => 78] + $_SESSION]);
ok('claim owned by other user -> 400', $r['status'] === 400);
$r = call('POST', 'claim', ['key' => $k, 'csrf' => $t], ['session' => ['user_id' => 'abc'] + $_SESSION]);
ok('claim non-numeric user -> 403', $r['status'] === 403);

// ---- verify + CORS
api_reset($conn);
$k = lic($conn);
$origin = 'chrome-extension://' . EXT;
$r = call('POST', 'verify', ['key' => $k], ['origin' => $origin]);
ok('verify valid (no csrf needed)', $r['status'] === 200 && $r['body'] === ['ok' => true, 'valid' => true, 'revoked' => false, 'email_masked' => 'b***@studio.vn']);
ok('verify allowed origin: ACAO + Vary', acao($r) === $origin && ($r['headers']['Vary'] ?? '') === 'Origin');
$r = call('POST', 'verify', ['key' => 'SDT-0000-0000-0000'], ['origin' => 'chrome-extension://' . OTHER_EXT]);
ok('verify unknown key: valid false; other ext no ACAO', $r['status'] === 200 && $r['body']['valid'] === false && acao($r) === null);
$r = call('POST', 'verify', ['key' => $k], ['origin' => 'https://evil.example']);
ok('web origin: processed, no ACAO', $r['status'] === 200 && $r['body']['valid'] === true && acao($r) === null);
ok('no origin: no ACAO', acao(call('POST', 'verify', ['key' => $k])) === null);
$conn->query('UPDATE palette_licenses SET revoked_at = NOW()');
$r = call('POST', 'verify', ['key' => $k]);
ok('verify revoked', $r['body']['valid'] === false && $r['body']['revoked'] === true);
ok('verify non-string key', call('POST', 'verify', ['key' => ['x']])['body']['valid'] === false);

// extension_ids validation: junk entries never match
palette_settings_put($conn, ['extension_ids' => ['*', 'short', 'ABCDEFGHIJKLMNOPABCDEFGHIJKLMNOP', 7, EXT . 'a', EXT]]);
ok('only valid ids honoured', acao(call('POST', 'verify', ['key' => $k], ['origin' => $origin])) === $origin
    && acao(call('POST', 'verify', ['key' => $k], ['origin' => 'chrome-extension://*'])) === null);
$conn->query("UPDATE admin_settings SET setting_value = '{\"extension_ids\":\"notalist\"}' WHERE setting_key='palette'");
ok('non-array extension_ids -> no ACAO', acao(call('POST', 'verify', ['key' => $k], ['origin' => $origin])) === null);
palette_settings_put($conn, ['extension_ids' => [EXT]]);

// preflight
$r = call('OPTIONS', 'verify', [], ['origin' => $origin]);
ok('preflight 204 with CORS headers', $r['status'] === 204 && acao($r) === $origin
    && $r['headers']['Access-Control-Allow-Methods'] === 'POST' && $r['headers']['Access-Control-Allow-Headers'] === 'Content-Type'
    && $r['headers']['Access-Control-Max-Age'] === '600' && $r['headers']['Vary'] === 'Origin');
$r = call('OPTIONS', 'verify', [], ['origin' => 'chrome-extension://' . OTHER_EXT]);
ok('preflight from unlisted ext: 204 without ACAO', $r['status'] === 204 && acao($r) === null);
ok('OPTIONS on other action -> 405', call('OPTIONS', 'create', [], ['origin' => $origin])['status'] === 405);

// rate limit
api_reset($conn);
for ($i = 0; $i < 30; $i++) call('POST', 'verify', ['key' => 'SDT-0000-0000-0000']);
$r = call('POST', 'verify', ['key' => 'SDT-0000-0000-0000'], ['origin' => $origin]);
ok('31st verify -> 429 api_rate_limited', $r['status'] === 429 && $r['body']['ok'] === false && $r['body']['error'] === 'api_rate_limited'
    && $r['body']['message'] === palette_t('en', 'api_rate_limited'));
ok('429 still carries CORS headers (extension can read it)', acao($r) === $origin);
ok('other IP unaffected', call('POST', 'verify', ['key' => 'SDT-0000-0000-0000'], ['ip' => '198.51.100.1'])['status'] === 200);

// ---- no secrets anywhere, JSON-serialisable
api_reset($conn);
$enc = json_encode([call('GET', 'config'), call('POST', 'verify', ['key' => 'x']), call('GET', 'nope')]);
ok('all bodies json-serialisable, no secret/resend key', $enc !== false && !str_contains($enc, '"sec"') && !str_contains($enc, 're_test'));
