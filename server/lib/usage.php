<?php
// AI usage metering. One row per (licence, period_start); period_start = licence.period_start ?? created_at.
// Times are PHP-side (ruling A4). $lic needs 'id' and 'period_start' and/or 'created_at'.
function palette_usage_period(array $lic): string
{
    return (string)($lic['period_start'] ?? $lic['created_at']);
}

function palette_usage_limit(array $settings): int
{
    $v = $settings['ai_monthly_analyses'] ?? null;
    return is_numeric($v) && (int)$v > 0 ? (int)$v : 100;
}

function palette_usage_token_cap(array $settings): int
{
    $v = $settings['ai_monthly_token_cap'] ?? null;
    return is_numeric($v) && (int)$v > 0 ? (int)$v : 600000;
}

function palette_usage_ensure_row(mysqli $c, int $id, string $period): void
{
    $st = $c->prepare('INSERT IGNORE INTO palette_ai_usage (license_id, period_start) VALUES (?, ?)');
    $st->bind_param('is', $id, $period);
    $st->execute();
    $st->close();
}

function palette_usage_get(mysqli $c, array $lic, array $settings): array
{
    $id = (int)$lic['id'];
    $period = palette_usage_period($lic);
    $st = $c->prepare('SELECT analyses, tokens_in, tokens_out FROM palette_ai_usage WHERE license_id = ? AND period_start = ?');
    $st->bind_param('is', $id, $period);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    $pt = $lic['paid_through'] ?? null;
    $t = is_string($pt) ? strtotime($pt) : false;
    return [
        'used' => (int)($row['analyses'] ?? 0),
        'limit' => palette_usage_limit($settings),
        'tokens' => (int)($row['tokens_in'] ?? 0) + (int)($row['tokens_out'] ?? 0),
        'token_cap' => palette_usage_token_cap($settings),
        'resets_on' => $t === false ? null : date('Y-m-d', $t),
    ];
}

// Atomic: a single conditional UPDATE decides, so concurrent callers cannot exceed the limit.
function palette_usage_reserve(mysqli $c, array $lic, array $settings): bool
{
    $id = (int)$lic['id'];
    $period = palette_usage_period($lic);
    $limit = palette_usage_limit($settings);
    $cap = palette_usage_token_cap($settings);
    palette_usage_ensure_row($c, $id, $period);
    $st = $c->prepare('UPDATE palette_ai_usage SET analyses = analyses + 1
                       WHERE license_id = ? AND period_start = ? AND analyses < ? AND (tokens_in + tokens_out) < ?');
    $st->bind_param('isii', $id, $period, $limit, $cap);
    $st->execute();
    $ok = $st->affected_rows === 1;
    $st->close();
    return $ok;
}

function palette_usage_release(mysqli $c, array $lic): void
{
    $id = (int)$lic['id'];
    $period = palette_usage_period($lic);
    $st = $c->prepare('UPDATE palette_ai_usage SET analyses = GREATEST(analyses - 1, 0) WHERE license_id = ? AND period_start = ?');
    $st->bind_param('is', $id, $period);
    $st->execute();
    $st->close();
}

function palette_usage_tokens(mysqli $c, array $lic, int $in, int $out): void
{
    $id = (int)$lic['id'];
    $period = palette_usage_period($lic);
    $in = max(0, $in);
    $out = max(0, $out);
    palette_usage_ensure_row($c, $id, $period);
    $st = $c->prepare('UPDATE palette_ai_usage SET tokens_in = tokens_in + ?, tokens_out = tokens_out + ? WHERE license_id = ? AND period_start = ?');
    $st->bind_param('iiis', $in, $out, $id, $period);
    $st->execute();
    $st->close();
}
