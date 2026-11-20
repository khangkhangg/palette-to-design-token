<?php
// Admin tabs for the token directory: Directory (items) and Members, plus the directory block in Settings.
// Same shape as lib/admin.php: tabs return ['title', 'html'], actions return ['ok' | 'err', 'tab', 'params'].
// Hide, delete and member changes open an inline confirm row first (?confirm=<action>-<id>), no window.confirm.

const PALETTE_DIR_ADMIN_VIEWS = ['new' => 'New', 'reported' => 'Reported', 'hidden' => 'Hidden', 'all' => 'All'];
const PALETTE_DIR_MEMBER_VIEWS = ['' => 'All', 'active' => 'Active', 'deactivated' => 'Deactivated', 'banned' => 'Banned'];
const PALETTE_DIR_MEMBER_ACTIONS = ['member_deactivate' => 'deactivated', 'member_ban' => 'banned', 'member_restore' => 'active'];

function palette_dir_log(mysqli $c, int $adminId, string $target, int $targetId, string $action, ?string $note): void
{
    $now = gmdate('Y-m-d H:i:s');
    $note = $note === null || $note === '' ? null : mb_substr($note, 0, 500);
    $st = $c->prepare('INSERT INTO palette_dir_log (admin_user_id, target, target_id, action, note, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $st->bind_param('isisss', $adminId, $target, $targetId, $action, $note, $now);
    $st->execute();
    $st->close();
}

// New plus reported items, shown as the Directory tab's badge.
function palette_dir_admin_badge(mysqli $c): string
{
    $n = (int)$c->query("SELECT COUNT(*) FROM palette_dir_items WHERE (reviewed_at IS NULL AND status = 'published') OR reports > 0")->fetch_row()[0];
    return $n > 0 ? ' <span class="badge">' . $n . '</span>' : '';
}

function palette_dir_admin_view(array $src): string
{
    $s = (string)($src['status'] ?? '');
    return isset(PALETTE_DIR_ADMIN_VIEWS[$s]) ? $s : 'new';
}

function palette_dir_admin_confirm(array $session, string $action, int $id, string $question, string $hint, string $button, bool $noteRequired, array $hidden, string $back): string
{
    $fields = '';
    foreach ($hidden as $k => $val) $fields .= '<input type="hidden" name="' . palette_h($k) . '" value="' . palette_h((string)$val) . '">';
    return '<div class="st-confirm" role="alertdialog" aria-label="' . palette_h($question) . '"><p><b>' . palette_h($question) . '</b> '
        . palette_h($hint) . '</p>' . palette_admin_form_open($session, $id, $action) . $fields
        . '<label class="fld"><span>Reason' . ($noteRequired ? '' : ' (optional)') . '</span><textarea name="note" rows="2" maxlength="500"'
        . ($noteRequired ? ' required' : '') . '></textarea></label><div class="actions"><a class="btn" href="' . palette_h($back) . '">Cancel</a>'
        . '<button class="btn btn-danger" type="submit">' . palette_h($button) . '</button></div></form></div>';
}

function palette_admin_tab_directory(mysqli $c, array $session, array $query): array
{
    $str = fn(string $k): string => is_string($query[$k] ?? null) ? trim($query[$k]) : '';
    $view = palette_dir_admin_view($query);
    $q = mb_substr($str('q'), 0, 190);
    $confirm = $str('confirm');
    $stat = fn(string $sql) => (int)$c->query($sql)->fetch_row()[0];
    $card = fn(string $label, string $value, bool $warn = false) => '<div class="stat"><span>' . $label . '</span><b' . ($warn ? ' class="bad"' : '') . '>' . $value . '</b></div>';
    $new = $stat("SELECT COUNT(*) FROM palette_dir_items WHERE reviewed_at IS NULL AND status = 'published'");
    $reported = $stat('SELECT COUNT(*) FROM palette_dir_items WHERE reports > 0');
    $stats = '<section class="stats" aria-label="Totals">' . $card('New, not checked', (string)$new) . $card('Reported', (string)$reported, $reported > 0)
        . $card('Hidden', (string)$stat("SELECT COUNT(*) FROM palette_dir_items WHERE status = 'hidden'"))
        . $card('Disk used', round($stat('SELECT COALESCE(SUM(bytes), 0) FROM palette_dir_items') / 1048576) . ' MB') . '</section>';

    $where = ['new' => "i.reviewed_at IS NULL AND i.status = 'published'", 'reported' => 'i.reports > 0', 'hidden' => "i.status = 'hidden'", 'all' => '1 = 1'][$view];
    $types = '';
    $args = [];
    if ($q !== '') {
        $where .= ' AND (i.title LIKE ? OR i.host LIKE ? OR u.email LIKE ? OR i.slug = ?)';
        $types = 'ssss';
        $args = [palette_admin_like($q), palette_admin_like($q), palette_admin_like($q), $q];
    }
    $st = $c->prepare("SELECT i.*, s.name AS style_name, u.email FROM palette_dir_items i JOIN palette_dir_styles s ON s.id = i.style_id
        LEFT JOIN users u ON u.id = i.user_id WHERE $where ORDER BY i.created_at DESC, i.id DESC LIMIT " . PALETTE_ADMIN_PER_PAGE);
    if ($types !== '') $st->bind_param($types, ...$args);
    $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $styles = [];
    foreach (palette_dir_styles($c, true) as $s) $styles[(string)$s['id']] = $s['name'];
    $back = palette_admin_url('directory', array_filter(['status' => $view, 'q' => $q]));
    $hidden = ['status' => $view];
    $rows = '';
    foreach ($items as $r) {
        $id = (int)$r['id'];
        $open = '<a class="btn btn-sm" href="' . palette_h(PALETTE_BASE . '/directory/' . $r['slug']) . '">Open</a>';
        $act = fn(string $action, string $label, string $cls = 'btn btn-sm') => palette_admin_form_open($session, $id, $action)
            . '<input type="hidden" name="status" value="' . $view . '"><button class="' . $cls . '" type="submit">' . $label . '</button></form>';
        $ask = fn(string $action, string $label) => '<a class="btn btn-sm btn-danger" href="'
            . palette_h(palette_admin_url('directory', array_filter(['status' => $view, 'q' => $q, 'confirm' => "$action-$id"]))) . '">' . $label . '</a>';
        $buttons = $open . ($r['reviewed_at'] === null && $r['status'] === 'published' ? $act('dir_reviewed', 'Looks fine', 'btn btn-sm btn-accent') : '')
            . ((int)$r['reports'] > 0 ? $act('dir_dismiss_reports', $r['hidden_by'] === 'reports' ? 'Dismiss and show' : 'Dismiss') : '')
            . ($r['status'] === 'published' ? $ask('hide', 'Hide') : $act('dir_unhide', 'Show')) . $ask('delete', 'Delete');
        $state = $r['status'] === 'published' ? '<span class="pill pill-ok">Visible</span>'
            : '<span class="pill pill-bad">' . ($r['hidden_by'] === 'reports' ? 'Hidden by reports' : 'Hidden') . '</span>';
        if ((int)$r['reports'] > 0) {
            $why = [];
            foreach ($c->query("SELECT reason, COUNT(*) n FROM palette_dir_reports WHERE item_id = $id AND status = 'open' GROUP BY reason") as $x) {
                $why[] = str_replace('_', ' ', $x['reason']) . ' · ' . $x['n'];
            }
            $state .= '<div class="hint"><b>' . (int)$r['reports'] . '</b> open: ' . palette_h(implode(', ', $why)) . '</div>';
        }
        if ($r['status'] !== 'published' && $r['status_note'] !== null) $state .= '<div class="hint">' . palette_h($r['status_note']) . '</div>';
        $style = palette_admin_form_open($session, $id, 'dir_style') . '<input type="hidden" name="status" value="' . $view . '"><div class="key-row">'
            . palette_admin_select('style', 'Style', $styles, (string)$r['style_id']) . '<button class="btn btn-sm" type="submit">Set</button></div></form>';
        $rows .= '<tr><td><div class="key-row"><img class="thumb" src="' . palette_h(palette_dir_shot_url($r['slug'])) . '" alt="" loading="lazy">'
            . '<div><b>' . palette_h($r['title']) . '</b><div class="muted mono">' . palette_h($r['host']) . '</div></div></div></td>'
            . '<td>' . palette_h($r['email'] !== null ? palette_mask_email((string)$r['email']) : '') . '<div class="muted">user ' . (int)$r['user_id'] . '</div></td>'
            . '<td><span class="pill pill-plain">' . ($r['source'] === 'extension' ? 'Extension' : 'Website') . '</span></td>'
            . '<td>' . $style . '</td><td>' . $state . '</td><td class="muted">' . palette_h(palette_admin_date($r['created_at'], 'j M, H:i')) . '</td>'
            . '<td class="end"><div class="actions">' . $buttons . '</div></td></tr>';
        foreach (['hide' => ['dir_hide', 'Hide "' . $r['title'] . '"?', 'The member sees this reason on Your submissions.', 'Hide', true],
                  'delete' => ['dir_delete', 'Delete "' . $r['title'] . '"?', 'The files are removed. This cannot be undone.', 'Delete', false]] as $k => [$action, $qn, $hint, $btn, $req]) {
            if ($confirm === "$k-$id") {
                $rows .= '<tr><td colspan="7">' . palette_dir_admin_confirm($session, $action, $id, $qn, $hint, $btn, $req, $hidden, $back) . '</td></tr>';
            }
        }
    }
    if ($rows === '') $rows = '<tr><td colspan="7" class="empty-cell">' . ($view === 'reported' ? 'No open reports.' : 'No items here.') . '</td></tr>';

    $seg = '';
    foreach (PALETTE_DIR_ADMIN_VIEWS as $k => $label) {
        $seg .= '<a class="btn btn-sm"' . ($k === $view ? ' aria-current="page"' : '') . ' href="' . palette_h(palette_admin_url('directory', ['status' => $k])) . '">' . $label . '</a>';
    }
    $form = '<form class="filters" method="get" action="' . PALETTE_ADMIN_PAGE . '"><input type="hidden" name="tab" value="directory">'
        . '<input type="hidden" name="status" value="' . $view . '"><label class="search">' . palette_icon('search', 16)
        . '<input type="search" name="q" value="' . palette_h($q) . '" aria-label="Search" placeholder="Site, title or email"></label>'
        . '<button class="btn" type="submit">Filter</button><nav class="key-row" aria-label="Lists">' . $seg . '</nav></form>';
    $table = '<div class="table-wrap"><table><thead><tr><th>Submission</th><th>Member</th><th>Source</th><th>Style</th><th>State</th><th>Sent</th>'
        . '<th><span class="sr-only">Actions</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    return ['title' => 'Directory', 'html' => $stats . '<section class="card">' . $form . $table . '</section>'];
}

function palette_admin_tab_members(mysqli $c, array $session, array $query): array
{
    $str = fn(string $k): string => is_string($query[$k] ?? null) ? trim($query[$k]) : '';
    $view = isset(PALETTE_DIR_MEMBER_VIEWS[$str('status')]) ? $str('status') : '';
    $q = mb_substr($str('q'), 0, 190);
    $confirm = $str('confirm');
    $where = '1 = 1';
    $types = '';
    $args = [];
    if ($view !== '') {
        $where .= " AND COALESCE(m.status, 'active') = ?";
        $types .= 's';
        $args[] = $view;
    }
    if ($q !== '') {
        $where .= ' AND (u.email LIKE ? OR i.user_id = ?)';
        $types .= 'si';
        array_push($args, palette_admin_like($q), ctype_digit($q) ? (int)$q : -1);
    }
    $st = $c->prepare("SELECT i.user_id, COUNT(*) AS items, SUM(i.status = 'hidden') AS hidden, MAX(i.created_at) AS last_at,
        COALESCE(m.status, 'active') AS mstatus, m.note, m.changed_at, m.changed_by, u.email
        FROM palette_dir_items i LEFT JOIN palette_dir_members m ON m.user_id = i.user_id LEFT JOIN users u ON u.id = i.user_id
        WHERE $where GROUP BY i.user_id, m.status, m.note, m.changed_at, m.changed_by, u.email ORDER BY last_at DESC LIMIT " . PALETTE_ADMIN_PER_PAGE);
    if ($types !== '') $st->bind_param($types, ...$args);
    $st->execute();
    $members = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $back = palette_admin_url('members', array_filter(['status' => $view, 'q' => $q]));
    $rows = '';
    foreach ($members as $m) {
        $uid = (int)$m['user_id'];
        $pill = ['active' => 'pill-ok', 'deactivated' => 'pill-warn', 'banned' => 'pill-bad'][$m['mstatus']];
        $ask = fn(string $k, string $label, string $cls) => '<a class="btn btn-sm ' . $cls . '" href="'
            . palette_h(palette_admin_url('members', array_filter(['status' => $view, 'q' => $q, 'confirm' => "$k-$uid"]))) . '">' . $label . '</a>';
        $buttons = '<a class="btn btn-sm" href="' . palette_h(palette_admin_url('directory', ['status' => 'all', 'q' => (string)($m['email'] ?? '')])) . '">Items</a>'
            . ($m['mstatus'] === 'active' ? $ask('member_deactivate', 'Deactivate', '') . $ask('member_ban', 'Ban', 'btn-danger') : $ask('member_restore', 'Restore', '')
                . ($m['mstatus'] === 'deactivated' ? $ask('member_ban', 'Ban', 'btn-danger') : ''));
        $note = $m['mstatus'] !== 'active' ? '<div class="hint">' . palette_h(palette_admin_date($m['changed_at'], 'j M') . ', by admin ' . (int)$m['changed_by']
            . ': ' . (string)$m['note']) . '</div>' : '';
        $rows .= '<tr><td>' . palette_h($m['email'] !== null ? palette_mask_email((string)$m['email']) : '') . '<div class="muted">user ' . $uid . '</div></td>'
            . '<td><span class="pill ' . $pill . '">' . ucfirst($m['mstatus']) . '</span>' . $note . '</td><td>' . (int)$m['items'] . '</td>'
            . '<td>' . (int)$m['hidden'] . '</td><td class="muted">' . palette_h(palette_admin_date($m['last_at'])) . '</td>'
            . '<td class="end"><div class="actions">' . $buttons . '</div></td></tr>';
        $texts = ['member_deactivate' => ['Deactivate user ' . $uid . '?', 'Their items are hidden and they cannot share until restored.', 'Deactivate'],
            'member_ban' => ['Ban user ' . $uid . '?', 'Their items are hidden, their extension is disconnected, and they cannot share again. Their companies account is not changed.', 'Ban'],
            'member_restore' => ['Restore user ' . $uid . '?', 'They can share again. Their items stay hidden until you show them.', 'Restore']];
        foreach ($texts as $k => [$qn, $hint, $btn]) {
            if ($confirm === "$k-$uid") {
                $rows .= '<tr><td colspan="6">' . palette_dir_admin_confirm($session, $k, $uid, $qn, $hint, $btn, true, ['status' => $view], $back) . '</td></tr>';
            }
        }
    }
    if ($rows === '') $rows = '<tr><td colspan="6" class="empty-cell">No members yet. People show here after their first share.</td></tr>';
    $form = '<form class="filters" method="get" action="' . PALETTE_ADMIN_PAGE . '"><input type="hidden" name="tab" value="members">'
        . '<label class="search">' . palette_icon('search', 16) . '<input type="search" name="q" value="' . palette_h($q) . '" aria-label="Search" placeholder="Email or user id"></label>'
        . palette_admin_select('status', 'Status', PALETTE_DIR_MEMBER_VIEWS, $view) . '<button class="btn" type="submit">Filter</button></form>';
    $table = '<div class="table-wrap"><table><thead><tr><th>Member</th><th>Status</th><th>Items</th><th>Hidden</th><th>Last upload</th>'
        . '<th><span class="sr-only">Actions</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    return ['title' => 'Members', 'html' => '<section class="card">' . $form . $table . '</section>'];
}

// ---- item actions

function palette_dir_admin_item(mysqli $c, array $session, array $post, string $action): array
{
    $back = ['tab' => 'directory', 'params' => ['status' => palette_dir_admin_view($post)]];
    $id = palette_admin_post_id($post);
    $item = $id > 0 ? palette_dir_item($c, 'id', $id) : null;
    if (!$item) return ['err' => 'dir_missing'] + $back;
    $note = trim((string)($post['note'] ?? ''));
    if (($action === 'dir_hide' && !palette_admin_note_ok($note)) || mb_strlen($note) > 500) {
        return ['err' => 'note_required', 'tab' => 'directory', 'params' => $back['params'] + ['confirm' => 'hide-' . $id]];
    }
    $now = gmdate('Y-m-d H:i:s');
    $ok = 'saved';
    switch ($action) {
        case 'dir_reviewed':
            $st = $c->prepare('UPDATE palette_dir_items SET reviewed_at = ? WHERE id = ?');
            $st->bind_param('si', $now, $id);
            $ok = 'dir_checked';
            break;
        case 'dir_hide':
            $st = $c->prepare("UPDATE palette_dir_items SET status = 'hidden', hidden_by = 'admin', status_note = ?, reviewed_at = ? WHERE id = ?");
            $st->bind_param('ssi', $note, $now, $id);
            $ok = 'dir_hidden';
            break;
        case 'dir_unhide':
            $st = $c->prepare("UPDATE palette_dir_items SET status = 'published', hidden_by = NULL, status_note = NULL, reviewed_at = ? WHERE id = ?");
            $st->bind_param('si', $now, $id);
            $ok = 'dir_shown';
            break;
        case 'dir_dismiss_reports':
            $c->query("UPDATE palette_dir_reports SET status = 'resolved' WHERE item_id = $id AND status = 'open'");
            $st = $c->prepare("UPDATE palette_dir_items SET reports = 0, reviewed_at = ?, status = IF(hidden_by = 'reports', 'published', status),
                hidden_by = IF(hidden_by = 'reports', NULL, hidden_by) WHERE id = ?");
            $st->bind_param('si', $now, $id);
            $ok = 'dir_dismissed';
            break;
        case 'dir_style':
            $style = (int)($post['style'] ?? 0);
            $names = array_column(palette_dir_styles($c, true), 'name', 'id');
            if (!isset($names[$style])) return ['err' => 'bad_style'] + $back;
            $st = $c->prepare('UPDATE palette_dir_items SET style_id = ? WHERE id = ?');
            $st->bind_param('ii', $style, $id);
            $note = 'style: ' . $names[$style];
            break;
        default: // dir_delete
            $deleted = palette_dir_delete($c, $item);
            palette_dir_log($c, (int)($session['user_id'] ?? 0), 'item', $id, $deleted ? 'dir_delete' : 'dir_delete_failed', $note);
            return ($deleted ? ['ok' => 'dir_deleted'] : ['err' => 'dir_files']) + $back;
    }
    $st->execute();
    $st->close();
    palette_dir_log($c, (int)($session['user_id'] ?? 0), 'item', $id, $action, $note);
    return ['ok' => $ok] + $back;
}

function palette_admin_do_dir_reviewed(mysqli $c, array $s, array $p): array { return palette_dir_admin_item($c, $s, $p, 'dir_reviewed'); }
function palette_admin_do_dir_hide(mysqli $c, array $s, array $p): array { return palette_dir_admin_item($c, $s, $p, 'dir_hide'); }
function palette_admin_do_dir_unhide(mysqli $c, array $s, array $p): array { return palette_dir_admin_item($c, $s, $p, 'dir_unhide'); }
function palette_admin_do_dir_delete(mysqli $c, array $s, array $p): array { return palette_dir_admin_item($c, $s, $p, 'dir_delete'); }
function palette_admin_do_dir_dismiss_reports(mysqli $c, array $s, array $p): array { return palette_dir_admin_item($c, $s, $p, 'dir_dismiss_reports'); }
function palette_admin_do_dir_style(mysqli $c, array $s, array $p): array { return palette_dir_admin_item($c, $s, $p, 'dir_style'); }

// ---- member actions: deactivate and ban hide the member's items; ban also revokes their extension tokens.

function palette_dir_admin_member(mysqli $c, array $session, array $post, string $action): array
{
    $uid = palette_admin_post_id($post);
    $view = (string)($post['status'] ?? '');
    $back = ['tab' => 'members', 'params' => isset(PALETTE_DIR_MEMBER_VIEWS[$view]) && $view !== '' ? ['status' => $view] : []];
    if ($uid < 1) return ['err' => 'dir_missing'] + $back;
    $note = trim((string)($post['note'] ?? ''));
    if (!palette_admin_note_ok($note)) return ['err' => 'note_required', 'tab' => 'members', 'params' => $back['params'] + ['confirm' => "$action-$uid"]];
    $status = PALETTE_DIR_MEMBER_ACTIONS[$action];
    $admin = (int)($session['user_id'] ?? 0);
    $now = gmdate('Y-m-d H:i:s');
    $st = $c->prepare('INSERT INTO palette_dir_members (user_id, status, note, changed_by, changed_at) VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note), changed_by = VALUES(changed_by), changed_at = VALUES(changed_at)');
    $st->bind_param('issis', $uid, $status, $note, $admin, $now);
    $st->execute();
    $st->close();
    if ($status !== 'active') {
        $st = $c->prepare("UPDATE palette_dir_items SET status = 'hidden', hidden_by = 'admin', status_note = ? WHERE user_id = ? AND status = 'published'");
        $st->bind_param('si', $note, $uid);
        $st->execute();
        $st->close();
    }
    if ($status === 'banned') {
        $st = $c->prepare('UPDATE palette_dir_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL');
        $st->bind_param('si', $now, $uid);
        $st->execute();
        $st->close();
    }
    palette_dir_log($c, $admin, 'member', $uid, $action, $note);
    return ['ok' => 'member_saved'] + $back;
}

function palette_admin_do_member_deactivate(mysqli $c, array $s, array $p): array { return palette_dir_admin_member($c, $s, $p, 'member_deactivate'); }
function palette_admin_do_member_ban(mysqli $c, array $s, array $p): array { return palette_dir_admin_member($c, $s, $p, 'member_ban'); }
function palette_admin_do_member_restore(mysqli $c, array $s, array $p): array { return palette_dir_admin_member($c, $s, $p, 'member_restore'); }

// ---- Settings: the report limit and the style list

function palette_dir_admin_settings_html(mysqli $c, array $session): string
{
    $rows = '';
    foreach (palette_dir_styles($c, true) as $s) {
        $id = (int)$s['id'];
        $rows .= '<div class="key-row"><input type="text" name="style_name[' . $id . ']" value="' . palette_h($s['name']) . '" maxlength="60" aria-label="Style name">'
            . '<label class="check"><input type="checkbox" name="style_active[' . $id . ']" value="1"' . ((int)$s['active'] === 1 ? ' checked' : '') . '> Offered</label>'
            . '<span class="muted mono">' . palette_h($s['slug']) . '</span></div>';
    }
    return '<section class="card card-pad" aria-labelledby="st-dir"><h2 id="st-dir">Token directory</h2>'
        . '<form class="stack" method="post" action="' . PALETTE_ADMIN_PAGE . '">' . palette_admin_csrf_field($session)
        . '<input type="hidden" name="action" value="dir_settings">'
        . '<label class="fld"><span>Hide an item after reports from this many members</span><input type="number" name="report_limit" min="1" max="20" value="'
        . (int)palette_settings($c)['dir_report_limit'] . '"></label>'
        . '<fieldset class="fld"><legend>Styles (retired styles keep their items)</legend>' . $rows
        . '<input type="text" name="style_new" maxlength="60" placeholder="New style name" aria-label="New style name"></fieldset>'
        . '<div class="actions"><button class="btn btn-accent" type="submit">Save directory settings</button></div></form></section>';
}

function palette_admin_do_dir_settings(mysqli $c, array $session, array $post): array
{
    $limit = (int)($post['report_limit'] ?? 0);
    if ($limit < 1 || $limit > 20) return ['err' => 'dir_limit', 'tab' => 'settings'];
    palette_settings_put($c, ['dir_report_limit' => $limit] + palette_settings($c));
    $names = is_array($post['style_name'] ?? null) ? $post['style_name'] : [];
    $active = is_array($post['style_active'] ?? null) ? $post['style_active'] : [];
    foreach (palette_dir_styles($c, true) as $s) {
        $id = (int)$s['id'];
        $name = trim((string)($names[$id] ?? ''));
        $name = $name === '' ? $s['name'] : mb_substr($name, 0, 60);
        $on = isset($active[$id]) ? 1 : 0;
        $st = $c->prepare('UPDATE palette_dir_styles SET name = ?, active = ? WHERE id = ?');
        $st->bind_param('sii', $name, $on, $id);
        $st->execute();
        $st->close();
    }
    $new = mb_substr(trim((string)($post['style_new'] ?? '')), 0, 60);
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($new)), '-');
    if ($slug !== '') {
        $st = $c->prepare('INSERT IGNORE INTO palette_dir_styles (slug, name, sort) SELECT ?, ?, COALESCE(MAX(sort), 0) + 1 FROM palette_dir_styles');
        $st->bind_param('ss', $slug, $new);
        $st->execute();
        $st->close();
    }
    palette_admin_log($c, (int)($session['user_id'] ?? 0), 'dir_settings', null, null, ['report_limit' => $limit, 'new_style' => $slug], null);
    return ['ok' => 'saved', 'tab' => 'settings'];
}
