<?php
require __DIR__ . '/helpers.php';

function reset_state(mysqli $c): void
{
    $c->query('TRUNCATE api_rate_limits');
    $c->query('DELETE FROM palette_licenses');
    $GLOBALS['palette_sent'] = [];
}
reset_state($conn);

// keys
$re = '/^SDT-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/';
$seen = [];
$allMatch = true;
for ($i = 0; $i < 1000; $i++) {
    $k = palette_new_key();
    $seen[$k] = 1;
    if (!preg_match($re, $k)) $allMatch = false;
}
ok('key format', $allMatch);
ok('1000 keys unique', count($seen) === 1000);

ok('normalise spaces/case', palette_normalise_key('sdt-7q4m k2xd 93rb') === 'SDT-7Q4M-K2XD-93RB');
ok('normalise O/I/L', palette_normalise_key('SDT-OOOO-IIII-LLLL') === 'SDT-0000-1111-1111');
ok('normalise rejects junk', palette_normalise_key('hello') === null && palette_normalise_key('') === null);
ok('normalise rejects U', palette_normalise_key('SDT-UUUU-0000-0000') === null);

// issue + verify
$a = palette_issue_license($conn, 'maya@studio.vn', 'ORD1', 'es');
ok('issue returns id and key', is_int($a['id']) && preg_match($re, $a['key']) === 1);
$v = palette_verify($conn, strtolower($a['key']), '10.0.0.1');
ok('verify valid', $v === ['valid' => true, 'revoked' => false, 'email_masked' => 'm***@studio.vn']);
$r = $conn->query("SELECT last_verified_at FROM palette_licenses WHERE id={$a['id']}")->fetch_assoc();
ok('verify sets last_verified_at', $r['last_verified_at'] !== null);

$none = ['valid' => false, 'revoked' => false, 'email_masked' => null];
ok('verify unknown', palette_verify($conn, 'SDT-0000-0000-0000', '10.0.0.1') === $none);
ok('verify malformed identical', palette_verify($conn, 'nonsense', '10.0.0.1') === $none);

$one = palette_issue_license($conn, 'x@d.com', 'ORD9', 'en');
ok('single-char local masked', palette_verify($conn, $one['key'], '10.0.0.1')['email_masked'] === '*@d.com');

palette_revoke_by_order($conn, 'ORD1');
ok('verify revoked', palette_verify($conn, $a['key'], '10.0.0.1') === ['valid' => false, 'revoked' => true, 'email_masked' => 'm***@studio.vn']);

// rate limit verify
reset_state($conn);
$b = palette_issue_license($conn, 'b@studio.vn', 'ORD2', 'en');
$last = null;
for ($i = 1; $i <= 31; $i++) $last = palette_verify($conn, $b['key'], '10.0.0.2');
ok('31st verify rate limited', $last === ['error' => 'rate_limited']);
ok('other IP unaffected', palette_verify($conn, $b['key'], '10.0.0.3')['valid'] === true);

// resend
reset_state($conn);
$k1 = palette_issue_license($conn, 'two@studio.vn', 'ORD3', 'en');
$k2 = palette_issue_license($conn, 'two@studio.vn', 'ORD4', 'en');
$k3 = palette_issue_license($conn, 'two@studio.vn', 'ORD5', 'en');
palette_revoke_by_order($conn, 'ORD5');
palette_resend($conn, ' Two@Studio.vn ', '10.0.1.1');
ok('resend sends one email', count($GLOBALS['palette_sent']) === 1);
$m = $GLOBALS['palette_sent'][0] ?? ['to' => '', 'html' => '', 'subject' => ''];
ok('resend lists both live keys, not revoked',
    str_contains($m['html'], $k1['key']) && str_contains($m['html'], $k2['key']) && !str_contains($m['html'], $k3['key']));
ok('resend addressed to the email', $m['to'] === 'two@studio.vn');
$GLOBALS['palette_sent'] = [];
palette_resend($conn, 'nobody@studio.vn', '10.0.1.1');
ok('resend unknown sends nothing', count($GLOBALS['palette_sent']) === 0);
palette_resend($conn, 'two@studio.vn', '10.0.1.1');
palette_resend($conn, 'two@studio.vn', '10.0.1.1');
ok('2nd and 3rd resends delivered', count($GLOBALS['palette_sent']) === 2);
palette_resend($conn, 'two@studio.vn', '10.0.1.1');
ok('4th resend for same email sends nothing', count($GLOBALS['palette_sent']) === 2);

// per-IP resend limit 10/h
reset_state($conn);
for ($i = 0; $i < 12; $i++) {
    palette_issue_license($conn, "u$i@studio.vn", "OI$i", 'en');
    palette_resend($conn, "u$i@studio.vn", '10.0.9.9');
}
ok('per-IP resend cap 10', count($GLOBALS['palette_sent']) === 10);

// global per-email resend cap across IPs
reset_state($conn);
palette_issue_license($conn, 'bomb@studio.vn', 'ORDB', 'en');
foreach (['10.1.0.1', '10.1.0.2', '10.1.0.3', '10.1.0.4'] as $ip) palette_resend($conn, 'bomb@studio.vn', $ip);
ok('4 resends for one email from 4 IPs -> 3 emails', count($GLOBALS['palette_sent']) === 3);

// claim
reset_state($conn);
$c = palette_issue_license($conn, 'c@studio.vn', 'ORD6', 'en');
ok('claim first user', palette_claim($conn, strtolower($c['key']), 7) === true);
ok('claim again by owner is idempotent', palette_claim($conn, $c['key'], 7) === true);
ok('claim second user fails', palette_claim($conn, $c['key'], 8) === false);
ok('claim unknown fails', palette_claim($conn, 'bad', 7) === false);
$kk = palette_keys_for_user($conn, 7);
ok('keys for user', count($kk) === 1 && $kk[0]['key'] === $c['key'] && array_key_exists('revoked', $kk[0]));
ok('keys for other user empty', palette_keys_for_user($conn, 8) === []);

// mail
reset_state($conn);
$d = palette_issue_license($conn, 'es@studio.vn', 'ORD7', 'es');
ok('mail rejects bad address', palette_mail('not-an-email', 's', 'h') === false);
ok('mail rejects CRLF', palette_mail("a@b.com\r\nBcc: x@y.com", 's', 'h') === false);
$GLOBALS['palette_sent'] = [];
$mail = palette_license_email($d['key'], 'es');
ok('license email es subject', $mail['subject'] === palette_t('es', 'email_license_subject') && $mail['subject'] !== palette_t('en', 'email_license_subject'));
ok('license email has key and refund', str_contains($mail['html'], $d['key']) && str_contains($mail['html'], htmlspecialchars(palette_t('es', 'email_refund'))));
$en = palette_license_email($d['key'], 'en');
ok('en subject exact', $en['subject'] === 'Your Site to Design Token key');
ok('en refund exact', str_contains($en['html'], 'All sales are final. Because Pro unlocks instantly and costs $1.49, we do not offer refunds after purchase.'));
ok('en account link', str_contains($en['html'], 'https://vietnamcos.com/palette/account'));
ok('ar is rtl', str_contains(palette_license_email($d['key'], 'ar')['html'], 'dir="rtl"'));
ok('en not rtl', !str_contains($en['html'], 'dir="rtl"'));
ok('palette_mail records', palette_mail('a@b.com', 'S', '<p>x</p>') === true && $GLOBALS['palette_sent'][0] === ['to' => 'a@b.com', 'subject' => 'S', 'html' => '<p>x</p>']);

// i18n
ok('t fallback to en', palette_t('xx', 'email_license_subject') === 'Your Site to Design Token key');
ok('t missing key returns key', palette_t('en', 'nope_nope') === 'nope_nope');
ok('t vars', str_contains(palette_t('en', 'email_resend_intro', ['count' => 2]), '2'));
$langs = ['en', 'zh-CN', 'hi', 'es', 'ar', 'fr', 'bn'];
$base = array_keys(require PALETTE_DIR . '/lib/i18n/en.php');
$allKeys = true;
foreach ($langs as $l) {
    $arr = require PALETTE_DIR . '/lib/i18n/' . $l . '.php';
    if (array_keys($arr) !== $base) $allKeys = false;
    foreach ($arr as $s) if (preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $s)) $allKeys = false;
}
ok('all 7 languages have identical keys and no emoji', $allKeys);
ok('resend email escapes', !str_contains(palette_resend_email(['<script>'], 'en')['html'], '<script>'));
reset_state($conn);

// Resend transport (stubbed pp_http; stub presence disables the PALETTE_NO_MAIL capture)
ini_set('error_log', sys_get_temp_dir() . '/palette-test.log');
$calls = [];
$GLOBALS['pp_http'] = function ($method, $url, $headers, $body) use (&$calls) {
    $calls[] = compact('method', 'url', 'headers', 'body');
    return ['status' => 200, 'json' => ['id' => 'abc']];
};
$conn->query("DELETE FROM admin_settings WHERE setting_key='resend_api_key'");
ok('no api key -> false', palette_mail('a@b.com', 'S', '<p>x</p>') === false);
ok('no api key -> no HTTP call', $calls === []);
$conn->query("INSERT INTO admin_settings (setting_key, setting_value) VALUES ('resend_api_key', 're_testkey')");
ok('resend ok -> true', palette_mail(' A@B.com ', 'Subj é', '<p>x</p>') === true);
$cl = $calls[0] ?? ['method' => '', 'url' => '', 'headers' => [], 'body' => ''];
$bd = json_decode($cl['body'], true) ?: [];
ok('resend request shape', $cl['method'] === 'POST' && $cl['url'] === 'https://api.resend.com/emails'
    && in_array('Authorization: Bearer re_testkey', $cl['headers'], true) && in_array('Content-Type: application/json', $cl['headers'], true));
ok('resend body', $bd === ['from' => 'Site to Design Token <palette@vietnamcos.com>', 'to' => ['a@b.com'], 'subject' => 'Subj é',
    'html' => '<p>x</p>', 'reply_to' => 'support@vietnamcos.com']);
$GLOBALS['pp_http'] = fn() => ['status' => 500, 'json' => ['message' => 'boom']];
ok('resend 500 -> false', palette_mail('a@b.com', 'S', 'h') === false);
$GLOBALS['pp_http'] = fn() => ['status' => 200, 'json' => []];
ok('2xx without id -> false', palette_mail('a@b.com', 'S', 'h') === false);
$calls = [];
ok('bad address -> false, no call', palette_mail('nope', 'S', 'h') === false && $calls === []);
unset($GLOBALS['pp_http']);
$conn->query("DELETE FROM admin_settings WHERE setting_key='resend_api_key'");
