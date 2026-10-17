<?php
// PayPal subscriptions: catalog product + monthly billing plan setup (admin, from setup.php).

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
