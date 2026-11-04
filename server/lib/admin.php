<?php
// Palette admin (admin.php): one shell, one function per tab, one function per POST action. Everything here is a pure
// function of ($conn, $session, $query, $post, $method) so it is tested without HTTP. Admin session only
// ($_SESSION['user_role'] === 'admin', the companies login); every POST carries the 'palette_admin' CSRF token.
// Later tasks add a tab by writing palette_admin_tab_<name>() and listing it in PALETTE_ADMIN_TABS, and an action by
// writing palette_admin_do_<action>() and listing it in PALETTE_ADMIN_ACTIONS.

const PALETTE_ADMIN_PAGE = 'admin.php';
const PALETTE_ADMIN_PER_PAGE = 50;

// tab => [label shown in the nav ('' = not in the nav), handler]. 'licence' and 'issue' are reached from the list.
const PALETTE_ADMIN_TABS = [
    'licences' => ['Licences', 'palette_admin_tab_licences'],
    'payments' => ['Payments', 'palette_admin_tab_payments'],
    'sites'    => ['Sites', 'palette_admin_tab_sites'],
    'settings' => ['Settings', 'palette_admin_tab_settings'],
    'licence'  => ['', 'palette_admin_tab_licence'],
    'issue'    => ['', 'palette_admin_tab_issue'],
];

// POST action => handler(mysqli $c, array $session, array $post): array{ok?: string, err?: string, tab?: string, params?: array}.
// Empty until Tasks 9-11 add actions.
const PALETTE_ADMIN_ACTIONS = [];

// ---- access, CSRF, log

function palette_admin_is_admin(array $session): bool
{
    return ($session['user_role'] ?? '') === 'admin';
}

// The token the session holds for form 'palette_admin' ('' when none). Compared with hash_equals in palette_admin_csrf_ok.
function palette_admin_csrf_value(array $session): string
{
    $tok = $session['csrf_tokens']['palette_admin'] ?? null;
    return is_string($tok) && preg_match('/^[0-9a-f]{64}$/D', $tok) ? $tok : '';
}

// Created once per session and kept (a second open tab must not invalidate the first); admin.php calls this before
// building the request. Same session layout as the companies helpers (verifyCSRFToken reads the same slot).
function palette_admin_csrf_token(): string
{
    $tok = palette_admin_csrf_value($_SESSION ?? []);
    return $tok !== '' ? $tok : generateCSRFToken('palette_admin');
}

function palette_admin_csrf_ok(array $session, array $post): bool
{
    $want = palette_admin_csrf_value($session);
    $got = $post['csrf'] ?? null;
    return $want !== '' && is_string($got) && hash_equals($want, $got);
}

function palette_admin_log(mysqli $c, int $adminId, string $action, ?int $licenceId, ?string $orderRef, array $detail, ?string $note): void
{
    $json = $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null;
    $now = gmdate('Y-m-d H:i:s');
    $action = mb_substr($action, 0, 40);
    $orderRef = $orderRef === null ? null : mb_substr($orderRef, 0, 80);
    $note = $note === null ? null : mb_substr($note, 0, 500);
    $st = $c->prepare('INSERT INTO palette_admin_log (license_id, order_ref, admin_user_id, action, detail, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $st->bind_param('isissss', $licenceId, $orderRef, $adminId, $action, $json, $note, $now);
    $st->execute();
    $st->close();
}

// ---- data

// Counted as "active": not revoked, not refunded, not suspended or expired, and a live (not Stripe test) key.
const PALETTE_ADMIN_ACTIVE_SQL = "l.revoked_at IS NULL AND l.pay_status <> 'refunded' AND l.livemode = 1
    AND (l.sub_status IS NULL OR l.sub_status NOT IN ('suspended', 'expired'))";

function palette_admin_like(string $s): string
{
    return '%' . addcslashes($s, '\\%_') . '%';
}

// Filters: q, plan (pro|ai), status (active|suspended|revoked|refunded|test), source (stripe|paypal|admin). Unknown values are ignored.
// Returns ['rows' => [...], 'total' => int], 50 a page, newest first.
function palette_admin_licences(mysqli $c, array $filters, int $page): array
{
    $where = ['1 = 1'];
    $types = '';
    $args = [];
    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $q = mb_substr($q, 0, 190);
        $where[] = '(l.email LIKE ? OR l.license_key = ? OR l.order_id = ? OR l.subscription_id = ?
            OR EXISTS (SELECT 1 FROM palette_orders o WHERE o.license_id = l.id AND (o.stripe_session = ? OR o.stripe_payment_intent = ? OR o.order_id = ?))
            OR EXISTS (SELECT 1 FROM palette_subscriptions s WHERE s.license_id = l.id AND (s.stripe_session = ? OR s.subscription_id = ?)))';
        $types .= 'sssssssss';
        array_push($args, palette_admin_like($q), $q, $q, $q, $q, $q, $q, $q, $q);
    }
    $plan = $filters['plan'] ?? '';
    if (in_array($plan, ['pro', 'ai'], true)) { $where[] = 'l.plan = ?'; $types .= 's'; $args[] = $plan; }
    $source = $filters['source'] ?? '';
    if (in_array($source, ['stripe', 'paypal', 'admin'], true)) { $where[] = 'l.source = ?'; $types .= 's'; $args[] = $source; }
    switch ($filters['status'] ?? '') {
        case 'active':    $where[] = PALETTE_ADMIN_ACTIVE_SQL; break;
        case 'suspended': $where[] = "l.sub_status = 'suspended'"; break;
        case 'revoked':   $where[] = 'l.revoked_at IS NOT NULL'; break;
        case 'refunded':  $where[] = "l.pay_status = 'refunded'"; break;
        case 'test':      $where[] = 'l.livemode = 0'; break;
    }
    $w = implode(' AND ', $where);

    $st = $c->prepare("SELECT COUNT(*) FROM palette_licenses l WHERE $w");
    if ($types !== '') $st->bind_param($types, ...$args);
    $st->execute();
    $total = (int)$st->get_result()->fetch_row()[0];
    $st->close();

    $limit = PALETTE_ADMIN_PER_PAGE;
    $offset = (max(1, $page) - 1) * $limit;
    $st = $c->prepare("SELECT l.id, l.license_key, l.email, l.plan, l.source, l.pay_status, l.livemode, l.sub_status, l.paid_through, l.revoked_at, l.created_at
                       FROM palette_licenses l WHERE $w ORDER BY l.created_at DESC, l.id DESC LIMIT $limit OFFSET $offset");
    if ($types !== '') $st->bind_param($types, ...$args);
    $st->execute();
    $rows = [];
    foreach ($st->get_result() as $r) {
        $r['id'] = (int)$r['id'];
        $r['livemode'] = (int)$r['livemode'];
        $rows[] = $r;
    }
    $st->close();
    return ['rows' => $rows, 'total' => $total];
}

// ['pro' => active live Pro keys, 'ai' => active live Pro + AI keys, 'unfulfilled' => live orders paid with no key
// (captured without licence, or failed with a Stripe payment intent), 'revenue_month_cents' => live captured orders
// plus completed subscription payments since the 1st of this month UTC].
function palette_admin_totals(mysqli $c): array
{
    $out = ['pro' => 0, 'ai' => 0, 'unfulfilled' => 0, 'revenue_month_cents' => 0];
    foreach ($c->query('SELECT l.plan, COUNT(*) FROM palette_licenses l WHERE ' . PALETTE_ADMIN_ACTIVE_SQL . ' GROUP BY l.plan') as $r) {
        $out[$r['plan'] === 'ai' ? 'ai' : 'pro'] = (int)$r['COUNT(*)'];
    }
    $out['unfulfilled'] = (int)$c->query("SELECT COUNT(*) FROM palette_orders WHERE livemode = 1 AND provider <> 'admin'
        AND ((status = 'captured' AND license_id IS NULL) OR (status = 'failed' AND stripe_payment_intent IS NOT NULL AND stripe_payment_intent <> ''))")->fetch_row()[0];
    $since = gmdate('Y-m-01 00:00:00');
    $st = $c->prepare("SELECT COALESCE(SUM(ROUND(amount_usd * 100)), 0) FROM palette_orders WHERE status = 'captured' AND livemode = 1 AND provider <> 'admin' AND captured_at >= ?");
    $st->bind_param('s', $since);
    $st->execute();
    $cents = (int)$st->get_result()->fetch_row()[0];
    $st->close();
    $st = $c->prepare("SELECT COALESCE(SUM(ROUND(p.amount_usd * 100)), 0) FROM palette_sub_payments p
        WHERE p.status = 'completed' AND p.paid_at >= ?
          AND NOT EXISTS (SELECT 1 FROM palette_licenses l WHERE l.subscription_id = p.subscription_id AND l.livemode = 0)");
    $st->bind_param('s', $since);
    $st->execute();
    $cents += (int)$st->get_result()->fetch_row()[0];
    $st->close();
    $out['revenue_month_cents'] = $cents;
    return $out;
}

// ---- router

function palette_admin_headers(): array
{
    return ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex'] + palette_page_headers();
}

function palette_admin_url(string $tab, array $params = []): string
{
    return PALETTE_ADMIN_PAGE . '?' . http_build_query(['tab' => $tab] + $params);
}

// admin.php as a pure function: ['status', 'headers', 'body']. A successful POST answers 303 back to the tab with ?ok=
// (or ?err=). Order: admin check, then CSRF, then the action; a failed check changes nothing.
function palette_admin_page(mysqli $c, array $session, array $query, array $post, string $method): array
{
    $headers = palette_admin_headers();
    if (!palette_admin_is_admin($session)) return ['status' => 403, 'headers' => $headers, 'body' => 'Forbidden'];

    if ($method === 'POST') {
        if (!palette_admin_csrf_ok($session, $post)) return ['status' => 403, 'headers' => $headers, 'body' => 'Forbidden'];
        $action = is_string($post['action'] ?? null) ? $post['action'] : '';
        $handler = PALETTE_ADMIN_ACTIONS[$action] ?? null;
        if ($handler === null) return ['status' => 400, 'headers' => $headers, 'body' => 'Unknown action'];
        $res = $handler($c, $session, $post);
        $params = (array)($res['params'] ?? []);
        if (isset($res['err'])) $params['err'] = $res['err'];
        elseif (isset($res['ok'])) $params['ok'] = $res['ok'];
        return ['status' => 303, 'headers' => ['Location' => palette_admin_url((string)($res['tab'] ?? 'licences'), $params)] + $headers, 'body' => ''];
    }

    $tab = is_string($query['tab'] ?? null) ? $query['tab'] : 'licences';
    if (!isset(PALETTE_ADMIN_TABS[$tab])) {
        return ['status' => 404, 'headers' => $headers, 'body' => palette_admin_shell('licences', 'Not found', '<div class="empty">That admin page does not exist.</div>', $session, $query)];
    }
    $res = (PALETTE_ADMIN_TABS[$tab][1])($c, $session, $query);
    if (isset($res['response'])) return $res['response'];   // a tab may answer with its own response (CSV download)
    return ['status' => $res['status'] ?? 200, 'headers' => $headers,
            'body' => palette_admin_shell($tab, (string)($res['title'] ?? ''), (string)$res['html'], $session, $query)];
}

function palette_admin_flash(array $query): string
{
    $out = '';
    foreach (['ok' => 'flash-ok', 'err' => 'flash-err'] as $k => $cls) {
        if (is_string($query[$k] ?? null) && $query[$k] !== '') {
            $out .= '<p class="flash ' . $cls . '" role="status">' . palette_h(mb_substr($query[$k], 0, 300)) . '</p>';
        }
    }
    return $out;
}

// A hidden CSRF field for forms the tabs render (the token comes from the session array, never generated here).
function palette_admin_csrf_field(array $session): string
{
    return '<input type="hidden" name="csrf" value="' . palette_h(palette_admin_csrf_value($session)) . '">';
}

function palette_admin_shell(string $tab, string $title, string $inner, array $session, array $query): string
{
    $navTab = $tab === 'licence' || $tab === 'issue' ? 'licences' : $tab;
    $nav = '';
    foreach (PALETTE_ADMIN_TABS as $key => [$label]) {
        if ($label === '') continue;
        $cur = $key === $navTab ? ' aria-current="page"' : '';
        $nav .= '<a href="' . palette_h(palette_admin_url($key)) . '"' . $cur . '>' . palette_h($label) . '</a>';
    }
    $h1 = $title !== '' ? $title : (PALETTE_ADMIN_TABS[$navTab][0] ?: 'Licences');
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light dark"><meta name="robots" content="noindex">'
        . '<title>' . palette_h('Admin: ' . $h1) . '</title>'
        . '<link rel="stylesheet" href="' . palette_h(palette_asset('admin.css')) . '"></head><body class="admin">'
        . '<div class="adm"><header class="adm-head"><span class="mark" aria-hidden="true"></span><h1>' . palette_h($h1) . '</h1>'
        . '<nav class="adm-nav" aria-label="Admin sections">' . $nav . '</nav><span class="grow"></span>'
        . '<a class="btn btn-accent" href="' . palette_h(palette_admin_url('issue')) . '">' . palette_icon('plus', 16) . '<span>Issue a key</span></a></header>'
        . palette_admin_flash($query) . $inner . '</div></body></html>';
}

// ---- tabs (each returns ['html' => inner HTML, 'title'? , 'status'? ] or ['response' => full response])

function palette_admin_placeholder(string $title): array
{
    return ['title' => $title, 'html' => '<section class="card"><div class="empty">' . palette_icon('info', 16) . '<span>Coming in a later task.</span></div></section>'];
}

function palette_admin_tab_payments(mysqli $c, array $session, array $query): array { return palette_admin_placeholder('Payments'); }
function palette_admin_tab_settings(mysqli $c, array $session, array $query): array { return palette_admin_placeholder('Settings'); }
function palette_admin_tab_licence(mysqli $c, array $session, array $query): array { return palette_admin_placeholder('Licence'); }
function palette_admin_tab_issue(mysqli $c, array $session, array $query): array { return palette_admin_placeholder('Issue a key'); }

function palette_admin_tab_sites(mysqli $c, array $session, array $query): array
{
    $sites = palette_sites_top($c);
    if (($query['format'] ?? '') === 'csv') {
        return ['response' => palette_sites_csv_response($sites) + ['status' => 200]];
    }
    return ['title' => 'Sites', 'html' => '<section class="card card-pad">' . palette_sites_table_html($sites, palette_admin_url('sites', ['format' => 'csv'])) . '</section>'];
}

function palette_admin_date(?string $dt, string $fmt = 'j M Y'): string
{
    $ts = $dt ? strtotime($dt . ' UTC') : false;
    return $ts ? gmdate($fmt, $ts) : '';
}

// [paid label, paid pill class, access label, access pill class] for one list row.
function palette_admin_row_state(array $r): array
{
    $suspended = $r['sub_status'] === 'suspended';
    $paid = match ($r['pay_status']) {
        'manual_paid' => ['Marked paid', 'pill-ok'],
        'comp'        => ['Free (comp)', 'pill-plain'],
        'refunded'    => ['Refunded', 'pill-plain'],
        default       => $suspended ? ['Payment failed', 'pill-warn'] : ($r['sub_status'] === 'cancelled' ? ['Cancelled', 'pill-plain'] : ['Paid', 'pill-ok']),
    };
    $until = palette_admin_date($r['paid_through'], 'j M');
    if ($r['revoked_at'] !== null) $access = ['Revoked', 'pill-bad'];
    elseif ($r['sub_status'] === 'expired') $access = ['Expired', 'pill-bad'];
    elseif ($suspended) $access = [$until !== '' ? 'Grace to ' . $until : 'Suspended', 'pill-warn'];
    elseif ($r['paid_through'] !== null && in_array($r['sub_status'], ['active', 'cancelled'], true)) $access = ['Active to ' . $until, 'pill-ok'];
    else $access = ['Active', 'pill-ok'];
    return [$paid[0], $paid[1], $access[0], $access[1]];
}

const PALETTE_ADMIN_SOURCES = ['stripe' => 'Stripe', 'paypal' => 'PayPal', 'admin' => 'Issued by admin'];

function palette_admin_select(string $name, string $label, array $options, string $current): string
{
    $o = '';
    foreach ($options as $value => $text) {
        $o .= '<option value="' . palette_h((string)$value) . '"' . ((string)$value === $current ? ' selected' : '') . '>' . palette_h($text) . '</option>';
    }
    return '<select name="' . $name . '" aria-label="' . palette_h($label) . '">' . $o . '</select>';
}

function palette_admin_tab_licences(mysqli $c, array $session, array $query): array
{
    $str = fn($k) => is_string($query[$k] ?? null) ? $query[$k] : '';
    $filters = ['q' => trim($str('q')), 'plan' => $str('plan'), 'status' => $str('status'), 'source' => $str('source')];
    $page = max(1, (int)$str('page'));
    $res = palette_admin_licences($c, $filters, $page);
    $totals = palette_admin_totals($c);

    $card = fn(string $label, string $value, string $cls = '') => '<div class="stat"><span>' . palette_h($label) . '</span><b' . ($cls ? ' class="' . $cls . '"' : '') . '>' . palette_h($value) . '</b></div>';
    $stats = '<section class="stats" aria-label="Totals">' . $card('Active Pro', (string)$totals['pro']) . $card('Active Pro + AI', (string)$totals['ai'])
        . $card('Paid, no key yet', (string)$totals['unfulfilled'], $totals['unfulfilled'] > 0 ? 'bad' : '')
        . $card('Revenue this month', '$' . palette_money($totals['revenue_month_cents'])) . '</section>';

    $form = '<form class="filters" method="get" action="' . PALETTE_ADMIN_PAGE . '"><input type="hidden" name="tab" value="licences">'
        . '<label class="search">' . palette_icon('search', 16) . '<input type="search" name="q" value="' . palette_h($filters['q']) . '" aria-label="Search"'
        . ' placeholder="Search email, key, order, Stripe or PayPal id"></label>'
        . palette_admin_select('plan', 'Plan', ['' => 'All plans', 'pro' => 'Pro', 'ai' => 'Pro + AI'], $filters['plan'])
        . palette_admin_select('status', 'Status', ['' => 'All statuses', 'active' => 'Active', 'suspended' => 'Past due', 'revoked' => 'Revoked', 'refunded' => 'Refunded', 'test' => 'Test'], $filters['status'])
        . palette_admin_select('source', 'Source', ['' => 'All sources'] + PALETTE_ADMIN_SOURCES, $filters['source'])
        . '<button class="btn" type="submit">Filter</button></form>';

    $body = '';
    foreach ($res['rows'] as $r) {
        [$paid, $paidCls, $access, $accessCls] = palette_admin_row_state($r);
        $test = $r['livemode'] === 0 ? ' <span class="badge badge-test">Test</span>' : '';
        $body .= '<tr><td class="mono">' . palette_h($r['license_key']) . $test . '</td><td>' . palette_h($r['email']) . '</td>'
            . '<td>' . ($r['plan'] === 'ai' ? 'Pro + AI' : 'Pro') . '</td><td class="muted">' . palette_h(PALETTE_ADMIN_SOURCES[$r['source']] ?? '') . '</td>'
            . '<td><span class="pill ' . $paidCls . '">' . palette_h($paid) . '</span></td><td><span class="pill ' . $accessCls . '">' . palette_h($access) . '</span></td>'
            . '<td class="muted">' . palette_h(palette_admin_date($r['created_at'])) . '</td>'
            . '<td class="end"><a class="btn btn-sm" href="' . palette_h(palette_admin_url('licence', ['id' => $r['id']])) . '">Open</a></td></tr>';
    }
    if ($body === '') $body = '<tr><td colspan="8" class="empty-cell">No licences match.</td></tr>';

    $pages = max(1, (int)ceil($res['total'] / PALETTE_ADMIN_PER_PAGE));
    $keep = array_filter($filters, fn($v) => $v !== '');
    $link = fn(int $p, string $label) => '<a class="btn btn-sm" href="' . palette_h(palette_admin_url('licences', $keep + ['page' => $p])) . '">' . $label . '</a>';
    $pager = '<nav class="pager" aria-label="Pages"><span class="muted">' . $res['total'] . ' licence' . ($res['total'] === 1 ? '' : 's') . ', page ' . min($page, $pages) . ' of ' . $pages . '</span>'
        . ($page > 1 ? $link($page - 1, 'Previous') : '') . ($page < $pages ? $link($page + 1, 'Next') : '') . '</nav>';

    $table = '<div class="table-wrap"><table><thead><tr><th>Key</th><th>Email</th><th>Plan</th><th>Source</th><th>Paid</th><th>Access</th><th>Created</th>'
        . '<th><span class="sr-only">Actions</span></th></tr></thead><tbody>' . $body . '</tbody></table></div>';
    return ['title' => 'Licences', 'html' => $stats . '<section class="card">' . $form . $table . $pager . '</section>'];
}
