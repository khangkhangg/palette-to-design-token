<?php
// Admin: issue a key, the payments tab, and moving a PayPal subscriber to card (lib/admin.php, Task 10).
require __DIR__ . '/helpers.php';
require __DIR__ . '/st-stub.php';
$GLOBALS['ai_log'] = sys_get_temp_dir() . '/palette-admin-issue-test.log';
@unlink($GLOBALS['ai_log']);
ini_set('error_log', $GLOBALS['ai_log']);
ob_start();
session_start();

const AI_ADMIN = 9;
$admin = ['user_id' => AI_ADMIN, 'user_role' => 'admin', 'csrf_tokens' => ['palette_admin' => str_repeat('a', 64)]];

function ai_reset(mysqli $c): void
{
    st_stub_reset($c);
    st_stub_connect($c, 'live');
    st_stub_connect($c, 'test');
    $c->query('DELETE FROM palette_rate');
    $c->query('DELETE FROM palette_ai_usage');
    // PayPal credentials for the cancel call only (not enabled: checkout methods stay Stripe only).
    $json = json_encode(['enabled' => false, 'mode' => 'sandbox', 'client_id' => 'cid', 'secret' => 'sec', 'error' => '']);
    $st = $c->prepare("INSERT INTO admin_settings (setting_key, setting_value) VALUES ('paypal', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $st->bind_param('s', $json);
    $st->execute();
    $GLOBALS['pp_routes'] = [];
    $GLOBALS['pp_calls'] = [];
    $GLOBALS['pp_http'] = function (string $method, string $url, array $headers, ?string $body): array {
        if ($url === 'https://api.resend.com/emails') {
            $GLOBALS['mails'][] = json_decode((string)$body, true);
            return ['status' => 200, 'json' => ['id' => 'mail' . count($GLOBALS['mails'])]];
        }
        if (str_ends_with($url, '/v1/oauth2/token')) return ['status' => 200, 'json' => ['access_token' => 'tok']];
        $path = (string)preg_replace('#^https://api-m\.(sandbox\.)?paypal\.com#', '', $url);
        $GLOBALS['pp_calls'][] = ['method' => $method, 'path' => $path, 'raw' => $body];
        return $GLOBALS['pp_routes']["$method $path"] ?? ['status' => 500, 'json' => ['message' => 'unrouted']];
    };
    $_SESSION = [];
}

function ai_count(mysqli $c, string $sql): int { return (int)$c->query($sql)->fetch_row()[0]; }
function ai_lic(mysqli $c, int $id): array { return $c->query("SELECT * FROM palette_licenses WHERE id = $id")->fetch_assoc(); }
function ai_logs(mysqli $c, string $action): array { return $c->query("SELECT * FROM palette_admin_log WHERE action = '" . $c->real_escape_string($action) . "' ORDER BY id")->fetch_all(MYSQLI_ASSOC); }
function ai_errlog(): string { return (string)@file_get_contents($GLOBALS['ai_log']); }

// A Stripe one-payment order for $email, with session $sid (as palette_st_order_start makes it).
function ai_order(mysqli $c, string $sid, string $email = 'aisha@example.ae', string $lang = 'ar', int $live = 1): array
{
    $GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => $sid, 'url' => 'https://checkout.stripe.com/c/pay/x']];
    $r = palette_st_order_start($c, $live ? 'live' : 'test', $email, $lang, true);
    return palette_order_by($c, 'id', $r['row_id']);
}

function ai_paid_session(array $o, string $pi = 'pi_3PaidOne', string $pay = 'paid'): array
{
    return ['id' => $o['stripe_session'], 'object' => 'checkout.session', 'payment_status' => $pay, 'status' => 'complete', 'amount_total' => 149, 'currency' => 'usd',
            'client_reference_id' => 'palette:order:' . $o['id'], 'metadata' => ['palette' => 'order', 'row' => (string)$o['id']],
            'livemode' => (int)$o['livemode'] === 1, 'payment_intent' => $pi, 'customer_details' => ['email' => $o['email']], 'created' => 1762160000];
}

// ======== issue a key
ai_reset($conn);

// ---- validation: nothing written
$bad = [
    ['note_required', ['new@example.com', 'pro', 'comp', '', '  ']],
    ['bad_email', ['nope', 'pro', 'comp', '', 'x']],
    ['bad_email', ["a@example.com\r\nBcc: x@y.z", 'pro', 'comp', '', 'x']],
    ['bad_plan', ['new@example.com', 'gold', 'comp', '', 'x']],
    ['bad_reason', ['new@example.com', 'pro', 'because', '', 'x']],
    ['bad_ref', ['new@example.com', 'pro', 'comp', 'cs_live_<script>', 'x']],
];
foreach ($bad as [$code, [$e, $p, $why, $ref, $note]]) {
    $r = palette_admin_issue($conn, AI_ADMIN, $e, $p, $why, $ref, $note, true);
    ok("issue refuses $code", $r === ['ok' => false, 'error' => $code]);
}
ok('issue refuses bad email: nothing written, mailed or called', ai_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 0
    && ai_count($conn, 'SELECT COUNT(*) FROM palette_admin_log') === 0 && !$GLOBALS['mails'] && !$GLOBALS['st_calls']);

// ---- issue comp
$r = palette_admin_issue($conn, AI_ADMIN, ' New@Example.com ', 'pro', 'comp', '', 'Influencer copy', true, 'fr');
$l = $conn->query('SELECT * FROM palette_licenses')->fetch_all(MYSQLI_ASSOC);
$log = ai_logs($conn, 'issue');
ok('issue comp: ok with a new key', $r['ok'] === true && preg_match(PALETTE_KEY_RE, $r['key']) === 1 && $r['existing'] === false && count($l) === 1 && $l[0]['license_key'] === $r['key']);
ok('issue comp: admin licence, comp, pro, lowercased email, language kept', $l[0]['email'] === 'new@example.com' && str_starts_with($l[0]['order_id'], 'ADM-')
    && preg_match('/^ADM-[0-9A-F]{16}$/', $l[0]['order_id']) === 1 && $l[0]['source'] === 'admin' && $l[0]['pay_status'] === 'comp' && $l[0]['plan'] === 'pro' && $l[0]['lang'] === 'fr');
ok('issue comp: one log row with the reason and note, no key or email in it', count($log) === 1 && (int)$log[0]['license_id'] === (int)$l[0]['id'] && (int)$log[0]['admin_user_id'] === AI_ADMIN
    && $log[0]['note'] === 'Influencer copy' && json_decode($log[0]['detail'], true)['reason'] === 'comp'
    && !str_contains((string)$log[0]['detail'], 'SDT-') && !str_contains((string)$log[0]['detail'], 'new@example'));
ok('issue comp: notify mails the key once, in the chosen language', count($GLOBALS['mails']) === 1 && $GLOBALS['mails'][0]['to'] === ['new@example.com']
    && $GLOBALS['mails'][0]['subject'] === palette_t('fr', 'email_license_subject') && str_contains($GLOBALS['mails'][0]['html'], $r['key']) && $r['mailed'] === true);

// ---- AI, paid another way, no mail
$GLOBALS['mails'] = [];
$r = palette_admin_issue($conn, AI_ADMIN, 'bank@example.com', 'ai', 'paid_other', 'BANK-2026-11-04', 'Bank transfer', false);
$row = $conn->query("SELECT * FROM palette_licenses WHERE license_key = '" . $r['key'] . "'")->fetch_assoc();
$pt = strtotime($row['paid_through'] . ' UTC');
ok('issue ai: manual_paid, plan ai, paid through about a month ahead', $r['ok'] && $row['plan'] === 'ai' && $row['pay_status'] === 'manual_paid' && $row['source'] === 'admin'
    && $pt > time() + 27 * 86400 && $pt < time() + 33 * 86400 && palette_licence_access($row)['ai'] === true);
ok('issue ai: no notify, no mail', !$GLOBALS['mails'] && $r['mailed'] === false);
$log = ai_logs($conn, 'issue');
ok('issue: a non-Stripe reference is recorded in the log only', end($log)['order_ref'] === 'BANK-2026-11-04' && !$GLOBALS['st_calls']);
$r2 = palette_admin_issue($conn, AI_ADMIN, 'bank@example.com', 'ai', 'paid_other', 'BANK-2026-11-04', 'Bank transfer', false);
ok('issue: same reference twice -> same key, existing', $r2['ok'] && $r2['key'] === $r['key'] && $r2['existing'] === true
    && ai_count($conn, "SELECT COUNT(*) FROM palette_licenses WHERE email = 'bank@example.com'") === 1);

$r3 = palette_admin_issue($conn, AI_ADMIN, 'someone@example.com', 'ai', 'paid_other', 'BANK-2026-11-04', 'Typo', false);
ok('issue: a reference already used for another email is refused, not shown', $r3 === ['ok' => false, 'error' => 'ref_other_email']
    && ai_count($conn, "SELECT COUNT(*) FROM palette_licenses WHERE email = 'someone@example.com'") === 0);

// ---- double-click without a reference
$a = palette_admin_issue($conn, AI_ADMIN, 'twice@example.com', 'pro', 'replacement', '', 'Lost key', false);
$b = palette_admin_issue($conn, AI_ADMIN, 'twice@example.com', 'pro', 'replacement', '', 'Lost key', false);
ok('issue: double submit without a reference -> one licence, the second shows it', $a['ok'] && $b['ok'] && $a['key'] === $b['key'] && $b['existing'] === true
    && ai_count($conn, "SELECT COUNT(*) FROM palette_licenses WHERE email = 'twice@example.com'") === 1
    && $conn->query("SELECT pay_status FROM palette_licenses WHERE email = 'twice@example.com'")->fetch_row()[0] === 'manual_paid');

// ---- links a paid Stripe order
ai_reset($conn);
$o = ai_order($conn, 'cs_live_PaidButNoKey1');
$GLOBALS['st_calls'] = [];
$GLOBALS['st_routes']['GET /v1/checkout/sessions/cs_live_PaidButNoKey1'] = ['status' => 200, 'json' => ai_paid_session($o)];
$r = palette_admin_issue($conn, AI_ADMIN, 'aisha@example.ae', 'pro', 'paid_no_key', 'cs_live_PaidButNoKey1', 'Webhook missed', false);
$o2 = palette_order_by($conn, 'id', (int)$o['id']);
$lic = $o2['license_id'] ? ai_lic($conn, (int)$o2['license_id']) : [];
ok('issue links paid stripe order: the order holds the key and is captured', $r['ok'] && $r['existing'] === false && $o2['status'] === 'captured'
    && ($lic['license_key'] ?? '') === $r['key'] && $lic['source'] === 'stripe' && $o2['stripe_payment_intent'] === 'pi_3PaidOne');
ok('issue links paid stripe order: Stripe GET with the live key', ($GLOBALS['st_calls'][0]['method'] ?? '') === 'GET' && $GLOBALS['st_calls'][0]['path'] === '/v1/checkout/sessions/cs_live_PaidButNoKey1'
    && in_array('Authorization: Bearer sk_live_x', $GLOBALS['st_calls'][0]['headers'], true));
ok('issue links paid stripe order: the buyer gets the usual key email (once), in the order language', count($GLOBALS['mails']) === 1 && $GLOBALS['mails'][0]['subject'] === palette_t('ar', 'email_license_subject'));
$log = ai_logs($conn, 'issue');
ok('issue links paid stripe order: logged with the reference', count($log) === 1 && $log[0]['order_ref'] === 'cs_live_PaidButNoKey1' && (int)$log[0]['license_id'] === (int)$lic['id']
    && json_decode($log[0]['detail'], true)['linked'] === true);

// ---- issue idempotent
$GLOBALS['st_calls'] = [];
$r2 = palette_admin_issue($conn, AI_ADMIN, 'aisha@example.ae', 'pro', 'paid_no_key', 'cs_live_PaidButNoKey1', 'Again', true);
ok('issue idempotent: same cs_ ref -> same key, existing, one licence', $r2['ok'] && $r2['key'] === $r['key'] && $r2['existing'] === true
    && ai_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 1);
ok('issue idempotent: no Stripe call and no second mail', !$GLOBALS['st_calls'] && count($GLOBALS['mails']) === 1);

// ---- a pi_ reference: the session is found by its payment intent
$o = ai_order($conn, 'cs_live_ByIntent2', 'kofi@example.gh', 'en');
$GLOBALS['st_calls'] = [];
$GLOBALS['st_routes']['GET /v1/checkout/sessions'] = function (array $p) use ($o) {
    return ($p['payment_intent'] ?? '') === 'pi_3ByIntent' ? ['status' => 200, 'json' => ['object' => 'list', 'data' => [ai_paid_session($o, 'pi_3ByIntent')]]] : ['status' => 200, 'json' => ['data' => []]];
};
$r = palette_admin_issue($conn, AI_ADMIN, 'kofi@example.gh', 'pro', 'paid_no_key', 'pi_3ByIntent', 'From the Stripe dashboard', false);
$o2 = palette_order_by($conn, 'id', (int)$o['id']);
ok('issue pi_ ref: sessions listed by payment_intent, order fulfilled', $r['ok'] && $r['existing'] === false && $o2['status'] === 'captured' && $o2['license_id'] !== null
    && ($GLOBALS['st_calls'][0]['path'] ?? '') === '/v1/checkout/sessions' && str_contains((string)$GLOBALS['st_calls'][0]['url'], 'payment_intent=pi_3ByIntent'));
$r2 = palette_admin_issue($conn, AI_ADMIN, 'kofi@example.gh', 'pro', 'paid_no_key', 'pi_3ByIntent', 'again', false);
ok('issue pi_ ref: second time existing', $r2['existing'] === true && $r2['key'] === $r['key']);

// ---- found, not paid, but the checkout can still complete -> refused (order_pending), nothing issued
$o = ai_order($conn, 'cs_live_NotPaid3', 'unpaid@example.com', 'es');
$n = ai_count($conn, 'SELECT COUNT(*) FROM palette_licenses');
foreach (['open' => ['status' => 'open', 'payment_status' => 'unpaid'], 'processing' => ['status' => 'complete', 'payment_status' => 'unpaid']] as $label => $over) {
    $GLOBALS['st_routes']['GET /v1/checkout/sessions/cs_live_NotPaid3'] = ['status' => 200, 'json' => $over + ai_paid_session($o, 'pi_x', 'unpaid')];
    $r = palette_admin_issue($conn, AI_ADMIN, 'unpaid@example.com', 'pro', 'paid_other', 'cs_live_NotPaid3', 'Paid by bank instead', false);
    ok("issue order_pending: session $label -> refused, no licence, no log", $r === ['ok' => false, 'error' => 'order_pending']
        && ai_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === $n && !array_filter(ai_logs($conn, 'issue'), fn($x) => $x['order_ref'] === 'cs_live_NotPaid3'));
}
// ---- found, not paid, and the checkout expired -> admin licence, ref recorded
$GLOBALS['st_routes']['GET /v1/checkout/sessions/cs_live_NotPaid3'] = ['status' => 200, 'json' => ['status' => 'expired'] + ai_paid_session($o, 'pi_x', 'unpaid')];
$r = palette_admin_issue($conn, AI_ADMIN, 'unpaid@example.com', 'pro', 'paid_other', 'cs_live_NotPaid3', 'Paid by bank instead', false);
$lic = $conn->query("SELECT * FROM palette_licenses WHERE license_key = '" . $r['key'] . "'")->fetch_assoc();
$log = ai_logs($conn, 'issue');
ok('issue not paid in Stripe: admin licence, order untouched', $r['ok'] && $lic['source'] === 'admin' && $lic['pay_status'] === 'manual_paid' && str_starts_with($lic['order_id'], 'ADM-')
    && palette_order_by($conn, 'id', (int)$o['id'])['status'] === 'created' && palette_order_by($conn, 'id', (int)$o['id'])['license_id'] === null);
ok('issue not paid in Stripe: ref and Stripe state in the log', end($log)['order_ref'] === 'cs_live_NotPaid3' && json_decode(end($log)['detail'], true)['stripe'] === 'not_paid');
$r2 = palette_admin_issue($conn, AI_ADMIN, 'unpaid@example.com', 'pro', 'paid_other', 'cs_live_NotPaid3', 'Paid by bank instead', false);
ok('issue not paid in Stripe: idempotent too', $r2['existing'] === true && $r2['key'] === $r['key']);

// ---- a pending Pro + AI subscription checkout: open -> order_pending; expired -> admin licence
$GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_live_SubPend7', 'url' => 'https://checkout.stripe.com/c/pay/sp']];
$sp = palette_st_sub_start($conn, 'live', 'subpend@example.com', 'en', true);
$GLOBALS['st_routes']['GET /v1/checkout/sessions/cs_live_SubPend7'] = ['status' => 200, 'json' => ['id' => 'cs_live_SubPend7', 'status' => 'open', 'payment_status' => 'unpaid']];
$r = palette_admin_issue($conn, AI_ADMIN, 'subpend@example.com', 'ai', 'paid_no_key', 'cs_live_SubPend7', 'x', false);
ok('issue order_pending: open subscription checkout refused', $r === ['ok' => false, 'error' => 'order_pending']
    && ai_count($conn, "SELECT COUNT(*) FROM palette_licenses WHERE email = 'subpend@example.com'") === 0);
$GLOBALS['st_routes']['GET /v1/checkout/sessions/cs_live_SubPend7'] = ['status' => 200, 'json' => ['id' => 'cs_live_SubPend7', 'status' => 'expired', 'payment_status' => 'unpaid']];
$r = palette_admin_issue($conn, AI_ADMIN, 'subpend@example.com', 'ai', 'paid_no_key', 'cs_live_SubPend7', 'x', false);
ok('issue: expired subscription checkout -> admin licence', $r['ok'] === true && $r['existing'] === false
    && $conn->query("SELECT source FROM palette_licenses WHERE license_key = '" . $r['key'] . "'")->fetch_row()[0] === 'admin');

// ---- unknown cs_ (not a palette order): admin licence, no Stripe call
$GLOBALS['st_calls'] = [];
$r = palette_admin_issue($conn, AI_ADMIN, 'other@example.com', 'pro', 'paid_other', 'cs_live_CompaniesOwn9', 'Paid on companies by mistake', false);
ok('issue unknown cs_ ref: admin licence, no Stripe call', $r['ok'] && $r['existing'] === false && !$GLOBALS['st_calls']
    && $conn->query("SELECT source FROM palette_licenses WHERE license_key = '" . $r['key'] . "'")->fetch_row()[0] === 'admin');

// ---- Stripe down: nothing issued
$o = ai_order($conn, 'cs_live_StripeDown4', 'down@example.com');
$GLOBALS['st_routes']['GET /v1/checkout/sessions/cs_live_StripeDown4'] = ['status' => 503, 'json' => []];
$n = ai_count($conn, 'SELECT COUNT(*) FROM palette_licenses');
$r = palette_admin_issue($conn, AI_ADMIN, 'down@example.com', 'pro', 'paid_no_key', 'cs_live_StripeDown4', 'x', false);
ok('issue: Stripe unreachable -> err stripe, no licence', $r === ['ok' => false, 'error' => 'stripe'] && ai_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === $n);

// ======== ref lookup
ai_reset($conn);
$o = ai_order($conn, 'cs_live_Lookup5', 'aisha@example.ae', 'ar');
$GLOBALS['st_routes']['GET /v1/checkout/sessions/cs_live_Lookup5'] = ['status' => 200, 'json' => ai_paid_session($o)];
$GLOBALS['st_calls'] = [];
$x = palette_admin_ref_lookup($conn, 'cs_live_Lookup5');
ok('ref lookup: paid palette order', $x['found'] === true && $x['paid'] === true && $x['amount'] === '$1.49' && $x['email'] === 'aisha@example.ae'
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$x['date']) === 1 && $x['lang'] === 'ar' && $x['has_key'] === false);
ok('ref lookup: read-only (order still created, no licence)', palette_order_by($conn, 'id', (int)$o['id'])['status'] === 'created' && ai_count($conn, 'SELECT COUNT(*) FROM palette_licenses') === 0);
$GLOBALS['st_calls'] = [];
$x = palette_admin_ref_lookup($conn, 'cs_live_x"><script>');
ok('ref lookup: bad format refused before any Stripe call', $x['found'] === false && $x['paid'] === false && $x['email'] === null && !$GLOBALS['st_calls']);
$x = palette_admin_ref_lookup($conn, 'cs_live_NoSuchOrder');
ok('ref lookup: unknown reference not found', $x['found'] === false && $x['paid'] === false);
// through the router: admin only, JSON
$r = palette_admin_page($conn, $admin, ['tab' => 'issue', 'lookup' => 'cs_live_Lookup5'], [], 'GET');
$j = json_decode($r['body'], true);
ok('ref lookup via router: JSON, no-store', $r['status'] === 200 && str_starts_with($r['headers']['Content-Type'], 'application/json') && $r['headers']['Cache-Control'] === 'no-store'
    && $j['found'] === true && $j['paid'] === true);
$r = palette_admin_page($conn, ['user_role' => 'user'], ['tab' => 'issue', 'lookup' => 'cs_live_Lookup5'], [], 'GET');
ok('ref lookup via router: not admin -> 403', $r['status'] === 403 && !str_contains($r['body'], 'aisha'));

// ======== issue through the router: key shown once, never in the URL
ai_reset($conn);
$post = ['csrf' => str_repeat('a', 64), 'action' => 'issue', 'email' => 'router@example.com', 'plan' => 'pro', 'reason' => 'comp', 'ref' => '', 'note' => 'Friend', 'lang' => 'es'];
$r = palette_admin_page($conn, $admin, [], $post, 'POST');
$key = (string)$conn->query('SELECT license_key FROM palette_licenses')->fetch_row()[0];
ok('issue via router: 303 back to the issue tab with ok=issued, key not in the URL', $r['status'] === 303 && str_contains($r['headers']['Location'], 'tab=issue')
    && str_contains($r['headers']['Location'], 'ok=issued') && !str_contains($r['headers']['Location'], $key) && $key !== '');
ok('issue via router: key held in the session flash', ($_SESSION['palette_admin_issued']['key'] ?? '') === $key);
$page = palette_admin_page($conn, $admin + $_SESSION, ['tab' => 'issue', 'ok' => 'issued'], [], 'GET');
ok('issue tab: shows the key once', $page['status'] === 200 && str_contains($page['body'], $key) && str_contains($page['body'], 'Key ready'));
ok('issue tab: flash consumed', !isset($_SESSION['palette_admin_issued']));
$page = palette_admin_page($conn, $admin + $_SESSION, ['tab' => 'issue'], [], 'GET');
ok('issue tab: second render has no key, has the form', !str_contains($page['body'], $key) && str_contains($page['body'], 'name="action" value="issue"')
    && str_contains($page['body'], 'Customer email') && str_contains($page['body'], 'Payment reference') && !str_contains($page['body'], 'Coming in a later task'));
ok('issue log: key never logged', !str_contains((string)$conn->query('SELECT GROUP_CONCAT(CONCAT_WS("|", detail, note, order_ref)) FROM palette_admin_log')->fetch_row()[0], $key));
$r = palette_admin_page($conn, $admin, [], ['note' => ''] + $post, 'POST');
ok('issue via router: no note -> err=note_required', str_contains($r['headers']['Location'], 'err=note_required') && str_contains($r['headers']['Location'], 'tab=issue'));
$r = palette_admin_page($conn, $admin, [], ['csrf' => 'nope'] + $post, 'POST');
ok('issue via router: bad csrf -> 403', $r['status'] === 403);

// ======== payments tab
ai_reset($conn);
$l1 = palette_issue_license($conn, 'paid@example.com', 'ST-PAID', 'en', null, 'stripe')['id'];
$conn->query("INSERT INTO palette_orders (order_id, email, lang, amount_usd, status, consent_at, created_at, captured_at, license_id, provider, stripe_payment_intent)
              VALUES ('ST-PAID', 'paid@example.com', 'en', 1.49, 'captured', NOW(), '2026-11-03 10:00:00', '2026-11-03 10:01:00', $l1, 'stripe', 'pi_paid')");
$conn->query("INSERT INTO palette_orders (order_id, email, lang, amount_usd, status, consent_at, created_at, captured_at, provider)
              VALUES ('PP-NOKEY', 'nokey@example.com', 'en', 1.49, 'captured', NOW(), '2026-10-01 10:00:00', '2026-10-01 10:01:00', 'paypal')");
$conn->query("INSERT INTO palette_orders (order_id, email, lang, amount_usd, status, consent_at, created_at, provider, stripe_payment_intent)
              VALUES ('ST-FAIL', 'fail@example.com', 'en', 1.49, 'failed', NOW(), '2026-09-01 10:00:00', 'stripe', 'pi_fail')");
$conn->query("INSERT INTO palette_orders (order_id, email, lang, amount_usd, status, consent_at, created_at, provider)
              VALUES ('ST-ABANDON', 'gone@example.com', 'en', 1.49, 'created', NOW(), '2026-11-04 10:00:00', 'stripe')");
$l2 = palette_issue_license($conn, 'sub@example.com', 'sub_PAY1', 'fr', ['subscription_id' => 'sub_PAY1', 'paid_through' => '2026-12-04 00:00:00', 'period_start' => '2026-11-04 00:00:00'], 'stripe')['id'];
$conn->query("INSERT INTO palette_sub_payments (subscription_id, sale_id, amount_usd, status, paid_at) VALUES ('sub_PAY1', 'in_PAY1', 3.99, 'completed', '2026-11-04 08:00:00')");
$conn->query("INSERT INTO palette_subscriptions (subscription_id, email, lang, status, plan_id, license_id, consent_at, created_at, updated_at, provider, livemode, price_usd)
              VALUES ('sub_PAY1', 'sub@example.com', 'fr', 'suspended', 'stripe', $l2, NOW(), NOW(), '2026-11-02 06:00:00', 'stripe', 1, 3.99),
                     ('I-FAILEDPP1', 'pp-late@example.com', 'en', 'suspended', 'P-1', NULL, NOW(), NOW(), '2026-11-01 06:00:00', 'paypal', 1, 3.99),
                     ('I-ACTIVEPP1', 'pp-ok@example.com', 'en', 'active', 'P-1', NULL, NOW(), NOW(), '2026-11-01 06:00:00', 'paypal', 1, 3.99)");
$fr = palette_admin_failed_renewals($conn);
ok('failed renewals: suspended Stripe and PayPal rows, oldest first', array_column($fr, 'email') === ['pp-late@example.com', 'sub@example.com']
    && $fr[0]['provider'] === 'paypal' && $fr[1]['provider'] === 'stripe' && $fr[1]['price'] === '$3.99' && $fr[1]['licence_id'] === $l2 && $fr[0]['licence_id'] === null
    && $fr[1]['since'] === '2026-11-02 06:00:00');
$h = palette_admin_page($conn, $admin, ['tab' => 'payments'], [], 'GET')['body'];
ok('payments tab: failed renewals listed above the payments', str_contains($h, 'Failed renewals') && strpos($h, 'Failed renewals') < strpos($h, '<th>Reference</th>')
    && strpos($h, 'pp-late@example.com') < strpos($h, 'nokey@example.com') && !str_contains(substr($h, 0, strpos($h, '<th>Reference</th>')), 'pp-ok@example.com'));
$rows = palette_admin_payments($conn);
$emails = array_column($rows, 'email');
ok('payments order: unfulfilled and failed first, then newest', $emails === ['nokey@example.com', 'fail@example.com', 'sub@example.com', 'paid@example.com']);
ok('payments order: status, provider, licence link', $rows[0]['status'] === 'unfulfilled' && $rows[0]['provider'] === 'paypal' && $rows[0]['licence_id'] === null
    && $rows[1]['status'] === 'failed' && $rows[2]['status'] === 'paid' && $rows[2]['licence_id'] === $l2 && $rows[2]['amount'] === '$3.99'
    && $rows[3]['licence_id'] === $l1 && $rows[3]['provider'] === 'stripe');
ok('payments order: abandoned checkouts are not payments', !in_array('gone@example.com', $emails, true));
ok('payments: limit', count(palette_admin_payments($conn, 2)) === 2);
$h = palette_admin_page($conn, $admin, ['tab' => 'payments'], [], 'GET')['body'];
ok('payments tab: table with columns and licence links', str_contains($h, '<th>Date</th>') && str_contains($h, '<th>Email</th>') && str_contains($h, '<th>Amount</th>')
    && str_contains($h, '<th>Provider</th>') && str_contains($h, '<th>Status</th>') && str_contains($h, 'tab=licence&amp;id=' . $l2) && str_contains($h, 'Paid, no key')
    && !str_contains($h, 'Coming in a later task'));
$conn->query("UPDATE palette_orders SET email = '<b>x</b>@example.com' WHERE order_id = 'ST-FAIL'");
ok('payments tab: escaped', !str_contains(palette_admin_page($conn, $admin, ['tab' => 'payments'], [], 'GET')['body'], '<b>x</b>'));

// ======== move to card
function ai_pp_licence(mysqli $c, string $email = 'marco@example.es', int $paidFor = 10 * 86400, string $sid = 'I-MOVE00001'): int
{
    $lic = palette_issue_license($c, $email, $sid, 'es', ['subscription_id' => $sid, 'paid_through' => gmdate('Y-m-d H:i:s', time() + $paidFor), 'period_start' => gmdate('Y-m-d H:i:s', time() - 20 * 86400)], 'paypal');
    $st = $c->prepare("INSERT INTO palette_subscriptions (subscription_id, email, lang, status, plan_id, license_id, consent_at, created_at, updated_at, provider, livemode, price_usd)
                       VALUES (?, ?, 'es', 'active', 'P-1', ?, NOW(), NOW(), NOW(), 'paypal', 1, 3.99)");
    $st->bind_param('ssi', $sid, $email, $lic['id']);
    $st->execute();
    return (int)$lic['id'];
}

ai_reset($conn);
$mv = ai_pp_licence($conn);
$exp = time() + 7 * 86400;
$tok = palette_admin_move_token($conn, $mv, $exp);
ok('move token: shape base64url.base64url, no secret inside', preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}$/', $tok) === 1 && !str_contains($tok, palette_sub_secret($conn)));
$ck = palette_admin_move_check($conn, $tok);
ok('move token valid', $ck['ok'] === true && (int)$ck['licence']['id'] === $mv && $ck['expires'] === $exp);
[$p, $s] = explode('.', $tok);
$forged = rtrim(strtr(base64_encode(($mv + 1) . '.' . $exp), '+/', '-_'), '=') . '.' . $s;
ok('move token tampered: other licence id', palette_admin_move_check($conn, $forged) === ['ok' => false, 'error' => 'invalid']);
$later = rtrim(strtr(base64_encode($mv . '.' . ($exp + 86400)), '+/', '-_'), '=') . '.' . $s;
ok('move token tampered: longer expiry', palette_admin_move_check($conn, $later) === ['ok' => false, 'error' => 'invalid']);
ok('move token tampered: signature', palette_admin_move_check($conn, $p . '.' . str_repeat('A', 43)) === ['ok' => false, 'error' => 'invalid']);
foreach (['', 'abc', '..', str_repeat('a', 500), "$p.$s.x"] as $junk) {
    ok('move token junk refused (' . strlen($junk) . ')', palette_admin_move_check($conn, $junk)['ok'] === false);
}
ok('move token expired', palette_admin_move_check($conn, palette_admin_move_token($conn, $mv, time() - 1)) === ['ok' => false, 'error' => 'expired']);
ok('move token: checked against a given clock', palette_admin_move_check($conn, $tok, $exp + 1) === ['ok' => false, 'error' => 'expired']);
ok('move token: longer than 7 days refused', palette_admin_move_check($conn, palette_admin_move_token($conn, $mv, time() + 9 * 86400))['ok'] === false);
$pro = palette_issue_license($conn, 'pro@example.com', 'ST-1', 'en', null, 'stripe')['id'];
ok('move token: Pro licence not eligible', palette_admin_move_check($conn, palette_admin_move_token($conn, $pro, $exp)) === ['ok' => false, 'error' => 'not_eligible']);

// ---- move link: refused unless Stripe is a checkout method and live is ready
$stS = palette_stripe_settings($conn);
foreach (['methods [paypal]' => ['checkout_methods' => ['paypal']], 'live not ready' => ['live' => ['error' => 'bad key'] + $stS['live']]] as $label => $over) {
    palette_stripe_settings_put($conn, $over + $stS);
    $GLOBALS['mails'] = [];
    ok("move link: $label -> stripe_not_live, no mail, no log", palette_admin_move_link($conn, AI_ADMIN, $mv, '') === ['ok' => false, 'error' => 'stripe_not_live']
        && !$GLOBALS['mails'] && !ai_logs($conn, 'move_link'));
}
palette_stripe_settings_put($conn, $stS);

// ---- move link: emailed in the licence's language, logged without the token
$GLOBALS['mails'] = [];
$r = palette_admin_move_link($conn, AI_ADMIN, $mv, 'Customer asked to pay by card');
$m = $GLOBALS['mails'][0] ?? [];
preg_match('#https://vietnamcos\.com/palette/checkout\?move=([A-Za-z0-9_.-]+)#', (string)($m['html'] ?? ''), $mm);
ok('move link: one mail in Spanish with the checkout link', $r['ok'] === true && count($GLOBALS['mails']) === 1 && $m['to'] === ['marco@example.es']
    && $m['subject'] === palette_t('es', 'em_move_subject') && isset($mm[1]) && palette_admin_move_check($conn, $mm[1])['ok'] === true);
$ml = ai_logs($conn, 'move_link');
ok('move link: logged with note, no token', count($ml) === 1 && (int)$ml[0]['license_id'] === $mv && $ml[0]['note'] === 'Customer asked to pay by card'
    && !str_contains((string)$ml[0]['detail'], $mm[1] ?? 'x') && !str_contains((string)$ml[0]['order_ref'], $mm[1] ?? 'x'));
ok('move link: Pro licence refused', palette_admin_move_link($conn, AI_ADMIN, $pro, '') === ['ok' => false, 'error' => 'not_paypal']);
$r = palette_admin_page($conn, $admin, [], ['csrf' => str_repeat('a', 64), 'action' => 'move_link', 'id' => (string)$mv, 'note' => ''], 'POST');
ok('move link via router', $r['status'] === 303 && str_contains($r['headers']['Location'], 'ok=move_sent') && str_contains($r['headers']['Location'], 'id=' . $mv));
$h = palette_admin_page($conn, $admin, ['tab' => 'licence', 'id' => (string)$mv], [], 'GET')['body'];
ok('licence page: PayPal AI subscriber has Move to card', str_contains($h, 'name="action" value="move_link"') && str_contains($h, 'Move to card'));
ok('licence page: Pro licence has no Move to card', !str_contains(palette_admin_page($conn, $admin, ['tab' => 'licence', 'id' => (string)$pro], [], 'GET')['body'], 'value="move_link"'));

// ---- checkout page with ?move=
$_SESSION = [];
$GLOBALS['conn'] = $conn;
$h = palette_render_checkout(['query' => ['move' => $tok, 'lang' => 'en'], 'session' => [], 'accept_language' => '']);
ok('checkout move: Pro + AI only, Stripe only, email fixed', str_contains($h, 'data-plan="ai"') && !str_contains($h, 'data-plan="pro"') && str_contains($h, 'id="st-pay"')
    && !str_contains($h, 'id="pay-paypal"') && preg_match('/<input[^>]*id="email"[^>]*value="marco@example\.es"[^>]*readonly/', $h) === 1);
ok('checkout move: the move note, the token for the script', str_contains($h, palette_h(palette_t('en', 'co_move_title'))) && str_contains($h, '"move":"' . $tok . '"'));
$h = palette_render_checkout(['query' => ['move' => $forged, 'lang' => 'en'], 'session' => [], 'accept_language' => '']);
ok('checkout move: a bad link says so and is an ordinary checkout', str_contains($h, palette_h(palette_t('en', 'co_move_invalid'))) && str_contains($h, 'data-plan="pro"')
    && !str_contains($h, 'readonly') && !str_contains($h, '"move"'));

// ---- st_checkout with a move token
function ai_api(string $action, array $post): array
{
    return palette_api_handle(['method' => 'POST', 'action' => $action, 'post' => $post + ['csrf' => generateCSRFToken('palette_checkout')],
        'origin' => '', 'ip' => '203.0.113.7', 'session' => $_SESSION]);
}
$GLOBALS['st_calls'] = [];
$GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_live_Move1', 'url' => 'https://checkout.stripe.com/c/pay/m']];
$r = ai_api('st_checkout', ['plan' => 'pro', 'email' => 'attacker@example.com', 'consent' => '1', 'lang' => 'es', 'move' => $tok]);
$srow = $conn->query("SELECT * FROM palette_subscriptions WHERE stripe_session = 'cs_live_Move1'")->fetch_assoc();
$raw = urldecode((string)($GLOBALS['st_calls'][0]['raw'] ?? ''));
$ptUnix = strtotime(ai_lic($conn, $mv)['paid_through'] . ' UTC');
ok('st_checkout move: a subscription for the licence email, moving that licence', $r['status'] === 200 && $srow && (int)$srow['move_license_id'] === $mv
    && $srow['email'] === 'marco@example.es' && str_contains($raw, 'mode=subscription') && str_contains($raw, 'customer_email=marco@example.es') && !str_contains($raw, 'attacker'));
ok('st_checkout move: trial ends at the PayPal paid-through', str_contains($raw, 'subscription_data[trial_end]=' . $ptUnix));
// one link, many checkouts: a start while the first session is still open answers that session again
$_SESSION['user_role'] = 'admin';
$GLOBALS['st_calls'] = [];
$GLOBALS['st_routes']['GET /v1/checkout/sessions/cs_live_Move1'] = ['status' => 200, 'json' => ['id' => 'cs_live_Move1', 'status' => 'open', 'url' => 'https://checkout.stripe.com/c/pay/m']];
$rows0 = ai_count($conn, 'SELECT COUNT(*) FROM palette_subscriptions');
$r = ai_api('st_checkout', ['plan' => 'ai', 'consent' => '1', 'move' => $tok, 'test' => '1']);
$posts = array_filter($GLOBALS['st_calls'], fn($x) => $x['method'] === 'POST');
ok('st_checkout move: two starts -> one Stripe session, same URL, no new row', $r['status'] === 200 && $r['body']['url'] === 'https://checkout.stripe.com/c/pay/m'
    && !$posts && ai_count($conn, 'SELECT COUNT(*) FROM palette_subscriptions') === $rows0);
ok('st_checkout move: the open session is checked in live mode (admin ?test=1 ignored)', in_array('Authorization: Bearer sk_live_x', $GLOBALS['st_calls'][0]['headers'] ?? [], true)
    && in_array('s:' . $srow['id'], $_SESSION['palette_st'] ?? [], true));
foreach (['expired' => ['status' => 200, 'json' => ['id' => 'cs_live_Move1', 'status' => 'expired']], 'gone (404)' => ['status' => 404, 'json' => []]] as $label => $resp) {
    $GLOBALS['st_calls'] = [];
    $GLOBALS['st_routes']['GET /v1/checkout/sessions/cs_live_Move1'] = $resp;
    $new = 'cs_live_Move1' . ($label === 'expired' ? 'b' : 'c');
    $GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => $new, 'url' => 'https://checkout.stripe.com/c/pay/' . $new]];
    $r = ai_api('st_checkout', ['plan' => 'ai', 'consent' => '1', 'move' => $tok, 'test' => '1']);
    ok("st_checkout move: previous session $label -> a new live session", $r['status'] === 200 && $r['body']['url'] === 'https://checkout.stripe.com/c/pay/' . $new
        && in_array('Authorization: Bearer sk_live_x', end($GLOBALS['st_calls'])['headers'], true)
        && (int)$conn->query("SELECT livemode FROM palette_subscriptions WHERE stripe_session = '$new'")->fetch_row()[0] === 1);
    $conn->query("UPDATE palette_subscriptions SET status = 'cancelled' WHERE stripe_session = '$new'");   // keep cs_live_Move1 the open one
}
$GLOBALS['st_routes']['GET /v1/checkout/sessions/cs_live_Move1'] = ['status' => 503, 'json' => []];
$GLOBALS['st_calls'] = [];
$r = ai_api('st_checkout', ['plan' => 'ai', 'consent' => '1', 'move' => $tok]);
ok('st_checkout move: Stripe down while checking the open session -> 502, nothing created', $r['status'] === 502 && !array_filter($GLOBALS['st_calls'], fn($x) => $x['method'] === 'POST'));
$h = palette_render_checkout(['query' => ['move' => $tok, 'test' => '1'], 'session' => ['user_role' => 'admin'], 'accept_language' => '']);
ok('checkout move: no test banner for an admin ?test=1', !str_contains($h, 'id="test-banner"') && str_contains($h, 'readonly'));
$_SESSION = [];
$GLOBALS['st_calls'] = [];
$r = ai_api('st_checkout', ['plan' => 'ai', 'email' => 'x@example.com', 'consent' => '1', 'move' => $forged]);
ok('st_checkout move: bad token refused, no Stripe call', $r['status'] === 400 && $r['body']['error'] === 'err_move' && !$GLOBALS['st_calls']);
$soon = ai_pp_licence($conn, 'soon@example.es', 3600, 'I-MOVESOON1');
$GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_live_Move2', 'url' => 'https://checkout.stripe.com/c/pay/m2']];
$r = ai_api('st_checkout', ['plan' => 'ai', 'email' => '', 'consent' => '1', 'move' => palette_admin_move_token($conn, $soon, $exp)]);
$t = preg_match('/subscription_data\[trial_end\]=(\d+)/', urldecode((string)end($GLOBALS['st_calls'])['raw']), $tm) ? (int)$tm[1] : 0;
ok('st_checkout move: paid-through 1 h ahead -> trial ends now + 48 h + 5 min', $r['status'] === 200 && abs($t - (time() + 48 * 3600 + 300)) <= 5);
$in10 = gmdate('Y-m-d H:i:s', time() + 10 * 86400);
$in3h = gmdate('Y-m-d H:i:s', time() + 3 * 3600);
ok('move trial end: paid_through 10 days ahead -> paid_through', palette_admin_move_trial_end(['paid_through' => $in10]) === (string)strtotime($in10 . ' UTC'));
ok('move trial end: paid_through 3 h ahead -> now + 48 h + 5 min', abs((int)palette_admin_move_trial_end(['paid_through' => $in3h]) - (time() + 48 * 3600 + 300)) <= 5);
ok('move trial end: past or missing paid_through -> now + 48 h + 5 min', abs((int)palette_admin_move_trial_end(['paid_through' => gmdate('Y-m-d H:i:s', time() - 60)]) - (time() + 173100)) <= 5
    && abs((int)palette_admin_move_trial_end(['paid_through' => null]) - (time() + 173100)) <= 5);

// ---- move keeps key and cancels paypal
function ai_move_session(array $row, string $subId = 'sub_MOVED1'): array
{
    $sub = ['id' => $subId, 'status' => 'trialing', 'currency' => 'usd', 'metadata' => ['palette' => 'sub', 'row' => (string)$row['id']],
            'current_period_start' => time(), 'current_period_end' => time() + 10 * 86400, 'items' => ['data' => [['price' => ['unit_amount' => 399, 'currency' => 'usd']]]]];
    return ['id' => $row['stripe_session'], 'mode' => 'subscription', 'payment_status' => 'no_payment_required', 'status' => 'complete', 'currency' => 'usd',
            'customer' => 'cus_MOVE', 'subscription' => $sub, 'client_reference_id' => 'palette:sub:' . $row['id'], 'livemode' => true];
}
$before = ai_lic($conn, $mv);
$GLOBALS['mails'] = [];
$GLOBALS['pp_calls'] = [];
$GLOBALS['pp_routes']['POST /v1/billing/subscriptions/I-MOVE00001/cancel'] = ['status' => 204, 'json' => []];
$f = palette_st_fulfil_sub($conn, $srow, ai_move_session($srow));
$after = ai_lic($conn, $mv);
$pp = palette_sub_row($conn, 'subscription_id', 'I-MOVE00001');
ok('move keeps key and cancels paypal: same key, no new licence, no key mail', $f['ok'] === true && $f['key'] === $before['license_key'] && $after['license_key'] === $before['license_key']
    && ai_count($conn, "SELECT COUNT(*) FROM palette_licenses WHERE email = 'marco@example.es'") === 1 && !$GLOBALS['mails']);
ok('move keeps key and cancels paypal: licence now on Stripe, active', $after['source'] === 'stripe' && $after['subscription_id'] === 'sub_MOVED1' && $after['sub_status'] === 'active');
ok('move keeps key and cancels paypal: PayPal cancel called, row cancelled', count($GLOBALS['pp_calls']) === 1 && $GLOBALS['pp_calls'][0]['path'] === '/v1/billing/subscriptions/I-MOVE00001/cancel'
    && $pp['status'] === 'cancelled');
ok('move: the PayPal cancel does not touch the moved licence', ai_lic($conn, $mv)['sub_status'] === 'active');
ok('move: token now used (move_used logged)', count(ai_logs($conn, 'move_used')) === 1 && palette_admin_move_check($conn, $tok) === ['ok' => false, 'error' => 'used']);
$GLOBALS['st_calls'] = [];
$r = ai_api('st_checkout', ['plan' => 'ai', 'email' => '', 'consent' => '1', 'move' => $tok]);
ok('move: a used link cannot start a second checkout', $r['status'] === 400 && $r['body']['error'] === 'err_move' && !$GLOBALS['st_calls']);
ok('move: fulfil again is idempotent', palette_st_fulfil_sub($conn, $srow, ai_move_session($srow))['key'] === $before['license_key'] && count($GLOBALS['pp_calls']) === 1);
$GLOBALS['pp_calls'] = [];
ok('move: a late PayPal EXPIRED event leaves the moved licence alone', in_array(palette_sub_status_event($conn, 'BILLING.SUBSCRIPTION.EXPIRED', ['id' => 'I-MOVE00001']), ['expired', 'already'], true)
    && ai_lic($conn, $mv)['sub_status'] === 'active');

// ---- move paypal cancel failure logged
$GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_live_Move3', 'url' => 'https://checkout.stripe.com/c/pay/m3']];
$cf = ai_pp_licence($conn, 'fail@example.es', 10 * 86400, 'I-MOVEFAIL1');
ai_api('st_checkout', ['plan' => 'ai', 'consent' => '1', 'move' => palette_admin_move_token($conn, $cf, $exp)]);
$srow3 = $conn->query("SELECT * FROM palette_subscriptions WHERE stripe_session = 'cs_live_Move3'")->fetch_assoc();
$GLOBALS['pp_routes']['POST /v1/billing/subscriptions/I-MOVEFAIL1/cancel'] = ['status' => 500, 'json' => []];
$f = palette_st_fulfil_sub($conn, $srow3, ai_move_session($srow3, 'sub_MOVED3'));
ok('move paypal cancel failure: the move still happens', $f['ok'] === true && ai_lic($conn, $cf)['subscription_id'] === 'sub_MOVED3'
    && palette_sub_row($conn, 'subscription_id', 'I-MOVEFAIL1')['status'] === 'active');
ok('move paypal cancel failure logged: PALETTE_CANCEL_FAILED', str_contains(ai_errlog(), 'PALETTE_CANCEL_FAILED subscription=I-MOVEFAIL1'));
$fl = ai_logs($conn, 'move_paypal_cancel_failed');
ok('move paypal cancel failure logged: admin log row on the licence', count($fl) === 1 && (int)$fl[0]['license_id'] === $cf);
$h = palette_admin_page($conn, $admin, ['tab' => 'licence', 'id' => (string)$cf], [], 'GET')['body'];
ok('move paypal cancel failure: the licence page shows it', str_contains($h, 'PayPal could not cancel') && str_contains($h, 'I-MOVEFAIL1'));

// ---- C1: after a move, the customer's own cancel and portal act on the Stripe subscription, not the dead PayPal row
$cfKey = ai_lic($conn, $cf)['license_key'];
$GLOBALS['st_calls'] = [];
$GLOBALS['st_routes']['POST /v1/subscriptions/sub_MOVED3'] = ['status' => 200, 'json' => ['id' => 'sub_MOVED3', 'cancel_at_period_end' => true]];
$rc = palette_sub_cancel($conn, $cfKey, null, 'fail@example.es');
ok('after move: customer cancel POSTs cancel_at_period_end to the Stripe subscription', $rc['ok'] === true
    && in_array('POST /v1/subscriptions/sub_MOVED3', array_map(fn($x) => $x['method'] . ' ' . $x['path'], $GLOBALS['st_calls']), true)
    && ($GLOBALS['st_calls'][0]['params']['cancel_at_period_end'] ?? '') === 'true');
$conn->query("UPDATE palette_subscriptions SET status = 'active' WHERE subscription_id = 'sub_MOVED3'");
$conn->query("UPDATE palette_licenses SET sub_status = 'active' WHERE id = $cf");
$GLOBALS['st_routes']['POST /v1/billing_portal/sessions'] = ['status' => 200, 'json' => ['url' => 'https://billing.stripe.com/p/session/x']];
$pr = palette_st_portal_url($conn, $cfKey, null, 'fail@example.es');
ok('after move: the billing portal opens for the Stripe customer', $pr === ['ok' => true, 'url' => 'https://billing.stripe.com/p/session/x']);
$sr = palette_admin_sub_row($conn, $cf);
$h = palette_admin_page($conn, $admin, ['tab' => 'licence', 'id' => (string)$cf], [], 'GET')['body'];
ok('after move: admin licence page shows the Stripe subscription', $sr['subscription_id'] === 'sub_MOVED3' && $sr['provider'] === 'stripe'
    && str_contains($h, 'Send billing link') && !str_contains($h, 'value="move_link"'));

// ---- reconcile retries the PayPal cancel the move could not do (licence untouched)
$GLOBALS['pp_calls'] = [];
$GLOBALS['pp_routes']['GET /v1/billing/subscriptions/I-MOVEFAIL1'] = ['status' => 200, 'json' => ['id' => 'I-MOVEFAIL1', 'status' => 'ACTIVE', 'plan_id' => 'P-1', 'billing_info' => []]];
$GLOBALS['pp_routes']['POST /v1/billing/subscriptions/I-MOVEFAIL1/cancel'] = ['status' => 204, 'json' => []];
$conn->query("UPDATE palette_subscriptions SET updated_at = '2000-01-01 00:00:00' WHERE subscription_id = 'I-MOVEFAIL1'");
palette_sub_reconcile($conn);
ok('reconcile after a move: the old PayPal subscription is cancelled, the licence stays on Stripe', palette_sub_row($conn, 'subscription_id', 'I-MOVEFAIL1')['status'] === 'cancelled'
    && ai_lic($conn, $cf)['sub_status'] === 'active' && ai_lic($conn, $cf)['subscription_id'] === 'sub_MOVED3'
    && !str_contains(palette_admin_page($conn, $admin, ['tab' => 'licence', 'id' => (string)$cf], [], 'GET')['body'], 'PayPal could not cancel'));

// ---- I1: a refund of a PayPal payment made before the move does not revoke the Stripe-paid licence
$conn->query("INSERT INTO palette_sub_payments (subscription_id, sale_id, amount_usd, status, paid_at) VALUES ('I-MOVEFAIL1', 'SALEOLD0001', 3.99, 'completed', '2026-10-01 00:00:00')");
$out = palette_sub_event($conn, 'PAYMENT.SALE.REFUNDED', ['id' => 'REFUND0001', 'sale_id' => 'SALEOLD0001', 'amount' => ['total' => '3.99', 'currency' => 'USD']]);
ok('old PayPal refund after move: licence not revoked', ai_lic($conn, $cf)['revoked_at'] === null);
ok('old PayPal refund after move: refund recorded on the PayPal payment', $conn->query("SELECT status FROM palette_sub_payments WHERE sale_id = 'SALEOLD0001'")->fetch_row()[0] === 'refunded');
ok('old PayPal refund after move: PALETTE_MOVE_OLD_REFUND logged and admin log row', str_contains(ai_errlog(), 'PALETTE_MOVE_OLD_REFUND licence=' . $cf)
    && count(array_filter(ai_logs($conn, 'move_old_refund'), fn($x) => (int)$x['license_id'] === $cf)) === 1);
ok('old PayPal refund after move: shown in the licence history', str_contains(palette_admin_page($conn, $admin, ['tab' => 'licence', 'id' => (string)$cf], [], 'GET')['body'], 'the key was not revoked'));

// ---- I2: a refused move whose Stripe cancel fails transiently stays pending and retries
$GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_live_Move5', 'url' => 'https://checkout.stripe.com/c/pay/m5']];
$or = ai_pp_licence($conn, 'or@example.es', 10 * 86400, 'I-MOVEORPH1');
ai_api('st_checkout', ['plan' => 'ai', 'consent' => '1', 'move' => palette_admin_move_token($conn, $or, $exp)]);
$srow5 = $conn->query("SELECT * FROM palette_subscriptions WHERE stripe_session = 'cs_live_Move5'")->fetch_assoc();
$conn->query("UPDATE palette_licenses SET revoked_at = NOW() WHERE id = $or");
$GLOBALS['st_routes']['DELETE /v1/subscriptions/sub_MOVED5'] = ['status' => 503, 'json' => []];
$f = palette_st_fulfil_sub($conn, $srow5, ai_move_session($srow5, 'sub_MOVED5'));
$r5 = palette_sub_row($conn, 'id', (int)$srow5['id']);
ok('refused move, Stripe cancel 503: retry, row still pending, licence untouched', $f['ok'] === false && !empty($f['retry']) && $r5['status'] === 'pending'
    && $r5['license_id'] === null && ai_lic($conn, $or)['subscription_id'] === 'I-MOVEORPH1');
ok('refused move, Stripe cancel 503: admin log move_orphan_cancel_failed on the licence', count(array_filter(ai_logs($conn, 'move_orphan_cancel_failed'), fn($x) => (int)$x['license_id'] === $or)) === 1
    && str_contains(ai_errlog(), 'PALETTE_CANCEL_FAILED subscription=sub_MOVED5'));
$GLOBALS['st_routes']['DELETE /v1/subscriptions/sub_MOVED5'] = ['status' => 200, 'json' => ['id' => 'sub_MOVED5', 'status' => 'canceled']];
$f = palette_st_fulfil_sub($conn, $srow5, ai_move_session($srow5, 'sub_MOVED5'));
ok('refused move, retry: the cancel goes through and the row is closed', $f['ok'] === false && empty($f['retry'])
    && palette_sub_row($conn, 'id', (int)$srow5['id'])['status'] === 'cancelled' && count(ai_logs($conn, 'move_orphan_cancel_failed')) === 1);

// ---- move refused at fulfil: the licence changed hands meanwhile
$GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_live_Move4', 'url' => 'https://checkout.stripe.com/c/pay/m4']];
$rv = ai_pp_licence($conn, 'rv@example.es', 10 * 86400, 'I-MOVEREV01');
ai_api('st_checkout', ['plan' => 'ai', 'consent' => '1', 'move' => palette_admin_move_token($conn, $rv, $exp)]);
$srow4 = $conn->query("SELECT * FROM palette_subscriptions WHERE stripe_session = 'cs_live_Move4'")->fetch_assoc();
$conn->query("UPDATE palette_licenses SET email = 'someone-else@example.com' WHERE id = $rv");
$GLOBALS['st_routes']['DELETE /v1/subscriptions/sub_MOVED4'] = ['status' => 200, 'json' => ['id' => 'sub_MOVED4', 'status' => 'canceled']];
$GLOBALS['pp_calls'] = [];
$f = palette_st_fulfil_sub($conn, $srow4, ai_move_session($srow4, 'sub_MOVED4'));
$l4 = ai_lic($conn, $rv);
ok('move refused when the licence email no longer matches: licence untouched', $f['ok'] === false && $l4['subscription_id'] === 'I-MOVEREV01' && $l4['source'] === 'paypal' && !$GLOBALS['pp_calls']);
ok('move refused: the Stripe subscription is cancelled and the row closed', in_array('DELETE /v1/subscriptions/sub_MOVED4', array_map(fn($x) => $x['method'] . ' ' . $x['path'], $GLOBALS['st_calls']), true)
    && palette_sub_row($conn, 'id', (int)$srow4['id'])['status'] === 'cancelled' && str_contains(ai_errlog(), 'PALETTE_PAID_UNFULFILLED sub=' . $srow4['id'] . ' move refused'));

// leave no Stripe config behind for the other test files
$conn->query("DELETE FROM admin_settings WHERE setting_key IN ('palette', 'palette_stripe', 'paypal')");
