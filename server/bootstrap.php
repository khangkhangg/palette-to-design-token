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
require_once PALETTE_COMPANIES_DIR . '/includes/paypal.php';
// AI gateway helpers (ruling A2). Both files and the hiring-admin.php they require define only constants and
// functions: no output, session, headers or DB access at include time. hiring-admin.php supplies hiring_setting().
require_once PALETTE_COMPANIES_DIR . '/tools/api/ai-usage.php';
require_once PALETTE_COMPANIES_DIR . '/tools/api/ai-gateway.php';

foreach (glob(PALETTE_DIR . '/lib/*.php') ?: [] as $f) require_once $f;
