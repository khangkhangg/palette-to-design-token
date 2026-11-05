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
    // A test-mode (Stripe test key) licence is a trial of the flow: it stops working 7 days after it was made.
    $testEnd = palette_test_expiry($lic);
    if ($testEnd !== null && $testEnd < $now) return ['pro' => false, 'ai' => false];
    if (($lic['plan'] ?? 'pro') !== 'ai') return ['pro' => true, 'ai' => false];
    $pt = $lic['paid_through'] ?? null;
    $t = is_string($pt) ? strtotime($pt) : false;
    $grace = ($lic['sub_status'] ?? null) === 'active' ? PALETTE_RENEWAL_GRACE : 0;
    $paid = $t !== false && $t + $grace >= $now;
    return ['pro' => $paid, 'ai' => $paid];
}

// Ruling A19: the moment access ends, as the extension should see it (unix time, null when never paid):
// paid_through + 48 h while sub_status is 'active' (A16 grace), else paid_through. A test-mode licence (livemode 0 with
// created_at given) never runs past its 7-day test expiry.
function palette_access_until(array $lic): ?int
{
    $pt = $lic['paid_through'] ?? null;
    $t = is_string($pt) ? strtotime($pt) : false;
    if ($t === false) return null;
    $until = $t + (($lic['sub_status'] ?? null) === 'active' ? PALETTE_RENEWAL_GRACE : 0);
    $testEnd = palette_test_expiry($lic);
    return $testEnd === null ? $until : min($until, $testEnd);
}

// When a test-mode licence stops working (created_at + 7 days), null for live licences or an unknown created_at.
function palette_test_expiry(array $lic): ?int
{
    if ((int)($lic['livemode'] ?? 1) !== 0) return null;
    $made = is_string($lic['created_at'] ?? null) ? strtotime($lic['created_at']) : false;
    return $made === false ? null : $made + 7 * 86400;
}
