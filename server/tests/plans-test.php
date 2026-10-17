<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/pp-stub.php';
ini_set('error_log', sys_get_temp_dir() . '/palette-test.log');
ob_start();
session_start();

function col_exists(mysqli $c, string $t, string $col): bool
{
    $st = $c->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->bind_param('ss', $t, $col);
    $st->execute();
    $n = $st->get_result()->num_rows;
    $st->close();
    return $n === 1;
}
function tbl_exists(mysqli $c, string $t): bool
{
    $st = $c->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->bind_param('s', $t);
    $st->execute();
    $n = $st->get_result()->num_rows;
    $st->close();
    return $n === 1;
}

// ---- migration
palette_migrate($conn);
palette_migrate($conn);   // second run must not throw
$allCols = true;
foreach (['plan', 'subscription_id', 'sub_status', 'paid_through', 'period_start'] as $col) $allCols = $allCols && col_exists($conn, 'palette_licenses', $col);
ok('migrate twice: licence columns exist', $allCols);
$allT = true;
foreach (['palette_subscriptions', 'palette_sub_payments', 'palette_ai_usage', 'palette_ai_cache', 'palette_sites', 'palette_site_links'] as $t) $allT = $allT && tbl_exists($conn, $t);
ok('new tables exist', $allT);
$siteCols = true;
foreach (['id', 'domain', 'name', 'title', 'sector', 'tags_json', 'country', 'colours_json', 'fonts_json', 'first_seen', 'last_seen', 'analyses_count', 'suggested_count'] as $col) $siteCols = $siteCols && col_exists($conn, 'palette_sites', $col);
ok('palette_sites columns per spec 8', $siteCols);
$linkCols = true;
foreach (['site_id', 'related_site_id', 'relation', 'source', 'seen_count'] as $col) $linkCols = $linkCols && col_exists($conn, 'palette_site_links', $col);
ok('palette_site_links columns per spec 8', $linkCols);
$conn->query('DELETE FROM palette_sites');
$conn->query("INSERT INTO palette_sites (domain, first_seen, last_seen) VALUES ('a.com', NOW(), NOW())");
$dup = false;
try { $conn->query("INSERT INTO palette_sites (domain, first_seen, last_seen) VALUES ('a.com', NOW(), NOW())"); } catch (mysqli_sql_exception $e) { $dup = $e->getCode() === 1062; }
ok('palette_sites.domain is UNIQUE', $dup);
$conn->query('DELETE FROM palette_sites');
$conn->query("INSERT INTO palette_licenses (license_key, email, order_id, lang, created_at) VALUES ('SDT-ZZZZ-ZZZZ-ZZZZ', 'p@x.com', 'PLAN-DEF', 'en', NOW())");
$def = $conn->query("SELECT plan, sub_status FROM palette_licenses WHERE order_id = 'PLAN-DEF'")->fetch_assoc();
ok('plan defaults to pro, sub_status null', $def['plan'] === 'pro' && $def['sub_status'] === null);
$conn->query("DELETE FROM palette_licenses WHERE order_id = 'PLAN-DEF'");

// ---- settings
$d = palette_defaults();
ok('ai settings defaults', $d['ai_price_usd'] === '3.99' && $d['ai_monthly_analyses'] === 100 && $d['ai_monthly_token_cap'] === 600000
    && $d['search_provider'] === 'brave' && $d['search_key'] === '' && $d['ai_paypal_plan_id'] === '' && $d['ai_paypal_plan_mode'] === '' && $d['ai_paypal_product_id'] === '' && $d['ai_paypal_plan_price'] === '');
$conn->query("DELETE FROM admin_settings WHERE setting_key = 'palette'");
palette_settings_put($conn, ['search_key' => 'SECRET-123', 'ai_monthly_analyses' => 50]);
$s = palette_settings($conn);
ok('ai settings whitelisted and round-trip', $s['search_key'] === 'SECRET-123' && $s['ai_monthly_analyses'] === 50 && $s['price_usd'] === '1.49');

// ---- access truth table
$now = time();
$day = 86400;
$fmt = fn(int $t) => date('Y-m-d H:i:s', $t);
$acc = fn(array $l) => palette_licence_access($l, $now);
ok('pro not revoked -> pro only', $acc(['plan' => 'pro', 'revoked_at' => null, 'paid_through' => null]) === ['pro' => true, 'ai' => false]);
ok('pro revoked -> none', $acc(['plan' => 'pro', 'revoked_at' => '2026-01-01 00:00:00', 'paid_through' => null]) === ['pro' => false, 'ai' => false]);
ok('ai paid_through tomorrow -> pro+ai', $acc(['plan' => 'ai', 'revoked_at' => null, 'paid_through' => $fmt($now + $day)]) === ['pro' => true, 'ai' => true]);
ok('ai paid_through yesterday -> none', $acc(['plan' => 'ai', 'revoked_at' => null, 'paid_through' => $fmt($now - $day)]) === ['pro' => false, 'ai' => false]);
ok('ai revoked -> none', $acc(['plan' => 'ai', 'revoked_at' => '2026-01-01 00:00:00', 'paid_through' => $fmt($now + $day)]) === ['pro' => false, 'ai' => false]);
ok('ai null paid_through -> none', $acc(['plan' => 'ai', 'revoked_at' => null, 'paid_through' => null]) === ['pro' => false, 'ai' => false]);
ok('ai paid_through == now -> on (>=)', $acc(['plan' => 'ai', 'revoked_at' => null, 'paid_through' => $fmt($now)]) === ['pro' => true, 'ai' => true]);
ok('default now argument works', palette_licence_access(['plan' => 'pro', 'revoked_at' => null, 'paid_through' => null])['pro'] === true);
// ruling A16: an active subscription keeps access for 48 h past paid_through (renewal lag); nothing else gets grace
$h = 3600;
$ai = fn(string $status, int $pt, ?string $rev = null) => $acc(['plan' => 'ai', 'revoked_at' => $rev, 'paid_through' => $fmt($pt), 'sub_status' => $status]);
ok('A16: active, 47 h past paid_through -> pro+ai', $ai('active', $now - 47 * $h) === ['pro' => true, 'ai' => true]);
ok('A16: active, exactly 48 h past -> pro+ai (>=)', $ai('active', $now - 48 * $h) === ['pro' => true, 'ai' => true]);
ok('A16: active, 48 h + 1 s past -> none', $ai('active', $now - 48 * $h - 1) === ['pro' => false, 'ai' => false]);
foreach (['cancelled', 'suspended', 'expired'] as $st) {
    ok("A16: $st, 1 s past paid_through -> none (no grace)", $ai($st, $now - 1) === ['pro' => false, 'ai' => false]);
    ok("A16: $st, before paid_through -> pro+ai", $ai($st, $now + $h) === ['pro' => true, 'ai' => true]);
}
ok('A16: revoked inside the grace window -> none', $ai('active', $now - $h, '2026-01-01 00:00:00') === ['pro' => false, 'ai' => false]);
ok('A16: no sub_status -> no grace', $acc(['plan' => 'ai', 'revoked_at' => null, 'paid_through' => $fmt($now - $h)]) === ['pro' => false, 'ai' => false]);
ok('A16: a pro licence is unaffected', $acc(['plan' => 'pro', 'revoked_at' => null, 'paid_through' => $fmt($now - 100 * $day), 'sub_status' => 'active']) === ['pro' => true, 'ai' => false]);

// ---- verify
$conn->query('DELETE FROM palette_rate');
$conn->query('DELETE FROM palette_licenses');
$pro = palette_issue_license($conn, 'pro@studio.vn', 'O-PRO', 'en');
ok('pro verify unchanged (no plan fields)', palette_verify($conn, $pro['key'], '10.1.0.1') === ['valid' => true, 'revoked' => false, 'email_masked' => 'p***@studio.vn']);
$ai = palette_issue_license($conn, 'ai@studio.vn', 'O-AI', 'en');
$pt = $fmt($now + 10 * $day);
$conn->query("UPDATE palette_licenses SET plan='ai', sub_status='active', paid_through='$pt' WHERE id={$ai['id']}");
$v = palette_verify($conn, $ai['key'], '10.1.0.1');
ok('ai verify: plan, ai_active, paid_through ISO', $v['valid'] === true && $v['plan'] === 'ai' && $v['ai_active'] === true
    && $v['paid_through'] === date('c', strtotime($pt)) && $v['email_masked'] === 'a***@studio.vn');
ok('ai verify: sub_status active', $v['sub_status'] === 'active');
// ruling A19: access_until = paid_through + 48 h while active, else paid_through (ISO 8601 with offset)
ok('A19: active -> access_until = paid_through + 48 h', ($v['access_until'] ?? null) === date('c', strtotime($pt) + 48 * 3600)
    && preg_match('/[+-]\d\d:\d\d$/', (string)($v['access_until'] ?? '')) === 1);
$conn->query("UPDATE palette_licenses SET sub_status='cancelled' WHERE id={$ai['id']}");
$v = palette_verify($conn, $ai['key'], '10.1.0.1');
ok('ai verify: sub_status cancelled', $v['sub_status'] === 'cancelled');
ok('A19: cancelled -> access_until = paid_through (no grace)', ($v['access_until'] ?? null) === date('c', strtotime($pt)));
$conn->query("UPDATE palette_licenses SET sub_status='suspended' WHERE id={$ai['id']}");
ok('A19: suspended -> access_until = paid_through', (palette_verify($conn, $ai['key'], '10.1.0.1')['access_until'] ?? null) === date('c', strtotime($pt)));
$conn->query("UPDATE palette_licenses SET paid_through=NULL WHERE id={$ai['id']}");
$v = palette_verify($conn, $ai['key'], '10.1.0.1');
ok('A19: no paid_through -> access_until null', array_key_exists('access_until', $v) && $v['access_until'] === null);
ok('A19: pro verify has no access_until', !array_key_exists('access_until', palette_verify($conn, $pro['key'], '10.1.0.1')));
$conn->query("UPDATE palette_licenses SET sub_status='active', paid_through='$pt' WHERE id={$ai['id']}");
$conn->query("UPDATE palette_licenses SET paid_through='" . $fmt($now - $day) . "' WHERE id={$ai['id']}");
$v = palette_verify($conn, $ai['key'], '10.1.0.1');
ok('A16: active ai a day past paid_through: still ai_active (48 h grace)', $v['ai_active'] === true && $v['valid'] === true);
$conn->query("UPDATE palette_licenses SET paid_through='" . $fmt($now - 3 * $day) . "' WHERE id={$ai['id']}");
$v = palette_verify($conn, $ai['key'], '10.1.0.1');
ok('lapsed ai (past the grace): ai_active false, valid false for Pro', $v['plan'] === 'ai' && $v['ai_active'] === false && $v['valid'] === false && $v['revoked'] === false);
$conn->query("UPDATE palette_licenses SET paid_through='$pt', revoked_at=NOW() WHERE id={$ai['id']}");
ok('revoked ai verify', palette_verify($conn, $ai['key'], '10.1.0.1') === ['valid' => false, 'revoked' => true, 'email_masked' => 'a***@studio.vn']);

// ---- search_key never in any API response
const EXT = 'abcdefghijklmnopabcdefghijklmnop';
stub_reset($conn);
$conn->query('DELETE FROM palette_rate');
palette_settings_put($conn, ['extension_ids' => [EXT], 'search_key' => 'SECRET-LEAK-CHECK', 'ai_paypal_plan_id' => 'P-1']);
$subSecret = palette_sub_secret_stored($conn);   // created by palette_migrate (ruling A20)
$_SESSION = [];
$call = fn(string $m, string $a, array $p = [], array $o = []) => palette_api_handle($o + ['method' => $m, 'action' => $a, 'post' => $p,
    'origin' => 'chrome-extension://' . EXT, 'ip' => '203.0.113.9', 'session' => $_SESSION]);
$csrf = generateCSRFToken('palette_checkout');
$bodies = [
    $call('GET', 'config'), $call('OPTIONS', 'verify'), $call('POST', 'verify', ['key' => $ai['key']]), $call('POST', 'verify', ['key' => 'junk']),
    $call('POST', 'create', ['csrf' => $csrf, 'email' => 'bad']), $call('POST', 'create', ['csrf' => 'x']),
    $call('POST', 'capture', ['csrf' => $csrf, 'order_id' => '']), $call('POST', 'resend', ['csrf' => $csrf, 'email' => 'a@b.co']),
    $call('POST', 'claim', ['csrf' => $csrf, 'key' => $ai['key']]), $call('GET', 'nope'), $call('GET', 'create'),
];
$leak = false;
foreach ($bodies as $r) {
    $j = json_encode($r);
    if (str_contains($j, 'SECRET-LEAK-CHECK') || str_contains($j, 'search_key') || $subSecret === null || str_contains($j, $subSecret) || str_contains($j, 'sub_secret')) $leak = true;
}
ok('no API action response contains search_key, sub_secret or their values', !$leak && count($bodies) === 11);
$conn->query("DELETE FROM admin_settings WHERE setting_key = 'palette'");
$conn->query('DELETE FROM palette_licenses');
