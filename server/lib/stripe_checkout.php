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
        // Task 4 adds one branch here: a palette_subscriptions row for this session fulfils the subscription.
        return ['state' => 'unknown'];
    }
    $paid = fn(?string $key) => ['state' => 'paid', 'key' => $key, 'plan' => 'pro', 'row' => (int)$o['id'], 'email_masked' => palette_mask_email((string)$o['email'])];
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
    $paid = (int)($session['amount_total'] ?? -1);
    $want = palette_cents((string)$order['amount_usd']);
    if (($session['payment_status'] ?? '') !== 'paid' || strtolower((string)($session['currency'] ?? '')) !== 'usd' || $want === null || $paid < $want) {
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
