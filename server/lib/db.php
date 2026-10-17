<?php
const PALETTE_SETTINGS_KEY = 'palette';

function palette_defaults(): array
{
    return ['price_usd' => '1.49', 'tax_mode' => 'none', 'tax_rate' => '0.0725',
            'extension_ids' => [], 'webhook_id' => '', 'webhook_mode' => '',
            // AI insights add-on. search_key is a secret: validated at use, never returned by any API action.
            'ai_price_usd' => '3.99', 'ai_monthly_analyses' => 100, 'ai_monthly_token_cap' => 600000,
            'search_provider' => 'brave', 'search_key' => '', 'ai_paypal_plan_id' => '', 'ai_paypal_plan_mode' => ''];
}

function palette_migrate(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS palette_orders (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id    VARCHAR(40) NOT NULL,
        email       VARCHAR(190) NOT NULL,
        lang        VARCHAR(8) NOT NULL,
        country     CHAR(2) NULL,
        region      VARCHAR(8) NULL,
        amount_usd  DECIMAL(10,2) NOT NULL,
        tax_usd     DECIMAL(10,2) NOT NULL DEFAULT 0,
        status      ENUM('created','captured','failed') NOT NULL DEFAULT 'created',
        capture_id  VARCHAR(40) NULL,
        consent_at  DATETIME NOT NULL,
        created_at  DATETIME NOT NULL,
        captured_at DATETIME NULL,
        license_id  INT NULL,
        UNIQUE KEY order_id (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS palette_licenses (
        id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        license_key      CHAR(18) NOT NULL,
        email            VARCHAR(190) NOT NULL,
        user_id          INT NULL,
        order_id         VARCHAR(40) NOT NULL,
        lang             VARCHAR(8) NOT NULL,
        created_at       DATETIME NOT NULL,
        revoked_at       DATETIME NULL,
        last_verified_at DATETIME NULL,
        UNIQUE KEY license_key (license_key),
        KEY email (email),
        KEY user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Palette's own rate limiter (lib/rate.php, ruling S8): fixed windows, one row per bucket and window.
    $conn->query("CREATE TABLE IF NOT EXISTS palette_rate (
        bucket       VARCHAR(120) NOT NULL,
        window_start INT NOT NULL,
        count        INT NOT NULL,
        PRIMARY KEY (bucket, window_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    palette_add_column($conn, 'palette_licenses', 'plan', "ENUM('pro','ai') NOT NULL DEFAULT 'pro'");
    palette_add_column($conn, 'palette_licenses', 'subscription_id', 'VARCHAR(40) NULL');
    palette_add_column($conn, 'palette_licenses', 'sub_status', "ENUM('active','cancelled','suspended','expired') NULL");
    palette_add_column($conn, 'palette_licenses', 'paid_through', 'DATETIME NULL');
    palette_add_column($conn, 'palette_licenses', 'period_start', 'DATETIME NULL');
    $conn->query("CREATE TABLE IF NOT EXISTS palette_subscriptions (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        subscription_id VARCHAR(40) NULL,
        email           VARCHAR(190) NOT NULL,
        lang            VARCHAR(8) NOT NULL,
        status          ENUM('pending','active','cancelled','suspended','expired') NOT NULL DEFAULT 'pending',
        plan_id         VARCHAR(40) NOT NULL,
        license_id      INT NULL,
        consent_at      DATETIME NOT NULL,
        created_at      DATETIME NOT NULL,
        updated_at      DATETIME NOT NULL,
        UNIQUE KEY subscription_id (subscription_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS palette_sub_payments (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        subscription_id VARCHAR(40) NOT NULL,
        sale_id         VARCHAR(40) NOT NULL,
        amount_usd      DECIMAL(10,2) NOT NULL,
        status          ENUM('completed','refunded','reversed') NOT NULL,
        paid_at         DATETIME NOT NULL,
        UNIQUE KEY sale_id (sale_id),
        KEY subscription_id (subscription_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS palette_ai_usage (
        license_id   INT NOT NULL,
        period_start DATETIME NOT NULL,
        analyses     INT NOT NULL DEFAULT 0,
        tokens_in    INT NOT NULL DEFAULT 0,
        tokens_out   INT NOT NULL DEFAULT 0,
        PRIMARY KEY (license_id, period_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS palette_ai_cache (
        host        VARCHAR(190) NOT NULL,
        lang        VARCHAR(8) NOT NULL,
        colour_hash CHAR(40) NOT NULL,
        result_json MEDIUMTEXT NOT NULL,
        created_at  DATETIME NOT NULL,
        PRIMARY KEY (host, lang, colour_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Private site list (spec 8): aggregate counts only, no user identifiers.
    $conn->query("CREATE TABLE IF NOT EXISTS palette_sites (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        domain          VARCHAR(190) NOT NULL,
        name            VARCHAR(190) NULL,
        title           VARCHAR(255) NULL,
        sector          VARCHAR(100) NULL,
        tags_json       TEXT NULL,
        country         CHAR(2) NULL,
        colours_json    TEXT NULL,
        fonts_json      TEXT NULL,
        first_seen      DATETIME NOT NULL,
        last_seen       DATETIME NOT NULL,
        analyses_count  INT NOT NULL DEFAULT 0,
        suggested_count INT NOT NULL DEFAULT 0,
        UNIQUE KEY domain (domain)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS palette_site_links (
        site_id         INT UNSIGNED NOT NULL,
        related_site_id INT UNSIGNED NOT NULL,
        relation        ENUM('competitor','similar') NOT NULL,
        source          ENUM('web','ai') NOT NULL,
        seen_count      INT NOT NULL DEFAULT 1,
        PRIMARY KEY (site_id, related_site_id, relation)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Idempotent ALTER: adds the column only when INFORMATION_SCHEMA says it is missing.
function palette_add_column(mysqli $conn, string $table, string $column, string $definition): void
{
    $st = $conn->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->bind_param('ss', $table, $column);
    $st->execute();
    $exists = $st->get_result()->num_rows > 0;
    $st->close();
    if (!$exists) $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}

function palette_settings(mysqli $conn): array
{
    $d = palette_defaults();
    $raw = getAdminSetting(PALETTE_SETTINGS_KEY, null);
    $s = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($s) ? array_merge($d, array_intersect_key($s, $d)) : $d;
}

function palette_settings_put(mysqli $conn, array $s): void
{
    $d = palette_defaults();
    $json = json_encode(array_merge($d, array_intersect_key($s, $d)));
    $k = PALETTE_SETTINGS_KEY;
    $st = $conn->prepare("INSERT INTO admin_settings (setting_key, setting_value) VALUES (?, ?)
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $st->bind_param('ss', $k, $json);
    $st->execute();
    $st->close();
}
