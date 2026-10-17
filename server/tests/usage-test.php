<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/pp-stub.php';
ini_set('error_log', sys_get_temp_dir() . '/palette-test.log');

function mk_ai(mysqli $c, string $tag, ?string $paidThrough, ?string $periodStart): array
{
    $key = palette_new_key();
    $st = $c->prepare("INSERT INTO palette_licenses (license_key, email, order_id, lang, created_at, plan, paid_through, period_start)
                       VALUES (?, 'u@x.com', ?, 'en', ?, 'ai', ?, ?)");
    $created = date('Y-m-d H:i:s', time() - 86400);
    $oid = "USG-$tag";
    $st->bind_param('sssss', $key, $oid, $created, $paidThrough, $periodStart);
    $st->execute();
    $id = (int)$c->insert_id;
    $st->close();
    return ['id' => $id, 'key' => $key, 'created_at' => $created, 'period_start' => $periodStart, 'paid_through' => $paidThrough];
}
$conn->query("DELETE FROM palette_ai_usage");
$conn->query("DELETE FROM palette_licenses WHERE order_id LIKE 'USG-%'");
$S = palette_defaults();
$pt = date('Y-m-d H:i:s', time() + 20 * 86400);
$ps = date('Y-m-d H:i:s', time() - 10 * 86400);

// period
$lic = mk_ai($conn, 'A', $pt, $ps);
ok('period = period_start when set', palette_usage_period($lic) === $ps);
ok('period falls back to created_at', palette_usage_period(['id' => 1, 'period_start' => null, 'created_at' => '2026-01-02 03:04:05']) === '2026-01-02 03:04:05');

// get on fresh
$u = palette_usage_get($conn, $lic, $S);
ok('fresh usage', $u === ['used' => 0, 'limit' => 100, 'tokens' => 0, 'token_cap' => 600000, 'resets_on' => date('Y-m-d', strtotime($pt))]);
ok('resets_on null without paid_through', palette_usage_get($conn, ['id' => $lic['id'], 'period_start' => $ps, 'paid_through' => null], $S)['resets_on'] === null);

// verify includes usage
$v = palette_verify($conn, $lic['key'], '10.9.0.1');
ok('verify ai includes usage', ($v['usage'] ?? null) === ['used' => 0, 'limit' => 100, 'resets_on' => date('Y-m-d', strtotime($pt))]);
$lapsed = mk_ai($conn, 'L', date('Y-m-d H:i:s', time() - 86400), $ps);
$vl = palette_verify($conn, $lapsed['key'], '10.9.0.1');
ok('verify lapsed ai includes usage', $vl['valid'] === false && $vl['revoked'] === false && isset($vl['usage']) && $vl['usage']['used'] === 0);
$pro = palette_issue_license($conn, 'pro@x.com', 'USG-PRO', 'en');
ok('verify pro has no usage', !array_key_exists('usage', palette_verify($conn, $pro['key'], '10.9.0.1')));

// 100 reserves then full
$all = true;
for ($i = 0; $i < 100; $i++) $all = $all && palette_usage_reserve($conn, $lic, $S);
ok('100 reserves succeed', $all);
ok('101st reserve false', palette_usage_reserve($conn, $lic, $S) === false);
ok('used stays 100', palette_usage_get($conn, $lic, $S)['used'] === 100);
ok('verify shows used 100', palette_verify($conn, $lic['key'], '10.9.0.1')['usage']['used'] === 100);

// release frees one
palette_usage_release($conn, $lic);
ok('release drops to 99', palette_usage_get($conn, $lic, $S)['used'] === 99);
ok('reserve works after release', palette_usage_reserve($conn, $lic, $S) === true);

// release never below 0
$z = mk_ai($conn, 'Z', $pt, $ps);
palette_usage_release($conn, $z);
ok('release on missing row is a no-op', palette_usage_get($conn, $z, $S)['used'] === 0);
palette_usage_reserve($conn, $z, $S);
palette_usage_release($conn, $z);
palette_usage_release($conn, $z);
ok('release never below 0', palette_usage_get($conn, $z, $S)['used'] === 0);

// race: two connections for the last slot
$r = mk_ai($conn, 'R', $pt, $ps);
for ($i = 0; $i < 99; $i++) palette_usage_reserve($conn, $r, $S);
$dbname = $conn->query('SELECT DATABASE()')->fetch_row()[0];
$c2 = new mysqli('127.0.0.1', 'palette', 'palette', $dbname, 3306);
$c2->set_charset('utf8mb4');
$before = [palette_usage_get($conn, $r, $S)['used'], palette_usage_get($c2, $r, $S)['used']];
$res = [palette_usage_reserve($conn, $r, $S), palette_usage_reserve($c2, $r, $S)];
ok('race: both saw 99 before', $before === [99, 99]);
ok('race: exactly one of two reserves succeeds', count(array_filter($res)) === 1);
ok('race: usage never exceeds limit', palette_usage_get($conn, $r, $S)['used'] === 100);
// both connections create the row first (INSERT IGNORE), then both attempt the conditional UPDATE
$q = mk_ai($conn, 'Q', $pt, $ps);
$S1 = ['ai_monthly_analyses' => 1] + $S;
palette_usage_ensure_row($conn, $q['id'], $ps);
palette_usage_ensure_row($c2, $q['id'], $ps);
$resQ = [palette_usage_reserve($c2, $q, $S1), palette_usage_reserve($conn, $q, $S1)];
ok('race: interleaved ensure then reserve -> one winner', count(array_filter($resQ)) === 1 && palette_usage_get($conn, $q, $S1)['used'] === 1);
$c2->close();

// tokens cap
$t = mk_ai($conn, 'T', $pt, $ps);
palette_usage_tokens($conn, $t, 500000, 99999);
ok('tokens below cap still reserve', palette_usage_reserve($conn, $t, $S) === true);
palette_usage_tokens($conn, $t, 1, 0);
$ut = palette_usage_get($conn, $t, $S);
ok('tokens summed', $ut['tokens'] === 600000 && $ut['used'] === 1);
ok('tokens >= cap blocks reserve with analyses left', palette_usage_reserve($conn, $t, $S) === false);
ok('blocked reserve did not bump analyses', palette_usage_get($conn, $t, $S)['used'] === 1);
palette_usage_tokens($conn, $t, 5, 5);
ok('tokens can exceed cap (still counted)', palette_usage_get($conn, $t, $S)['tokens'] === 600010);

// new period starts at 0
$np = $lic;
$np['period_start'] = date('Y-m-d H:i:s', time() + 5);
ok('new period starts at 0', palette_usage_get($conn, $np, $S)['used'] === 0 && palette_usage_reserve($conn, $np, $S) === true);
ok('old period row untouched', palette_usage_get($conn, $lic, $S)['used'] === 100);

// settings fallbacks
foreach ([0, -5, 'abc', null] as $i => $bad) {
    $x = mk_ai($conn, 'F' . $i, $pt, $ps);
    $g = palette_usage_get($conn, $x, ['ai_monthly_analyses' => $bad, 'ai_monthly_token_cap' => $bad]);
    ok('invalid settings fall back: ' . json_encode($bad), $g['limit'] === 100 && $g['token_cap'] === 600000);
}
$sm = palette_usage_get($conn, $z, ['ai_monthly_analyses' => 3, 'ai_monthly_token_cap' => 1000]);
ok('custom limits honoured', $sm['limit'] === 3 && $sm['token_cap'] === 1000);

$conn->query("DELETE FROM palette_ai_usage");
$conn->query("DELETE FROM palette_licenses WHERE order_id LIKE 'USG-%'");
