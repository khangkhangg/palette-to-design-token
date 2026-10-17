<?php
const PALETTE_KEY_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
const PALETTE_KEY_RE = '/^SDT-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/';

function palette_new_key(): string
{
    $n = unpack('J', random_bytes(8))[1] & ((1 << 60) - 1);
    $s = '';
    for ($i = 0; $i < 12; $i++) {
        $s = PALETTE_KEY_ALPHABET[$n & 31] . $s;
        $n >>= 5;
    }
    return 'SDT-' . substr($s, 0, 4) . '-' . substr($s, 4, 4) . '-' . substr($s, 8, 4);
}

function palette_normalise_key(string $raw): ?string
{
    $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
    if (strlen($s) !== 15 || !str_starts_with($s, 'SDT')) return null;
    $body = strtr(substr($s, 3), ['O' => '0', 'I' => '1', 'L' => '1']);
    $key = 'SDT-' . substr($body, 0, 4) . '-' . substr($body, 4, 4) . '-' . substr($body, 8, 4);
    return preg_match(PALETTE_KEY_RE, $key) ? $key : null;
}

function palette_mask_email(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false) return '***';
    $local = substr($email, 0, $at);
    return (mb_strlen($local) <= 1 ? '*' : mb_substr($local, 0, 1) . '***') . substr($email, $at);
}

// $ai = null issues a one-time Pro key. For a subscription pass ['subscription_id', 'paid_through', 'period_start']
// (PHP-time DATETIME strings, ruling A4): the key gets plan 'ai' and sub_status 'active'.
function palette_issue_license(mysqli $c, string $email, string $orderId, string $lang, ?array $ai = null): array
{
    $email = strtolower(trim($email));
    $lang = palette_lang($lang);
    $plan = $ai === null ? 'pro' : 'ai';
    $subId = $ai === null ? null : (string)$ai['subscription_id'];
    $status = $ai === null ? null : 'active';
    $paid = $ai === null ? null : (string)$ai['paid_through'];
    $start = $ai === null ? null : (string)$ai['period_start'];
    for ($try = 0; $try < 10; $try++) {
        $key = palette_new_key();
        $st = $c->prepare('INSERT INTO palette_licenses (license_key, email, order_id, lang, created_at, plan, subscription_id, sub_status, paid_through, period_start)
                           VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)');
        $st->bind_param('sssssssss', $key, $email, $orderId, $lang, $plan, $subId, $status, $paid, $start);
        try {
            $st->execute();
            $id = (int)$c->insert_id;
            $st->close();
            return ['id' => $id, 'key' => $key];
        } catch (mysqli_sql_exception $e) {
            $st->close();
            if ($e->getCode() !== 1062) throw $e;
        }
    }
    throw new RuntimeException('could not generate a unique license key');
}

function palette_verify(mysqli $c, string $rawKey, string $ip): array
{
    if (!palette_rate_hit('verify:ip:' . $ip, 30, 60)) return ['error' => 'rate_limited'];
    $none = ['valid' => false, 'revoked' => false, 'email_masked' => null];
    $key = palette_normalise_key($rawKey);
    if ($key === null) return $none;
    $st = $c->prepare('SELECT id, email, revoked_at, plan, sub_status, paid_through, period_start, created_at FROM palette_licenses WHERE license_key = ?');
    $st->bind_param('s', $key);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) return $none;
    $masked = palette_mask_email($row['email']);
    if ($row['revoked_at'] !== null) return ['valid' => false, 'revoked' => true, 'email_masked' => $masked];
    $id = (int)$row['id'];
    $c->query("UPDATE palette_licenses SET last_verified_at = NOW() WHERE id = $id");
    $out = ['valid' => true, 'revoked' => false, 'email_masked' => $masked];
    if ($row['plan'] === 'ai') {
        // Pro licences keep the original three-field answer; AI licences add their plan state.
        $acc = palette_licence_access($row);
        $out['valid'] = $acc['pro'];
        $out['plan'] = 'ai';
        $out['ai_active'] = $acc['ai'];
        $out['sub_status'] = $row['sub_status'];
        $out['paid_through'] = $row['paid_through'] === null ? null : date('c', strtotime($row['paid_through']));
        $until = palette_access_until($row);
        $out['access_until'] = $until === null ? null : date('c', $until);
        $u = palette_usage_get($c, $row, palette_settings($c));
        $out['usage'] = ['used' => $u['used'], 'limit' => $u['limit'], 'resets_on' => $u['resets_on']];
    }
    return $out;
}

// Always returns normally; callers answer the same message whether or not anything was sent.
function palette_resend(mysqli $c, string $email, string $ip): void
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    if (!palette_rate_hit('resend:ip:' . $ip, 10, 3600)) return;
    if (!palette_rate_hit('resend:email:' . sha1($email), 3, 3600)) return;
    $st = $c->prepare('SELECT license_key, lang FROM palette_licenses WHERE email = ? AND revoked_at IS NULL ORDER BY id');
    $st->bind_param('s', $email);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    if (!$rows) return;
    $m = palette_resend_email(array_column($rows, 'license_key'), (string)end($rows)['lang']);
    palette_mail($email, $m['subject'], $m['html']);
}

function palette_claim(mysqli $c, string $rawKey, int $userId): bool
{
    $key = palette_normalise_key($rawKey);
    if ($key === null) return false;
    $st = $c->prepare('UPDATE palette_licenses SET user_id = ? WHERE license_key = ? AND user_id IS NULL AND revoked_at IS NULL');
    $st->bind_param('is', $userId, $key);
    $st->execute();
    $n = $st->affected_rows;
    $st->close();
    if ($n === 1) return true;
    // Idempotent for the current owner.
    $st = $c->prepare('SELECT 1 FROM palette_licenses WHERE license_key = ? AND user_id = ? AND revoked_at IS NULL');
    $st->bind_param('si', $key, $userId);
    $st->execute();
    $owned = $st->get_result()->num_rows === 1;
    $st->close();
    return $owned;
}

function palette_keys_for_user(mysqli $c, int $userId): array
{
    $st = $c->prepare('SELECT license_key, email, created_at, revoked_at, plan, sub_status, paid_through FROM palette_licenses WHERE user_id = ? ORDER BY id');
    $st->bind_param('i', $userId);
    $st->execute();
    $out = [];
    foreach ($st->get_result() as $r) {
        $out[] = ['key' => $r['license_key'], 'email_masked' => palette_mask_email($r['email']),
                  'created_at' => $r['created_at'], 'revoked' => $r['revoked_at'] !== null,
                  'plan' => $r['plan'] === 'ai' ? 'ai' : 'pro', 'sub_status' => $r['sub_status'], 'paid_through' => $r['paid_through']];
    }
    $st->close();
    return $out;
}

function palette_revoke_by_order(mysqli $c, string $orderId): void
{
    $st = $c->prepare('UPDATE palette_licenses SET revoked_at = NOW() WHERE order_id = ? AND revoked_at IS NULL');
    $st->bind_param('s', $orderId);
    $st->execute();
    $st->close();
}
