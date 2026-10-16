<?php
// Palette's own rate limiter (ruling S8) and client IP behind Cloudflare (ruling S9).
require __DIR__ . '/helpers.php';

$conn->query('DELETE FROM palette_rate');
$cols = [];
foreach ($conn->query('SHOW COLUMNS FROM palette_rate') as $r) $cols[] = $r['Field'];
ok('palette_rate table (bucket, window_start, count)', $cols === ['bucket', 'window_start', 'count']);
$pk = [];
foreach ($conn->query("SHOW INDEX FROM palette_rate WHERE Key_name = 'PRIMARY'") as $r) $pk[] = $r['Column_name'];
ok('primary key (bucket, window_start)', $pk === ['bucket', 'window_start']);

// ---- fixed windows
$t0 = 1_800_000_000;            // a multiple of 60 and of 3600
$hits = [];
for ($i = 0; $i < 4; $i++) $hits[] = palette_rate_hit('t:a', 3, 60, $t0 + $i);
ok('first 3 hits allowed, 4th refused', $hits === [true, true, true, false]);
ok('counts in one row per window', (int)$conn->query("SELECT count FROM palette_rate WHERE bucket='t:a' AND window_start=$t0")->fetch_row()[0] === 4);
ok('still refused at the end of the window', palette_rate_hit('t:a', 3, 60, $t0 + 59) === false);
ok('next window starts fresh', palette_rate_hit('t:a', 3, 60, $t0 + 60) === true);
ok('buckets are independent', palette_rate_hit('t:b', 3, 60, $t0 + 1) === true);
ok('window_start is floor(now/window)*window', (int)$conn->query("SELECT window_start FROM palette_rate WHERE bucket='t:a' ORDER BY window_start DESC LIMIT 1")->fetch_row()[0] === $t0 + 60);

// ---- the companies limiter's bug: verify's short window must not wipe resend's hourly counter
$conn->query('DELETE FROM palette_rate');
for ($i = 0; $i < 3; $i++) palette_rate_hit('resend:email:x', 3, 3600, $t0 + $i);
for ($i = 0; $i < 5; $i++) palette_rate_hit('verify:ip:10.0.0.1', 30, 60, $t0 + 61 + $i);
palette_rate_purge($t0 + 70);   // what an occasional purge would do
ok('resend counter survives verify calls (and a purge) 61 s later', palette_rate_hit('resend:email:x', 3, 3600, $t0 + 120) === false);
ok('resend counter rolls over in the next hour', palette_rate_hit('resend:email:x', 3, 3600, $t0 + 3600) === true);

// ---- purge: only palette_rate rows older than one day
$conn->query('DELETE FROM palette_rate');
palette_rate_hit('old', 1, 60, $t0);
palette_rate_hit('recent', 1, 60, $t0 + 86400 - 60);
palette_rate_purge($t0 + 86400 + 60);
$left = [];
foreach ($conn->query('SELECT bucket FROM palette_rate ORDER BY bucket') as $r) $left[] = $r['bucket'];
ok('purge drops rows older than a day only', $left === ['recent']);

ok('real clock works', palette_rate_hit('t:now', 1, 60) === true);
ok('long bucket names are cut to 120 chars, not an error', palette_rate_hit(str_repeat('b', 200), 1, 60, $t0) === true
    && palette_rate_hit(str_repeat('b', 200), 1, 60, $t0) === false);

// ---- CIDR matching
ok('v4 in range', palette_ip_in_cidr('173.245.48.1', '173.245.48.0/20') && palette_ip_in_cidr('173.245.63.255', '173.245.48.0/20'));
ok('v4 out of range', !palette_ip_in_cidr('173.245.64.0', '173.245.48.0/20') && !palette_ip_in_cidr('173.245.47.255', '173.245.48.0/20'));
ok('v4 /13 and /32 and /0', palette_ip_in_cidr('104.23.255.1', '104.16.0.0/13') && !palette_ip_in_cidr('104.24.0.1', '104.16.0.0/13')
    && palette_ip_in_cidr('1.2.3.4', '1.2.3.4/32') && !palette_ip_in_cidr('1.2.3.5', '1.2.3.4/32') && palette_ip_in_cidr('9.9.9.9', '0.0.0.0/0'));
ok('v6 in range', palette_ip_in_cidr('2606:4700:10::6816:1', '2606:4700::/32') && palette_ip_in_cidr('2a06:98c7:ffff::1', '2a06:98c0::/29'));
ok('v6 out of range', !palette_ip_in_cidr('2606:4701::1', '2606:4700::/32') && !palette_ip_in_cidr('2a06:98c8::1', '2a06:98c0::/29'));
ok('families never cross', !palette_ip_in_cidr('173.245.48.1', '2606:4700::/32') && !palette_ip_in_cidr('2606:4700::1', '173.245.48.0/20'));
ok('junk is never inside', !palette_ip_in_cidr('', '173.245.48.0/20') && !palette_ip_in_cidr('nope', '0.0.0.0/0') && !palette_ip_in_cidr('1.2.3.4', 'bad'));
ok('Cloudflare list has all 15 v4 and 7 v6 ranges', count(PALETTE_CLOUDFLARE_CIDRS) === 22);

// ---- palette_client_ip
ok('no proxy: REMOTE_ADDR', palette_client_ip(['REMOTE_ADDR' => '203.0.113.9']) === '203.0.113.9');
ok('from Cloudflare v4: CF-Connecting-IP', palette_client_ip(['REMOTE_ADDR' => '162.158.1.2', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7']) === '198.51.100.7');
ok('from Cloudflare v6: CF-Connecting-IP (v6 client)', palette_client_ip(['REMOTE_ADDR' => '2400:cb00:1::1', 'HTTP_CF_CONNECTING_IP' => '2001:db8::5']) === '2001:db8::5');
ok('spoofed header from a non-Cloudflare peer is ignored', palette_client_ip(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '1.1.1.1']) === '203.0.113.9');
ok('invalid CF header from Cloudflare falls back to REMOTE_ADDR', palette_client_ip(['REMOTE_ADDR' => '162.158.1.2', 'HTTP_CF_CONNECTING_IP' => '1.1.1.1, 2.2.2.2']) === '162.158.1.2'
    && palette_client_ip(['REMOTE_ADDR' => '162.158.1.2', 'HTTP_CF_CONNECTING_IP' => ['x']]) === '162.158.1.2');
ok('nginx real_ip already applied: REMOTE_ADDR is the client', palette_client_ip(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7']) === '198.51.100.7');
ok('missing REMOTE_ADDR -> empty', palette_client_ip([]) === '');

$conn->query('DELETE FROM palette_rate');
