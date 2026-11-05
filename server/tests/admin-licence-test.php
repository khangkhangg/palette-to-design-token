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
    && $GLOBALS['mails'][0]['subject'] === palette_t('fr', 'em_admin_change_subject') && str_contains($GLOBALS['mails'][0]['html'], palette_t('fr', 'em_admin_change_body_revoked')));
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
ok('plan: Pro -> Pro + AI without a date is refused (plan_needs_until), nothing changes', $r === ['ok' => false, 'error' => 'plan_needs_until']
    && al_lrow($conn, $p2)['plan'] === 'pro' && !al_logs($conn, $p2) && al_mails() === 0);
$r = palette_admin_set_plan($conn, AL_ADMIN, $p2, 'ai', 'Upgrade deal', true, gmdate('Y-m-d', time() - 86400));
ok('plan: a past date is refused too', $r['error'] === 'plan_needs_until' && al_lrow($conn, $p2)['plan'] === 'pro');
$r = palette_admin_set_plan($conn, AL_ADMIN, $p2, 'ai', 'Upgrade deal', true, $future);
$row = al_lrow($conn, $p2);
$l = al_logs($conn, $p2);
$d = json_decode($l[0]['detail'], true);
ok('plan: pro to ai with a future date, one log row', $r['ok'] === true && $row['plan'] === 'ai' && $row['paid_through'] === "$future 23:59:59" && $row['sub_status'] === 'active'
    && $d['before']['plan'] === 'pro' && $d['after']['plan'] === 'ai' && $d['after']['paid_through'] === "$future 23:59:59" && count($l) === 1);
$acc = palette_licence_access($row);
ok('plan: after the switch the customer has Pro and AI access', $acc['pro'] === true && $acc['ai'] === true);
ok('plan: notify mail names the plan and the date, in the licence language', al_mails() === 1 && str_contains($GLOBALS['mails'][0]['html'], 'Pro + AI')
    && str_contains($GLOBALS['mails'][0]['html'], $future) && $GLOBALS['mails'][0]['subject'] === palette_t('zh-CN', 'em_admin_change_subject'));
$r = palette_admin_set_plan($conn, AL_ADMIN, $p2, 'ai', 'Again', true, $future);
ok('plan: double submit no-op', !empty($r['nochange']) && count(al_logs($conn, $p2)) === 1 && al_mails() === 1);
$p3 = al_lic($conn, 'SDT-CCCC-CCCC-C333', 'p3@example.cn', 'pro', ['lang' => 'en']);
$conn->query("UPDATE palette_licenses SET plan = 'ai', paid_through = '" . gmdate('Y-m-d H:i:s', time() + 86400 * 3) . "' WHERE id = $p3");
$r = palette_admin_set_plan($conn, AL_ADMIN, $p3, 'pro', 'Downgrade', false);
ok('plan: Pro + AI -> Pro with no subscription is fine', $r['ok'] === true && al_lrow($conn, $p3)['plan'] === 'pro');

// AI -> Pro while a subscription is live keeps billing: cancel_first. A cancelled row is fine.
$cf = al_lic($conn, 'SDT-CF00-CF00-CF00', 'cf@example.com', 'ai', ['sub_status' => 'active', 'subscription_id' => 'sub_CF', 'paid_through' => gmdate('Y-m-d H:i:s', time() + 5 * 86400)]);
al_sub($conn, $cf, 'sub_CF', 'stripe');
$r = palette_admin_set_plan($conn, AL_ADMIN, $cf, 'pro', 'Downgrade', true);
ok('plan: ai -> pro with an active subscription -> cancel_first, unchanged', $r === ['ok' => false, 'error' => 'cancel_first'] && al_lrow($conn, $cf)['plan'] === 'ai' && !al_logs($conn, $cf));
$conn->query("UPDATE palette_subscriptions SET status = 'suspended' WHERE license_id = $cf");
ok('plan: a suspended subscription also needs cancelling first', palette_admin_set_plan($conn, AL_ADMIN, $cf, 'pro', 'Downgrade', true)['error'] === 'cancel_first');
$conn->query("UPDATE palette_subscriptions SET status = 'cancelled' WHERE license_id = $cf");
$GLOBALS['st_routes']['GET /v1/subscriptions/sub_CF'] = ['status' => 200, 'json' => ['id' => 'sub_CF', 'status' => 'canceled']];   // #10: ended at Stripe
ok('plan: a cancelled subscription allows the downgrade', palette_admin_set_plan($conn, AL_ADMIN, $cf, 'pro', 'Downgrade', false)['ok'] === true);

// ---- revoke ends billing
$rv = al_lic($conn, 'SDT-RV00-RV00-RV00', 'rv@example.com', 'ai', ['sub_status' => 'active', 'subscription_id' => 'sub_RV', 'paid_through' => gmdate('Y-m-d H:i:s', time() + 5 * 86400)]);
al_sub($conn, $rv, 'sub_RV', 'stripe');
$GLOBALS['st_calls'] = [];
$GLOBALS['st_routes']['DELETE /v1/subscriptions/sub_RV'] = ['status' => 200, 'json' => ['id' => 'sub_RV', 'status' => 'canceled']];
$r = palette_admin_set_access($conn, AL_ADMIN, $rv, false, 'Abuse', false);
$l = al_logs($conn, $rv);
$d = json_decode($l[0]['detail'], true);
ok('revoke subscriber (Stripe): DELETE the subscription now, row and licence cancelled', $r['ok'] === true && empty($r['warn'])
    && ($GLOBALS['st_calls'][0]['method'] ?? '') === 'DELETE' && ($GLOBALS['st_calls'][0]['path'] ?? '') === '/v1/subscriptions/sub_RV'
    && palette_sub_row($conn, 'subscription_id', 'sub_RV')['status'] === 'cancelled' && al_lrow($conn, $rv)['sub_status'] === 'cancelled' && al_lrow($conn, $rv)['revoked_at'] !== null);
ok('revoke subscriber: same log row records the subscription', count($l) === 1 && $d['subscription']['before'] === 'active' && $d['subscription']['after'] === 'cancelled' && $d['subscription']['cancel_failed'] === false);
$rf = al_lic($conn, 'SDT-RF00-RF00-RF00', 'rf@example.com', 'ai', ['sub_status' => 'active', 'subscription_id' => 'sub_RF', 'paid_through' => gmdate('Y-m-d H:i:s', time() + 5 * 86400)]);
al_sub($conn, $rf, 'sub_RF', 'stripe');
@unlink(sys_get_temp_dir() . '/palette-admin-licence-test.log');
$GLOBALS['st_routes']['DELETE /v1/subscriptions/sub_RF'] = ['status' => 500, 'json' => []];
$r = palette_admin_set_access($conn, AL_ADMIN, $rf, false, 'Abuse', false);
$l = al_logs($conn, $rf);
$d = json_decode($l[0]['detail'] ?? 'null', true);
ok('revoke subscriber, Stripe fails: still revoked, warn cancel_failed, row stays active', $r['ok'] === true && ($r['warn'] ?? '') === 'cancel_failed'
    && al_lrow($conn, $rf)['revoked_at'] !== null && palette_sub_row($conn, 'subscription_id', 'sub_RF')['status'] === 'active');
ok('revoke subscriber, Stripe fails: logged in the same row and PALETTE_CANCEL_FAILED written', count($l) === 1 && $d['subscription']['cancel_failed'] === true
    && str_contains((string)@file_get_contents(sys_get_temp_dir() . '/palette-admin-licence-test.log'), 'PALETTE_CANCEL_FAILED subscription=sub_RF'));
$rt = palette_admin_page($conn, $admin, [], ['csrf' => str_repeat('a', 64), 'action' => 'change', 'id' => (string)$rf, 'access' => 'active', 'note' => 'Back'], 'POST');
ok('restore access does not touch billing', str_contains($rt['headers']['Location'], 'ok=saved') && palette_sub_row($conn, 'subscription_id', 'sub_RF')['status'] === 'active'
    && count(array_filter($GLOBALS['st_calls'], fn($x) => $x['path'] === '/v1/subscriptions/sub_RF')) === 1);
$rr = al_lic($conn, 'SDT-RR00-RR00-RR00', 'rr@example.com', 'ai', ['sub_status' => 'active', 'subscription_id' => 'sub_RR', 'paid_through' => gmdate('Y-m-d H:i:s', time() + 5 * 86400)]);
al_sub($conn, $rr, 'sub_RR', 'stripe');
$GLOBALS['st_routes']['DELETE /v1/subscriptions/sub_RR'] = ['status' => 500, 'json' => []];
$rt = palette_admin_page($conn, $admin, [], ['csrf' => str_repeat('a', 64), 'action' => 'change', 'id' => (string)$rr, 'access' => 'revoked', 'note' => 'Abuse'], 'POST');
ok('revoke via the router with a failed cancel -> ?err=cancel_failed', str_contains($rt['headers']['Location'], 'err=cancel_failed') && al_lrow($conn, $rr)['revoked_at'] !== null);
// refund also ends billing
$rd = al_lic($conn, 'SDT-RD00-RD00-RD00', 'rd@example.com', 'ai', ['sub_status' => 'active', 'subscription_id' => 'sub_RD', 'paid_through' => gmdate('Y-m-d H:i:s', time() + 5 * 86400)]);
al_sub($conn, $rd, 'sub_RD', 'stripe');
$GLOBALS['st_routes']['DELETE /v1/subscriptions/sub_RD'] = ['status' => 200, 'json' => []];
$r = palette_admin_set_payment($conn, AL_ADMIN, $rd, 'refunded', 'Refunded in Stripe', false);
ok('refund of a subscriber also ends billing', $r['ok'] === true && palette_sub_row($conn, 'subscription_id', 'sub_RD')['status'] === 'cancelled');

// ---- refund / restore mails
$GLOBALS['mails'] = [];
$mf = al_lic($conn, 'SDT-MF00-MF00-MF00', 'mf@example.com', 'pro');
palette_admin_set_payment($conn, AL_ADMIN, $mf, 'refunded', 'Refunded', true);
ok('refund mail says the key stopped working, not that it is unchanged', al_mails() === 1 && str_contains($GLOBALS['mails'][0]['html'], palette_h(palette_t('en', 'em_admin_change_body_refunded')))
    && !str_contains($GLOBALS['mails'][0]['html'], palette_h(palette_t('en', 'em_admin_change_body_payment'))) && !str_contains(strtolower($GLOBALS['mails'][0]['html']), 'unchanged'));
$GLOBALS['mails'] = [];
palette_admin_set_access($conn, AL_ADMIN, $mf, true, 'Mistake', true);
ok('restore mail has its own sentence', al_mails() === 1 && str_contains($GLOBALS['mails'][0]['html'], palette_h(palette_t('en', 'em_admin_change_body_restored'))));
$allKeys = ['em_admin_change_subject', 'em_admin_change_body_revoked', 'em_admin_change_body_restored', 'em_admin_change_body_refunded', 'em_admin_change_body_payment',
            'em_admin_change_body_until', 'em_admin_change_body_plan'];
$missing = 0;
foreach (PALETTE_LANGS as $lg) foreach ($allKeys as $k) if (!isset(palette_strings($lg)[$k])) $missing++;
ok('admin change mail keys exist in all 7 languages', $missing === 0 && !isset(palette_strings('en')['em_admin_change_body_access']));

// ---- until on a cancelled subscription row keeps the licence status
$uc = al_lic($conn, 'SDT-UC00-UC00-UC00', 'uc@example.com', 'ai', ['sub_status' => 'cancelled', 'subscription_id' => 'sub_UC', 'paid_through' => '2026-11-01 10:00:00']);
al_sub($conn, $uc, 'sub_UC', 'stripe', 'cancelled');
$r = palette_admin_set_until($conn, AL_ADMIN, $uc, $future, 'Goodwill', false);
ok('until: a cancelled subscription keeps sub_status, paid_through moves', $r['ok'] === true && al_lrow($conn, $uc)['sub_status'] === 'cancelled' && al_lrow($conn, $uc)['paid_through'] === "$future 23:59:59");

// ---- +1 month
ok('plus month: from a future paid_through, clamped to the month length', palette_admin_plus_month('2037-01-31 10:00:00') === '2037-02-28');
ok('plus month: from the past it starts at now', palette_admin_plus_month('2020-01-01 00:00:00', strtotime('2026-12-15 12:00:00 UTC')) === '2027-01-15'
    && palette_admin_plus_month(null, strtotime('2026-12-31 UTC')) === '2027-01-31');
$pm = al_lic($conn, 'SDT-PM00-PM00-PM00', 'pm@example.com', 'ai', ['sub_status' => 'active', 'paid_through' => '2037-01-31 10:00:00']);
$GLOBALS['mails'] = [];
$rt = palette_admin_page($conn, $admin, [], ['csrf' => str_repeat('a', 64), 'action' => 'change', 'id' => (string)$pm, 'until' => '2037-01-31', 'plus_month' => '1', 'note' => 'Goodwill', 'notify' => '1'], 'POST');
ok('plus month through the router: same note and notify rules', str_contains($rt['headers']['Location'], 'ok=saved') && al_lrow($conn, $pm)['paid_through'] === '2037-02-28 23:59:59'
    && al_mails() === 1 && str_contains($GLOBALS['mails'][0]['html'], '2037-02-28'));
$rt = palette_admin_page($conn, $admin, [], ['csrf' => str_repeat('a', 64), 'action' => 'change', 'id' => (string)$pm, 'plus_month' => '1', 'note' => ''], 'POST');
ok('plus month without a reason is refused', str_contains($rt['headers']['Location'], 'err=note_required') && al_lrow($conn, $pm)['paid_through'] === '2037-02-28 23:59:59');

// ---- several changes in one save: one mail, one log row each; validation before anything is applied
$mc = al_lic($conn, 'SDT-MC00-MC00-MC00', 'mc@example.com', 'ai', ['sub_status' => 'active', 'paid_through' => '2037-01-31 10:00:00']);
$GLOBALS['mails'] = [];
$rt = palette_admin_page($conn, $admin, [], ['csrf' => str_repeat('a', 64), 'action' => 'change', 'id' => (string)$mc, 'access' => 'revoked', 'pay_status' => 'comp',
    'plan' => 'ai', 'until' => '2037-03-01', 'note' => 'Bundle', 'notify' => '1'], 'POST');
$mcl = al_logs($conn, $mc);
ok('three changes in one save: 3 log rows, exactly 1 mail listing each', str_contains($rt['headers']['Location'], 'ok=saved') && count($mcl) === 3 && al_mails() === 1
    && str_contains($GLOBALS['mails'][0]['html'], palette_h(palette_t('en', 'em_admin_change_body_revoked'))) && str_contains($GLOBALS['mails'][0]['html'], palette_h(palette_t('en', 'em_admin_change_body_payment')))
    && str_contains($GLOBALS['mails'][0]['html'], '2037-03-01'));
$ap = al_lic($conn, 'SDT-AP00-AP00-AP00', 'ap@example.com', 'pro');
$GLOBALS['mails'] = [];
$rt = palette_admin_page($conn, $admin, [], ['csrf' => str_repeat('a', 64), 'action' => 'change', 'id' => (string)$ap, 'plan' => 'ai', 'until' => '2026-13-40', 'access' => 'revoked',
    'pay_status' => 'comp', 'note' => 'x', 'notify' => '1'], 'POST');
ok('plan switch + bad date: nothing applied, no mail', str_contains($rt['headers']['Location'], 'err=until_bad') && al_lrow($conn, $ap)['plan'] === 'pro' && al_lrow($conn, $ap)['revoked_at'] === null
    && al_lrow($conn, $ap)['pay_status'] === 'provider' && !al_logs($conn, $ap) && al_mails() === 0);
$rt = palette_admin_page($conn, $admin, [], ['csrf' => str_repeat('a', 64), 'action' => 'change', 'id' => (string)$ap, 'plan' => 'ai', 'access' => 'revoked', 'note' => 'x', 'notify' => '1'], 'POST');
ok('plan switch without a date + other changes: plan_needs_until, nothing applied', str_contains($rt['headers']['Location'], 'err=plan_needs_until') && al_lrow($conn, $ap)['revoked_at'] === null && !al_logs($conn, $ap));
$rt = palette_admin_page($conn, $admin, [], ['csrf' => str_repeat('a', 64), 'action' => 'change', 'id' => (string)$ap, 'until' => gmdate('Y-m-d', time() + 86400), 'access' => 'revoked', 'note' => 'x'], 'POST');
ok('a date on a Pro licence that stays Pro: until_pro, nothing applied', str_contains($rt['headers']['Location'], 'err=until_pro') && al_lrow($conn, $ap)['revoked_at'] === null);
$conn->query("UPDATE palette_licenses SET plan = 'ai' WHERE id = $cf");
$conn->query("UPDATE palette_subscriptions SET status = 'active' WHERE license_id = $cf");
$GLOBALS['mails'] = [];
$rt = palette_admin_page($conn, $admin, [], ['csrf' => str_repeat('a', 64), 'action' => 'change', 'id' => (string)$cf, 'plan' => 'pro', 'access' => 'revoked', 'note' => 'x'], 'POST');
ok('ai -> pro with a live subscription + revoke: cancel_first, nothing applied', str_contains($rt['headers']['Location'], 'err=cancel_first') && al_lrow($conn, $cf)['revoked_at'] === null && al_lrow($conn, $cf)['plan'] === 'ai');
$rt = palette_admin_page($conn, $admin, [], ['csrf' => str_repeat('a', 64), 'action' => 'change', 'id' => (string)$ap, 'plan' => 'ai', 'until' => gmdate('Y-m-d', time() + 5 * 86400), 'note' => 'Deal'], 'POST');
ok('router: Pro -> Pro + AI with a date works and leaves working access', str_contains($rt['headers']['Location'], 'ok=saved') && palette_licence_access(al_lrow($conn, $ap))['ai'] === true);

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

// ---- form hints and controls
$h = palette_admin_page($conn, $admin, ['tab' => 'licence', 'id' => (string)$mf], [], 'GET')['body'];
ok('page: Pro licence shows the date input with the switching label, +1 month and the revoke hint', str_contains($h, 'Pro + AI access until (needed when switching to Pro + AI)')
    && str_contains($h, 'name="plus_month"') && str_contains($h, 'Revoking a subscriber also ends their subscription now. Restoring access later does not restart billing.'));
$h = palette_admin_page($conn, $admin, ['tab' => 'licence', 'id' => (string)$rf], [], 'GET')['body'];
ok('page: live subscriber sees the renewal hint', str_contains($h, 'An earlier date is replaced at the next renewal.'));
ok('page: Pro without a subscription has no renewal hint', !str_contains(palette_admin_page($conn, $admin, ['tab' => 'licence', 'id' => (string)$mf], [], 'GET')['body'], 'replaced at the next renewal'));

// ---- final review #2: history labels each payment by its own provider; list shows a dash for an unknown source; backfill
$mv = al_lic($conn, 'SDT-MVMV-MVMV-MVMV', 'moved@example.com', 'ai', ['sub_status' => 'active', 'subscription_id' => 'sub_MV1', 'paid_through' => gmdate('Y-m-d H:i:s', time() + 5 * 86400)]);
al_sub($conn, $mv, 'I-OLDPAYPAL1', 'paypal', 'cancelled');
al_sub($conn, $mv, 'sub_MV1', 'stripe');
$conn->query("INSERT INTO palette_sub_payments (subscription_id, sale_id, amount_usd, status, paid_at) VALUES ('I-OLDPAYPAL1', 'SALE-MV-OLD', 2.97, 'completed', '2026-09-03 09:01:00')");
$conn->query("INSERT INTO palette_sub_payments (subscription_id, sale_id, amount_usd, status, paid_at) VALUES ('sub_MV1', 'in_MV1AAAAAAAAAAAAAAAAAAAA', 3.98, 'completed', '2026-10-03 09:01:00')");
$conn->query("INSERT INTO palette_orders (order_id, email, lang, amount_usd, status, consent_at, created_at, captured_at, license_id, provider) VALUES ('ORD-MVPP', 'moved@example.com', 'en', 1.47, 'captured', NOW(), '2026-08-01 08:00:00', '2026-08-01 08:01:00', $mv, 'paypal')");
$h = palette_admin_page($conn, $admin, ['tab' => 'licence', 'id' => (string)$mv], [], 'GET')['body'];
ok('#2 history: old PayPal subscription payment labelled PayPal on a Stripe licence', str_contains($h, 'PayPal: $2.97 paid.'));
ok('#2 history: Stripe payment labelled Stripe', str_contains($h, 'Stripe: $3.98 paid.'));
ok('#2 history: PayPal order labelled PayPal', str_contains($h, 'PayPal: $1.47 paid.'));
$nul = al_lic($conn, 'SDT-NULL-NULL-NULL', 'nosource@example.com', 'pro', ['source' => null, 'order_id' => 'ADM-NULLSRC']);
$h = palette_admin_page($conn, $admin, ['tab' => 'licences', 'q' => 'nosource@example.com'], [], 'GET')['body'];
ok('#2 list: unknown source shows a dash', str_contains($h, 'nosource@example.com') && str_contains($h, '<td class="muted">—</td>'));
$bf = [];
foreach (['I-BACKFILL01', 'ORD-BACKFILL2', 'ADM-BACKFILL3', 'ST-BACKFILL4', 'sub_BACKFILL5'] as $i => $oid) {
    $bf[$oid] = al_lic($conn, 'SDT-BF0' . $i . '-BFBF-BFBF', 'bf@example.com', 'pro', ['source' => null, 'order_id' => $oid]);
}
$adminSrc = al_lic($conn, 'SDT-BFAD-BFAD-BFAD', 'bf@example.com', 'pro', ['source' => 'admin', 'order_id' => 'I-ADMINLIKE1']);
palette_migrate($conn);
palette_migrate($conn);
$src = fn(int $id) => al_lrow($conn, $id)['source'];
ok('#2 backfill: PayPal subscription and order licences become paypal', $src($bf['I-BACKFILL01']) === 'paypal' && $src($bf['ORD-BACKFILL2']) === 'paypal');
ok('#2 backfill: ADM-, ST- and sub_ rows are left alone', $src($bf['ADM-BACKFILL3']) === null && $src($bf['ST-BACKFILL4']) === null && $src($bf['sub_BACKFILL5']) === null);
ok('#2 backfill: a row with a source keeps it', $src($adminSrc) === 'admin');

// ---- final review #4: cancelling a past-due Stripe subscription deletes it now; an active one cancels at period end
$GLOBALS['mails'] = [];
$ptPd = gmdate('Y-m-d H:i:s', time() + 6 * 86400);
$pd = al_lic($conn, 'SDT-PDPD-PDPD-PDPD', 'pastdue@example.com', 'ai', ['sub_status' => 'suspended', 'subscription_id' => 'sub_PD1', 'paid_through' => $ptPd]);
al_sub($conn, $pd, 'sub_PD1', 'stripe', 'suspended', 'pastdue@example.com');
$GLOBALS['st_calls'] = [];
$GLOBALS['st_routes']['DELETE /v1/subscriptions/sub_PD1'] = ['status' => 200, 'json' => ['id' => 'sub_PD1', 'status' => 'canceled']];
$r = palette_admin_cancel($conn, AL_ADMIN, $pd);
$call = $GLOBALS['st_calls'][0] ?? [];
ok('#4 admin cancel, past due: Stripe subscription deleted now', $r['ok'] === true && ($call['method'] ?? '') === 'DELETE' && ($call['path'] ?? '') === '/v1/subscriptions/sub_PD1'
    && count($GLOBALS['st_calls']) === 1);
ok('#4 admin cancel, past due: row cancelled, access still ends at paid_through', al_lrow($conn, $pd)['sub_status'] === 'cancelled' && al_lrow($conn, $pd)['paid_through'] === $ptPd
    && palette_sub_row($conn, 'subscription_id', 'sub_PD1')['status'] === 'cancelled');
$pd2 = al_lic($conn, 'SDT-PDP2-PDP2-PDP2', 'pastdue2@example.com', 'ai', ['sub_status' => 'suspended', 'subscription_id' => 'sub_PD2', 'paid_through' => $ptPd]);
al_sub($conn, $pd2, 'sub_PD2', 'stripe', 'suspended', 'pastdue2@example.com');
$GLOBALS['st_calls'] = [];
$GLOBALS['st_routes']['DELETE /v1/subscriptions/sub_PD2'] = ['status' => 200, 'json' => ['id' => 'sub_PD2', 'status' => 'canceled']];
$r = palette_sub_cancel($conn, 'SDT-PDP2-PDP2-PDP2', null, 'pastdue2@example.com');
ok('#4 account cancel, past due: DELETE, ends on paid_through', $r['ok'] === true && ($GLOBALS['st_calls'][0]['method'] ?? '') === 'DELETE'
    && $r['ends_on'] === date('Y-m-d', strtotime($ptPd)) && al_lrow($conn, $pd2)['sub_status'] === 'cancelled');
$ac = al_lic($conn, 'SDT-ACAC-ACAC-ACAC', 'active4@example.com', 'ai', ['sub_status' => 'active', 'subscription_id' => 'sub_AC4', 'paid_through' => $ptPd]);
al_sub($conn, $ac, 'sub_AC4', 'stripe', 'active', 'active4@example.com');
$GLOBALS['st_calls'] = [];
$GLOBALS['st_routes']['POST /v1/subscriptions/sub_AC4'] = ['status' => 200, 'json' => ['id' => 'sub_AC4', 'cancel_at_period_end' => true]];
$r = palette_sub_cancel($conn, 'SDT-ACAC-ACAC-ACAC', null, 'active4@example.com');
ok('#4 account cancel, active: cancel_at_period_end, no DELETE', $r['ok'] === true && ($GLOBALS['st_calls'][0]['method'] ?? '') === 'POST'
    && ($GLOBALS['st_calls'][0]['params']['cancel_at_period_end'] ?? '') === 'true' && count($GLOBALS['st_calls']) === 1);
$pd3 = al_lic($conn, 'SDT-PDP3-PDP3-PDP3', 'pastdue3@example.com', 'ai', ['sub_status' => 'suspended', 'subscription_id' => 'sub_PD3', 'paid_through' => $ptPd]);
al_sub($conn, $pd3, 'sub_PD3', 'stripe', 'suspended', 'pastdue3@example.com');
$GLOBALS['st_routes']['DELETE /v1/subscriptions/sub_PD3'] = ['status' => 500, 'json' => []];
$r = palette_admin_cancel($conn, AL_ADMIN, $pd3);
ok('#4 past due, Stripe DELETE fails: error, nothing changes', $r === ['ok' => false, 'error' => 'stripe'] && al_lrow($conn, $pd3)['sub_status'] === 'suspended');

// ---- final review #10: to Pro is refused while a cancelled Stripe subscription could still be resumed
// Rule: refuse while the backing row is active/suspended, or while it is a Stripe row marked cancelled whose paid_through is still
// in the future and Stripe does not report the subscription as ended (status canceled/incomplete_expired, or 404).
$rs = al_lic($conn, 'SDT-RSRS-RSRS-RSRS', 'resume@example.com', 'ai', ['sub_status' => 'cancelled', 'subscription_id' => 'sub_RS1', 'paid_through' => $ptPd]);
al_sub($conn, $rs, 'sub_RS1', 'stripe', 'cancelled', 'resume@example.com');
$GLOBALS['st_routes']['GET /v1/subscriptions/sub_RS1'] = ['status' => 200, 'json' => ['id' => 'sub_RS1', 'status' => 'active', 'cancel_at_period_end' => true]];
ok('#10 to Pro: cancelled-at-period-end Stripe subscription -> cancel_first', palette_admin_set_plan($conn, AL_ADMIN, $rs, 'pro', 'Downgrade', false)['error'] === 'cancel_first'
    && al_lrow($conn, $rs)['plan'] === 'ai');
$rt = palette_admin_page($conn, $admin, [], ['csrf' => str_repeat('a', 64), 'action' => 'change', 'id' => (string)$rs, 'plan' => 'pro', 'note' => 'Downgrade'], 'POST');
ok('#10 to Pro through the change form: cancel_first', str_contains($rt['headers']['Location'], 'err=cancel_first') && al_lrow($conn, $rs)['plan'] === 'ai');
$GLOBALS['st_routes']['GET /v1/subscriptions/sub_RS1'] = ['status' => 500, 'json' => []];
ok('#10 to Pro: Stripe unreachable -> cancel_first (cannot prove it ended)', palette_admin_set_plan($conn, AL_ADMIN, $rs, 'pro', 'Downgrade', false)['error'] === 'cancel_first');
$GLOBALS['st_routes']['GET /v1/subscriptions/sub_RS1'] = ['status' => 200, 'json' => ['id' => 'sub_RS1', 'status' => 'canceled']];
ok('#10 to Pro: subscription ended at Stripe -> allowed', palette_admin_set_plan($conn, AL_ADMIN, $rs, 'pro', 'Downgrade', false)['ok'] === true && al_lrow($conn, $rs)['plan'] === 'pro');
$rp = al_lic($conn, 'SDT-RPRP-RPRP-RPRP', 'ppcancel@example.com', 'ai', ['source' => 'paypal', 'sub_status' => 'cancelled', 'subscription_id' => 'I-RPCANCEL1', 'paid_through' => $ptPd]);
al_sub($conn, $rp, 'I-RPCANCEL1', 'paypal', 'cancelled', 'ppcancel@example.com');
$GLOBALS['st_calls'] = [];
ok('#10 to Pro: cancelled PayPal subscription (cannot resume) -> allowed, no Stripe call', palette_admin_set_plan($conn, AL_ADMIN, $rp, 'pro', 'Downgrade', false)['ok'] === true && !$GLOBALS['st_calls']);
$ro = al_lic($conn, 'SDT-RORO-RORO-RORO', 'past@example.com', 'ai', ['sub_status' => 'cancelled', 'subscription_id' => 'sub_RO1', 'paid_through' => gmdate('Y-m-d H:i:s', time() - 86400)]);
al_sub($conn, $ro, 'sub_RO1', 'stripe', 'cancelled', 'past@example.com');
ok('#10 to Pro: cancelled Stripe subscription past paid_through -> allowed', palette_admin_set_plan($conn, AL_ADMIN, $ro, 'pro', 'Downgrade', false)['ok'] === true);

st_stub_reset($conn);
$conn->query('DELETE FROM palette_ai_usage');
