<?php
// Admin Directory and Members tabs (lib/directory-admin.php): item actions incl. dir_style, member deactivate/ban/restore, settings.
require __DIR__ . '/helpers.php';
ob_start();
session_start();

foreach (['palette_dir_items', 'palette_dir_reports', 'palette_dir_members', 'palette_dir_tokens', 'palette_dir_log', 'palette_rate'] as $t) {
    $conn->query("DELETE FROM $t");
}
// The companies users table (read-only here: only the email is joined in).
$conn->query('CREATE TABLE IF NOT EXISTS users (id INT PRIMARY KEY, email VARCHAR(190) NULL)');
$conn->query("REPLACE INTO users (id, email) VALUES (5120, 'a.nguyen@example.com'), (5408, 'k.le@example.com')");

function upload(int $uid, string $host): array
{
    $tmp = function (string $bytes): array {
        $p = tempnam(sys_get_temp_dir(), 'dir');
        file_put_contents($p, $bytes);
        return ['tmp_name' => $p, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
    };
    $im = imagecreatetruecolor(40, 30);
    ob_start();
    imagepng($im);
    $png = (string)ob_get_clean();
    $GLOBALS['conn']->query('DELETE FROM palette_rate');
    $r = palette_dir_upload($GLOBALS['conn'], $uid, 'extension', ['url' => "https://$host/", 'title' => $host, 'style' => 'minimal', 'consent' => '1'],
        ['tokens' => $tmp('{"c":{"$type":"color","a":{"$value":"#112233"}}}'), 'shot' => $tmp($png)], '203.0.113.1');
    return palette_dir_item($GLOBALS['conn'], 'slug', $r['slug']);
}

$admin = ['user_id' => 1, 'user_role' => 'admin', 'csrf_tokens' => ['palette_admin' => str_repeat('a', 64)]];
$do = fn(string $action, array $p = []) => palette_admin_page($GLOBALS['conn'], $admin, [], ['action' => $action, 'csrf' => str_repeat('a', 64)] + $p, 'POST');
$item = fn(int $id) => palette_dir_item($GLOBALS['conn'], 'id', $id);
$logged = fn(string $action, int $id) => (int)$GLOBALS['conn']->query("SELECT COUNT(*) FROM palette_dir_log WHERE action = '$action' AND target_id = $id")->fetch_row()[0];

$x = upload(5120, 'orchid-bridal.example');
$y = upload(5408, 'nebula.example');
$id = (int)$x['id'];

// ---- access
ok('non-admin: 403', palette_admin_page($conn, ['user_id' => 5], ['tab' => 'directory'], [], 'GET')['status'] === 403);
$tab = palette_admin_page($conn, $admin, ['tab' => 'directory'], [], 'GET');
ok('Directory tab lists new items with masked email', $tab['status'] === 200 && str_contains($tab['body'], 'orchid-bridal.example')
    && str_contains($tab['body'], palette_h(palette_mask_email('a.nguyen@example.com'))) && !str_contains($tab['body'], 'a.nguyen@example.com'));
ok('nav badge counts new + reported', str_contains($tab['body'], 'Directory <span class="badge">2</span>'));
ok('hide asks first', str_contains(palette_admin_page($conn, $admin, ['tab' => 'directory', 'confirm' => "hide-$id"], [], 'GET')['body'], 'role="alertdialog"'));

// ---- item actions
$r = $do('dir_reviewed', ['id' => (string)$id, 'status' => 'new']);
ok('Looks fine -> 303 ok', $r['status'] === 303 && str_contains($r['headers']['Location'], 'ok=dir_checked') && str_contains($r['headers']['Location'], 'tab=directory'));
ok('reviewed_at set, logged', $item($id)['reviewed_at'] !== null && $logged('dir_reviewed', $id) === 1);
$r = $do('dir_hide', ['id' => (string)$id]);
ok('hide without a reason -> note_required, confirm reopened', str_contains($r['headers']['Location'], 'err=note_required') && str_contains($r['headers']['Location'], 'confirm=hide-' . $id)
    && $item($id)['status'] === 'published');
$do('dir_hide', ['id' => (string)$id, 'note' => 'Screenshot shows an email address.']);
ok('hide: hidden by admin with the reason', $item($id)['status'] === 'hidden' && $item($id)['hidden_by'] === 'admin' && $item($id)['status_note'] === 'Screenshot shows an email address.');
$do('dir_unhide', ['id' => (string)$id]);
ok('show again', $item($id)['status'] === 'published' && $item($id)['status_note'] === null && $logged('dir_unhide', $id) === 1);
$tech = (int)$conn->query("SELECT id FROM palette_dir_styles WHERE slug = 'tech'")->fetch_row()[0];
$r = $do('dir_style', ['id' => (string)$id, 'style' => (string)$tech]);
ok('dir_style changes the style and logs it', str_contains($r['headers']['Location'], 'ok=saved') && $item($id)['style_slug'] === 'tech'
    && $conn->query("SELECT note FROM palette_dir_log WHERE action = 'dir_style'")->fetch_row()[0] === 'style: Tech');
ok('dir_style: unknown style refused', str_contains($do('dir_style', ['id' => (string)$id, 'style' => '9999'])['headers']['Location'], 'err=bad_style'));
ok('unknown item', str_contains($do('dir_hide', ['id' => '999999', 'note' => 'x'])['headers']['Location'], 'err=dir_missing'));

foreach ([701, 702, 703] as $m) palette_dir_report($conn, $item($id), $m, 'spam', '');
ok('3 reports hid it', $item($id)['hidden_by'] === 'reports');
ok('Reported list shows reasons', str_contains(palette_admin_page($conn, $admin, ['tab' => 'directory', 'status' => 'reported'], [], 'GET')['body'], 'spam · 3'));
$do('dir_dismiss_reports', ['id' => (string)$id, 'status' => 'reported']);
ok('dismiss and show', $item($id)['status'] === 'published' && (int)$item($id)['reports'] === 0
    && (int)$conn->query("SELECT COUNT(*) FROM palette_dir_reports WHERE item_id = $id AND status = 'open'")->fetch_row()[0] === 0);

$dir = palette_dir_folder($x);
$r = $do('dir_delete', ['id' => (string)$id]);
ok('delete removes row and folder', str_contains($r['headers']['Location'], 'ok=dir_deleted') && $item($id) === null && !is_dir($dir) && $logged('dir_delete', $id) === 1);

// ---- members: ban blocks upload and download and revokes tokens
$tok = palette_dir_token_new($conn, 5408);
ok('token works before the ban', palette_dir_token_user($conn, 'Bearer ' . $tok) === 5408);
ok('members tab lists sharers', str_contains(palette_admin_page($conn, $admin, ['tab' => 'members'], [], 'GET')['body'], 'user 5408'));
ok('ban needs a reason', str_contains($do('member_ban', ['id' => '5408'])['headers']['Location'], 'err=note_required'));
$r = $do('member_ban', ['id' => '5408', 'note' => 'Third screenshot of signed-in pages.']);
ok('ban saved', str_contains($r['headers']['Location'], 'ok=member_saved') && palette_dir_member($conn, 5408)['status'] === 'banned');
ok('ban hides their items', $item((int)$y['id'])['status'] === 'hidden' && $item((int)$y['id'])['status_note'] === 'Third screenshot of signed-in pages.');
ok('ban revokes tokens', palette_dir_token_user($conn, 'Bearer ' . $tok) === null);
ok('banned upload -> blocked', palette_dir_upload($conn, 5408, 'web', [], [], '1.1.1.1')['error'] === 'blocked');
$z = upload(5120, 'tidepool.example');
$dl = palette_directory_page($conn, ['path' => '/palette/directory/' . $z['slug'] . '/bundle.zip', 'method' => 'GET', 'session' => ['user_id' => 5408]]);
ok('banned download -> 403', $dl['status'] === 403 && !isset($dl['file']));
ok('banned: connect page has no button', !str_contains(palette_directory_page($conn, ['path' => '/palette/directory/connect', 'method' => 'GET',
    'query' => ['ext' => str_repeat('a', 32)], 'session' => ['user_id' => 5408]])['body'], 'id="dir-connect"'));
$_SESSION = ['user_id' => 5408, 'csrf_tokens' => ['palette_account' => str_repeat('b', 64)]];
ok('banned: dir_token -> 403 blocked', palette_api_handle(['method' => 'POST', 'action' => 'dir_token', 'post' => ['csrf' => str_repeat('b', 64)],
    'session' => $_SESSION])['body']['error'] === 'blocked');
$do('member_restore', ['id' => '5408', 'note' => 'Appeal accepted.']);
ok('restore: active again, items stay hidden', palette_dir_member($conn, 5408)['status'] === 'active' && $item((int)$y['id'])['status'] === 'hidden');
$do('member_deactivate', ['id' => '5120', 'note' => 'Spam.']);
ok('deactivate hides items, keeps tokens table untouched', palette_dir_member($conn, 5120)['status'] === 'deactivated' && $item((int)$z['id'])['status'] === 'hidden');
ok('member actions logged', (int)$conn->query("SELECT COUNT(*) FROM palette_dir_log WHERE target = 'member'")->fetch_row()[0] === 3);

// ---- settings: report limit and styles
ok('settings show the directory block', str_contains(palette_admin_page($conn, $admin, ['tab' => 'settings'], [], 'GET')['body'], 'name="report_limit"'));
ok('limit out of range refused', str_contains($do('dir_settings', ['report_limit' => '0'])['headers']['Location'], 'err=dir_limit'));
$names = array_column(palette_dir_styles($conn, true), 'name', 'id');
$active = array_fill_keys(array_keys($names), '1');
unset($active[$tech]);
$do('dir_settings', ['report_limit' => '5', 'style_name' => $names, 'style_active' => $active, 'style_new' => 'Brutalist']);
ok('limit saved, other settings kept', palette_settings($conn)['dir_report_limit'] === 5 && palette_settings($conn)['price_usd'] === '1.49');
ok('style retired and added', palette_dir_style_id($conn, 'tech') === null && palette_dir_style_id($conn, 'brutalist') !== null);
$conn->query("UPDATE palette_dir_styles SET active = 1 WHERE slug = 'tech'");
$conn->query("DELETE FROM palette_dir_styles WHERE slug = 'brutalist'");
palette_settings_put($conn, ['dir_report_limit' => 3] + palette_settings($conn));
