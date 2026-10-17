<?php
// Admin only: the private site list (spec 8) as a table, or ?format=csv. Logic in lib/sites.php.
require __DIR__ . '/bootstrap.php';
$res = palette_admin_sites_page($conn, $_SESSION ?? [], $_GET);
http_response_code($res['status']);
foreach ($res['headers'] as $k => $v) header("$k: $v");
echo $res['body'];
