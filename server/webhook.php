<?php
// PayPal webhook for Site to Design Token (registered by setup.php).
// Answers 200 with a short status for everything it handled or ignored (so PayPal does not
// retry companies' events forever), 400 only when the signature did not verify.
require __DIR__ . '/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo 'method not allowed';
    exit;
}
$headers = [];
foreach ((function_exists('getallheaders') ? getallheaders() : []) ?: [] as $k => $v) $headers[strtolower((string)$k)] = (string)$v;
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($k, 5)))] ??= (string)$v;
}
$result = palette_webhook($conn, $headers, (string)file_get_contents('php://input'));
http_response_code($result === 'unverified' ? 400 : 200);
echo $result;
