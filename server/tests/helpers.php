<?php
// Test harness. The ONLY place that seeds $_ENV: companies' config.php reads a
// .env holding real credentials, which only yields to keys already in $_ENV.
// PALETTE_TEST_DB_NAME exists solely so bootstrap-test can prove the _test guard.
$__name = getenv('PALETTE_TEST_DB_NAME') ?: 'palette_test';
foreach (['DB_HOST_LOCAL' => '127.0.0.1', 'DB_HOST_REMOTE' => '127.0.0.1', 'DB_USERNAME' => 'palette',
          'DB_PASSWORD' => 'palette', 'DB_NAME' => $__name] as $k => $v) {
    $_ENV[$k] = $v;
    putenv("$k=$v");
}
if (!getenv('PALETTE_COMPANIES_DIR')) putenv('PALETTE_COMPANIES_DIR=C:\\Projects\\companies');
if (!defined('PALETTE_NO_MAIL')) define('PALETTE_NO_MAIL', true);

require __DIR__ . '/../bootstrap.php';

$__db = $conn->query('SELECT DATABASE()')->fetch_row()[0] ?? '';
if (!str_ends_with((string)$__db, '_test')) {
    fwrite(STDERR, "REFUSING to run: database '$__db' does not end in _test\n");
    exit(2);
}

$conn->query("CREATE TABLE IF NOT EXISTS admin_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
palette_migrate($conn);

$GLOBALS['__ok'] = 0;
$GLOBALS['__fail'] = 0;
function ok(string $label, bool $cond): void
{
    $GLOBALS[$cond ? '__ok' : '__fail']++;
    echo ($cond ? 'PASS ' : 'FAIL ') . $label . "\n";
}
register_shutdown_function(function () {
    echo "{$GLOBALS['__ok']} passed, {$GLOBALS['__fail']} failed\n";
    if ($GLOBALS['__fail'] > 0) exit(1);
});
