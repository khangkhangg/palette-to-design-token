<?php
require __DIR__ . '/helpers.php';

// The guard: a child pointed at a non-_test database must exit 2 (information_schema
// is readable by any user, so nothing is touched before the refusal).
putenv("PALETTE_TEST_DB_NAME=information_schema");
exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg(__DIR__ . "/helpers.php") . " 2>&1", $out, $code);
putenv("PALETTE_TEST_DB_NAME");
ok('non-_test database refused with exit 2', $code === 2);

palette_migrate($conn);
palette_migrate($conn);
ok('palette_migrate twice is idempotent', $conn->errno === 0);

function cols(mysqli $c, string $t): array
{
    $r = $c->query("SHOW COLUMNS FROM $t");
    $o = [];
    while ($x = $r->fetch_assoc()) $o[$x['Field']] = $x;
    return $o;
}
$o = cols($conn, 'palette_orders');
ok('orders columns', array_keys($o) === ['id','order_id','email','lang','country','region','amount_usd','tax_usd','status','capture_id','consent_at','created_at','captured_at','license_id']);
ok('orders status enum', str_contains($o['status']['Type'], "'created','captured','failed'"));
ok('orders country nullable char(2)', $o['country']['Null'] === 'YES' && $o['country']['Type'] === 'char(2)');
$l = cols($conn, 'palette_licenses');
ok('licenses columns', array_keys($l) === ['id','license_key','email','user_id','order_id','lang','created_at','revoked_at','last_verified_at','plan','subscription_id','sub_status','paid_through','period_start']);
ok('license_key char(18)', $l['license_key']['Type'] === 'char(18)');
$idx = [];
$r = $conn->query('SHOW INDEX FROM palette_licenses');
while ($x = $r->fetch_assoc()) $idx[$x['Key_name']] = (int)$x['Non_unique'];
ok('licenses indexes', isset($idx['license_key'], $idx['email'], $idx['user_id']) && $idx['license_key'] === 0);
$r = $conn->query("SHOW INDEX FROM palette_orders WHERE Key_name='order_id'");
ok('orders order_id unique', (int)$r->fetch_assoc()['Non_unique'] === 0);

$conn->query("DELETE FROM admin_settings WHERE setting_key='palette'");
ok('defaults', palette_settings($conn) === ['price_usd'=>'1.49','tax_mode'=>'none','tax_rate'=>'0.0725','extension_ids'=>[],'webhook_id'=>'','webhook_mode'=>'','ai_price_usd'=>'3.99','ai_monthly_analyses'=>100,'ai_monthly_token_cap'=>600000,'search_provider'=>'brave','search_key'=>'','ai_paypal_plan_id'=>'','ai_paypal_plan_mode'=>'']);
palette_settings_put($conn, ['tax_mode' => 'ca', 'extension_ids' => ['abc']]);
$s = palette_settings($conn);
ok('stored merged over defaults', $s['tax_mode'] === 'ca' && $s['extension_ids'] === ['abc'] && $s['price_usd'] === '1.49');
$conn->query("DELETE FROM admin_settings WHERE setting_key='palette'");
