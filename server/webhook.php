<?php
// PayPal webhook for Site to Design Token (registered by setup.php).
// 200 with a short status for everything handled or ignored (so PayPal does not retry companies'
// events forever); 400 when the signature did not verify; 503 on a transient failure (PayPal
// redelivers); 413 for bodies over 64 KB.
require __DIR__ . '/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo 'method not allowed';
    exit;
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > PALETTE_WEBHOOK_MAX_BYTES) {
    http_response_code(413);
    echo 'too large';
    exit;
}
$headers = [];
foreach ((function_exists('getallheaders') ? getallheaders() : []) ?: [] as $k => $v) $headers[strtolower((string)$k)] = (string)$v;
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($k, 5)))] ??= (string)$v;
}
// Read one byte past the cap so an oversized chunked body is still refused.
$body = (string)file_get_contents('php://input', false, null, 0, PALETTE_WEBHOOK_MAX_BYTES + 1);
[$code, $text] = palette_webhook_response($conn, $headers, $body);
http_response_code($code);
echo $text;
