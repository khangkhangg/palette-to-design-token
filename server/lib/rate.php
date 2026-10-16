<?php
// Palette's own rate limiter (ruling S8) and the client IP behind Cloudflare (ruling S9).
//
// companies' checkRateLimit purges every api_rate_limits row older than the CALLER's window, so a
// 60-second limit wipes hourly counters. Palette therefore keeps its own table palette_rate with
// fixed windows and only ever purges its own rows older than a day.

const PALETTE_RATE_KEEP_SEC = 86400;
const PALETTE_RATE_PURGE_ONE_IN = 50;

// Records one hit on $bucket and answers whether it is still within $max hits for the current
// fixed window (window_start = floor(now / window) * window). True = allowed, false = limited.
function palette_rate_hit(string $bucket, int $max, int $windowSec, ?int $now = null): bool
{
    $c = $GLOBALS['conn'];
    $now ??= time();
    $start = intdiv($now, $windowSec) * $windowSec;
    $bucket = substr($bucket, 0, 120);
    $st = $c->prepare('INSERT INTO palette_rate (bucket, window_start, count) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE count = count + 1');
    $st->bind_param('si', $bucket, $start);
    $st->execute();
    $st->close();
    $st = $c->prepare('SELECT count FROM palette_rate WHERE bucket = ? AND window_start = ?');
    $st->bind_param('si', $bucket, $start);
    $st->execute();
    $count = (int)($st->get_result()->fetch_row()[0] ?? PHP_INT_MAX);
    $st->close();
    if (random_int(1, PALETTE_RATE_PURGE_ONE_IN) === 1) palette_rate_purge($now);
    return $count <= $max;
}

function palette_rate_purge(?int $now = null): void
{
    $cut = ($now ?? time()) - PALETTE_RATE_KEEP_SEC;
    $st = $GLOBALS['conn']->prepare('DELETE FROM palette_rate WHERE window_start < ?');
    $st->bind_param('i', $cut);
    $st->execute();
    $st->close();
}

// Cloudflare's published edge ranges. Source: https://www.cloudflare.com/ips-v4 and
// https://www.cloudflare.com/ips-v6 (fetched 2026-10-17). Refresh yearly.
const PALETTE_CLOUDFLARE_CIDRS = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
    '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
    '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
    '2a06:98c0::/29', '2c0f:f248::/32',
];

// IPv4 or IPv6 $ip inside $cidr (same family only).
function palette_ip_in_cidr(string $ip, string $cidr): bool
{
    $parts = explode('/', $cidr);
    if (count($parts) !== 2 || !ctype_digit($parts[1])) return false;
    $ipBin = filter_var($ip, FILTER_VALIDATE_IP) ? inet_pton($ip) : false;
    $netBin = filter_var($parts[0], FILTER_VALIDATE_IP) ? inet_pton($parts[0]) : false;
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) return false;
    $bits = (int)$parts[1];
    if ($bits > strlen($ipBin) * 8) return false;
    $bytes = intdiv($bits, 8);
    if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) return false;
    $rem = $bits % 8;
    if ($rem === 0) return true;
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
}

// The visitor's IP: CF-Connecting-IP only when the peer (REMOTE_ADDR) is a Cloudflare edge,
// else REMOTE_ADDR. Never X-Forwarded-For. If nginx's real_ip module already rewrote
// REMOTE_ADDR to the visitor, the peer is not Cloudflare and REMOTE_ADDR is used: both are fine.
function palette_client_ip(array $server): string
{
    $remote = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';
    $cf = $server['HTTP_CF_CONNECTING_IP'] ?? null;
    if (!is_string($cf) || !filter_var($cf, FILTER_VALIDATE_IP)) return $remote;
    foreach (PALETTE_CLOUDFLARE_CIDRS as $cidr) {
        if (palette_ip_in_cidr($remote, $cidr)) return $cf;
    }
    return $remote;
}
