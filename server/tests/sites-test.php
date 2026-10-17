<?php
// Private site list (lib/sites.php) and the admin view behind admin-sites.php.
require __DIR__ . '/helpers.php';

$conn->query('DELETE FROM palette_sites');
$conn->query('DELETE FROM palette_site_links');

$page = ['url' => 'https://shop.example/', 'title' => 'Ridge Coffee | Home', 'description' => 'Beans'];
$design = ['colours' => [['role' => 'primary', 'hex' => '#1f5e4b']], 'fonts' => ['Inter'], 'type_sizes' => [], 'spacing_base' => null,
           'radius' => [], 'contrast' => ['pass' => 0, 'fail' => 0, 'failing' => []]];
$result = ['sector' => 'Coffee', 'tags' => ['Shop'], 'sites' => [
    ['name' => 'Blue Bottle', 'domain' => 'bluebottle.example', 'relation' => 'competitor', 'source' => 'web'],
    ['name' => 'Onyx', 'domain' => 'onyx.example', 'relation' => 'similar', 'source' => 'ai']]];
palette_sites_record($conn, 'shop.example', $page, $design, $result);
$result2 = ['sector' => 'Coffee roaster', 'tags' => [], 'sites' => [
    ['name' => 'Renamed', 'domain' => 'bluebottle.example', 'relation' => 'competitor', 'source' => 'ai']]];
palette_sites_record($conn, 'shop.example', $page, $design, $result2);
palette_sites_record($conn, 'other.example', ['title' => 'Other'], $design, ['sector' => 'X', 'tags' => [], 'sites' => [
    ['name' => 'Blue Bottle', 'domain' => 'bluebottle.example', 'relation' => 'similar', 'source' => 'ai']]]);

$top = palette_sites_top($conn);
$d = array_column($top, null, 'domain');
ok('top: every site listed', count($top) === 4 && isset($d['shop.example'], $d['bluebottle.example'], $d['onyx.example'], $d['other.example']));
ok('top: most seen first', $top[0]['domain'] === 'bluebottle.example');
ok('top: analysed counters and latest fields', $d['shop.example']['analyses_count'] === 2 && $d['shop.example']['sector'] === 'Coffee roaster'
    && $d['shop.example']['name'] === 'Ridge Coffee' && $d['shop.example']['tags'] === []);
ok('top: suggested keeps first name', $d['bluebottle.example']['suggested_count'] === 3 && $d['bluebottle.example']['name'] === 'Blue Bottle');
ok('top: limit', count(palette_sites_top($conn, 2)) === 2);
ok('record: only name + domain of the page are kept, the title column stays NULL (ruling A15)',
    (int)$conn->query("SELECT COUNT(*) FROM palette_sites WHERE title IS NOT NULL")->fetch_row()[0] === 0
    && !array_key_exists('title', $d['shop.example']));
$link = $conn->query("SELECT l.source, l.seen_count FROM palette_site_links l JOIN palette_sites s ON s.id = l.site_id
    JOIN palette_sites r ON r.id = l.related_site_id WHERE s.domain = 'shop.example' AND r.domain = 'bluebottle.example'")->fetch_assoc();
ok('links: seen twice, web label kept once seen', (int)$link['seen_count'] === 2 && $link['source'] === 'web');
ok('links: same pair, other relation is its own row', (int)$conn->query("SELECT COUNT(*) FROM palette_site_links")->fetch_row()[0] === 3);

// ---- admin view
$r = palette_admin_sites_page($conn, [], []);
ok('admin: no session -> 403', $r['status'] === 403 && !str_contains($r['body'], 'bluebottle'));
$r = palette_admin_sites_page($conn, ['user_id' => 5, 'user_role' => 'user'], ['format' => 'csv']);
ok('admin: non-admin -> 403 for csv too', $r['status'] === 403 && !str_contains($r['body'], 'bluebottle'));
$admin = ['user_id' => 1, 'user_role' => 'admin'];
$conn->query("INSERT INTO palette_sites (domain, name, title, sector, first_seen, last_seen, analyses_count)
    VALUES ('evil.example', '<script>alert(1)</script>', '=HYPERLINK(\"http://x\")', '+SUM(A1)', NOW(), NOW(), 1)");
$conn->query("INSERT INTO palette_sites (domain, name, sector, first_seen, last_seen, suggested_count)
    VALUES ('evil2.example', '-2+3', '@cmd', NOW(), NOW(), 1)");
$conn->query("INSERT INTO palette_sites (domain, name, first_seen, last_seen, suggested_count)
    VALUES ('evil3.example', '=HYPERLINK(\"http://y\")', NOW(), NOW(), 1)");
$r = palette_admin_sites_page($conn, $admin, []);
ok('admin: html 200 with page security headers', $r['status'] === 200 && str_contains($r['headers']['Content-Security-Policy'] ?? '', "default-src 'self'")
    && ($r['headers']['X-Frame-Options'] ?? '') === 'DENY' && ($r['headers']['Cache-Control'] ?? '') === 'no-store');
ok('admin: html table of sites', str_contains($r['body'], '<table') && str_contains($r['body'], 'bluebottle.example') && str_contains($r['body'], 'format=csv'));
ok('admin: copy says analyses_count is uncached analyses only', str_contains($r['body'], 'uncached analyses only'));
ok('admin: html escaped',!str_contains($r['body'], '<script>alert(1)') && str_contains($r['body'], '&lt;script&gt;'));
ok('admin: no title column, and an old stored title is never shown (ruling A15)', !str_contains($r['body'], '<th>title</th>') && !str_contains($r['body'], 'HYPERLINK(&quot;http://x'));
$r = palette_admin_sites_page($conn, $admin, ['format' => 'csv']);
ok('admin: csv download headers', $r['status'] === 200 && str_starts_with($r['headers']['Content-Type'] ?? '', 'text/csv')
    && str_contains($r['headers']['Content-Disposition'] ?? '', 'attachment') && ($r['headers']['X-Content-Type-Options'] ?? '') === 'nosniff');
$rows = array_map(fn($l) => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", $r['body']), fn($l) => $l !== ''));
ok('admin: csv header row, without title', ($rows[0] ?? []) === ['domain', 'name', 'sector', 'tags', 'analyses_count', 'suggested_count', 'first_seen', 'last_seen']);
$cells = array_merge(...array_slice($rows, 1));
ok('admin: csv has the sites', in_array('bluebottle.example', $cells, true));
ok('admin: csv never exports a stored title', !in_array("'=HYPERLINK(\"http://x\")", $cells, true));
ok('admin: csv neutralises formula cells', in_array("'=HYPERLINK(\"http://y\")", $cells, true) && in_array("'+SUM(A1)", $cells, true)
    && in_array("'-2+3", $cells, true) && in_array("'@cmd", $cells, true));
ok('admin: no raw formula cell', !array_filter($cells, fn($c) => $c !== '' && in_array($c[0], ['=', '+', '-', '@', "\t", "\r"], true)));
ok('csv cell helper', palette_csv_cell('=1') === "'=1" && palette_csv_cell("\tx") === "'\tx" && palette_csv_cell('ok') === 'ok' && palette_csv_cell(5) === '5');

$conn->query('DELETE FROM palette_sites');
$conn->query('DELETE FROM palette_site_links');
