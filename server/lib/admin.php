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
    'move_link'    => 'palette_admin_do_move_link',
    'issue'        => 'palette_admin_do_issue',
    'st_connect'    => 'palette_admin_do_st_connect',
    'st_check'      => 'palette_admin_do_st_check',
    'st_disconnect' => 'palette_admin_do_st_disconnect',
    'methods'       => 'palette_admin_do_methods',
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
        'move_sent'    => 'The move-to-card link was emailed to the customer. It works once, for 7 days.',
        'issued'       => 'Key issued.',
        'issued_existing' => 'That payment already has a key. It is shown below; no second key was made.',
        'st_connected'    => 'Stripe is connected.',
        'st_disconnected' => 'Stripe is disconnected.',
        'methods_saved'   => 'Checkout payment methods saved.',
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
        'plan_needs_until' => 'To switch to Pro + AI, set an access date in the future in the same save.',
        'cancel_first'  => 'Cancel the subscription first.',
        'cancel_failed' => 'Access was revoked, but the provider could not cancel the subscription. Cancel it in Stripe or PayPal now.',
        'not_paypal'    => 'Move to card is only for Pro + AI licences paid by an active PayPal subscription.',
        'bad_email'     => 'Enter a valid email address.',
        'bad_reason'    => 'Choose why the key is issued.',
        'bad_ref'       => 'A payment reference may hold letters, digits and _ - . : / only (Stripe: cs_... or pi_...).',
        'ref_other_email' => 'That payment reference already has a key for a different email. Check the reference, or leave it empty.',
        'order_pending' => 'That Stripe checkout is not paid yet but may still complete, and then it gets its own key. Check it in Stripe, or issue the key once the checkout has expired.',
        'st_disconnect_failed' => "Stripe refused or failed; nothing was changed. Try again or check the key's webhook permission.",
        'st_bad_mode'   => 'Choose Live or Test.',
        'stripe_not_live' => 'Card payments are not on: connect Stripe Live and turn Stripe on in the checkout methods first.',
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
        . palette_admin_flash($query) . $inner . '</div><script src="' . palette_h(palette_asset('admin.js')) . '" defer></script></body></html>';
}

// ---- tabs (each returns ['html' => inner HTML, 'title'? , 'status'? ] or ['response' => full response])

function palette_admin_placeholder(string $title): array
{
    return ['title' => $title, 'html' => '<section class="card"><div class="empty">' . palette_icon('info', 16) . '<span>Coming in a later task.</span></div></section>'];
}

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
            . '<td>' . ($r['plan'] === 'ai' ? 'Pro + AI' : 'Pro') . '</td><td class="muted">' . palette_h(PALETTE_ADMIN_SOURCES[$r['source'] ?? ''] ?? '—') . '</td>'
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
    // The row that backs the licence: the one whose subscription id the licence holds (a moved licence's old PayPal row keeps
    // its license_id); the newest row only when the licence holds none.
    $st = $c->prepare('SELECT s.*, l.user_id, l.paid_through, l.revoked_at FROM palette_licenses l JOIN palette_subscriptions s ON s.license_id = l.id
                         AND (l.subscription_id IS NULL OR s.subscription_id <=> l.subscription_id)
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

// One email for one or more changes, in the licence's language: $items = [[kind, vars], ...], kind = revoked|restored|refunded|
// payment|until|plan (em_admin_change_body_<kind>). The subject is always em_admin_change_subject.
function palette_admin_notify_many(array $lic, array $items): bool
{
    if (!$items) return true;
    $lang = palette_lang((string)$lic['lang']);
    $html = '<h1 style="font-size:20px;margin:0 0 12px">' . palette_h(palette_t($lang, 'em_admin_change_subject')) . '</h1>';
    foreach ($items as [$kind, $vars]) {
        $html .= '<p style="margin:0 0 8px">' . palette_h(palette_t($lang, 'em_admin_change_body_' . $kind, $vars)) . '</p>';
    }
    $html .= palette_email_footer($lang);
    $ok = palette_mail((string)$lic['email'], palette_t($lang, 'em_admin_change_subject'), palette_email_shell($lang, $html));
    if (!$ok) error_log('PALETTE_MAIL_FAILED admin_change licence=' . (int)$lic['id']);
    return $ok;
}

// Shared tail of the change functions: $applied is the result of the compare-and-swap UPDATE. $items are the mail sentences
// (also returned as 'mail', so a caller applying several changes can send them as one email). $extra goes into the log detail.
function palette_admin_changed(mysqli $c, int $adminId, array $lic, string $action, bool $applied, array $before, array $after, string $note, bool $notify, array $items, array $extra = []): array
{
    if (!$applied) return ['ok' => true, 'error' => null, 'nochange' => true];
    palette_admin_log($c, $adminId, $action, (int)$lic['id'], null, ['before' => $before, 'after' => $after] + $extra, trim($note));
    if ($notify) palette_admin_notify_many($lic, $items);
    return ['ok' => true, 'error' => null, 'mail' => $items];
}

// Revoking a subscriber ends their billing in the same action (otherwise the provider keeps charging, or the webhook/reconcile
// cancels later). Stripe: DELETE the subscription now; PayPal: the existing cancel. A failure is logged (PALETTE_CANCEL_FAILED by
// the helpers) and never undoes the revoke. null when the licence has no active or suspended subscription, else
// ['was' => status, 'ok' => bool].
function palette_admin_end_billing(mysqli $c, array $lic): ?array
{
    $row = palette_admin_sub_row($c, (int)$lic['id']);
    if (!$row || !in_array($row['status'], ['active', 'suspended'], true)) return null;
    $sid = (string)$row['subscription_id'];
    if (($row['provider'] ?? 'paypal') === 'stripe') {
        $mode = (int)$row['livemode'] === 1 ? 'live' : 'test';
        if (palette_stripe_ready($c, $mode)) {
            $ok = palette_st_cancel_sub($c, $mode, $row, 'admin revoke');
        } else {
            error_log("PALETTE_CANCEL_FAILED subscription=$sid after admin revoke (Stripe $mode not connected); cancel it in Stripe by hand");
            $ok = false;
        }
    } else {
        $ok = palette_sub_stop_billing($c, $row, $sid, 'admin revoke');
    }
    return ['was' => (string)$row['status'], 'ok' => $ok];
}

// Log detail and result flags for an end-billing outcome.
function palette_admin_billing_extra(?array $b): array
{
    return $b === null ? [] : ['subscription' => ['before' => $b['was'], 'after' => $b['ok'] ? 'cancelled' : $b['was'], 'cancel_failed' => !$b['ok']]];
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
    $b = $applied && !$active ? palette_admin_end_billing($c, $lic) : null;
    $r = palette_admin_changed($c, $adminId, $lic, 'access', $applied, ['revoked' => $was], ['revoked' => !$active], $note, $notify,
        [[$active ? 'restored' : 'revoked', []]], palette_admin_billing_extra($b));
    if ($b !== null && !$b['ok']) $r['warn'] = 'cancel_failed';
    return $r;
}

// $payStatus: provider|manual_paid|comp|refunded. Records only (the money moves in Stripe/PayPal); 'refunded' also revokes
// (and, like a revoke, ends a live subscription's billing).
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
    $b = $applied && $revoke ? palette_admin_end_billing($c, $lic) : null;
    $r = palette_admin_changed($c, $adminId, $lic, 'payment', $applied,
        ['pay_status' => $lic['pay_status'], 'revoked' => $wasRevoked], ['pay_status' => $payStatus, 'revoked' => $wasRevoked || $revoke], $note, $notify,
        [[$revoke ? 'refunded' : 'payment', []]], palette_admin_billing_extra($b));
    if ($b !== null && !$b['ok']) $r['warn'] = 'cancel_failed';
    return $r;
}

// "YYYY-MM-DD 23:59:59" for a valid Y-m-d string, else null.
function palette_admin_until_stamp(string $dateYmd): ?string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $dateYmd, new DateTimeZone('UTC'));
    return $d && $d->format('Y-m-d') === $dateYmd ? $dateYmd . ' 23:59:59' : null;
}

function palette_admin_is_future(?string $stamp): bool
{
    return $stamp !== null && strtotime($stamp . ' UTC') > time();
}

// max(current paid_through, now) + one calendar month (UTC), the day clamped to the target month's length. Y-m-d.
function palette_admin_plus_month(?string $paidThrough, ?int $now = null): string
{
    $now ??= time();
    $t = $paidThrough !== null && $paidThrough !== '' ? strtotime($paidThrough . ' UTC') : false;
    $base = new DateTimeImmutable('@' . max($now, $t === false ? 0 : $t));
    [$y, $m, $d] = array_map('intval', explode('-', $base->format('Y-n-j')));
    $m++;
    if ($m > 12) { $m = 1; $y++; }
    $d = min($d, (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t'));
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

// 'active' only when the subscription row itself is active/suspended (or there is none) and the date is ahead; otherwise unchanged.
function palette_admin_until_status(array $lic, ?array $subRow, string $stamp): ?string
{
    $live = $subRow === null || in_array($subRow['status'], ['active', 'suspended'], true);
    return palette_admin_is_future($stamp) && $live ? 'active' : $lic['sub_status'];
}

// Pro + AI only: paid_through = that day 23:59:59 (UTC). The licence goes back to 'active' when that moment is ahead and the
// subscription row is not cancelled/expired. The subscription row and the provider are not touched.
function palette_admin_set_until(mysqli $c, int $adminId, int $id, string $dateYmd, string $note, bool $notify): array
{
    if (!palette_admin_note_ok($note)) return ['ok' => false, 'error' => 'note_required'];
    $lic = palette_admin_licence_row($c, $id);
    if (!$lic) return ['ok' => false, 'error' => 'not_found'];
    if ($lic['plan'] !== 'ai') return ['ok' => false, 'error' => 'until_pro'];
    $to = palette_admin_until_stamp($dateYmd);
    if ($to === null) return ['ok' => false, 'error' => 'until_bad'];
    $newStatus = palette_admin_until_status($lic, palette_admin_sub_row($c, $id), $to);
    if ($lic['paid_through'] === $to && $lic['sub_status'] === $newStatus) return ['ok' => true, 'error' => null, 'nochange' => true];
    $st = $c->prepare('UPDATE palette_licenses SET paid_through = ?, sub_status = ? WHERE id = ? AND plan = \'ai\' AND paid_through <=> ? AND sub_status <=> ?');
    $st->bind_param('ssiss', $to, $newStatus, $id, $lic['paid_through'], $lic['sub_status']);
    $st->execute();
    $applied = $st->affected_rows === 1;
    $st->close();
    return palette_admin_changed($c, $adminId, $lic, 'until', $applied,
        ['paid_through' => $lic['paid_through'], 'sub_status' => $lic['sub_status']], ['paid_through' => $to, 'sub_status' => $newStatus], $note, $notify,
        [['until', ['date' => $dateYmd]]]);
}

// Changing the plan edits the licence only (the provider is not touched).
//  - To Pro + AI the licence needs a future paid_through, or it would have no access at all (Pro has none): pass $untilYmd (a future
//    date, applied in the same UPDATE) or the licence must already have a future paid_through; else plan_needs_until.
//  - To Pro while the subscription may still bill is refused (cancel_first): see palette_admin_sub_may_bill.
// True when the licence's backing subscription may still bill: its row is active or suspended, or it is a Stripe row marked
// cancelled whose paid_through is still in the future and Stripe does not report it as ended (canceled, incomplete_expired or
// 404). A cancel_at_period_end subscription can be resumed by the customer until the period ends; when Stripe cannot be asked
// the answer is true (it cannot be proved ended). A cancelled PayPal subscription cannot be resumed.
function palette_admin_sub_may_bill(mysqli $c, ?array $row): bool
{
    if (!$row) return false;
    if (in_array($row['status'], ['active', 'suspended'], true)) return true;
    if (($row['provider'] ?? 'paypal') !== 'stripe' || $row['status'] !== 'cancelled' || !palette_admin_is_future($row['paid_through'] ?? null)) return false;
    $mode = (int)$row['livemode'] === 1 ? 'live' : 'test';
    if (!palette_stripe_ready($c, $mode)) return true;
    $r = palette_st_api($c, $mode, 'GET', 'subscriptions/' . rawurlencode((string)$row['subscription_id']));
    if ($r['status'] === 404) return false;
    if ($r['status'] < 200 || $r['status'] >= 300) return true;
    return !in_array($r['json']['status'] ?? '', ['canceled', 'incomplete_expired'], true);
}

function palette_admin_set_plan(mysqli $c, int $adminId, int $id, string $plan, string $note, bool $notify, ?string $untilYmd = null): array
{
    if (!palette_admin_note_ok($note)) return ['ok' => false, 'error' => 'note_required'];
    if (!in_array($plan, ['pro', 'ai'], true)) return ['ok' => false, 'error' => 'bad_plan'];
    $lic = palette_admin_licence_row($c, $id);
    if (!$lic) return ['ok' => false, 'error' => 'not_found'];
    if ($lic['plan'] === $plan) return ['ok' => true, 'error' => null, 'nochange' => true];
    $row = palette_admin_sub_row($c, $id);
    $to = $lic['paid_through'];
    $status = $lic['sub_status'];
    $items = [['plan', ['plan' => $plan === 'ai' ? 'Pro + AI' : 'Pro']]];
    if ($plan === 'pro') {
        if (palette_admin_sub_may_bill($c, $row)) return ['ok' => false, 'error' => 'cancel_first'];
    } elseif ($untilYmd !== null && $untilYmd !== '') {
        $to = palette_admin_until_stamp($untilYmd);
        if ($to === null) return ['ok' => false, 'error' => 'until_bad'];
        if (!palette_admin_is_future($to)) return ['ok' => false, 'error' => 'plan_needs_until'];
        $status = palette_admin_until_status($lic, $row, $to);
        $items[] = ['until', ['date' => $untilYmd]];
    } elseif (!palette_admin_is_future($lic['paid_through'])) {
        return ['ok' => false, 'error' => 'plan_needs_until'];
    }
    $st = $c->prepare('UPDATE palette_licenses SET plan = ?, paid_through = ?, sub_status = ? WHERE id = ? AND plan = ?');
    $st->bind_param('sssis', $plan, $to, $status, $id, $lic['plan']);
    $st->execute();
    $applied = $st->affected_rows === 1;
    $st->close();
    $before = ['plan' => $lic['plan']];
    $after = ['plan' => $plan];
    if ($to !== $lic['paid_through'] || $status !== $lic['sub_status']) {
        $before += ['paid_through' => $lic['paid_through'], 'sub_status' => $lic['sub_status']];
        $after += ['paid_through' => $to, 'sub_status' => $status];
    }
    return palette_admin_changed($c, $adminId, $lic, 'plan', $applied, $before, $after, $note, $notify, $items);
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
    if (!empty($res['warn'])) return ['err' => (string)$res['warn']] + $where;   // done, but the admin must act (cancel_failed)
    return ['ok' => !empty($res['nochange']) ? 'nochange' : $okCode] + $where;
}

// The "Change this licence" form. Works out which fields differ from the stored state, validates ALL of them before applying
// any (an invalid request changes nothing), then applies plan, access, payment, until in that order: one function and one log
// row each, but ONE email to the customer (when ticked) listing every change. "+1 month" (plus_month) sets until to
// max(paid_through, now) + 1 month. Switching to Pro + AI takes its access date in the same save (set_plan).
function palette_admin_do_change(mysqli $c, array $session, array $post): array
{
    $id = palette_admin_post_id($post);
    $lic = $id > 0 ? palette_admin_licence_row($c, $id) : null;
    if (!$lic) return ['err' => 'not_found'];
    $adminId = (int)($session['user_id'] ?? 0);
    $str = fn(string $k) => is_string($post[$k] ?? null) ? trim($post[$k]) : '';
    $note = $str('note');
    $notify = $str('notify') !== '';

    $plan = $str('plan') !== '' ? $str('plan') : (string)$lic['plan'];
    if (!in_array($plan, ['pro', 'ai'], true)) return palette_admin_result(['ok' => false, 'error' => 'bad_plan'], $id, 'saved');
    $toAi = $plan === 'ai' && $lic['plan'] !== 'ai';
    $toPro = $plan === 'pro' && $lic['plan'] !== 'pro';
    $pay = $str('pay_status');
    if ($pay !== '' && !in_array($pay, ['provider', 'manual_paid', 'comp', 'refunded'], true)) return palette_admin_result(['ok' => false, 'error' => 'bad_status'], $id, 'saved');
    $access = $str('access');
    $until = $str('plus_month') !== '' ? palette_admin_plus_month($lic['paid_through']) : $str('until');
    $curUntil = $lic['paid_through'] === null ? '' : substr((string)$lic['paid_through'], 0, 10);

    $wantAccess = in_array($access, ['active', 'revoked'], true) && ($access === 'revoked') !== ($lic['revoked_at'] !== null);
    $wantPay = $pay !== '' && $pay !== $lic['pay_status'];
    $wantUntil = $until !== '' && ($until !== $curUntil || $toAi);
    if (!$toAi && !$toPro && !$wantAccess && !$wantPay && !$wantUntil) return palette_admin_result(['ok' => true, 'nochange' => true], $id, 'saved');

    // Validate everything first.
    $err = null;
    $stamp = $until !== '' ? palette_admin_until_stamp($until) : null;
    if (!palette_admin_note_ok($note)) $err = 'note_required';
    elseif ($wantUntil && $stamp === null) $err = 'until_bad';
    elseif ($wantUntil && $plan !== 'ai') $err = 'until_pro';
    elseif ($toAi && !($wantUntil ? palette_admin_is_future($stamp) : palette_admin_is_future($lic['paid_through']))) $err = 'plan_needs_until';
    elseif ($toPro && palette_admin_sub_may_bill($c, palette_admin_sub_row($c, $id))) $err = 'cancel_first';
    if ($err !== null) return palette_admin_result(['ok' => false, 'error' => $err], $id, 'saved');

    $steps = [];
    if ($toAi || $toPro) $steps[] = fn() => palette_admin_set_plan($c, $adminId, $id, $plan, $note, false, $toAi && $wantUntil ? $until : null);
    if ($wantAccess) $steps[] = fn() => palette_admin_set_access($c, $adminId, $id, $access === 'active', $note, false);
    if ($wantPay) $steps[] = fn() => palette_admin_set_payment($c, $adminId, $id, $pay, $note, false);
    if ($wantUntil && !$toAi) $steps[] = fn() => palette_admin_set_until($c, $adminId, $id, $until, $note, false);

    $res = ['ok' => true, 'error' => null, 'nochange' => true];
    $mail = [];
    foreach ($steps as $step) {
        $r = $step();
        if (!$r['ok']) return palette_admin_result($r, $id, 'saved');
        if (!empty($r['nochange'])) continue;
        $res['nochange'] = false;
        $mail = array_merge($mail, $r['mail'] ?? []);
        if (!empty($r['warn'])) $res['warn'] = $r['warn'];
    }
    if ($notify && $mail) palette_admin_notify_many($lic, $mail);
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

// Payments of one licence, newest first: [at, amount, result, ref] (orders by licence; subscription payments of the licence's
// subscription and of every subscription row it had, so a moved-to-card licence still lists its PayPal payments).
function palette_admin_licence_payments(mysqli $c, array $lic): array
{
    $out = [];
    $id = (int)$lic['id'];
    $st = $c->prepare('SELECT order_id, provider, stripe_payment_intent, capture_id, amount_usd, status, created_at, captured_at FROM palette_orders WHERE license_id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    foreach ($st->get_result() as $o) {
        $ref = (string)($o['stripe_payment_intent'] ?: ($o['capture_id'] ?: $o['order_id']));
        $out[] = ['at' => (string)($o['captured_at'] ?? $o['created_at']), 'amount' => palette_admin_usd($o['amount_usd']),
                  'result' => ['captured' => 'Paid', 'failed' => 'Failed', 'created' => 'Not paid'][$o['status']] ?? $o['status'], 'ref' => $ref,
                  'provider' => (string)$o['provider']];
    }
    $st->close();
    // Each payment's provider is its own subscription row's (a moved licence lists old PayPal payments next to Stripe ones).
    $st = $c->prepare('SELECT p.sale_id, p.subscription_id, p.amount_usd, p.status, p.paid_at,
                              (SELECT s.provider FROM palette_subscriptions s WHERE s.subscription_id = p.subscription_id ORDER BY s.id DESC LIMIT 1) AS provider
                       FROM palette_sub_payments p WHERE p.subscription_id = ?
                       OR p.subscription_id IN (SELECT subscription_id FROM palette_subscriptions WHERE license_id = ? AND subscription_id IS NOT NULL)');
    $sid = (string)($lic['subscription_id'] ?? '');
    $st->bind_param('si', $sid, $id);
    $st->execute();
    foreach ($st->get_result() as $p) {
        $out[] = ['at' => (string)$p['paid_at'], 'amount' => palette_admin_usd($p['amount_usd']),
                  'result' => ['completed' => 'Paid', 'refunded' => 'Refunded', 'reversed' => 'Reversed'][$p['status']] ?? $p['status'], 'ref' => (string)$p['sale_id'],
                  'provider' => (string)($p['provider'] ?? (str_starts_with((string)$p['subscription_id'], 'sub_') ? 'stripe' : 'paypal'))];
    }
    $st->close();
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
        'issue'        => !empty($d['existing']) ? 'Asked to issue a key again; the existing key was shown'
                          : 'Issued this key (' . (PALETTE_ADMIN_REASONS[$d['reason'] ?? ''] ?? 'admin') . ')' . (!empty($d['linked']) ? ' and linked it to the Stripe payment' : ''),
        'move_link'    => 'Emailed a move-to-card link',
        'move_used'    => 'Moved to card payments with Stripe; the key stayed the same',
        'move_paypal_cancel_failed' => 'Could not cancel the PayPal subscription after the move to card',
        'move_old_refund' => 'PayPal refunded a payment made before the move to card; the key was not revoked (decide by hand)',
        'move_orphan_cancel_failed' => 'Could not cancel a refused move-to-card Stripe subscription; cancel it in Stripe',
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
        $who = (int)$r['admin_user_id'] === 0 ? 'System' : 'Admin ' . (int)$r['admin_user_id'];   // 0: written by checkout or a webhook
        $html = '<b>' . $who . '</b> ' . palette_h(lcfirst(palette_admin_log_text($r))) . '.';
        if ($r['note'] !== null && $r['note'] !== '') $html .= ' <span class="muted">Reason: ' . palette_h($r['note']) . '</span>';
        $items[] = ['at' => (string)$r['created_at'], 'html' => $html];
    }
    $st->close();
    foreach ($payments as $p) {
        $by = ['stripe' => 'Stripe', 'paypal' => 'PayPal', 'admin' => 'Admin'][$p['provider'] ?? ''] ?? 'Payment';   // the payment's own provider
        $items[] = ['at' => $p['at'], 'html' => palette_h($by . ': ' . $p['amount'] . ' ' . strtolower($p['result']) . '.')];
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

    $payments = palette_admin_licence_payments($c, $lic);
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
    $liveSub = $sub !== null && in_array($sub['status'], ['active', 'suspended'], true);
    $untilLabel = $isAi ? 'Pro + AI access until' : 'Pro + AI access until (needed when switching to Pro + AI)';
    $until = '<div class="fld"><label for="until">' . palette_h($untilLabel) . '</label><div class="row"><input id="until" type="date" name="until" value="'
        . palette_h($lic['paid_through'] === null ? '' : substr((string)$lic['paid_through'], 0, 10)) . '">'
        . '<button class="btn btn-sm" type="submit" name="plus_month" value="1">+1 month</button></div>'
        . ($liveSub ? '<span class="hint">An earlier date is replaced at the next renewal.</span>' : '') . '</div>';
    $form = palette_admin_form_open($session, $id, 'change')
        . '<button class="sr-only" type="submit" tabindex="-1" aria-hidden="true">Save changes</button><h2>Change this licence</h2>'
        . $sel('access', 'Access', ['active' => 'Active', 'revoked' => 'Revoked'], $lic['revoked_at'] === null ? 'active' : 'revoked')
        . '<p class="hint">Revoking a subscriber also ends their subscription now. Restoring access later does not restart billing.</p>'
        . $sel('pay_status', 'Payment', PALETTE_ADMIN_PAY_LABELS, (string)$lic['pay_status'])
        . '<p class="hint">Changes our records only. Refund the money in Stripe or PayPal; "Refunded" also revokes access.</p>'
        . $sel('plan', 'Plan', ['pro' => 'Pro', 'ai' => 'Pro + AI'], (string)$lic['plan']) . $until
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
        if (palette_admin_move_paypal_row($c, $lic)) {
            $buttons .= palette_admin_form_open($session, $id, 'move_link')
                . '<label class="fld"><span>Note (optional, kept in history)</span><input type="text" name="note" maxlength="500"></label>'
                . '<button class="btn" type="submit">' . palette_icon('card', 16) . '<span>Move to card</span></button>'
                . '<p class="hint">Emails the customer a one-use link (7 days) to subscribe by card with Stripe. Their key stays the same, the PayPal days they paid for carry over, and the PayPal subscription is cancelled when they subscribe.</p></form>';
        }
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
    $head .= palette_admin_move_warnings($c, $id);
    return ['title' => 'Licence', 'html' => $head . '<div class="lic-grid"><div class="lic-main"><section class="card card-pad kvs">' . $details . '</section>'
        . $payHtml . $histHtml . '</div><aside class="lic-side">' . $side . '</aside></div>'];
}

// ---- issue a key
// palette_admin_issue: one function for every way an admin hands out a key. Idempotent: the same payment reference (or, without
// one, the same admin, email and plan within PALETTE_ADMIN_ISSUE_WINDOW seconds: a double-click) answers the key already made
// (existing = true) instead of a second licence. A named lock serialises concurrent calls for the same reference or email.

const PALETTE_ADMIN_REASONS = ['paid_no_key' => 'Paid, key not issued', 'paid_other' => 'Paid another way', 'comp' => 'Free (comp)', 'replacement' => 'Replacement key'];
const PALETTE_ADMIN_STRIPE_REF_RE = '/^(cs|pi)_[A-Za-z0-9_]{1,77}$/D';
const PALETTE_ADMIN_REF_RE = '/^[A-Za-z0-9][A-Za-z0-9_.:\/-]{0,79}$/D';
const PALETTE_ADMIN_ISSUE_WINDOW = 30;

// Lowercased, trimmed email, or null when it is not one address.
function palette_admin_clean_email(string $email): ?string
{
    if (preg_match('/[\r\n]/', $email)) return null;
    $email = strtolower(trim($email));
    return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

function palette_admin_ref_ok(string $ref): bool
{
    if (preg_match('/^(cs|pi)_/', $ref)) return preg_match(PALETTE_ADMIN_STRIPE_REF_RE, $ref) === 1;
    return preg_match(PALETTE_ADMIN_REF_RE, $ref) === 1;
}

// A palette order for a Stripe reference and, while it has no key, its Checkout session from Stripe (in the order's mode).
// Read-only. A pi_ that matches no order is looked up as GET /v1/checkout/sessions?payment_intent= (live, then test).
// ['order' => ?row, 'session' => ?array, 'error' => ?'stripe'].
function palette_admin_ref_resolve(mysqli $c, string $ref): array
{
    $out = ['order' => null, 'session' => null, 'error' => null];
    $col = str_starts_with($ref, 'cs_') ? 'stripe_session' : 'stripe_payment_intent';
    $st = $c->prepare("SELECT * FROM palette_orders WHERE $col = ? ORDER BY id DESC LIMIT 1");
    $st->bind_param('s', $ref);
    $st->execute();
    $o = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    if ($o) {
        $out['order'] = $o;
        if ($o['license_id'] !== null || !preg_match(PALETTE_STRIPE_SESSION_RE, (string)$o['stripe_session'])) return $out;
        $r = palette_st_api($c, (int)$o['livemode'] === 1 ? 'live' : 'test', 'GET', 'checkout/sessions/' . $o['stripe_session']);
        if ($r['status'] >= 200 && $r['status'] < 300) {
            $out['session'] = $r['json'];
        } else {
            palette_st_log_error('admin lookup', (string)$o['order_id'], $r['status']);
            $out['error'] = 'stripe';
        }
        return $out;
    }
    if (!str_starts_with($ref, 'pi_')) return $out;
    foreach (['live', 'test'] as $mode) {
        if (!palette_stripe_ready($c, $mode)) continue;
        $r = palette_st_api($c, $mode, 'GET', 'checkout/sessions', ['payment_intent' => $ref, 'limit' => 1]);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            palette_st_log_error('admin lookup by payment intent', '-', $r['status']);
            $out['error'] = 'stripe';
            continue;
        }
        $s = $r['json']['data'][0] ?? null;
        if (!is_array($s) || !preg_match(PALETTE_STRIPE_SESSION_RE, (string)($s['id'] ?? ''))) continue;
        $o = palette_st_row_by_session($c, 'palette_orders', (string)$s['id']);
        return ['order' => $o, 'session' => $o ? $s : null, 'error' => null];   // a session that is not ours: not found
    }
    return $out;
}

// The hint under the reference field (GET admin.php?tab=issue&lookup=). Read-only; the format is checked before any Stripe call.
// ['found', 'paid', 'amount', 'email', 'date'] (+ 'lang', 'has_key', 'kind' when found; 'error' => bad_ref|stripe).
function palette_admin_ref_lookup(mysqli $c, string $ref): array
{
    $out = ['found' => false, 'paid' => false, 'amount' => '', 'email' => null, 'date' => null];
    $ref = trim($ref);
    if (!preg_match(PALETTE_ADMIN_STRIPE_REF_RE, $ref)) return $out + ['error' => 'bad_ref'];
    $res = palette_admin_ref_resolve($c, $ref);
    $o = $res['order'];
    if (!$o && str_starts_with($ref, 'cs_') && ($s = palette_st_row_by_session($c, 'palette_subscriptions', $ref))) {
        return ['found' => true, 'paid' => $s['status'] !== 'pending', 'amount' => palette_admin_usd((string)($s['price_usd'] ?? '0')), 'email' => (string)$s['email'],
                'date' => substr((string)$s['created_at'], 0, 10), 'lang' => (string)$s['lang'], 'has_key' => $s['license_id'] !== null, 'kind' => 'subscription'];
    }
    if (!$o) return $out + ($res['error'] !== null ? ['error' => 'stripe'] : []);
    $hasKey = $o['license_id'] !== null;
    $paid = $hasKey || $o['status'] === 'captured' || ($res['session']['payment_status'] ?? '') === 'paid';
    return ['found' => true, 'paid' => $paid, 'amount' => palette_admin_usd((string)$o['amount_usd']), 'email' => (string)$o['email'],
            'date' => substr((string)$o['created_at'], 0, 10), 'lang' => (string)$o['lang'], 'has_key' => $hasKey, 'kind' => 'order']
        + ($res['error'] !== null ? ['error' => 'stripe'] : []);
}

// The licence an earlier admin issue made for this reference (or, without one, for this admin + email + plan a moment ago).
function palette_admin_issued_before(mysqli $c, int $adminId, string $email, string $plan, string $ref): ?array
{
    if ($ref !== '') {
        $st = $c->prepare("SELECT l.id, l.license_key, l.email, l.order_id FROM palette_admin_log a JOIN palette_licenses l ON l.id = a.license_id
                           WHERE a.action = 'issue' AND a.order_ref = ? ORDER BY a.id DESC LIMIT 1");
        $st->bind_param('s', $ref);
    } else {
        $since = gmdate('Y-m-d H:i:s', time() - PALETTE_ADMIN_ISSUE_WINDOW);
        $st = $c->prepare("SELECT l.id, l.license_key, l.email, l.order_id FROM palette_admin_log a JOIN palette_licenses l ON l.id = a.license_id
                           WHERE a.action = 'issue' AND a.order_ref IS NULL AND a.admin_user_id = ? AND l.email = ? AND l.plan = ? AND a.created_at >= ?
                           ORDER BY a.id DESC LIMIT 1");
        $st->bind_param('isss', $adminId, $email, $plan, $since);
    }
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ?: null;
}

function palette_admin_key_by(mysqli $c, string $col, string|int $val): ?array
{
    $col = $col === 'id' ? 'id' : 'license_key';
    $st = $c->prepare("SELECT id, license_key, email FROM palette_licenses WHERE $col = ?");
    $v = (string)$val;
    $st->bind_param('s', $v);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ?: null;
}

// ['ok' => true, 'key', 'existing' => bool, 'licence_id', 'mailed' => bool] or ['ok' => false, 'error' => code].
// $reason: paid_no_key|paid_other|comp|replacement; $ref optional (a Stripe cs_/pi_ is looked up, anything else is recorded);
// $note required. $lang is the language of a new admin licence and its email (a linked Stripe order keeps its own).
// A paid Stripe order is fulfilled through palette_st_fulfil_order (linked, marked captured; the buyer gets the usual key
// email whatever $notify says). Otherwise an admin licence: order_id ADM-<16 hex>, source admin, pay_status comp for 'comp'
// else manual_paid; Pro + AI runs one month. Every call writes one palette_admin_log row (never the key or the email).
function palette_admin_issue(mysqli $c, int $adminId, string $email, string $plan, string $reason, string $ref, string $note, bool $notify, string $lang = 'en'): array
{
    if (!palette_admin_note_ok($note)) return ['ok' => false, 'error' => 'note_required'];
    $email = palette_admin_clean_email($email);
    if ($email === null) return ['ok' => false, 'error' => 'bad_email'];
    if (!in_array($plan, ['pro', 'ai'], true)) return ['ok' => false, 'error' => 'bad_plan'];
    if (!isset(PALETTE_ADMIN_REASONS[$reason])) return ['ok' => false, 'error' => 'bad_reason'];
    $ref = trim($ref);
    if ($ref !== '' && !palette_admin_ref_ok($ref)) return ['ok' => false, 'error' => 'bad_ref'];
    $lock = 'palette_issue:' . sha1($ref !== '' ? "ref:$ref" : "email:$email");   // hex only: safe inside the quotes
    $got = (int)($c->query("SELECT GET_LOCK('$lock', 10)")->fetch_row()[0] ?? 0);
    try {
        return palette_admin_issue_locked($c, $adminId, $email, $plan, $reason, $ref, trim($note), $notify, palette_lang($lang));
    } finally {
        if ($got === 1) $c->query("SELECT RELEASE_LOCK('$lock')");
    }
}

function palette_admin_issue_locked(mysqli $c, int $adminId, string $email, string $plan, string $reason, string $ref, string $note, bool $notify, string $lang): array
{
    $detail = ['reason' => $reason, 'plan' => $plan];
    $refOrNull = $ref !== '' ? $ref : null;
    $existing = function (array $l, array $extra = []) use ($c, $adminId, $refOrNull, $detail, $note): array {
        palette_admin_log($c, $adminId, 'issue', (int)$l['id'], $refOrNull, $detail + $extra + ['existing' => true], $note);
        return ['ok' => true, 'key' => (string)$l['license_key'], 'existing' => true, 'licence_id' => (int)$l['id'], 'mailed' => false, 'email' => (string)$l['email']];
    };
    if ($prev = palette_admin_issued_before($c, $adminId, $email, $plan, $ref)) {
        // A reference the admin typed earlier for someone else is a typo, not a request to show that person's key. (A Stripe
        // order's key is shown whatever email was typed: the order says whose it is.)
        if ($prev['email'] !== $email && str_starts_with((string)$prev['order_id'], 'ADM-')) return ['ok' => false, 'error' => 'ref_other_email'];
        return $existing($prev);
    }

    if ($ref !== '' && preg_match(PALETTE_ADMIN_STRIPE_REF_RE, $ref)) {
        $res = palette_admin_ref_resolve($c, $ref);
        $o = $res['order'];
        if ($o && $o['license_id'] !== null && ($l = palette_admin_key_by($c, 'id', (int)$o['license_id']))) return $existing($l, ['linked' => true]);
        if (!$o && str_starts_with($ref, 'cs_') && ($s = palette_st_row_by_session($c, 'palette_subscriptions', $ref))) {
            if ($s['license_id'] !== null && ($l = palette_admin_key_by($c, 'id', (int)$s['license_id']))) return $existing($l, ['linked' => true]);
            if ($s['status'] === 'pending') {
                // Ask Stripe (palette_st_confirm_sub fulfils a paid session exactly as the return page would).
                $cf = palette_st_confirm_sub($c, $s, $ref);
                if ($cf['state'] === 'error') return ['ok' => false, 'error' => 'stripe'];
                if ($cf['state'] === 'waiting') return ['ok' => false, 'error' => 'order_pending'];
                if ($cf['state'] === 'paid' && is_string($cf['key']) && ($l = palette_admin_key_by($c, 'key', $cf['key']))) {
                    palette_admin_log($c, $adminId, 'issue', (int)$l['id'], $ref, $detail + ['linked' => true, 'existing' => false], $note);
                    return ['ok' => true, 'key' => (string)$l['license_key'], 'existing' => false, 'licence_id' => (int)$l['id'], 'mailed' => true, 'email' => (string)$l['email']];
                }
                $detail['stripe'] = 'expired';   // failed: the session expired or was refused, nothing will complete it
            }
        }
        if ($res['error'] !== null) return ['ok' => false, 'error' => 'stripe'];
        if ($o && $res['session'] !== null && ($res['session']['payment_status'] ?? '') === 'paid') {
            $f = palette_st_fulfil_order($c, $o, $res['session']);
            if (!empty($f['retry'])) return ['ok' => false, 'error' => 'stripe'];
            $l = $f['ok'] && $f['key'] !== null ? palette_admin_key_by($c, 'key', (string)$f['key']) : null;
            if ($l) {
                if ($f['already']) return $existing($l, ['linked' => true]);
                palette_admin_log($c, $adminId, 'issue', (int)$l['id'], $ref, $detail + ['linked' => true, 'existing' => false], $note);
                return ['ok' => true, 'key' => (string)$l['license_key'], 'existing' => false, 'licence_id' => (int)$l['id'], 'mailed' => true, 'email' => (string)$l['email']];
            }
            $detail['stripe'] = 'refused';   // paid, but the order could not take it (amount mismatch, failed order): issued by hand below
        } elseif ($o && $o['status'] === 'created' && ($res['session']['status'] ?? '') !== 'expired') {
            // Not paid yet, but the checkout can still complete (open, or an async payment processing): a key issued now would
            // be a second one when it does.
            return ['ok' => false, 'error' => 'order_pending'];
        } elseif (!isset($detail['stripe'])) {   // (an expired subscription checkout above already set it)
            $detail['stripe'] = $o ? 'not_paid' : 'not_found';
        }
    }

    $orderId = 'ADM-' . strtoupper(bin2hex(random_bytes(8)));
    $pay = $reason === 'comp' ? 'comp' : 'manual_paid';
    $until = $plan === 'ai' ? palette_admin_plus_month(null) . ' 23:59:59' : null;
    $start = $plan === 'ai' ? gmdate('Y-m-d H:i:s') : null;
    $c->begin_transaction();
    try {
        $lic = palette_issue_license($c, $email, $orderId, $lang, null, 'admin');
        $lid = (int)$lic['id'];
        $st = $c->prepare('UPDATE palette_licenses SET pay_status = ?, plan = ?, paid_through = ?, period_start = ? WHERE id = ?');
        $st->bind_param('ssssi', $pay, $plan, $until, $start, $lid);
        $st->execute();
        $st->close();
        palette_admin_log($c, $adminId, 'issue', $lid, $refOrNull, $detail + ['existing' => false], $note);
        $c->commit();
    } catch (Throwable $e) {
        $c->rollback();
        throw $e;
    }
    $mailed = false;
    if ($notify) {
        $m = $plan === 'ai' ? palette_sub_email($lic['key'], $lang, palette_usage_limit(palette_settings($c))) : palette_license_email($lic['key'], $lang);
        $mailed = palette_mail($email, $m['subject'], $m['html']);
        if (!$mailed) error_log("PALETTE_MAIL_FAILED admin_issue licence=$lid");
    }
    return ['ok' => true, 'key' => $lic['key'], 'existing' => false, 'licence_id' => $lid, 'mailed' => $mailed, 'email' => $email];
}

// POST action=issue. The key goes to the session flash (shown once by the issue tab), never into the URL or the log.
function palette_admin_do_issue(mysqli $c, array $session, array $post): array
{
    $str = fn(string $k) => is_string($post[$k] ?? null) ? $post[$k] : '';
    $r = palette_admin_issue($c, (int)($session['user_id'] ?? 0), $str('email'), $str('plan'), $str('reason'), $str('ref'), $str('note'),
        $str('notify') !== '', $str('lang') !== '' ? $str('lang') : 'en');
    if (!$r['ok']) {
        // Keep what was typed (nothing secret in it) so the admin does not type it again.
        $_SESSION['palette_admin_issue_form'] = array_map(fn($v) => mb_substr($v, 0, 500),
            ['email' => $str('email'), 'plan' => $str('plan'), 'reason' => $str('reason'), 'ref' => $str('ref'), 'note' => $str('note'), 'lang' => $str('lang'), 'notify' => $str('notify')]);
        return ['err' => (string)$r['error'], 'tab' => 'issue'];
    }
    unset($_SESSION['palette_admin_issue_form']);
    $_SESSION['palette_admin_issued'] = ['key' => $r['key'], 'licence_id' => $r['licence_id'], 'existing' => $r['existing'], 'mailed' => $r['mailed'], 'email' => $r['email']];
    return ['ok' => $r['existing'] ? 'issued_existing' : 'issued', 'tab' => 'issue'];
}

function palette_admin_json(array $body): array
{
    return ['status' => 200, 'body' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'headers' => ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex']];
}

function palette_admin_tab_issue(mysqli $c, array $session, array $query): array
{
    if (array_key_exists('lookup', $query)) {
        return ['response' => palette_admin_json(palette_admin_ref_lookup($c, is_string($query['lookup']) ? $query['lookup'] : ''))];
    }
    // The flash: the key made by the last POST, shown on this one render only.
    $done = $session['palette_admin_issued'] ?? null;
    $form = is_array($session['palette_admin_issue_form'] ?? null) ? $session['palette_admin_issue_form'] : [];
    unset($_SESSION['palette_admin_issued'], $_SESSION['palette_admin_issue_form']);
    $v = fn(string $k) => is_string($form[$k] ?? null) ? $form[$k] : (is_string($query[$k] ?? null) ? mb_substr($query[$k], 0, 190) : '');

    $after = '';
    if (is_array($done) && is_string($done['key'] ?? null) && preg_match(PALETTE_KEY_RE, $done['key'])) {
        $sent = !empty($done['mailed'])
            ? '<p class="ok-line">' . palette_icon('check', 14) . '<span>Email sent to ' . palette_h((string)($done['email'] ?? '')) . '</span></p>'
            : '<p class="hint">No email was sent. Copy the key to send it yourself.</p>';
        $after = '<aside class="card card-pad issue-done" aria-labelledby="issued-h"><h2 id="issued-h">' . (!empty($done['existing']) ? 'Existing key' : 'Key ready') . '</h2>'
            . '<p>' . (!empty($done['existing']) ? 'This payment or request already had a key; no second key was made.' : 'Copy it to send it yourself, or it is already on its way.') . '</p>'
            . '<div class="key-row"><code id="issued-key" class="keybox">' . palette_h($done['key']) . '</code>'
            . '<button class="btn btn-sm" type="button" data-copy-from="issued-key">' . palette_icon('copy', 14) . '<span>Copy</span></button></div>'
            . '<p class="hint" id="copy-status" role="status"></p>' . $sent
            . '<a class="btn btn-sm" href="' . palette_h(palette_admin_url('licence', ['id' => (int)($done['licence_id'] ?? 0)])) . '">Open the licence</a></aside>';
    }

    $sel = fn(string $name, string $id, string $label, array $opts, string $cur) => '<div class="fld"><label for="' . $id . '">' . palette_h($label) . '</label>'
        . preg_replace('/^<select /', '<select id="' . $id . '" ', palette_admin_select($name, $label, $opts, $cur)) . '</div>';
    $notify = $form ? (($form['notify'] ?? '') !== '') : true;
    $html = '<a class="back" href="' . palette_h(palette_admin_url('licences')) . '">Licences</a><div class="issue-grid">'
        . '<section class="card card-pad" aria-label="Issue a key">'
        . '<form class="stack" method="post" action="' . PALETTE_ADMIN_PAGE . '">' . palette_admin_csrf_field($session) . '<input type="hidden" name="action" value="issue">'
        . '<div class="fld"><label for="em">Customer email</label><input id="em" type="email" name="email" required maxlength="190" autocomplete="off" value="' . palette_h($v('email')) . '"></div>'
        . '<div class="two">' . $sel('plan', 'pl', 'Plan', ['pro' => 'Pro (lifetime)', 'ai' => 'Pro + AI (one month)'], $v('plan') ?: 'pro')
        . $sel('reason', 'why', 'Why', PALETTE_ADMIN_REASONS, $v('reason') ?: 'paid_no_key') . '</div>'
        . '<div class="fld"><label for="ref">Payment reference <span class="muted">(optional)</span></label>'
        . '<input id="ref" class="mono-in" type="text" name="ref" maxlength="80" autocomplete="off" spellcheck="false" value="' . palette_h($v('ref')) . '" aria-describedby="ref-hint" data-lookup="'
        . palette_h(palette_admin_url('issue', ['lookup' => ''])) . '">'
        . '<span class="hint" id="ref-hint" role="status">A Stripe cs_ or pi_ reference is looked up: a paid palette order gets its key linked and marked fulfilled.</span></div>'
        . $sel('lang', 'lg', 'Language of a new key and its email', PALETTE_LANG_NAMES, $v('lang') ?: 'en')
        . '<div class="fld"><label for="nt">Note (kept in history)</label><textarea id="nt" name="note" rows="2" maxlength="500" required>' . palette_h($v('note')) . '</textarea></div>'
        . '<label class="check"><input type="checkbox" name="notify" value="1"' . ($notify ? ' checked' : '') . '> Email the key to the customer now</label>'
        . '<div class="actions"><a class="btn" href="' . palette_h(palette_admin_url('licences')) . '">Cancel</a>'
        . '<button class="btn btn-accent" type="submit">Issue key</button></div></form></section>' . $after . '</div>';
    return ['title' => 'Issue a key', 'html' => $html];
}

// ---- payments tab

// Recent payments across Stripe, PayPal and admin orders plus subscription payments: problems first (paid with no key,
// failed with money touched), then newest. Each: at, email, amount ('$1.49'), provider (stripe|paypal|admin), status
// (paid|unfulfilled|failed|refunded|reversed), licence_id (?int), ref, livemode, problem. Abandoned checkouts are left out.
function palette_admin_payments(mysqli $c, int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    $out = [];
    $q = $c->query("SELECT id, order_id, email, amount_usd, status, provider, license_id, livemode, stripe_payment_intent, stripe_session, capture_id, created_at, captured_at,
            (status = 'failed' OR license_id IS NULL) AS problem
        FROM palette_orders
        WHERE status = 'captured' OR (status = 'failed' AND (COALESCE(stripe_payment_intent, '') <> '' OR COALESCE(capture_id, '') <> ''))
        ORDER BY problem DESC, COALESCE(captured_at, created_at) DESC, id DESC LIMIT $limit");
    foreach ($q as $o) {
        $out[] = ['at' => (string)($o['captured_at'] ?? $o['created_at']), 'email' => (string)$o['email'], 'amount' => palette_admin_usd((string)$o['amount_usd']),
            'provider' => (string)$o['provider'], 'status' => $o['status'] === 'failed' ? 'failed' : ($o['license_id'] === null ? 'unfulfilled' : 'paid'),
            'licence_id' => $o['license_id'] === null ? null : (int)$o['license_id'], 'livemode' => (int)$o['livemode'], 'problem' => (bool)$o['problem'],
            'ref' => (string)($o['stripe_payment_intent'] ?: ($o['capture_id'] ?: ($o['stripe_session'] ?: $o['order_id'])))];
    }
    $q = $c->query("SELECT p.sale_id, p.subscription_id, p.amount_usd, p.status, p.paid_at, s.email AS s_email, s.provider AS s_provider, s.livemode AS s_live,
            l.id AS lid, l.email AS l_email, l.livemode AS l_live
        FROM palette_sub_payments p
        LEFT JOIN palette_subscriptions s ON s.subscription_id = p.subscription_id
        LEFT JOIN palette_licenses l ON l.id = COALESCE(s.license_id, (SELECT l2.id FROM palette_licenses l2 WHERE l2.subscription_id = p.subscription_id ORDER BY l2.id LIMIT 1))
        ORDER BY p.paid_at DESC, p.id DESC LIMIT $limit");
    foreach ($q as $p) {
        $out[] = ['at' => (string)$p['paid_at'], 'email' => (string)($p['l_email'] ?? $p['s_email'] ?? ''), 'amount' => palette_admin_usd((string)$p['amount_usd']),
            'provider' => (string)($p['s_provider'] ?? (str_starts_with((string)$p['subscription_id'], 'sub_') ? 'stripe' : 'paypal')),
            'status' => ['completed' => 'paid', 'refunded' => 'refunded', 'reversed' => 'reversed'][$p['status']] ?? (string)$p['status'],
            'licence_id' => $p['lid'] === null ? null : (int)$p['lid'], 'livemode' => (int)($p['l_live'] ?? $p['s_live'] ?? 1), 'problem' => false, 'ref' => (string)$p['sale_id']];
    }
    usort($out, fn($a, $b) => [$b['problem'], $b['at']] <=> [$a['problem'], $a['at']]);
    return array_slice($out, 0, $limit);
}

const PALETTE_ADMIN_PAY_STATES = ['paid' => ['Paid', 'pill-ok'], 'unfulfilled' => ['Paid, no key', 'pill-bad'], 'failed' => ['Failed', 'pill-warn'],
    'refunded' => ['Refunded', 'pill-plain'], 'reversed' => ['Reversed', 'pill-plain']];

// Subscriptions whose renewal failed (status 'suspended', Stripe or PayPal), oldest failure first. Only the row that backs its
// licence (a moved licence's old PayPal row is not listed). Each: email, provider, price ('$3.99'), since (updated_at), licence_id.
function palette_admin_failed_renewals(mysqli $c, int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    $out = [];
    $q = $c->query("SELECT s.email AS s_email, s.provider, s.price_usd, s.updated_at, s.livemode, l.id AS lid, l.email AS l_email
        FROM palette_subscriptions s LEFT JOIN palette_licenses l ON l.id = s.license_id
        WHERE s.status = 'suspended' AND (l.id IS NULL OR l.subscription_id IS NULL OR l.subscription_id = s.subscription_id)
        ORDER BY s.updated_at, s.id LIMIT $limit");
    foreach ($q as $r) {
        $out[] = ['email' => (string)($r['l_email'] ?? $r['s_email']), 'provider' => (string)$r['provider'],
            'price' => $r['price_usd'] === null ? '' : palette_admin_usd((string)$r['price_usd']), 'since' => (string)$r['updated_at'],
            'licence_id' => $r['lid'] === null ? null : (int)$r['lid'], 'livemode' => (int)$r['livemode']];
    }
    return $out;
}

function palette_admin_tab_payments(mysqli $c, array $session, array $query): array
{
    $failed = '';
    foreach (palette_admin_failed_renewals($c) as $f) {
        $test = $f['livemode'] === 0 ? ' <span class="badge badge-test">Test</span>' : '';
        $failed .= '<tr><td class="muted">' . palette_h(palette_admin_date($f['since'], 'j M Y, H:i')) . '</td><td>' . palette_h($f['email']) . '</td>'
            . '<td>' . palette_h($f['price']) . '</td><td>' . palette_h($f['provider'] === 'stripe' ? 'Stripe' : 'PayPal') . $test . '</td>'
            . '<td><span class="pill pill-warn">Renewal failed</span></td><td class="end">'
            . ($f['licence_id'] !== null ? '<a class="btn btn-sm" href="' . palette_h(palette_admin_url('licence', ['id' => $f['licence_id']])) . '">Open licence</a>' : '') . '</td></tr>';
    }
    $failedHtml = $failed === '' ? '' : '<section class="card"><h2 class="card-title">Failed renewals</h2><p class="hint card-note">Subscriptions whose last renewal did not go through. '
        . 'Access ends at the paid-through date unless a retried payment succeeds.</p><div class="table-wrap"><table><thead><tr><th>Since</th><th>Email</th>'
        . '<th>Price</th><th>Provider</th><th>Status</th><th><span class="sr-only">Licence</span></th></tr></thead><tbody>' . $failed . '</tbody></table></div></section>';
    $body = '';
    foreach (palette_admin_payments($c) as $p) {
        [$label, $cls] = PALETTE_ADMIN_PAY_STATES[$p['status']] ?? [$p['status'], 'pill-plain'];
        $test = $p['livemode'] === 0 ? ' <span class="badge badge-test">Test</span>' : '';
        if ($p['licence_id'] !== null) {
            $link = '<a class="btn btn-sm" href="' . palette_h(palette_admin_url('licence', ['id' => $p['licence_id']])) . '">Open licence</a>';
        } elseif ($p['problem']) {
            $link = '<a class="btn btn-sm" href="' . palette_h(palette_admin_url('issue', ['email' => $p['email'], 'ref' => $p['ref']])) . '">Issue a key</a>';
        } else {
            $link = '';
        }
        $body .= '<tr><td class="muted">' . palette_h(palette_admin_date($p['at'], 'j M Y, H:i')) . '</td><td>' . palette_h($p['email']) . '</td>'
            . '<td>' . palette_h($p['amount']) . '</td><td>' . palette_h(['stripe' => 'Stripe', 'paypal' => 'PayPal', 'admin' => 'Admin'][$p['provider']] ?? $p['provider']) . $test . '</td>'
            . '<td><span class="pill ' . $cls . '">' . palette_h($label) . '</span></td><td class="mono muted">' . palette_h(palette_admin_short($p['ref'])) . '</td>'
            . '<td class="end">' . $link . '</td></tr>';
    }
    if ($body === '') $body = '<tr><td colspan="7" class="empty-cell">No payments yet.</td></tr>';
    return ['title' => 'Payments', 'html' => $failedHtml . '<section class="card"><p class="hint card-note">Paid without a key and failed payments first, then the newest. Times are UTC.</p>'
        . '<div class="table-wrap"><table><thead><tr><th>Date</th><th>Email</th><th>Amount</th><th>Provider</th><th>Status</th><th>Reference</th>'
        . '<th><span class="sr-only">Licence</span></th></tr></thead><tbody>' . $body . '</tbody></table></div></section>'];
}

// ---- move a PayPal subscriber to card
// Token = base64url("<licence id>.<expires unix>") . "." . base64url(HMAC-SHA256("palette-move:<licence id>.<expires>", palette_sub_secret)).
// Valid 7 days, one use: when a move fulfils, palette_st_fulfil_sub writes a 'move_used' admin log row for the licence, and every
// token minted before that row is spent.

const PALETTE_MOVE_TTL = 7 * 86400;
const PALETTE_MOVE_URL = 'https://vietnamcos.com/palette/checkout?move=';
// Stripe Checkout refuses a subscription trial_end less than 48 hours ahead. A move always gets a trial (never a double
// charge): at least 48 h + 5 min, so a PayPal period ending sooner gives the customer up to about two free days.
const PALETTE_MOVE_MIN_TRIAL = 48 * 3600 + 300;

function palette_b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function palette_admin_move_sig(mysqli $c, string $payload): string
{
    return palette_b64url(hash_hmac('sha256', 'palette-move:' . $payload, palette_sub_secret($c), true));
}

function palette_admin_move_token(mysqli $c, int $licenceId, int $expires): string
{
    $payload = $licenceId . '.' . $expires;
    return palette_b64url($payload) . '.' . palette_admin_move_sig($c, $payload);
}

// The live PayPal subscription row a licence can move from, or null: a Pro + AI licence, not revoked, currently backed by an
// active or suspended PayPal subscription.
function palette_admin_move_paypal_row(mysqli $c, ?array $lic): ?array
{
    if (!$lic || $lic['plan'] !== 'ai' || $lic['revoked_at'] !== null) return null;
    $row = palette_admin_sub_row($c, (int)$lic['id']);
    if (!$row || ($row['provider'] ?? 'paypal') !== 'paypal' || !in_array($row['status'], ['active', 'suspended'], true)) return null;
    return $row['subscription_id'] === $lic['subscription_id'] ? $row : null;
}

function palette_admin_move_used(mysqli $c, int $licenceId, int $mintedAt): bool
{
    $since = gmdate('Y-m-d H:i:s', $mintedAt);
    $st = $c->prepare("SELECT 1 FROM palette_admin_log WHERE action = 'move_used' AND license_id = ? AND created_at >= ? LIMIT 1");
    $st->bind_param('is', $licenceId, $since);
    $st->execute();
    $used = $st->get_result()->num_rows > 0;
    $st->close();
    return $used;
}

// ['ok' => true, 'licence' => row, 'expires' => int, 'paypal' => subscription row] or ['ok' => false, 'error' => invalid|expired|used|not_eligible].
function palette_admin_move_check(mysqli $c, string $token, ?int $now = null): array
{
    $now ??= time();
    $bad = ['ok' => false, 'error' => 'invalid'];
    if (strlen($token) > 120 || !preg_match('/^([A-Za-z0-9_-]{4,40})\.([A-Za-z0-9_-]{43})$/D', $token, $m)) return $bad;
    $payload = base64_decode(strtr($m[1], '-_', '+/'), true);
    if (!is_string($payload) || !preg_match('/^([1-9]\d{0,9})\.(\d{1,12})$/D', $payload, $p)) return $bad;
    if (!hash_equals(palette_admin_move_sig($c, $payload), $m[2])) return $bad;
    $id = (int)$p[1];
    $exp = (int)$p[2];
    if ($exp > $now + PALETTE_MOVE_TTL + 300) return $bad;   // never minted for longer than 7 days
    if ($exp < $now) return ['ok' => false, 'error' => 'expired'];
    if (palette_admin_move_used($c, $id, $exp - PALETTE_MOVE_TTL)) return ['ok' => false, 'error' => 'used'];
    $lic = palette_admin_licence_row($c, $id);
    $pp = palette_admin_move_paypal_row($c, $lic);
    if (!$pp) return ['ok' => false, 'error' => 'not_eligible'];
    return ['ok' => true, 'licence' => $lic, 'expires' => $exp, 'paypal' => $pp];
}

// The Stripe trial_end (unix seconds, as a string) for a move: max(PayPal paid-through, now + 48 h + 5 min).
function palette_admin_move_trial_end(array $lic, ?int $now = null): string
{
    $now ??= time();
    $t = is_string($lic['paid_through'] ?? null) ? strtotime($lic['paid_through'] . ' UTC') : false;
    return (string)max($t === false ? 0 : $t, $now + PALETTE_MOVE_MIN_TRIAL);
}

// Emails the licence's owner the one-use move link in their language. ['ok' => true] or ['ok' => false, 'error' => not_found|not_paypal|mail].
function palette_admin_move_link(mysqli $c, int $adminId, int $licenceId, string $note): array
{
    $lic = palette_admin_licence_row($c, $licenceId);
    if (!$lic) return ['ok' => false, 'error' => 'not_found'];
    if (!palette_admin_move_paypal_row($c, $lic)) return ['ok' => false, 'error' => 'not_paypal'];
    // The link leads to a live Stripe checkout: it must be able to take the card.
    if (!in_array('stripe', palette_stripe_settings($c)['checkout_methods'], true) || !palette_stripe_ready($c, 'live')) return ['ok' => false, 'error' => 'stripe_not_live'];
    $exp = time() + PALETTE_MOVE_TTL;
    $lang = palette_lang((string)$lic['lang']);
    $url = PALETTE_MOVE_URL . palette_admin_move_token($c, $licenceId, $exp) . '&lang=' . rawurlencode($lang);
    $trial = palette_admin_move_trial_end($lic);
    $m = palette_move_email($url, $lang, gmdate('Y-m-d', (int)$trial));
    if (!palette_mail((string)$lic['email'], $m['subject'], $m['html'])) {
        error_log('PALETTE_MAIL_FAILED admin_move_link licence=' . $licenceId);
        return ['ok' => false, 'error' => 'mail'];
    }
    $note = trim($note);
    palette_admin_log($c, $adminId, 'move_link', $licenceId, null, ['expires' => gmdate('Y-m-d H:i:s', $exp), 'first_charge' => gmdate('Y-m-d', (int)$trial)], $note !== '' ? $note : null);
    return ['ok' => true, 'error' => null];
}

function palette_admin_do_move_link(mysqli $c, array $session, array $post): array
{
    $id = palette_admin_post_id($post);
    $note = is_string($post['note'] ?? null) ? $post['note'] : '';
    return palette_admin_result(palette_admin_move_link($c, (int)($session['user_id'] ?? 0), $id, $note), $id, 'move_sent');
}

// Licence page: a warning for each PayPal subscription a move to card could not cancel, while PayPal may still bill it.
function palette_admin_move_warnings(mysqli $c, int $licenceId): string
{
    $out = '';
    $st = $c->prepare("SELECT DISTINCT order_ref FROM palette_admin_log WHERE action = 'move_paypal_cancel_failed' AND license_id = ?");
    $st->bind_param('i', $licenceId);
    $st->execute();
    foreach ($st->get_result() as $r) {
        $pp = palette_sub_row($c, 'subscription_id', (string)$r['order_ref']);
        if (!$pp || !in_array($pp['status'], ['active', 'suspended'], true)) continue;
        $out .= '<p class="flash flash-err" role="alert">The customer moved to card, but PayPal could not cancel subscription <span class="mono">'
            . palette_h((string)$r['order_ref']) . '</span>. Cancel it in PayPal now so they are not charged twice.</p>';
    }
    $st->close();
    return $out;
}

// ---- settings tab: Stripe connection (2026-11-04)
// Results that must not travel in the URL (a check's six lines, a Stripe error text, the disconnect confirm step) live in the
// session for one render. Secrets are only ever written (st_connect); the page receives hints and statuses.

function palette_admin_st_mode(array $post): ?string
{
    $m = is_string($post['mode'] ?? null) ? $post['mode'] : '';
    return in_array($m, ['live', 'test'], true) ? $m : null;
}

function palette_admin_st_notice(string $text): void
{
    $_SESSION['palette_admin_st_notice'] = mb_substr($text, 0, 300);
}

function palette_admin_do_st_connect(mysqli $c, array $session, array $post): array
{
    $mode = palette_admin_st_mode($post);
    if ($mode === null) return ['err' => 'st_bad_mode', 'tab' => 'settings'];
    $source = $mode === 'live' && ($post['source'] ?? '') === 'shared' ? 'shared' : 'own';
    $secret = is_string($post['secret'] ?? null) ? $post['secret'] : '';
    $r = palette_stripe_connect($c, $mode, $source, $secret);
    palette_admin_log($c, (int)($session['user_id'] ?? 0), 'st_connect', null, null,
        ['mode' => $mode, 'source' => $source, 'ok' => $r['ok'], 'error' => $r['ok'] ? null : 'connect_failed'], null);
    if (!$r['ok']) {
        palette_admin_st_notice((string)$r['error']);
        return ['tab' => 'settings'];
    }
    return ['ok' => 'st_connected', 'tab' => 'settings'];
}

function palette_admin_do_st_check(mysqli $c, array $session, array $post): array
{
    $mode = palette_admin_st_mode($post);
    if ($mode === null) return ['err' => 'st_bad_mode', 'tab' => 'settings'];
    $r = palette_stripe_check($c, $mode);
    $_SESSION['palette_admin_st_check'] = ['mode' => $mode, 'ok' => $r['ok'], 'checks' => $r['checks']];
    palette_admin_log($c, (int)($session['user_id'] ?? 0), 'st_check', null, null,
        ['mode' => $mode, 'ok' => $r['ok'], 'error' => $r['ok'] ? null : 'check_failed'], null);
    return ['tab' => 'settings'];
}

function palette_admin_do_st_disconnect(mysqli $c, array $session, array $post): array
{
    $mode = palette_admin_st_mode($post);
    if ($mode === null) return ['err' => 'st_bad_mode', 'tab' => 'settings'];
    if (($post['confirm'] ?? '') !== '1') {
        $_SESSION['palette_admin_st_confirm'] = $mode;   // step one: the page asks, nothing is touched
        return ['tab' => 'settings'];
    }
    $r = palette_stripe_disconnect($c, $mode);
    palette_admin_log($c, (int)($session['user_id'] ?? 0), 'st_disconnect', null, null,
        ['mode' => $mode, 'ok' => $r['ok'], 'error' => $r['ok'] ? null : 'disconnect_failed'], null);
    return $r['ok'] ? ['ok' => 'st_disconnected', 'tab' => 'settings'] : ['err' => 'st_disconnect_failed', 'tab' => 'settings'];
}

function palette_admin_do_methods(mysqli $c, array $session, array $post): array
{
    $picked = is_array($post['methods'] ?? null) ? array_values(array_filter($post['methods'], 'is_string')) : [];
    $order = ($post['order'] ?? '') === 'paypal_first' ? ['paypal', 'stripe'] : ['stripe', 'paypal'];
    $chosen = array_values(array_intersect($order, $picked));
    $r = palette_stripe_set_methods($c, $chosen);
    palette_admin_log($c, (int)($session['user_id'] ?? 0), 'methods', null, null,
        ['methods' => $chosen, 'ok' => $r['ok'], 'error' => $r['ok'] ? null : 'refused'], null);
    if (!$r['ok']) {
        palette_admin_st_notice((string)$r['error']);
        return ['tab' => 'settings'];
    }
    return ['ok' => 'methods_saved', 'tab' => 'settings'];
}

function palette_admin_st_card(array $session, string $mode, array $p, ?array $check, bool $confirm): string
{
    $label = $mode === 'live' ? 'Live' : 'Test';
    $has = $p['key_hint'] !== '' || $p['webhook'];
    [$pill, $cls] = $p['error'] !== '' ? ['Error', 'pill-bad'] : ($p['connected'] ? ['Connected', 'pill-ok'] : ['Not connected', 'pill-plain']);
    $row = fn(string $k, string $v) => '<div class="kv"><span>' . $k . '</span><b>' . $v . '</b></div>';
    $out = '<section class="card card-pad st-card" aria-labelledby="st-' . $mode . '"><div class="st-head"><h2 id="st-' . $mode . '">' . $label . ' mode</h2>'
        . '<span class="pill ' . $cls . '">' . $pill . '</span></div>'
        . '<div class="kvs">' . $row('Key', $p['key_hint'] !== '' ? '<span class="mono">' . palette_h($p['key_hint']) . '</span>' : 'None')
        . $row('Webhook', 'Webhook: ' . ($p['webhook'] ? 'registered' : 'missing'))
        . $row('Last checked', $p['checked_at'] !== '' ? palette_h(palette_admin_date($p['checked_at'], 'j M Y H:i') . ' UTC') : 'Never') . '</div>';
    if ($p['error'] !== '') $out .= '<p class="hint is-bad" role="alert">' . palette_icon('alert', 14) . ' ' . palette_h($p['error']) . '</p>';

    if ($check !== null) {
        $out .= '<ul class="st-checks" aria-label="Connection checks">';
        foreach ($check['checks'] as $ch) {
            $out .= '<li class="check-line ' . ($ch['ok'] ? 'check-pass' : 'check-fail') . '">' . palette_icon($ch['ok'] ? 'check' : 'alert', 16)
                . '<span><b>' . palette_h($ch['label']) . '</b> <span class="muted">' . palette_h($ch['detail']) . '</span></span></li>';
        }
        $out .= '</ul>';
    }

    $form = fn(string $action) => '<form method="post" action="' . PALETTE_ADMIN_PAGE . '">' . palette_admin_csrf_field($session)
        . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="mode" value="' . $mode . '">';
    $own = $p['source'] === 'own';
    $out .= $form('st_connect') . '<div class="stack">';
    if ($mode === 'live') {
        $out .= '<fieldset class="fld st-src"><legend>Which Stripe account</legend>'
            . '<label class="check"><input type="radio" name="source" value="shared"' . ($own ? '' : ' checked') . '> Use the vietnamcos.com Stripe account</label>'
            . '<label class="check"><input type="radio" name="source" value="own"' . ($own ? ' checked' : '') . '> Use a separate key</label></fieldset>';
    } else {
        $out .= '<input type="hidden" name="source" value="own">';
    }
    $out .= '<div class="fld"><label for="sec-' . $mode . '">' . ($mode === 'live' ? 'Separate secret key (only for "Use a separate key")' : 'Test secret or restricted key') . '</label>'
        . '<input id="sec-' . $mode . '" class="mono-in" type="password" name="secret" autocomplete="off" spellcheck="false" placeholder="' . ($mode === 'live' ? 'sk_live_… or rk_live_…' : 'sk_test_… or rk_test_…') . '" aria-describedby="help-' . $mode . '"></div>'
        . '<div class="actions"><button class="btn btn-accent" type="submit">' . ($has ? 'Connect again' : 'Connect') . '</button></div></div></form>';

    $out .= '<div class="actions st-actions">' . $form('st_check') . '<button class="btn" type="submit">Test connection</button></form>';
    if ($has && !$confirm) $out .= $form('st_disconnect') . '<button class="btn btn-danger" type="submit">Disconnect</button></form>';
    $out .= '</div>';
    if ($confirm) {
        $out .= '<div class="st-confirm" role="alertdialog" aria-labelledby="cf-' . $mode . '"><p id="cf-' . $mode . '"><b>Disconnect ' . $label . '? Checkout stops taking card payments.</b></p>'
            . $form('st_disconnect') . '<input type="hidden" name="confirm" value="1"><div class="actions">'
            . '<a class="btn" href="' . palette_h(palette_admin_url('settings')) . '">Keep it connected</a>'
            . '<button class="btn btn-danger" type="submit">Disconnect ' . $label . '</button></div></form></div>';
    }

    $out .= '<p class="hint" id="help-' . $mode . '">Get keys at <span class="mono">dashboard.stripe.com/apikeys</span>'
        . ($mode === 'test' ? ' (switch the dashboard to test mode first)' : '') . '. A restricted key needs Balance read and Webhook endpoints write.</p>';
    if ($mode === 'test') {
        $out .= '<p class="hint"><a href="/palette/checkout?test=1">Open the test checkout</a> (admins only; pay with card 4242 4242 4242 4242).</p>';
    }
    return $out . '</section>';
}

function palette_admin_tab_settings(mysqli $c, array $session, array $query): array
{
    // One-render flashes from the last POST.
    $check = is_array($session['palette_admin_st_check'] ?? null) ? $session['palette_admin_st_check'] : null;
    $confirm = is_string($session['palette_admin_st_confirm'] ?? null) ? $session['palette_admin_st_confirm'] : '';
    $notice = is_string($session['palette_admin_st_notice'] ?? null) ? $session['palette_admin_st_notice'] : '';
    unset($_SESSION['palette_admin_st_check'], $_SESSION['palette_admin_st_confirm'], $_SESSION['palette_admin_st_notice']);

    $pub = palette_stripe_public($c);
    $html = $notice !== '' ? '<p class="flash flash-err" role="alert">' . palette_h($notice) . '</p>' : '';
    $html .= '<div class="st-grid">';
    foreach (['live', 'test'] as $mode) {
        $html .= palette_admin_st_card($session, $mode, $pub[$mode], ($check['mode'] ?? '') === $mode ? $check : null, $confirm === $mode);
    }
    $html .= '</div>';

    // Checkout methods: a method that is not connected cannot be switched on (the control says why).
    $on = $pub['checkout_methods'];
    $ready = ['stripe' => $pub['live']['connected'], 'paypal' => pp_ready(pp_settings($c))];
    $why = ['stripe' => 'Connect Stripe Live first.', 'paypal' => 'PayPal is not set up.'];
    $boxes = '';
    foreach (['stripe' => 'Stripe (card)', 'paypal' => 'PayPal'] as $k => $name) {
        $chk = in_array($k, $on, true);
        $off = !$ready[$k] && !$chk;
        $boxes .= '<label class="check"><input type="checkbox" name="methods[]" value="' . $k . '"' . ($chk ? ' checked' : '') . ($off ? ' disabled' : '') . '> ' . $name
            . ($off ? ' <span class="muted">' . $why[$k] . '</span>' : '') . '</label>';
    }
    $first = ($on[0] ?? 'stripe') === 'paypal' ? 'paypal_first' : 'stripe_first';
    $opts = '';
    foreach (['stripe_first' => 'Stripe first', 'paypal_first' => 'PayPal first'] as $v => $name) {
        $opts .= '<option value="' . $v . '"' . ($v === $first ? ' selected' : '') . '>' . $name . '</option>';
    }
    $html .= '<section class="card card-pad" aria-labelledby="st-methods"><h2 id="st-methods">Payment methods on checkout</h2>'
        . '<form class="stack" method="post" action="' . PALETTE_ADMIN_PAGE . '">' . palette_admin_csrf_field($session) . '<input type="hidden" name="action" value="methods">'
        . '<fieldset class="fld"><legend>Offer these methods</legend>' . $boxes . '</fieldset>'
        . '<div class="fld"><label for="st-order">When both are on, show first</label><select id="st-order" name="order">' . $opts . '</select></div>'
        . '<div class="actions"><button class="btn btn-accent" type="submit">Save methods</button></div></form></section>';
    return ['title' => 'Settings', 'html' => $html];
}
