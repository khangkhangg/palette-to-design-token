<?php
// Plans on licences. $lic needs 'plan', 'revoked_at' and 'paid_through' (DATETIME string or null).
// Pro on = non-revoked pro licence, or ai licence with paid_through >= now.
// AI on  = ai licence with paid_through >= now, not revoked.
function palette_licence_access(array $lic, ?int $now = null): array
{
    $now ??= time();
    if (($lic['revoked_at'] ?? null) !== null) return ['pro' => false, 'ai' => false];
    if (($lic['plan'] ?? 'pro') !== 'ai') return ['pro' => true, 'ai' => false];
    $pt = $lic['paid_through'] ?? null;
    $paid = is_string($pt) && ($t = strtotime($pt)) !== false && $t >= $now;
    return ['pro' => $paid, 'ai' => $paid];
}
