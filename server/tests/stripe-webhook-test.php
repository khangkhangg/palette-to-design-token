<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/st-stub.php';
ini_set('error_log', sys_get_temp_dir() . '/palette-stripe-webhook-test.log');
@unlink(sys_get_temp_dir() . '/palette-stripe-webhook-test.log');
ob_start();
session_start();

const SW_START = 1760000000;
const SW_END = 1762592000;

function sw_log(): string
{
    return (string)@file_get_contents(sys_get_temp_dir() . '/palette-stripe-webhook-test.log');
}

function sw_reset(mysqli $c, bool $test = true): void
{
    st_stub_reset($c);
    st_stub_connect($c, 'live');
    if ($test) st_stub_connect($c, 'test');
    $c->query('DELETE FROM palette_rate');
    $_SESSION = [];
}

function sw_count(mysqli $c, string $sql): int
{
    return (int)$c->query($sql)->fetch_row()[0];
}

// A signed delivery of $event (livemode true unless given) with the matching mode's secret unless $secret is given.
function sw_send(mysqli $c, string $type, array $object, array $over = [], ?string $secret = null, ?int $t = null): array
{
    $event = $over + ['id' => 'evt_' . mt_rand(), 'object' => 'event', 'type' => $type, 'livemode' => true, 'data' => ['object' => $object]];
    $payload = json_encode($event);
    $secret ??= $event['livemode'] === true ? 'whsec_live' : 'whsec_test';
    return palette_st_webhook_response($c, $payload, st_sign($payload, $secret, $t));
}

function sw_order(mysqli $c, string $mode = 'live'): array
{
    $GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_' . $mode . '_' . mt_rand(), 'url' => 'https://checkout.stripe.com/c/pay/x']];
    $r = palette_st_order_start($c, $mode, 'Buyer@Studio.vn', 'en', true);
    return palette_order_by($c, 'id', $r['row_id']);
}

function sw_order_session(array $row, array $over = []): array
{
    return $over + ['id' => $row['stripe_session'], 'object' => 'checkout.session', 'mode' => 'payment', 'payment_status' => 'paid', 'status' => 'complete',
        'currency' => 'usd', 'amount_total' => 149, 'payment_intent' => 'pi_123', 'client_reference_id' => 'palette:order:' . $row['id'],
        'metadata' => ['palette' => 'order', 'row' => (string)$row['id']], 'livemode' => (int)$row['livemode'] === 1];
}

function sw_sub(mysqli $c, string $mode = 'live'): array
{
    $GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_' . $mode . '_' . mt_rand(), 'url' => 'https://checkout.stripe.com/c/pay/s']];
    $r = palette_st_sub_start($c, $mode, 'Buyer@Studio.vn', 'en', true);
    return palette_sub_row($c, 'id', $r['row_id']);
}

function sw_sub_session(array $row, array $subOver = []): array
{
    $sub = $subOver + ['id' => 'sub_123', 'object' => 'subscription', 'status' => 'active', 'currency' => 'usd', 'metadata' => ['palette' => 'sub', 'row' => (string)$row['id']],
        'current_period_start' => SW_START, 'current_period_end' => SW_END, 'cancel_at_period_end' => false,
        'items' => ['data' => [['price' => ['unit_amount' => 399, 'currency' => 'usd']]]]];
    return ['id' => $row['stripe_session'], 'object' => 'checkout.session', 'mode' => 'subscription', 'payment_status' => 'paid', 'status' => 'complete',
        'currency' => 'usd', 'customer' => 'cus_123', 'subscription' => $sub, 'client_reference_id' => 'palette:sub:' . $row['id'],
        'metadata' => [], 'livemode' => (int)$row['livemode'] === 1];
}

// A fulfilled Stripe subscription (licence issued, paid through SW_END). Returns the fresh row.
function sw_fulfilled_sub(mysqli $c, string $mode = 'live'): array
{
    $row = sw_sub($c, $mode);
    $f = palette_st_fulfil_sub($c, $row, sw_sub_session($row));
    if (!$f['ok']) throw new RuntimeException('fixture fulfil failed');
    $GLOBALS['st_calls'] = [];
    $GLOBALS['mails'] = [];
    return palette_sub_row($c, 'id', (int)$row['id']);
}

function sw_invoice(string $id, int $paid, array $over = [], ?int $start = null, ?int $end = null): array
{
    $start ??= SW_END;
    $end ??= SW_END + 30 * 86400;
    return $over + ['id' => $id, 'object' => 'invoice', 'subscription' => 'sub_123', 'currency' => 'usd', 'amount_paid' => $paid, 'customer' => 'cus_123',
        'billing_reason' => 'subscription_cycle', 'status' => 'paid', 'status_transitions' => ['paid_at' => $start + 60],
        'lines' => ['data' => [['type' => 'subscription', 'period' => ['start' => $start, 'end' => $end]]]]];
}

function sw_lic(mysqli $c): array
{
    return $c->query('SELECT * FROM palette_licenses ORDER BY id DESC LIMIT 1')->fetch_assoc() ?: [];
}

// ---- signature and body
sw_reset($conn);
$row = sw_order($conn);
$GLOBALS['st_calls'] = [];
$obj = sw_order_session($row);
$payload = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'livemode' => true, 'data' => ['object' => $obj]]);
ok('bad signature 400', palette_st_webhook_response($conn, $payload, st_sign($payload, 'whsec_wrong'))[0] === 400
    && !$GLOBALS['st_calls'] && sw_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 0);
ok('missing signature 400', palette_st_webhook_response($conn, $payload, '')[0] === 400);
ok('stale signature 400', palette_st_webhook_response($conn, $payload, st_sign($payload, 'whsec_live', time() - 400))[0] === 400 && !$GLOBALS['st_calls']);
ok('live event signed with the test secret 400', palette_st_webhook_response($conn, $payload, st_sign($payload, 'whsec_test'))[0] === 400);
ok('not JSON 400', palette_st_webhook_response($conn, 'nope', st_sign('nope', 'whsec_live'))[0] === 400);
ok('JSON list 400', palette_st_webhook_response($conn, '[1,2]', st_sign('[1,2]', 'whsec_live'))[0] === 400);
ok('oversized body 413, nothing called', palette_st_webhook_response($conn, str_repeat(' ', 65537), 't=1,v1=x') === [413, 'too large'] && !$GLOBALS['st_calls']);
ok('unknown event type ignored', sw_send($conn, 'customer.created', ['id' => 'cus_1']) === [200, 'ignored']);

// test events: checked with the test secret
$GLOBALS['st_calls'] = [];
$r = sw_send($conn, 'customer.created', ['id' => 'cus_1'], ['livemode' => false]);
ok('test event checked with test secret', $r === [200, 'ignored']);
$tp = json_encode(['id' => 'evt_t', 'type' => 'customer.created', 'livemode' => false, 'data' => ['object' => []]]);
ok('test event signed with the live secret 400', palette_st_webhook_response($conn, $tp, st_sign($tp, 'whsec_live'))[0] === 400);
sw_reset($conn, false);
ok('test event without test connection 400', sw_send($conn, 'customer.created', ['id' => 'cus_1'], ['livemode' => false])[0] === 400);

// ---- foreign and unknown sessions
sw_reset($conn);
$row = sw_order($conn);
$GLOBALS['st_calls'] = [];
$foreign = ['id' => 'cs_live_api1', 'object' => 'checkout.session', 'payment_status' => 'paid', 'metadata' => ['kind' => 'api'], 'livemode' => true];
$before = $conn->query('SELECT * FROM palette_orders')->fetch_all(MYSQLI_ASSOC);
ok('foreign event ignored', sw_send($conn, 'checkout.session.completed', $foreign) === [200, 'ignored'] && !$GLOBALS['st_calls']
    && $conn->query('SELECT * FROM palette_orders')->fetch_all(MYSQLI_ASSOC) === $before && sw_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 0);
$unknown = ['id' => 'cs_live_nobody', 'object' => 'checkout.session', 'metadata' => ['palette' => 'order', 'row' => '999999'], 'livemode' => true];
ok('palette metadata without a row ignored, no Stripe call', sw_send($conn, 'checkout.session.completed', $unknown) === [200, 'ignored'] && !$GLOBALS['st_calls']);

// ---- completed: re-fetched, fulfils once
$thin = ['id' => $row['stripe_session'], 'object' => 'checkout.session', 'metadata' => ['palette' => 'order', 'row' => (string)$row['id']], 'livemode' => true,
         'payment_status' => 'paid', 'amount_total' => 149];
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 200, 'json' => sw_order_session($row, ['payment_status' => 'unpaid'])];
$ordersBefore = $conn->query('SELECT * FROM palette_orders')->fetch_all(MYSQLI_ASSOC);
ok('event body not trusted: Stripe says unpaid -> 200 waiting, no DB change', sw_send($conn, 'checkout.session.completed', $thin) === [200, 'waiting']
    && sw_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 0 && $conn->query('SELECT * FROM palette_orders')->fetch_all(MYSQLI_ASSOC) === $ordersBefore);
$get = $GLOBALS['st_calls'][0] ?? [];
ok('session re-fetched with the live key', ($get['method'] ?? '') === 'GET' && ($get['path'] ?? '') === '/v1/checkout/sessions/' . $row['stripe_session']
    && in_array('Authorization: Bearer sk_live_x', $get['headers'] ?? [], true));
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 200, 'json' => sw_order_session($row)];
$r = sw_send($conn, 'checkout.session.completed', $thin);
$ret = palette_st_confirm($conn, $row['stripe_session']);
ok('completed fulfils once', $r === [200, 'fulfilled'] && $ret['state'] === 'paid' && sw_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 1
    && count($GLOBALS['mails']) === 1 && palette_order_by($conn, 'id', (int)$row['id'])['stripe_payment_intent'] === 'pi_123');
ok('completed redelivered -> already', sw_send($conn, 'checkout.session.async_payment_succeeded', $thin) === [200, 'already']
    && sw_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 1);

// mismatch: Stripe's session belongs to another row -> refused, 200
$row2 = sw_order($conn);
$thin2 = ['id' => $row2['stripe_session'], 'metadata' => ['palette' => 'order', 'row' => (string)$row2['id']], 'livemode' => true];
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row2['stripe_session']] = ['status' => 200, 'json' => sw_order_session($row2, ['client_reference_id' => 'palette:order:1'])];
ok('mismatched session refused (200)', sw_send($conn, 'checkout.session.completed', $thin2) === [200, 'refused']
    && sw_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 1);
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row2['stripe_session']] = ['status' => 500, 'json' => []];
ok('Stripe down on re-fetch -> 503', sw_send($conn, 'checkout.session.completed', $thin2) === [503, 'retry'] && str_contains(sw_log(), 'PALETTE_STRIPE_ERROR'));
ok('test event cannot fulfil a live row', sw_send($conn, 'checkout.session.completed', $thin2 + ['x' => 1], ['livemode' => false]) === [200, 'ignored']);

// subscription session: expanded re-fetch
sw_reset($conn);
$srow = sw_sub($conn);
$GLOBALS['st_calls'] = [];
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $srow['stripe_session']] = ['status' => 200, 'json' => sw_sub_session($srow)];
$sthin = ['id' => $srow['stripe_session'], 'object' => 'checkout.session', 'metadata' => [], 'subscription' => 'sub_123', 'livemode' => true,
          'client_reference_id' => 'palette:sub:' . $srow['id']];
// Task 4 puts palette's metadata on the subscription, not the session: the client_reference_id names the row.
$r = sw_send($conn, 'checkout.session.completed', $sthin);
$get = $GLOBALS['st_calls'][0] ?? [];
ok('sub session fulfilled via expanded re-fetch', $r === [200, 'fulfilled'] && str_contains(urldecode((string)($get['url'] ?? '')), 'expand[0]=subscription')
    && sw_lic($conn)['plan'] === 'ai' && palette_sub_row($conn, 'id', (int)$srow['id'])['status'] === 'active');

// ---- invoice.paid
sw_reset($conn);
$srow = sw_sub($conn);
$GLOBALS['st_calls'] = [];
$first = sw_invoice('in_first', 399, ['billing_reason' => 'subscription_create', 'subscription_details' => ['metadata' => ['palette' => 'sub', 'row' => (string)$srow['id']]]], SW_START, SW_END);
ok('invoice.paid before fulfilment -> 503, nothing recorded', sw_send($conn, 'invoice.paid', $first) === [503, 'retry']
    && sw_count($conn, 'SELECT COUNT(*) FROM palette_sub_payments') === 0 && !$GLOBALS['st_calls']);
palette_st_fulfil_sub($conn, $srow, sw_sub_session($srow));
$srow = palette_sub_row($conn, 'id', (int)$srow['id']);
ok('first invoice after fulfilment recorded', sw_send($conn, 'invoice.paid', $first) === [200, 'renewed']
    && sw_count($conn, "SELECT COUNT(*) FROM palette_sub_payments WHERE sale_id = 'in_first' AND subscription_id = 'sub_123' AND amount_usd = 3.99") === 1
    && sw_lic($conn)['paid_through'] === gmdate('Y-m-d H:i:s', SW_END));

$inv = sw_invoice('in_2', 399);
$r1 = sw_send($conn, 'invoice.paid', $inv);
$r2 = sw_send($conn, 'invoice.paid', $inv);
$l = sw_lic($conn);
ok('invoice.paid extends and is idempotent', $r1 === [200, 'renewed'] && $r2 === [200, 'already']
    && sw_count($conn, "SELECT COUNT(*) FROM palette_sub_payments WHERE sale_id = 'in_2'") === 1
    && $l['paid_through'] === gmdate('Y-m-d H:i:s', SW_END + 30 * 86400) && $l['period_start'] === gmdate('Y-m-d H:i:s', SW_END));

$r = sw_send($conn, 'invoice.paid', sw_invoice('in_low', 100, [], SW_END + 30 * 86400, SW_END + 60 * 86400));
ok('invoice below the price refused', $r === [200, 'refused'] && sw_count($conn, "SELECT COUNT(*) FROM palette_sub_payments WHERE sale_id = 'in_low'") === 0
    && str_contains(sw_log(), 'PALETTE_PAID_UNFULFILLED') && sw_lic($conn)['paid_through'] === gmdate('Y-m-d H:i:s', SW_END + 30 * 86400));
ok('invoice in another currency refused', sw_send($conn, 'invoice.paid', sw_invoice('in_eur', 399, ['currency' => 'eur'])) === [200, 'refused']);
ok('zero invoice (trial) ignored', sw_send($conn, 'invoice.paid', sw_invoice('in_zero', 0)) === [200, 'ignored']
    && sw_count($conn, "SELECT COUNT(*) FROM palette_sub_payments WHERE sale_id = 'in_zero'") === 0);
ok('foreign subscription invoice ignored', sw_send($conn, 'invoice.paid', sw_invoice('in_x', 399, ['subscription' => 'sub_other'])) === [200, 'ignored']
    && sw_count($conn, "SELECT COUNT(*) FROM palette_sub_payments WHERE sale_id = 'in_x'") === 0);
ok('test-mode invoice cannot touch a live subscription', sw_send($conn, 'invoice.paid', sw_invoice('in_t', 399), ['livemode' => false]) === [200, 'ignored']);

// no subscription line: the period comes from the subscription
$GLOBALS['st_calls'] = [];
$GLOBALS['st_routes']['GET /v1/subscriptions/sub_123'] = ['status' => 200, 'json' => ['id' => 'sub_123', 'status' => 'active',
    'current_period_start' => SW_END + 30 * 86400, 'current_period_end' => SW_END + 60 * 86400]];
$noLine = sw_invoice('in_3', 399, ['lines' => ['data' => [['type' => 'invoiceitem', 'period' => ['start' => 1, 'end' => 2]]]]]);
ok('period end from the subscription when no subscription line', sw_send($conn, 'invoice.paid', $noLine) === [200, 'renewed']
    && sw_lic($conn)['paid_through'] === gmdate('Y-m-d H:i:s', SW_END + 60 * 86400) && ($GLOBALS['st_calls'][0]['path'] ?? '') === '/v1/subscriptions/sub_123');

// ---- invoice.payment_failed: suspended, access holds while paid_through is ahead
sw_reset($conn);
$srow = sw_fulfilled_sub($conn);
$future = gmdate('Y-m-d H:i:s', time() + 3 * 86400);
$conn->query("UPDATE palette_licenses SET paid_through = '$future'");
$key = sw_lic($conn)['license_key'];
$r = sw_send($conn, 'invoice.payment_failed', ['id' => 'in_f', 'object' => 'invoice', 'subscription' => 'sub_123', 'amount_paid' => 0, 'currency' => 'usd']);
$v = palette_verify($conn, $key, '127.0.0.1');
ok('payment_failed suspends', $r === [200, 'suspended'] && palette_sub_row($conn, 'id', (int)$srow['id'])['status'] === 'suspended'
    && sw_lic($conn)['sub_status'] === 'suspended');
ok('payment_failed: access holds until paid_through', $v['valid'] === true && $v['ai_active'] === true && $v['sub_status'] === 'suspended');
ok('payment_failed again -> already', sw_send($conn, 'invoice.payment_failed', ['id' => 'in_f', 'subscription' => 'sub_123']) === [200, 'already']);
$r = sw_send($conn, 'invoice.paid', sw_invoice('in_4', 399, [], time(), time() + 30 * 86400));
ok('paid after failure reactivates', $r === [200, 'renewed'] && palette_sub_row($conn, 'id', (int)$srow['id'])['status'] === 'active' && sw_lic($conn)['sub_status'] === 'active');

// ---- customer.subscription.updated
$subObj = fn(array $o = []) => $o + ['id' => 'sub_123', 'object' => 'subscription', 'status' => 'active', 'cancel_at_period_end' => false];
ok('updated past_due -> suspended', sw_send($conn, 'customer.subscription.updated', $subObj(['status' => 'past_due'])) === [200, 'suspended']
    && sw_lic($conn)['sub_status'] === 'suspended');
ok('updated active -> active', sw_send($conn, 'customer.subscription.updated', $subObj()) === [200, 'active']
    && palette_sub_row($conn, 'id', (int)$srow['id'])['status'] === 'active');
ok('updated cancel_at_period_end -> cancelled', sw_send($conn, 'customer.subscription.updated', $subObj(['cancel_at_period_end' => true])) === [200, 'cancelled']
    && sw_lic($conn)['sub_status'] === 'cancelled');
$GLOBALS['st_routes']['GET /v1/subscriptions/sub_123'] = ['status' => 200, 'json' => $subObj(['cancel_at_period_end' => true])];
ok('stale undo (Stripe still cancelling) -> already', sw_send($conn, 'customer.subscription.updated', $subObj()) === [200, 'already']
    && sw_lic($conn)['sub_status'] === 'cancelled');
$GLOBALS['st_routes']['GET /v1/subscriptions/sub_123'] = ['status' => 200, 'json' => $subObj()];
ok('updated undo cancel -> active', sw_send($conn, 'customer.subscription.updated', $subObj()) === [200, 'active']);
ok('updated unpaid -> suspended', sw_send($conn, 'customer.subscription.updated', $subObj(['status' => 'unpaid'])) === [200, 'suspended']);
ok('updated foreign subscription ignored', sw_send($conn, 'customer.subscription.updated', $subObj(['id' => 'sub_other'])) === [200, 'ignored']);

// ---- customer.subscription.deleted
$r = sw_send($conn, 'customer.subscription.deleted', $subObj(['status' => 'canceled']));
ok('subscription deleted -> cancelled while paid_through is ahead', $r === [200, 'cancelled'] && sw_lic($conn)['sub_status'] === 'cancelled'
    && palette_sub_row($conn, 'id', (int)$srow['id'])['status'] === 'cancelled');
ok('deleted subscription stays cancelled on a late update', sw_send($conn, 'customer.subscription.updated', $subObj(['status' => 'past_due'])) === [200, 'already']);
sw_reset($conn);
$srow = sw_fulfilled_sub($conn);   // paid through SW_END, long past
ok('subscription deleted after paid_through -> expired', sw_send($conn, 'customer.subscription.deleted', $subObj(['status' => 'canceled'])) === [200, 'expired']
    && sw_lic($conn)['sub_status'] === 'expired');

// ---- refunds and disputes
sw_reset($conn);
$row = sw_order($conn);
palette_st_fulfil_order($conn, $row, sw_order_session($row));
$GLOBALS['st_calls'] = [];
$charge = fn(array $o = []) => $o + ['id' => 'ch_1', 'object' => 'charge', 'amount' => 149, 'amount_refunded' => 149, 'currency' => 'usd', 'payment_intent' => 'pi_123', 'invoice' => null];
ok('partial refund ignored', sw_send($conn, 'charge.refunded', $charge(['amount_refunded' => 50])) === [200, 'ignored'] && sw_lic($conn)['revoked_at'] === null);
ok('foreign charge ignored', sw_send($conn, 'charge.refunded', $charge(['payment_intent' => 'pi_other'])) === [200, 'ignored'] && sw_lic($conn)['revoked_at'] === null);
$r = sw_send($conn, 'charge.refunded', $charge());
$l = sw_lic($conn);
ok('full refund revokes the Pro licence', $r === [200, 'revoked'] && $l['revoked_at'] !== null && $l['pay_status'] === 'refunded' && !$GLOBALS['st_calls']);
ok('refund redelivered still 200', sw_send($conn, 'charge.refunded', $charge())[0] === 200);

sw_reset($conn);
$row = sw_order($conn);
palette_st_fulfil_order($conn, $row, sw_order_session($row));
$r = sw_send($conn, 'charge.dispute.created', ['id' => 'dp_1', 'object' => 'dispute', 'charge' => 'ch_1', 'payment_intent' => 'pi_123', 'amount' => 149]);
ok('dispute revokes', $r === [200, 'revoked'] && sw_lic($conn)['revoked_at'] !== null && sw_lic($conn)['pay_status'] === 'refunded');

// subscription refund: revoke + cancel the Stripe subscription now
sw_reset($conn);
$srow = sw_fulfilled_sub($conn);
sw_send($conn, 'invoice.paid', sw_invoice('in_r', 399, [], SW_START, SW_END));
$GLOBALS['st_calls'] = [];
$GLOBALS['st_routes']['DELETE /v1/subscriptions/sub_123'] = ['status' => 200, 'json' => ['id' => 'sub_123', 'status' => 'canceled']];
$r = sw_send($conn, 'charge.refunded', $charge(['amount' => 399, 'amount_refunded' => 399, 'payment_intent' => 'pi_sub', 'invoice' => 'in_r']));
$del = array_values(array_filter($GLOBALS['st_calls'], fn($x) => $x['method'] === 'DELETE'));
ok('refund revokes and cancels', $r === [200, 'revoked'] && sw_lic($conn)['revoked_at'] !== null && sw_lic($conn)['pay_status'] === 'refunded'
    && count($del) === 1 && $del[0]['path'] === '/v1/subscriptions/sub_123' && in_array('Authorization: Bearer sk_live_x', $del[0]['headers'], true)
    && palette_sub_row($conn, 'id', (int)$srow['id'])['status'] === 'cancelled'
    && sw_count($conn, "SELECT COUNT(*) FROM palette_sub_payments WHERE sale_id = 'in_r' AND status = 'refunded'") === 1);

sw_reset($conn);
$srow = sw_fulfilled_sub($conn);
$GLOBALS['st_routes']['GET /v1/invoices/in_unrec'] = ['status' => 200, 'json' => ['id' => 'in_unrec', 'subscription' => 'sub_123']];
$GLOBALS['st_routes']['DELETE /v1/subscriptions/sub_123'] = ['status' => 500, 'json' => []];
$r = sw_send($conn, 'charge.dispute.created', ['id' => 'dp_2', 'object' => 'dispute', 'charge' => 'ch_9', 'payment_intent' => 'pi_9', 'amount' => 399]);
ok('dispute: charge lookup failing -> 503', $r === [503, 'retry'] && sw_lic($conn)['revoked_at'] === null);
$GLOBALS['st_routes']['GET /v1/charges/ch_9'] = ['status' => 200, 'json' => ['id' => 'ch_9', 'payment_intent' => 'pi_9', 'invoice' => 'in_unrec']];
$r = sw_send($conn, 'charge.dispute.created', ['id' => 'dp_2', 'object' => 'dispute', 'charge' => 'ch_9', 'payment_intent' => 'pi_9', 'amount' => 399]);
ok('dispute on a subscription revokes; failed cancel logged, still 200', $r === [200, 'revoked'] && sw_lic($conn)['revoked_at'] !== null
    && str_contains(sw_log(), 'PALETTE_CANCEL_FAILED') && palette_sub_row($conn, 'id', (int)$srow['id'])['status'] === 'active');
// still billed after the failed cancel: recorded, never extended, cancel retried
$GLOBALS['st_calls'] = [];
$GLOBALS['st_routes']['DELETE /v1/subscriptions/sub_123'] = ['status' => 200, 'json' => ['id' => 'sub_123', 'status' => 'canceled']];
$ptBefore = sw_lic($conn)['paid_through'];
$r = sw_send($conn, 'invoice.paid', sw_invoice('in_after', 399));
ok('payment on a revoked key: recorded, not extended, cancel retried', $r === [200, 'unfulfilled']
    && sw_count($conn, "SELECT COUNT(*) FROM palette_sub_payments WHERE sale_id = 'in_after'") === 1 && sw_lic($conn)['paid_through'] === $ptBefore
    && count(array_filter($GLOBALS['st_calls'], fn($x) => $x['method'] === 'DELETE')) === 1
    && palette_sub_row($conn, 'id', (int)$srow['id'])['status'] === 'cancelled');

// ---- exceptions -> 503
sw_reset($conn);
$row = sw_order($conn);
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = function () { throw new RuntimeException('db gone sk_live_secret'); };
$r = sw_send($conn, 'checkout.session.completed', ['id' => $row['stripe_session'], 'metadata' => ['palette' => 'order', 'row' => (string)$row['id']], 'livemode' => true],
    ['id' => 'evt_boom']);
ok('db failure 503', $r === [503, 'retry'] && str_contains(sw_log(), 'PALETTE_STRIPE_ERROR event=evt_boom RuntimeException') && !str_contains(sw_log(), 'sk_live_secret'));

// ---- endpoint file
$ep = (string)@file_get_contents(__DIR__ . '/../stripe-webhook.php');
ok('endpoint mirrors webhook.php', str_contains($ep, "require __DIR__ . '/bootstrap.php'") && str_contains($ep, 'http_response_code(405)')
    && str_contains($ep, 'http_response_code(413)') && str_contains($ep, 'PALETTE_WEBHOOK_MAX_BYTES + 1') && str_contains($ep, "HTTP_STRIPE_SIGNATURE")
    && str_contains($ep, 'palette_st_webhook_response($conn'));

// leave no Stripe config or rows behind for the other test files
st_stub_reset($conn);
$conn->query("DELETE FROM admin_settings WHERE setting_key IN ('palette', 'palette_stripe')");
