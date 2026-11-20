<?php
// Public design token directory (proposal: docs/directory/token-directory-proposal.html, kept outside git).
// Items are public as soon as they are shared; reports auto-hide them and admins check them afterwards.
// Files live under PALETTE_DIR_DATA (bootstrap.php), outside the docroot and the deploy's rsync target.

const PALETTE_DIR_JSON_MAX = 512 * 1024;
const PALETTE_DIR_SHOT_MAX = 8 * 1024 * 1024;
const PALETTE_DIR_SHOT_WIDTH = 1600;
const PALETTE_DIR_SHOT_PIXELS = 40000000; // refuse decompression bombs before GD allocates them
const PALETTE_DIR_MIN_FREE = 512 * 1024 * 1024; // uploads stop with disk_full below this much free disk
const PALETTE_DIR_PER_PAGE = 24;
const PALETTE_DIR_REASONS = ['personal_data', 'copyright', 'offensive', 'spam', 'broken', 'other'];
// Published path => file on disk. Only the screenshot is public; the rest need a signed-in member.
const PALETTE_DIR_FILES = ['shot.webp' => 'screenshot.webp', 'tokens.json' => 'tokens.json',
    'figma-variables.json' => 'figma-variables.json', 'bundle.zip' => 'bundle.zip'];
// ?ok= / ?err= codes on directory pages => i18n key. Anything else shows nothing.
const PALETTE_DIR_FLASH = [
    'ok' => ['shared' => 'dir_shared_ok', 'reported' => 'dir_reported_ok', 'deleted' => 'dir_deleted_ok', 'disconnected' => 'dir_disconnected_ok'],
    'err' => ['rate_limited' => 'rate_limited', 'already' => 'dir_reported_already', 'bad_reason' => 'dir_err_reason',
        'csrf' => 'err_csrf', 'files' => 'dir_err_delete'],
];

// ---- members and extension tokens

function palette_dir_member(mysqli $c, int $uid): array
{
    $st = $c->prepare('SELECT status, note FROM palette_dir_members WHERE user_id = ?');
    $st->bind_param('i', $uid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: ['status' => 'active', 'note' => null];
}

// A new extension token for this member. Only its SHA-256 hash is stored.
function palette_dir_token_new(mysqli $c, int $uid): string
{
    $tok = bin2hex(random_bytes(32));
    $hash = hash('sha256', $tok);
    $now = gmdate('Y-m-d H:i:s');
    $st = $c->prepare('INSERT INTO palette_dir_tokens (user_id, token_hash, created_at) VALUES (?, ?, ?)');
    $st->bind_param('iss', $uid, $hash, $now);
    $st->execute();
    $st->close();
    return $tok;
}

// The member behind an "Authorization: Bearer <token>" header, or null when the token is missing, unknown or revoked.
function palette_dir_token_user(mysqli $c, string $auth): ?int
{
    if (!preg_match('/^Bearer\s+([0-9a-f]{64})$/i', trim($auth), $m)) return null;
    $hash = hash('sha256', strtolower($m[1]));
    $st = $c->prepare('SELECT id, user_id FROM palette_dir_tokens WHERE token_hash = ? AND revoked_at IS NULL');
    $st->bind_param('s', $hash);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) return null;
    $now = gmdate('Y-m-d H:i:s');
    $id = (int)$row['id'];
    $st = $c->prepare('UPDATE palette_dir_tokens SET last_used_at = ? WHERE id = ?');
    $st->bind_param('si', $now, $id);
    $st->execute();
    $st->close();
    return (int)$row['user_id'];
}

// ---- styles

function palette_dir_styles(mysqli $c, bool $withRetired = false): array
{
    $rows = $c->query('SELECT id, slug, name, sort, active FROM palette_dir_styles' . ($withRetired ? '' : ' WHERE active = 1')
        . ' ORDER BY sort, id')->fetch_all(MYSQLI_ASSOC);
    return $rows;
}

function palette_dir_style_id(mysqli $c, string $slug): ?int
{
    foreach (palette_dir_styles($c) as $s) if ($s['slug'] === $slug) return (int)$s['id'];
    return null;
}

// ---- validation of one upload

// The URL without query string or fragment, or null when it is not a public http(s) address.
// shortcut: checks the host as written (names and literal IPs) without DNS; the server never fetches it, replace if it ever does.
function palette_dir_clean_url(string $raw): ?string
{
    $u = parse_url(trim($raw));
    if (!is_array($u) || !in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true) || !isset($u['host'])
        || isset($u['user']) || isset($u['pass'])) return null;
    $host = strtolower(rtrim($u['host'], '.'));
    $ip = trim($host, '[]');
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
    } elseif (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $host)
        || preg_match('/\.(localhost|local|localdomain|internal|lan|home\.arpa)$/', $host)) {
        return null;
    }
    $url = strtolower($u['scheme']) . '://' . $host . (isset($u['port']) ? ':' . (int)$u['port'] : '') . ($u['path'] ?? '/');
    return strlen($url) <= 500 ? $url : null;
}

// A DTCG token tree (the extension's tokens.json and figma-variables.json are both DTCG-shaped):
// the re-serialized JSON, its format and the card summary, or null when the text is not one.
function palette_dir_tokens(string $raw): ?array
{
    $tree = json_decode($raw, true, 64);
    if (!is_array($tree) || array_is_list($tree)) return null;
    $colours = [];
    $fonts = [];
    $n = 0;
    $walk = function (array $node, ?string $type) use (&$walk, &$colours, &$fonts, &$n): void {
        $type = is_string($node['$type'] ?? null) ? $node['$type'] : $type;
        if (array_key_exists('$value', $node)) {
            $n++;
            $v = $node['$value'];
            if ($type === 'color' && is_string($v) && preg_match('/^#[0-9a-f]{3,8}$/i', $v)) $colours[strtoupper($v)] = true;
            if ($type === 'fontFamily') {
                $first = is_array($v) ? ($v[0] ?? null) : $v;
                if (is_string($first) && $first !== '') $fonts[mb_substr($first, 0, 60)] = true;
            }
            return;
        }
        foreach ($node as $k => $child) if (is_array($child) && !str_starts_with((string)$k, '$')) $walk($child, $type);
    };
    $walk($tree, null);
    if ($n === 0) return null;
    return [
        // Decoded again as objects so empty {} stays {} in the stored file.
        'json' => json_encode(json_decode($raw), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'format' => isset($tree['$extensions']['com.figma']) ? 'figma' : 'dtcg',
        'summary' => ['colours' => array_slice(array_keys($colours), 0, 8), 'fonts' => array_slice(array_keys($fonts), 0, 4),
            'colour_count' => count($colours), 'tokens' => $n],
    ];
}

// The screenshot re-encoded with GD as WebP (at most 1,600 px wide, quality 80), which drops EXIF and anything
// hidden in the file. Null when GD cannot read a PNG, JPEG or WebP from the bytes (SVG, HTML, zip...).
function palette_dir_webp(string $bytes): ?string
{
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)
        || $info[0] * $info[1] > PALETTE_DIR_SHOT_PIXELS) return null;
    $im = @imagecreatefromstring($bytes);
    if (!$im) return null;
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);
    if (imagesx($im) > PALETTE_DIR_SHOT_WIDTH) {
        $small = imagescale($im, PALETTE_DIR_SHOT_WIDTH);
        imagedestroy($im);
        if (!$small) return null;
        $im = $small;
    }
    ob_start();
    $ok = imagewebp($im, null, 80);
    $out = (string)ob_get_clean();
    imagedestroy($im);
    return $ok && $out !== '' ? $out : null;
}

function palette_dir_free_bytes(): float
{
    if (isset($GLOBALS['palette_dir_free'])) return (float)$GLOBALS['palette_dir_free']; // tests
    @mkdir(PALETTE_DIR_DATA, 0750, true);
    return (float)@disk_free_space(PALETTE_DIR_DATA);
}

// Files of one item: PALETTE_DIR_DATA/YYYY/MM/{id}, from its created_at (UTC).
function palette_dir_folder(array $item): string
{
    return PALETTE_DIR_DATA . '/' . gmdate('Y/m', (int)strtotime($item['created_at'] . ' UTC')) . '/' . (int)$item['id'];
}

function palette_dir_rmdir(string $dir): bool
{
    if (!is_dir($dir)) return true;
    foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
    return @rmdir($dir);
}

// ---- upload

// One upload from the web form or the extension. $files is shaped like $_FILES (tokens, figma, shot).
// Answers ['ok' => true, 'slug'] or ['ok' => false, 'status' => HTTP status, 'error' => code].
function palette_dir_upload(mysqli $c, int $uid, string $source, array $post, array $files, string $ip): array
{
    $fail = fn(int $status, string $error) => ['ok' => false, 'status' => $status, 'error' => $error];
    if (palette_dir_member($c, $uid)['status'] !== 'active') return $fail(403, 'blocked');
    if (!palette_rate_hit('dir:up:' . $uid, 5, 3600) || !palette_rate_hit('dir:upday:' . $uid, 20, 86400)
        || !palette_rate_hit('dir:upip:' . $ip, 20, 3600)) return $fail(429, 'rate_limited');
    $str = fn(string $k): string => is_string($post[$k] ?? null) ? trim($post[$k]) : '';
    if ($str('consent') !== '1') return $fail(422, 'no_consent');
    $url = palette_dir_clean_url($str('url'));
    if ($url === null) return $fail(422, 'bad_url');
    $style = palette_dir_style_id($c, $str('style'));
    if ($style === null) return $fail(422, 'no_style');

    $blobs = [];
    foreach (['tokens' => PALETTE_DIR_JSON_MAX, 'figma' => PALETTE_DIR_JSON_MAX, 'shot' => PALETTE_DIR_SHOT_MAX] as $k => $max) {
        $f = is_array($files[$k] ?? null) ? $files[$k] : [];
        $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int)($f['size'] ?? 0) > $max) return $fail(413, 'too_large');
        $tmp = (string)($f['tmp_name'] ?? '');
        // CLI never receives real uploads: the tests pass plain temp files.
        if ($err !== UPLOAD_ERR_OK || !(is_uploaded_file($tmp) || PHP_SAPI === 'cli')) return $fail(422, $k === 'shot' ? 'bad_image' : 'bad_tokens');
        $blobs[$k] = (string)file_get_contents($tmp, false, null, 0, $max + 1);
        if (strlen($blobs[$k]) > $max) return $fail(413, 'too_large');
    }
    $tokens = isset($blobs['tokens']) ? palette_dir_tokens($blobs['tokens']) : null;
    $figma = isset($blobs['figma']) ? palette_dir_tokens($blobs['figma']) : null;
    if ((isset($blobs['tokens']) && !$tokens) || (isset($blobs['figma']) && !$figma) || (!$tokens && !$figma)) return $fail(422, 'bad_tokens');
    $shot = isset($blobs['shot']) ? palette_dir_webp($blobs['shot']) : null;
    if ($shot === null) return $fail(422, 'bad_image');
    if (palette_dir_free_bytes() < PALETTE_DIR_MIN_FREE) return $fail(507, 'disk_full');

    $host = (string)parse_url($url, PHP_URL_HOST);
    $title = mb_substr($str('title') !== '' ? $str('title') : $host, 0, 120);
    $format = $tokens ? $tokens['format'] : 'figma';
    $summary = json_encode(($tokens ?? $figma)['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $now = gmdate('Y-m-d H:i:s');
    $base = substr(trim((string)preg_replace('/[^a-z0-9]+/', '-', preg_replace('/^www\./', '', $host)), '-'), 0, 70);
    $id = 0;
    for ($try = 0; $try < 5 && $id === 0; $try++) {
        $slug = $base . '-';
        for ($i = 0; $i < 4; $i++) $slug .= 'abcdefghijkmnpqrstuvwxyz23456789'[random_int(0, 31)];
        $st = $c->prepare("INSERT IGNORE INTO palette_dir_items (slug, user_id, host, url, title, source, token_format, style_id,
            summary_json, files_json, bytes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '[]', 0, ?)");
        $st->bind_param('sisssssiss', $slug, $uid, $host, $url, $title, $source, $format, $style, $summary, $now);
        $st->execute();
        $id = (int)$st->insert_id;
        $st->close();
    }
    if ($id === 0) return $fail(500, 'err_generic');

    $item = ['id' => $id, 'created_at' => $now];
    $dir = palette_dir_folder($item);
    $out = ['screenshot.webp' => $shot, 'meta.json' => json_encode(['url' => $url, 'title' => $title, 'host' => $host,
        'captured_at' => $now . 'Z', 'source' => $source], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    if ($tokens) $out['tokens.json'] = $tokens['json'];
    if ($figma) $out['figma-variables.json'] = $figma['json'];
    $written = (is_dir($dir) || @mkdir($dir, 0750, true));
    foreach ($out as $name => $bytes) $written = $written && @file_put_contents("$dir/$name", $bytes) === strlen($bytes);
    if ($written) {
        $zip = new ZipArchive();
        $written = $zip->open("$dir/bundle.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE) === true;
        foreach ($out as $name => $bytes) $written = $written && $zip->addFromString($name, $bytes);
        $written = $written && $zip->close();
    }
    if (!$written) {
        palette_dir_rmdir($dir);
        $c->query('DELETE FROM palette_dir_items WHERE id = ' . $id);
        return $fail(507, 'disk_full');
    }
    $list = [];
    $total = 0;
    foreach (array_merge(array_keys($out), ['bundle.zip']) as $name) {
        $size = (int)filesize("$dir/$name");
        $total += $size;
        $list[] = ['name' => $name, 'bytes' => $size, 'sha256' => hash_file('sha256', "$dir/$name")];
    }
    $json = json_encode($list, JSON_UNESCAPED_SLASHES);
    $st = $c->prepare('UPDATE palette_dir_items SET files_json = ?, bytes = ? WHERE id = ?');
    $st->bind_param('sii', $json, $total, $id);
    $st->execute();
    $st->close();
    return ['ok' => true, 'slug' => $slug];
}

// dir_upload for api.php, from the extension (Bearer) or the web (session + CSRF).
function palette_dir_upload_reply(mysqli $c, int $uid, string $source, array $req, string $lang, array $cors): array
{
    $post = is_array($req['post'] ?? null) ? $req['post'] : [];
    $files = is_array($req['files'] ?? null) ? $req['files'] : [];
    // Over post_max_size PHP drops the whole body: an empty request that had a body was too large.
    if ($post === [] && $files === [] && (int)($req['body_bytes'] ?? 0) > 0) return palette_api_error(413, 'too_large', $lang, $cors);
    $r = palette_dir_upload($c, $uid, $source, $post, $files, (string)($req['ip'] ?? ''));
    if (!$r['ok']) return palette_api_error($r['status'], $r['error'], $lang, $cors);
    return palette_api_reply(200, ['ok' => true, 'slug' => $r['slug'], 'url' => PALETTE_STRIPE_BASE . '/directory/' . $r['slug']], $cors);
}

// ---- reading

const PALETTE_DIR_ITEM_SQL = 'SELECT i.*, s.slug AS style_slug, s.name AS style_name FROM palette_dir_items i
    JOIN palette_dir_styles s ON s.id = i.style_id';

function palette_dir_item(mysqli $c, string $col, $val): ?array
{
    $col = $col === 'id' ? 'i.id' : 'i.slug';
    $st = $c->prepare(PALETTE_DIR_ITEM_SQL . " WHERE $col = ?");
    $v = (string)$val;
    $st->bind_param('s', $v);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

function palette_dir_summary(array $item): array
{
    $s = json_decode((string)$item['summary_json'], true);
    return (is_array($s) ? $s : []) + ['colours' => [], 'fonts' => [], 'colour_count' => 0, 'tokens' => 0];
}

// Published items, newest first, 24 a page; $q matches title or host, $style is a style slug.
function palette_dir_list(mysqli $c, string $q, string $style, int $page): array
{
    $where = "i.status = 'published'";
    $types = '';
    $args = [];
    if ($q !== '') {
        $where .= ' AND (i.title LIKE ? OR i.host LIKE ?)';
        $types .= 'ss';
        array_push($args, palette_admin_like($q), palette_admin_like($q));
    }
    if ($style !== '') {
        $where .= ' AND s.slug = ?';
        $types .= 's';
        $args[] = $style;
    }
    $st = $c->prepare('SELECT COUNT(*) FROM palette_dir_items i JOIN palette_dir_styles s ON s.id = i.style_id WHERE ' . $where);
    if ($types !== '') $st->bind_param($types, ...$args);
    $st->execute();
    $total = (int)$st->get_result()->fetch_row()[0];
    $st->close();
    $page = min(max(1, $page), max(1, (int)ceil($total / PALETTE_DIR_PER_PAGE)));
    $offset = ($page - 1) * PALETTE_DIR_PER_PAGE;
    $st = $c->prepare(PALETTE_DIR_ITEM_SQL . " WHERE $where ORDER BY i.created_at DESC, i.id DESC LIMIT " . PALETTE_DIR_PER_PAGE . " OFFSET $offset");
    if ($types !== '') $st->bind_param($types, ...$args);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return ['rows' => $rows, 'total' => $total, 'page' => $page];
}

// Active styles with their count of published items (every item has exactly one style).
function palette_dir_style_counts(mysqli $c): array
{
    return $c->query("SELECT s.slug, s.name, COUNT(i.id) AS n FROM palette_dir_styles s
        LEFT JOIN palette_dir_items i ON i.style_id = s.id AND i.status = 'published'
        WHERE s.active = 1 GROUP BY s.id, s.slug, s.name, s.sort ORDER BY s.sort, s.id")->fetch_all(MYSQLI_ASSOC);
}

// ---- changes

// One report per member per item. When open reports from the limit's number of members (Settings, default 3)
// are reached, the item is hidden until an admin checks it. Returns '' or an error code.
function palette_dir_report(mysqli $c, array $item, int $uid, string $reason, string $note): string
{
    if ((int)$item['user_id'] === $uid) return 'own';
    if (!in_array($reason, PALETTE_DIR_REASONS, true)) return 'bad_reason';
    if (!palette_rate_hit('dir:report:' . $uid, 10, 3600)) return 'rate_limited';
    $id = (int)$item['id'];
    $note = mb_substr(trim($note), 0, 500);
    $now = gmdate('Y-m-d H:i:s');
    $st = $c->prepare('INSERT IGNORE INTO palette_dir_reports (item_id, user_id, reason, note, created_at) VALUES (?, ?, ?, ?, ?)');
    $st->bind_param('iisss', $id, $uid, $reason, $note, $now);
    $st->execute();
    $added = $st->affected_rows === 1;
    $st->close();
    if (!$added) return 'already';
    palette_dir_sync_reports($c, $id);
    return '';
}

function palette_dir_sync_reports(mysqli $c, int $id): void
{
    $open = (int)$c->query("SELECT COUNT(*) FROM palette_dir_reports WHERE item_id = $id AND status = 'open'")->fetch_row()[0];
    $c->query("UPDATE palette_dir_items SET reports = $open WHERE id = $id");
    $limit = max(1, (int)palette_settings($c)['dir_report_limit']);
    if ($open >= $limit) $c->query("UPDATE palette_dir_items SET status = 'hidden', hidden_by = 'reports' WHERE id = $id AND status = 'published'");
}

// Removes the folder, then the row and its reports. When the folder cannot be removed the item is hidden
// instead, and the error log names it (PALETTE_DIR_FILES) so an admin can clean up.
function palette_dir_delete(mysqli $c, array $item): bool
{
    $id = (int)$item['id'];
    if (!palette_dir_rmdir(palette_dir_folder($item))) {
        error_log('PALETTE_DIR_FILES could not delete ' . palette_dir_folder($item));
        $c->query("UPDATE palette_dir_items SET status = 'hidden', hidden_by = 'admin' WHERE id = $id");
        return false;
    }
    $c->query("DELETE FROM palette_dir_reports WHERE item_id = $id");
    $c->query("DELETE FROM palette_dir_items WHERE id = $id");
    return true;
}

// ---- pages: /palette/directory/... (directory.php is the adapter, nginx sends every path under it there)

function palette_dir_url(string $rest, string $lang, array $params = []): string
{
    return PALETTE_BASE . '/directory' . ($rest !== '' ? '/' . $rest : '') . '?'
        . http_build_query(['lang' => $lang] + $params, '', '&', PHP_QUERY_RFC3986);
}

function palette_dir_shot_url(string $slug): string
{
    return PALETTE_BASE . '/directory/' . rawurlencode($slug) . '/shot.webp';
}

// $ctx: path, method, query, post, files, session, accept_language, ip, body_bytes.
// Returns ['status', 'headers', 'body'] or, for a file, ['status', 'headers', 'file' => path on disk].
function palette_directory_page(mysqli $c, array $ctx): array
{
    $query = is_array($ctx['query'] ?? null) ? $ctx['query'] : [];
    $post = is_array($ctx['post'] ?? null) ? $ctx['post'] : [];
    $session = is_array($ctx['session'] ?? null) ? $ctx['session'] : [];
    $path = (string)($ctx['path'] ?? '');
    $v = [
        'c' => $c, 'lang' => palette_page_lang($query, (string)($ctx['accept_language'] ?? '')),
        'uid' => palette_session_user($session), 'user' => palette_session_viewer($session), 'admin' => palette_admin_is_admin($session),
        'query' => $query, 'post' => $post, 'files' => is_array($ctx['files'] ?? null) ? $ctx['files'] : [],
        'ip' => (string)($ctx['ip'] ?? ''), 'body_bytes' => (int)($ctx['body_bytes'] ?? 0),
        'isPost' => ($ctx['method'] ?? 'GET') === 'POST',
        'login' => '/companies/?auth=login&redirect=' . rawurlencode($path . ($query ? '?' . http_build_query($query) : '')),
    ];
    $v['csrfOk'] = $v['isPost'] && verifyCSRFToken(is_string($post['csrf'] ?? null) ? $post['csrf'] : '', 'palette_account');
    $rest = trim(substr($path, strlen(PALETTE_BASE . '/directory')), '/');
    $parts = $rest === '' ? [] : explode('/', $rest);
    if (count($parts) > 2) return palette_dir_not_found($v);
    switch ($parts[0] ?? '') {
        case '': return palette_dir_page_list($v);
        case 'new': return count($parts) === 1 ? palette_dir_page_new($v) : palette_dir_not_found($v);
        case 'mine': return count($parts) === 1 ? palette_dir_page_mine($v) : palette_dir_not_found($v);
        case 'connect': return count($parts) === 1 ? palette_dir_page_connect($v) : palette_dir_not_found($v);
        default: return palette_dir_page_item($v, $parts[0], $parts[1] ?? null);
    }
}

function palette_dir_html(array $v, int $status, string $page, string $title, string $main, array $data = [], array $meta = []): array
{
    $keep = array_intersect_key(palette_page_keep($v['query']), ['ext' => 1]);
    if ($status !== 200) $meta['noindex'] = true;
    return ['status' => $status, 'headers' => palette_page_headers() + ['Cache-Control' => 'private, no-store'],
        'body' => palette_page_shell($page, $v['lang'], $keep, $title . ' | Site to Design Token', $main, $data, $v['user'], $meta)];
}

function palette_dir_redirect(string $to): array
{
    return ['status' => 303, 'headers' => ['Location' => $to, 'Cache-Control' => 'no-store'], 'body' => ''];
}

function palette_dir_t(array $v, string $key, array $vars = []): string
{
    return palette_h(palette_t($v['lang'], $key, $vars));
}

// The ?ok= / ?err= line, or a given error code's message.
function palette_dir_flash(array $v, ?string $errKey = null): string
{
    $ok = PALETTE_DIR_FLASH['ok'][(string)($v['query']['ok'] ?? '')] ?? null;
    $err = $errKey ?? PALETTE_DIR_FLASH['err'][(string)($v['query']['err'] ?? '')] ?? null;
    if ($err !== null) return '<p class="error" role="alert">' . palette_icon('alert', 16) . '<span>' . palette_dir_t($v, $err) . '</span></p>';
    if ($ok !== null) return '<p class="pending" role="status">' . palette_icon('check', 16) . '<span>' . palette_dir_t($v, $ok) . '</span></p>';
    return '';
}

function palette_dir_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . palette_h(palette_account_csrf_token()) . '">';
}

function palette_dir_not_found(array $v): array
{
    $main = '<main class="wrap narrow"><div class="page-head"><h1>' . palette_dir_t($v, 'dir_not_found') . '</h1></div>'
        . '<p><a href="' . palette_h(palette_dir_url('', $v['lang'])) . '">' . palette_dir_t($v, 'dir_back') . '</a></p></main>';
    return palette_dir_html($v, 404, 'directory', palette_t($v['lang'], 'dir_title'), $main);
}

function palette_dir_card(array $v, array $r): string
{
    $s = palette_dir_summary($r);
    $strip = '';
    foreach (array_slice($s['colours'], 0, 5) as $hex) $strip .= '<span style="background:' . palette_h($hex) . '"></span>';
    return '<li><a class="dcard" href="' . palette_h(palette_dir_url($r['slug'], $v['lang'])) . '">'
        . '<img src="' . palette_h(palette_dir_shot_url($r['slug'])) . '" alt="" loading="lazy" decoding="async">'
        . '<span class="dcard-meta"><b>' . palette_h($r['title']) . '</b>'
        . '<span class="dcard-host">' . palette_h($r['host'] . ' · ' . $r['style_name']) . '</span>'
        . '<span class="strip" aria-hidden="true">' . $strip . '</span>'
        . '<span class="dcard-fine"><span>' . palette_h(implode(', ', array_slice($s['fonts'], 0, 2))) . '</span><span>'
        . palette_dir_t($v, 'dir_downloads', ['n' => (string)$r['downloads']]) . '</span></span></span></a></li>';
}

// W1: the list.
function palette_dir_page_list(array $v): array
{
    $str = fn(string $k): string => is_string($v['query'][$k] ?? null) ? trim($v['query'][$k]) : '';
    $q = mb_substr($str('q'), 0, 100);
    $style = $str('style');
    $res = palette_dir_list($v['c'], $q, $style, (int)$str('page'));
    $chip = fn(string $slug, string $name, int $n) => '<a class="chip"' . ($slug === $style ? ' aria-current="true"' : '')
        . ' href="' . palette_h(palette_dir_url('', $v['lang'], array_filter(['q' => $q, 'style' => $slug]))) . '">'
        . palette_h($name) . ' <span class="num">' . $n . '</span></a>';
    $chips = '';
    $all = 0;
    foreach (palette_dir_style_counts($v['c']) as $s) {
        $all += (int)$s['n'];
        $chips .= $chip($s['slug'], $s['name'], (int)$s['n']);
    }
    $chips = $chip('', palette_t($v['lang'], 'dir_all'), $all) . $chips;
    $cards = '';
    foreach ($res['rows'] as $r) $cards .= palette_dir_card($v, $r);
    if ($cards === '') {
        $cards = '<div class="empty dir-empty">' . palette_icon('search', 18) . '<b>'
            . ($q !== '' ? palette_dir_t($v, 'dir_empty_q', ['q' => $q]) : palette_dir_t($v, 'dir_empty')) . '</b><span>'
            . palette_dir_t($v, 'dir_empty_text') . '</span></div>';
    } else {
        $cards = '<ul class="dcards">' . $cards . '</ul>';
    }
    $pages = max(1, (int)ceil($res['total'] / PALETTE_DIR_PER_PAGE));
    $link = fn(int $p, string $k) => '<a class="btn btn-quiet btn-sm" href="'
        . palette_h(palette_dir_url('', $v['lang'], array_filter(['q' => $q, 'style' => $style, 'page' => $p > 1 ? $p : null]))) . '">'
        . palette_dir_t($v, $k) . '</a>';
    $pager = $res['total'] <= PALETTE_DIR_PER_PAGE ? '' : '<nav class="dir-pager"><span>' . palette_dir_t($v, 'dir_showing', [
        'from' => (string)(($res['page'] - 1) * PALETTE_DIR_PER_PAGE + 1),
        'to' => (string)min($res['total'], $res['page'] * PALETTE_DIR_PER_PAGE), 'total' => (string)$res['total']]) . '</span>'
        . ($res['page'] > 1 ? $link($res['page'] - 1, 'dir_prev') : '') . ($res['page'] < $pages ? $link($res['page'] + 1, 'dir_next') : '') . '</nav>';
    $main = '<main class="wrap dir"><div class="dir-head"><div class="page-head"><h1>' . palette_dir_t($v, 'dir_title') . '</h1>'
        . '<p class="lead">' . palette_dir_t($v, 'dir_lead') . '</p></div>'
        . '<a class="btn btn-accent" href="' . palette_h(palette_dir_url('new', $v['lang'])) . '">' . palette_icon('send', 16)
        . '<span>' . palette_dir_t($v, 'dir_share') . '</span></a></div>'
        . '<nav class="dir-chips" aria-label="' . palette_dir_t($v, 'dir_style') . '">' . $chips . '</nav>'
        . '<form class="dir-search" method="get" action="' . PALETTE_BASE . '/directory" role="search">'
        . '<input type="hidden" name="lang" value="' . palette_h($v['lang']) . '">'
        . ($style !== '' ? '<input type="hidden" name="style" value="' . palette_h($style) . '">' : '')
        . '<input type="search" name="q" value="' . palette_h($q) . '" maxlength="100" aria-label="' . palette_dir_t($v, 'dir_search')
        . '" placeholder="' . palette_dir_t($v, 'dir_search') . '"><button class="btn btn-quiet" type="submit">'
        . palette_icon('search', 16) . '<span class="sr-only">' . palette_dir_t($v, 'dir_search') . '</span></button></form>'
        . $cards . $pager . '</main>';
    return palette_dir_html($v, 200, 'directory', palette_t($v['lang'], 'dir_title'), $main);
}

// W2: detail page, its screenshot and its files.
function palette_dir_page_item(array $v, string $slug, ?string $file): array
{
    $item = palette_dir_item($v['c'], 'slug', $slug);
    $owner = $item && $v['uid'] > 0 && (int)$item['user_id'] === $v['uid'];
    // Hidden items answer 404 to everyone except the owner and admins.
    if (!$item || ($item['status'] !== 'published' && !$owner && !$v['admin'])) return palette_dir_not_found($v);
    $self = palette_dir_url($item['slug'], $v['lang']);

    if ($file !== null) {
        $disk = PALETTE_DIR_FILES[$file] ?? null;
        $full = $disk !== null ? palette_dir_folder($item) . '/' . $disk : '';
        if ($full === '' || !is_file($full)) return palette_dir_not_found($v);
        if ($file === 'shot.webp') {
            return ['status' => 200, 'file' => $full, 'headers' => ['Content-Type' => 'image/webp', 'Content-Length' => (string)filesize($full),
                'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => $item['status'] === 'published' ? 'public, max-age=3600' : 'private, no-store']];
        }
        if ($v['uid'] < 1) return palette_dir_redirect($v['login']);
        if (palette_dir_member($v['c'], $v['uid'])['status'] !== 'active') {
            return palette_dir_html($v, 403, 'directory/' . $item['slug'], $item['title'],
                '<main class="wrap narrow">' . palette_dir_flash($v, 'blocked') . '</main>');
        }
        if (!palette_rate_hit('dir:dl:' . $v['uid'], 120, 3600)) {
            return palette_dir_html($v, 429, 'directory/' . $item['slug'], $item['title'],
                '<main class="wrap narrow">' . palette_dir_flash($v, 'rate_limited') . '</main>');
        }
        $v['c']->query('UPDATE palette_dir_items SET downloads = downloads + 1 WHERE id = ' . (int)$item['id']);
        return ['status' => 200, 'file' => $full, 'headers' => [
            'Content-Type' => $file === 'bundle.zip' ? 'application/zip' : 'application/json',
            'Content-Disposition' => 'attachment; filename="' . $item['slug'] . '-' . $file . '"',
            'Content-Length' => (string)filesize($full), 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']];
    }

    if ($v['isPost']) {
        if ($v['uid'] < 1) return palette_dir_redirect($v['login']);
        if (!$v['csrfOk']) return palette_dir_redirect($self . '&err=csrf');
        $err = palette_dir_report($v['c'], $item, $v['uid'], (string)($v['post']['reason'] ?? ''), (string)($v['post']['note'] ?? ''));
        return palette_dir_redirect($self . ($err === '' ? '&ok=reported' : '&err=' . rawurlencode($err)) . '#report');
    }

    $s = palette_dir_summary($item);
    $t = fn(string $k, array $vars = []) => palette_dir_t($v, $k, $vars);
    $swatches = '';
    foreach ($s['colours'] as $hex) $swatches .= '<li><i style="background:' . palette_h($hex) . '"></i><span class="mono">' . palette_h($hex) . '</span></li>';
    $fonts = '';
    foreach ($s['fonts'] as $f) $fonts .= '<li>' . palette_h($f) . '</li>';
    $signedIn = $v['uid'] > 0;
    $sizes = [];
    foreach (json_decode((string)$item['files_json'], true) ?: [] as $f) $sizes[$f['name']] = (int)$f['bytes'];
    $kb = fn(string $name) => max(1, (int)round(($sizes[$name] ?? 0) / 1024)) . ' KB';
    $files = '';
    foreach (['tokens.json', 'figma-variables.json'] as $name) {
        if (!isset($sizes[$name])) continue;
        $files .= '<li class="keyrow"><span class="keyrow-main"><code>' . $name . '</code><span class="keyrow-meta">' . $kb($name) . '</span></span>'
            . ($signedIn ? '<a class="btn btn-quiet btn-sm" href="' . palette_h(PALETTE_BASE . '/directory/' . $item['slug'] . '/' . $name) . '" download>'
                . palette_icon('download', 14) . '<span>' . $t('dir_download') . '</span></a>' : '') . '</li>';
    }
    $download = $signedIn
        ? '<a class="btn btn-accent btn-lg" href="' . palette_h(PALETTE_BASE . '/directory/' . $item['slug'] . '/bundle.zip') . '" download>'
            . palette_icon('download', 17) . '<span>' . $t('dir_download_all', ['size' => $kb('bundle.zip')]) . '</span></a>'
        : '<a class="btn btn-accent btn-lg" href="' . palette_h($v['login']) . '">' . palette_icon('user', 17) . '<span>'
            . $t('dir_signin_dl') . '</span></a><p class="hint">' . $t('dir_signin_hint') . '</p>';
    $hiddenBar = $item['status'] !== 'published'
        ? '<p class="error" role="status">' . palette_icon('alert', 16) . '<span>' . $t('dir_hidden_owner', ['reason' => (string)($item['status_note'] ?? '')]) . '</span></p>' : '';

    $report = '';
    if (!$owner) {
        $reported = false;
        if ($signedIn) {
            $st = $v['c']->prepare('SELECT 1 FROM palette_dir_reports WHERE item_id = ? AND user_id = ?');
            $iid = (int)$item['id'];
            $st->bind_param('ii', $iid, $v['uid']);
            $st->execute();
            $reported = $st->get_result()->num_rows > 0;
            $st->close();
        }
        $reasons = '';
        foreach (PALETTE_DIR_REASONS as $r) {
            $reasons .= '<label class="consent"><input type="radio" name="reason" value="' . $r . '" required><span>' . $t('dir_reason_' . $r) . '</span></label>';
        }
        $report = $reported ? '<p class="hint">' . palette_icon('check', 14) . ' ' . $t('dir_reported_already') . '</p>'
            : ($signedIn
                ? '<details class="dir-report"><summary class="btn btn-quiet btn-sm">' . palette_icon('alert', 14) . '<span>' . $t('dir_report') . '</span></summary>'
                    . '<form method="post" action="' . palette_h($self) . '" class="claim">' . palette_dir_csrf_field()
                    . '<fieldset class="field"><legend class="field-label">' . $t('dir_report_h') . '</legend>' . $reasons . '</fieldset>'
                    . '<div class="field"><label for="report-note">' . $t('dir_report_note') . '</label>'
                    . '<textarea id="report-note" name="note" maxlength="500" rows="3"></textarea></div>'
                    . '<button class="btn btn-accent" type="submit">' . $t('dir_report_send') . '</button></form></details>'
                : '<a class="btn btn-quiet btn-sm" href="' . palette_h($v['login']) . '">' . palette_icon('alert', 14) . '<span>' . $t('dir_report') . '</span></a>');
    }
    $mail = '<span dir="ltr">' . palette_h(PALETTE_SUPPORT_EMAIL) . '</span>';

    $main = '<main class="wrap dir"><p><a class="aside" href="' . palette_h(palette_dir_url('', $v['lang'])) . '">'
        . $t('dir_back') . '</a></p>' . palette_dir_flash($v) . $hiddenBar
        . '<div class="dir-detail"><div class="dir-main">'
        . '<figure class="shot"><img src="' . palette_h(palette_dir_shot_url($item['slug'])) . '" alt=""></figure>'
        . ($swatches !== '' ? '<section class="card"><h2 class="field-label">' . $t('dir_colours') . '</h2><ul class="sw-grid">' . $swatches . '</ul></section>' : '')
        . ($fonts !== '' ? '<section class="card"><h2 class="field-label">' . $t('dir_type') . '</h2><ul class="dir-fonts">' . $fonts . '</ul></section>' : '')
        . '</div><aside class="dir-side"><section class="card claim"><h1 class="dir-h1">' . palette_h($item['title']) . '</h1>'
        . '<a class="aside" href="' . palette_h($item['url']) . '" rel="nofollow noopener noreferrer" dir="ltr">' . palette_h($item['url']) . '</a>'
        . '<dl class="dir-kvs"><div><dt>' . $t('dir_style') . '</dt><dd><a href="' . palette_h(palette_dir_url('', $v['lang'], ['style' => $item['style_slug']])) . '">'
        . palette_h($item['style_name']) . '</a></dd></div>'
        . '<div><dt>' . $t('dir_col_shared') . '</dt><dd>' . palette_ltr(substr((string)$item['created_at'], 0, 10)) . '</dd></div>'
        . '<div><dt>' . $t('dir_downloads_h') . '</dt><dd>' . (int)$item['downloads'] . '</dd></div>'
        . '<div><dt>' . $t('dir_format') . '</dt><dd>' . $t($item['token_format'] === 'figma' ? 'dir_fmt_figma' : 'dir_fmt_dtcg') . '</dd></div></dl>'
        . $download . '</section>'
        . '<section class="card claim"><h2 class="field-label">' . $t('dir_files') . '</h2><ul class="keys">' . $files . '</ul></section>'
        . '<p class="pp-note">' . palette_icon('info', 15) . '<span>' . $t('dir_ext_cta') . '</span></p>'
        . '<div id="report">' . $report . '<p class="hint">' . palette_t_html($v['lang'], 'dir_removal', ['email' => $mail]) . '</p></div>'
        . '</aside></div></main>';
    return palette_dir_html($v, 200, 'directory/' . $item['slug'], $item['title'], $main, [], [
        'description' => palette_t($v['lang'], 'dir_item_desc', ['site' => $item['host']]),
        'image' => PALETTE_ORIGIN . palette_dir_shot_url($item['slug']),
        'noindex' => $item['status'] !== 'published',
    ]);
}

// W3: upload form. Posts here (multipart, session + CSRF) and redirects to the new detail page.
function palette_dir_page_new(array $v): array
{
    if ($v['uid'] < 1) return palette_dir_redirect($v['login']);
    $t = fn(string $k, array $vars = []) => palette_dir_t($v, $k, $vars);
    $err = null;
    $status = 200;
    if ($v['isPost']) {
        if (!$v['csrfOk']) {
            [$status, $err] = [400, 'err_csrf'];
        } elseif ($v['post'] === [] && $v['files'] === [] && $v['body_bytes'] > 0) {
            [$status, $err] = [413, 'too_large'];
        } else {
            $r = palette_dir_upload($v['c'], $v['uid'], 'web', $v['post'], $v['files'], $v['ip']);
            if ($r['ok']) return palette_dir_redirect(palette_dir_url($r['slug'], $v['lang'], ['ok' => 'shared']));
            [$status, $err] = [$r['status'], $r['error']];
        }
    }
    $blocked = palette_dir_member($v['c'], $v['uid'])['status'] !== 'active';
    $val = fn(string $k) => palette_h(is_string($v['post'][$k] ?? null) ? $v['post'][$k] : '');
    $styles = '<option value="">' . $t('dir_f_style_pick') . '</option>';
    foreach (palette_dir_styles($v['c']) as $s) {
        $styles .= '<option value="' . palette_h($s['slug']) . '"' . (($v['post']['style'] ?? '') === $s['slug'] ? ' selected' : '')
            . '>' . palette_h($s['name']) . '</option>';
    }
    $terms = '<a href="' . palette_h(palette_page_url('terms', $v['lang'])) . '">' . $t('page_terms') . '</a>';
    $form = $blocked ? palette_dir_flash($v, 'blocked') : '<form class="card claim" method="post" enctype="multipart/form-data" action="'
        . palette_h(palette_dir_url('new', $v['lang'])) . '">' . palette_dir_csrf_field()
        . palette_dir_flash($v, $err)
        . '<div class="field"><label for="dir-url">' . $t('dir_f_url') . '</label><input type="url" id="dir-url" name="url" required maxlength="500" dir="ltr" value="'
        . $val('url') . '" aria-describedby="dir-url-hint"><p class="hint" id="dir-url-hint">' . $t('dir_f_url_hint') . '</p></div>'
        . '<div class="field-row"><div class="field"><label for="dir-title">' . $t('dir_f_title') . '</label><input type="text" id="dir-title" name="title" maxlength="120" value="'
        . $val('title') . '"></div><div class="field"><label for="dir-style">' . $t('dir_f_style') . '</label><select id="dir-style" name="style" required aria-describedby="dir-style-hint">'
        . $styles . '</select><p class="hint" id="dir-style-hint">' . $t('dir_f_style_hint') . '</p></div></div>'
        . '<div class="field"><label for="dir-tokens">' . $t('dir_f_tokens') . '</label><input type="file" id="dir-tokens" name="tokens" required accept=".json,application/json" aria-describedby="dir-tokens-hint">'
        . '<p class="hint" id="dir-tokens-hint">' . $t('dir_f_tokens_hint') . '</p></div>'
        . '<div class="field"><label for="dir-shot">' . $t('dir_f_shot') . '</label><input type="file" id="dir-shot" name="shot" required accept="image/png,image/jpeg,image/webp" aria-describedby="dir-shot-hint">'
        . '<p class="hint" id="dir-shot-hint">' . $t('dir_f_shot_hint') . '</p></div>'
        . '<label class="consent"><input type="checkbox" name="consent" value="1" required><span>' . $t('dir_f_consent') . '</span></label>'
        . '<p class="hint">' . palette_t_html($v['lang'], 'dir_f_terms', ['terms' => $terms]) . '</p>'
        . '<button class="btn btn-accent btn-lg" type="submit">' . palette_icon('send', 16) . '<span>' . $t('dir_f_submit') . '</span></button></form>';
    $main = '<main class="wrap narrow"><div class="page-head"><h1>' . $t('dir_new_h') . '</h1><p class="lead">' . $t('dir_new_lead') . '</p></div>' . $form . '</main>';
    return palette_dir_html($v, $status, 'directory/new', palette_t($v['lang'], 'dir_new_h'), $main);
}

// W4: the member's own items and connected extensions.
function palette_dir_page_mine(array $v): array
{
    if ($v['uid'] < 1) return palette_dir_redirect($v['login']);
    $c = $v['c'];
    $uid = $v['uid'];
    $mine = palette_dir_url('mine', $v['lang']);
    $t = fn(string $k, array $vars = []) => palette_dir_t($v, $k, $vars);
    if ($v['isPost']) {
        if (!$v['csrfOk']) return palette_dir_redirect($mine . '&err=csrf');
        if (($v['post']['action'] ?? '') === 'disconnect') {
            $tid = (int)($v['post']['id'] ?? 0);
            $now = gmdate('Y-m-d H:i:s');
            $st = $c->prepare('UPDATE palette_dir_tokens SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL');
            $st->bind_param('sii', $now, $tid, $uid);
            $st->execute();
            $st->close();
            return palette_dir_redirect($mine . '&ok=disconnected');
        }
        $item = palette_dir_item($c, 'slug', (string)($v['post']['slug'] ?? ''));
        if (!$item || (int)$item['user_id'] !== $uid) return palette_dir_redirect($mine);
        return palette_dir_redirect($mine . (palette_dir_delete($c, $item) ? '&ok=deleted' : '&err=files'));
    }

    $member = palette_dir_member($c, $uid);
    $mail = '<span dir="ltr">' . palette_h(PALETTE_SUPPORT_EMAIL) . '</span>';
    $blocked = $member['status'] === 'active' ? '' : '<p class="error" role="status">' . palette_icon('alert', 16) . '<span>'
        . palette_t_html($v['lang'], 'dir_blocked', ['reason' => palette_h((string)$member['note']), 'email' => $mail]) . '</span></p>';
    $st = $c->prepare(PALETTE_DIR_ITEM_SQL . ' WHERE i.user_id = ? ORDER BY i.created_at DESC, i.id DESC');
    $st->bind_param('i', $uid);
    $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $confirm = (string)($v['query']['confirm'] ?? '');
    $rows = '';
    foreach ($items as $r) {
        [$label, $cls] = $r['status'] === 'published' ? ['dir_st_published', 'is-active']
            : ($r['hidden_by'] === 'reports' ? ['dir_st_reports', 'is-pending'] : ['dir_st_hidden', 'is-revoked']);
        $why = $r['status'] === 'published' ? ''
            : '<span class="keyrow-meta">' . ($r['hidden_by'] === 'reports' ? $t('dir_reports_hint', ['n' => (string)$r['reports']])
                : palette_h((string)$r['status_note'])) . '</span>';
        $form = '<form method="post" action="' . palette_h($mine) . '">' . palette_dir_csrf_field()
            . '<input type="hidden" name="slug" value="' . palette_h($r['slug']) . '">';
        $actions = $confirm === $r['slug']
            ? '<div class="cancel-confirm" role="group"><p><b>' . $t('dir_delete_q', ['title' => $r['title']]) . '</b> ' . $t('dir_delete_hint') . '</p>'
                . '<div class="cancel-actions">' . $form . '<button class="btn btn-danger btn-sm" type="submit">' . $t('dir_delete') . '</button></form>'
                . '<a class="btn btn-quiet btn-sm" href="' . palette_h($mine) . '">' . $t('dir_keep') . '</a></div></div>'
            : '<a class="btn btn-quiet btn-sm" href="' . palette_h(palette_dir_url($r['slug'], $v['lang'])) . '">' . $t('dir_view') . '</a>'
                . '<a class="btn btn-quiet btn-sm" href="' . palette_h(palette_dir_url('mine', $v['lang'], ['confirm' => $r['slug']])) . '">' . $t('dir_delete') . '</a>';
        $rows .= '<li class="keyrow"><img class="dir-thumb" src="' . palette_h(palette_dir_shot_url($r['slug'])) . '" alt="" loading="lazy">'
            . '<div class="keyrow-main"><b>' . palette_h($r['title']) . '</b><span class="keyrow-meta"><span dir="ltr">' . palette_h($r['host']) . '</span>'
            . '<span class="sub-status ' . $cls . '">' . $t($label) . '</span><span>' . palette_ltr(substr((string)$r['created_at'], 0, 10)) . '</span>'
            . '<span>' . $t('dir_downloads', ['n' => (string)$r['downloads']]) . '</span></span>' . $why . '</div>'
            . '<div class="dir-actions">' . $actions . '</div></li>';
    }
    $list = $rows !== '' ? '<ul class="keys">' . $rows . '</ul>'
        : '<div class="empty dir-empty">' . palette_icon('send', 18) . '<b>' . $t('dir_mine_empty') . '</b><span>' . $t('dir_mine_empty_text') . '</span></div>';

    $st = $c->prepare('SELECT t.id, t.created_at, t.last_used_at FROM palette_dir_tokens t WHERE t.user_id = ? AND t.revoked_at IS NULL ORDER BY t.id DESC');
    $st->bind_param('i', $uid);
    $st->execute();
    $tokens = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $ext = '';
    foreach ($tokens as $tok) {
        $ext .= '<li class="keyrow"><span class="keyrow-main">' . palette_icon('plug', 16) . '<span>'
            . $t('dir_ext_row', ['date' => substr((string)$tok['created_at'], 0, 10), 'last' => substr((string)($tok['last_used_at'] ?? '-'), 0, 10)])
            . '</span></span><form method="post" action="' . palette_h($mine) . '">' . palette_dir_csrf_field()
            . '<input type="hidden" name="action" value="disconnect"><input type="hidden" name="id" value="' . (int)$tok['id'] . '">'
            . '<button class="btn btn-quiet btn-sm" type="submit">' . $t('dir_disconnect') . '</button></form></li>';
    }
    $share = $member['status'] === 'active' ? '<a class="btn btn-accent" href="' . palette_h(palette_dir_url('new', $v['lang'])) . '">'
        . palette_icon('send', 16) . '<span>' . $t('dir_share') . '</span></a>' : '';
    $main = '<main class="wrap narrow"><div class="dir-head"><div class="page-head"><h1>' . $t('dir_nav_mine') . '</h1><p class="lead">'
        . $t('dir_mine_lead') . '</p></div>' . $share . '</div>' . palette_dir_flash($v) . $blocked
        . '<section class="card">' . $list . '</section>'
        . '<section class="card claim"><h2>' . palette_icon('plug', 16) . ' ' . $t('dir_ext_h') . '</h2>'
        . ($ext !== '' ? '<ul class="keys">' . $ext . '</ul>' : '<p class="hint">' . $t('dir_ext_none') . '</p>') . '</section></main>';
    return palette_dir_html($v, 200, 'directory/mine', palette_t($v['lang'], 'dir_nav_mine'), $main);
}

// W5: connect the extension. The button (checkout.js) asks api.php?action=dir_token for a token and hands it
// to the extension with chrome.runtime.sendMessage, so a link alone never creates a token.
function palette_dir_page_connect(array $v): array
{
    if ($v['uid'] < 1) return palette_dir_redirect($v['login']);
    $t = fn(string $k) => palette_dir_t($v, $k);
    $ext = is_string($v['query']['ext'] ?? null) ? $v['query']['ext'] : '';
    $data = [];
    if (palette_dir_member($v['c'], $v['uid'])['status'] === 'banned') {
        $body = palette_dir_flash($v, 'blocked');
    } elseif (!in_array($ext, palette_api_extension_ids(palette_settings($v['c'])), true)) {
        $body = palette_dir_flash($v, 'dir_connect_bad');
    } else {
        $body = '<p class="error" id="dir-connect-status" role="status" hidden></p>'
            . '<button class="btn btn-accent btn-lg" type="button" id="dir-connect" data-ext="' . palette_h($ext) . '">'
            . palette_icon('plug', 17) . '<span>' . $t('dir_connect_btn') . '</span></button>';
        $data = ['csrf' => palette_account_csrf_token(), 't' => ['dir_connect_ok' => palette_t($v['lang'], 'dir_connect_ok'),
            'dir_connect_err' => palette_t($v['lang'], 'dir_connect_err')]];
    }
    $main = '<main class="wrap narrow"><section class="card signin">' . palette_icon('plug', 22) . '<h1 class="dir-h1">' . $t('dir_connect_h') . '</h1>'
        . '<p>' . $t('dir_connect_lead') . '</p>' . $body . '</section></main>';
    return palette_dir_html($v, 200, 'directory/connect', palette_t($v['lang'], 'dir_connect_h'), $main, $data);
}
