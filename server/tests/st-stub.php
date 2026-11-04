<?php
// Shared Stripe + Resend stub (not a *-test.php, so run.php skips it).
//   - $GLOBALS['palette_st_http'] routes "METHOD /v1/path" -> $GLOBALS['st_routes'] (array response or callable($params)),
//     every call recorded in $GLOBALS['st_calls']; unrouted -> HTTP 500.
//   - Resend mails go through companies' pp_http hook into $GLOBALS['mails'], exactly like pp-stub.php.

function st_stub_reset(mysqli $c): void
{
    foreach (['palette_orders', 'palette_subscriptions', 'palette_sub_payments', 'palette_licenses', 'palette_admin_log'] as $t) {
        $c->query("DELETE FROM $t");
    }
    $c->query("DELETE FROM admin_settings WHERE setting_key IN ('palette', 'palette_stripe', 'paypal', 'stripe', 'resend_api_key')");
    $c->query("INSERT INTO admin_settings (setting_key, setting_value) VALUES ('resend_api_key', 're_test')");
    $GLOBALS['st_routes'] = [];
    $GLOBALS['st_calls'] = [];
    $GLOBALS['mails'] = [];
    $GLOBALS['pp_http'] = function (string $method, string $url, array $headers, ?string $body): array {
        if ($url === 'https://api.resend.com/emails') {
            $GLOBALS['mails'][] = json_decode((string)$body, true);
            return ['status' => 200, 'json' => ['id' => 'mail' . count($GLOBALS['mails'])]];
        }
        return ['status' => 500, 'json' => ['message' => 'unrouted']];
    };
    $GLOBALS['palette_st_http'] = function (string $method, string $url, array $headers, ?string $body): array {
        $path = (string)preg_replace('#^https://api\.stripe\.com#', '', $url);
        $path = explode('?', $path, 2)[0];
        parse_str($body ?? (string)(parse_url($url, PHP_URL_QUERY) ?? ''), $params);
        $GLOBALS['st_calls'][] = ['method' => $method, 'path' => $path, 'url' => $url, 'headers' => $headers, 'raw' => $body, 'params' => $params];
        $r = $GLOBALS['st_routes']["$method $path"] ?? ['status' => 500, 'json' => ['error' => ['message' => 'unrouted']]];
        return is_callable($r) ? $r($params) : $r;
    };
}

// Stores an own key + webhook for a mode: secret sk_{mode}_x, webhook id we_{mode}, signing secret whsec_{mode}.
function st_stub_connect(mysqli $c, string $mode): void
{
    $s = palette_stripe_settings($c);
    $s[$mode] = ['source' => 'own', 'secret' => "sk_{$mode}_x", 'webhook_id' => "we_$mode", 'webhook_secret' => "whsec_$mode",
                 'checked_at' => date('Y-m-d H:i:s'), 'error' => ''];
    palette_stripe_settings_put($c, $s);
}

// A Stripe-Signature header for $payload.
function st_sign(string $payload, string $secret, ?int $t = null): string
{
    $t ??= time();
    return "t=$t,v1=" . hash_hmac('sha256', "$t.$payload", $secret);
}
