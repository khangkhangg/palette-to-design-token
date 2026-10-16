<?php
// CLI: php migrate.php  (creates the palette_* tables on the configured DB)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
palette_migrate($conn);
echo "palette tables ready\n";
