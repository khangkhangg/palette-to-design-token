<?php
// AI insights: the analyse request end to end (spec 5.2). Per-IP limit -> input whitelist + scrub (ruling A9) -> licence
// (one row per request, ruling A5) -> per-licence limits -> 7-day result cache -> reserve one analysis -> 2 web searches -> one AI call -> validate -> record.
// A cache hit, a failed call and an invalid answer cost no analysis; tokens are recorded for every call.

const PALETTE_ANALYSE_MAX_BYTES = 16384;
const PALETTE_ANALYSE_CACHE_TTL = 7 * 86400;
const PALETTE_ANALYSE_PROMPT_RESULTS = 12;
const PALETTE_ANALYSE_PATH_MAX = 300;

// ['status' => int, 'body' => array]. $req: key, url, title, description, design, lang, ip.
function palette_analyse(mysqli $c, array $req): array
{
    $lang = palette_lang(is_string($req['lang'] ?? null) ? $req['lang'] : '');
    $ip = is_string($req['ip'] ?? null) ? $req['ip'] : '';

    // Per-IP load protection first, so bad input and invalid keys are limited too.
    if (!palette_rate_hit('ai:ip:' . $ip, 20, 3600)) return palette_analyse_error(429, 'api_rate_limited', $lang);

    // 1. Input: only the whitelisted fields go any further. Title and description are scrubbed of personal data
    // (ruling A9) before they reach the prompt, the shared cache or the site list.
    $u = palette_analyse_url($req['url'] ?? null);
    $key = palette_normalise_key(is_string($req['key'] ?? null) ? $req['key'] : '');
    if ($u === null || $key === null || !is_array($req['design'] ?? null)) return palette_analyse_error(400, 'err_bad_request', $lang);
    $design = palette_analyse_design($req['design']);
    $page = ['url' => $u['url'], 'title' => palette_scrub_text($req['title'] ?? '', 200),
             'description' => palette_scrub_text($req['description'] ?? '', 300)];
    $host = $u['host'];

    // 2. Licence, loaded once and passed to every usage call (ruling A5).
    $st = $c->prepare('SELECT id, plan, revoked_at, paid_through, period_start, created_at FROM palette_licenses WHERE license_key = ?');
    $st->bind_param('s', $key);
    $st->execute();
    $lic = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$lic || $lic['plan'] !== 'ai' || $lic['revoked_at'] !== null) return palette_analyse_error(403, 'err_not_ai', $lang);
    if (!palette_licence_access($lic)['ai']) {
        $t = $lic['paid_through'] === null ? false : strtotime($lic['paid_through']);
        if ($t === false) return palette_analyse_error(403, 'err_ended', $lang, ['ended_on' => null], [], 'err_ended_nodate');
        $ended = date('Y-m-d', $t);
        return palette_analyse_error(403, 'err_ended', $lang, ['ended_on' => $ended], ['date' => $ended]);
    }

    // 3. Per-licence rate limits: both buckets record the hit.
    $id = (int)$lic['id'];
    $okMin = palette_rate_hit("ai:min:$id", 6, 60);
    $okHour = palette_rate_hit("ai:hour:$id", 60, 3600);
    if (!$okMin || !$okHour) return palette_analyse_error(429, 'api_rate_limited', $lang);

    $settings = palette_settings($c);
    $usage = fn() => array_intersect_key(palette_usage_get($c, $lic, $settings), ['used' => 1, 'limit' => 1, 'resets_on' => 1]);

    // 4. Cache: same host, language, colour set and page text (ruling A13) within 7 days costs nothing.
    $hash = palette_colour_hash($design);
    $pageHash = palette_page_hash($page);
    $cached = palette_cache_get($c, $host, $lang, $hash, $pageHash);
    if ($cached !== null) return ['status' => 200, 'body' => ['ok' => true, 'result' => $cached, 'cached' => true, 'usage' => $usage()]];

    // 5. Reserve before any paid call goes out.
    if (!palette_usage_reserve($c, $lic, $settings)) {
        $now = $usage();
        return palette_analyse_error(429, 'err_quota', $lang, ['resets_on' => $now['resets_on'], 'usage' => $now], ['date' => (string)$now['resets_on']]);
    }

    try {
        // 6. Search: both null = provider unavailable (AI-only); [] = no results.
        [$q1, $q2] = palette_analyse_queries($page['title'], $page['description'], $host);
        $r1 = palette_search($settings, $q1);
        $r2 = palette_search($settings, $q2);
        $merged = [];
        foreach ([$r1 ?? [], $r2 ?? []] as $list) {
            foreach ($list as $row) $merged[$row['host']] ??= $row;
        }

        // 7. AI call; its tokens count whatever happens next.
        $ai = palette_ai_call($c, palette_ai_prompt($page, $design, array_slice(array_values($merged), 0, PALETTE_ANALYSE_PROMPT_RESULTS), $lang));
        palette_usage_tokens($c, $lic, $ai['usage']['in'], $ai['usage']['out']);
        $result = $ai['ok'] ? palette_ai_validate($ai['json'], palette_ai_design_hexes($design), array_keys($merged), $host) : null;
        if ($result === null) {
            error_log('palette_analyse: AI ' . ($ai['ok'] ? 'answer failed validation' : $ai['error']));
            palette_usage_release($c, $lic);
            return palette_analyse_error(503, 'err_ai_unavailable', $lang);
        }
        $result['search_unavailable'] = $r1 === null && $r2 === null;

        // 8. Record.
        palette_cache_put($c, $host, $lang, $hash, $pageHash, $result);
        palette_sites_record($c, $host, $page, $design, $result);
    } catch (Throwable $e) {
        palette_usage_release($c, $lic);   // nothing was delivered, so nothing is charged
        throw $e;
    }
    return ['status' => 200, 'body' => ['ok' => true, 'result' => $result, 'cached' => false, 'usage' => $usage()]];
}

// $messageKey: the i18n string when it differs from the error key (err_ended without a date).
function palette_analyse_error(int $status, string $key, string $lang, array $extra = [], array $vars = [], string $messageKey = ''): array
{
    return ['status' => $status, 'body' => ['ok' => false, 'error' => $key, 'message' => palette_t($lang, $messageKey ?: $key, $vars)] + $extra];
}

// Page text with personal data removed (ruling A9): URLs, email addresses, @handles, unread counters such as "(3)" or
// "[12]", and runs of 6+ digits (phone numbers, ids). Separators left dangling are collapsed; then flattened and capped.
function palette_scrub_text($s, int $max): string
{
    $s = palette_ai_clean($s, 2000);
    if ($s === '') return '';
    $s = (string)preg_replace([
        '~\b[a-z][a-z0-9+.-]*://\S*~iu',                                   // scheme URLs
        '~\bwww\.\S+~iu',                                                  // www. hosts
        '~[^\s@<>()\[\]]+@[^\s@<>()\[\]]+\.[^\s@<>()\[\]]+~u',             // email addresses
        '~(?<![\w@])@[\p{L}\p{N}_][\p{L}\p{N}_.]*~u',                      // @handles
        '~\b[a-z0-9-]+(?:\.[a-z0-9-]+)+/\S*~iu',                           // host/path without a scheme
        '~\+?\d(?:[\s().-]{0,2}\d){5,}~u',                                 // 6+ digits: phones, ids (before counters)
        '~[(\[]\s*\d+\+?\s*[)\]]~u',                                       // unread counters (3) [12] (99+)
    ], ' ', $s);
    $sep = '[-–—|·:•]';
    $s = (string)preg_replace("~\\s*$sep(?:\\s*$sep)+\\s*~u", ' – ', $s);      // "a – – b" -> "a – b"
    $s = (string)preg_replace("~^(?:\\s|$sep)+|(?:\\s|$sep)+$~u", '', $s);     // no separator at either end
    $s = (string)preg_replace('~\s+~u', ' ', $s);
    return trim(mb_substr(trim($s), 0, $max));
}

// ['url' => origin + safe first segment (ruling A14; no userinfo, query, fragment or deeper path), 'host' => palette_host]
// or null for anything that is not a public http(s) page, or whose path is longer than 300 characters.
function palette_analyse_url($raw): ?array
{
    if (!is_string($raw) || strlen($raw) > 4096) return null;
    $p = parse_url(trim($raw));
    if (!is_array($p)) return null;
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) return null;
    $path = (string)($p['path'] ?? '');
    if ($path === '') $path = '/';
    if (strlen($path) > PALETTE_ANALYSE_PATH_MAX) return null;
    $origin = $scheme . '://' . strtolower(rtrim((string)($p['host'] ?? ''), '.')) . (isset($p['port']) ? ':' . (int)$p['port'] : '');
    $host = palette_host($origin . '/');   // no query, so no redirect unwrapping
    if ($host === null || strlen($host) > 190) return null;
    return ['url' => $origin . palette_analyse_safe_path($path), 'host' => $host];
}

// Ruling A14: the model and the search only ever see the origin plus the first path segment, and only when that
// segment is at most 24 characters with no digits and no @ ("/shop", "/pricing"); any other path becomes "/".
function palette_analyse_safe_path(string $path): string
{
    $first = explode('/', $path)[1] ?? '';
    return preg_match('~^[^/\d@]{1,24}$~D', $first) ? '/' . $first : '/';
}

function palette_analyse_hex($v): ?string
{
    return is_string($v) && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $v) ? strtolower($v) : null;
}

function palette_analyse_num($v): int|float|null
{
    if (!is_int($v) && !is_float($v)) return null;
    if (!is_finite((float)$v) || $v < 0 || $v > 10000) return null;
    return floor($v) == $v ? (int)$v : round((float)$v, 2);
}

function palette_analyse_nums($v, int $max): array
{
    $out = [];
    foreach (is_array($v) ? $v : [] as $n) {
        if (count($out) >= $max) break;
        if (($n = palette_analyse_num($n)) !== null) $out[] = $n;
    }
    return $out;
}

// The design summary reduced to the whitelisted shape; everything else in the request is dropped.
function palette_analyse_design(array $d): array
{
    $colours = [];
    foreach (is_array($d['colours'] ?? null) ? $d['colours'] : [] as $col) {
        if (count($colours) >= 10) break;
        $hex = is_array($col) ? palette_analyse_hex($col['hex'] ?? null) : null;
        if ($hex !== null) $colours[] = ['role' => palette_ai_clean($col['role'] ?? '', 20), 'hex' => $hex];
    }
    $fonts = [];
    foreach (is_array($d['fonts'] ?? null) ? $d['fonts'] : [] as $f) {
        if (count($fonts) >= 3) break;
        if (($f = palette_ai_clean($f, 80)) !== '') $fonts[] = $f;
    }
    $c = is_array($d['contrast'] ?? null) ? $d['contrast'] : [];
    $failing = [];
    foreach (is_array($c['failing'] ?? null) ? $c['failing'] : [] as $pair) {
        if (count($failing) >= 6) break;
        if (!is_array($pair)) continue;
        $fg = palette_analyse_hex($pair['fg'] ?? null);
        $bg = palette_analyse_hex($pair['bg'] ?? null);
        if ($fg !== null && $bg !== null) $failing[] = ['fg' => $fg, 'bg' => $bg, 'ratio' => palette_analyse_num($pair['ratio'] ?? null)];
    }
    // JSON may carry 3.0 for 3: integral non-negative numbers count, anything else is 0.
    $count = fn($v) => (is_int($v) || (is_float($v) && is_finite($v))) && $v >= 0 && floor($v) == $v ? (int)min($v, 100000) : 0;
    return [
        'colours' => $colours,
        'fonts' => $fonts,
        'type_sizes' => palette_analyse_nums($d['type_sizes'] ?? null, 12),
        'spacing_base' => palette_analyse_num($d['spacing_base'] ?? null),
        'radius' => palette_analyse_nums($d['radius'] ?? null, 6),
        'contrast' => ['pass' => $count($c['pass'] ?? null), 'fail' => $count($c['fail'] ?? null), 'failing' => $failing],
    ];
}

// sha1 of the design's distinct lowercase hexes, sorted, joined by ','.
function palette_colour_hash(array $design): string
{
    $hexes = palette_ai_design_hexes($design);
    sort($hexes, SORT_STRING);
    return sha1(implode(',', $hexes));
}

// The title before its first ' | ', ' - ', ' — ' or ':' (<= 60 chars), else the host's first label.
function palette_brand(string $title, string $host): string
{
    $t = palette_ai_clean($title, 200);
    $cut = mb_strlen($t);
    foreach ([' | ', ' - ', ' — ', ':'] as $sep) {
        $at = mb_strpos($t, $sep);
        if ($at !== false && $at < $cut) $cut = $at;
    }
    $brand = trim(str_replace('"', '', mb_substr(mb_substr($t, 0, $cut), 0, 60)));
    return $brand !== '' ? $brand : explode('.', $host)[0];
}

// ['"<brand>" competitors', '<description (80) or title or brand> similar websites'].
function palette_analyse_queries(string $title, string $description, string $host): array
{
    $brand = palette_brand($title, $host);
    $topic = palette_ai_clean($description, 80);
    if ($topic === '') $topic = palette_ai_clean($title, 120);
    if ($topic === '') $topic = $brand;
    return ['"' . $brand . '" competitors', $topic . ' similar websites'];
}

// sha1 of the scrubbed title + "\n" + scrubbed description (ruling A13): a cached answer is only ever reused for the same
// page text, so one page cannot plant an answer that other pages of the host would be served.
function palette_page_hash(array $page): string
{
    return sha1((string)($page['title'] ?? '') . "\n" . (string)($page['description'] ?? ''));
}

function palette_cache_get(mysqli $c, string $host, string $lang, string $hash, string $pageHash): ?array
{
    $since = date('Y-m-d H:i:s', time() - PALETTE_ANALYSE_CACHE_TTL);
    $st = $c->prepare('SELECT result_json FROM palette_ai_cache WHERE host = ? AND lang = ? AND colour_hash = ? AND page_hash = ? AND created_at >= ?');
    $st->bind_param('sssss', $host, $lang, $hash, $pageHash, $since);
    $st->execute();
    $row = $st->get_result()->fetch_row();
    $st->close();
    $r = $row ? json_decode((string)$row[0], true) : null;
    return is_array($r) ? $r : null;
}

// Upserts the result and purges expired rows (the table only grows by misses, so this is cheap).
function palette_cache_put(mysqli $c, string $host, string $lang, string $hash, string $pageHash, array $result): void
{
    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $now = date('Y-m-d H:i:s');
    $st = $c->prepare('INSERT INTO palette_ai_cache (host, lang, colour_hash, page_hash, result_json, created_at) VALUES (?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE result_json = VALUES(result_json), created_at = VALUES(created_at)');
    $st->bind_param('ssssss', $host, $lang, $hash, $pageHash, $json, $now);
    $st->execute();
    $st->close();
    $cut = date('Y-m-d H:i:s', time() - PALETTE_ANALYSE_CACHE_TTL);
    $st = $c->prepare('DELETE FROM palette_ai_cache WHERE created_at < ?');
    $st->bind_param('s', $cut);
    $st->execute();
    $st->close();
}
