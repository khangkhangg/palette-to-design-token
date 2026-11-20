<?php
// A Stripe purchase made in a signed-in companies session saves the key to that account (billing mockup, "Attach the key on purchase").
require __DIR__ . '/helpers.php';
require __DIR__ . '/st-stub.php';
ini_set('error_log', sys_get_temp_dir() . '/palette-buy-attach-test.log');
ob_start();
session_start();

function ba_reset(mysqli $c, ?int $uid): void
{
    st_stub_reset($c);
    st_stub_connect($c, 'live');
    $c->query('DELETE FROM palette_rate');
    $_SESSION = $uid === null ? [] : ['user_id' => $uid];
}

function ba_call(string $action, array $post = []): array
{
    return palette_api_handle(['method' => 'POST', 'action' => $action, 'post' => $post + ['csrf' => generateCSRFToken('palette_checkout')],
        'origin' => '', 'ip' => '203.0.113.9', 'session' => $_SESSION]);
}

// st_checkout as the current $_SESSION; returns the new orders (or subscriptions) row.
function ba_checkout(array $post = []): array
{
    $GLOBALS['st_routes']['POST /v1/checkout/sessions'] = ['status' => 200, 'json' => ['id' => 'cs_live_' . mt_rand(), 'url' => 'https://checkout.stripe.com/c/pay/x']];
    $r = ba_call('st_checkout', $post + ['email' => 'buyer@studio.vn', 'consent' => '1', 'plan' => 'pro']);
    if (!($r['body']['ok'] ?? false)) throw new RuntimeException('st_checkout failed: ' . json_encode($r['body']));
    [$kind, $id] = explode(':', (string)end($_SESSION['palette_st']));
    $row = $kind === 's' ? palette_sub_row($GLOBALS['conn'], 'id', (int)$id) : palette_order_by($GLOBALS['conn'], 'id', (int)$id);
    // Stripe answers the re-fetch on return and in the webhook; $meta lets a test forge metadata.
    $GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 200, 'json' => ba_session($row)];
    return $row;
}

function ba_session(array $row, array $meta = []): array
{
    return ['id' => $row['stripe_session'], 'object' => 'checkout.session', 'mode' => 'payment', 'payment_status' => 'paid', 'status' => 'complete',
        'currency' => 'usd', 'amount_total' => 149, 'payment_intent' => 'pi_123', 'client_reference_id' => 'palette:order:' . $row['id'],
        'metadata' => $meta + ['palette' => 'order', 'row' => (string)$row['id']], 'livemode' => true];
}

function ba_hook(mysqli $c, array $session): array
{
    $payload = json_encode(['id' => 'evt_' . mt_rand(), 'object' => 'event', 'type' => 'checkout.session.completed', 'livemode' => true, 'data' => ['object' => $session]]);
    return palette_st_webhook_response($c, $payload, st_sign($payload, 'whsec_live'));
}

function ba_lics(mysqli $c): array
{
    return $c->query('SELECT license_key, user_id FROM palette_licenses ORDER BY id')->fetch_all(MYSQLI_ASSOC);
}

// ---- signed in, return page first, then the webhook: one key, saved to user 42
ba_reset($conn, 42);
$row = ba_checkout();
ok('signed-in checkout stores the session user on the order', (int)$row['user_id'] === 42);
$r = ba_call('st_confirm', ['session' => $row['stripe_session']]);
$l = ba_lics($conn);
ok('return path: key issued with user_id 42 and saved flag', $r['body']['state'] === 'paid' && $r['body']['saved'] === true
    && count($l) === 1 && (int)$l[0]['user_id'] === 42 && $l[0]['license_key'] === $r['body']['key']);
$h = ba_hook($conn, ba_session($row));
$l = ba_lics($conn);
ok('webhook after return: no second key, user_id kept', $h[0] === 200 && count($l) === 1 && (int)$l[0]['user_id'] === 42);

// ---- signed in, webhook first, then the return page
ba_reset($conn, 42);
$row = ba_checkout();
$h = ba_hook($conn, ba_session($row));
$l = ba_lics($conn);
ok('webhook path: key issued with user_id 42', $h[0] === 200 && count($l) === 1 && (int)$l[0]['user_id'] === 42);
$r = ba_call('st_confirm', ['session' => $row['stripe_session']]);
ok('return after webhook: same key, saved, no duplicate', ($r['body']['key'] ?? null) === $l[0]['license_key'] && $r['body']['saved'] === true
    && count(ba_lics($conn)) === 1);

// ---- signed out stays as today
ba_reset($conn, null);
$row = ba_checkout();
$r = ba_call('st_confirm', ['session' => $row['stripe_session']]);
$l = ba_lics($conn);
ok('signed-out purchase: no user_id on order or key, not saved', $row['user_id'] === null && count($l) === 1 && $l[0]['user_id'] === null
    && $r['body']['saved'] === false);
ok('signed-out key can still be added with "Add a key"', palette_claim($conn, $l[0]['license_key'], 7)
    && (int)ba_lics($conn)[0]['user_id'] === 7);

// ---- forged ids: request fields and Stripe metadata are ignored
ba_reset($conn, null);
$row = ba_checkout(['user_id' => '99', 'uid' => '99']);
ok('signed out + forged user_id in the request: order has none', $row['user_id'] === null);
ba_reset($conn, 42);
$row = ba_checkout(['user_id' => '99', 'uid' => '99']);
ok('signed in + forged user_id in the request: order has the session user', (int)$row['user_id'] === 42);
$forged = ba_session($row, ['user_id' => '99', 'uid' => '99']);
$GLOBALS['st_routes']['GET /v1/checkout/sessions/' . $row['stripe_session']] = ['status' => 200, 'json' => $forged];
ba_hook($conn, $forged);
ok('forged user_id in Stripe metadata: key goes to the session user', (int)ba_lics($conn)[0]['user_id'] === 42);
$_SESSION = ['user_id' => 99];
$r = ba_call('st_confirm', ['session' => $row['stripe_session']]);
ok('another account confirming the session gets no key and no saved flag', !isset($r['body']['key']) && !isset($r['body']['saved']));

// ---- an existing user_id is never overwritten
$key = ba_lics($conn)[0]['license_key'];
ok('claim by another user is refused and user_id stays', !palette_claim($conn, $key, 7) && (int)ba_lics($conn)[0]['user_id'] === 42);
ba_hook($conn, ba_session($row));
ok('webhook redelivery leaves user_id alone', count(ba_lics($conn)) === 1 && (int)ba_lics($conn)[0]['user_id'] === 42);

// ---- Pro + AI subscription: the subscription row carries the user to the key
ba_reset($conn, 42);
$sub = ba_checkout(['plan' => 'ai']);
ok('signed-in Pro + AI checkout stores the user on the subscription row', (int)$sub['user_id'] === 42);
$s = ['id' => 'sub_123', 'object' => 'subscription', 'status' => 'active', 'currency' => 'usd', 'metadata' => ['palette' => 'sub', 'row' => (string)$sub['id']],
    'current_period_start' => 1760000000, 'current_period_end' => 1762592000, 'cancel_at_period_end' => false,
    'items' => ['data' => [['price' => ['unit_amount' => 399, 'currency' => 'usd']]]]];
$f = palette_st_fulfil_sub($conn, $sub, ['id' => $sub['stripe_session'], 'object' => 'checkout.session', 'mode' => 'subscription', 'payment_status' => 'paid',
    'status' => 'complete', 'currency' => 'usd', 'customer' => 'cus_123', 'subscription' => $s, 'client_reference_id' => 'palette:sub:' . $sub['id'],
    'metadata' => ['user_id' => '99'], 'livemode' => true]);
ok('Pro + AI key issued with the session user', $f['ok'] && count(ba_lics($conn)) === 1 && (int)ba_lics($conn)[0]['user_id'] === 42);

// ---- copy: real handoff steps, Pro + AI success line, saved note, in every language
$old = ['Unlock Pro', '解锁 Pro', 'Pro अनलॉक', 'Desbloquear Pro', 'فتح Pro', 'Débloquer Pro', 'Pro আনলক'];
$label = ['en' => 'Already have a key? Enter it', 'zh-CN' => '已有密钥？输入密钥', 'hi' => 'पहले से कुंजी है? दर्ज करें', 'es' => '¿Ya tienes una clave? Introdúcela',
    'ar' => 'لديك مفتاح بالفعل؟ أدخله', 'fr' => 'Vous avez déjà une clé ? Saisissez-la', 'bn' => 'আগে থেকেই কী আছে? লিখুন'];
foreach ($label as $lg => $want) {
    $s = require PALETTE_DIR . "/lib/i18n/$lg.php";
    $paste = (string)($s['co_ext_paste'] ?? '');
    ok("copy $lg: paste names the real control", str_contains($paste, $want) && str_contains($paste, 'Site to Design Token')
        && str_replace($old, '', $paste) === $paste);
    ok("copy $lg: Pro + AI success line and saved note", str_contains((string)($s['co_ext_ok_ai'] ?? ''), 'Pro + AI') && ($s['co_saved'] ?? '') !== '');
}
ok('copy: en saved note matches the mockup', (require PALETTE_DIR . '/lib/i18n/en.php')['co_saved'] === 'Saved to your account. You can find it any time under Your keys.');
ok('copy: Pro + AI line reaches the checkout script', palette_script_strings('en')['co_ext_ok_ai'] === 'Your extension now has Pro + AI.');
