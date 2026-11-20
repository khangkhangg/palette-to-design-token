<?php
// Token directory (lib/directory.php): upload validation and every error code, tokens, list, reports, bans, pages.
require __DIR__ . '/helpers.php';
ob_start();
session_start();

const EXT = 'abcdefghijklmnopabcdefghijklmnop';
foreach (['palette_dir_items', 'palette_dir_reports', 'palette_dir_members', 'palette_dir_tokens', 'palette_dir_log', 'palette_rate'] as $t) {
    $conn->query("DELETE FROM $t");
}
palette_dir_rmdir_all(PALETTE_DIR_DATA);
palette_settings_put($conn, ['extension_ids' => [EXT]] + palette_settings($conn));
unset($GLOBALS['palette_dir_free']);

function palette_dir_rmdir_all(string $d): void
{
    if (!is_dir($d)) return;
    foreach (scandir($d) as $f) if ($f !== '.' && $f !== '..') is_dir("$d/$f") ? palette_dir_rmdir_all("$d/$f") : unlink("$d/$f");
    rmdir($d);
}

function tmpfile_with(string $bytes): array
{
    $p = tempnam(sys_get_temp_dir(), 'dir');
    file_put_contents($p, $bytes);
    return ['tmp_name' => $p, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
}

function png(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 80, 60));
    ob_start();
    imagepng($im);
    return (string)ob_get_clean();
}

$dtcg = json_encode(['color' => ['$type' => 'color', 'primary' => ['$value' => '#2f7a55'], 'text' => ['$value' => '#1E2B22']],
    'font' => ['$type' => 'fontFamily', 'heading' => ['$value' => ['Fraunces', 'serif']]], 'meta' => ['$extensions' => new stdClass()]]);
$figma = json_encode(['color' => ['$type' => 'color', 'bg' => ['$value' => '#ffffff']], '$extensions' => ['com.figma' => ['modes' => ['Light']]]]);
$PNG = png(2000, 1200);
$post = ['url' => 'https://fernleaf.example/shop?utm=x#top', 'title' => 'Fernleaf garden shop', 'style' => 'editorial', 'consent' => '1'];
$files = fn(array $over = []) => $over + ['tokens' => tmpfile_with($GLOBALS['dtcg']), 'shot' => tmpfile_with($GLOBALS['PNG'])];
$up = fn(int $uid, array $p = [], array $f = [], string $ip = '203.0.113.9') => palette_dir_upload($GLOBALS['conn'], $uid, 'web', $p + $GLOBALS['post'], $files($f), $ip);

// ---- URL cleaning
ok('url: query and fragment stripped', palette_dir_clean_url('https://Fernleaf.example/shop?utm=x#top') === 'https://fernleaf.example/shop');
ok('url: no path -> /', palette_dir_clean_url('http://a.com') === 'http://a.com/');
foreach (['http://localhost/', 'http://192.168.1.20/admin/', 'http://10.0.0.1/', 'http://127.0.0.1:8080/', 'http://[::1]/', 'http://169.254.1.1/',
    'ftp://a.com/', 'javascript:alert(1)', 'https://intranet/', 'https://box.local/', 'https://u:p@a.com/', 'not a url'] as $bad) {
    ok("url refused: $bad", palette_dir_clean_url($bad) === null);
}

// ---- tokens and image
$t = palette_dir_tokens($dtcg);
ok('dtcg parsed', $t['format'] === 'dtcg' && $t['summary']['colours'] === ['#2F7A55', '#1E2B22'] && $t['summary']['fonts'] === ['Fraunces'] && $t['summary']['tokens'] === 3);
ok('dtcg re-serialized keeps {}', str_contains($t['json'], '"$extensions": {}'));
ok('figma detected', palette_dir_tokens($figma)['format'] === 'figma');
foreach (['[1,2]', '{"a":1}', '<html></html>', "PK\x03\x04zip", ''] as $bad) ok('not tokens: ' . substr($bad, 0, 8), palette_dir_tokens($bad) === null);
$webp = palette_dir_webp($PNG);
$info = getimagesizefromstring((string)$webp);
ok('webp: re-encoded, 1600 px wide', $info[2] === IMAGETYPE_WEBP && $info[0] === 1600 && $info[1] === 960);
ok('svg refused', palette_dir_webp('<svg xmlns="http://www.w3.org/2000/svg"></svg>') === null);
ok('zip refused', palette_dir_webp("PK\x03\x04" . str_repeat('x', 100)) === null);

// ---- upload: success
$r = $up(11);
$item = palette_dir_item($conn, 'slug', (string)($r['slug'] ?? ''));
ok('upload ok, slug from host', $r['ok'] && preg_match('/^fernleaf-example-[a-z2-9]{4}$/', $r['slug']) === 1);
ok('row stored', $item['url'] === 'https://fernleaf.example/shop' && $item['host'] === 'fernleaf.example' && $item['status'] === 'published'
    && $item['style_slug'] === 'editorial' && $item['source'] === 'web' && $item['token_format'] === 'dtcg' && $item['reviewed_at'] === null);
$dir = palette_dir_folder($item);
ok('folder YYYY/MM/id', $dir === PALETTE_DIR_DATA . '/' . gmdate('Y/m') . '/' . $item['id']);
ok('files written', is_file("$dir/tokens.json") && is_file("$dir/screenshot.webp") && is_file("$dir/meta.json") && is_file("$dir/bundle.zip") && !is_file("$dir/figma-variables.json"));
$zip = new ZipArchive();
$zip->open("$dir/bundle.zip");
ok('bundle.zip has the parts', $zip->numFiles === 3 && $zip->locateName('tokens.json') !== false);
$zip->close();
$fl = json_decode($item['files_json'], true);
ok('files_json with sha256', count($fl) === 4 && $fl[0]['sha256'] === hash_file('sha256', $dir . '/' . $fl[0]['name']) && (int)$item['bytes'] > 0);
ok('meta.json', json_decode(file_get_contents("$dir/meta.json"), true)['url'] === 'https://fernleaf.example/shop');

// ---- upload: every error code
$GLOBALS['conn']->query('DELETE FROM palette_rate');
$code = fn(array $r) => $r['ok'] ? 'ok' : $r['status'] . ' ' . $r['error'];
ok('no consent -> 422', $code($up(12, ['consent' => ''])) === '422 no_consent');
ok('private url -> bad_url', $code($up(12, ['url' => 'http://192.168.1.20/admin/'])) === '422 bad_url');
ok('style required -> no_style', $code($up(12, ['style' => ''])) === '422 no_style');
ok('unknown style -> no_style', $code($up(13, ['style' => 'nope'])) === '422 no_style');
$conn->query("UPDATE palette_dir_styles SET active = 0 WHERE slug = 'retro'");
ok('retired style -> no_style', $code($up(13, ['style' => 'retro'])) === '422 no_style');
$conn->query("UPDATE palette_dir_styles SET active = 1 WHERE slug = 'retro'");
ok('html as tokens -> bad_tokens', $code($up(14, [], ['tokens' => tmpfile_with('<html>hi</html>')])) === '422 bad_tokens');
ok('no token file -> bad_tokens', $code(palette_dir_upload($conn, 14, 'web', $post, ['shot' => tmpfile_with($PNG)], '1.1.1.1')) === '422 bad_tokens');
ok('bad figma file -> bad_tokens', $code($up(14, [], ['figma' => tmpfile_with('[]')])) === '422 bad_tokens');
ok('svg shot -> bad_image', $code($up(15, [], ['shot' => tmpfile_with('<svg xmlns="http://www.w3.org/2000/svg"/>')])) === '422 bad_image');
ok('no shot -> bad_image', $code(palette_dir_upload($conn, 15, 'web', $post, ['tokens' => tmpfile_with($dtcg)], '1.1.1.2')) === '422 bad_image');
ok('json over 512 KB -> too_large', $code($up(16, [], ['tokens' => tmpfile_with('{"a":"' . str_repeat('x', 530000) . '"}')])) === '413 too_large');
ok('PHP size error -> too_large', $code($up(16, [], ['shot' => ['tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]])) === '413 too_large');
$GLOBALS['palette_dir_free'] = 1024;
ok('low disk -> disk_full', $code($up(17)) === '507 disk_full');
unset($GLOBALS['palette_dir_free']);
$conn->query('DELETE FROM palette_rate');
for ($i = 0; $i < 5; $i++) $up(18, ['consent' => '']);
ok('6th upload in an hour -> rate_limited', $code($up(18)) === '429 rate_limited');
for ($i = 0; $i < 20; $i++) $up(100 + $i, ['consent' => ''], [], '198.51.100.1');
ok('21st upload from one IP -> rate_limited', $code($up(200, [], [], '198.51.100.1')) === '429 rate_limited');
$conn->query("INSERT INTO palette_dir_members (user_id, status, note, changed_at) VALUES (19, 'deactivated', 'x', NOW())");
ok('deactivated -> blocked', $code($up(19)) === '403 blocked');

// ---- API: extension tokens (Bearer) and the session path
$conn->query('DELETE FROM palette_rate');
$_SESSION = ['user_id' => 21, 'csrf_tokens' => ['palette_account' => str_repeat('c', 64)]];
$api = fn(string $method, string $action, array $post = [], array $over = []) => palette_api_handle($over + ['method' => $method, 'action' => $action,
    'post' => $post, 'origin' => 'chrome-extension://' . EXT, 'ip' => '203.0.113.9', 'session' => $_SESSION]);
$r = $api('POST', 'dir_token', ['csrf' => str_repeat('c', 64)]);
$tok = (string)($r['body']['token'] ?? '');
ok('dir_token: 64 hex token', $r['status'] === 200 && preg_match('/^[0-9a-f]{64}$/', $tok) === 1);
$row = $conn->query('SELECT token_hash FROM palette_dir_tokens WHERE user_id = 21')->fetch_row();
ok('only the SHA-256 hash is stored', $row[0] === hash('sha256', $tok) && $conn->query("SELECT COUNT(*) FROM palette_dir_tokens WHERE token_hash = '$tok'")->fetch_row()[0] == 0);
ok('dir_token needs CSRF', $api('POST', 'dir_token', ['csrf' => 'x'])['status'] === 419);
ok('dir_token needs a login', $api('POST', 'dir_token', ['csrf' => str_repeat('c', 64)], ['session' => []])['status'] === 403);
$me = $api('GET', 'dir_me', [], ['auth' => 'Bearer ' . $tok]);
ok('dir_me: status and styles in sort order', $me['status'] === 200 && $me['body']['status'] === 'active'
    && $me['body']['styles'][0] === ['slug' => 'minimal', 'name' => 'Minimal'] && count($me['body']['styles']) === 8);
ok('dir_me: CORS for the extension', ($me['headers']['Access-Control-Allow-Origin'] ?? '') === 'chrome-extension://' . EXT);
ok('dir_me: unknown token -> 401 token_revoked', $api('GET', 'dir_me', [], ['auth' => 'Bearer ' . str_repeat('0', 64)])['body']['error'] === 'token_revoked');
ok('dir_me: POST -> 405', $api('POST', 'dir_me', [], ['auth' => 'Bearer ' . $tok])['status'] === 405);
$pre = $api('OPTIONS', 'dir_upload');
ok('preflight allows Authorization', $pre['status'] === 204 && str_contains($pre['headers']['Access-Control-Allow-Headers'], 'Authorization'));
$r = $api('POST', 'dir_upload', $post, ['auth' => 'Bearer ' . $tok, 'files' => $files()]);
ok('dir_upload with Bearer: absolute url', $r['status'] === 200 && $r['body']['ok'] === true
    && $r['body']['url'] === 'https://vietnamcos.com/palette/directory/' . $r['body']['slug']);
ok('extension source stored', palette_dir_item($conn, 'slug', $r['body']['slug'])['source'] === 'extension');
$r = $api('POST', 'dir_upload', ['style' => ''] + $post, ['auth' => 'Bearer ' . $tok, 'files' => $files()]);
ok('dir_upload error envelope', $r['status'] === 422 && $r['body'] === ['ok' => false, 'error' => 'no_style', 'message' => palette_t('en', 'no_style')]);
ok('dir_upload over post_max_size -> 413', $api('POST', 'dir_upload', [], ['auth' => 'Bearer ' . $tok, 'body_bytes' => 9000000])['body']['error'] === 'too_large');
ok('dir_upload revoked token -> 401', $api('POST', 'dir_upload', $post, ['auth' => 'Bearer ' . str_repeat('f', 64)])['status'] === 401);
$r = $api('POST', 'dir_upload', $post + ['csrf' => str_repeat('c', 64)], ['files' => $files(), 'origin' => '']);
ok('dir_upload with session + CSRF', $r['status'] === 200 && palette_dir_item($conn, 'slug', $r['body']['slug'])['source'] === 'web');
ok('dir_upload session without CSRF -> 419', $api('POST', 'dir_upload', $post, ['files' => $files(), 'origin' => ''])['status'] === 419);

// ---- list: style filter and search
$conn->query('DELETE FROM palette_rate');
$a = $up(31, ['url' => 'https://harbor-coffee.example/', 'title' => 'Harbor Roasters', 'style' => 'retro'], [], '1.0.0.1')['slug'];
$b = $up(32, ['url' => 'https://lumen-studio.example/', 'title' => 'Lumen dashboard', 'style' => 'tech'], [], '1.0.0.2')['slug'];
$slugs = fn(array $res) => array_column($res['rows'], 'slug');
ok('filter by style', $slugs(palette_dir_list($conn, '', 'retro', 1)) === [$a]);
ok('search title or host', $slugs(palette_dir_list($conn, 'lumen', '', 1)) === [$b] && $slugs(palette_dir_list($conn, 'harbor-coffee', '', 1)) === [$a]);
$counts = array_column(palette_dir_style_counts($conn), 'n', 'slug');
ok('style counts', (int)$counts['retro'] === 1 && (int)$counts['tech'] === 1 && count($counts) === 8);

// ---- reports: auto-hide at 3 different members
$it = palette_dir_item($conn, 'slug', $a);
ok('owner cannot report', palette_dir_report($conn, $it, 31, 'spam', '') === 'own');
ok('bad reason', palette_dir_report($conn, $it, 41, 'meh', '') === 'bad_reason');
ok('report 1', palette_dir_report($conn, $it, 41, 'personal_data', 'email visible') === '');
ok('same member again -> already', palette_dir_report($conn, $it, 41, 'spam', '') === 'already');
palette_dir_report($conn, $it, 42, 'spam', '');
ok('2 reports: still visible', palette_dir_item($conn, 'slug', $a)['status'] === 'published' && (int)palette_dir_item($conn, 'slug', $a)['reports'] === 2);
palette_dir_report($conn, $it, 43, 'other', '');
$it = palette_dir_item($conn, 'slug', $a);
ok('3rd member hides it', $it['status'] === 'hidden' && $it['hidden_by'] === 'reports' && (int)$it['reports'] === 3);
ok('hidden items leave the list', !in_array($a, $slugs(palette_dir_list($conn, '', '', 1)), true));
palette_settings_put($conn, ['dir_report_limit' => 1] + palette_settings($conn));
palette_dir_report($conn, palette_dir_item($conn, 'slug', $b), 41, 'broken', '');
ok('limit from Settings', palette_dir_item($conn, 'slug', $b)['status'] === 'hidden');
palette_settings_put($conn, ['dir_report_limit' => 3] + palette_settings($conn));
$conn->query('DELETE FROM palette_rate');
for ($i = 0; $i < 10; $i++) palette_dir_report($conn, ['id' => 999000 + $i, 'user_id' => 1], 77, 'spam', '');
ok('11th report in an hour -> rate_limited', palette_dir_report($conn, ['id' => 999999, 'user_id' => 1], 77, 'spam', '') === 'rate_limited');

// ---- pages
$conn->query('DELETE FROM palette_rate');
$fern = palette_dir_item($conn, 'slug', $conn->query("SELECT slug FROM palette_dir_items WHERE user_id = 11")->fetch_row()[0]);
$page = fn(string $path, array $session = [], string $method = 'GET', array $post = [], array $query = []) => palette_directory_page($conn,
    ['path' => '/palette/directory' . $path, 'method' => $method, 'query' => $query, 'post' => $post, 'session' => $session, 'ip' => '203.0.113.9']);
$list = $page('');
ok('list page renders cards', $list['status'] === 200 && str_contains($list['body'], 'Fernleaf garden shop') && str_contains($list['body'], '/shot.webp'));
ok('list page: no emoji', preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $list['body']) === 0);
ok('list page in Arabic is RTL', str_contains($page('', [], 'GET', [], ['lang' => 'ar'])['body'], 'dir="rtl"'));
$d = $page('/' . $fern['slug']);
ok('detail signed out: sign in to download', $d['status'] === 200 && str_contains($d['body'], '/companies/?auth=login') && !str_contains($d['body'], 'bundle.zip"'));
ok('detail shows no uploader', !str_contains($d['body'], 'user 11') && !str_contains($d['body'], 'csrf'));
ok('hidden item: 404 for others', $page('/' . $a)['status'] === 404 && $page('/' . $a, ['user_id' => 41])['status'] === 404);
ok('hidden item: owner and admin see it', $page('/' . $a, ['user_id' => 31])['status'] === 200 && $page('/' . $a, ['user_id' => 1, 'user_role' => 'admin'])['status'] === 200);
$shot = $page('/' . $fern['slug'] . '/shot.webp');
ok('screenshot public, nosniff', $shot['status'] === 200 && $shot['headers']['Content-Type'] === 'image/webp' && $shot['headers']['X-Content-Type-Options'] === 'nosniff' && is_file($shot['file']));
ok('hidden screenshot 404', $page('/' . $a . '/shot.webp')['status'] === 404);
ok('download signed out -> sign in', $page('/' . $fern['slug'] . '/tokens.json')['status'] === 303);
$dl = $page('/' . $fern['slug'] . '/tokens.json', ['user_id' => 50]);
ok('download: JSON attachment', $dl['status'] === 200 && str_starts_with($dl['headers']['Content-Disposition'], 'attachment;') && $dl['headers']['Content-Type'] === 'application/json');
ok('download counted', (int)palette_dir_item($conn, 'id', $fern['id'])['downloads'] === 1);
ok('missing figma file -> 404', $page('/' . $fern['slug'] . '/figma-variables.json', ['user_id' => 50])['status'] === 404);
ok('unknown file -> 404', $page('/' . $fern['slug'] . '/meta.json', ['user_id' => 50])['status'] === 404);
ok('new/mine/connect need a login', $page('/new')['status'] === 303 && $page('/mine')['status'] === 303 && $page('/connect', [], 'GET', [], ['ext' => EXT])['status'] === 303);
$_SESSION = ['user_id' => 60, 'csrf_tokens' => ['palette_account' => str_repeat('d', 64)]];
$n = $page('/new', $_SESSION);
ok('upload form: style required, consent required', $n['status'] === 200 && str_contains($n['body'], 'name="style" required') && str_contains($n['body'], 'name="consent" value="1" required'));
$n = palette_directory_page($conn, ['path' => '/palette/directory/new', 'method' => 'POST', 'query' => [], 'session' => $_SESSION, 'ip' => '9.9.9.9',
    'post' => $post + ['csrf' => str_repeat('d', 64)], 'files' => $files()]);
ok('web form upload -> 303 to the detail page', $n['status'] === 303 && preg_match('#^/palette/directory/fernleaf-example-[a-z2-9]{4}\?lang=en&ok=shared$#', $n['headers']['Location']) === 1);
$n = palette_directory_page($conn, ['path' => '/palette/directory/new', 'method' => 'POST', 'query' => [], 'session' => $_SESSION, 'ip' => '9.9.9.9',
    'post' => ['url' => 'http://localhost/'] + $post + ['csrf' => str_repeat('d', 64)], 'files' => $files()]);
ok('web form error shown in place', $n['status'] === 422 && str_contains($n['body'], palette_h(palette_t('en', 'bad_url'))) && str_contains($n['body'], 'value="http://localhost/"'));
$c = $page('/connect', $_SESSION, 'GET', [], ['ext' => EXT]);
ok('connect: button with ext and csrf', str_contains($c['body'], 'id="dir-connect" data-ext="' . EXT . '"') && str_contains($c['body'], str_repeat('d', 64)));
ok('connect: unknown extension refused', !str_contains($page('/connect', $_SESSION, 'GET', [], ['ext' => str_repeat('p', 32)])['body'], 'id="dir-connect"'));

// report and delete through the pages
$r = $page('/' . $fern['slug'], $_SESSION, 'POST', ['reason' => 'spam', 'csrf' => str_repeat('d', 64)]);
ok('report form -> ?ok=reported', $r['status'] === 303 && str_contains($r['headers']['Location'], 'ok=reported'));
ok('report form without CSRF', str_contains($page('/' . $fern['slug'], $_SESSION, 'POST', ['reason' => 'spam'])['headers']['Location'], 'err=csrf'));
$mine = $page('/mine', ['user_id' => 11]);
ok('mine lists own items', str_contains($mine['body'], 'Fernleaf garden shop') && !str_contains($mine['body'], 'Harbor Roasters'));
ok('mine: delete asks first', str_contains($page('/mine', ['user_id' => 11], 'GET', [], ['confirm' => $fern['slug']])['body'], 'cancel-confirm'));
$_SESSION = ['user_id' => 11, 'csrf_tokens' => ['palette_account' => str_repeat('e', 64)]];
$page('/mine', $_SESSION, 'POST', ['slug' => $a, 'csrf' => str_repeat('e', 64)]);
ok('cannot delete someone else\'s item', palette_dir_item($conn, 'slug', $a) !== null);
$fdir = palette_dir_folder($fern);
$r = $page('/mine', $_SESSION, 'POST', ['slug' => $fern['slug'], 'csrf' => str_repeat('e', 64)]);
ok('owner deletes: row and folder gone', str_contains($r['headers']['Location'], 'ok=deleted') && palette_dir_item($conn, 'id', $fern['id']) === null && !is_dir($fdir));
