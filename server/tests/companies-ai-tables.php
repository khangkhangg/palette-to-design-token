<?php
// Shared by helpers.php (tests) and dev-router.php (local e2e); not a *-test.php, so run.php skips it.
// Tables touched by companies' AI helpers (ai_key_for, ai_log, ai_price, hiring_setting), copied from companies
// migrations 045 (hiring_settings), 048 + 049 (hiring_ai_usage + own_key), 049 + 059 (hiring_ai_models, hiring_user_ai_keys).
function companies_ai_tables(mysqli $c): void
{
    $c->query("CREATE TABLE IF NOT EXISTS hiring_settings (skey VARCHAR(80) PRIMARY KEY, svalue TEXT NULL, updated_by INT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $c->query("CREATE TABLE IF NOT EXISTS hiring_ai_usage (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        feature VARCHAR(40) NOT NULL DEFAULT 'other', provider VARCHAR(30) NOT NULL DEFAULT 'deepseek', model VARCHAR(60) NOT NULL DEFAULT '',
        input_tokens INT NOT NULL DEFAULT 0, output_tokens INT NOT NULL DEFAULT 0, total_tokens INT NOT NULL DEFAULT 0,
        cost_micro BIGINT NOT NULL DEFAULT 0, ms INT NOT NULL DEFAULT 0, ok TINYINT(1) NOT NULL DEFAULT 1,
        error VARCHAR(255) NOT NULL DEFAULT '', ref_type VARCHAR(30) NOT NULL DEFAULT '', ref_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, own_key TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_user_when (user_id, created_at), INDEX idx_feature (feature, created_at), INDEX idx_when (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $c->query("CREATE TABLE IF NOT EXISTS hiring_ai_models (id INT AUTO_INCREMENT PRIMARY KEY, provider VARCHAR(30) NOT NULL,
        model_id VARCHAR(80) NOT NULL, label VARCHAR(80) NOT NULL, kind ENUM('cloud','browser') NOT NULL DEFAULT 'cloud',
        price_in INT NOT NULL DEFAULT 0, price_out INT NOT NULL DEFAULT 0, price_in_off INT NOT NULL DEFAULT 0,
        price_out_off INT NOT NULL DEFAULT 0, offpeak_rule VARCHAR(20) NOT NULL DEFAULT '', size_mb INT NOT NULL DEFAULT 0,
        vram_mb INT NOT NULL DEFAULT 0, tier ENUM('cheap','balanced','best') NOT NULL DEFAULT 'balanced',
        note VARCHAR(200) NOT NULL DEFAULT '', verified_at DATETIME NULL, active TINYINT(1) NOT NULL DEFAULT 1,
        sort INT NOT NULL DEFAULT 100, updated_by INT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_model (provider, model_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $c->query("CREATE TABLE IF NOT EXISTS hiring_user_ai_keys (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        provider VARCHAR(30) NOT NULL, api_key VARCHAR(255) NOT NULL DEFAULT '', model_id VARCHAR(80) NOT NULL DEFAULT '',
        active TINYINT(1) NOT NULL DEFAULT 1, last_ok_at DATETIME NULL, last_error VARCHAR(255) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_provider (user_id, provider)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
