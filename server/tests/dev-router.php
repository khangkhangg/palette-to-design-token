<?php
// Router for `php -S 127.0.0.1:<port> server/tests/dev-router.php` (checkout e2e and local preview only; never deployed).
// Seeds the test database into $_ENV BEFORE companies' config.php loads (its .env holds real credentials, and under
// the cli-server SAPI it reads DB_HOST_REMOTE), refuses to serve unless the database name ends in _test, and maps
// /palette/* onto server/*.
// PALETTE_DEV_PP_STUB=1 (honoured only on a *_test database) replaces PayPal REST and Resend with a local stub and
// enables /__dev/seed?paypal=ready|paused&tax=none|ca&ai=ready|off (resets the palette tables; ai=ready, the default,
// stores a Pro + AI plan id for the sandbox), /__dev/login?user_id=N and /__dev/license?email=… for the e2e.
// Subscriptions: the e2e's fake SDK answers subscription ids "I-DEVSUB<row id>" (the row action=subscribe_start made);
// GET /v1/billing/subscriptions/I-DEVSUB<n> answers that row ACTIVE with a first payment at its price, except for
// buyers whose address starts with "pending@" (ACTIVE, no payment yet -> the page's pending message) or "retry@"
// (PayPal 503 -> err_retry). Any subscription's cancel answers 204.
// PALETTE_DEV_AI_STUB=1 (also only on a *_test database) answers web search (Brave/Tavily) and the AI model locally so
// action=analyse runs end to end: canned search results (a query containing "dev-search-down" gets HTTP 500), and a
// canned valid answer that uses the first two sent hexes (a title containing "dev-ai-fail" gets HTTP 503). With it,
// /__dev/seed also stores a dev platform AI key and search key, clears the AI tables and takes ?ext=<extension id>,
// /__dev/license takes ?plan=ai (paid 30 days ahead; &ended=1 for one that lapsed yesterday) and /__dev/login takes
// &role=admin (for /palette/admin-sites). Combine with PALETTE_DEV_PP_STUB=1 for PayPal; without it PayPal and Resend
// calls get a stub 500, never the network.
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
        if ($method === 'GET' && preg_match('#^/v1/billing/subscriptions/(I-DEVSUB(\d+))$#', $p, $m)) {
            $row = palette_sub_row($conn, 'id', (int)$m[2]);
            if (!$row) return ['status' => 404, 'json' => ['name' => 'RESOURCE_NOT_FOUND']];
            if (str_starts_with((string)$row['email'], 'retry@')) return ['status' => 503, 'json' => ['name' => 'SERVICE_UNAVAILABLE']];
            $sub = ['id' => $m[1], 'status' => 'ACTIVE', 'plan_id' => $row['plan_id'], 'custom_id' => 'palette-sub:' . $row['id'],
                'billing_info' => ['next_billing_time' => gmdate('Y-m-d\TH:i:s\Z', time() + 30 * 86400)]];
            if (!str_starts_with((string)$row['email'], 'pending@')) {
                $sub['billing_info']['last_payment'] = ['amount' => ['currency_code' => 'USD', 'value' => (string)$row['price_usd']], 'time' => gmdate('Y-m-d\TH:i:s\Z')];
            }
            return ['status' => 200, 'json' => $sub];
        }
        if ($method === 'POST' && preg_match('#^/v1/billing/subscriptions/I-[A-Z0-9]+/cancel$#', $p)) return ['status' => 204, 'json' => null];
        return ['status' => 500, 'json' => ['message' => 'dev stub: unrouted ' . $method . ' ' . $p]];
    };
}

$aiStub = getenv('PALETTE_DEV_AI_STUB') === '1';
if ($aiStub) {
    require_once __DIR__ . '/companies-ai-tables.php';
    $inner = $GLOBALS['pp_http'] ?? null;
    $GLOBALS['pp_http'] = function (string $method, string $url, array $headers, ?string $body) use ($inner): array {
        $isBrave = str_starts_with($url, 'https://api.search.brave.com/');
        if ($isBrave || $url === 'https://api.tavily.com/search') {
            parse_str((string)parse_url($url, PHP_URL_QUERY), $qs);
            $q = $isBrave ? (string)($qs['q'] ?? '') : (string)(json_decode((string)$body, true)['query'] ?? '');
            if (str_contains($q, 'dev-search-down')) return ['status' => 500, 'json' => ['message' => 'dev stub: search down']];
            $rows = [['title' => 'Northwind Roasters', 'url' => 'https://northwind.example/', 'snippet' => 'Small-batch coffee, shipped weekly.'],
                     ['title' => 'Harbor Coffee Co.', 'url' => 'https://harbor-coffee.example/shop', 'snippet' => 'Single-origin beans and gear.'],
                     ['title' => 'Kettle & Pour', 'url' => 'https://kettleandpour.example/', 'snippet' => 'Brewing guides and equipment.']];
            return $isBrave
                ? ['status' => 200, 'json' => ['web' => ['results' => array_map(fn($r) => ['title' => $r['title'], 'url' => $r['url'], 'description' => $r['snippet']], $rows)]]]
                : ['status' => 200, 'json' => ['results' => array_map(fn($r) => ['title' => $r['title'], 'url' => $r['url'], 'content' => $r['snippet']], $rows)]];
        }
        if ($inner !== null) return $inner($method, $url, $headers, $body);
        return ['status' => 500, 'json' => ['message' => 'dev AI stub: unrouted ' . $method . ' ' . $url]];
    };
    $GLOBALS['palette_ai_transport'] = function (array $req, int $timeout): array {
        $msgs = $req['body']['messages'] ?? [];
        $system = (string)($msgs[0]['content'] ?? '');
        $user = (string)($msgs[1]['content'] ?? '');
        $usage = ['prompt_tokens' => 2400, 'completion_tokens' => 600, 'total_tokens' => 3000];
        if (str_contains($user, 'dev-ai-fail')) return [json_encode(['error' => ['message' => 'dev stub: AI down']]), 503, '', 5];
        preg_match_all('/#[0-9a-f]{6}\b|#[0-9a-f]{3}\b/i', $system, $m);
        $hexes = array_slice(array_values(array_unique($m[0])), 0, 2);
        $titles = ['Deep tone: grounded, trustworthy', 'Light tone: calm, open'];
        $answer = ['sector' => 'Specialty coffee', 'tags' => ['Online shop', 'Premium, craft'], 'verdict' => 'on_par',
            'summary' => 'A clean, warm storefront that reads as a craft roaster. The palette is restrained and the type is legible.',
            'colours' => array_map(fn($h, $i) => ['hex' => $h, 'title' => $titles[$i], 'meaning' => 'Sets the tone for the brand.'], $hexes, array_keys($hexes)),
            'fit' => ['level' => 'strong', 'reason' => 'The colours match what buyers expect from a craft coffee brand.'],
            'works' => ['Consistent palette', 'Readable body text'], 'improve' => ['Raise contrast on muted labels'],
            'sites' => [['name' => 'Northwind Roasters', 'domain' => 'northwind.example', 'why' => 'Same market, similar tone', 'relation' => 'competitor'],
                        ['name' => 'Harbor Coffee Co.', 'domain' => 'harbor-coffee.example', 'why' => 'Direct competitor online', 'relation' => 'competitor'],
                        ['name' => 'Brew Lab', 'domain' => 'brewlab.example', 'why' => 'Similar visual style', 'relation' => 'similar']]];
        return [json_encode(['model' => 'deepseek-flash', 'choices' => [['message' => ['content' => json_encode($answer)], 'finish_reason' => 'stop']],
                             'usage' => $usage]), 200, '', 40];
    };
}
$dev = $stub || $aiStub;

if ($dev && $path === '/__dev/seed') {
    $conn->query("CREATE TABLE IF NOT EXISTS admin_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    palette_migrate($conn);
    $conn->query('DELETE FROM palette_orders');
    $conn->query('DELETE FROM palette_licenses');
    $conn->query('DELETE FROM palette_subscriptions');
    $conn->query('DELETE FROM palette_sub_payments');
    $conn->query('DELETE FROM palette_rate');
    $conn->query("DELETE FROM admin_settings WHERE setting_key IN ('palette', 'paypal', 'resend_api_key')");
    $conn->query("INSERT INTO admin_settings (setting_key, setting_value) VALUES ('resend_api_key', 're_dev')");
    pp_settings_put($conn, ['enabled' => ($_GET['paypal'] ?? 'ready') === 'ready', 'mode' => 'sandbox', 'client_id' => 'dev-client', 'secret' => 'dev-secret', 'error' => '']);
    $settings = ['tax_mode' => ($_GET['tax'] ?? 'none') === 'ca' ? 'ca' : 'none'];
    if (($_GET['ai'] ?? 'ready') === 'ready') $settings += ['ai_paypal_plan_id' => 'P-DEVPLAN001', 'ai_paypal_plan_mode' => 'sandbox', 'ai_paypal_plan_price' => '3.99'];
    if ($aiStub) {
        foreach (['palette_ai_usage', 'palette_ai_cache', 'palette_sites', 'palette_site_links'] as $t) $conn->query("DELETE FROM $t");
        companies_ai_tables($conn);
        $conn->query("DELETE FROM hiring_settings WHERE skey IN ('deepseek_key', 'ai_enabled')");
        $conn->query("INSERT INTO hiring_settings (skey, svalue) VALUES ('deepseek_key', 'sk-dev')");
        $settings += ['search_provider' => 'brave', 'search_key' => 'dev-search'];
        $ext = (string)($_GET['ext'] ?? '');
        if (preg_match('/^[a-p]{32}$/', $ext)) $settings['extension_ids'] = [$ext];
    }
    palette_settings_put($conn, $settings);
    header('Content-Type: text/plain');
    echo 'seeded';
    return true;
}
if ($dev && $path === '/__dev/login') {   // pretend to be signed in to companies as this user id (0 signs out)
    $_SESSION['user_id'] = (int)($_GET['user_id'] ?? 0);
    unset($_SESSION['user_role']);
    if ($_SESSION['user_id'] < 1) unset($_SESSION['user_id']);
    elseif ($aiStub && ($_GET['role'] ?? '') === 'admin') $_SESSION['user_role'] = 'admin';
    echo 'ok';
    return true;
}
if ($dev && $path === '/__dev/license') {   // issue a key as if bought by ?email=
    $lic = palette_issue_license($conn, (string)($_GET['email'] ?? 'dev@studio.vn'), 'DEV-' . bin2hex(random_bytes(4)), 'en');
    if (($_GET['plan'] ?? '') === 'ai') {
        // A subscription licence with its palette_subscriptions row, so the account page can show and cancel it.
        $paid = date('Y-m-d H:i:s', ($_GET['ended'] ?? '') === '1' ? time() - 86400 : time() + 30 * 86400);
        $start = date('Y-m-d H:i:s', time() - 60);
        $sid = 'I-DEVLIC' . $lic['id'];
        $st = $conn->prepare("UPDATE palette_licenses SET plan = 'ai', sub_status = 'active', paid_through = ?, period_start = ?, subscription_id = ? WHERE id = ?");
        $st->bind_param('sssi', $paid, $start, $sid, $lic['id']);
        $st->execute();
        $st->close();
        $email = strtolower((string)($_GET['email'] ?? 'dev@studio.vn'));
        $now = date('Y-m-d H:i:s');
        $st = $conn->prepare("INSERT INTO palette_subscriptions (subscription_id, email, lang, status, plan_id, license_id, price_usd, consent_at, created_at, updated_at)
                              VALUES (?, ?, 'en', 'active', 'P-DEVPLAN001', ?, '3.99', ?, ?, ?)");
        $st->bind_param('ssisss', $sid, $email, $lic['id'], $now, $now, $now);
        $st->execute();
        $st->close();
    }
    echo $lic['key'];
    return true;
}
if ($path === '/') {
    echo 'ok';
    return true;
}

$routes = ['/palette/' => 'index.php', '/palette/index' => 'index.php', '/palette/terms' => 'terms.php', '/palette/checkout' => 'checkout.php', '/palette/account' => 'account.php', '/palette/privacy' => 'privacy.php', '/palette/api.php' => 'api.php', '/palette/admin-sites' => 'admin-sites.php'];
$route = $routes[$path] ?? $routes[preg_replace('#\.php$#', '', $path)] ?? null;
if ($route === null) {
    http_response_code(404);
    echo 'not found';
    return true;
}
require $server . '/' . $route;
return true;
