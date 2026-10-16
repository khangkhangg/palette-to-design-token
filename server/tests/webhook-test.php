<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/pp-stub.php';
ini_set('error_log', sys_get_temp_dir() . '/palette-test.log');

const HDRS = ['paypal-auth-algo' => 'SHA256withRSA', 'paypal-cert-url' => 'https://api.paypal.com/cert.pem',
              'paypal-transmission-id' => 'tid-1', 'paypal-transmission-sig' => 'sig==', 'paypal-transmission-time' => '2026-10-16T00:00:00Z'];

function verified(bool $ok): void
{
    $GLOBALS['pp_routes']['POST /v1/notifications/verify-webhook-signature'] = ['status' => 200, 'json' => ['verification_status' => $ok ? 'SUCCESS' : 'FAILURE']];
}

function hook_reset(mysqli $c): void
{
    stub_reset($c);
    palette_settings_put($c, ['webhook_id' => 'WH-PALETTE', 'webhook_mode' => 'sandbox']);
    verified(true);
}

function ev(string $type, array $resource): string
{
    return json_encode(['id' => 'WH-EV-' . mt_rand(), 'event_type' => $type, 'resource' => $resource]);
}

function capture_resource(string $orderId, string $customId, string $capId = 'CAP1', string $value = '1.49'): array
{
    return ['id' => $capId, 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => $value], 'custom_id' => $customId,
            'supplementary_data' => ['related_ids' => ['order_id' => $orderId]]];
}

function cid(mysqli $c, string $orderId): string
{
    return 'palette:' . order_row($c, $orderId)['id'];
}

// ---- signature
hook_reset($conn);
make_order($conn, 'ORD-1');
verified(false);
$GLOBALS['pp_calls'] = [];
ok('failed signature -> unverified', palette_webhook($conn, HDRS, ev('PAYMENT.CAPTURE.COMPLETED', capture_resource('ORD-1', cid($conn, 'ORD-1')))) === 'unverified');
ok('unverified: no change', order_row($conn, 'ORD-1')['status'] === 'created' && license_count($conn) === 0 && $GLOBALS['mails'] === []);
$vb = calls_to('POST', '/v1/notifications/verify-webhook-signature')[0]['body'] ?? [];
ok('verify request carries all 5 headers + webhook_id + event', ($vb['auth_algo'] ?? '') === 'SHA256withRSA' && ($vb['cert_url'] ?? '') === HDRS['paypal-cert-url']
    && ($vb['transmission_id'] ?? '') === 'tid-1' && ($vb['transmission_sig'] ?? '') === 'sig==' && ($vb['transmission_time'] ?? '') === HDRS['paypal-transmission-time']
    && ($vb['webhook_id'] ?? '') === 'WH-PALETTE' && ($vb['webhook_event']['event_type'] ?? '') === 'PAYMENT.CAPTURE.COMPLETED');
$GLOBALS['pp_routes']['POST /v1/notifications/verify-webhook-signature'] = ['status' => 500, 'json' => []];
ok('verify HTTP error -> unverified', palette_webhook($conn, HDRS, ev('PAYMENT.CAPTURE.COMPLETED', capture_resource('ORD-1', cid($conn, 'ORD-1')))) === 'unverified');
verified(true);
ok('bad body', palette_webhook($conn, HDRS, 'not json') === 'bad body');
palette_settings_put($conn, ['webhook_id' => '']);
ok('no webhook_id -> not configured, no verify call', palette_webhook($conn, HDRS, ev('X', [])) === 'not configured');
palette_settings_put($conn, ['webhook_id' => 'WH-PALETTE']);

// ---- PAYMENT.CAPTURE.COMPLETED
$r = palette_webhook($conn, HDRS, ev('PAYMENT.CAPTURE.COMPLETED', capture_resource('ORD-1', cid($conn, 'ORD-1'))));
ok('capture completed -> fulfilled', $r === 'fulfilled');
ok('fulfilled: one license + one email', license_count($conn) === 1 && count($GLOBALS['mails']) === 1 && order_row($conn, 'ORD-1')['status'] === 'captured');
ok('repeat event -> already', palette_webhook($conn, HDRS, ev('PAYMENT.CAPTURE.COMPLETED', capture_resource('ORD-1', cid($conn, 'ORD-1')))) === 'already'
    && license_count($conn) === 1 && count($GLOBALS['mails']) === 1);

// fallback to custom_id when related order id is missing
make_order($conn, 'ORD-2');
$res = capture_resource('ORD-2', cid($conn, 'ORD-2'), 'CAP2');
unset($res['supplementary_data']);
ok('custom_id fallback -> fulfilled', palette_webhook($conn, HDRS, ev('PAYMENT.CAPTURE.COMPLETED', $res)) === 'fulfilled' && order_row($conn, 'ORD-2')['capture_id'] === 'CAP2');

// mismatch between related order id and custom_id
make_order($conn, 'ORD-3');
ok('order/custom_id mismatch -> unknown order', palette_webhook($conn, HDRS, ev('PAYMENT.CAPTURE.COMPLETED', capture_resource('ORD-3', cid($conn, 'ORD-1')))) === 'unknown order'
    && order_row($conn, 'ORD-3')['status'] === 'created');
ok('unknown palette order', palette_webhook($conn, HDRS, ev('PAYMENT.CAPTURE.COMPLETED', capture_resource('ORD-NONE', 'palette:999999'))) === 'unknown order');
ok('underpaid via webhook', palette_webhook($conn, HDRS, ev('PAYMENT.CAPTURE.COMPLETED', capture_resource('ORD-3', cid($conn, 'ORD-3'), 'CAP3', '1.00'))) === 'fulfil failed'
    && order_row($conn, 'ORD-3')['status'] === 'failed' && license_count($conn) === 2);

// companies' events on the shared PayPal app
$n = count($GLOBALS['pp_calls']);
ok('companies capture -> ignored', palette_webhook($conn, HDRS, ev('PAYMENT.CAPTURE.COMPLETED', capture_resource('ORD-1', 'premium:3:12'))) === 'ignored');
ok('companies approval -> ignored', palette_webhook($conn, HDRS, ev('CHECKOUT.ORDER.APPROVED', ['id' => 'CO-1', 'purchase_units' => [['custom_id' => 'ad:5:9']]])) === 'ignored');
ok('companies refund -> ignored', palette_webhook($conn, HDRS, ev('PAYMENT.CAPTURE.REFUNDED', ['id' => 'RF', 'custom_id' => 'premium:1:2'])) === 'ignored');
ok('ignored made no capture call', calls_to('POST', '/v2/checkout/orders/') === []);
ok('other event type -> ignored', palette_webhook($conn, HDRS, ev('BILLING.SUBSCRIPTION.CREATED', [])) === 'ignored');

// ---- page capture then webhook, and webhook then page capture: one license each
hook_reset($conn);
make_order($conn, 'ORD-PW');
$GLOBALS['pp_routes']['POST /v2/checkout/orders/ORD-PW/capture'] = ['status' => 201, 'json' => pp_completed('ORD-PW', '1.49', 'USD', 'CPW')];
$page = palette_capture($conn, 'ORD-PW');
$hook = palette_webhook($conn, HDRS, ev('PAYMENT.CAPTURE.COMPLETED', capture_resource('ORD-PW', cid($conn, 'ORD-PW'), 'CPW')));
ok('page then webhook: one license, one email', $page['ok'] && $hook === 'already' && license_count($conn) === 1 && count($GLOBALS['mails']) === 1);

make_order($conn, 'ORD-WP');
$hook = palette_webhook($conn, HDRS, ev('PAYMENT.CAPTURE.COMPLETED', capture_resource('ORD-WP', cid($conn, 'ORD-WP'), 'CWP')));
$page = palette_capture($conn, 'ORD-WP');
ok('webhook then page: one license, one email, page gets key', $hook === 'fulfilled' && $page['ok'] && license_count($conn) === 2 && count($GLOBALS['mails']) === 2
    && $page['key'] === $conn->query("SELECT license_key FROM palette_licenses WHERE order_id = 'ORD-WP'")->fetch_row()[0]);
ok('webhook then page: no capture call', calls_to('POST', '/v2/checkout/orders/ORD-WP/capture') === []);

// ---- CHECKOUT.ORDER.APPROVED captures
make_order($conn, 'ORD-AP');
$GLOBALS['pp_routes']['POST /v2/checkout/orders/ORD-AP/capture'] = ['status' => 201, 'json' => pp_completed('ORD-AP', '1.49', 'USD', 'CAP-AP')];
$appr = ['id' => 'ORD-AP', 'status' => 'APPROVED', 'purchase_units' => [['custom_id' => cid($conn, 'ORD-AP')]]];
ok('approved -> captured', palette_webhook($conn, HDRS, ev('CHECKOUT.ORDER.APPROVED', $appr)) === 'captured' && order_row($conn, 'ORD-AP')['status'] === 'captured');
ok('approved again -> already, no 2nd capture', palette_webhook($conn, HDRS, ev('CHECKOUT.ORDER.APPROVED', $appr)) === 'already'
    && count(calls_to('POST', '/v2/checkout/orders/ORD-AP/capture')) === 1);
ok('approved unknown palette order', palette_webhook($conn, HDRS, ev('CHECKOUT.ORDER.APPROVED', ['id' => 'ORD-ZZ', 'purchase_units' => [['custom_id' => 'palette:1']]])) === 'unknown order');

// ---- REFUNDED / REVERSED revoke
$keyAp = $conn->query("SELECT license_key FROM palette_licenses WHERE order_id = 'ORD-AP'")->fetch_row()[0];
$refund = ['id' => 'RF-1', 'status' => 'COMPLETED', 'custom_id' => cid($conn, 'ORD-AP'),
           'links' => [['rel' => 'up', 'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAP-AP', 'method' => 'GET']]];
ok('refunded -> revoked', palette_webhook($conn, HDRS, ev('PAYMENT.CAPTURE.REFUNDED', $refund)) === 'revoked');
ok('refunded: license revoked', palette_verify($conn, $keyAp, '10.9.0.1')['revoked'] === true);
ok('other licenses untouched', (int)$conn->query('SELECT COUNT(*) FROM palette_licenses WHERE revoked_at IS NOT NULL')->fetch_row()[0] === 1);

$keyPw = $conn->query("SELECT license_key FROM palette_licenses WHERE order_id = 'ORD-PW'")->fetch_row()[0];
$reversed = ['id' => 'CPW', 'status' => 'REVERSED', 'custom_id' => cid($conn, 'ORD-PW'), 'amount' => ['currency_code' => 'USD', 'value' => '1.49']];
ok('reversed (resource = capture) -> revoked', palette_webhook($conn, HDRS, ev('PAYMENT.CAPTURE.REVERSED', $reversed)) === 'revoked'
    && palette_verify($conn, $keyPw, '10.9.0.1')['revoked'] === true);

$keyWp = $conn->query("SELECT license_key FROM palette_licenses WHERE order_id = 'ORD-WP'")->fetch_row()[0];
$rel = ['id' => 'RF-2', 'custom_id' => cid($conn, 'ORD-WP'), 'supplementary_data' => ['related_ids' => ['order_id' => 'ORD-WP']]];
ok('refund via related order id -> revoked', palette_webhook($conn, HDRS, ev('PAYMENT.CAPTURE.REFUNDED', $rel)) === 'revoked'
    && palette_verify($conn, $keyWp, '10.9.0.1')['revoked'] === true);
ok('refund for unknown palette capture', palette_webhook($conn, HDRS, ev('PAYMENT.CAPTURE.REFUNDED', ['id' => 'RF-3', 'custom_id' => 'palette:999999',
    'links' => [['rel' => 'up', 'href' => 'https://api-m.paypal.com/v2/payments/captures/NOPE']]])) === 'unknown order');

// ---- palette_setup_webhook
hook_reset($conn);
palette_settings_put($conn, ['webhook_id' => '', 'webhook_mode' => '']);
$GLOBALS['pp_routes']['GET /v1/notifications/webhooks'] = ['status' => 200, 'json' => ['webhooks' => [
    ['id' => 'WH-COMPANIES', 'url' => 'https://www.vietnamcos.com/companies/api/paypal-webhook.php'],
    ['id' => 'WH-EXISTING', 'url' => PALETTE_WEBHOOK_URL]]]];
$s = palette_setup_webhook($conn);
ok('setup reuses existing webhook', ($s['ok'] ?? false) === true && ($s['webhook_id'] ?? '') === 'WH-EXISTING' && calls_to('POST', '/v1/notifications/webhooks') === []);
$ps = palette_settings($conn);
ok('setup stores id + mode', $ps['webhook_id'] === 'WH-EXISTING' && $ps['webhook_mode'] === 'sandbox');

hook_reset($conn);
$GLOBALS['pp_routes']['GET /v1/notifications/webhooks'] = ['status' => 200, 'json' => ['webhooks' => [['id' => 'WH-COMPANIES', 'url' => 'https://www.vietnamcos.com/companies/api/paypal-webhook.php']]]];
$GLOBALS['pp_routes']['POST /v1/notifications/webhooks'] = ['status' => 201, 'json' => ['id' => 'WH-NEW']];
$s = palette_setup_webhook($conn);
$cb = calls_to('POST', '/v1/notifications/webhooks')[0]['body'] ?? [];
ok('setup creates webhook', ($s['ok'] ?? false) === true && ($s['webhook_id'] ?? '') === 'WH-NEW' && palette_settings($conn)['webhook_id'] === 'WH-NEW');
ok('setup create body', ($cb['url'] ?? '') === 'https://www.vietnamcos.com/palette/webhook.php' && ($cb['event_types'] ?? null) === [
    ['name' => 'CHECKOUT.ORDER.APPROVED'], ['name' => 'PAYMENT.CAPTURE.COMPLETED'], ['name' => 'PAYMENT.CAPTURE.REFUNDED'], ['name' => 'PAYMENT.CAPTURE.REVERSED']]);

hook_reset($conn);
palette_settings_put($conn, ['webhook_id' => 'OLD']);
$GLOBALS['pp_routes']['GET /v1/notifications/webhooks'] = ['status' => 200, 'json' => ['webhooks' => []]];
$GLOBALS['pp_routes']['POST /v1/notifications/webhooks'] = ['status' => 400, 'json' => ['message' => 'bad']];
ok('setup create failure -> err_paypal, id kept', palette_setup_webhook($conn) === ['ok' => false, 'error' => 'err_paypal'] && palette_settings($conn)['webhook_id'] === 'OLD');
$conn->query("UPDATE admin_settings SET setting_value = '{\"enabled\":false,\"client_id\":\"\",\"secret\":\"\"}' WHERE setting_key = 'paypal'");
$GLOBALS['pp_calls'] = [];
ok('setup without PayPal keys -> err_paused', palette_setup_webhook($conn) === ['ok' => false, 'error' => 'err_paused'] && $GLOBALS['pp_calls'] === []);

stub_reset($conn);
unset($GLOBALS['pp_http']);
$conn->query("DELETE FROM admin_settings WHERE setting_key IN ('palette', 'paypal', 'resend_api_key')");
