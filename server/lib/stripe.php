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
    return palette_st_request(palette_stripe_secret($c, $mode), $method, $path, $params);
}

// The same call with an explicit key (used to try a key before it is stored).
function palette_st_request(string $secret, string $method, string $path, array $params = []): array
{
    $body = $params ? http_build_query($params) : null;
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/') . ($method === 'GET' && $body ? '?' . $body : '');
    $headers = ['Authorization: Bearer ' . $secret,'Content-Type: application/x-www-form-urlencoded', 'Stripe-Version: 2024-06-20'];
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

// ---- Connect, test, disconnect (2026-11-04) ----

const PALETTE_STRIPE_EVENTS = ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'invoice.paid', 'invoice.payment_failed',
    'customer.subscription.updated', 'customer.subscription.deleted', 'charge.refunded', 'charge.dispute.created'];

function palette_stripe_webhook_url(): string
{
    return 'https://vietnamcos.com/palette/stripe-webhook.php';
}

// The companies site's key, only while it is in live mode.
function palette_stripe_shared_secret(mysqli $c): string
{
    if (!function_exists('st_settings')) return '';
    $co = st_settings($c);
    return ($co['mode'] ?? '') === 'live' ? (string)($co['secret'] ?? '') : '';
}

// Stripe's own message, or "HTTP <status>"; anything shaped like a key is masked before it is stored.
function palette_stripe_api_error(array $r): string
{
    $msg = trim((string)($r['json']['error']['message'] ?? ''));
    if ($msg === '') return 'HTTP ' . (int)($r['status'] ?? 0);
    return mb_substr((string)preg_replace('/\b(?:sk|rk|pk|whsec)_[A-Za-z0-9_*]+/', '[key]', $msg), 0, 300);
}

// Updates only checked_at/error of one mode, from fresh settings.
function palette_stripe_mark(mysqli $c, string $mode, string $error): void
{
    $s = palette_stripe_settings($c);
    $s[$mode]['checked_at'] = date('Y-m-d H:i:s');
    $s[$mode]['error'] = $error;
    palette_stripe_settings_put($c, $s);
}

function palette_stripe_connect(mysqli $c, string $mode, string $source, string $secret): array
{
    $mode = $mode === 'live' ? 'live' : 'test';
    $source = ($mode === 'live' && $source === 'shared') ? 'shared' : 'own';
    if ($source === 'shared') {
        $key = palette_stripe_shared_secret($c);
        if ($key === '') return ['ok' => false, 'error' => 'The companies site has no live Stripe key to share.'];
        $secret = '';
    } else {
        $key = trim($secret);
        if (!str_starts_with($key, "sk_{$mode}_") && !str_starts_with($key, "rk_{$mode}_")) {
            return ['ok' => false, 'error' => "That key is not a $mode-mode secret or restricted key."];
        }
        $secret = $key;
    }
    $fail = function (string $msg) use ($c, $mode): array {
        palette_stripe_mark($c, $mode, $msg);
        return ['ok' => false, 'error' => $msg];
    };

    $b = palette_st_request($key, 'GET', 'balance');
    if ($b['status'] !== 200) return $fail(palette_stripe_api_error($b));
    if (($b['json']['livemode'] ?? null) !== ($mode === 'live')) return $fail("That key is not in $mode mode.");

    $oldSettings = palette_stripe_settings($c)[$mode];
    $oldKey = palette_stripe_secret($c, $mode);
    $w = palette_st_request($key, 'POST', 'webhook_endpoints', ['url' => palette_stripe_webhook_url(), 'enabled_events' => PALETTE_STRIPE_EVENTS,
        'description' => 'Site to Design Token (palette)', 'metadata' => ['palette' => '1']]);
    $id = (string)($w['json']['id'] ?? '');
    $whsec = (string)($w['json']['secret'] ?? '');
    if ($w['status'] !== 200 || $id === '' || $whsec === '') return $fail(palette_stripe_api_error($w));

    // Created first, so a failure above leaves the old connection working. Only the palette's own old endpoint is removed; a 404 is fine.
    if ($oldSettings['webhook_id'] !== '' && $oldSettings['webhook_id'] !== $id) {
        palette_st_request($oldKey !== '' ? $oldKey : $key, 'DELETE', 'webhook_endpoints/' . rawurlencode($oldSettings['webhook_id']));
    }
    $s = palette_stripe_settings($c);
    $s[$mode] = ['source' => $source, 'secret' => $secret, 'webhook_id' => $id, 'webhook_secret' => $whsec,
                 'checked_at' => date('Y-m-d H:i:s'), 'error' => ''];
    palette_stripe_settings_put($c, $s);
    return ['ok' => true, 'error' => null];
}

function palette_stripe_check(mysqli $c, string $mode): array
{
    $mode = $mode === 'live' ? 'live' : 'test';
    $m = palette_stripe_settings($c)[$mode];
    $key = palette_stripe_secret($c, $mode);
    $labels = ['Key accepted by Stripe', "Key is in $mode mode", 'Webhook registered', 'Webhook enabled', 'Webhook points to this site', 'Webhook sends every needed event'];
    $res = array_fill_keys($labels, ['ok' => false, 'detail' => 'Not checked.']);
    $set = function (string $l, bool $ok, string $detail) use (&$res) { $res[$l] = ['ok' => $ok, 'detail' => $detail]; };

    if ($key === '') {
        $res[$labels[0]]['detail'] = $mode === 'live' && $m['source'] === 'shared' ? 'No live key is available from the companies site.' : 'No key is stored.';
    } else {
        $b = palette_st_request($key, 'GET', 'balance');
        if ($b['status'] === 200) {
            $set($labels[0], true, 'Stripe accepted the key.');
            $live = ($b['json']['livemode'] ?? null) === true;
            $set($labels[1], $live === ($mode === 'live'), 'The key is in ' . ($live ? 'live' : 'test') . ' mode.');
        } else {
            $set($labels[0], false, palette_stripe_api_error($b));
        }
        if ($m['webhook_id'] === '') {
            $set($labels[2], false, 'No webhook has been registered. Connect again.');
        } else {
            $e = palette_st_request($key, 'GET', 'webhook_endpoints/' . rawurlencode($m['webhook_id']));
            if ($e['status'] !== 200) {
                $set($labels[2], false, $e['status'] === 404 ? 'Stripe has no such webhook. Connect again.' : palette_stripe_api_error($e));
            } else {
                $j = $e['json'];
                $set($labels[2], true, 'The webhook exists.');
                $set($labels[3], ($j['status'] ?? '') === 'enabled', 'Status: ' . ((string)($j['status'] ?? '') ?: 'unknown') . '.');
                $url = (string)($j['url'] ?? '');
                $set($labels[4], $url === palette_stripe_webhook_url(), 'It points to ' . ($url ?: 'nowhere') . '.');
                $have = is_array($j['enabled_events'] ?? null) ? $j['enabled_events'] : [];
                $missing = in_array('*', $have, true) ? [] : array_values(array_diff(PALETTE_STRIPE_EVENTS, $have));
                $set($labels[5], !$missing, $missing ? 'Missing: ' . implode(', ', $missing) . '.' : 'All ' . count(PALETTE_STRIPE_EVENTS) . ' events.');
            }
        }
    }
    $checks = [];
    $all = true;
    $firstBad = '';
    foreach ($labels as $l) {
        $checks[] = ['label' => $l, 'ok' => $res[$l]['ok'], 'detail' => $res[$l]['detail']];
        if (!$res[$l]['ok']) { $all = false; $firstBad = $firstBad ?: $l . ': ' . $res[$l]['detail']; }
    }
    if ($key !== '') palette_stripe_mark($c, $mode, $all ? '' : $firstBad);
    return ['ok' => $all, 'checks' => $checks];
}

function palette_stripe_disconnect(mysqli $c, string $mode): array
{
    $mode = $mode === 'live' ? 'live' : 'test';
    $m = palette_stripe_settings($c)[$mode];
    $key = palette_stripe_secret($c, $mode);
    if ($m['webhook_id'] !== '' && $key !== '') {
        $r = palette_st_request($key, 'DELETE', 'webhook_endpoints/' . rawurlencode($m['webhook_id']));
        // Gone already (404) or the key no longer works (401): nothing left to remove. An outage keeps the connection.
        if (!in_array($r['status'], [200, 404, 401], true)) return ['ok' => false];
    }
    $s = palette_stripe_settings($c);
    $s[$mode] = palette_stripe_mode_defaults($mode);
    palette_stripe_settings_put($c, $s);
    return ['ok' => true];
}

function palette_stripe_set_methods(mysqli $c, array $methods): array
{
    $clean = [];
    foreach ($methods as $x) {
        if (!in_array($x, ['stripe', 'paypal'], true)) return ['ok' => false, 'error' => 'Unknown payment method.'];
        if (!in_array($x, $clean, true)) $clean[] = $x;
    }
    if (!$clean) return ['ok' => false, 'error' => 'Choose at least one payment method.'];
    if (in_array('stripe', $clean, true) && !palette_stripe_ready($c, 'live')) return ['ok' => false, 'error' => 'Stripe is not connected in live mode.'];
    if (in_array('paypal', $clean, true) && !pp_ready(pp_settings($c))) return ['ok' => false, 'error' => 'PayPal is not set up.'];
    $s = palette_stripe_settings($c);
    $s['checkout_methods'] = $clean;
    palette_stripe_settings_put($c, $s);
    return ['ok' => true, 'error' => null];
}
