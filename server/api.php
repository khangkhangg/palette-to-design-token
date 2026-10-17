<?php
// JSON API adapter: superglobals -> palette_api_handle() (lib/api.php) -> HTTP. Logic lives in the handler.
require __DIR__ . '/bootstrap.php';
$post = $_POST;
$bodyBytes = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === 0) {   // the extension posts JSON
    // One byte past the analyse limit is enough to tell an oversized body (413) from one that fits.
    $raw = (string)file_get_contents('php://input', false, null, 0, PALETTE_ANALYSE_MAX_BYTES + 1);
    $bodyBytes = max($bodyBytes, strlen($raw));
    $j = strlen($raw) > PALETTE_ANALYSE_MAX_BYTES ? null : json_decode($raw, true);
    $post = is_array($j) ? $j : [];
}
$res = palette_api_handle([
    'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
    'action' => is_string($_GET['action'] ?? null) ? $_GET['action'] : '',
    'post' => $post,
    'origin' => (string)($_SERVER['HTTP_ORIGIN'] ?? ''),
    'ip' => palette_client_ip($_SERVER),   // CF-Connecting-IP only from Cloudflare's edge; never X-Forwarded-For (lib/rate.php)
    'session' => $_SESSION ?? [],
    'body_bytes' => $bodyBytes,
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
