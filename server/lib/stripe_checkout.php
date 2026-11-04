<?php
// Pro by Stripe (one payment): start a Checkout session, confirm on return, fulfil exactly once.
// The webhook calls palette_st_fulfil_order too, so fulfilment is claim-first and safe to race.

const PALETTE_STRIPE_SESSION_RE = '/^cs_[A-Za-z0-9_]{1,100}$/';
const PALETTE_STRIPE_BASE = 'https://vietnamcos.com/palette';

// 'test' only for a signed-in admin asking for it; everyone else is always live.
function palette_checkout_mode(array $session, array $query): string
{
    return ($query['test'] ?? null) === '1' && ($session['user_role'] ?? null) === 'admin' ? 'test' : 'live';
}

// Checkout's own locale codes: zh-CN -> zh, languages it lacks -> auto.
function palette_st_locale(string $lang): string
{
    return ['zh-CN' => 'zh', 'bn' => 'auto', 'hi' => 'auto'][$lang] ?? $lang;
}

function palette_st_log_error(string $what, string $orderId, int $status): void
{
    error_log("PALETTE_STRIPE_ERROR $what order=$orderId http=$status");   // never the key or any secret
}

function palette_st_order_start(mysqli $c, string $mode, string $email, string $lang, bool $consent): array
{
    if (preg_match('/[\r\n]/', $email)) return ['ok' => false, 'error' => 'err_email'];
    $email = strtolower(trim($email));
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'err_email'];
    if (!$consent) return ['ok' => false, 'error' => 'err_consent'];
    $mode = $mode === 'test' ? 'test' : 'live';
    if (!palette_stripe_ready($c, $mode)) return ['ok' => false, 'error' => 'err_paused'];
    $lang = palette_lang($lang);

    $t = palette_total(palette_settings($c), null, null);
    $cents = (int)palette_cents($t['amount']);
    $orderId = 'ST-' . strtoupper(bin2hex(random_bytes(8)));
    $live = $mode === 'live' ? 1 : 0;
    $st = $c->prepare("INSERT INTO palette_orders (order_id, email, lang, amount_usd, tax_usd, status, consent_at, created_at, provider, livemode)
                       VALUES (?, ?, ?, ?, ?, 'created', NOW(), NOW(), 'stripe', ?)");
    $st->bind_param('sssssi', $orderId, $email, $lang, $t['amount'], $t['tax'], $live);
    $st->execute();
    $id = (int)$c->insert_id;
    $st->close();

    $back = PALETTE_STRIPE_BASE . '/checkout?st=%s&lang=' . rawurlencode($lang) . ($mode === 'test' ? '&test=1' : '');
    $r = palette_st_api($c, $mode, 'POST', 'checkout/sessions', [
        'mode' => 'payment',
        'line_items' => [['price_data' => ['currency' => 'usd', 'unit_amount' => $cents, 'product_data' => ['name' => 'Site to Design Token Pro']], 'quantity' => 1]],
        'customer_email' => $email,
        'client_reference_id' => 'palette:order:' . $id,
        'metadata' => ['palette' => 'order', 'row' => $id],
        'locale' => palette_st_locale($lang),
        'success_url' => sprintf($back, '{CHECKOUT_SESSION_ID}'),
        'cancel_url' => sprintf($back, 'cancel'),
    ]);
    $sid = (string)($r['json']['id'] ?? '');
    $url = (string)($r['json']['url'] ?? '');
    if ($r['status'] < 200 || $r['status'] >= 300 || !preg_match(PALETTE_STRIPE_SESSION_RE, $sid) || !str_starts_with($url, 'https://')) {
        palette_mark_failed($c, $id);
        palette_st_log_error('create session', $orderId, $r['status']);
        return ['ok' => false, 'error' => 'err_stripe'];
    }
    $st = $c->prepare("UPDATE palette_orders SET stripe_session = ? WHERE id = ?");
    $st->bind_param('si', $sid, $id);
    $st->execute();
    $st->close();
    return ['ok' => true, 'url' => $url, 'row_id' => $id];
}

function palette_st_row_by_session(mysqli $c, string $table, string $sessionId): ?array
{
    $table = $table === 'palette_subscriptions' ? 'palette_subscriptions' : 'palette_orders';
    $st = $c->prepare("SELECT * FROM $table WHERE stripe_session = ?");
    $st->bind_param('s', $sessionId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ?: null;
}

// The licence key an order holds, or null while it has none.
function palette_st_order_key(mysqli $c, int $orderRowId): ?string
{
    $st = $c->prepare("SELECT l.license_key FROM palette_orders o JOIN palette_licenses l ON l.id = o.license_id WHERE o.id = ?");
    $st->bind_param('i', $orderRowId);
    $st->execute();
    $k = $st->get_result()->fetch_row()[0] ?? null;
    $st->close();
    return is_string($k) ? $k : null;
}

// ['state' => paid|waiting|failed|unknown|error, ...]. 'paid' carries key (null when issued elsewhere),
// plan, row (orders row id, for the caller's ownership check) and email_masked.
function palette_st_confirm(mysqli $c, string $sessionId): array
{
    if (!preg_match(PALETTE_STRIPE_SESSION_RE, $sessionId)) return ['state' => 'unknown'];
    $o = palette_st_row_by_session($c, 'palette_orders', $sessionId);
    if (!$o) {
        $sub = palette_st_row_by_session($c, 'palette_subscriptions', $sessionId);
        return $sub ? palette_st_confirm_sub($c, $sub, $sessionId) : ['state' => 'unknown'];
    }
    $paid = fn(?string $key) => ['state' => 'paid', 'key' => $key, 'plan' => 'pro', 'kind' => 'o', 'row' => (int)$o['id'], 'email_masked' => palette_mask_email((string)$o['email'])];
    if ($o['status'] === 'failed') return ['state' => 'failed'];
    if ($o['status'] === 'captured' && ($key = palette_st_order_key($c, (int)$o['id'])) !== null) return $paid($key);

    $mode = (int)$o['livemode'] === 1 ? 'live' : 'test';
    $r = palette_st_api($c, $mode, 'GET', 'checkout/sessions/' . $sessionId, ['expand' => ['subscription']]);
    if ($r['status'] < 200 || $r['status'] >= 300) {
        palette_st_log_error('get session', (string)$o['order_id'], $r['status']);
        return ['state' => 'error', 'error' => 'err_stripe'];
    }
    $s = $r['json'];
    if (($s['payment_status'] ?? '') !== 'paid') {
        return ['state' => ($s['status'] ?? '') === 'expired' ? 'failed' : 'waiting'];
    }
    $f = palette_st_fulfil_order($c, $o, $s);
    if (!empty($f['retry'])) return ['state' => 'waiting'];
    return $f['ok'] ? $paid($f['key']) : ['state' => 'failed'];
}

// Shared by the return page and the webhook. ['ok', 'key' => ?string, 'already' => bool]; 'retry' => true on a transient failure.
function palette_st_fulfil_order(mysqli $c, array $order, array $session): array
{
    $id = (int)$order['id'];
    $oid = (string)$order['order_id'];
    // The session must be this order's own (the webhook passes event payloads): a foreign one is refused, order untouched.
    if (($session['id'] ?? null) !== ($order['stripe_session'] ?? '') || ($session['client_reference_id'] ?? null) !== 'palette:order:' . $id
        || (string)($session['metadata']['row'] ?? '') !== (string)$id || (int)!empty($session['livemode']) !== (int)$order['livemode']) {
        error_log("PALETTE_PAID_UNFULFILLED order=$oid foreign session");
        return ['ok' => false, 'key' => null, 'already' => false];
    }
    // Delayed payment methods complete the session unpaid; async_payment_succeeded fulfils later. Not a failure.
    if (($session['payment_status'] ?? '') !== 'paid') return ['ok' => false, 'key' => null, 'already' => false, 'retry' => true];
    $paid = (int)($session['amount_total'] ?? -1);
    $want = palette_cents((string)$order['amount_usd']);
    if (strtolower((string)($session['currency'] ?? '')) !== 'usd' || $want === null || $paid < $want) {
        palette_mark_failed($c, $id);
        error_log("PALETTE_PAID_UNFULFILLED order=$oid");
        return ['ok' => false, 'key' => null, 'already' => false];
    }
    $pi = is_string($session['payment_intent'] ?? null) ? substr($session['payment_intent'], 0, 80) : null;

    // Claim first: only the call whose UPDATE flips 'created' issues the key. The transaction makes a
    // concurrent claimer wait on the row lock until license_id is stored.
    $c->begin_transaction();
    try {
        $st = $c->prepare("UPDATE palette_orders SET status = 'captured', captured_at = NOW(), stripe_payment_intent = ? WHERE id = ? AND status = 'created'");
        $st->bind_param('si', $pi, $id);
        $st->execute();
        $claimed = $st->affected_rows === 1;
        $st->close();
        if (!$claimed) {
            $c->commit();
            return palette_st_fulfil_loser($c, $id);
        }
        $lic = palette_issue_license($c, (string)$order['email'], $oid, (string)$order['lang'], null, 'stripe', (int)$order['livemode']);
        $lid = (int)$lic['id'];
        $c->query("UPDATE palette_orders SET license_id = $lid WHERE id = $id");
        $c->commit();
    } catch (Throwable $e) {
        $c->rollback();
        error_log("PALETTE_STRIPE_ERROR fulfil order=$oid failed (will retry): " . $e->getMessage());
        return ['ok' => false, 'key' => null, 'already' => false, 'retry' => true];
    }
    $m = palette_license_email($lic['key'], (string)$order['lang']);
    if (!palette_mail((string)$order['email'], $m['subject'], $m['html'])) error_log("PALETTE_MAIL_FAILED order=$oid");
    return ['ok' => true, 'key' => $lic['key'], 'already' => false];
}

// Another call claimed the order: share its key, waiting briefly if it has not stored license_id yet.
function palette_st_fulfil_loser(mysqli $c, int $id): array
{
    for ($try = 0; $try < 4; $try++) {
        $row = palette_order_by($c, 'id', $id);
        if (!$row || $row['status'] === 'failed') return ['ok' => false, 'key' => null, 'already' => true];
        if (($key = palette_st_order_key($c, $id)) !== null) return ['ok' => true, 'key' => $key, 'already' => true];
        if ($try < 3) usleep(150000);
    }
    return ['ok' => true, 'key' => null, 'already' => true];
}


// ---- Pro + AI by Stripe (subscription). Same shape as the one-payment flow above; rows live in palette_subscriptions.

function palette_st_sub_start(mysqli $c, string $mode, string $email, string $lang, bool $consent, ?int $moveLicenceId = null, ?string $trialEnd = null): array
{
    if (preg_match('/[\r\n]/', $email)) return ['ok' => false, 'error' => 'err_email'];
    $email = strtolower(trim($email));
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'err_email'];
    if (!$consent) return ['ok' => false, 'error' => 'err_consent'];
    $mode = $mode === 'test' ? 'test' : 'live';
    if (!palette_stripe_ready($c, $mode)) return ['ok' => false, 'error' => 'err_paused'];
    $lang = palette_lang($lang);

    $cents = palette_sub_price_cents(palette_settings($c));
    $price = palette_money($cents);
    $live = $mode === 'live' ? 1 : 0;
    $now = palette_sub_now();
    $st = $c->prepare("INSERT INTO palette_subscriptions (email, lang, status, plan_id, price_usd, consent_at, created_at, updated_at, provider, livemode, move_license_id)
                       VALUES (?, ?, 'pending', 'stripe', ?, ?, ?, ?, 'stripe', ?, ?)");
    $st->bind_param('ssssssii', $email, $lang, $price, $now, $now, $now, $live, $moveLicenceId);
    $st->execute();
    $id = (int)$c->insert_id;
    $st->close();

    $back = PALETTE_STRIPE_BASE . '/checkout?st=%s&lang=' . rawurlencode($lang) . ($mode === 'test' ? '&test=1' : '');
    $subData = ['metadata' => ['palette' => 'sub', 'row' => $id]];
    if ($trialEnd !== null && ctype_digit($trialEnd)) $subData['trial_end'] = $trialEnd;
    $r = palette_st_api($c, $mode, 'POST', 'checkout/sessions', [
        'mode' => 'subscription',
        'line_items' => [['price_data' => ['currency' => 'usd', 'unit_amount' => $cents, 'recurring' => ['interval' => 'month'],
            'product_data' => ['name' => 'Site to Design Token Pro + AI']], 'quantity' => 1]],
        'customer_email' => $email,
        'client_reference_id' => 'palette:sub:' . $id,
        'subscription_data' => $subData,
        'locale' => palette_st_locale($lang),
        'success_url' => sprintf($back, '{CHECKOUT_SESSION_ID}'),
        'cancel_url' => sprintf($back, 'cancel'),
    ]);
    $sid = (string)($r['json']['id'] ?? '');
    $url = (string)($r['json']['url'] ?? '');
    if ($r['status'] < 200 || $r['status'] >= 300 || !preg_match(PALETTE_STRIPE_SESSION_RE, $sid) || !str_starts_with($url, 'https://')) {
        $c->query("UPDATE palette_subscriptions SET status = 'cancelled' WHERE id = $id AND status = 'pending'");
        palette_st_log_error('create subscription session', "sub-$id", $r['status']);
        return ['ok' => false, 'error' => 'err_stripe'];
    }
    $st = $c->prepare("UPDATE palette_subscriptions SET stripe_session = ? WHERE id = ?");
    $st->bind_param('si', $sid, $id);
    $st->execute();
    $st->close();
    return ['ok' => true, 'url' => $url, 'row_id' => $id];
}

// The licence key a subscription row holds, or null while it has none.
function palette_st_sub_key(mysqli $c, int $rowId): ?string
{
    $st = $c->prepare("SELECT l.license_key FROM palette_subscriptions s JOIN palette_licenses l ON l.id = s.license_id WHERE s.id = ?");
    $st->bind_param('i', $rowId);
    $st->execute();
    $k = $st->get_result()->fetch_row()[0] ?? null;
    $st->close();
    return is_string($k) ? $k : null;
}

function palette_st_confirm_sub(mysqli $c, array $s, string $sessionId): array
{
    $paid = fn(?string $key) => ['state' => 'paid', 'key' => $key, 'plan' => 'ai', 'kind' => 's', 'row' => (int)$s['id'], 'email_masked' => palette_mask_email((string)$s['email'])];
    if ($s['status'] !== 'pending') {
        $key = palette_st_sub_key($c, (int)$s['id']);
        return $key !== null ? $paid($key) : ['state' => 'failed'];
    }
    $mode = (int)$s['livemode'] === 1 ? 'live' : 'test';
    $r = palette_st_api($c, $mode, 'GET', 'checkout/sessions/' . $sessionId, ['expand' => ['subscription']]);
    if ($r['status'] < 200 || $r['status'] >= 300) {
        palette_st_log_error('get session', 'sub-' . $s['id'], $r['status']);
        return ['state' => 'error', 'error' => 'err_stripe'];
    }
    $sess = $r['json'];
    if (!in_array($sess['payment_status'] ?? '', ['paid', 'no_payment_required'], true)) {
        return ['state' => ($sess['status'] ?? '') === 'expired' ? 'failed' : 'waiting'];
    }
    $f = palette_st_fulfil_sub($c, $s, $sess);
    if (!empty($f['retry'])) return ['state' => 'waiting'];
    return $f['ok'] ? $paid($f['key']) : ['state' => 'failed'];
}

// Unix seconds -> UTC 'Y-m-d H:i:s'; null when absent.
function palette_st_utc(mixed $t): ?string
{
    return (is_int($t) || (is_string($t) && ctype_digit($t))) && (int)$t > 0 ? gmdate('Y-m-d H:i:s', (int)$t) : null;
}

// Shared by the return page and (later) the webhook. Same result shape as palette_st_fulfil_order.
function palette_st_fulfil_sub(mysqli $c, array $row, array $session): array
{
    $id = (int)$row['id'];
    $no = ['ok' => false, 'key' => null, 'already' => false];
    $foreign = function () use ($id, $no) {
        error_log("PALETTE_PAID_UNFULFILLED sub=$id foreign session");
        return $no;
    };
    if (($session['id'] ?? null) !== ($row['stripe_session'] ?? '') || ($session['client_reference_id'] ?? null) !== 'palette:sub:' . $id
        || (int)!empty($session['livemode']) !== (int)$row['livemode']) return $foreign();
    // Delayed payment methods complete the session unpaid. Not a failure: retry.
    if (!in_array($session['payment_status'] ?? '', ['paid', 'no_payment_required'], true)) return $no + ['retry' => true];

    $sub = $session['subscription'] ?? null;
    if (is_string($sub) && preg_match('/^sub_[A-Za-z0-9_]{1,100}$/', $sub)) {   // not expanded (event payload): fetch it
        $mode = (int)$row['livemode'] === 1 ? 'live' : 'test';
        $r = palette_st_api($c, $mode, 'GET', 'subscriptions/' . $sub);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            palette_st_log_error('get subscription', "sub-$id", $r['status']);
            return $no + ['retry' => true];
        }
        $sub = $r['json'];
    }
    if (!is_array($sub) || !preg_match('/^sub_[A-Za-z0-9_]{1,100}$/', (string)($sub['id'] ?? ''))) return $no + ['retry' => true];
    if (isset($sub['metadata']['row']) && (string)$sub['metadata']['row'] !== (string)$id) return $foreign();

    $status = (string)($sub['status'] ?? '');
    if ($status === 'incomplete') return $no + ['retry' => true];
    $item = $sub['items']['data'][0] ?? [];
    $paidCents = (int)($item['price']['unit_amount'] ?? -1);
    $currency = strtolower((string)($item['price']['currency'] ?? $sub['currency'] ?? $session['currency'] ?? ''));
    if (!in_array($status, ['active', 'trialing'], true) || $currency !== 'usd' || $paidCents < palette_sub_row_price($c, $row)) {
        error_log("PALETTE_PAID_UNFULFILLED sub=$id status=$status");
        return $no;
    }
    $sid = (string)$sub['id'];
    $cust = is_string($session['customer'] ?? null) ? substr($session['customer'], 0, 80) : null;
    $now = palette_sub_now();
    // 2024-06-20: the period lives on the subscription; later API versions moved it to the item.
    $start = palette_st_utc($sub['current_period_start'] ?? $item['current_period_start'] ?? null) ?? gmdate('Y-m-d H:i:s');
    $end = palette_st_utc($sub['current_period_end'] ?? $item['current_period_end'] ?? null) ?? gmdate('Y-m-d H:i:s', strtotime($start . ' UTC +1 month'));
    $move = $row['move_license_id'] !== null ? (int)$row['move_license_id'] : null;
    $moved = false;

    // Claim first: only the call whose UPDATE flips 'pending' issues the key; the transaction makes a concurrent
    // claimer wait on the row lock until license_id is stored.
    $c->begin_transaction();
    try {
        $st = $c->prepare("UPDATE palette_subscriptions SET status = 'active', subscription_id = ?, stripe_customer = ?, updated_at = ? WHERE id = ? AND status = 'pending'");
        $st->bind_param('sssi', $sid, $cust, $now, $id);
        $st->execute();
        $claimed = $st->affected_rows === 1;
        $st->close();
        if (!$claimed) {
            $c->commit();
            return palette_st_sub_loser($c, $id);
        }
        $lic = null;
        if ($move !== null) {
            // Move to card: the existing licence takes over, so the customer keeps their key.
            $livemode = (int)$row['livemode'];
            $st = $c->prepare("UPDATE palette_licenses SET subscription_id = ?, source = 'stripe', sub_status = 'active', paid_through = ?, period_start = ?, livemode = ? WHERE id = ?");
            $st->bind_param('sssii', $sid, $end, $start, $livemode, $move);
            $st->execute();
            $st->close();
            $k = $c->query("SELECT id, license_key FROM palette_licenses WHERE id = $move")->fetch_assoc();
            if ($k) {
                $lic = ['id' => (int)$k['id'], 'key' => $k['license_key']];
                $moved = true;
            }
        }
        $lic ??= palette_issue_license($c, (string)$row['email'], $sid, (string)$row['lang'],
            ['subscription_id' => $sid, 'paid_through' => $end, 'period_start' => $start], 'stripe', (int)$row['livemode']);
        $lid = (int)$lic['id'];
        $c->query("UPDATE palette_subscriptions SET license_id = $lid WHERE id = $id");
        $c->commit();
    } catch (Throwable $e) {
        $c->rollback();
        error_log("PALETTE_STRIPE_ERROR fulfil sub=$id failed (will retry): " . $e->getMessage());
        return $no + ['retry' => true];
    }
    if (!$moved) {   // a moved customer already holds the key
        $m = palette_sub_email($lic['key'], (string)$row['lang'], palette_usage_limit(palette_settings($c)));
        if (!palette_mail((string)$row['email'], $m['subject'], $m['html'])) error_log("PALETTE_MAIL_FAILED sub=$id");
    }
    return ['ok' => true, 'key' => $lic['key'], 'already' => false];
}

// Another call claimed the subscription row: share its key, waiting briefly if license_id is not stored yet.
function palette_st_sub_loser(mysqli $c, int $id): array
{
    for ($try = 0; $try < 4; $try++) {
        $row = palette_sub_row($c, 'id', $id);
        if (!$row || $row['status'] === 'cancelled' || $row['status'] === 'expired') return ['ok' => false, 'key' => null, 'already' => true];
        if (($key = palette_st_sub_key($c, $id)) !== null) return ['ok' => true, 'key' => $key, 'already' => true];
        if ($try < 3) usleep(150000);
    }
    return ['ok' => true, 'key' => null, 'already' => true];
}
