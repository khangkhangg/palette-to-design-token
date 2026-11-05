<?php
// Palette admin router (spec "Admin pages"): ?tab=licences|payments|sites|settings|licence|issue. Admin session only.
// All logic is in lib/admin.php; this file only turns the request into arguments and sends the answer.
require __DIR__ . '/bootstrap.php';
if (($_SESSION['user_role'] ?? '') === 'admin') palette_admin_csrf_token();   // make sure forms have a token to carry
$res = palette_admin_page($conn, $_SESSION ?? [], $_GET, $_POST, (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
http_response_code($res['status']);
foreach ($res['headers'] as $k => $v) header("$k: $v");
echo $res['body'];
