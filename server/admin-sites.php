<?php
// Moved: the site list is the Sites tab of the admin (admin.php). Old bookmarks and the CSV link keep working.
$target = 'admin.php?tab=sites' . (($_GET['format'] ?? '') === 'csv' ? '&format=csv' : '');
http_response_code(301);
header('Location: ' . $target);
header('Cache-Control: no-store');
