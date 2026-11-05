<?php
// Stripe webhook for Site to Design Token (registered by admin › Settings › Connect, live and test share it).
// 200 with a short status for everything handled or ignored (so Stripe does not retry the companies site's events
// forever); 400 when the body or signature is bad; 503 on a transient failure (Stripe redelivers); 413 over 64 KB.
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
// Read one byte past the cap so an oversized chunked body is still refused.
$body = (string)file_get_contents('php://input', false, null, 0, PALETTE_WEBHOOK_MAX_BYTES + 1);
[$code, $text] = palette_st_webhook_response($conn, $body, (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
http_response_code($code);
echo $text;
