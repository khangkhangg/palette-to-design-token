<?php
// Stripe webhook events (2026-11-04): signature by the event's own mode, then one handler per event type.
// Only rows palette created are touched; everything else answers 200 'ignored'. Money is never taken from the
// event body alone: sessions are re-fetched before fulfilment, invoices are checked against the row's stored price.
// Results: 200 for handled/ignored/refused, 400 bad body or signature, 413 too large, 503 'retry' (Stripe redelivers).

function palette_st_webhook_response(mysqli $c, string $payload, string $sigHeader): array
{
    if (strlen($payload) > PALETTE_WEBHOOK_MAX_BYTES) return [413, 'too large'];
    $event = json_decode($payload, true);
    if (!is_array($event) || array_is_list($event) || !is_bool($event['livemode'] ?? null)) return [400, 'bad body'];
    $mode = $event['livemode'] ? 'live' : 'test';
    $id = palette_st_ev_ref(palette_st_id($event['id'] ?? null, 'evt') ?? '?');
    try {
        // Inside the try: a database failure reading the secret is a 503, never an uncaught 500.
        $secret = palette_stripe_settings($c)[$mode]['webhook_secret'];
        if ($secret === '' || !palette_st_signed($payload, $sigHeader, $secret)) return [400, 'unverified'];
        $r = palette_st_event($c, $mode, $event);
    } catch (Throwable $e) {
        error_log("PALETTE_STRIPE_ERROR event=$id " . get_class($e) . ' (will retry)');   // never the message: it may quote a key
        return [503, 'retry'];
    }
    return [$r === 'retry' ? 503 : 200, $r];
}

// The id of the event being handled, for log lines (set once per delivery).
function palette_st_ev_ref(?string $set = null): string
{
    static $id = '?';
    if ($set !== null) $id = $set;
    return $id;
}

// A GET for the webhook: the JSON on 2xx, else null with $fail set. Only a transport failure (0), 429 or 5xx is worth
// a redelivery ('retry'); any other 4xx is permanent (a companies charge we cannot see, a deleted object): 'ignored'.
function palette_st_ev_get(mysqli $c, string $mode, string $path, array $params, ?string &$fail): ?array
{
    $r = palette_st_api($c, $mode, 'GET', $path, $params);
    $s = $r['status'];
    if ($s >= 200 && $s < 300) return $r['json'];
    error_log('PALETTE_STRIPE_ERROR event=' . palette_st_ev_ref() . " http=$s GET " . strtok($path, '/'));
    $fail = ($s === 0 || $s === 429 || $s >= 500) ? 'retry' : 'ignored';
    return null;
}

// A Stripe id with the given prefix (sub, in, pi, ch, cs, evt), or null. An expanded object yields its id.
function palette_st_id(mixed $v, string $prefix): ?string
{
    if (is_array($v)) $v = $v['id'] ?? null;
    return is_string($v) && preg_match('/^' . $prefix . '_[A-Za-z0-9_]{1,100}$/D', $v) ? $v : null;
}

// A test event never touches a live row, nor a live event a test row.
function palette_st_same_mode(array $row, string $mode): bool
{
    return (int)$row['livemode'] === ($mode === 'live' ? 1 : 0);
}

function palette_st_event(mysqli $c, string $mode, array $event): string
{
    $obj = $event['data']['object'] ?? null;
    if (!is_array($obj)) return 'ignored';
    return match ((string)($event['type'] ?? '')) {
        'checkout.session.completed', 'checkout.session.async_payment_succeeded' => palette_st_ev_session($c, $mode, $obj),
        'invoice.paid' => palette_st_ev_invoice_paid($c, $mode, $obj),
        'invoice.payment_failed' => palette_st_ev_invoice_failed($c, $mode, $obj),
        'customer.subscription.updated' => palette_st_ev_subscription($c, $mode, $obj, false),
        'customer.subscription.deleted' => palette_st_ev_subscription($c, $mode, $obj, true),
        'charge.refunded' => palette_st_ev_refund($c, $mode, $obj, false),
        'charge.dispute.created' => palette_st_ev_refund($c, $mode, $obj, true),
        default => 'ignored',
    };
}

// ---- checkout.session.*: re-fetch the session in the event's mode and fulfil it (Tasks 3/4).
function palette_st_ev_session(mysqli $c, string $mode, array $obj): string
{
    $sid = palette_st_id($obj['id'] ?? null, 'cs');
    if ($sid === null) return 'ignored';
    // Pro sessions carry metadata.palette; subscription sessions carry it on the subscription, so their
    // client_reference_id ("palette:sub:<row>") names the kind.
    $kind = $obj['metadata']['palette'] ?? null;
    if (!in_array($kind, ['order', 'sub'], true)) {
        $kind = is_string($obj['client_reference_id'] ?? null) && preg_match('/^palette:(order|sub):\d{1,10}$/D', $obj['client_reference_id'], $m) ? $m[1] : null;
    }
    if ($kind === null) return 'ignored';
    $row = palette_st_row_by_session($c, $kind === 'sub' ? 'palette_subscriptions' : 'palette_orders', $sid);
    if (!$row || !palette_st_same_mode($row, $mode)) return 'ignored';
    if ($row['status'] !== ($kind === 'sub' ? 'pending' : 'created')) return 'already';

    $sess = palette_st_ev_get($c, $mode, 'checkout/sessions/' . $sid, $kind === 'sub' ? ['expand' => ['subscription']] : [], $fail);
    if ($sess === null) return $fail;
    $f = $kind === 'sub' ? palette_st_fulfil_sub($c, $row, $sess) : palette_st_fulfil_order($c, $row, $sess);
    if ($f['ok']) return $f['already'] ? 'already' : 'fulfilled';
    if (!empty($f['retry'])) {
        // Completed but not paid yet (a delayed payment method): async_payment_succeeded fulfils it later. 200 with no
        // DB change, so Stripe does not count the endpoint as failing. Other retries (DB rollback, Stripe down) stay 503.
        return in_array($sess['payment_status'] ?? '', ['paid', 'no_payment_required'], true) ? 'retry' : 'waiting';
    }
    return $f['already'] ? 'already' : 'refused';   // a foreign or mismatched session: logged by the fulfil function
}

// ---- subscription lookups

// The fulfilled palette row bound to a Stripe subscription id, in this mode; null for anyone else's.
function palette_st_sub_by_stripe(mysqli $c, ?string $subId, string $mode): ?array
{
    if ($subId === null) return null;
    $st = $c->prepare("SELECT * FROM palette_subscriptions WHERE subscription_id = ? AND provider = 'stripe'");
    $st->bind_param('s', $subId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row && palette_st_same_mode($row, $mode) ? $row : null;
}

// An invoice's subscription id: `subscription` (API 2024-06-20) or parent.subscription_details (later versions).
function palette_st_invoice_sub(array $inv): ?string
{
    return palette_st_id($inv['subscription'] ?? $inv['parent']['subscription_details']['subscription'] ?? null, 'sub');
}

// True when an invoice names a palette subscription row that is still waiting for checkout.session.completed.
function palette_st_invoice_pending(mysqli $c, array $inv, string $mode): bool
{
    $metas = [$inv['subscription_details']['metadata'] ?? null, $inv['parent']['subscription_details']['metadata'] ?? null];
    foreach ((array)($inv['lines']['data'] ?? []) as $line) $metas[] = is_array($line) ? ($line['metadata'] ?? null) : null;
    foreach ($metas as $m) {
        if (!is_array($m) || ($m['palette'] ?? null) !== 'sub' || !ctype_digit((string)($m['row'] ?? ''))) continue;
        $row = palette_sub_row($c, 'id', (int)$m['row']);
        return $row !== null && $row['provider'] === 'stripe' && $row['status'] === 'pending' && $row['subscription_id'] === null
            && palette_st_same_mode($row, $mode);
    }
    return false;
}

// ---- invoice.paid: record the payment once (sale_id = invoice id), extend to the line's period end, reactivate.
function palette_st_ev_invoice_paid(mysqli $c, string $mode, array $inv): string
{
    $invId = palette_st_id($inv['id'] ?? null, 'in');
    $subId = palette_st_invoice_sub($inv);
    if ($invId === null || $subId === null) return 'ignored';
    $row = palette_st_sub_by_stripe($c, $subId, $mode);
    if (!$row) {
        // The first invoice can beat checkout.session.completed: never record money against a licence not yet issued.
        return palette_st_invoice_pending($c, $inv, $mode) ? 'retry' : 'ignored';
    }
    if ($row['license_id'] === null) {
        // A subscription palette refused at checkout (cancelled at Stripe, no key; palette_st_refuse_sub): never extended.
        error_log("PALETTE_PAID_UNFULFILLED invoice=$invId sub=$subId on a refused subscription; refund it in Stripe if it was charged");
        return 'refused';
    }
    // The price check reads the subtotal (before invoice discounts and customer credit: the Stripe account is shared, so a
    // balance from elsewhere can lower amount_paid); amount_paid is what is recorded as paid.
    $paid = $inv['amount_paid'] ?? null;
    $subtotal = $inv['subtotal'] ?? $paid;
    if (!is_int($paid) || $paid < 0 || !is_int($subtotal) || $subtotal <= 0) return 'ignored';   // a trial or zero invoice: nothing was sold
    $st = $c->prepare('SELECT 1 FROM palette_sub_payments WHERE sale_id = ?');
    $st->bind_param('s', $invId);
    $st->execute();
    $seen = $st->get_result()->num_rows > 0;
    $st->close();
    if ($seen) return 'already';

    $amount = palette_money($paid);
    $paidAt = palette_st_utc($inv['status_transitions']['paid_at'] ?? $inv['created'] ?? null) ?? gmdate('Y-m-d H:i:s');
    if (!palette_sub_live_licence($c, $row)) {
        // Refunded (revoked) key still being billed: record it, alert, stop the billing again. Never extended.
        palette_st_record_payment($c, $subId, $invId, $amount, 'completed', $paidAt);
        error_log("PALETTE_PAID_UNFULFILLED invoice=$invId sub=$subId paid $amount USD but the key was refunded; not extended, cancelling");
        palette_st_cancel_sub($c, $mode, $row, 'a payment on a refunded key');
        return 'unfulfilled';
    }
    if (strtolower((string)($inv['currency'] ?? '')) !== 'usd' || $subtotal < palette_sub_row_price($c, $row)) {
        error_log("PALETTE_PAID_UNFULFILLED invoice=$invId sub=$subId subtotal $subtotal paid $paid " . preg_replace('/[^a-z]/', '', strtolower((string)($inv['currency'] ?? ''))) . '; below the price, not extended');
        return 'refused';
    }

    $start = $end = null;
    foreach ((array)($inv['lines']['data'] ?? []) as $line) {
        if (is_array($line) && ($line['type'] ?? null) === 'subscription' && palette_st_utc($line['period']['end'] ?? null) !== null) {
            $start = palette_st_utc($line['period']['start'] ?? null);
            $end = palette_st_utc($line['period']['end']);
            break;
        }
    }
    if ($end === null) {
        $sub = palette_st_ev_get($c, $mode, 'subscriptions/' . $subId, [], $fail);
        if ($sub === null) return $fail;
        $item = $sub['items']['data'][0] ?? [];
        $start = palette_st_utc($sub['current_period_start'] ?? $item['current_period_start'] ?? null);
        $end = palette_st_utc($sub['current_period_end'] ?? $item['current_period_end'] ?? null);
        if ($end === null) return 'retry';
    }

    $c->begin_transaction();
    try {
        palette_st_record_payment($c, $subId, $invId, $amount, 'completed', $paidAt);
        palette_sub_extend($c, $row, $end, $start);
        $c->commit();
    } catch (Throwable $e) {
        $c->rollback();
        if ($e instanceof mysqli_sql_exception && $e->getCode() === 1062) return 'already';   // a concurrent delivery
        throw $e;
    }
    if ($row['status'] === 'suspended') palette_sub_set_status($c, $row, 'active', ['suspended']);
    return 'renewed';
}

function palette_st_record_payment(mysqli $c, string $subId, string $invId, string $amount, string $status, string $paidAt): void
{
    $st = $c->prepare('INSERT INTO palette_sub_payments (subscription_id, sale_id, amount_usd, status, paid_at) VALUES (?, ?, ?, ?, ?)');
    $st->bind_param('sssss', $subId, $invId, $amount, $status, $paidAt);
    $st->execute();
    $st->close();
}

// ---- invoice.payment_failed: 'suspended' on row and licence; the licence keeps access until paid_through.
function palette_st_ev_invoice_failed(mysqli $c, string $mode, array $inv): string
{
    $row = palette_st_sub_by_stripe($c, palette_st_invoice_sub($inv), $mode);
    if (!$row) return 'ignored';   // includes a first payment that failed before any key was issued
    return palette_sub_set_status($c, $row, 'suspended', ['active']) ? 'suspended' : 'already';
}

// ---- customer.subscription.updated / deleted. Expired is final; cancelled is final once Stripe deleted it.
function palette_st_ev_subscription(mysqli $c, string $mode, array $sub, bool $deleted): string
{
    $subId = palette_st_id($sub['id'] ?? null, 'sub');
    $row = palette_st_sub_by_stripe($c, $subId, $mode);
    if (!$row) return 'ignored';
    $status = (string)($sub['status'] ?? '');
    if ($deleted || $status === 'canceled' || $status === 'incomplete_expired') {
        $lid = (int)$row['license_id'];
        $pt = $c->query("SELECT paid_through FROM palette_licenses WHERE id = $lid")->fetch_row()[0] ?? null;
        $over = !is_string($pt) || strtotime($pt) < time();
        [$to, $from] = $over ? ['expired', ['active', 'suspended', 'cancelled']] : ['cancelled', ['active', 'suspended']];
        return palette_sub_set_status($c, $row, $to, $from) ? $to : 'already';
    }
    if (!palette_sub_live_licence($c, $row)) return 'ignored';   // refunded: the refund handler owns it
    if ($status === 'past_due' || $status === 'unpaid') {
        [$to, $from] = ['suspended', ['active']];
    } elseif ($status === 'active' || $status === 'trialing') {
        if (!empty($sub['cancel_at_period_end'])) {
            [$to, $from] = ['cancelled', ['active', 'suspended']];
        } else {
            [$to, $from] = ['active', ['suspended', 'cancelled']];
            // Undoing a cancel: events can arrive out of order, so ask Stripe before reopening a cancelled row.
            if ($row['status'] === 'cancelled') {
                $now = palette_st_ev_get($c, $mode, 'subscriptions/' . $subId, [], $fail);
                if ($now === null) return $fail;
                if (!in_array($now['status'] ?? '', ['active', 'trialing'], true) || !empty($now['cancel_at_period_end'])) return 'already';
            }
        }
    } else {
        return 'ignored';
    }
    return palette_sub_set_status($c, $row, $to, $from) ? $to : 'already';
}

// ---- charge.refunded (full only) and charge.dispute.created: revoke as PayPal refunds do, cancel billing now.
function palette_st_ev_refund(mysqli $c, string $mode, array $obj, bool $dispute): string
{
    if (!$dispute) {
        $amount = $obj['amount'] ?? null;
        $refunded = $obj['amount_refunded'] ?? null;
        if (!is_int($amount) || !is_int($refunded) || $amount <= 0 || $refunded < $amount) return 'ignored';   // partial
    }
    $pi = palette_st_id($obj['payment_intent'] ?? null, 'pi');
    $invId = $dispute ? null : palette_st_id($obj['invoice'] ?? null, 'in');
    $mark = $dispute ? 'reversed' : 'refunded';

    if ($pi !== null) {
        $st = $c->prepare("SELECT * FROM palette_orders WHERE stripe_payment_intent = ? AND provider = 'stripe'");
        $st->bind_param('s', $pi);
        $st->execute();
        $order = $st->get_result()->fetch_assoc();
        $st->close();
        if ($order) {
            if (!palette_st_same_mode($order, $mode)) return 'ignored';
            $oid = (string)$order['order_id'];
            palette_revoke_by_order($c, $oid);
            $st = $c->prepare("UPDATE palette_licenses SET pay_status = 'refunded' WHERE order_id = ?");
            $st->bind_param('s', $oid);
            $st->execute();
            $st->close();
            error_log("palette: Stripe $mark for order $oid; licence revoked");
            return 'revoked';
        }
    }
    if ($invId === null) {
        // A dispute names only its charge, and from API 2025-03-31 a charge has no `invoice` field (the endpoint may
        // send a newer version than ours): fetch the charge with palette_st_api, pinned to 2024-06-20, which has it.
        $ch = $dispute ? ($obj['charge'] ?? null) : ($obj['id'] ?? null);
        $chId = palette_st_id($ch, 'ch') ?? palette_st_id($ch, 'py');
        if ($chId === null) return 'ignored';
        $charge = palette_st_ev_get($c, $mode, 'charges/' . $chId, [], $fail);
        if ($charge === null) return $fail;
        $invId = palette_st_id($charge['invoice'] ?? null, 'in');
    }
    if ($invId === null) return 'ignored';   // not palette's (a companies charge on the shared account)

    $st = $c->prepare('SELECT subscription_id FROM palette_sub_payments WHERE sale_id = ?');
    $st->bind_param('s', $invId);
    $st->execute();
    $subId = $st->get_result()->fetch_row()[0] ?? null;
    $st->close();
    if ($subId === null) {
        $inv = palette_st_ev_get($c, $mode, 'invoices/' . $invId, [], $fail);
        if ($inv === null) return $fail;
        $subId = palette_st_invoice_sub($inv);
    }
    $row = palette_st_sub_by_stripe($c, is_string($subId) ? $subId : null, $mode);
    if (!$row) return 'ignored';

    $now = palette_sub_now();
    $lid = (int)$row['license_id'];
    $st = $c->prepare("UPDATE palette_licenses SET revoked_at = COALESCE(revoked_at, ?), pay_status = 'refunded' WHERE id = ?");
    $st->bind_param('si', $now, $lid);
    $st->execute();
    $st->close();
    $st = $c->prepare('UPDATE palette_sub_payments SET status = ? WHERE sale_id = ?');
    $st->bind_param('ss', $mark, $invId);
    $st->execute();
    $st->close();
    error_log("palette: Stripe $mark for subscription {$row['subscription_id']}; licence revoked");
    if (in_array($row['status'], ['active', 'suspended'], true)) palette_st_cancel_sub($c, $mode, $row, $mark);
    return 'revoked';
}

// DELETE /v1/subscriptions/{id}: billing stops now. Best effort: a failure logs PALETTE_CANCEL_FAILED and never undoes a revoke.
function palette_st_cancel_sub(mysqli $c, string $mode, array $row, string $why): bool
{
    $subId = (string)$row['subscription_id'];
    $r = palette_st_api($c, $mode, 'DELETE', 'subscriptions/' . $subId);
    if (($r['status'] >= 200 && $r['status'] < 300) || $r['status'] === 404) {   // 404: already gone
        palette_sub_set_status($c, $row, 'cancelled', ['active', 'suspended']);
        return true;
    }
    error_log("PALETTE_CANCEL_FAILED subscription=$subId after $why (HTTP " . (int)$r['status'] . '); cancel it in Stripe by hand');
    return false;
}

// ---- billing portal and the nightly reconcile (Task 6)

// A Stripe Customer Portal session for a Stripe subscription's owner (ownership as palette_sub_cancel).
// ['ok' => true, 'url'] or ['ok' => false, 'error' => err_invalid_key|err_not_owner|err_stripe].
function palette_st_portal_url(mysqli $c, string $rawKey, ?int $userId, ?string $email): array
{
    $o = palette_sub_owned_row($c, $rawKey, $userId, $email);
    if (!$o['ok']) return $o;
    return palette_st_portal_for_row($c, $o['row']);
}

// The portal session for a subscription row the caller may act on (the owner above, or an admin: lib/admin.php).
function palette_st_portal_for_row(mysqli $c, array $row): array
{
    if (($row['provider'] ?? '') !== 'stripe') return ['ok' => false, 'error' => 'err_invalid_key'];   // a PayPal subscription has no Stripe portal
    $cus = (string)($row['stripe_customer'] ?? '');
    $mode = (int)$row['livemode'] === 1 ? 'live' : 'test';
    if ($cus === '' || !palette_stripe_ready($c, $mode)) return ['ok' => false, 'error' => 'err_stripe'];
    $r = palette_st_api($c, $mode, 'POST', 'billing_portal/sessions', ['customer' => $cus, 'return_url' => PALETTE_STRIPE_BASE . '/account']);
    $url = (string)($r['json']['url'] ?? '');
    if ($r['status'] < 200 || $r['status'] >= 300 || !str_starts_with($url, 'https://')) {
        error_log('PALETTE_STRIPE_ERROR portal http=' . (int)$r['status']);
        return ['ok' => false, 'error' => 'err_stripe'];
    }
    return ['ok' => true, 'url' => $url];
}

// Nightly, after the PayPal pass. Two jobs, each up to $limit rows:
//  1. Stripe subscriptions (active|suspended|cancelled, paid_through not older than 3 days): GET the subscription with
//     its latest invoice and run the webhook handlers on it (invoice.paid, then updated/deleted), so a missed event
//     is repaired by exactly the rules the webhook uses. Rows in their own livemode; a mode not ready is skipped.
//  2. Purchases the webhook missed: Stripe orders 'created' and subscriptions 'pending' with a session, 10 minutes to
//     7 days old, go through palette_st_confirm (which fulfils only a paid session).
// ['checked', 'changed', 'failed'].
function palette_st_reconcile(mysqli $c, int $limit = 200): array
{
    $out = ['checked' => 0, 'changed' => 0, 'failed' => 0];
    $limit = max(0, $limit);
    $since = date('Y-m-d H:i:s', time() - 3 * 86400);
    $st = $c->prepare("SELECT s.* FROM palette_subscriptions s JOIN palette_licenses l ON l.id = s.license_id
                       WHERE s.provider = 'stripe' AND s.subscription_id IS NOT NULL AND s.status IN ('active', 'suspended', 'cancelled')
                         AND l.paid_through >= ? ORDER BY s.updated_at, s.id LIMIT ?");
    $st->bind_param('si', $since, $limit);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $changedBy = ['renewed', 'suspended', 'cancelled', 'active', 'expired', 'unfulfilled'];
    $ready = [];
    foreach ($rows as $row) {
        $mode = (int)$row['livemode'] === 1 ? 'live' : 'test';
        $ready[$mode] ??= palette_stripe_ready($c, $mode);
        if (!$ready[$mode]) continue;
        $out['checked']++;
        $rid = (int)$row['id'];
        $c->query("UPDATE palette_subscriptions SET updated_at = '" . $c->real_escape_string(palette_sub_now()) . "' WHERE id = $rid");   // rotate the batch
        try {
            palette_st_ev_ref('reconcile');
            $sid = (string)$row['subscription_id'];
            $sub = palette_st_ev_get($c, $mode, 'subscriptions/' . rawurlencode($sid), ['expand' => ['latest_invoice']], $fail);
            if ($sub === null || palette_st_id($sub['id'] ?? null, 'sub') !== $sid) {
                $out['failed']++;
                continue;
            }
            $changed = false;
            $inv = $sub['latest_invoice'] ?? null;
            if (is_array($inv) && ($inv['status'] ?? '') === 'paid') {
                $inv['subscription'] ??= $sid;
                $res = palette_st_ev_invoice_paid($c, $mode, $inv);
                if ($res === 'retry') { $out['failed']++; continue; }
                $changed = in_array($res, $changedBy, true);
            }
            $res = palette_st_ev_subscription($c, $mode, $sub, false);
            if ($res === 'retry') { $out['failed']++; continue; }
            if (in_array($res, $changedBy, true)) $changed = true;
            if ($changed) $out['changed']++;
        } catch (Throwable $e) {
            error_log("palette: Stripe reconcile of subscription {$row['subscription_id']} failed (" . get_class($e) . ')');
            $out['failed']++;
        }
    }

    // 2. Missed purchases.
    $lo = date('Y-m-d H:i:s', time() - 7 * 86400);
    $hi = date('Y-m-d H:i:s', time() - 600);
    $found = [];
    $st = $c->prepare("SELECT stripe_session, livemode FROM palette_orders WHERE provider = 'stripe' AND status = 'created' AND stripe_session IS NOT NULL AND stripe_session <> ''
                       AND created_at < NOW() - INTERVAL 10 MINUTE AND created_at > NOW() - INTERVAL 7 DAY ORDER BY id DESC LIMIT ?");
    $st->bind_param('i', $limit);
    $st->execute();
    foreach ($st->get_result() as $r) $found[] = $r;
    $st->close();
    $st = $c->prepare("SELECT stripe_session, livemode FROM palette_subscriptions WHERE provider = 'stripe' AND status = 'pending' AND stripe_session IS NOT NULL AND stripe_session <> ''
                       AND created_at < ? AND created_at > ? ORDER BY id DESC LIMIT ?");
    $st->bind_param('ssi', $hi, $lo, $limit);
    $st->execute();
    foreach ($st->get_result() as $r) $found[] = $r;
    $st->close();
    foreach ($found as $r) {
        $mode = (int)$r['livemode'] === 1 ? 'live' : 'test';
        $ready[$mode] ??= palette_stripe_ready($c, $mode);
        if (!$ready[$mode]) continue;
        $out['checked']++;
        try {
            $res = palette_st_confirm($c, (string)$r['stripe_session']);
            if ($res['state'] === 'paid') $out['changed']++;
            elseif ($res['state'] === 'error') $out['failed']++;
        } catch (Throwable $e) {
            error_log('palette: Stripe reconcile of a pending checkout failed (' . get_class($e) . ')');
            $out['failed']++;
        }
    }
    return $out;
}
