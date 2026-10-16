<?php
const PALETTE_SETTINGS_KEY = 'palette';

function palette_defaults(): array
{
    return ['price_usd' => '1.49', 'tax_mode' => 'none', 'tax_rate' => '0.0725',
            'extension_ids' => [], 'webhook_id' => '', 'webhook_mode' => ''];
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
