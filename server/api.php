<?php
// JSON API adapter: superglobals -> palette_api_handle() (lib/api.php) -> HTTP. Logic lives in the handler.
require __DIR__ . '/bootstrap.php';
$post = $_POST;
if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === 0) {   // the extension posts JSON
    $j = json_decode((string)file_get_contents('php://input', false, null, 0, 8192), true);
    $post = is_array($j) ? $j : [];
}
$res = palette_api_handle([
    'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
    'action' => is_string($_GET['action'] ?? null) ? $_GET['action'] : '',
    'post' => $post,
    'origin' => (string)($_SERVER['HTTP_ORIGIN'] ?? ''),
    'ip' => palette_client_ip($_SERVER),   // CF-Connecting-IP only from Cloudflare's edge; never X-Forwarded-For (lib/rate.php)
    'session' => $_SESSION ?? [],
]);
http_response_code($res['status']);
foreach ($res['headers'] as $k => $v) header("$k: $v");
if ($res['status'] !== 204) echo json_encode($res['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (isset($res['defer'])) {   // ruling S4: answer first, then do the work whose duration would reveal whether the address bought
    session_write_close();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    else { while (ob_get_level() > 0) ob_end_flush(); flush(); }
    ($res['defer'])();
}
