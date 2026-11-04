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
const PALETTE_ADMIN_ACTIONS = [
    'change'       => 'palette_admin_do_change',
    'resend'       => 'palette_admin_do_resend',
    'cancel'       => 'palette_admin_do_cancel',
    'billing_link' => 'palette_admin_do_billing_link',
];

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

// ?ok=<code> / ?err=<code> -> fixed English text. A code not listed here shows nothing (free text is never echoed).
const PALETTE_ADMIN_MESSAGES = [
    'ok' => [
        'saved'        => 'Changes saved.',
        'nochange'     => 'Nothing to change: the licence already has that state.',
        'resent'       => 'The key email was sent.',
        'cancelled'    => 'The subscription will end at the end of the paid period.',
        'billing_sent' => 'The billing link was emailed to the customer.',
    ],
    'err' => [
        'note_required' => 'Write a reason (1 to 500 characters).',
        'not_found'     => 'That licence was not found.',
        'bad_status'    => 'That payment state is not valid.',
        'bad_plan'      => 'That plan is not valid.',
        'until_pro'     => 'An access date applies to Pro + AI licences only.',
        'until_bad'     => 'Enter the date as YYYY-MM-DD.',
        'not_stripe'    => 'This licence has no Stripe subscription.',
        'no_subscription' => 'This licence has no subscription.',
        'stripe'        => 'Stripe could not do that. Try again in a moment.',
        'paypal'        => 'PayPal could not cancel the subscription. Try again in a moment.',
        'mail'          => 'The email could not be sent.',
    ],
];

function palette_admin_flash(array $query): string
{
    $out = '';
    foreach (['ok' => 'flash-ok', 'err' => 'flash-err'] as $k => $cls) {
        $code = $query[$k] ?? null;
        $msg = is_string($code) ? (PALETTE_ADMIN_MESSAGES[$k][$code] ?? null) : null;
        if ($msg !== null) $out .= '<p class="flash ' . $cls . '" role="status">' . palette_h($msg) . '</p>';
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

// ---- licence actions
// Every function returns ['ok' => bool, 'error' => ?string] (+ 'nochange' => true when the licence already had that state:
// nothing is written, mailed or logged). Change functions need a reason (1-500 characters) and write ONE palette_admin_log
// row whose detail holds before/after of the fields that changed (never emails, keys or secrets). A compare-and-swap
// UPDATE decides, so a double submit or two admins at once log one change. $notify sends the customer one short email in
// the licence's language (em_admin_change_*); a mail failure never undoes the change.

function palette_admin_licence_row(mysqli $c, int $id): ?array
{
    $st = $c->prepare('SELECT * FROM palette_licenses WHERE id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

// The subscription row of a licence with the licence fields palette_sub_cancel_row needs, or null.
function palette_admin_sub_row(mysqli $c, int $licenceId): ?array
{
    $st = $c->prepare('SELECT s.*, l.user_id, l.paid_through, l.revoked_at FROM palette_licenses l JOIN palette_subscriptions s ON s.license_id = l.id
                       WHERE l.id = ? AND s.subscription_id IS NOT NULL ORDER BY s.id DESC LIMIT 1');
    $st->bind_param('i', $licenceId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

function palette_admin_note_ok(string $note): bool
{
    $n = mb_strlen(trim($note));
    return $n >= 1 && $n <= 500;
}

function palette_admin_notify(array $lic, string $kind, array $vars = []): bool
{
    $lang = palette_lang((string)$lic['lang']);
    $html = '<h1 style="font-size:20px;margin:0 0 12px">' . palette_h(palette_t($lang, 'em_admin_change_subject')) . '</h1>'
        . '<p style="margin:0 0 8px">' . palette_h(palette_t($lang, 'em_admin_change_body_' . $kind, $vars)) . '</p>'
        . palette_email_footer($lang);
    $ok = palette_mail((string)$lic['email'], palette_t($lang, 'em_admin_change_subject'), palette_email_shell($lang, $html));
    if (!$ok) error_log('PALETTE_MAIL_FAILED admin_change licence=' . (int)$lic['id']);
    return $ok;
}

// Shared tail of the four change functions: $applied is the result of the compare-and-swap UPDATE.
function palette_admin_changed(mysqli $c, int $adminId, array $lic, string $action, bool $applied, array $before, array $after, string $note, bool $notify, array $vars = []): array
{
    if (!$applied) return ['ok' => true, 'error' => null, 'nochange' => true];
    palette_admin_log($c, $adminId, $action, (int)$lic['id'], null, ['before' => $before, 'after' => $after], trim($note));
    if ($notify) palette_admin_notify($lic, $action, $vars);
    return ['ok' => true, 'error' => null];
}

function palette_admin_set_access(mysqli $c, int $adminId, int $id, bool $active, string $note, bool $notify): array
{
    if (!palette_admin_note_ok($note)) return ['ok' => false, 'error' => 'note_required'];
    $lic = palette_admin_licence_row($c, $id);
    if (!$lic) return ['ok' => false, 'error' => 'not_found'];
    $was = $lic['revoked_at'] !== null;
    if ($was === !$active) return ['ok' => true, 'error' => null, 'nochange' => true];
    $sql = $active ? 'UPDATE palette_licenses SET revoked_at = NULL WHERE id = ? AND revoked_at IS NOT NULL'
                   : 'UPDATE palette_licenses SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL';
    $st = $c->prepare($sql);
    $st->bind_param('i', $id);
    $st->execute();
    $applied = $st->affected_rows === 1;
    $st->close();
    return palette_admin_changed($c, $adminId, $lic, 'access', $applied, ['revoked' => $was], ['revoked' => !$active], $note, $notify);
}

// $payStatus: provider|manual_paid|comp|refunded. Records only (the money moves in Stripe/PayPal); 'refunded' also revokes.
function palette_admin_set_payment(mysqli $c, int $adminId, int $id, string $payStatus, string $note, bool $notify): array
{
    if (!palette_admin_note_ok($note)) return ['ok' => false, 'error' => 'note_required'];
    if (!in_array($payStatus, ['provider', 'manual_paid', 'comp', 'refunded'], true)) return ['ok' => false, 'error' => 'bad_status'];
    $lic = palette_admin_licence_row($c, $id);
    if (!$lic) return ['ok' => false, 'error' => 'not_found'];
    if ($lic['pay_status'] === $payStatus) return ['ok' => true, 'error' => null, 'nochange' => true];
    $revoke = $payStatus === 'refunded';
    $st = $c->prepare('UPDATE palette_licenses SET pay_status = ?, revoked_at = IF(?, COALESCE(revoked_at, NOW()), revoked_at) WHERE id = ? AND pay_status = ?');
    $flag = $revoke ? 1 : 0;
    $st->bind_param('siis', $payStatus, $flag, $id, $lic['pay_status']);
    $st->execute();
    $applied = $st->affected_rows === 1;
    $st->close();
    $wasRevoked = $lic['revoked_at'] !== null;
    return palette_admin_changed($c, $adminId, $lic, 'payment', $applied,
        ['pay_status' => $lic['pay_status'], 'revoked' => $wasRevoked], ['pay_status' => $payStatus, 'revoked' => $wasRevoked || $revoke], $note, $notify);
}

// Pro + AI only: paid_through = that day 23:59:59 (UTC), and the status goes back to 'active' when that moment is ahead.
function palette_admin_set_until(mysqli $c, int $adminId, int $id, string $dateYmd, string $note, bool $notify): array
{
    if (!palette_admin_note_ok($note)) return ['ok' => false, 'error' => 'note_required'];
    $lic = palette_admin_licence_row($c, $id);
    if (!$lic) return ['ok' => false, 'error' => 'not_found'];
    if ($lic['plan'] !== 'ai') return ['ok' => false, 'error' => 'until_pro'];
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $dateYmd, new DateTimeZone('UTC'));
    if (!$d || $d->format('Y-m-d') !== $dateYmd) return ['ok' => false, 'error' => 'until_bad'];
    $to = $dateYmd . ' 23:59:59';
    $future = strtotime($to . ' UTC') > time();
    $newStatus = $future ? 'active' : $lic['sub_status'];
    if ($lic['paid_through'] === $to && $lic['sub_status'] === $newStatus) return ['ok' => true, 'error' => null, 'nochange' => true];
    $st = $c->prepare('UPDATE palette_licenses SET paid_through = ?, sub_status = ? WHERE id = ? AND plan = \'ai\' AND paid_through <=> ? AND sub_status <=> ?');
    $st->bind_param('ssiss', $to, $newStatus, $id, $lic['paid_through'], $lic['sub_status']);
    $st->execute();
    $applied = $st->affected_rows === 1;
    $st->close();
    return palette_admin_changed($c, $adminId, $lic, 'until', $applied,
        ['paid_through' => $lic['paid_through'], 'sub_status' => $lic['sub_status']], ['paid_through' => $to, 'sub_status' => $newStatus], $note, $notify, ['date' => $dateYmd]);
}

// Changing the plan edits the licence only: it does not touch a Stripe or PayPal subscription (cancel that separately).
function palette_admin_set_plan(mysqli $c, int $adminId, int $id, string $plan, string $note, bool $notify): array
{
    if (!palette_admin_note_ok($note)) return ['ok' => false, 'error' => 'note_required'];
    if (!in_array($plan, ['pro', 'ai'], true)) return ['ok' => false, 'error' => 'bad_plan'];
    $lic = palette_admin_licence_row($c, $id);
    if (!$lic) return ['ok' => false, 'error' => 'not_found'];
    if ($lic['plan'] === $plan) return ['ok' => true, 'error' => null, 'nochange' => true];
    $st = $c->prepare('UPDATE palette_licenses SET plan = ? WHERE id = ? AND plan = ?');
    $st->bind_param('sis', $plan, $id, $lic['plan']);
    $st->execute();
    $applied = $st->affected_rows === 1;
    $st->close();
    return palette_admin_changed($c, $adminId, $lic, 'plan', $applied, ['plan' => $lic['plan']], ['plan' => $plan], $note, $notify, ['plan' => $plan === 'ai' ? 'Pro + AI' : 'Pro']);
}

function palette_admin_resend(mysqli $c, int $adminId, int $id): array
{
    $lic = palette_admin_licence_row($c, $id);
    if (!$lic) return ['ok' => false, 'error' => 'not_found'];
    $m = palette_license_email((string)$lic['license_key'], (string)$lic['lang']);
    if (!palette_mail((string)$lic['email'], $m['subject'], $m['html'])) {
        error_log('PALETTE_MAIL_FAILED admin_resend licence=' . $id);
        return ['ok' => false, 'error' => 'mail'];
    }
    palette_admin_log($c, $adminId, 'resend', $id, null, ['sent' => true], null);
    return ['ok' => true, 'error' => null];
}

// Admin bypasses the owner check (palette_sub_cancel_row). Stripe: cancel at period end; PayPal: cancelled at once.
// The customer gets the usual cancellation email from palette_sub_cancel_row.
function palette_admin_cancel(mysqli $c, int $adminId, int $id): array
{
    $lic = palette_admin_licence_row($c, $id);
    if (!$lic) return ['ok' => false, 'error' => 'not_found'];
    $row = palette_admin_sub_row($c, $id);
    if (!$row) return ['ok' => false, 'error' => 'no_subscription'];
    if (in_array($row['status'], ['cancelled', 'expired'], true)) return ['ok' => true, 'error' => null, 'nochange' => true];
    $r = palette_sub_cancel_row($c, $row);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'] === 'err_paypal' ? 'paypal' : 'stripe'];
    $after = palette_admin_licence_row($c, $id);
    palette_admin_log($c, $adminId, 'cancel', $id, null,
        ['before' => ['sub_status' => $lic['sub_status']], 'after' => ['sub_status' => $after['sub_status'] ?? null]], null);
    return ['ok' => true, 'error' => null];
}

// Stripe subscribers only. The portal URL goes to the customer's email and nowhere else: not to the admin, not to the log.
function palette_admin_billing_link(mysqli $c, int $adminId, int $id): array
{
    $lic = palette_admin_licence_row($c, $id);
    if (!$lic) return ['ok' => false, 'error' => 'not_found'];
    $row = palette_admin_sub_row($c, $id);
    if (!$row || ($row['provider'] ?? '') !== 'stripe') return ['ok' => false, 'error' => 'not_stripe'];
    $r = palette_st_portal_for_row($c, $row);
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'] === 'err_invalid_key' ? 'not_stripe' : 'stripe'];
    $lang = palette_lang((string)$lic['lang']);
    $url = (string)$r['url'];
    $html = '<h1 style="font-size:20px;margin:0 0 12px">' . palette_h(palette_t($lang, 'em_billing_link_title')) . '</h1>'
        . '<p style="margin:0 0 16px">' . palette_h(palette_t($lang, 'em_billing_link_body')) . '</p>'
        . '<p style="margin:0 0 8px"><a href="' . palette_h($url) . '" style="display:inline-block;padding:10px 18px;background:#3b4bd8;color:#ffffff;'
        . 'border-radius:6px;text-decoration:none;font-weight:bold">' . palette_h(palette_t($lang, 'em_billing_link_button')) . '</a></p>'
        . palette_email_footer($lang);
    if (!palette_mail((string)$lic['email'], palette_t($lang, 'em_billing_link_subject'), palette_email_shell($lang, $html))) {
        error_log('PALETTE_MAIL_FAILED admin_billing_link licence=' . $id);
        return ['ok' => false, 'error' => 'mail'];
    }
    palette_admin_log($c, $adminId, 'billing_link', $id, null, ['sent' => true], null);
    return ['ok' => true, 'error' => null];
}

// ---- licence actions: POST handlers (admin + CSRF already checked by the router)

function palette_admin_post_id(array $post): int
{
    return is_string($post['id'] ?? null) && ctype_digit($post['id']) ? (int)$post['id'] : 0;
}

// Redirect target for an action result: back to the licence page (or the list when the licence does not exist).
function palette_admin_result(array $res, int $id, string $okCode): array
{
    $gone = !$res['ok'] && $res['error'] === 'not_found';
    $where = $gone || $id <= 0 ? ['tab' => 'licences', 'params' => []] : ['tab' => 'licence', 'params' => ['id' => $id]];
    if (!$res['ok']) return ['err' => (string)$res['error']] + $where;
    return ['ok' => !empty($res['nochange']) ? 'nochange' : $okCode] + $where;
}

// The "Change this licence" form: applies only the fields that differ from the stored state, one function each (and one
// log row each), plan first so a Pro -> Pro + AI switch can take an access date in the same save.
function palette_admin_do_change(mysqli $c, array $session, array $post): array
{
    $id = palette_admin_post_id($post);
    $lic = $id > 0 ? palette_admin_licence_row($c, $id) : null;
    if (!$lic) return ['err' => 'not_found'];
    $adminId = (int)($session['user_id'] ?? 0);
    $str = fn(string $k) => is_string($post[$k] ?? null) ? trim($post[$k]) : '';
    $note = $str('note');
    $notify = $str('notify') !== '';
    $steps = [];
    $plan = $str('plan');
    if ($plan !== '' && $plan !== $lic['plan']) $steps[] = fn() => palette_admin_set_plan($c, $adminId, $id, $plan, $note, $notify);
    $access = $str('access');
    if (in_array($access, ['active', 'revoked'], true) && ($access === 'revoked') !== ($lic['revoked_at'] !== null)) {
        $steps[] = fn() => palette_admin_set_access($c, $adminId, $id, $access === 'active', $note, $notify);
    }
    $pay = $str('pay_status');
    if ($pay !== '' && $pay !== $lic['pay_status']) $steps[] = fn() => palette_admin_set_payment($c, $adminId, $id, $pay, $note, $notify);
    $until = $str('until');
    $curUntil = $lic['paid_through'] === null ? '' : substr((string)$lic['paid_through'], 0, 10);
    if ($until !== '' && $until !== $curUntil) $steps[] = fn() => palette_admin_set_until($c, $adminId, $id, $until, $note, $notify);
    if (!$steps) return palette_admin_result(['ok' => true, 'nochange' => true], $id, 'saved');
    if (!palette_admin_note_ok($note)) return palette_admin_result(['ok' => false, 'error' => 'note_required'], $id, 'saved');
    $res = ['ok' => true, 'error' => null, 'nochange' => true];
    foreach ($steps as $step) {
        $r = $step();
        if (!$r['ok']) return palette_admin_result($r, $id, 'saved');
        if (empty($r['nochange'])) $res = ['ok' => true, 'error' => null];
    }
    return palette_admin_result($res, $id, 'saved');
}

function palette_admin_do_resend(mysqli $c, array $session, array $post): array
{
    $id = palette_admin_post_id($post);
    return palette_admin_result(palette_admin_resend($c, (int)($session['user_id'] ?? 0), $id), $id, 'resent');
}

function palette_admin_do_cancel(mysqli $c, array $session, array $post): array
{
    $id = palette_admin_post_id($post);
    return palette_admin_result(palette_admin_cancel($c, (int)($session['user_id'] ?? 0), $id), $id, 'cancelled');
}

function palette_admin_do_billing_link(mysqli $c, array $session, array $post): array
{
    $id = palette_admin_post_id($post);
    return palette_admin_result(palette_admin_billing_link($c, (int)($session['user_id'] ?? 0), $id), $id, 'billing_sent');
}

// ---- licence page

const PALETTE_ADMIN_PAY_LABELS = ['provider' => 'As the payment provider reports', 'manual_paid' => 'Marked paid by hand', 'comp' => 'Free (comp)', 'refunded' => 'Refunded'];

// A long provider id as its first 8 and last 4 characters.
function palette_admin_short(string $s): string
{
    return mb_strlen($s) > 16 ? mb_substr($s, 0, 8) . '…' . mb_substr($s, -4) : $s;
}

function palette_admin_usd(string|float $amount): string
{
    return '$' . number_format((float)$amount, 2, '.', ',');
}

// Payments of one licence, newest first: [at, amount, result, ref] (orders by licence, subscription payments by subscription id).
function palette_admin_payments(mysqli $c, array $lic): array
{
    $out = [];
    $id = (int)$lic['id'];
    $st = $c->prepare('SELECT order_id, stripe_payment_intent, capture_id, amount_usd, status, created_at, captured_at FROM palette_orders WHERE license_id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    foreach ($st->get_result() as $o) {
        $ref = (string)($o['stripe_payment_intent'] ?: ($o['capture_id'] ?: $o['order_id']));
        $out[] = ['at' => (string)($o['captured_at'] ?? $o['created_at']), 'amount' => palette_admin_usd($o['amount_usd']),
                  'result' => ['captured' => 'Paid', 'failed' => 'Failed', 'created' => 'Not paid'][$o['status']] ?? $o['status'], 'ref' => $ref];
    }
    $st->close();
    if ($lic['subscription_id'] !== null && $lic['subscription_id'] !== '') {
        $st = $c->prepare('SELECT sale_id, amount_usd, status, paid_at FROM palette_sub_payments WHERE subscription_id = ?');
        $sid = (string)$lic['subscription_id'];
        $st->bind_param('s', $sid);
        $st->execute();
        foreach ($st->get_result() as $p) {
            $out[] = ['at' => (string)$p['paid_at'], 'amount' => palette_admin_usd($p['amount_usd']),
                      'result' => ['completed' => 'Paid', 'refunded' => 'Refunded', 'reversed' => 'Reversed'][$p['status']] ?? $p['status'], 'ref' => (string)$p['sale_id']];
        }
        $st->close();
    }
    usort($out, fn($a, $b) => strcmp($b['at'], $a['at']));
    return $out;
}

// One history sentence for an admin log row (English).
function palette_admin_log_text(array $r): string
{
    $d = json_decode((string)($r['detail'] ?? ''), true);
    $after = is_array($d) ? (array)($d['after'] ?? []) : [];
    return match ($r['action']) {
        'access'       => !empty($after['revoked']) ? 'Revoked access' : 'Restored access',
        'payment'      => 'Set payment to "' . (PALETTE_ADMIN_PAY_LABELS[$after['pay_status'] ?? ''] ?? '') . '"'
                          . (!empty($after['revoked']) && ($after['pay_status'] ?? '') === 'refunded' ? ' and revoked access' : ''),
        'until'        => 'Set Pro + AI access until ' . substr((string)($after['paid_through'] ?? ''), 0, 10),
        'plan'         => 'Changed the plan to ' . (($after['plan'] ?? '') === 'ai' ? 'Pro + AI' : 'Pro'),
        'resend'       => 'Resent the key email',
        'cancel'       => 'Cancelled the subscription',
        'billing_link' => 'Sent a billing link',
        default        => ucfirst(str_replace('_', ' ', (string)$r['action'])),
    };
}

// Newest first: [at, html]. Admin actions (with their reason), payments, and the key being issued.
function palette_admin_history(mysqli $c, array $lic, array $payments): array
{
    $items = [];
    $id = (int)$lic['id'];
    $st = $c->prepare('SELECT action, detail, note, admin_user_id, created_at FROM palette_admin_log WHERE license_id = ? ORDER BY id DESC LIMIT 200');
    $st->bind_param('i', $id);
    $st->execute();
    foreach ($st->get_result() as $r) {
        $html = '<b>Admin ' . (int)$r['admin_user_id'] . '</b> ' . palette_h(lcfirst(palette_admin_log_text($r))) . '.';
        if ($r['note'] !== null && $r['note'] !== '') $html .= ' <span class="muted">Reason: ' . palette_h($r['note']) . '</span>';
        $items[] = ['at' => (string)$r['created_at'], 'html' => $html];
    }
    $st->close();
    foreach ($payments as $p) {
        $items[] = ['at' => $p['at'], 'html' => palette_h(($lic['source'] === 'paypal' ? 'PayPal' : 'Stripe') . ': ' . $p['amount'] . ' ' . strtolower($p['result']) . '.')];
    }
    $items[] = ['at' => (string)$lic['created_at'], 'html' => 'Key issued.'];
    usort($items, fn($a, $b) => strcmp($b['at'], $a['at']));
    return $items;
}

function palette_admin_form_open(array $session, int $id, string $action): string
{
    return '<form method="post" action="' . PALETTE_ADMIN_PAGE . '">' . palette_admin_csrf_field($session)
        . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="id" value="' . $id . '">';
}

// "Open in Stripe" for a Stripe subscription (or the payment intent of a one-time order); '' otherwise.
function palette_admin_stripe_link(mysqli $c, array $lic, ?array $sub): string
{
    $path = '';
    if ($sub && ($sub['provider'] ?? '') === 'stripe' && preg_match('/^sub_[A-Za-z0-9]+$/D', (string)$sub['subscription_id'])) {
        $path = 'subscriptions/' . $sub['subscription_id'];
    } elseif ($lic['source'] === 'stripe') {
        $id = (int)$lic['id'];
        $pi = (string)($c->query("SELECT stripe_payment_intent FROM palette_orders WHERE license_id = $id AND stripe_payment_intent LIKE 'pi\\_%' ORDER BY id DESC LIMIT 1")->fetch_row()[0] ?? '');
        if (preg_match('/^pi_[A-Za-z0-9]+$/D', $pi)) $path = 'payments/' . $pi;
    }
    if ($path === '') return '';
    $url = 'https://dashboard.stripe.com/' . ((int)$lic['livemode'] === 1 ? '' : 'test/') . $path;
    return '<a class="btn" href="' . palette_h($url) . '" target="_blank" rel="noopener noreferrer">Open in Stripe</a>';
}

function palette_admin_tab_licence(mysqli $c, array $session, array $query): array
{
    $id = is_string($query['id'] ?? null) && ctype_digit($query['id']) ? (int)$query['id'] : 0;
    $lic = $id > 0 ? palette_admin_licence_row($c, $id) : null;
    if (!$lic) {
        return ['status' => 404, 'title' => 'Licence', 'html' => '<section class="card"><div class="empty">That licence does not exist. <a href="'
            . palette_h(palette_admin_url('licences')) . '">Back to licences</a></div></section>'];
    }

    $sub = palette_admin_sub_row($c, $id);
    $isAi = $lic['plan'] === 'ai';
    [$paid, $paidCls, $access, $accessCls] = palette_admin_row_state($lic);
    $field = fn(string $label, string $value) => '<div class="kv"><span>' . palette_h($label) . '</span><b>' . $value . '</b></div>';
    $source = palette_h(PALETTE_ADMIN_SOURCES[$lic['source']] ?? 'Unknown');
    if ($lic['subscription_id']) $source .= ' · <span class="mono">' . palette_h(palette_admin_short((string)$lic['subscription_id'])) . '</span>';
    if ((int)$lic['livemode'] === 0) $source .= ' <span class="badge badge-test">Test</span>';

    $details = $field('Email', palette_h($lic['email'])) . $field('Plan', $isAi ? 'Pro + AI' : 'Pro') . $field('Source', $source)
        . $field('Payment', palette_h(PALETTE_ADMIN_PAY_LABELS[$lic['pay_status']] ?? $lic['pay_status']))
        . $field('Created', palette_h(palette_admin_date($lic['created_at'])) . ' · ' . palette_h(strtoupper((string)$lic['lang'])))
        . $field('vietnamcos account', $lic['user_id'] === null ? 'Not linked' : 'User ' . (int)$lic['user_id']);
    if ($isAi) {
        $u = palette_usage_get($c, $lic, palette_settings($c));
        $details .= $field('Paid through', palette_h(palette_admin_date($lic['paid_through']) ?: 'Never paid'))
            . $field('AI analyses this period', (int)$u['used'] . ' of ' . (int)$u['limit']);
    }

    $payments = palette_admin_payments($c, $lic);
    $rows = '';
    foreach ($payments as $p) {
        $cls = in_array($p['result'], ['Failed', 'Reversed'], true) ? ' class="bad"' : '';
        $rows .= '<tr><td>' . palette_h(palette_admin_date($p['at'])) . '</td><td>' . palette_h($p['amount']) . '</td><td' . $cls . '>' . palette_h($p['result'])
            . '</td><td class="mono muted">' . palette_h(palette_admin_short($p['ref'])) . '</td></tr>';
    }
    if ($rows === '') $rows = '<tr><td colspan="4" class="empty-cell">No payments recorded.</td></tr>';
    $payHtml = '<section class="card card-pad"><h2>Payments</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Amount</th><th>Result</th><th>Reference</th></tr></thead><tbody>'
        . $rows . '</tbody></table></div></section>';

    $hist = '';
    foreach (palette_admin_history($c, $lic, $payments) as $h) {
        $hist .= '<li><span class="when">' . palette_h(palette_admin_date($h['at'], 'j M, H:i')) . '</span><span>' . $h['html'] . '</span></li>';
    }
    $histHtml = '<section class="card card-pad"><h2>History</h2><ol class="history">' . $hist . '</ol></section>';

    $sel = fn(string $name, string $label, array $opts, string $cur) => '<label class="fld"><span>' . palette_h($label) . '</span>' . palette_admin_select($name, $label, $opts, $cur) . '</label>';
    $until = $isAi ? '<label class="fld"><span>Pro + AI access until</span><input type="date" name="until" value="'
        . palette_h($lic['paid_through'] === null ? '' : substr((string)$lic['paid_through'], 0, 10)) . '"></label>' : '';
    $form = palette_admin_form_open($session, $id, 'change') . '<h2>Change this licence</h2>'
        . $sel('access', 'Access', ['active' => 'Active', 'revoked' => 'Revoked'], $lic['revoked_at'] === null ? 'active' : 'revoked')
        . $sel('pay_status', 'Payment', PALETTE_ADMIN_PAY_LABELS, (string)$lic['pay_status'])
        . '<p class="hint">Changes our records only. Refund the money in Stripe or PayPal; "Refunded" also revokes access.</p>'
        . $until . $sel('plan', 'Plan', ['pro' => 'Pro', 'ai' => 'Pro + AI'], (string)$lic['plan'])
        . '<label class="fld"><span>Reason (kept in history)</span><textarea name="note" rows="2" maxlength="500"></textarea></label>'
        . '<label class="check"><input type="checkbox" name="notify" value="1"> Email the customer about this change</label>'
        . '<button class="btn btn-accent" type="submit">Save changes</button></form>';
    $side = '<section class="card card-pad">' . $form . '</section>';

    if ($sub) {
        $stripe = ($sub['provider'] ?? '') === 'stripe';
        $live = !in_array($sub['status'], ['cancelled', 'expired'], true);
        $buttons = '';
        if ($live) $buttons .= palette_admin_form_open($session, $id, 'cancel') . '<button class="btn btn-danger" type="submit">Cancel subscription</button></form>';
        if ($stripe) $buttons .= palette_admin_form_open($session, $id, 'billing_link') . '<button class="btn" type="submit">Send billing link</button></form>';
        $note = $live ? ($stripe ? 'Stops Stripe charging this card at the end of the paid period. Access stays until then.'
                                 : 'Stops PayPal billing now. Access stays until the paid-through date.')
                      : 'This subscription is already ' . $sub['status'] . '.';
        $side .= '<section class="card card-pad card-danger"><h2>Subscription</h2><p class="hint">' . palette_h($note)
            . ($stripe ? ' The billing link is emailed to the customer so they can change their own card; you never see it.' : '') . '</p>' . $buttons . '</section>';
    }

    $head = '<a class="back" href="' . palette_h(palette_admin_url('licences')) . '">Licences</a>'
        . '<header class="lic-head"><h2 class="mono-title">' . palette_h($lic['license_key']) . '</h2>'
        . '<span class="pill ' . $accessCls . '">' . palette_h($access) . '</span><span class="pill ' . $paidCls . '">' . palette_h($paid) . '</span><span class="grow"></span>'
        . palette_admin_form_open($session, $id, 'resend') . '<button class="btn" type="submit">' . palette_icon('mail', 16) . '<span>Resend key email</span></button></form>'
        . palette_admin_stripe_link($c, $lic, $sub) . '</header>';
    return ['title' => 'Licence', 'html' => $head . '<div class="lic-grid"><div class="lic-main"><section class="card card-pad kvs">' . $details . '</section>'
        . $payHtml . $histHtml . '</div><aside class="lic-side">' . $side . '</aside></div>'];
}
