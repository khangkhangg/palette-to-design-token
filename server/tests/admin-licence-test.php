<?php
// Admin licence page and actions (lib/admin.php): one test group per action.
require __DIR__ . '/helpers.php';
require __DIR__ . '/st-stub.php';
ini_set('error_log', sys_get_temp_dir() . '/palette-admin-licence-test.log');

st_stub_reset($conn);
st_stub_connect($conn, 'live');
st_stub_connect($conn, 'test');
$conn->query('DELETE FROM palette_ai_usage');

const AL_ADMIN = 7;
$admin = ['user_id' => AL_ADMIN, 'user_role' => 'admin', 'csrf_tokens' => ['palette_admin' => str_repeat('a', 64)]];

function al_lic(mysqli $c, string $key, string $email, string $plan = 'pro', array $o = []): int
{
    $o += ['lang' => 'en', 'source' => 'stripe', 'pay_status' => 'provider', 'livemode' => 1, 'sub_status' => null, 'subscription_id' => null,
           'revoked_at' => null, 'paid_through' => null, 'created_at' => '2026-10-03 09:00:00', 'order_id' => 'ORD-' . $key];
    $st = $c->prepare('INSERT INTO palette_licenses (license_key, email, order_id, lang, created_at, revoked_at, plan, subscription_id, sub_status, paid_through, source, pay_status, livemode)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->bind_param('ssssssssssssi', $key, $email, $o['order_id'], $o['lang'], $o['created_at'], $o['revoked_at'], $plan, $o['subscription_id'], $o['sub_status'],
        $o['paid_through'], $o['source'], $o['pay_status'], $o['livemode']);
    $st->execute();
    return (int)$c->insert_id;
}

function al_sub(mysqli $c, int $licId, string $subId, string $provider, string $status = 'active', string $email = 'x@example.com'): void
{
    $cus = $provider === 'stripe' ? 'cus_9' : null;
    $st = $c->prepare("INSERT INTO palette_subscriptions (subscription_id, email, lang, status, plan_id, license_id, consent_at, created_at, updated_at, provider, stripe_customer, livemode)
                       VALUES (?, ?, 'en', ?, 'P-1', ?, NOW(), NOW(), NOW(), ?, ?, 1)");
    $st->bind_param('sssiss', $subId, $email, $status, $licId, $provider, $cus);
    $st->execute();
}

function al_lrow(mysqli $c, int $id): array { return $c->query("SELECT * FROM palette_licenses WHERE id = $id")->fetch_assoc(); }
function al_logs(mysqli $c, int $id): array { return $c->query("SELECT * FROM palette_admin_log WHERE license_id = $id ORDER BY id")->fetch_all(MYSQLI_ASSOC); }
function al_mails(): int { return count($GLOBALS['mails']); }

// ---- access
$pro = al_lic($conn, 'SDT-AAAA-AAAA-AAAA', 'linh@example.com', 'pro', ['lang' => 'fr']);
$GLOBALS['mails'] = [];
foreach (['', '   ', str_repeat('x', 501)] as $bad) {
    $r = palette_admin_set_access($conn, AL_ADMIN, $pro, false, $bad, true);
    ok('access: note required (' . strlen($bad) . ' chars) refused', $r['ok'] === false && $r['error'] === 'note_required');
}
ok('access: refused call changes nothing', al_lrow($conn, $pro)['revoked_at'] === null && !al_logs($conn, $pro) && al_mails() === 0);
$r = palette_admin_set_access($conn, AL_ADMIN, $pro, false, 'Chargeback', true);
$l = al_logs($conn, $pro);
$d = json_decode($l[0]['detail'] ?? 'null', true);
ok('access: revokes', $r['ok'] === true && al_lrow($conn, $pro)['revoked_at'] !== null);
ok('access: one log row with before/after and note', count($l) === 1 && $l[0]['action'] === 'access' && (int)$l[0]['admin_user_id'] === AL_ADMIN
    && $l[0]['note'] === 'Chargeback' && $d['before']['revoked'] === false && $d['after']['revoked'] === true);
ok('access: log carries no email or key', !str_contains((string)$l[0]['detail'], 'linh@') && !str_contains((string)$l[0]['detail'], 'SDT-'));
ok('access: notify sends exactly one mail, in the licence language', al_mails() === 1 && $GLOBALS['mails'][0]['to'] === ['linh@example.com']
    && $GLOBALS['mails'][0]['subject'] === palette_t('fr', 'em_admin_change_subject') && str_contains($GLOBALS['mails'][0]['html'], palette_t('fr', 'em_admin_change_body_access')));
$r = palette_admin_set_access($conn, AL_ADMIN, $pro, false, 'Again', true);
ok('access: double submit is a no-op (no second log row, no mail)', $r['ok'] === true && !empty($r['nochange']) && count(al_logs($conn, $pro)) === 1 && al_mails() === 1);
$GLOBALS['mails'] = [];
$r = palette_admin_set_access($conn, AL_ADMIN, $pro, true, 'Resolved', false);
ok('access: reinstate, no notify = no mail', $r['ok'] === true && al_lrow($conn, $pro)['revoked_at'] === null && al_mails() === 0 && count(al_logs($conn, $pro)) === 2);
ok('access: unknown licence', palette_admin_set_access($conn, AL_ADMIN, 999999, false, 'x', false) === ['ok' => false, 'error' => 'not_found']);

// ---- payment
$GLOBALS['mails'] = [];
ok('payment: note required', palette_admin_set_payment($conn, AL_ADMIN, $pro, 'comp', '', false)['error'] === 'note_required');
ok('payment: unknown status refused', palette_admin_set_payment($conn, AL_ADMIN, $pro, 'free', 'x', false)['error'] === 'bad_status');
$r = palette_admin_set_payment($conn, AL_ADMIN, $pro, 'manual_paid', 'Bank transfer', true);
$l = al_logs($conn, $pro);
$d = json_decode(end($l)['detail'], true);
ok('payment: manual_paid', $r['ok'] === true && al_lrow($conn, $pro)['pay_status'] === 'manual_paid' && al_lrow($conn, $pro)['revoked_at'] === null
    && $d['before']['pay_status'] === 'provider' && $d['after']['pay_status'] === 'manual_paid' && al_mails() === 1
    && str_contains($GLOBALS['mails'][0]['html'], palette_t('fr', 'em_admin_change_body_payment')));
$n = count(al_logs($conn, $pro));
$r = palette_admin_set_payment($conn, AL_ADMIN, $pro, 'manual_paid', 'Again', true);
ok('payment: double submit no-op', !empty($r['nochange']) && count(al_logs($conn, $pro)) === $n && al_mails() === 1);
$r = palette_admin_set_payment($conn, AL_ADMIN, $pro, 'refunded', 'Refunded in Stripe', false);
$row = al_lrow($conn, $pro);
$l = al_logs($conn, $pro);
$d = json_decode(end($l)['detail'], true);
ok('payment: refunded revokes too', $r['ok'] === true && $row['pay_status'] === 'refunded' && $row['revoked_at'] !== null
    && $d['after']['revoked'] === true && $d['before']['revoked'] === false);

// ---- until (Pro + AI only)
$ai = al_lic($conn, 'SDT-BBBB-BBBB-BBBB', 'ravi@example.in', 'ai', ['lang' => 'es', 'sub_status' => 'suspended', 'subscription_id' => 'sub_A', 'paid_through' => '2026-11-03 10:00:00']);
$GLOBALS['mails'] = [];
$future = gmdate('Y-m-d', time() + 20 * 86400);
ok('until: on Pro refused', palette_admin_set_until($conn, AL_ADMIN, $pro, $future, 'x', false) === ['ok' => false, 'error' => 'until_pro']);
ok('until: note required', palette_admin_set_until($conn, AL_ADMIN, $ai, $future, '', false)['error'] === 'note_required');
foreach (['2026-13-40', 'tomorrow', '', '2026-2-3'] as $bad) ok("until: bad date '$bad'", palette_admin_set_until($conn, AL_ADMIN, $ai, $bad, 'x', false)['error'] === 'until_bad');
$r = palette_admin_set_until($conn, AL_ADMIN, $ai, $future, 'Card coming', true);
$row = al_lrow($conn, $ai);
$l = al_logs($conn, $ai);
$d = json_decode($l[0]['detail'], true);
ok('until: paid_through is that day 23:59:59 and status active', $r['ok'] === true && $row['paid_through'] === "$future 23:59:59" && $row['sub_status'] === 'active');
ok('until: log before/after', count($l) === 1 && $d['before']['paid_through'] === '2026-11-03 10:00:00' && $d['after']['paid_through'] === "$future 23:59:59"
    && $d['before']['sub_status'] === 'suspended' && $d['after']['sub_status'] === 'active');
ok('until: notify mail names the date', al_mails() === 1 && str_contains($GLOBALS['mails'][0]['html'], $future));
$r = palette_admin_set_until($conn, AL_ADMIN, $ai, $future, 'Again', true);
ok('until: same date is a no-op', !empty($r['nochange']) && count(al_logs($conn, $ai)) === 1 && al_mails() === 1);
$past = gmdate('Y-m-d', time() - 3 * 86400);
palette_admin_set_until($conn, AL_ADMIN, $ai, $past, 'Ended', false);
ok('until: a past date keeps the status', al_lrow($conn, $ai)['paid_through'] === "$past 23:59:59" && al_lrow($conn, $ai)['sub_status'] === 'active');

// ---- plan
$GLOBALS['mails'] = [];
$p2 = al_lic($conn, 'SDT-CCCC-CCCC-CCCC', 'chen@example.cn', 'pro', ['lang' => 'zh-CN']);
ok('plan: note required', palette_admin_set_plan($conn, AL_ADMIN, $p2, 'ai', '', false)['error'] === 'note_required');
ok('plan: unknown plan', palette_admin_set_plan($conn, AL_ADMIN, $p2, 'gold', 'x', false)['error'] === 'bad_plan');
$r = palette_admin_set_plan($conn, AL_ADMIN, $p2, 'ai', 'Upgrade deal', true);
$l = al_logs($conn, $p2);
$d = json_decode($l[0]['detail'], true);
ok('plan: pro to ai', $r['ok'] === true && al_lrow($conn, $p2)['plan'] === 'ai' && $d['before']['plan'] === 'pro' && $d['after']['plan'] === 'ai' && count($l) === 1);
ok('plan: notify mail names the plan', al_mails() === 1 && str_contains($GLOBALS['mails'][0]['html'], 'Pro + AI') && $GLOBALS['mails'][0]['subject'] === palette_t('zh-CN', 'em_admin_change_subject'));
$r = palette_admin_set_plan($conn, AL_ADMIN, $p2, 'ai', 'Again', true);
ok('plan: double submit no-op', !empty($r['nochange']) && count(al_logs($conn, $p2)) === 1 && al_mails() === 1);

// ---- resend
$GLOBALS['mails'] = [];
$r = palette_admin_resend($conn, AL_ADMIN, $p2);
$m = palette_license_email('SDT-CCCC-CCCC-CCCC', 'zh-CN');
$l = al_logs($conn, $p2);
ok('resend: one licence email in the licence language', $r['ok'] === true && al_mails() === 1 && $GLOBALS['mails'][0]['to'] === ['chen@example.cn']
    && $GLOBALS['mails'][0]['subject'] === $m['subject'] && str_contains($GLOBALS['mails'][0]['html'], 'SDT-CCCC-CCCC-CCCC'));
ok('resend: logged', end($l)['action'] === 'resend');
ok('resend: unknown licence', palette_admin_resend($conn, AL_ADMIN, 999999)['error'] === 'not_found');

// ---- billing link (Stripe only)
$st = al_lic($conn, 'SDT-DDDD-DDDD-DDDD', 'nadia@example.bd', 'ai', ['lang' => 'es', 'sub_status' => 'active', 'subscription_id' => 'sub_S1', 'paid_through' => gmdate('Y-m-d H:i:s', time() + 5 * 86400)]);
al_sub($conn, $st, 'sub_S1', 'stripe');
$pp = al_lic($conn, 'SDT-EEEE-EEEE-EEEE', 'marco@example.it', 'ai', ['source' => 'paypal', 'sub_status' => 'active', 'subscription_id' => 'I-PAYPAL0001', 'paid_through' => gmdate('Y-m-d H:i:s', time() + 5 * 86400)]);
al_sub($conn, $pp, 'I-PAYPAL0001', 'paypal');
$GLOBALS['mails'] = [];
$GLOBALS['st_calls'] = [];
$GLOBALS['st_routes']['POST /v1/billing_portal/sessions'] = ['status' => 200, 'json' => ['url' => 'https://billing.stripe.com/p/session/secret123']];
$r = palette_admin_billing_link($conn, AL_ADMIN, $pp);
ok('billing link: PayPal subscriber refused, Stripe not called', $r === ['ok' => false, 'error' => 'not_stripe'] && !$GLOBALS['st_calls'] && al_mails() === 0);
ok('billing link: Pro key without subscription refused', palette_admin_billing_link($conn, AL_ADMIN, $pro)['error'] === 'not_stripe');
$r = palette_admin_billing_link($conn, AL_ADMIN, $st);
$l = al_logs($conn, $st);
ok('billing link: portal session created for the customer', $r['ok'] === true && ($GLOBALS['st_calls'][0]['params']['customer'] ?? '') === 'cus_9');
ok('billing link: URL emailed once to the licence email, in its language', al_mails() === 1 && $GLOBALS['mails'][0]['to'] === ['nadia@example.bd']
    && str_contains($GLOBALS['mails'][0]['html'], 'https://billing.stripe.com/p/session/secret123') && $GLOBALS['mails'][0]['subject'] === palette_t('es', 'em_billing_link_subject'));
ok('billing link: URL never returned or logged', !str_contains(json_encode($r), 'secret123') && count($l) === 1 && $l[0]['action'] === 'billing_link' && !str_contains((string)$l[0]['detail'], 'secret123'));
$GLOBALS['mails'] = [];
$GLOBALS['st_routes']['POST /v1/billing_portal/sessions'] = ['status' => 500, 'json' => []];
$r = palette_admin_billing_link($conn, AL_ADMIN, $st);
ok('billing link: Stripe failure -> error, no mail, no log', $r === ['ok' => false, 'error' => 'stripe'] && al_mails() === 0 && count(al_logs($conn, $st)) === 1);

// ---- cancel
$GLOBALS['mails'] = [];
$GLOBALS['st_calls'] = [];
$GLOBALS['st_routes']['POST /v1/subscriptions/sub_S1'] = ['status' => 500, 'json' => []];
$r = palette_admin_cancel($conn, AL_ADMIN, $st);
ok('cancel stripe: Stripe failure -> error, nothing changes', $r === ['ok' => false, 'error' => 'stripe'] && al_lrow($conn, $st)['sub_status'] === 'active' && count(al_logs($conn, $st)) === 1);
$GLOBALS['st_routes']['POST /v1/subscriptions/sub_S1'] = ['status' => 200, 'json' => ['id' => 'sub_S1', 'cancel_at_period_end' => true]];
$GLOBALS['st_calls'] = [];
$r = palette_admin_cancel($conn, AL_ADMIN, $st);
$l = al_logs($conn, $st);
$d = json_decode(end($l)['detail'], true);
ok('cancel stripe: calls Stripe cancel_at_period_end without an owner check', $r['ok'] === true && ($GLOBALS['st_calls'][0]['path'] ?? '') === '/v1/subscriptions/sub_S1'
    && ($GLOBALS['st_calls'][0]['params']['cancel_at_period_end'] ?? '') === 'true' && al_lrow($conn, $st)['sub_status'] === 'cancelled');
ok('cancel stripe: logged with before/after status, customer mailed once', end($l)['action'] === 'cancel' && $d['before']['sub_status'] === 'active' && $d['after']['sub_status'] === 'cancelled' && al_mails() === 1);
$n = count(al_logs($conn, $st));
$GLOBALS['st_calls'] = [];
$r = palette_admin_cancel($conn, AL_ADMIN, $st);
ok('cancel: already cancelled is a no-op', !empty($r['nochange']) && !$GLOBALS['st_calls'] && count(al_logs($conn, $st)) === $n && al_mails() === 1);
ok('cancel: Pro key has no subscription', palette_admin_cancel($conn, AL_ADMIN, $pro)['error'] === 'no_subscription');
// PayPal: the PayPal API is not stubbed here (no credentials), so the cancel fails cleanly and changes nothing.
$r = palette_admin_cancel($conn, AL_ADMIN, $pp);
ok('cancel paypal: goes through the PayPal path (fails without PayPal, nothing changes)', $r === ['ok' => false, 'error' => 'paypal'] && al_lrow($conn, $pp)['sub_status'] === 'active' && !al_logs($conn, $pp));

// ---- the licence page
$conn->query("INSERT INTO palette_sub_payments (subscription_id, sale_id, amount_usd, status, paid_at) VALUES ('sub_S1', 'in_1Q4cAAAAAAAAAAAAAAAAAAAA', 3.99, 'completed', '2026-10-03 09:01:00')");
$conn->query("INSERT INTO palette_orders (order_id, email, lang, amount_usd, status, consent_at, created_at, captured_at, license_id, provider) VALUES ('ORD-PAGE1', 'nadia@example.bd', 'es', 1.49, 'captured', NOW(), '2026-09-01 08:00:00', '2026-09-01 08:01:00', $st, 'stripe')");
$r = palette_admin_page($conn, $admin, ['tab' => 'licence', 'id' => (string)$st], [], 'GET');
$h = $r['body'];
ok('page: 200 with key, email, change form with csrf', $r['status'] === 200 && str_contains($h, 'SDT-DDDD-DDDD-DDDD') && str_contains($h, 'nadia@example.bd')
    && str_contains($h, 'Change this licence') && str_contains($h, 'value="' . str_repeat('a', 64) . '"') && str_contains($h, 'name="action" value="change"'));
ok('page: payments, history and the cancelled state', str_contains($h, 'Payments') && str_contains($h, '$3.99') && str_contains($h, '$1.49') && str_contains($h, 'History')
    && str_contains($h, 'Subscription'));
ok('page: history lists the admin actions', str_contains($h, 'cancelled the subscription') && str_contains($h, 'sent a billing link') && str_contains($h, 'Admin 7'));
ok('page: no portal URL on the page', !str_contains($h, 'secret123'));
$conn->query("UPDATE palette_subscriptions SET status = 'active' WHERE license_id = $st");
$conn->query("UPDATE palette_licenses SET sub_status = 'active' WHERE id = $st");
$h = palette_admin_page($conn, $admin, ['tab' => 'licence', 'id' => (string)$st], [], 'GET')['body'];
ok('page: active Stripe subscriber has cancel and billing link', str_contains($h, 'Cancel subscription') && str_contains($h, 'Send billing link'));
$r = palette_admin_page($conn, $admin, ['tab' => 'licence', 'id' => '999999'], [], 'GET');
ok('page: unknown id -> 404', $r['status'] === 404);
$r = palette_admin_page($conn, $admin, ['tab' => 'licence'], [], 'GET');
ok('page: no id -> 404', $r['status'] === 404);
$r = palette_admin_page($conn, $admin, ['tab' => 'licence', 'id' => (string)$pro], [], 'GET');
ok('page: Pro licence has no subscription card and no billing link', !str_contains($r['body'], 'Cancel subscription') && !str_contains($r['body'], 'Send billing link'));
$r = palette_admin_page($conn, $admin, ['tab' => 'licence', 'id' => (string)$pp], [], 'GET');
ok('page: PayPal subscriber has cancel but no billing link', str_contains($r['body'], 'Cancel subscription') && !str_contains($r['body'], 'Send billing link'));

// ---- router: change form
$post = ['csrf' => str_repeat('a', 64), 'action' => 'change', 'id' => (string)$p2];
$cur = ['access' => 'active', 'pay_status' => 'provider', 'plan' => 'ai', 'until' => '', 'note' => '', 'notify' => ''];
$n = count(al_logs($conn, $p2));
$r = palette_admin_page($conn, $admin, [], $post + $cur, 'POST');
ok('change: nothing changed -> ?ok=nochange', $r['status'] === 303 && str_contains($r['headers']['Location'], 'ok=nochange') && count(al_logs($conn, $p2)) === $n);
$r = palette_admin_page($conn, $admin, [], ['access' => 'revoked'] + $post + $cur, 'POST');
ok('change: no note -> ?err=note_required, nothing done', str_contains($r['headers']['Location'], 'err=note_required') && al_lrow($conn, $p2)['revoked_at'] === null);
$GLOBALS['mails'] = [];
$r = palette_admin_page($conn, $admin, [], ['access' => 'revoked', 'note' => 'Abuse', 'notify' => '1'] + $post + $cur, 'POST');
ok('change: revoke through the router, id kept in the redirect', str_contains($r['headers']['Location'], 'ok=saved') && str_contains($r['headers']['Location'], 'id=' . $p2)
    && al_lrow($conn, $p2)['revoked_at'] !== null && al_mails() === 1);
$r = palette_admin_page($conn, $admin, [], ['pay_status' => 'comp', 'note' => 'Gift'] + $post + $cur, 'POST');
ok('change: payment through the router', al_lrow($conn, $p2)['pay_status'] === 'comp' && str_contains($r['headers']['Location'], 'ok=saved'));
$r = palette_admin_page($conn, $admin, [], ['id' => 'abc', 'access' => 'revoked', 'note' => 'x'] + $post + $cur, 'POST');
ok('change: bad id -> ?err=not_found', str_contains($r['headers']['Location'], 'err=not_found'));
$r = palette_admin_page($conn, $admin, [], ['csrf' => str_repeat('a', 64), 'action' => 'resend', 'id' => (string)$pro], 'POST');
ok('router: resend action', str_contains($r['headers']['Location'], 'ok=resent'));
$r = palette_admin_page($conn, $admin, [], ['csrf' => 'bad', 'action' => 'cancel', 'id' => (string)$st], 'POST');
ok('router: bad csrf -> 403 on cancel', $r['status'] === 403);

// ---- flash codes
$r = palette_admin_page($conn, $admin, ['tab' => 'licence', 'id' => (string)$pro, 'ok' => 'saved'], [], 'GET');
ok('flash: ok code -> fixed English message', str_contains($r['body'], 'Changes saved'));
$r = palette_admin_page($conn, $admin, ['tab' => 'licence', 'id' => (string)$pro, 'err' => '<b>evil</b>'], [], 'GET');
ok('flash: unknown code is not echoed', !str_contains($r['body'], 'evil'));
$r = palette_admin_page($conn, $admin, ['tab' => 'licence', 'id' => (string)$pro, 'err' => 'note_required'], [], 'GET');
ok('flash: err code -> fixed English message', str_contains($r['body'], 'Write a reason'));

st_stub_reset($conn);
$conn->query('DELETE FROM palette_ai_usage');
