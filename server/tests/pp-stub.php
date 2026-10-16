<?php
// Shared PayPal + Resend stub for orders/webhook tests (not a *-test.php, so run.php skips it).
// Every HTTP call goes through companies' pp_http hook $GLOBALS['pp_http']:
//   - POST .../v1/oauth2/token           -> a token
//   - POST https://api.resend.com/emails -> recorded in $GLOBALS['mails'] (the real palette_mail Resend path)
//   - anything else                      -> $GLOBALS['pp_routes']["METHOD /path"] (array response or callable($body)),
//                                           recorded in $GLOBALS['pp_calls']; unrouted -> HTTP 500.

function stub_reset(mysqli $c): void
{
    $c->query('DELETE FROM palette_orders');
    $c->query('DELETE FROM palette_licenses');
    $c->query("DELETE FROM admin_settings WHERE setting_key IN ('palette', 'paypal', 'resend_api_key')");
    $c->query("INSERT INTO admin_settings (setting_key, setting_value) VALUES ('resend_api_key', 're_test')");
    stub_paypal($c, true);
    $GLOBALS['pp_routes'] = [];
    $GLOBALS['pp_calls'] = [];
    $GLOBALS['mails'] = [];
    $GLOBALS['pp_http'] = function (string $method, string $url, array $headers, ?string $body): array {
        if (str_ends_with($url, '/v1/oauth2/token')) return ['status' => 200, 'json' => ['access_token' => 'tok']];
        if ($url === 'https://api.resend.com/emails') {
            $GLOBALS['mails'][] = json_decode((string)$body, true);
            return ['status' => 200, 'json' => ['id' => 'mail' . count($GLOBALS['mails'])]];
        }
        $path = (string)preg_replace('#^https://api-m\.(sandbox\.)?paypal\.com#', '', $url);
        $GLOBALS['pp_calls'][] = ['method' => $method, 'path' => $path, 'body' => $body === null ? null : json_decode($body, true)];
        $r = $GLOBALS['pp_routes']["$method $path"] ?? ['status' => 500, 'json' => ['message' => 'unrouted']];
        return is_callable($r) ? $r($body === null ? null : json_decode($body, true)) : $r;
    };
}

function stub_paypal(mysqli $c, bool $ready): void
{
    $json = json_encode(['enabled' => $ready, 'mode' => 'sandbox', 'client_id' => 'cid', 'secret' => 'sec', 'error' => '']);
    $st = $c->prepare("INSERT INTO admin_settings (setting_key, setting_value) VALUES ('paypal', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $st->bind_param('s', $json);
    $st->execute();
    $st->close();
}

function calls_to(string $method, string $prefix): array
{
    return array_values(array_filter($GLOBALS['pp_calls'], fn($x) => $x['method'] === $method && str_starts_with($x['path'], $prefix)));
}

// PayPal's order JSON after a completed capture.
function pp_completed(string $orderId, string $value = '1.49', string $currency = 'USD', string $capId = 'CAP1'): array
{
    return ['id' => $orderId, 'status' => 'COMPLETED', 'payer' => ['email_address' => 'payer@paypal.test'],
            'purchase_units' => [['payments' => ['captures' => [[
                'id' => $capId, 'status' => 'COMPLETED', 'amount' => ['currency_code' => $currency, 'value' => $value]]]]]]];
}

function order_row(mysqli $c, string $orderId): ?array
{
    $st = $c->prepare('SELECT * FROM palette_orders WHERE order_id = ?');
    $st->bind_param('s', $orderId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ?: null;
}

function license_count(mysqli $c): int
{
    return (int)$c->query('SELECT COUNT(*) FROM palette_licenses')->fetch_row()[0];
}

// Creates a palette order through the real code with PayPal answering $paypalId.
function make_order(mysqli $c, string $paypalId, string $email = 'buyer@studio.vn', string $lang = 'es'): array
{
    $GLOBALS['pp_routes']['POST /v2/checkout/orders'] = ['status' => 201, 'json' => ['id' => $paypalId, 'status' => 'CREATED']];
    return palette_create_order($c, $email, $lang, true, null, null);
}
