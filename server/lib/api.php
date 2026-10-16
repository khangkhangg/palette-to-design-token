<?php
// JSON API handler: pure function of the request array (see api.php for the superglobals adapter).
// $req = ['method','action','post','origin','ip','session','lang'?]. CSRF is checked against $_SESSION
// (companies' helpers are session based); $req['session'] supplies the logged-in user.
// Returns ['status','headers','body'] plus 'defer' (a callable to run after the response is flushed) for resend.

const PALETTE_API_POST_ACTIONS = ['create', 'capture', 'resend', 'claim', 'verify'];

function palette_api_extension_ids(array $settings): array
{
    $ids = $settings['extension_ids'] ?? null;
    if (!is_array($ids)) return [];
    return array_values(array_filter($ids, fn($id) => is_string($id) && preg_match('/^[a-p]{32}$/', $id) === 1));
}

// CORS headers for an allowed chrome-extension origin; [] otherwise (browsers enforce CORS, so the request is still processed).
function palette_api_cors(string $origin, array $settings): array
{
    if (!preg_match('#^chrome-extension://([a-p]{32})$#', $origin, $m)) return [];
    if (!in_array($m[1], palette_api_extension_ids($settings), true)) return [];
    return ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin'];
}

function palette_api_reply(int $status, array $body, array $headers = []): array
{
    return ['status' => $status, 'body' => $body, 'headers' => $headers + [
        'Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']];
}

function palette_api_error(int $status, string $key, string $lang, array $headers = []): array
{
    return palette_api_reply($status, ['ok' => false, 'error' => $key, 'message' => palette_t($lang, $key)], $headers);
}

// The session's checkout token, created only when missing: rotating it on every config call would
// invalidate a second open tab (checkout and account share it).
function palette_api_csrf_token(): string
{
    $tok = $_SESSION['csrf_tokens']['palette_checkout'] ?? null;
    return is_string($tok) && preg_match('/^[0-9a-f]{64}$/', $tok) ? $tok : generateCSRFToken('palette_checkout');
}

const PALETTE_API_SESSION_ORDERS = 20;

// True when this session created the PayPal order (create stores ids in $_SESSION['palette_orders']).
function palette_api_owns_order(string $orderId): bool
{
    $mine = $_SESSION['palette_orders'] ?? null;
    return is_array($mine) && in_array($orderId, $mine, true);
}

function palette_api_handle(array $req): array
{
    $conn = $GLOBALS['conn'];
    $method = (string)($req['method'] ?? '');
    $action = (string)($req['action'] ?? '');
    $post = is_array($req['post'] ?? null) ? $req['post'] : [];
    $str = fn(string $k): string => is_string($post[$k] ?? null) ? $post[$k] : '';
    $lang = palette_lang(is_string($req['lang'] ?? null) ? $req['lang'] : $str('lang'));

    if ($action !== 'config' && !in_array($action, PALETTE_API_POST_ACTIONS, true)) return palette_api_error(404, 'err_unknown_action', $lang);

    $cors = $action === 'verify' ? palette_api_cors((string)($req['origin'] ?? ''), palette_settings($conn)) : [];
    if ($action === 'verify' && $method === 'OPTIONS') {
        return palette_api_reply(204, [], $cors + ['Access-Control-Allow-Methods' => 'POST', 'Access-Control-Allow-Headers' => 'Content-Type', 'Access-Control-Max-Age' => '600']);
    }
    if ($method !== ($action === 'config' ? 'GET' : 'POST')) return palette_api_error(405, 'err_method', $lang, $cors);

    if ($action === 'config') {
        $pp = pp_settings($conn);
        $m = palette_money_settings(palette_settings($conn));
        $ready = pp_ready($pp);
        return palette_api_reply(200, ['ok' => true, 'enabled' => $ready] + ($ready ? ['client_id' => $pp['client_id']] : [])
            + ['price' => palette_money($m['price']), 'tax_mode' => $m['tax_mode'], 'csrf' => palette_api_csrf_token()]);
    }

    if ($action === 'verify') {
        $r = palette_verify($conn, $str('key'), (string)($req['ip'] ?? ''));
        if (isset($r['error'])) return palette_api_error(429, 'api_rate_limited', $lang, $cors);
        return palette_api_reply(200, ['ok' => true] + $r, $cors);
    }

    if (!verifyCSRFToken(is_string($post['csrf'] ?? null) ? $post['csrf'] : '', 'palette_checkout')) return palette_api_error(419, 'err_csrf', $lang);

    switch ($action) {
        case 'create':
            if (!palette_rate_hit('create:ip:' . (string)($req['ip'] ?? ''), 20, 3600)) return palette_api_error(429, 'api_rate_limited', $lang);
            $r = palette_create_order($conn, $str('email'), $lang, $str('consent') === '1', $str('country') ?: null, $str('region') ?: null);
            if (!$r['ok']) return palette_api_error(['err_paused' => 503, 'err_paypal' => 502][$r['error']] ?? 400, $r['error'], $lang);
            // Only the session that created an order is shown its key at capture (see palette_api_owns_order).
            $mine = is_array($_SESSION['palette_orders'] ?? null) ? $_SESSION['palette_orders'] : [];
            $mine[] = $r['order_id'];
            $_SESSION['palette_orders'] = array_slice($mine, -PALETTE_API_SESSION_ORDERS);   // the last 20 are plenty
            return palette_api_reply(200, ['ok' => true, 'order_id' => $r['order_id'], 'total' => $r['total']]);
        case 'capture':
            if ($str('order_id') === '') return palette_api_error(400, 'err_bad_request', $lang);
            $r = palette_capture($conn, $str('order_id'));
            if (!$r['ok']) {
                if (!empty($r['pending'])) return palette_api_error(202, 'co_err_confirm', $lang);   // paid; PayPal still settling
                return !empty($r['retry']) ? palette_api_error(503, 'err_retry', $lang) : palette_api_error(502, $r['error'], $lang);
            }
            if (!palette_api_owns_order($str('order_id'))) {
                // Not this session's order: the key went to the buyer's email, never to whoever knows the order id.
                return palette_api_reply(200, ['ok' => true, 'key' => null, 'email_masked' => $r['email_masked'],
                    'message' => strtr(palette_t($lang, 'co_key_emailed'), ['{email}' => $r['email_masked']])]);
            }
            return palette_api_reply(200, ['ok' => true, 'key' => $r['key'], 'email_masked' => $r['email_masked']]);
        case 'resend':
            $email = $str('email');
            $res = palette_api_reply(200, ['ok' => true, 'message' => palette_t($lang, 'api_resend_ok')]);
            $res['defer'] = fn() => palette_resend($conn, $email, (string)($req['ip'] ?? ''));
            return $res;
        default: // claim
            $uid = $req['session']['user_id'] ?? null;
            if (!(is_int($uid) || (is_string($uid) && ctype_digit($uid))) || (int)$uid < 1) return palette_api_error(403, 'err_login', $lang);
            if (!palette_rate_hit('claim:user:' . (int)$uid, 30, 3600)) return palette_api_error(429, 'api_rate_limited', $lang);
            if (!palette_claim($conn, $str('key'), (int)$uid)) return palette_api_error(400, 'err_invalid_key', $lang);
            return palette_api_reply(200, ['ok' => true]);
    }
}
