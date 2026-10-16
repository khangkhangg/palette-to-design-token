<?php
// Palette server add-on bootstrap. Deployed at <companies>/palette/; only
// requires companies files, never modifies them. See README.md.
if (!defined('PALETTE_DIR')) define('PALETTE_DIR', __DIR__);
if (!defined('PALETTE_COMPANIES_DIR')) {
    define('PALETTE_COMPANIES_DIR', rtrim((string)(getenv('PALETTE_COMPANIES_DIR') ?: dirname(__DIR__)), '/\\'));
}
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) session_start();

require_once PALETTE_COMPANIES_DIR . '/config.php';
require_once PALETTE_COMPANIES_DIR . '/includes/functions.php';
require_once PALETTE_COMPANIES_DIR . '/includes/ip_rate_limit.php';
require_once PALETTE_COMPANIES_DIR . '/includes/paypal.php';

foreach (glob(PALETTE_DIR . '/lib/*.php') ?: [] as $f) require_once $f;
