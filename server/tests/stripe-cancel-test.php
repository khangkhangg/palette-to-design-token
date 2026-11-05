<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/st-stub.php';
ini_set('error_log', sys_get_temp_dir() . '/palette-stripe-cancel-test.log');
@unlink(sys_get_temp_dir() . '/palette-stripe-cancel-test.log');
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

function sc_sub_session(array $row, string $id, int $start, int $end): array
{
    $sub = ['id' => $id, 'object' => 'subscription', 'status' => 'active', 'currency' => 'usd', 'metadata' => ['palette' => 'sub', 'row' => (string)$row['id']],
        'current_period_start' => $start, 'current_period_end' => $end, 'cancel_at_period_end' => false,
        'items' => ['data' => [['price' => ['unit_amount' => 399, 'currency' => 'usd']]]]];
    return ['id' => $row['stripe_session'], 'object' => 'checkout.session', 'mode' => 'subscription', 'payment_status' => 'paid', 'status' => 'complete',
        'currency' => 'usd', 'customer' => 'cus_' . $id, 'subscription' => $sub, 'client_reference_id' => 'palette:sub:' . $row['id'],
        'metadata' => [], 'livemode' => (int)$row['livemode'] === 1];
}

// A fulfilled Stripe subscription whose current period ends in 5 days. Returns [row, licence key].
function sc_fulfilled(mysqli $c, string $mode = 'live', string $subId = 'sub_1', string $email = 'Buyer@Studio.vn'): array
{
    $GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_' . $mode . '_' . mt_rand(), 'url' => 'https://checkout.stripe.com/c/pay/s']];
    $r = palette_st_sub_start($c, $mode, $email, 'en', true);
    $row = palette_sub_row($c, 'id', $r['row_id']);
    $f = palette_st_fulfil_sub($c, $row, sc_sub_session($row, $subId, time() - 25 * 86400, time() + 5 * 86400));
    if (!$f['ok']) throw new RuntimeException('fixture failed');
    $GLOBALS['st_calls'] = [];
    $GLOBALS['mails'] = [];
    return [palette_sub_row($c, 'id', (int)$row['id']), $f['key']];
}

function sc_invoice(string $id, string $sub, int $paid, int $start, int $end): array
{
    return ['id' => $id, 'object' => 'invoice', 'subscription' => $sub, 'currency' => 'usd', 'amount_paid' => $paid, 'status' => 'paid',
        'status_transitions' => ['paid_at' => $start + 60], 'lines' => ['data' => [['type' => 'subscription', 'period' => ['start' => $start, 'end' => $end]]]]];
}

function sc_stripe_sub(string $id, array $over = []): array
{
    return $over + ['id' => $id, 'object' => 'subscription', 'status' => 'active', 'cancel_at_period_end' => false, 'latest_invoice' => null];
}

function sc_lic(mysqli $c): array
{
    return $c->query('SELECT * FROM palette_licenses ORDER BY id DESC LIMIT 1')->fetch_assoc() ?: [];
}

function sc_log(): string
{
    return (string)@file_get_contents(sys_get_temp_dir() . '/palette-stripe-cancel-test.log');
}

// ---- cancel
sc_reset($conn);
[$row, $key] = sc_fulfilled($conn);
$GLOBALS['st_routes']['POST /v1/subscriptions/sub_1'] = ['status' => 200, 'json' => ['id' => 'sub_1', 'cancel_at_period_end' => true]];
$r = palette_sub_cancel($conn, $key, null, 'buyer@studio.vn');
$call = $GLOBALS['st_calls'][0] ?? [];
ok('cancel stripe sets cancel_at_period_end', $r['ok'] === true && ($call['method'] ?? '') === 'POST' && ($call['path'] ?? '') === '/v1/subscriptions/sub_1'
    && ($call['params']['cancel_at_period_end'] ?? '') === 'true' && count($GLOBALS['st_calls']) === 1
    && palette_sub_row($conn, 'id', (int)$row['id'])['status'] === 'cancelled' && sc_lic($conn)['sub_status'] === 'cancelled'
    && count($GLOBALS['mails']) === 1 && $r['ends_on'] === date('Y-m-d', time() + 5 * 86400));

sc_reset($conn);
[$row, $key] = sc_fulfilled($conn);
$GLOBALS['st_routes']['POST /v1/subscriptions/sub_1'] = ['status' => 500, 'json' => []];
$r = palette_sub_cancel($conn, $key, null, 'buyer@studio.vn');
ok('cancel stripe failure', $r === ['ok' => false, 'error' => 'err_stripe'] && palette_sub_row($conn, 'id', (int)$row['id'])['status'] === 'active' && !$GLOBALS['mails']);
ok('cancel: wrong email refused, Stripe not called again', palette_sub_cancel($conn, $key, null, 'other@x.vn') === ['ok' => false, 'error' => 'err_not_owner'] && count($GLOBALS['st_calls']) === 1);

// API: st_portal CSRF form + action wiring
ok('st_portal uses the account CSRF form', palette_api_csrf_form('st_portal') === 'palette_account' && in_array('st_portal', PALETTE_API_POST_ACTIONS, true));

// ---- portal
sc_reset($conn);
[$row, $key] = sc_fulfilled($conn);
$GLOBALS['st_routes']['POST /v1/billing_portal/sessions'] = ['status' => 200, 'json' => ['url' => 'https://billing.stripe.com/p/session/abc']];
$r = palette_st_portal_url($conn, $key, null, 'Buyer@Studio.vn');
$call = $GLOBALS['st_calls'][0] ?? [];
ok('portal owner gets a url', $r === ['ok' => true, 'url' => 'https://billing.stripe.com/p/session/abc'] && ($call['params']['customer'] ?? '') === 'cus_sub_1'
    && ($call['params']['return_url'] ?? '') === 'https://vietnamcos.com/palette/account');
ok('portal owner only', palette_st_portal_url($conn, $key, null, 'x@y.vn') === ['ok' => false, 'error' => 'err_not_owner']
    && palette_st_portal_url($conn, $key, null, null) === ['ok' => false, 'error' => 'err_not_owner']
    && palette_st_portal_url($conn, 'nonsense', null, 'buyer@studio.vn') === ['ok' => false, 'error' => 'err_invalid_key']
    && count($GLOBALS['st_calls']) === 1);
$conn->query("UPDATE palette_licenses SET user_id = 77");
ok('portal by signed-in owner', palette_st_portal_url($conn, $key, 77, null)['ok'] === true && palette_st_portal_url($conn, $key, 78, null)['error'] === 'err_not_owner');
$GLOBALS['st_routes']['POST /v1/billing_portal/sessions'] = ['status' => 400, 'json' => ['error' => ['message' => 'no config']]];
ok('portal stripe failure', palette_st_portal_url($conn, $key, 77, null) === ['ok' => false, 'error' => 'err_stripe']);
$conn->query("UPDATE palette_subscriptions SET provider = 'paypal'");
ok('portal refuses a PayPal subscription', palette_st_portal_url($conn, $key, 77, null)['error'] === 'err_invalid_key');

// ---- reconcile: missed renewal invoice
sc_reset($conn);
[$row, $key] = sc_fulfilled($conn);
$conn->query("UPDATE palette_licenses SET paid_through = '" . date('Y-m-d H:i:s', time() - 3600) . "'");   // period ended an hour ago, renewal webhook lost
$newStart = time() - 3600;
$newEnd = $newStart + 30 * 86400;
$GLOBALS['st_routes']['GET /v1/subscriptions/sub_1'] = ['status' => 200, 'json' => sc_stripe_sub('sub_1', ['latest_invoice' => sc_invoice('in_9', 'sub_1', 399, $newStart, $newEnd)])];
$r = palette_st_reconcile($conn);
$l = sc_lic($conn);
ok('reconcile extends on missed invoice', $r['checked'] === 1 && $r['changed'] === 1 && $l['paid_through'] === gmdate('Y-m-d H:i:s', $newEnd)
    && (int)$conn->query("SELECT COUNT(*) FROM palette_sub_payments WHERE sale_id = 'in_9' AND status = 'completed'")->fetch_row()[0] === 1);
$r = palette_st_reconcile($conn);
ok('reconcile is idempotent', $r['checked'] === 1 && $r['changed'] === 0 && sc_lic($conn)['paid_through'] === $l['paid_through']
    && (int)$conn->query("SELECT COUNT(*) FROM palette_sub_payments")->fetch_row()[0] === 1);

// a suspended row with a paid invoice comes back
sc_reset($conn);
[$row, $key] = sc_fulfilled($conn);
palette_sub_set_status($conn, $row, 'suspended', ['active']);
$GLOBALS['st_routes']['GET /v1/subscriptions/sub_1'] = ['status' => 200, 'json' => sc_stripe_sub('sub_1', ['latest_invoice' => sc_invoice('in_10', 'sub_1', 399, time(), time() + 30 * 86400)])];
$r = palette_st_reconcile($conn);
ok('reconcile reactivates a suspended row on a paid invoice', $r['changed'] === 1 && palette_sub_row($conn, 'id', (int)$row['id'])['status'] === 'active');

// a below-price invoice is not extended
sc_reset($conn);
[$row, $key] = sc_fulfilled($conn);
$before = sc_lic($conn)['paid_through'];
$GLOBALS['st_routes']['GET /v1/subscriptions/sub_1'] = ['status' => 200, 'json' => sc_stripe_sub('sub_1', ['latest_invoice' => sc_invoice('in_low', 'sub_1', 100, time(), time() + 30 * 86400)])];
$r = palette_st_reconcile($conn);
ok('reconcile never extends on a below-price invoice', $r['changed'] === 0 && sc_lic($conn)['paid_through'] === $before);

// ---- reconcile: deleted / past_due / cancel_at_period_end
sc_reset($conn);
[$row, $key] = sc_fulfilled($conn);
$GLOBALS['st_routes']['GET /v1/subscriptions/sub_1'] = ['status' => 200, 'json' => sc_stripe_sub('sub_1', ['status' => 'canceled'])];
$r = palette_st_reconcile($conn);
ok('reconcile marks deleted (still paid: cancelled)', $r['changed'] === 1 && palette_sub_row($conn, 'id', (int)$row['id'])['status'] === 'cancelled' && sc_lic($conn)['sub_status'] === 'cancelled');

sc_reset($conn);
[$row, $key] = sc_fulfilled($conn);
$conn->query("UPDATE palette_licenses SET paid_through = '" . date('Y-m-d H:i:s', time() - 3600) . "'");
$GLOBALS['st_routes']['GET /v1/subscriptions/sub_1'] = ['status' => 200, 'json' => sc_stripe_sub('sub_1', ['status' => 'canceled'])];
palette_st_reconcile($conn);
ok('reconcile marks deleted past paid_through as expired', palette_sub_row($conn, 'id', (int)$row['id'])['status'] === 'expired');

sc_reset($conn);
[$row, $key] = sc_fulfilled($conn);
$GLOBALS['st_routes']['GET /v1/subscriptions/sub_1'] = ['status' => 200, 'json' => sc_stripe_sub('sub_1', ['status' => 'past_due'])];
palette_st_reconcile($conn);
ok('reconcile suspends past_due', palette_sub_row($conn, 'id', (int)$row['id'])['status'] === 'suspended');
$GLOBALS['st_routes']['GET /v1/subscriptions/sub_1'] = ['status' => 200, 'json' => sc_stripe_sub('sub_1', ['cancel_at_period_end' => true])];
palette_sub_set_status($conn, $row, 'active', ['suspended']);
palette_st_reconcile($conn);
ok('reconcile follows cancel_at_period_end', palette_sub_row($conn, 'id', (int)$row['id'])['status'] === 'cancelled');

// ---- reconcile: selection
sc_reset($conn);
[$row, $key] = sc_fulfilled($conn);
$conn->query("UPDATE palette_licenses SET paid_through = '" . date('Y-m-d H:i:s', time() - 5 * 86400) . "'");
$r = palette_st_reconcile($conn);
ok('reconcile skips rows long past paid_through', $r['checked'] === 0 && !$GLOBALS['st_calls']);

// a Stripe GET failure counts as failed and changes nothing
sc_reset($conn);
[$row, $key] = sc_fulfilled($conn);
$GLOBALS['st_routes']['GET /v1/subscriptions/sub_1'] = ['status' => 500, 'json' => []];
$r = palette_st_reconcile($conn);
ok('reconcile Stripe failure counted, row untouched', $r['checked'] === 1 && $r['failed'] === 1 && $r['changed'] === 0 && palette_sub_row($conn, 'id', (int)$row['id'])['status'] === 'active');

// a test row is checked with the test key; a mode that is not ready is skipped
sc_reset($conn);
[$row, $key] = sc_fulfilled($conn, 'test', 'sub_t');
$GLOBALS['st_routes']['GET /v1/subscriptions/sub_t'] = ['status' => 200, 'json' => sc_stripe_sub('sub_t', ['status' => 'past_due'])];
palette_st_reconcile($conn);
$auth = '';
foreach ($GLOBALS['st_calls'][0]['headers'] ?? [] as $h) if (str_starts_with($h, 'Authorization')) $auth = $h;
ok('reconcile uses the row livemode key', $auth === 'Authorization: Bearer sk_test_x' && palette_sub_row($conn, 'id', (int)$row['id'])['status'] === 'suspended');
sc_reset($conn);
[$row, $key] = sc_fulfilled($conn, 'test', 'sub_t');
$s = palette_stripe_settings($conn);
$s['test']['secret'] = '';
palette_stripe_settings_put($conn, $s);
$r = palette_st_reconcile($conn);
ok('reconcile skips a mode that is not ready', $r['checked'] === 0 && !$GLOBALS['st_calls']);

// limit: two runs of 2 rotate across all 3 rows
sc_reset($conn);
foreach (['sub_a', 'sub_b', 'sub_c'] as $i => $sid) {
    [$lrow] = sc_fulfilled($conn, 'live', $sid, "u$i@studio.vn");
    $conn->query("UPDATE palette_subscriptions SET updated_at = '" . date('Y-m-d H:i:s', time() - (10 - $i) * 60) . "' WHERE id = " . (int)$lrow['id']);   // a, b, c in turn
    $GLOBALS['st_routes']["GET /v1/subscriptions/$sid"] = ['status' => 200, 'json' => sc_stripe_sub($sid)];
}
$GLOBALS['st_calls'] = [];
$r = palette_st_reconcile($conn, 2);
$firstGets = count(array_filter($GLOBALS['st_calls'], fn($x) => $x['method'] === 'GET'));
$r2 = palette_st_reconcile($conn, 2);
$gets = array_values(array_unique(array_column(array_filter($GLOBALS['st_calls'], fn($x) => $x['method'] === 'GET'), 'path')));
ok('reconcile limit', $r['checked'] === 2 && $firstGets === 2 && $r2['checked'] === 2 && count($gets) === 3);

// palette_sub_reconcile runs the Stripe pass; its PayPal pass leaves Stripe rows alone
sc_reset($conn);
[$row, $key] = sc_fulfilled($conn);
$GLOBALS['st_routes']['GET /v1/subscriptions/sub_1'] = ['status' => 200, 'json' => sc_stripe_sub('sub_1', ['status' => 'past_due'])];
$r = palette_sub_reconcile($conn);
$paypalCalls = array_filter($GLOBALS['st_calls'], fn($x) => str_contains($x['url'], 'paypal'));
ok('palette_sub_reconcile includes the Stripe pass', $r['checked'] === 1 && $r['changed'] === 1 && !$paypalCalls && palette_sub_row($conn, 'id', (int)$row['id'])['status'] === 'suspended');

// ---- reconcile: purchases the webhook missed
function sc_order(mysqli $c, string $mode = 'live'): array
{
    $GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_' . $mode . '_' . mt_rand(), 'url' => 'https://checkout.stripe.com/c/pay/x']];
    $r = palette_st_order_start($c, $mode, 'Buyer@Studio.vn', 'en', true);
    return palette_order_by($c, 'id', $r['row_id']);
}
function sc_paid_session(array $row, bool $paid = true): array
{
    return ['id' => $row['stripe_session'], 'object' => 'checkout.session', 'mode' => 'payment', 'payment_status' => $paid ? 'paid' : 'unpaid', 'status' => $paid ? 'complete' : 'open',
        'currency' => 'usd', 'amount_total' => 149, 'payment_intent' => 'pi_1', 'client_reference_id' => 'palette:order:' . $row['id'],
        'metadata' => ['palette' => 'order', 'row' => (string)$row['id']], 'livemode' => (int)$row['livemode'] === 1];
}
sc_reset($conn);
$paid = sc_order($conn);
$unpaid = sc_order($conn);
$fresh = sc_order($conn);
$old = sc_order($conn);
$conn->query("UPDATE palette_orders SET created_at = NOW() - INTERVAL 1 HOUR WHERE id IN ({$paid['id']}, {$unpaid['id']})");
$conn->query("UPDATE palette_orders SET created_at = NOW() - INTERVAL 8 DAY WHERE id = {$old['id']}");
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $paid['stripe_session']] = ['status' => 200, 'json' => sc_paid_session($paid)];
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $unpaid['stripe_session']] = ['status' => 200, 'json' => sc_paid_session($unpaid, false)];
$GLOBALS['st_calls'] = [];
$r = palette_st_reconcile($conn);
$st = fn(array $o) => palette_order_by($conn, 'id', (int)$o['id'])['status'];
ok('reconcile recovers a paid order the webhook missed', $r['checked'] === 2 && $r['changed'] === 1 && $st($paid) === 'captured'
    && palette_st_order_key($conn, (int)$paid['id']) !== null);
ok('reconcile leaves an unpaid order created', $st($unpaid) === 'created' && $st($fresh) === 'created' && $st($old) === 'created'
    && count($GLOBALS['st_calls']) === 2);

sc_reset($conn);
$GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_live_sub', 'url' => 'https://checkout.stripe.com/c/pay/s']];
$sr = palette_st_sub_start($conn, 'live', 'Sub@Studio.vn', 'en', true);
$srow = palette_sub_row($conn, 'id', $sr['row_id']);
$conn->query("UPDATE palette_subscriptions SET created_at = '" . date('Y-m-d H:i:s', time() - 3600) . "' WHERE id = " . (int)$srow['id']);
$GLOBALS['st_routes']['GET /v1/checkout/sessions/cs_live_sub'] = ['status' => 200, 'json' => sc_sub_session($srow, 'sub_rec', time(), time() + 30 * 86400)];
$r = palette_st_reconcile($conn);
ok('reconcile recovers a paid subscription checkout', $r['changed'] === 1 && palette_sub_row($conn, 'id', (int)$srow['id'])['status'] === 'active'
    && sc_lic($conn)['plan'] === 'ai');

// ---- account page: Manage billing only for a Stripe Pro + AI key
$k = ['plan' => 'ai', 'source' => 'stripe', 'revoked' => false, 'sub_status' => 'active', 'paid_through' => date('Y-m-d H:i:s', time() + 86400)];
ok('manage billing shown for Stripe Pro + AI', str_contains(palette_account_billing_panel('en', $k), 'data-portal') && str_contains(palette_account_billing_panel('en', $k), 'Manage billing')
    && palette_account_billing_panel('en', ['source' => 'paypal'] + $k) === '' && palette_account_billing_panel('en', ['plan' => 'pro'] + $k) === ''
    && palette_account_billing_panel('en', ['revoked' => true] + $k) === '');
$all = true;
foreach (['en', 'ar', 'bn', 'es', 'fr', 'hi', 'zh-CN'] as $lg) $all = $all && palette_t($lg, 'co_manage_billing') !== 'co_manage_billing' && palette_t($lg, 'acct_billing_note') !== 'acct_billing_note';
ok('billing strings in all 7 languages', $all);

ok('no secret in the log', !str_contains(sc_log(), 'sk_live_x') && !str_contains(sc_log(), 'sk_test_x'));

// leave no Stripe config or rows behind for the other test files
st_stub_reset($conn);
$conn->query("DELETE FROM admin_settings WHERE setting_key IN ('palette', 'palette_stripe')");
