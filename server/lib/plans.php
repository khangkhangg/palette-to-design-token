<?php
// Plans on licences. $lic needs 'plan', 'revoked_at', 'paid_through' (DATETIME string or null) and, for ai, 'sub_status'.
// Pro on = non-revoked pro licence, or ai licence with paid_through >= now.
// AI on  = ai licence with paid_through >= now, not revoked.
// Ruling A16: while sub_status is 'active' both stay on until paid_through + 48 h, so a renewal PayPal reports late
// never cuts a paying subscriber off; cancelled, suspended, expired (and revoked) licences get no grace.
const PALETTE_RENEWAL_GRACE = 48 * 3600;

function palette_licence_access(array $lic, ?int $now = null): array
{
    $now ??= time();
    if (($lic['revoked_at'] ?? null) !== null) return ['pro' => false, 'ai' => false];
    if (($lic['plan'] ?? 'pro') !== 'ai') return ['pro' => true, 'ai' => false];
    $pt = $lic['paid_through'] ?? null;
    $t = is_string($pt) ? strtotime($pt) : false;
    $grace = ($lic['sub_status'] ?? null) === 'active' ? PALETTE_RENEWAL_GRACE : 0;
    $paid = $t !== false && $t + $grace >= $now;
    return ['pro' => $paid, 'ai' => $paid];
}

// Ruling A19: the moment access ends, as the extension should see it (unix time, null when never paid):
// paid_through + 48 h while sub_status is 'active' (A16 grace), else paid_through.
function palette_access_until(array $lic): ?int
{
    $pt = $lic['paid_through'] ?? null;
    $t = is_string($pt) ? strtotime($pt) : false;
    if ($t === false) return null;
    return $t + (($lic['sub_status'] ?? null) === 'active' ? PALETTE_RENEWAL_GRACE : 0);
}
