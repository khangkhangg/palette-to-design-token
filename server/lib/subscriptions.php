<?php
// PayPal subscriptions (Pro + AI): plan setup (admin, from setup.php), start/confirm/fulfil, webhook events,
// customer cancel and the daily reconcile. All licence times are PHP time (ruling A4).

// Strict decimal -> integer cents: "3.99", "3.990", "4.0000" ok; "3.999", "abc", "-1" null.
function palette_dec_cents(mixed $v): ?int
{
    if (!is_string($v) || !preg_match('/^(\d{1,8})(?:\.(\d+))?$/', $v, $m)) return null;
    $frac = $m[2] ?? '';
    if (strlen($frac) > 2 && trim(substr($frac, 2), '0') !== '') return null;
    return (int)$m[1] * 100 + (int)str_pad(substr($frac, 0, 2), 2, '0');
}

// Creates (or reuses by stored id + mode) the product and the monthly plan.
// Returns ['ok' => true, 'plan_id', 'mode', 'created' => bool] or ['ok' => false, 'error'].
function palette_sub_setup(mysqli $c): array
{
    $pp = pp_settings($c);
    if ($pp['client_id'] === '' || $pp['secret'] === '') return ['ok' => false, 'error' => 'err_paused'];
    $mode = (string)$pp['mode'];
    $s = palette_settings($c);
    $cents = palette_dec_cents(is_string($s['ai_price_usd']) ? $s['ai_price_usd'] : (is_int($s['ai_price_usd']) || is_float($s['ai_price_usd']) ? (string)$s['ai_price_usd'] : null));
    if ($cents === null || $cents < 1) return ['ok' => false, 'error' => 'err_bad_price'];
    $price = palette_money($cents);
    $ok = fn(array $r) => $r['status'] >= 200 && $r['status'] < 300;

    $planId = (string)$s['ai_paypal_plan_id'];
    $sameMode = $s['ai_paypal_plan_mode'] === $mode;
    if ($sameMode && preg_match(PALETTE_PAYPAL_ID_RE, $planId)) {
        $r = pp_api($pp, 'GET', '/v1/billing/plans/' . rawurlencode($planId));
        if (palette_transient($r['status']) || $r['status'] === 401 || $r['status'] === 403) {
            error_log('palette: plan check failed (HTTP ' . (int)$r['status'] . ')');
            return ['ok' => false, 'error' => 'err_paypal'];
        }
        $live = palette_dec_cents($r['json']['billing_cycles'][0]['pricing_scheme']['fixed_price']['value'] ?? null);
        if ($ok($r) && ($r['json']['status'] ?? '') === 'ACTIVE' && $live === $cents) {
            return ['ok' => true, 'plan_id' => $planId, 'mode' => $mode, 'created' => false];
        }
    }

    $productId = $sameMode ? (string)$s['ai_paypal_product_id'] : '';
    if (!preg_match(PALETTE_PAYPAL_ID_RE, $productId)) {
        $r = pp_api($pp, 'POST', '/v1/catalogs/products', ['name' => 'Site to Design Token Pro + AI', 'type' => 'SERVICE', 'category' => 'SOFTWARE',
            'description' => 'Monthly Pro + AI plan for the Site to Design Token browser extension']);
        $productId = (string)($r['json']['id'] ?? '');
        if (!$ok($r) || !preg_match(PALETTE_PAYPAL_ID_RE, $productId)) {
            error_log('palette: product creation failed (HTTP ' . (int)$r['status'] . ')');
            return ['ok' => false, 'error' => 'err_paypal'];
        }
        // Persist at once so a failed plan POST doesn't orphan a duplicate product on retry.
        palette_settings_put($c, ['ai_paypal_product_id' => $productId, 'ai_paypal_plan_mode' => $mode, 'ai_paypal_plan_id' => ''] + palette_settings($c));
    }
    $r = pp_api($pp, 'POST', '/v1/billing/plans', ['product_id' => $productId, 'name' => 'Pro + AI monthly', 'status' => 'ACTIVE',
        'billing_cycles' => [['frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1], 'tenure_type' => 'REGULAR', 'sequence' => 1, 'total_cycles' => 0,
            'pricing_scheme' => ['fixed_price' => ['value' => $price, 'currency_code' => 'USD']]]],
        'payment_preferences' => ['auto_bill_outstanding' => true, 'setup_fee_failure_action' => 'CONTINUE', 'payment_failure_threshold' => 1]]);
    $planId = (string)($r['json']['id'] ?? '');
    if (!$ok($r) || !preg_match(PALETTE_PAYPAL_ID_RE, $planId)) {
        error_log('palette: plan creation failed (HTTP ' . (int)$r['status'] . ')');
        return ['ok' => false, 'error' => 'err_paypal'];
    }
    palette_settings_put($c, ['ai_paypal_plan_id' => $planId, 'ai_paypal_plan_mode' => $mode] + palette_settings($c));
    return ['ok' => true, 'plan_id' => $planId, 'mode' => $mode, 'created' => true];
}

// Admin result line for setup.php (everything escaped; never contains secrets).
function palette_sub_result_html(array $r): string
{
    if (empty($r['ok'])) {
        if (($r['error'] ?? '') === 'err_bad_price') return '<p class="err">Plan setup failed: ai_price_usd is not a valid positive USD amount.</p>';
        return '<p class="err">Plan setup failed: ' . palette_h(($r['error'] ?? '') === 'csrf' ? 'the form expired, reload and try again.' : palette_t('en', (string)($r['error'] ?? 'err_paypal'))) . '</p>';
    }
    return '<p class="ok">' . ($r['created'] ? 'Created a new plan' : 'Reused the existing plan') . ' <code>' . palette_h((string)$r['plan_id'])
        . '</code> (' . palette_h((string)$r['mode']) . ').</p>';
}

const PALETTE_SUB_ID_RE = '/^I-[A-Z0-9]{6,32}$/';
const PALETTE_SUB_CANCEL_REASON = 'Cancelled by customer';
const PALETTE_SUB_RECONCILE_BATCH = 200;
const PALETTE_PAYPAL_AUTOPAY_URL = 'https://www.paypal.com/myaccount/autopay/';

function palette_sub_now(): string
{
    return date('Y-m-d H:i:s');
}

// PayPal ISO 8601 time -> the server's PHP-time DATETIME; null when absent or unparseable.
function palette_sub_time(mixed $iso): ?string
{
    if (!is_string($iso) || $iso === '') return null;
    $t = strtotime($iso);
    return $t === false ? null : date('Y-m-d H:i:s', $t);
}

function palette_sub_later(?string $a, ?string $b): ?string
{
    if ($a === null) return $b;
    if ($b === null) return $a;
    return strtotime($b) > strtotime($a) ? $b : $a;
}

function palette_sub_price_cents(array $s): int
{
    $v = $s['ai_price_usd'] ?? null;
    $cents = palette_dec_cents(is_string($v) ? $v : (is_int($v) || is_float($v) ? (string)$v : null));
    return $cents !== null && $cents > 0 ? $cents : 399;
}

function palette_sub_row(mysqli $c, string $col, string|int $val): ?array
{
    $col = $col === 'id' ? 'id' : 'subscription_id';
    $st = $c->prepare("SELECT * FROM palette_subscriptions WHERE $col = ?");
    $v = (string)$val;
    $st->bind_param('s', $v);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ?: null;
}

// Row id from custom_id "palette-sub:<id>"; null when absent; false when it belongs to someone else.
function palette_sub_custom_row(array $res): int|false|null
{
    $cid = $res['custom_id'] ?? null;
    if ($cid === null || $cid === '') return null;
    return is_string($cid) && preg_match('/^palette-sub:(\d{1,10})$/', $cid, $m) ? (int)$m[1] : false;
}

// True when PayPal's subscription object is the one this row handed out: its custom_id, its plan, and the
// subscription id the row is bound to (if any).
function palette_sub_matches(array $row, array $sub): bool
{
    $sid = $sub['id'] ?? null;
    return is_string($sid) && preg_match(PALETTE_SUB_ID_RE, $sid)
        && palette_sub_custom_row($sub) === (int)$row['id']
        && ($sub['plan_id'] ?? null) === $row['plan_id']
        && ($row['subscription_id'] === null || $row['subscription_id'] === $sid);
}

// The last payment, when PayPal reports one, must be at least the price in USD.
function palette_sub_paid_ok(array $sub, array $settings): bool
{
    $lp = $sub['billing_info']['last_payment'] ?? null;
    if (!is_array($lp)) return true;
    $cents = palette_dec_cents($lp['amount']['value'] ?? null);
    return ($lp['amount']['currency_code'] ?? '') === 'USD' && $cents !== null && $cents >= palette_sub_price_cents($settings);
}

// GET /v1/billing/subscriptions/{id}: ['ok' => true, 'sub'] or ['ok' => false, 'retry' => bool].
function palette_sub_get(mysqli $c, string $subId): array
{
    $r = pp_api(pp_settings($c), 'GET', '/v1/billing/subscriptions/' . rawurlencode($subId));
    if ($r['status'] === 200 && is_array($r['json']) && ($r['json']['id'] ?? null) === $subId) return ['ok' => true, 'sub' => $r['json']];
    error_log("palette: GET subscription $subId failed (HTTP " . (int)$r['status'] . ')');
    return ['ok' => false, 'retry' => palette_transient((int)$r['status'])];
}

function palette_sub_start(mysqli $c, string $email, string $lang, bool $consent): array
{
    if (preg_match('/[\r\n]/', $email)) return ['ok' => false, 'error' => 'err_email'];
    $email = strtolower(trim($email));
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'err_email'];
    if (!$consent) return ['ok' => false, 'error' => 'err_consent'];
    $pp = pp_settings($c);
    $s = palette_settings($c);
    $plan = (string)$s['ai_paypal_plan_id'];
    // A plan made in sandbox does not exist in live (and vice versa): paused until setup.php runs for this mode.
    if (!pp_ready($pp) || !preg_match(PALETTE_PAYPAL_ID_RE, $plan) || $s['ai_paypal_plan_mode'] !== $pp['mode']) return ['ok' => false, 'error' => 'err_paused'];
    $lang = palette_lang($lang);
    $now = palette_sub_now();
    $st = $c->prepare("INSERT INTO palette_subscriptions (email, lang, status, plan_id, consent_at, created_at, updated_at) VALUES (?, ?, 'pending', ?, ?, ?, ?)");
    $st->bind_param('ssssss', $email, $lang, $plan, $now, $now, $now);
    $st->execute();
    $id = (int)$c->insert_id;
    $st->close();
    return ['ok' => true, 'plan_id' => $plan, 'custom_id' => 'palette-sub:' . $id, 'row_id' => $id];
}

// The issued key for a fulfilled row, as palette_sub_confirm answers it.
function palette_sub_result(mysqli $c, int $rowId): array
{
    $st = $c->prepare("SELECT l.license_key, l.email FROM palette_subscriptions s JOIN palette_licenses l ON l.id = s.license_id
                       WHERE s.id = ? AND s.status <> 'pending'");
    $st->bind_param('i', $rowId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ? ['ok' => true, 'key' => $r['license_key'], 'email_masked' => palette_mask_email($r['email']), 'row_id' => $rowId]
              : ['ok' => false, 'error' => 'err_paypal'];
}

// The key is always returned here; the API shows it only to the session that started the subscription.
function palette_sub_confirm(mysqli $c, string $subscriptionId): array
{
    if (!preg_match(PALETTE_SUB_ID_RE, $subscriptionId)) return ['ok' => false, 'error' => 'err_bad_request'];
    $g = palette_sub_get($c, $subscriptionId);
    if (!$g['ok']) return ['ok' => false, 'error' => 'err_paypal'] + ($g['retry'] ? ['retry' => true] : []);
    $sub = $g['sub'];
    $rowId = palette_sub_custom_row($sub);
    $row = is_int($rowId) ? palette_sub_row($c, 'id', $rowId) : null;
    if (!$row || !palette_sub_matches($row, $sub)) {
        error_log("palette: subscription $subscriptionId does not match a palette checkout (custom_id " . json_encode($sub['custom_id'] ?? null)
            . ', plan ' . json_encode($sub['plan_id'] ?? null) . ')');
        return ['ok' => false, 'error' => 'err_paypal'];
    }
    if ($row['status'] !== 'pending') {
        $r = palette_sub_result($c, (int)$row['id']);
        return $r['ok'] ? $r + ['already' => true] : $r;
    }
    $status = (string)($sub['status'] ?? '');
    if ($status === 'APPROVAL_PENDING' || $status === 'APPROVED') return ['ok' => true, 'pending' => true, 'row_id' => (int)$row['id']];
    if ($status !== 'ACTIVE') {
        error_log("palette: subscription $subscriptionId is $status at confirm");
        return ['ok' => false, 'error' => 'err_paypal'];
    }
    return palette_sub_fulfil($c, $row, $sub);
}

// Claim first: only the request whose UPDATE flips 'pending' issues the key (the transaction makes a concurrent
// claimer wait for license_id). Idempotent: later calls return the issued key with 'already'.
function palette_sub_fulfil(mysqli $c, array $subRow, array $paypalSub): array
{
    $id = (int)$subRow['id'];
    if (!palette_sub_matches($subRow, $paypalSub) || ($paypalSub['status'] ?? '') !== 'ACTIVE') return ['ok' => false, 'error' => 'err_paypal'];
    $settings = palette_settings($c);
    if (!palette_sub_paid_ok($paypalSub, $settings)) {
        error_log("palette: subscription {$paypalSub['id']} paid " . json_encode($paypalSub['billing_info']['last_payment']['amount'] ?? null) . ', below the price');
        return ['ok' => false, 'error' => 'err_paypal'];
    }
    $sid = (string)$paypalSub['id'];
    $now = palette_sub_now();
    $paid = palette_sub_time($paypalSub['billing_info']['next_billing_time'] ?? null) ?? date('Y-m-d H:i:s', strtotime('+1 month'));

    $c->begin_transaction();
    try {
        $st = $c->prepare("UPDATE palette_subscriptions SET status = 'active', subscription_id = ?, updated_at = ? WHERE id = ? AND status = 'pending'");
        $st->bind_param('ssi', $sid, $now, $id);
        $st->execute();
        $claimed = $st->affected_rows === 1;
        $st->close();
        if (!$claimed) {
            $c->commit();
            $r = palette_sub_result($c, $id);
            return $r['ok'] ? $r + ['already' => true] : $r;
        }
        $lic = palette_issue_license($c, (string)$subRow['email'], $sid, (string)$subRow['lang'],
            ['subscription_id' => $sid, 'paid_through' => $paid, 'period_start' => $now]);
        $lid = (int)$lic['id'];
        $c->query("UPDATE palette_subscriptions SET license_id = $lid WHERE id = $id");
        $c->commit();
    } catch (Throwable $e) {
        $c->rollback();
        error_log("palette: fulfil of subscription $sid failed (will retry): " . $e->getMessage());
        return ['ok' => false, 'error' => 'err_paypal', 'retry' => true];
    }
    $m = palette_sub_email($lic['key'], (string)$subRow['lang'], palette_usage_limit($settings));
    if (!palette_mail((string)$subRow['email'], $m['subject'], $m['html'])) {
        error_log("PALETTE_MAIL_FAILED subscription=$sid");   // alert on this prefix; the buyer saw the key on the page
    }
    return ['ok' => true, 'key' => $lic['key'], 'email_masked' => palette_mask_email((string)$subRow['email']), 'row_id' => $id];
}

// Moves a fulfilled row (and its licence's sub_status) to $to, only from one of $from. True when it changed.
function palette_sub_set_status(mysqli $c, array $row, string $to, array $from): bool
{
    $id = (int)$row['id'];
    $in = implode(',', array_map(fn($s) => "'" . $c->real_escape_string($s) . "'", $from));
    $now = palette_sub_now();
    $c->begin_transaction();
    try {
        $st = $c->prepare("UPDATE palette_subscriptions SET status = ?, updated_at = ? WHERE id = ? AND status IN ($in)");
        $st->bind_param('ssi', $to, $now, $id);
        $st->execute();
        $changed = $st->affected_rows === 1;
        $st->close();
        if ($changed && $row['license_id'] !== null) {
            $lid = (int)$row['license_id'];
            $st = $c->prepare('UPDATE palette_licenses SET sub_status = ? WHERE id = ?');
            $st->bind_param('si', $to, $lid);
            $st->execute();
            $st->close();
        }
        $c->commit();
        return $changed;
    } catch (Throwable $e) {
        $c->rollback();
        throw $e;
    }
}

// Webhook events for subscriptions and their sales (the event is already signature-verified by palette_webhook).
// Returns 'activated'|'renewed'|'cancelled'|'suspended'|'expired'|'revoked'|'already'|'ignored'|'retry'.
function palette_sub_event(mysqli $c, string $type, array $resource): string
{
    try {
        if ($type === 'PAYMENT.SALE.COMPLETED') return palette_sub_sale($c, $resource);
        if ($type === 'PAYMENT.SALE.REFUNDED' || $type === 'PAYMENT.SALE.REVERSED') return palette_sub_refund($c, $type, $resource);
        if (str_starts_with($type, 'BILLING.SUBSCRIPTION.')) return palette_sub_status_event($c, $type, $resource);
    } catch (Throwable $e) {
        error_log("palette: $type failed (will retry): " . $e->getMessage());
        return 'retry';
    }
    return 'ignored';
}

function palette_sub_status_event(mysqli $c, string $type, array $res): string
{
    $sid = $res['id'] ?? null;
    if (!is_string($sid) || !preg_match(PALETTE_SUB_ID_RE, $sid)) return 'ignored';
    $cr = palette_sub_custom_row($res);
    if ($cr === false) return 'ignored';   // a companies subscription on the shared PayPal app
    $row = palette_sub_row($c, 'subscription_id', $sid);
    if (!$row && is_int($cr)) {
        $row = palette_sub_row($c, 'id', $cr);
        if ($row && $row['subscription_id'] !== null) $row = null;   // bound to another subscription
    }
    if (!$row) return 'ignored';
    if (is_int($cr) && $cr !== (int)$row['id']) {
        error_log("palette: $type custom_id palette-sub:$cr does not match subscription row {$row['id']}");
        return 'ignored';
    }
    if ($type === 'BILLING.SUBSCRIPTION.ACTIVATED') {
        if ($row['status'] !== 'pending') return 'already';
        $r = palette_sub_fulfil($c, $row, $res);
        if ($r['ok']) return empty($r['already']) ? 'activated' : 'already';
        if (!empty($r['retry'])) return 'retry';
        error_log("palette: ACTIVATED for $sid not fulfillable");
        return 'ignored';
    }
    if ($row['status'] === 'pending') return 'ignored';   // never fulfilled: no licence to change
    // Status only: access stays until paid_through. Cancelled and expired are final, so a late suspension
    // never overrides them.
    switch ($type) {
        case 'BILLING.SUBSCRIPTION.CANCELLED':
            palette_sub_set_status($c, $row, 'cancelled', ['active', 'suspended']);
            return 'cancelled';
        case 'BILLING.SUBSCRIPTION.SUSPENDED':
        case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
            palette_sub_set_status($c, $row, 'suspended', ['active']);
            return 'suspended';
        case 'BILLING.SUBSCRIPTION.EXPIRED':
            palette_sub_set_status($c, $row, 'expired', ['active', 'suspended', 'cancelled']);
            return 'expired';
    }
    return 'ignored';
}

// PAYMENT.SALE.COMPLETED: the first payment or a renewal.
function palette_sub_sale(mysqli $c, array $res): string
{
    $saleId = $res['id'] ?? null;
    $sid = $res['billing_agreement_id'] ?? null;
    if (!is_string($sid) || !preg_match(PALETTE_SUB_ID_RE, $sid) || !is_string($saleId) || !preg_match(PALETTE_PAYPAL_ID_RE, $saleId)) return 'ignored';
    $row = palette_sub_row($c, 'subscription_id', $sid);
    $sub = null;
    if (!$row) {
        // Unknown subscription id: a renewal-before-activation, or someone else's. Its custom_id decides.
        $g = palette_sub_get($c, $sid);
        if (!$g['ok']) return $g['retry'] ? 'retry' : 'ignored';
        $sub = $g['sub'];
        $cr = palette_sub_custom_row($sub);
        $row = is_int($cr) ? palette_sub_row($c, 'id', $cr) : null;
        if (!$row || !palette_sub_matches($row, $sub)) return 'ignored';
    }
    $st = $c->prepare('SELECT 1 FROM palette_sub_payments WHERE sale_id = ?');
    $st->bind_param('s', $saleId);
    $st->execute();
    $seen = $st->get_result()->num_rows > 0;
    $st->close();
    if ($seen) return 'already';

    $cents = palette_dec_cents($res['amount']['total'] ?? null);
    if (($res['amount']['currency'] ?? '') !== 'USD' || $cents === null || $cents < palette_sub_price_cents(palette_settings($c))) {
        error_log("palette: sale $saleId for $sid paid " . json_encode($res['amount'] ?? null) . ', below the price; ignored');
        return 'ignored';
    }
    if ($sub === null) {
        $g = palette_sub_get($c, $sid);
        if (!$g['ok']) return $g['retry'] ? 'retry' : 'ignored';
        $sub = $g['sub'];
    }
    if ($row['status'] === 'pending') {
        $f = palette_sub_fulfil($c, $row, $sub);
        if (!$f['ok']) {
            if (!empty($f['retry'])) return 'retry';
            error_log("palette: sale $saleId arrived for $sid but the subscription is not fulfillable");
            return 'ignored';
        }
        $row = palette_sub_row($c, 'id', (int)$row['id']);
    }
    $paidAt = palette_sub_time($res['create_time'] ?? null) ?? palette_sub_now();
    $next = palette_sub_time($sub['billing_info']['next_billing_time'] ?? null) ?? date('Y-m-d H:i:s', strtotime($paidAt . ' +1 month'));
    $amount = palette_money($cents);

    $c->begin_transaction();
    try {
        $st = $c->prepare("INSERT INTO palette_sub_payments (subscription_id, sale_id, amount_usd, status, paid_at) VALUES (?, ?, ?, 'completed', ?)");
        $st->bind_param('ssss', $sid, $saleId, $amount, $paidAt);
        $st->execute();
        $st->close();
        palette_sub_extend($c, $row, $next, $paidAt);
        $c->commit();
    } catch (mysqli_sql_exception $e) {
        $c->rollback();
        if ($e->getCode() === 1062) return 'already';   // a concurrent delivery of the same sale
        throw $e;
    }
    if ($row['status'] === 'suspended') palette_sub_set_status($c, $row, 'active', ['suspended']);   // the outstanding balance was paid
    return 'renewed';
}

// Call inside a transaction. paid_through only moves forward; period_start (the usage period) moves only to a
// later payment, which resets usage. True when the licence changed.
function palette_sub_extend(mysqli $c, array $row, ?string $paidThrough, ?string $periodStart): bool
{
    $lid = (int)$row['license_id'];
    $l = $c->query("SELECT paid_through, period_start FROM palette_licenses WHERE id = $lid FOR UPDATE")->fetch_assoc();
    if (!$l) return false;
    $pt = palette_sub_later($l['paid_through'], $paidThrough);
    $ps = palette_sub_later($l['period_start'], $periodStart);
    $rid = (int)$row['id'];
    $c->query("UPDATE palette_subscriptions SET updated_at = '" . $c->real_escape_string(palette_sub_now()) . "' WHERE id = $rid");
    if ($pt === $l['paid_through'] && $ps === $l['period_start']) return false;
    $st = $c->prepare('UPDATE palette_licenses SET paid_through = ?, period_start = ? WHERE id = ?');
    $st->bind_param('ssi', $pt, $ps, $lid);
    $st->execute();
    $st->close();
    return true;
}

// PAYMENT.SALE.REFUNDED (resource = the refund, with sale_id) or REVERSED (resource = the sale): revoke the ai
// licence now. The subscription comes from the recorded sale, else from billing_agreement_id.
function palette_sub_refund(mysqli $c, string $type, array $res): string
{
    $saleId = $res['sale_id'] ?? $res['id'] ?? null;
    $sid = null;
    if (is_string($saleId) && preg_match(PALETTE_PAYPAL_ID_RE, $saleId)) {
        $st = $c->prepare('SELECT subscription_id FROM palette_sub_payments WHERE sale_id = ?');
        $st->bind_param('s', $saleId);
        $st->execute();
        $sid = $st->get_result()->fetch_row()[0] ?? null;
        $st->close();
    }
    $ba = $res['billing_agreement_id'] ?? null;
    if ($sid === null && is_string($ba) && preg_match(PALETTE_SUB_ID_RE, $ba)) $sid = $ba;
    $row = $sid === null ? null : palette_sub_row($c, 'subscription_id', $sid);
    if (!$row || $row['license_id'] === null) return 'ignored';   // not a palette sale
    if (is_string($saleId)) {
        $mark = $type === 'PAYMENT.SALE.REFUNDED' ? 'refunded' : 'reversed';
        $st = $c->prepare('UPDATE palette_sub_payments SET status = ? WHERE sale_id = ?');
        $st->bind_param('ss', $mark, $saleId);
        $st->execute();
        $st->close();
    }
    $lid = (int)$row['license_id'];
    $now = palette_sub_now();
    $st = $c->prepare('UPDATE palette_licenses SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL');
    $st->bind_param('si', $now, $lid);
    $st->execute();
    $st->close();
    error_log("palette: $type for subscription $sid; licence revoked");
    return 'revoked';
}

// Owner = the signed-in user the key is claimed by, or whoever knows the key and the subscribing email.
// ['ok' => true, 'ends_on' => 'Y-m-d'] or ['ok' => false, 'error' => err_invalid_key|err_not_owner|err_paypal].
function palette_sub_cancel(mysqli $c, string $rawKey, ?int $userId, ?string $email): array
{
    $key = palette_normalise_key($rawKey);
    if ($key === null) return ['ok' => false, 'error' => 'err_invalid_key'];
    $st = $c->prepare("SELECT s.*, l.user_id, l.paid_through, l.revoked_at FROM palette_licenses l JOIN palette_subscriptions s ON s.license_id = l.id
                       WHERE l.license_key = ? AND l.plan = 'ai'");
    $st->bind_param('s', $key);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row || $row['revoked_at'] !== null || $row['subscription_id'] === null) return ['ok' => false, 'error' => 'err_invalid_key'];
    $byUser = $userId !== null && $userId > 0 && $row['user_id'] !== null && (int)$row['user_id'] === $userId;
    $byEmail = $email !== null && trim($email) !== '' && strcasecmp(trim($email), (string)$row['email']) === 0;
    if (!$byUser && !$byEmail) return ['ok' => false, 'error' => 'err_not_owner'];
    $endsOn = $row['paid_through'] === null ? null : date('Y-m-d', strtotime($row['paid_through']));
    if ($row['status'] === 'cancelled' || $row['status'] === 'expired') return ['ok' => true, 'ends_on' => $endsOn];

    $sid = (string)$row['subscription_id'];
    $r = palette_pp(pp_settings($c), 'POST', '/v1/billing/subscriptions/' . rawurlencode($sid) . '/cancel',
                    json_encode(['reason' => PALETTE_SUB_CANCEL_REASON]));
    // 422: PayPal has already cancelled it, which is what the customer asked for.
    if ($r['status'] !== 204 && $r['status'] !== 422) {
        error_log("palette: cancel of $sid failed (HTTP " . (int)$r['status'] . ')');
        return ['ok' => false, 'error' => 'err_paypal'];
    }
    palette_sub_set_status($c, $row, 'cancelled', ['active', 'suspended']);
    $m = palette_sub_cancel_email((string)$endsOn, (string)$row['lang']);
    if (!palette_mail((string)$row['email'], $m['subject'], $m['html'])) error_log("PALETTE_MAIL_FAILED cancel=$sid");
    return ['ok' => true, 'ends_on' => $endsOn];
}

// Daily (cron-subscriptions.php): GETs up to 200 active, suspended or still-paid cancelled subscriptions, least
// recently updated first, and syncs status, paid_through and the usage period. Revoked licences are left alone.
function palette_sub_reconcile(mysqli $c): array
{
    $now = palette_sub_now();
    $limit = PALETTE_SUB_RECONCILE_BATCH;
    $st = $c->prepare("SELECT s.* FROM palette_subscriptions s JOIN palette_licenses l ON l.id = s.license_id
                       WHERE l.revoked_at IS NULL AND s.subscription_id IS NOT NULL
                         AND (s.status IN ('active', 'suspended') OR (s.status = 'cancelled' AND l.paid_through >= ?))
                       ORDER BY s.updated_at, s.id LIMIT ?");
    $st->bind_param('si', $now, $limit);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $settings = palette_settings($c);
    $map = ['ACTIVE' => 'active', 'SUSPENDED' => 'suspended', 'CANCELLED' => 'cancelled', 'EXPIRED' => 'expired'];
    $out = ['checked' => 0, 'changed' => 0, 'failed' => 0];
    foreach ($rows as $row) {
        $out['checked']++;
        $rid = (int)$row['id'];
        $c->query("UPDATE palette_subscriptions SET updated_at = '" . $c->real_escape_string(palette_sub_now()) . "' WHERE id = $rid");   // rotate the batch
        try {
            $g = palette_sub_get($c, (string)$row['subscription_id']);
            if (!$g['ok'] || !palette_sub_matches($row, $g['sub']) || !isset($map[$g['sub']['status'] ?? ''])) {
                $out['failed']++;
                continue;
            }
            $sub = $g['sub'];
            $to = $map[$sub['status']];
            $changed = false;
            if ($to === 'active' && palette_sub_paid_ok($sub, $settings)) {
                // A missed renewal: ACTIVE means PayPal collected the last cycle (payment_failure_threshold=1 suspends otherwise).
                $c->begin_transaction();
                try {
                    $changed = palette_sub_extend($c, $row, palette_sub_time($sub['billing_info']['next_billing_time'] ?? null),
                                                  palette_sub_time($sub['billing_info']['last_payment']['time'] ?? null));
                    $c->commit();
                } catch (Throwable $e) {
                    $c->rollback();
                    throw $e;
                }
            }
            if ($to !== $row['status']) $changed = palette_sub_set_status($c, $row, $to, [$row['status']]) || $changed;
            if ($changed) $out['changed']++;
        } catch (Throwable $e) {
            error_log("palette: reconcile of {$row['subscription_id']} failed: " . $e->getMessage());
            $out['failed']++;
        }
    }
    return $out;
}
