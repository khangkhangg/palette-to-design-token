<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/pp-stub.php';
ini_set('error_log', sys_get_temp_dir() . '/palette-test.log');

// ---- palette_total
$d = palette_defaults();
ok('total default', palette_total($d, 'US', 'CA') === ['amount' => '1.49', 'tax' => '0.00']);
$ca = ['tax_mode' => 'ca'] + $d;
ok('total ca US+CA 1.60', palette_total($ca, 'US', 'CA') === ['amount' => '1.60', 'tax' => '0.11']);
ok('total ca lowercase input', palette_total($ca, 'us', 'ca') === ['amount' => '1.60', 'tax' => '0.11']);
ok('total ca US+NY no tax', palette_total($ca, 'US', 'NY') === ['amount' => '1.49', 'tax' => '0.00']);
ok('total ca CA country no tax', palette_total($ca, 'CA', 'CA') === ['amount' => '1.49', 'tax' => '0.00']);
ok('total ca no country no tax', palette_total($ca, null, null) === ['amount' => '1.49', 'tax' => '0.00']);
ok('bad price falls back', palette_total(['price_usd' => '1.5'] + $d, null, null)['amount'] === '1.49'
    && palette_total(['price_usd' => '-1.00'] + $d, null, null)['amount'] === '1.49'
    && palette_total(['price_usd' => ['x']] + $d, null, null)['amount'] === '1.49');
ok('valid price used', palette_total(['price_usd' => '2.00'] + $d, null, null)['amount'] === '2.00');
ok('bad tax_mode -> none', palette_total(['tax_mode' => 'eu'] + $d, 'US', 'CA')['tax'] === '0.00');
ok('bad tax_rate -> 0.0725', palette_total(['tax_rate' => '0.5'] + $ca, 'US', 'CA')['amount'] === '1.60'
    && palette_total(['tax_rate' => 'abc'] + $ca, 'US', 'CA')['amount'] === '1.60');
ok('custom tax_rate, half up', palette_total(['tax_rate' => '0.1', 'price_usd' => '1.25'] + $ca, 'US', 'CA') === ['amount' => '1.38', 'tax' => '0.13']);

// ---- palette_create_order validation
stub_reset($conn);
ok('bad email', palette_create_order($conn, 'nope', 'en', true, null, null) === ['ok' => false, 'error' => 'err_email']);
ok('CRLF email', palette_create_order($conn, "a@b.com\r\nBcc: x@y.com", 'en', true, null, null)['error'] === 'err_email');
ok('long email', palette_create_order($conn, str_repeat('a', 185) . '@b.com', 'en', true, null, null)['error'] === 'err_email');
ok('no consent', palette_create_order($conn, 'a@b.com', 'en', false, null, null) === ['ok' => false, 'error' => 'err_consent']);
stub_paypal($conn, false);
ok('paypal not ready', palette_create_order($conn, 'a@b.com', 'en', true, null, null) === ['ok' => false, 'error' => 'err_paused']);
ok('validation made no PayPal call and no row', $GLOBALS['pp_calls'] === [] && (int)$conn->query('SELECT COUNT(*) FROM palette_orders')->fetch_row()[0] === 0);
stub_paypal($conn, true);

// ---- order body + row
$r = make_order($conn, 'PPORDER1', ' Buyer@Studio.vn ', 'xx');
ok('create ok', $r === ['ok' => true, 'order_id' => 'PPORDER1', 'total' => '1.49']);
$row = order_row($conn, 'PPORDER1');
$b = calls_to('POST', '/v2/checkout/orders')[0]['body'] ?? [];
ok('body intent', ($b['intent'] ?? '') === 'CAPTURE' && count($b['purchase_units'] ?? []) === 1);
$pu = $b['purchase_units'][0] ?? [];
ok('body amount', ($pu['amount'] ?? null) === ['currency_code' => 'USD', 'value' => '1.49',
    'breakdown' => ['item_total' => ['currency_code' => 'USD', 'value' => '1.49'], 'tax_total' => ['currency_code' => 'USD', 'value' => '0.00']]]);
ok('body custom_id palette:<row id>', $row && ($pu['custom_id'] ?? '') === 'palette:' . $row['id']);
ok('body description', ($pu['description'] ?? '') === 'Site to Design Token Pro');
ok('body application_context', ($b['application_context'] ?? null) === ['brand_name' => 'Site to Design Token', 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'PAY_NOW']);
ok('row stored', $row && $row['status'] === 'created' && $row['email'] === 'buyer@studio.vn' && $row['lang'] === 'en'
    && $row['amount_usd'] === '1.49' && $row['tax_usd'] === '0.00' && $row['consent_at'] !== null && $row['country'] === null);

// tax_mode none ignores country even when given
make_order($conn, 'PPORDER2');
$GLOBALS['pp_routes']['POST /v2/checkout/orders'] = ['status' => 201, 'json' => ['id' => 'PPORDER3']];
ok('none mode: US+CA no tax', palette_create_order($conn, 'a@b.com', 'fr', true, 'US', 'CA')['total'] === '1.49');
ok('none mode: country not stored', order_row($conn, 'PPORDER3')['country'] === null);

// CA tax breakdown
palette_settings_put($conn, ['tax_mode' => 'ca']);
$GLOBALS['pp_calls'] = [];
$GLOBALS['pp_routes']['POST /v2/checkout/orders'] = ['status' => 201, 'json' => ['id' => 'PPTAX1']];
ok('ca create total 1.60', palette_create_order($conn, 'a@b.com', 'fr', true, 'us', 'ca')['total'] === '1.60');
$amt = calls_to('POST', '/v2/checkout/orders')[0]['body']['purchase_units'][0]['amount'] ?? [];
ok('tax breakdown 1.49 + 0.11 = 1.60', $amt === ['currency_code' => 'USD', 'value' => '1.60',
    'breakdown' => ['item_total' => ['currency_code' => 'USD', 'value' => '1.49'], 'tax_total' => ['currency_code' => 'USD', 'value' => '0.11']]]);
$t = order_row($conn, 'PPTAX1');
ok('ca row', $t['amount_usd'] === '1.60' && $t['tax_usd'] === '0.11' && $t['country'] === 'US' && $t['region'] === 'CA' && $t['lang'] === 'fr');
$GLOBALS['pp_routes']['POST /v2/checkout/orders'] = ['status' => 201, 'json' => ['id' => 'PPTAX2']];
palette_create_order($conn, 'a@b.com', 'fr', true, 'USA', str_repeat('X', 9));
$t2 = order_row($conn, 'PPTAX2');
ok('ca mode: bad country/region dropped, no tax', $t2['country'] === null && $t2['region'] === null && $t2['amount_usd'] === '1.49');
palette_settings_put($conn, ['tax_mode' => 'none']);

// failed PayPal create leaves nothing fulfillable
$before = (int)$conn->query("SELECT COUNT(*) FROM palette_orders WHERE status = 'created'")->fetch_row()[0];
$GLOBALS['pp_routes']['POST /v2/checkout/orders'] = ['status' => 422, 'json' => ['message' => 'nope']];
ok('paypal create failure -> err_paypal', palette_create_order($conn, 'a@b.com', 'en', true, null, null) === ['ok' => false, 'error' => 'err_paypal']);
ok('no new created row after failure', (int)$conn->query("SELECT COUNT(*) FROM palette_orders WHERE status = 'created'")->fetch_row()[0] === $before);
$GLOBALS['pp_routes']['POST /v2/checkout/orders'] = ['status' => 201, 'json' => ['id' => 'PPORDER1']]; // duplicate id
ok('duplicate PayPal id -> err_paypal', palette_create_order($conn, 'a@b.com', 'en', true, null, null)['error'] === 'err_paypal');
ok('still no new created row', (int)$conn->query("SELECT COUNT(*) FROM palette_orders WHERE status = 'created'")->fetch_row()[0] === $before);
$failedIds = $conn->query("SELECT order_id FROM palette_orders WHERE status = 'failed'")->fetch_all();
$GLOBALS['pp_routes'] = [];
$cantCapture = true;
foreach ($failedIds as [$fid]) if (palette_capture($conn, $fid)['ok'] !== false) $cantCapture = false;
ok('failed rows cannot be captured', $cantCapture && calls_to('POST', '/v2/checkout/orders/') === []);

// ---- palette_capture
stub_reset($conn);
ok('capture unknown order', palette_capture($conn, 'NOPE')['ok'] === false && $GLOBALS['pp_calls'] === []);
make_order($conn, 'ORD-A', 'buyer@studio.vn', 'es');
$GLOBALS['pp_routes']['POST /v2/checkout/orders/ORD-A/capture'] = ['status' => 201, 'json' => pp_completed('ORD-A')];
$c1 = palette_capture($conn, 'ORD-A');
ok('capture ok', $c1['ok'] === true && preg_match(PALETTE_KEY_RE, $c1['key'] ?? '') === 1 && $c1['email_masked'] === 'b***@studio.vn');
ok('exactly one license', license_count($conn) === 1);
$lic = $conn->query('SELECT * FROM palette_licenses')->fetch_assoc();
$o = order_row($conn, 'ORD-A');
ok('order captured + linked', $o['status'] === 'captured' && $o['capture_id'] === 'CAP1' && $o['captured_at'] !== null && (int)$o['license_id'] === (int)$lic['id']);
ok('license for order email + lang', $lic['email'] === 'buyer@studio.vn' && $lic['order_id'] === 'ORD-A' && $lic['lang'] === 'es' && $lic['license_key'] === $c1['key']);
ok('exactly one email', count($GLOBALS['mails']) === 1);
$m = $GLOBALS['mails'][0] ?? [];
ok('email to buyer, in es, with key', ($m['to'] ?? null) === ['buyer@studio.vn'] && ($m['subject'] ?? '') === palette_t('es', 'email_license_subject')
    && str_contains($m['html'] ?? '', $c1['key']));
$c2 = palette_capture($conn, 'ORD-A');
ok('second capture returns same key', $c2 === $c1);
ok('second capture: no PayPal call, no email, no license', count(calls_to('POST', '/v2/checkout/orders/ORD-A/capture')) === 1
    && count($GLOBALS['mails']) === 1 && license_count($conn) === 1);

$capCall = calls_to('POST', '/v2/checkout/orders/ORD-A/capture')[0] ?? ['headers' => []];
ok('capture sends PayPal-Request-Id', in_array('PayPal-Request-Id: palette-capture-ORD-A', $capCall['headers'], true));

// transient vs final capture failures
make_order($conn, 'ORD-T');
$GLOBALS['pp_routes']['POST /v2/checkout/orders/ORD-T/capture'] = ['status' => 503, 'json' => ['message' => 'unavailable']];
ok('PayPal 503 on capture -> retry', palette_capture($conn, 'ORD-T') === ['ok' => false, 'error' => 'err_paypal', 'retry' => true]);
$GLOBALS['pp_routes']['POST /v2/checkout/orders/ORD-T/capture'] = ['status' => 0, 'json' => ['message' => 'timeout']];
ok('PayPal timeout on capture -> retry', !empty(palette_capture($conn, 'ORD-T')['retry']));
$GLOBALS['pp_routes']['POST /v2/checkout/orders/ORD-T/capture'] = ['status' => 422, 'json' => ['details' => [['issue' => 'ORDER_NOT_APPROVED']]]];
ok('PayPal 422 on capture -> no retry', palette_capture($conn, 'ORD-T') === ['ok' => false, 'error' => 'err_paypal']);
ok('transient failures leave the order created', order_row($conn, 'ORD-T')['status'] === 'created' && license_count($conn) === 1);

// ORDER_ALREADY_CAPTURED re-reads the order
make_order($conn, 'ORD-B');
$GLOBALS['pp_routes']['POST /v2/checkout/orders/ORD-B/capture'] = ['status' => 422, 'json' => ['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]]];
$GLOBALS['pp_routes']['GET /v2/checkout/orders/ORD-B'] = ['status' => 200, 'json' => pp_completed('ORD-B', '1.49', 'USD', 'CAPB')];
$cb = palette_capture($conn, 'ORD-B');
ok('already-captured path re-reads order', count(calls_to('GET', '/v2/checkout/orders/ORD-B')) === 1);
ok('already-captured path fulfils', $cb['ok'] === true && order_row($conn, 'ORD-B')['capture_id'] === 'CAPB' && license_count($conn) === 2);

// not completed -> no license, order stays created
make_order($conn, 'ORD-P');
$pend = pp_completed('ORD-P');
$pend['purchase_units'][0]['payments']['captures'][0]['status'] = 'PENDING';
$GLOBALS['pp_routes']['POST /v2/checkout/orders/ORD-P/capture'] = ['status' => 201, 'json' => $pend];
ok('pending capture -> error', palette_capture($conn, 'ORD-P') === ['ok' => false, 'error' => 'err_paypal']);
ok('pending: order still created, no license', order_row($conn, 'ORD-P')['status'] === 'created' && license_count($conn) === 2);

// underpaid -> failed
$mailCount = count($GLOBALS['mails']);
make_order($conn, 'ORD-U');
$GLOBALS['pp_routes']['POST /v2/checkout/orders/ORD-U/capture'] = ['status' => 201, 'json' => pp_completed('ORD-U', '1.00')];
ok('underpaid -> error', palette_capture($conn, 'ORD-U') === ['ok' => false, 'error' => 'err_paypal']);
ok('underpaid: failed, no license, no email', order_row($conn, 'ORD-U')['status'] === 'failed' && license_count($conn) === 2 && count($GLOBALS['mails']) === $mailCount);
ok('underpaid order cannot be captured later', palette_capture($conn, 'ORD-U')['ok'] === false && license_count($conn) === 2);

// wrong currency -> failed
make_order($conn, 'ORD-E');
$GLOBALS['pp_routes']['POST /v2/checkout/orders/ORD-E/capture'] = ['status' => 201, 'json' => pp_completed('ORD-E', '1.49', 'EUR')];
ok('EUR -> error + failed', palette_capture($conn, 'ORD-E')['ok'] === false && order_row($conn, 'ORD-E')['status'] === 'failed' && license_count($conn) === 2);

// overpaid is accepted
make_order($conn, 'ORD-O');
$GLOBALS['pp_routes']['POST /v2/checkout/orders/ORD-O/capture'] = ['status' => 201, 'json' => pp_completed('ORD-O', '2.00', 'USD', 'CAPO')];
ok('overpaid accepted', palette_capture($conn, 'ORD-O')['ok'] === true && license_count($conn) === 3);

// ---- palette_fulfil race: a stale 'created' snapshot (the loser of a page/webhook race)
stub_reset($conn);
make_order($conn, 'ORD-R');
$stale = order_row($conn, 'ORD-R');
$cap = pp_completed('ORD-R')['purchase_units'][0]['payments']['captures'][0];
$f1 = palette_fulfil($conn, $stale, $cap, 'payer@paypal.test');
// Let time pass, so an unguarded UPDATE would change captured_at and count as a claim.
$conn->query("UPDATE palette_orders SET captured_at = captured_at - INTERVAL 1 MINUTE WHERE order_id = 'ORD-R'");
$f2 = palette_fulfil($conn, $stale, $cap, 'payer@paypal.test');
ok('race: winner issues key', $f1['ok'] === true && isset($f1['key']));
ok('race: loser returns the same key', $f2['ok'] === true && ($f2['key'] ?? null) === $f1['key']);
ok('race: one license, one email', license_count($conn) === 1 && count($GLOBALS['mails']) === 1);
$bad = $cap;
$bad['amount']['value'] = '0.01';
ok('race: late bad amount does not fail a captured order', palette_fulfil($conn, $stale, $bad, '')['ok'] === false
    && order_row($conn, 'ORD-R')['status'] === 'captured');
$weird = $cap;
$weird['amount']['value'] = '1.49abc';
make_order($conn, 'ORD-W');
ok('malformed amount rejected', palette_fulfil($conn, order_row($conn, 'ORD-W'), $weird, '')['ok'] === false && order_row($conn, 'ORD-W')['status'] === 'failed');

stub_reset($conn);
unset($GLOBALS['pp_http']);
$conn->query("DELETE FROM admin_settings WHERE setting_key IN ('palette', 'paypal', 'resend_api_key')");
