<?php
// Router for `php -S 127.0.0.1:<port> server/tests/dev-router.php` (checkout e2e and local preview only; never deployed).
// Seeds the test database into $_ENV BEFORE companies' config.php loads (its .env holds real credentials, and under
// the cli-server SAPI it reads DB_HOST_REMOTE), refuses to serve unless the database name ends in _test, and maps
// /palette/* onto server/*.
// PALETTE_DEV_PP_STUB=1 (honoured only on a *_test database) replaces PayPal REST and Resend with a local stub and
// enables /__dev/seed?paypal=ready|paused&tax=none|ca (resets the palette tables), /__dev/login?user_id=N and
// /__dev/license?email=… for the e2e.
foreach (['DB_HOST_LOCAL' => '127.0.0.1', 'DB_HOST_REMOTE' => '127.0.0.1', 'DB_USERNAME' => 'palette',
          'DB_PASSWORD' => 'palette', 'DB_NAME' => 'palette_test'] as $k => $v) {
    $_ENV[$k] = $v;
    putenv("$k=$v");
}
$_ENV['DEEPSEEK_API_KEY'] = '';   // companies' .env holds a real platform AI key; never let ai_key_for() fall back to it
putenv('DEEPSEEK_API_KEY=');
if (!getenv('PALETTE_COMPANIES_DIR')) putenv('PALETTE_COMPANIES_DIR=C:\\Projects\\companies');
$server = dirname(__DIR__);
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

// Static assets need no database.
if (preg_match('#^/palette/assets/([A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)?\.(css|js|woff2|txt|png))$#', $path, $m)
    && is_file($file = $server . '/assets/' . $m[1])) {
    $types = ['css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'woff2' => 'font/woff2', 'txt' => 'text/plain; charset=utf-8', 'png' => 'image/png'];
    header('Content-Type: ' . $types[$m[2]]);
    header('Cache-Control: no-store');
    readfile($file);
    return true;
}

// The direct-download zip, from server/download/ when one has been put there (it is uploaded at deploy, never committed).
if (preg_match('#^/palette/download/([A-Za-z0-9_-][A-Za-z0-9_.-]*\.zip)$#', $path, $m)) {
    if (!is_file($file = $server . '/download/' . $m[1])) {
        http_response_code(404);
        echo 'not found';
        return true;
    }
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-store');
    readfile($file);
    return true;
}

require_once $server . '/bootstrap.php';
$db = (string)($conn->query('SELECT DATABASE()')->fetch_row()[0] ?? '');
if (!str_ends_with($db, '_test')) {
    http_response_code(500);
    echo "REFUSING: database '$db' does not end in _test";
    return true;
}

$stub = getenv('PALETTE_DEV_PP_STUB') === '1';
if ($stub) {
    // PayPal REST + Resend. Orders are created for real in palette_orders; capture answers COMPLETED for the
    // stored amount, except for buyers whose address starts with "retry@" (PayPal 503 -> the page's err_retry path)
    // or "pending@" (order COMPLETED, capture PENDING -> the page's confirm path).
    $GLOBALS['pp_http'] = function (string $method, string $url, array $headers, ?string $body) use ($conn): array {
        if (str_ends_with($url, '/v1/oauth2/token')) return ['status' => 200, 'json' => ['access_token' => 'dev']];
        if ($url === 'https://api.resend.com/emails') return ['status' => 200, 'json' => ['id' => 'dev-mail']];
        $p = (string)preg_replace('#^https://api-m\.(sandbox\.)?paypal\.com#', '', $url);
        if ($method === 'POST' && $p === '/v2/checkout/orders') return ['status' => 201, 'json' => ['id' => 'DEV' . strtoupper(bin2hex(random_bytes(6))), 'status' => 'CREATED']];
        if ($method === 'POST' && preg_match('#^/v2/checkout/orders/([A-Za-z0-9-]+)/capture$#', $p, $m)) {
            $o = palette_order_by($conn, 'order_id', $m[1]);
            if (!$o) return ['status' => 404, 'json' => ['name' => 'RESOURCE_NOT_FOUND']];
            if (str_starts_with((string)$o['email'], 'retry@')) return ['status' => 503, 'json' => ['name' => 'SERVICE_UNAVAILABLE']];
            $capStatus = str_starts_with((string)$o['email'], 'pending@') ? 'PENDING' : 'COMPLETED';   // PayPal holding the payment
            return ['status' => 201, 'json' => ['id' => $m[1], 'status' => 'COMPLETED', 'payer' => ['email_address' => $o['email']],
                'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP' . $o['id'], 'status' => $capStatus,
                    'amount' => ['currency_code' => 'USD', 'value' => (string)$o['amount_usd']]]]]]]]];
        }
        return ['status' => 500, 'json' => ['message' => 'dev stub: unrouted ' . $method . ' ' . $p]];
    };
}

if ($stub && $path === '/__dev/seed') {
    $conn->query("CREATE TABLE IF NOT EXISTS admin_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    palette_migrate($conn);
    $conn->query('DELETE FROM palette_orders');
    $conn->query('DELETE FROM palette_licenses');
    $conn->query('DELETE FROM palette_rate');
    $conn->query("DELETE FROM admin_settings WHERE setting_key IN ('palette', 'paypal', 'resend_api_key')");
    $conn->query("INSERT INTO admin_settings (setting_key, setting_value) VALUES ('resend_api_key', 're_dev')");
    pp_settings_put($conn, ['enabled' => ($_GET['paypal'] ?? 'ready') === 'ready', 'mode' => 'sandbox', 'client_id' => 'dev-client', 'secret' => 'dev-secret', 'error' => '']);
    palette_settings_put($conn, ['tax_mode' => ($_GET['tax'] ?? 'none') === 'ca' ? 'ca' : 'none']);
    header('Content-Type: text/plain');
    echo 'seeded';
    return true;
}
if ($stub && $path === '/__dev/login') {   // pretend to be signed in to companies as this user id (0 signs out)
    $_SESSION['user_id'] = (int)($_GET['user_id'] ?? 0);
    if ($_SESSION['user_id'] < 1) unset($_SESSION['user_id']);
    echo 'ok';
    return true;
}
if ($stub && $path === '/__dev/license') {   // issue a key as if bought by ?email=
    echo palette_issue_license($conn, (string)($_GET['email'] ?? 'dev@studio.vn'), 'DEV-' . bin2hex(random_bytes(4)), 'en')['key'];
    return true;
}
if ($path === '/') {
    echo 'ok';
    return true;
}

$routes = ['/palette/' => 'index.php', '/palette/index' => 'index.php', '/palette/terms' => 'terms.php', '/palette/checkout' => 'checkout.php', '/palette/account' => 'account.php', '/palette/privacy' => 'privacy.php', '/palette/api.php' => 'api.php'];
$route = $routes[$path] ?? $routes[preg_replace('#\.php$#', '', $path)] ?? null;
if ($route === null) {
    http_response_code(404);
    echo 'not found';
    return true;
}
require $server . '/' . $route;
return true;
