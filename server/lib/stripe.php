<?php
// Stripe connection layer (2026-11-04): settings per mode (live/test), key hints, the API call and webhook signature check.
// Never returns or logs a secret except palette_stripe_secret() to its caller.
// The companies site already has a Stripe connection (includes/stripe.php, read-only here); the live key may be shared.
if (!function_exists('st_settings') && is_file(PALETTE_COMPANIES_DIR . '/includes/stripe.php')) {
    require_once PALETTE_COMPANIES_DIR . '/includes/stripe.php';
}

const PALETTE_STRIPE_KEY = 'palette_stripe';

function palette_stripe_mode_defaults(string $mode): array
{
    return ['source' => $mode === 'live' ? 'shared' : 'own', 'secret' => '', 'webhook_id' => '', 'webhook_secret' => '', 'checked_at' => '', 'error' => ''];
}

function palette_stripe_settings(mysqli $c): array
{
    $k = PALETTE_STRIPE_KEY;
    $st = $c->prepare('SELECT setting_value FROM admin_settings WHERE setting_key = ?');
    $st->bind_param('s', $k);
    $st->execute();
    $raw = $st->get_result()->fetch_row()[0] ?? null;
    $st->close();
    $v = is_string($raw) ? json_decode($raw, true) : null;
    $v = is_array($v) ? $v : [];
    $out = [];
    foreach (['live', 'test'] as $mode) {
        $d = palette_stripe_mode_defaults($mode);
        $m = is_array($v[$mode] ?? null) ? array_intersect_key($v[$mode], $d) + $d : $d;
        foreach ($d as $f => $_) $m[$f] = (string)$m[$f];
        if ($mode === 'test' || !in_array($m['source'], ['shared', 'own'], true)) $m['source'] = $d['source'];
        $out[$mode] = $m;
    }
    $methods = [];
    foreach (is_array($v['checkout_methods'] ?? null) ? $v['checkout_methods'] : [] as $x) {
        if (in_array($x, ['stripe', 'paypal'], true) && !in_array($x, $methods, true)) $methods[] = $x;
    }
    $out['checkout_methods'] = $methods ?: ['stripe'];
    return $out;
}

function palette_stripe_settings_put(mysqli $c, array $s): void
{
    $clean = [];
    foreach (['live', 'test'] as $mode) {
        $d = palette_stripe_mode_defaults($mode);
        $clean[$mode] = is_array($s[$mode] ?? null) ? array_intersect_key($s[$mode], $d) + $d : $d;
    }
    $clean['checkout_methods'] = array_values(array_filter(is_array($s['checkout_methods'] ?? null) ? $s['checkout_methods'] : ['stripe'],
        fn($x) => in_array($x, ['stripe', 'paypal'], true))) ?: ['stripe'];
    $json = json_encode($clean);
    $k = PALETTE_STRIPE_KEY;
    $st = $c->prepare('INSERT INTO admin_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $st->bind_param('ss', $k, $json);
    $st->execute();
    $st->close();
}

// Live + shared: the companies site's key, but only while that site is itself in live mode. Otherwise the own stored key.
function palette_stripe_secret(mysqli $c, string $mode): string
{
    $mode = $mode === 'live' ? 'live' : 'test';
    $m = palette_stripe_settings($c)[$mode];
    if ($mode === 'live' && $m['source'] === 'shared') {
        if (!function_exists('st_settings')) return '';
        $co = st_settings($c);
        return ($co['mode'] ?? '') === 'live' ? (string)($co['secret'] ?? '') : '';
    }
    return $m['secret'];
}

function palette_stripe_ready(mysqli $c, string $mode): bool
{
    $mode = $mode === 'live' ? 'live' : 'test';
    $m = palette_stripe_settings($c)[$mode];
    return palette_stripe_secret($c, $mode) !== '' && $m['webhook_secret'] !== '' && $m['error'] === '';
}

// What the admin screen may show: never a key.
function palette_stripe_public(mysqli $c): array
{
    $s = palette_stripe_settings($c);
    $out = [];
    foreach (['live', 'test'] as $mode) {
        $m = $s[$mode];
        $sec = palette_stripe_secret($c, $mode);
        $out[$mode] = ['connected' => palette_stripe_ready($c, $mode), 'source' => $m['source'],
                       'key_hint' => $sec !== '' ? substr($sec, 0, 8) . '…' . substr($sec, -4) : '',
                       'webhook' => $m['webhook_secret'] !== '', 'checked_at' => $m['checked_at'], 'error' => $m['error']];
    }
    $out['checkout_methods'] = $s['checkout_methods'];
    return $out;
}

// One Stripe API call (form-encoded). ['status' => int, 'json' => array]; status 0 on transport failure.
function palette_st_api(mysqli $c, string $mode, string $method, string $path, array $params = []): array
{
    $body = $params ? http_build_query($params) : null;
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/') . ($method === 'GET' && $body ? '?' . $body : '');
    $headers = ['Authorization: Bearer ' . palette_stripe_secret($c, $mode), 'Content-Type: application/x-www-form-urlencoded', 'Stripe-Version: 2024-06-20'];
    $send = $method === 'GET' ? null : $body;
    if (isset($GLOBALS['palette_st_http']) && is_callable($GLOBALS['palette_st_http'])) {
        $r = ($GLOBALS['palette_st_http'])($method, $url, $headers, $send, 20);
    } else {
        $r = palette_http($method, $url, $headers, $send, 20);
    }
    return ['status' => (int)($r['status'] ?? 0), 'json' => is_array($r['json'] ?? null) ? $r['json'] : []];
}

// Stripe-Signature check: t=<unix>,v1=<hmac>[,v1=<hmac>...]; HMAC-SHA256 of "t.payload"; within 5 minutes of $now.
function palette_st_signed(string $payload, string $header, string $secret, ?int $now = null): bool
{
    if ($secret === '' || $header === '') return false;
    $now ??= time();
    $t = null;
    $sigs = [];
    foreach (explode(',', $header) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) continue;
        if ($kv[0] === 't' && ctype_digit($kv[1])) $t = (int)$kv[1];
        elseif ($kv[0] === 'v1') $sigs[] = $kv[1];
    }
    if ($t === null || !$sigs || abs($now - $t) > 300) return false;
    $expect = hash_hmac('sha256', "$t.$payload", $secret);
    $match = false;
    foreach ($sigs as $s) if (hash_equals($expect, $s)) $match = true;
    return $match;
}
