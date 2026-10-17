<?php
// Private site list (spec 8): every analysed site and every suggested site, with aggregate counters only.
// Nothing here stores or receives a licence, user, email or IP.

function palette_sites_json($v): string
{
    return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

// $page: url/title/description; $design: the whitelisted summary; $result: the validated AI result.
// Of the page only the domain and the brand name (from the scrubbed title, ruling A9) are stored (ruling A15): the title
// column is left NULL (and cleared on update), and the description and url are never stored.
function palette_sites_record(mysqli $c, string $host, array $page, array $design, array $result): void
{
    if ($host === '' || strlen($host) > 190) return;
    $now = date('Y-m-d H:i:s');
    $title = palette_scrub_text($page['title'] ?? '', 255);   // idempotent; analyse has already scrubbed it
    $name = mb_substr(palette_brand($title, $host), 0, 190);
    $sector = palette_ai_clean($result['sector'] ?? '', 100);
    $tags = palette_sites_json(array_values(array_filter(is_array($result['tags'] ?? null) ? $result['tags'] : [], 'is_string')));
    $colours = [];
    foreach (is_array($design['colours'] ?? null) ? $design['colours'] : [] as $col) {
        if (is_array($col) && is_string($col['hex'] ?? null)) $colours[] = ['role' => (string)($col['role'] ?? ''), 'hex' => $col['hex']];
    }
    $colours = palette_sites_json($colours);
    $fonts = palette_sites_json(array_values(array_filter(is_array($design['fonts'] ?? null) ? $design['fonts'] : [], 'is_string')));

    // LAST_INSERT_ID(id) makes insert_id the row's id on the update path too.
    $st = $c->prepare('INSERT INTO palette_sites (domain, name, sector, tags_json, colours_json, fonts_json, first_seen, last_seen, analyses_count)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                       ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name = VALUES(name), title = NULL, sector = VALUES(sector),
                         tags_json = VALUES(tags_json), colours_json = VALUES(colours_json), fonts_json = VALUES(fonts_json),
                         last_seen = VALUES(last_seen), analyses_count = analyses_count + 1');
    $st->bind_param('ssssssss', $host, $name, $sector, $tags, $colours, $fonts, $now, $now);
    $st->execute();
    $siteId = (int)$c->insert_id;
    $st->close();

    foreach (is_array($result['sites'] ?? null) ? $result['sites'] : [] as $s) {
        $domain = is_array($s) && is_string($s['domain'] ?? null) ? $s['domain'] : '';
        if ($domain === '' || $domain === $host || strlen($domain) > 190) continue;
        $sname = mb_substr(palette_ai_clean($s['name'] ?? '', 190), 0, 190);
        $st = $c->prepare('INSERT INTO palette_sites (domain, name, first_seen, last_seen, suggested_count) VALUES (?, ?, ?, ?, 1)
                           ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name = COALESCE(name, VALUES(name)),
                             last_seen = VALUES(last_seen), suggested_count = suggested_count + 1');
        $st->bind_param('ssss', $domain, $sname, $now, $now);
        $st->execute();
        $relId = (int)$c->insert_id;
        $st->close();
        $relation = ($s['relation'] ?? '') === 'competitor' ? 'competitor' : 'similar';
        $source = ($s['source'] ?? '') === 'web' ? 'web' : 'ai';
        // Once a pair has been seen in web results it stays labelled web.
        $st = $c->prepare("INSERT INTO palette_site_links (site_id, related_site_id, relation, source) VALUES (?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE seen_count = seen_count + 1, source = IF(VALUES(source) = 'web', 'web', source)");
        $st->bind_param('iiss', $siteId, $relId, $relation, $source);
        $st->execute();
        $st->close();
    }
}

// The most-seen sites (analyses + suggestions), newest first on ties.
function palette_sites_top(mysqli $c, int $limit = 200): array
{
    $limit = max(1, min($limit, 5000));
    $st = $c->prepare('SELECT domain, name, sector, tags_json, analyses_count, suggested_count, first_seen, last_seen
                       FROM palette_sites ORDER BY analyses_count + suggested_count DESC, last_seen DESC, domain LIMIT ?');
    $st->bind_param('i', $limit);
    $st->execute();
    $out = [];
    foreach ($st->get_result() as $r) {
        $tags = json_decode((string)$r['tags_json'], true);
        $out[] = ['domain' => $r['domain'], 'name' => $r['name'], 'sector' => $r['sector'],
                  'tags' => is_array($tags) ? $tags : [], 'analyses_count' => (int)$r['analyses_count'],
                  'suggested_count' => (int)$r['suggested_count'], 'first_seen' => $r['first_seen'], 'last_seen' => $r['last_seen']];
    }
    $st->close();
    return $out;
}

// A cell a spreadsheet will not evaluate: text starting with = + - @ tab or CR gets a leading apostrophe.
function palette_csv_cell($v): string
{
    $s = (string)$v;
    return $s !== '' && strpbrk($s[0], "=+-@\t\r") !== false ? "'" . $s : $s;
}

const PALETTE_SITES_COLUMNS = ['domain', 'name', 'sector', 'tags', 'analyses_count', 'suggested_count', 'first_seen', 'last_seen'];

function palette_sites_row(array $s): array
{
    return [$s['domain'], $s['name'] ?? '', $s['sector'] ?? '', implode('; ', $s['tags']),
            $s['analyses_count'], $s['suggested_count'], $s['first_seen'], $s['last_seen']];
}

// admin-sites.php as a pure function: ['status', 'headers', 'body']. Admin session only; ?format=csv downloads.
function palette_admin_sites_page(mysqli $c, array $session, array $query): array
{
    $html = ['Cache-Control' => 'no-store'] + palette_page_headers();
    if (($session['user_role'] ?? '') !== 'admin') return ['status' => 403, 'headers' => $html, 'body' => 'Forbidden'];
    $sites = palette_sites_top($c);

    if (($query['format'] ?? '') === 'csv') {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, PALETTE_SITES_COLUMNS, ',', '"', '');
        foreach ($sites as $s) fputcsv($fh, array_map('palette_csv_cell', palette_sites_row($s)), ',', '"', '');
        rewind($fh);
        $csv = (string)stream_get_contents($fh);
        fclose($fh);
        return ['status' => 200, 'body' => $csv, 'headers' => [
            'Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="palette-sites.csv"',
            'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']];
    }

    $head = implode('', array_map(fn($h) => '<th>' . palette_h($h) . '</th>', PALETTE_SITES_COLUMNS));
    $rows = '';
    foreach ($sites as $s) {
        $rows .= '<tr>' . implode('', array_map(fn($v) => '<td>' . palette_h((string)$v) . '</td>', palette_sites_row($s))) . '</tr>';
    }
    if ($rows === '') $rows = '<tr><td colspan="' . count(PALETTE_SITES_COLUMNS) . '">No sites yet.</td></tr>';
    $body = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
          . '<title>Site to Design Token: sites</title><style>'
          . 'body{font-family:Arial,Helvetica,sans-serif;margin:24px;color:#1c1f26;background:#fff}'
          . 'table{border-collapse:collapse;font-size:14px}th,td{border:1px solid #d7dbe3;padding:4px 8px;text-align:start;vertical-align:top}'
          . 'th{background:#f0f2f6}@media (prefers-color-scheme:dark){body{color:#e6e8ee;background:#14161b}th{background:#232731}th,td{border-color:#3a3f4b}a{color:#8ab4f8}}'
          . '</style></head><body><h1>Site to Design Token: sites</h1>'
          . '<p>' . count($sites) . ' most-seen sites (analysed or suggested). Aggregate counts only, no user data. '
          . 'analyses_count counts uncached analyses only (a cached answer is not recorded). '
          . '<a href="?format=csv">Download CSV</a></p>'
          . '<table><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></body></html>';
    return ['status' => 200, 'headers' => $html, 'body' => $body];
}
