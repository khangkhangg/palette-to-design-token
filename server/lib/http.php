<?php
// HTTP with a caller-chosen timeout. companies' pp_http hard-codes 25 s, too long for the AI request budget.
// Same return shape as pp_http: ['status' => int, 'json' => ?array]; status 0 on transport failure.
// A callable $GLOBALS['pp_http'] (the test stub) takes precedence so every existing stub keeps working.
function palette_http(string $method, string $url, array $headers, ?string $body, int $timeoutSec): array
{
    if (isset($GLOBALS['pp_http']) && is_callable($GLOBALS['pp_http'])) return ($GLOBALS['pp_http'])($method, $url, $headers, $body);
    $timeoutSec = max(1, $timeoutSec);
    $ch = curl_init($url);
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeoutSec,
             CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSec), CURLOPT_HTTPHEADER => $headers];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['status' => 0, 'json' => ['message' => $err]];
    return ['status' => $status, 'json' => json_decode((string)$raw, true)];
}
