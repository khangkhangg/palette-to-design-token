<?php
// /palette/directory/*: the public token directory. nginx rewrites every path under it here (DEPLOY.md);
// the logic is in lib/directory.php.
require __DIR__ . '/bootstrap.php';
$res = palette_directory_page($conn, [
    'path' => (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
    'method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    'query' => $_GET, 'post' => $_POST, 'files' => $_FILES, 'session' => $_SESSION ?? [],
    'accept_language' => (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''),
    'ip' => palette_client_ip($_SERVER),
    'body_bytes' => (int)($_SERVER['CONTENT_LENGTH'] ?? 0),
]);
http_response_code($res['status']);
foreach ($res['headers'] as $k => $v) header("$k: $v");
if (isset($res['file'])) readfile($res['file']);
else echo $res['body'];
