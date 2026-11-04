<?php
// Admin shell (lib/admin.php): access, CSRF, licence list, totals, sites tab, log.
require __DIR__ . '/helpers.php';

foreach (['palette_licenses', 'palette_orders', 'palette_sub_payments', 'palette_admin_log', 'palette_sites', 'palette_site_links'] as $t) $conn->query("DELETE FROM $t");

$admin = ['user_id' => 1, 'user_role' => 'admin', 'csrf_tokens' => ['palette_admin' => str_repeat('a', 64)]];
$user = ['user_id' => 5, 'user_role' => 'user', 'csrf_tokens' => ['palette_admin' => str_repeat('a', 64)]];
$goodPost = ['csrf' => str_repeat('a', 64), 'action' => 'nope'];

function lic(mysqli $c, string $key, string $email, string $plan, array $o = []): int
{
    $o += ['order_id' => 'ORD-' . $key, 'source' => 'stripe', 'pay_status' => 'provider', 'livemode' => 1, 'sub_status' => null,
           'subscription_id' => null, 'revoked_at' => null, 'paid_through' => null, 'created_at' => '2026-11-0' . random_int(1, 4) . ' 10:00:00'];
    $st = $c->prepare('INSERT INTO palette_licenses (license_key, email, order_id, lang, created_at, revoked_at, plan, subscription_id, sub_status, paid_through, source, pay_status, livemode)
                       VALUES (?, ?, ?, "en", ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->bind_param('sssssssssssi', $key, $email, $o['order_id'], $o['created_at'], $o['revoked_at'], $plan, $o['subscription_id'], $o['sub_status'],
        $o['paid_through'], $o['source'], $o['pay_status'], $o['livemode']);
    $st->execute();
    return (int)$c->insert_id;
}

// ---- access
foreach ([[[], 'no session'], [$user, 'user role']] as [$s, $label]) {
    $r = palette_admin_page($conn, $s, [], [], 'GET');
    ok("admin 403 GET, $label", $r['status'] === 403 && $r['body'] === 'Forbidden');
    $r = palette_admin_page($conn, $s, [], $goodPost, 'POST');
    ok("admin 403 POST, $label", $r['status'] === 403 && $r['body'] === 'Forbidden');
}

// ---- csrf
$n0 = (int)$conn->query('SELECT COUNT(*) FROM palette_admin_log')->fetch_row()[0];
$r = palette_admin_page($conn, $admin, [], ['action' => 'nope'], 'POST');
ok('csrf required: missing token -> 403', $r['status'] === 403);
$r = palette_admin_page($conn, $admin, [], ['action' => 'nope', 'csrf' => str_repeat('b', 64)], 'POST');
ok('csrf required: wrong token -> 403', $r['status'] === 403);
$r = palette_admin_page($conn, ['user_role' => 'admin'], [], ['action' => 'nope', 'csrf' => ''], 'POST');
ok('csrf required: no token in session -> 403', $r['status'] === 403);
$r = palette_admin_page($conn, $admin, [], $goodPost, 'POST');
ok('post: valid csrf, unknown action -> 400', $r['status'] === 400);
ok('csrf failure changes nothing', (int)$conn->query('SELECT COUNT(*) FROM palette_admin_log')->fetch_row()[0] === $n0);
ok('csrf token helper: reads session token', palette_admin_csrf_value($admin) === str_repeat('a', 64) && palette_admin_csrf_value([]) === '');

// ---- seed
$a = lic($conn, 'SDT-AAAA-AAAA-AAAA', 'linh@example.com', 'pro');
lic($conn, 'SDT-BBBB-BBBB-BBBB', 'marco@example.it', 'pro', ['source' => 'paypal', 'order_id' => 'PP-ORDER-1']);
lic($conn, 'SDT-CCCC-CCCC-CCCC', 'ravi@example.in', 'ai', ['subscription_id' => 'sub_123', 'sub_status' => 'suspended', 'paid_through' => '2026-11-05 00:00:00']);
lic($conn, 'SDT-DDDD-DDDD-DDDD', 'chen@example.cn', 'pro', ['revoked_at' => '2026-11-01 00:00:00', 'pay_status' => 'refunded']);
lic($conn, 'SDT-EEEE-EEEE-EEEE', 'sofia@example.es', 'pro', ['source' => 'admin', 'pay_status' => 'comp']);
lic($conn, 'SDT-FFFF-FFFF-FFFF', 'nadia@example.bd', 'ai', ['subscription_id' => 'sub_456', 'sub_status' => 'active']);
lic($conn, 'SDT-TTTT-TTTT-TTTT', 'test@example.com', 'pro', ['livemode' => 0]);
lic($conn, 'SDT-XXXX-XXXX-XXXX', '<script>alert(1)</script>@example.com', 'pro');
$conn->query("INSERT INTO palette_orders (order_id, email, lang, amount_usd, tax_usd, status, consent_at, created_at, captured_at, provider, stripe_session, livemode, license_id)
  VALUES ('ORD-SESS', 'x@example.com', 'en', 1.49, 0, 'captured', NOW(), NOW(), UTC_TIMESTAMP(), 'stripe', 'cs_live_findme', 1, $a)");

// ---- list
$ids = fn(array $res) => array_column($res['rows'], 'license_key');
$res = palette_admin_licences($conn, [], 1);
ok('list: all rows, total', $res['total'] === 8 && count($res['rows']) === 8);
$cols = array_keys($res['rows'][0]);
ok('list: row columns', $cols === ['id', 'license_key', 'email', 'plan', 'source', 'pay_status', 'livemode', 'sub_status', 'paid_through', 'revoked_at', 'created_at']);
$d = array_column($res['rows'], 'created_at');
$sorted = $d;
rsort($sorted);
ok('list: newest first', $d === $sorted);
ok('search: email part', $ids(palette_admin_licences($conn, ['q' => 'linh@'], 1)) === ['SDT-AAAA-AAAA-AAAA']);
ok('search: exact key', $ids(palette_admin_licences($conn, ['q' => 'sdt-bbbb-bbbb-bbbb'], 1)) === ['SDT-BBBB-BBBB-BBBB']);
ok('search: partial key does not match', palette_admin_licences($conn, ['q' => 'SDT-BBBB'], 1)['total'] === 0);
ok('search: order id', $ids(palette_admin_licences($conn, ['q' => 'PP-ORDER-1'], 1)) === ['SDT-BBBB-BBBB-BBBB']);
ok('search: subscription id', $ids(palette_admin_licences($conn, ['q' => 'sub_123'], 1)) === ['SDT-CCCC-CCCC-CCCC']);
ok('search: stripe session via order', $ids(palette_admin_licences($conn, ['q' => 'cs_live_findme'], 1)) === ['SDT-AAAA-AAAA-AAAA']);
ok('search: LIKE wildcards are literal', palette_admin_licences($conn, ['q' => '%'], 1)['total'] === 0);
ok('filter: plan ai', palette_admin_licences($conn, ['plan' => 'ai'], 1)['total'] === 2);
ok('filter: plan junk ignored', palette_admin_licences($conn, ['plan' => 'x'], 1)['total'] === 8);
ok('filter: source paypal', $ids(palette_admin_licences($conn, ['source' => 'paypal'], 1)) === ['SDT-BBBB-BBBB-BBBB']);
ok('filter: source admin', $ids(palette_admin_licences($conn, ['source' => 'admin'], 1)) === ['SDT-EEEE-EEEE-EEEE']);
ok('filter: status revoked', $ids(palette_admin_licences($conn, ['status' => 'revoked'], 1)) === ['SDT-DDDD-DDDD-DDDD']);
ok('filter: status refunded', $ids(palette_admin_licences($conn, ['status' => 'refunded'], 1)) === ['SDT-DDDD-DDDD-DDDD']);
ok('filter: status suspended', $ids(palette_admin_licences($conn, ['status' => 'suspended'], 1)) === ['SDT-CCCC-CCCC-CCCC']);
ok('filter: status test', $ids(palette_admin_licences($conn, ['status' => 'test'], 1)) === ['SDT-TTTT-TTTT-TTTT']);
$act = $ids(palette_admin_licences($conn, ['status' => 'active'], 1));
ok('filter: status active excludes revoked, refunded, suspended, test', count($act) === 5 && !in_array('SDT-DDDD-DDDD-DDDD', $act, true)
    && !in_array('SDT-CCCC-CCCC-CCCC', $act, true) && !in_array('SDT-TTTT-TTTT-TTTT', $act, true));
ok('filter: combined', $ids(palette_admin_licences($conn, ['plan' => 'ai', 'status' => 'active'], 1)) === ['SDT-FFFF-FFFF-FFFF']);
for ($i = 0; $i < 55; $i++) lic($conn, sprintf('SDT-P%03d-PPPP-PPPP', $i), "p$i@page.example", 'pro', ['created_at' => '2026-10-01 00:00:00']);
$p1 = palette_admin_licences($conn, ['q' => '@page.example'], 1);
$p2 = palette_admin_licences($conn, ['q' => '@page.example'], 2);
ok('paging: 50 a page, total over all pages', $p1['total'] === 55 && count($p1['rows']) === 50 && count($p2['rows']) === 5);
ok('paging: page out of range is empty', palette_admin_licences($conn, ['q' => '@page.example'], 9)['rows'] === []);
$conn->query("DELETE FROM palette_licenses WHERE email LIKE '%@page.example'");

// ---- totals (active live pro = AAAA BBBB EEEE XXXX; active ai = FFFF; suspended ai, revoked, refunded, test excluded)
$conn->query("INSERT INTO palette_orders (order_id, email, lang, amount_usd, tax_usd, status, consent_at, created_at, captured_at, provider, livemode, license_id)
  VALUES ('ORD-NOKEY', 'n@example.com', 'en', 1.49, 0, 'captured', NOW(), NOW(), UTC_TIMESTAMP(), 'stripe', 1, NULL),
         ('ORD-TEST', 't@example.com', 'en', 9.00, 0, 'captured', NOW(), NOW(), UTC_TIMESTAMP(), 'stripe', 0, $a),
         ('ORD-OLD', 'o@example.com', 'en', 5.00, 0, 'captured', NOW(), NOW(), '2020-01-05 00:00:00', 'stripe', 1, $a)");
$conn->query("INSERT INTO palette_orders (order_id, email, lang, amount_usd, tax_usd, status, consent_at, created_at, provider, stripe_payment_intent, livemode)
  VALUES ('ORD-FAIL', 'f@example.com', 'en', 1.49, 0, 'failed', NOW(), NOW(), 'stripe', 'pi_failed1', 1),
         ('ORD-FAIL2', 'f2@example.com', 'en', 1.49, 0, 'failed', NOW(), NOW(), 'paypal', NULL, 1)");
$conn->query("INSERT INTO palette_sub_payments (subscription_id, sale_id, amount_usd, status, paid_at) VALUES
  ('sub_456', 'in_1', 3.99, 'completed', UTC_TIMESTAMP()), ('sub_456', 'in_2', 3.99, 'refunded', UTC_TIMESTAMP()), ('sub_456', 'in_3', 3.99, 'completed', '2020-01-05 00:00:00')");
$t = palette_admin_totals($conn);
ok('totals: shape', array_keys($t) === ['pro', 'ai', 'unfulfilled', 'revenue_month_cents']);
ok('totals: active pro and ai (live, not revoked/suspended)', $t['pro'] === 4 && $t['ai'] === 1);
ok('totals: unfulfilled = captured no licence + failed with a Stripe intent', $t['unfulfilled'] === 2);
// revenue this month: ORD-SESS 1.49 + ORD-NOKEY 1.49 (live captured now) + in_1 3.99; test, old, refunded excluded
ok('totals: revenue this month, live only', $t['revenue_month_cents'] === 149 + 149 + 399);

// ---- pages
$r = palette_admin_page($conn, $admin, [], [], 'GET');
ok('licences tab renders', $r['status'] === 200 && str_contains($r['body'], 'SDT-AAAA-AAAA-AAAA') && str_contains($r['body'], 'Issue a key')
    && str_contains($r['body'], 'tab=payments') && str_contains($r['body'], 'tab=sites') && str_contains($r['body'], 'tab=settings'));
ok('page headers: no-store, noindex, CSP', ($r['headers']['Cache-Control'] ?? '') === 'no-store' && ($r['headers']['X-Robots-Tag'] ?? '') === 'noindex'
    && isset($r['headers']['Content-Security-Policy']));
ok('html escapes email', !str_contains($r['body'], '<script>alert(1)') && str_contains($r['body'], '&lt;script&gt;alert(1)&lt;/script&gt;@example.com'));
ok('test badge on livemode=0 row', str_contains($r['body'], 'badge-test'));
ok('totals cards shown', str_contains($r['body'], 'Active Pro') && str_contains($r['body'], '$6.97'));
$r = palette_admin_page($conn, $admin, ['q' => '"><img src=x onerror=1>'], [], 'GET');
ok('search term is escaped in the form', $r['status'] === 200 && !str_contains($r['body'], '"><img') && str_contains($r['body'], '&quot;&gt;&lt;img'));
$r = palette_admin_page($conn, $admin, ['tab' => 'licences', 'plan' => 'ai', 'status' => 'bogus', 'page' => '-3'], [], 'GET');
ok('bad filter / page values are tolerated', $r['status'] === 200);
$r = palette_admin_page($conn, $admin, ['tab' => 'settings'], [], 'GET');
ok('settings tab renders inside shell (details in admin-settings-test)', $r['status'] === 200 && str_contains($r['body'], 'Live mode') && str_contains($r['body'], 'Issue a key'));
$r = palette_admin_page($conn, $admin, ['tab' => 'nonsense'], [], 'GET');
ok('unknown tab -> 404 inside shell', $r['status'] === 404 && str_contains($r['body'], 'Licences'));

$conn->query("DELETE FROM palette_sites");
palette_sites_record($conn, 'shop.example', ['title' => 'Ridge Coffee | Home'], ['colours' => [], 'fonts' => []], ['sector' => 'Coffee', 'tags' => [], 'sites' => []]);
$r = palette_admin_page($conn, $admin, ['tab' => 'sites'], [], 'GET');
ok('sites tab renders', $r['status'] === 200 && str_contains($r['body'], 'shop.example') && str_contains($r['body'], 'format=csv') && str_contains($r['body'], 'analyses_count'));
$r = palette_admin_page($conn, $admin, ['tab' => 'sites', 'format' => 'csv'], [], 'GET');
ok('sites tab csv download', $r['status'] === 200 && str_starts_with($r['headers']['Content-Type'], 'text/csv') && str_contains($r['body'], 'shop.example'));

// ---- log
palette_admin_log($conn, 1, 'test_action', $a, 'ORD-X', ['from' => 'a', 'to' => 'b'], 'because');
palette_admin_log($conn, 1, 'bare', null, null, [], null);
$row = $conn->query("SELECT * FROM palette_admin_log WHERE action = 'test_action'")->fetch_assoc();
ok('log: row written', $row && (int)$row['admin_user_id'] === 1 && (int)$row['license_id'] === $a && $row['order_ref'] === 'ORD-X' && $row['note'] === 'because'
    && json_decode($row['detail'], true) === ['from' => 'a', 'to' => 'b'] && strlen($row['created_at']) === 19);
$row = $conn->query("SELECT * FROM palette_admin_log WHERE action = 'bare'")->fetch_assoc();
ok('log: nulls', $row && $row['license_id'] === null && $row['order_ref'] === null && $row['note'] === null);

// ---- cleanup so other test files start clean
foreach (['palette_licenses', 'palette_orders', 'palette_sub_payments', 'palette_admin_log', 'palette_sites', 'palette_site_links'] as $t) $conn->query("DELETE FROM $t");
