<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/pp-stub.php';
ini_set('error_log', sys_get_temp_dir() . '/palette-test.log');

function sub_reset(mysqli $c): void
{
    stub_reset($c);
    palette_settings_put($c, []);
    $GLOBALS['pp_routes']['POST /v1/catalogs/products'] = ['status' => 201, 'json' => ['id' => 'PROD-1']];
    $GLOBALS['pp_routes']['POST /v1/billing/plans'] = ['status' => 201, 'json' => ['id' => 'P-1']];
    $GLOBALS['pp_routes']['GET /v1/billing/plans/P-1'] = ['status' => 200, 'json' => ['id' => 'P-1', 'status' => 'ACTIVE',
        'billing_cycles' => [['pricing_scheme' => ['fixed_price' => ['value' => '3.99', 'currency_code' => 'USD']]]]]];
}

// ---- first run creates product then plan
sub_reset($conn);
$r = palette_sub_setup($conn);
ok('first run ok, created', $r === ['ok' => true, 'plan_id' => 'P-1', 'mode' => 'sandbox', 'created' => true]);
$order = array_map(fn($x) => $x['method'] . ' ' . $x['path'], $GLOBALS['pp_calls']);
ok('product POSTed before plan', $order === ['POST /v1/catalogs/products', 'POST /v1/billing/plans']);
ok('product body', calls_to('POST', '/v1/catalogs/products')[0]['body'] === ['name' => 'Site to Design Token Pro + AI', 'type' => 'SERVICE', 'category' => 'SOFTWARE',
    'description' => 'Monthly Pro + AI plan for the Site to Design Token browser extension']);
ok('plan body exact', calls_to('POST', '/v1/billing/plans')[0]['body'] === ['product_id' => 'PROD-1', 'name' => 'Pro + AI monthly', 'status' => 'ACTIVE',
    'billing_cycles' => [['frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1], 'tenure_type' => 'REGULAR', 'sequence' => 1, 'total_cycles' => 0,
        'pricing_scheme' => ['fixed_price' => ['value' => '3.99', 'currency_code' => 'USD']]]],
    'payment_preferences' => ['auto_bill_outstanding' => true, 'setup_fee_failure_action' => 'CONTINUE', 'payment_failure_threshold' => 1]]);
$s = palette_settings($conn);
ok('stores plan id, mode, product id', $s['ai_paypal_plan_id'] === 'P-1' && $s['ai_paypal_plan_mode'] === 'sandbox' && $s['ai_paypal_product_id'] === 'PROD-1');
ok('other settings kept', $s['price_usd'] === '1.49' && $s['ai_price_usd'] === '3.99');
ok('A18: stores the plan price it created', $s['ai_paypal_plan_price'] === '3.99');

// ---- second run, same mode: reuse, no POST
$GLOBALS['pp_calls'] = [];
$r = palette_sub_setup($conn);
ok('second run reuses', $r === ['ok' => true, 'plan_id' => 'P-1', 'mode' => 'sandbox', 'created' => false]);
ok('second run: no POST', array_filter($GLOBALS['pp_calls'], fn($x) => $x['method'] === 'POST') === [] && count(calls_to('GET', '/v1/billing/plans/P-1')) === 1);

// ---- price changed -> new plan, product reused
palette_settings_put($conn, ['ai_price_usd' => '4.99'] + palette_settings($conn));
$GLOBALS['pp_routes']['POST /v1/billing/plans'] = ['status' => 201, 'json' => ['id' => 'P-2']];
$GLOBALS['pp_calls'] = [];
$r = palette_sub_setup($conn);
ok('price change -> new plan', ($r['plan_id'] ?? '') === 'P-2' && $r['created'] === true && palette_settings($conn)['ai_paypal_plan_id'] === 'P-2');
ok('A18: price change -> new plan price stored', palette_settings($conn)['ai_paypal_plan_price'] === '4.99');
ok('price change: product reused, new price', calls_to('POST', '/v1/catalogs/products') === []
    && calls_to('POST', '/v1/billing/plans')[0]['body']['billing_cycles'][0]['pricing_scheme']['fixed_price']['value'] === '4.99'
    && calls_to('POST', '/v1/billing/plans')[0]['body']['product_id'] === 'PROD-1');

// ---- inactive stored plan -> new plan
sub_reset($conn);
palette_sub_setup($conn);
$GLOBALS['pp_routes']['GET /v1/billing/plans/P-1']['json']['status'] = 'INACTIVE';
$GLOBALS['pp_routes']['POST /v1/billing/plans'] = ['status' => 201, 'json' => ['id' => 'P-3']];
ok('inactive plan -> new plan', palette_sub_setup($conn)['plan_id'] === 'P-3');
// plan gone (404) -> new plan
$GLOBALS['pp_routes']['GET /v1/billing/plans/P-3'] = ['status' => 404, 'json' => ['name' => 'RESOURCE_NOT_FOUND']];
$GLOBALS['pp_routes']['POST /v1/billing/plans'] = ['status' => 201, 'json' => ['id' => 'P-4']];
ok('404 plan -> new plan', palette_sub_setup($conn)['plan_id'] === 'P-4');
// transient check failure -> no duplicate plan
$GLOBALS['pp_routes']['GET /v1/billing/plans/P-4'] = ['status' => 503, 'json' => []];
$GLOBALS['pp_calls'] = [];
ok('transient plan check -> err_paypal, no POST', palette_sub_setup($conn) === ['ok' => false, 'error' => 'err_paypal'] && calls_to('POST', '/v1/') === []);

// ---- mode changed -> new product and plan
sub_reset($conn);
palette_sub_setup($conn);
$st = $conn->prepare("UPDATE admin_settings SET setting_value = ? WHERE setting_key = 'paypal'");
$j = json_encode(['enabled' => true, 'mode' => 'live', 'client_id' => 'cid', 'secret' => 'sec', 'error' => '']);
$st->bind_param('s', $j);
$st->execute();
$GLOBALS['pp_routes']['POST /v1/catalogs/products'] = ['status' => 201, 'json' => ['id' => 'PROD-LIVE']];
$GLOBALS['pp_routes']['POST /v1/billing/plans'] = ['status' => 201, 'json' => ['id' => 'P-LIVE']];
$GLOBALS['pp_calls'] = [];
$r = palette_sub_setup($conn);
$s = palette_settings($conn);
ok('mode change -> new product + plan', $r === ['ok' => true, 'plan_id' => 'P-LIVE', 'mode' => 'live', 'created' => true]
    && calls_to('GET', '/v1/billing/plans') === [] && count(calls_to('POST', '/v1/catalogs/products')) === 1
    && calls_to('POST', '/v1/billing/plans')[0]['body']['product_id'] === 'PROD-LIVE'
    && $s['ai_paypal_plan_mode'] === 'live' && $s['ai_paypal_product_id'] === 'PROD-LIVE');

// ---- failures
sub_reset($conn);
$GLOBALS['pp_routes']['POST /v1/catalogs/products'] = ['status' => 400, 'json' => ['message' => 'bad']];
ok('product failure -> err_paypal, nothing stored', palette_sub_setup($conn) === ['ok' => false, 'error' => 'err_paypal']
 && palette_settings($conn)['ai_paypal_plan_id'] === ''
    && calls_to('POST', '/v1/billing/plans') === []);
sub_reset($conn);
$GLOBALS['pp_routes']['POST /v1/billing/plans'] = ['status' => 422, 'json' => []];
ok('plan failure -> err_paypal, nothing stored', palette_sub_setup($conn) === ['ok' => false, 'error' => 'err_paypal']
    && palette_settings($conn)['ai_paypal_plan_id'] === '' && palette_settings($conn)['ai_paypal_product_id'] === 'PROD-1');
// retry after the failed plan POST reuses the stored product
$GLOBALS['pp_routes']['POST /v1/billing/plans'] = ['status' => 201, 'json' => ['id' => 'P-RETRY']];
$GLOBALS['pp_calls'] = [];
ok('retry reuses stored product (no duplicate)', palette_sub_setup($conn)['plan_id'] === 'P-RETRY' && calls_to('POST', '/v1/catalogs/products') === []);

// ---- price normalisation
foreach ([['3.990', '3.99'], ['3.9900', '3.99'], ['4.0000', '4.00'], ['4', '4.00']] as [$live, $set]) {
    sub_reset($conn);
    palette_settings_put($conn, ['ai_price_usd' => $set]);
    palette_sub_setup($conn);
    $GLOBALS['pp_routes']['GET /v1/billing/plans/P-1']['json']['billing_cycles'][0]['pricing_scheme']['fixed_price']['value'] = $live;
    $GLOBALS['pp_calls'] = [];
    $r = palette_sub_setup($conn);
    ok("live price '$live' equals settings '$set': reused, no POST", ($r['created'] ?? null) === false && calls_to('POST', '/v1/') === []);
    ok("A18: reuse stores the plan price as '" . palette_money(palette_dec_cents($set)) . "'", palette_settings($conn)['ai_paypal_plan_price'] === palette_money(palette_dec_cents($set)));
}
sub_reset($conn);
palette_sub_setup($conn);
$GLOBALS['pp_routes']['GET /v1/billing/plans/P-1']['json']['billing_cycles'][0]['pricing_scheme']['fixed_price']['value'] = '3.999';
$GLOBALS['pp_routes']['POST /v1/billing/plans'] = ['status' => 201, 'json' => ['id' => 'P-9']];
ok('live price 3.999 differs -> new plan', palette_sub_setup($conn)['plan_id'] === 'P-9');
foreach (['0', '0.00', 'abc', '-1', '3.999', '', '1e2'] as $bad) {
    sub_reset($conn);
    palette_settings_put($conn, ['ai_price_usd' => $bad]);
    $GLOBALS['pp_calls'] = [];
    ok("invalid ai_price_usd '$bad' -> err_bad_price, no calls", palette_sub_setup($conn) === ['ok' => false, 'error' => 'err_bad_price'] && $GLOBALS['pp_calls'] === []);
}
ok('dec_cents: a trailing newline is not a price (/D)', palette_dec_cents("3.99\n") === null && palette_dec_cents('3.99') === 399);
ok('bad price shown in result html', str_contains(palette_sub_result_html(['ok' => false, 'error' => 'err_bad_price']), 'ai_price_usd'));
foreach ([401, 403] as $code) {
    sub_reset($conn);
    palette_sub_setup($conn);
    $GLOBALS['pp_routes']['GET /v1/billing/plans/P-1'] = ['status' => $code, 'json' => []];
    $GLOBALS['pp_calls'] = [];
    ok("plan GET $code -> err_paypal, nothing created", palette_sub_setup($conn) === ['ok' => false, 'error' => 'err_paypal'] && calls_to('POST', '/v1/') === []);
}
sub_reset($conn);
$GLOBALS['pp_routes']['POST /v1/billing/plans'] = ['status' => 201, 'json' => ['id' => 'bad id!']];
ok('malformed plan id rejected', palette_sub_setup($conn)['ok'] === false);
sub_reset($conn);
$conn->query("UPDATE admin_settings SET setting_value = '{\"enabled\":false,\"client_id\":\"\",\"secret\":\"\"}' WHERE setting_key = 'paypal'");
$GLOBALS['pp_calls'] = [];
ok('no PayPal keys -> err_paused, no calls', palette_sub_setup($conn) === ['ok' => false, 'error' => 'err_paused'] && $GLOBALS['pp_calls'] === []);

// ---- setup.php output
$h = palette_sub_result_html(['ok' => true, 'plan_id' => 'P-<b>1</b>', 'mode' => '"><script>x</script>', 'created' => true]);
ok('result html escapes and shows plan id + mode', str_contains($h, 'P-&lt;b&gt;1&lt;/b&gt;') && str_contains($h, '&quot;&gt;&lt;script&gt;') && !str_contains($h, '<script>') && !str_contains($h, '<b>'));
ok('result html failure', str_contains(palette_sub_result_html(['ok' => false, 'error' => 'csrf']), 'form expired'));
$src = file_get_contents(__DIR__ . '/../setup.php');
ok('setup.php: admin + CSRF guard, plan action wired', str_contains($src, "'admin'") && substr_count($src, 'verifyCSRFToken') === 1
    && str_contains($src, 'palette_sub_setup($conn)') && str_contains($src, 'palette_sub_result_html($subResult)'));

stub_reset($conn);
unset($GLOBALS['pp_http']);
$conn->query("DELETE FROM admin_settings WHERE setting_key IN ('palette', 'paypal', 'resend_api_key')");
