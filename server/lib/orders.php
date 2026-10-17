<?php
// PayPal orders, idempotent capture/fulfilment and the PayPal webhook.
// Uses companies' pp_settings / pp_ready / pp_api only (PayPal keys are shared
// with companies, so its events also arrive here and are ignored by custom_id).

const PALETTE_WEBHOOK_URL = 'https://www.vietnamcos.com/palette/webhook.php';
const PALETTE_WEBHOOK_EVENTS = ['CHECKOUT.ORDER.APPROVED', 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED',
    'BILLING.SUBSCRIPTION.ACTIVATED', 'BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.SUSPENDED', 'BILLING.SUBSCRIPTION.EXPIRED',
    'BILLING.SUBSCRIPTION.PAYMENT.FAILED', 'PAYMENT.SALE.COMPLETED', 'PAYMENT.SALE.REFUNDED', 'PAYMENT.SALE.REVERSED'];
const PALETTE_PAYPAL_ID_RE = '/^[A-Za-z0-9-]{1,40}$/';

const PALETTE_WEBHOOK_MAX_BYTES = 65536;

// Like companies' pp_api, but with a raw JSON body and extra headers.
function palette_pp(array $pp, string $method, string $path, ?string $rawBody, array $extraHeaders = []): array
{
    $err = '';
    $tok = pp_token($pp, $err);
    if ($tok === '') return ['status' => 0, 'json' => ['message' => $err]];
    return pp_http($method, pp_base($pp) . $path,
                   array_merge(['Authorization: Bearer ' . $tok, 'Content-Type: application/json', 'Accept: application/json'], $extraHeaders), $rawBody);
}

// No answer, rate limited or a server error: worth PayPal redelivering the webhook.
function palette_transient(int $status): bool
{
    return $status === 0 || $status === 429 || $status >= 500;
}

// Webhook status for a capture/fulfil result: transient failures become 'retry' (HTTP 503).
function palette_outcome(array $r, string $done, string $failed): string
{
    if ($r['ok']) return empty($r['already']) ? $done : 'already';
    if (!empty($r['pending'])) return 'pending';   // PAYMENT.CAPTURE.COMPLETED will follow and fulfil
    return empty($r['retry']) ? $failed : 'retry';
}

// [HTTP status, text] for webhook.php: 413 oversized, 400 unverified, 503 retry, else 200.
function palette_webhook_response(mysqli $c, array $headersLower, string $body): array
{
    if (strlen($body) > PALETTE_WEBHOOK_MAX_BYTES) return [413, 'too large'];
    $r = palette_webhook($c, $headersLower, $body);
    return [$r === 'unverified' ? 400 : ($r === 'retry' ? 503 : 200), $r];
}

// "1.49" -> 149; null unless a plain non-negative amount with at most 2 decimals.
function palette_cents(mixed $v): ?int
{
    if (!is_string($v) || !preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/', $v, $m)) return null;
    return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
}

function palette_money(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

// Stored settings validated at use: ['price' => cents, 'tax_mode' => none|ca, 'rate_ppm' => tax rate in millionths].
function palette_money_settings(array $s): array
{
    $price = is_string($s['price_usd'] ?? null) && preg_match('/^\d+\.\d{2}$/', $s['price_usd']) ? palette_cents($s['price_usd']) : null;
    $mode = in_array($s['tax_mode'] ?? null, ['none', 'ca'], true) ? $s['tax_mode'] : 'none';
    $rate = $s['tax_rate'] ?? null;
    $rate = (is_string($rate) || is_int($rate) || is_float($rate)) && is_numeric($rate) && (float)$rate >= 0 && (float)$rate <= 0.2 ? (float)$rate : 0.0725;
    return ['price' => $price ?? 149, 'tax_mode' => $mode, 'rate_ppm' => (int)round($rate * 1000000)];
}

function palette_tax_applies(array $settings, ?string $country, ?string $region): bool
{
    return palette_money_settings($settings)['tax_mode'] === 'ca'
        && strtoupper((string)$country) === 'US' && strtoupper((string)$region) === 'CA';
}

function palette_total(array $settings, ?string $country, ?string $region): array
{
    $m = palette_money_settings($settings);
    // Integer cents; tax rounded half up.
    $tax = palette_tax_applies($settings, $country, $region) ? intdiv($m['price'] * $m['rate_ppm'] + 500000, 1000000) : 0;
    return ['amount' => palette_money($m['price'] + $tax), 'tax' => palette_money($tax)];
}

function palette_order_by(mysqli $c, string $col, string|int $val): ?array
{
    $col = $col === 'id' ? 'id' : ($col === 'capture_id' ? 'capture_id' : 'order_id');
    $st = $c->prepare("SELECT * FROM palette_orders WHERE $col = ?");
    $v = (string)$val;
    $st->bind_param('s', $v);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ?: null;
}

function palette_mark_failed(mysqli $c, int $id): void
{
    $c->query("UPDATE palette_orders SET status = 'failed' WHERE id = $id AND status = 'created'");
}

function palette_create_order(mysqli $c, string $email, string $lang, bool $consent, ?string $country, ?string $region): array
{
    if (preg_match('/[\r\n]/', $email)) return ['ok' => false, 'error' => 'err_email'];
    $email = strtolower(trim($email));
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'err_email'];
    if (!$consent) return ['ok' => false, 'error' => 'err_consent'];
    $pp = pp_settings($c);
    if (!pp_ready($pp)) return ['ok' => false, 'error' => 'err_paused'];
    $lang = palette_lang($lang);

    $s = palette_settings($c);
    if (palette_money_settings($s)['tax_mode'] === 'ca') {
        $country = is_string($country) && preg_match('/^[A-Za-z]{2}$/', $country) ? strtoupper($country) : null;
        $region = is_string($region) && preg_match('/^[A-Za-z0-9-]{1,8}$/', $region) ? strtoupper($region) : null;
    } else {
        $country = $region = null;
    }
    $t = palette_total($s, $country, $region);
    $item = palette_money(palette_cents($t['amount']) - palette_cents($t['tax']));

    // Reserve the row first (unguessable placeholder order_id) so custom_id can carry its id.
    $placeholder = 'new:' . bin2hex(random_bytes(16));
    $st = $c->prepare("INSERT INTO palette_orders (order_id, email, lang, country, region, amount_usd, tax_usd, status, consent_at, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 'created', NOW(), NOW())");
    $st->bind_param('sssssss', $placeholder, $email, $lang, $country, $region, $t['amount'], $t['tax']);
    $st->execute();
    $id = (int)$c->insert_id;
    $st->close();

    $usd = fn(string $v) => ['currency_code' => 'USD', 'value' => $v];
    $r = pp_api($pp, 'POST', '/v2/checkout/orders', [
        'intent' => 'CAPTURE',
        'purchase_units' => [['amount' => $usd($t['amount']) + ['breakdown' => ['item_total' => $usd($item), 'tax_total' => $usd($t['tax'])]],
                              'description' => 'Site to Design Token Pro', 'custom_id' => 'palette:' . $id]],
        'application_context' => ['brand_name' => 'Site to Design Token', 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'PAY_NOW'],
    ]);
    $oid = (string)($r['json']['id'] ?? '');
    if ($r['status'] < 200 || $r['status'] >= 300 || !preg_match(PALETTE_PAYPAL_ID_RE, $oid)) {
        palette_mark_failed($c, $id);
        error_log('palette: PayPal create order failed (HTTP ' . (int)$r['status'] . ') for row ' . $id);
        return ['ok' => false, 'error' => 'err_paypal'];
    }
    try {
        $st = $c->prepare("UPDATE palette_orders SET order_id = ? WHERE id = ? AND status = 'created'");
        $st->bind_param('si', $oid, $id);
        $st->execute();
        $st->close();
    } catch (mysqli_sql_exception $e) {
        palette_mark_failed($c, $id);
        error_log("palette: could not store PayPal order $oid for row $id: " . $e->getMessage());
        return ['ok' => false, 'error' => 'err_paypal'];
    }
    return ['ok' => true, 'order_id' => $oid, 'total' => $t['amount']];
}

// The issued key for a captured order, as palette_capture answers it.
function palette_order_result(mysqli $c, int $orderRowId): array
{
    $st = $c->prepare("SELECT l.license_key, l.email FROM palette_orders o JOIN palette_licenses l ON l.id = o.license_id
                       WHERE o.id = ? AND o.status = 'captured'");
    $st->bind_param('i', $orderRowId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ? ['ok' => true, 'key' => $r['license_key'], 'email_masked' => palette_mask_email($r['email'])]
              : ['ok' => false, 'error' => 'err_paypal'];
}

function palette_capture(mysqli $c, string $orderId): array
{
    if (!preg_match(PALETTE_PAYPAL_ID_RE, $orderId)) return ['ok' => false, 'error' => 'err_paypal'];
    $o = palette_order_by($c, 'order_id', $orderId);
    if (!$o || $o['status'] === 'failed') return ['ok' => false, 'error' => 'err_paypal'];
    if ($o['status'] === 'captured') return palette_order_result($c, (int)$o['id']);

    $pp = pp_settings($c);
    $path = '/v2/checkout/orders/' . rawurlencode($orderId);
    // PayPal-Request-Id makes a repeated capture call idempotent on PayPal's side too.
    $r = palette_pp($pp, 'POST', $path . '/capture', '{}', ['PayPal-Request-Id: palette-capture-' . $orderId]);
    $j = (array)($r['json'] ?? []);
    if (($j['details'][0]['issue'] ?? '') === 'ORDER_ALREADY_CAPTURED') {
        $r = pp_api($pp, 'GET', $path);
        $j = (array)($r['json'] ?? []);
    }
    $cap = $j['purchase_units'][0]['payments']['captures'][0] ?? null;
    if (($j['status'] ?? '') === 'COMPLETED' && is_array($cap) && ($cap['status'] ?? '') === 'PENDING') {
        // Paid, but PayPal holds the money for review: the key is issued by the PAYMENT.CAPTURE.COMPLETED webhook.
        error_log("palette: capture of $orderId is PENDING (" . ($cap['status_details']['reason'] ?? '?') . ')');
        return ['ok' => false, 'error' => 'err_paypal', 'pending' => true];
    }
    if (($j['status'] ?? '') !== 'COMPLETED' || !is_array($cap) || ($cap['status'] ?? '') !== 'COMPLETED') {
        $transient = palette_transient((int)$r['status']);
        error_log("palette: capture of $orderId not completed (HTTP " . (int)$r['status'] . ', ' . ($j['details'][0]['issue'] ?? $j['status'] ?? '?') . ')');
        return ['ok' => false, 'error' => 'err_paypal'] + ($transient ? ['retry' => true] : []);
    }
    return palette_fulfil($c, $o, $cap, (string)($j['payer']['email_address'] ?? ''));
}

// $payerEmail is informational only: the key always goes to the address the buyer typed at checkout.
function palette_fulfil(mysqli $c, array $order, array $capture, string $payerEmail): array
{
    $id = (int)$order['id'];
    if (($capture['status'] ?? '') !== 'COMPLETED') return ['ok' => false, 'error' => 'err_paypal'];
    $paid = palette_cents($capture['amount']['value'] ?? null);
    $want = palette_cents((string)$order['amount_usd']);
    if (($capture['amount']['currency_code'] ?? '') !== 'USD' || $paid === null || $want === null || $paid < $want) {
        palette_mark_failed($c, $id);
        error_log("palette: order {$order['order_id']} paid " . json_encode($capture['amount'] ?? null) . ", expected {$order['amount_usd']} USD");
        return ['ok' => false, 'error' => 'err_paypal'];
    }
    $capId = substr((string)($capture['id'] ?? ''), 0, 40);

    // Claim first: only the request whose UPDATE flips 'created' issues the key. The transaction
    // makes a concurrent claimer wait on the row lock until license_id is stored.
    $c->begin_transaction();
    try {
        $st = $c->prepare("UPDATE palette_orders SET status = 'captured', capture_id = ?, captured_at = NOW() WHERE id = ? AND status = 'created'");
        $st->bind_param('si', $capId, $id);
        $st->execute();
        $claimed = $st->affected_rows === 1;
        $st->close();
        if (!$claimed) {
            $c->commit();
            $r = palette_order_result($c, $id);
            if (!$r['ok']) error_log("palette: paid capture $capId for order {$order['order_id']} but the order is not fulfillable");
            return $r['ok'] ? $r + ['already' => true] : $r;
        }
        $lic = palette_issue_license($c, (string)$order['email'], (string)$order['order_id'], (string)$order['lang']);
        $lid = (int)$lic['id'];
        $c->query("UPDATE palette_orders SET license_id = $lid WHERE id = $id");
        $c->commit();
    } catch (Throwable $e) {
        $c->rollback();
        error_log("palette: fulfil of order {$order['order_id']} failed (will retry): " . $e->getMessage());
        return ['ok' => false, 'error' => 'err_paypal', 'retry' => true];
    }
    $m = palette_license_email($lic['key'], (string)$order['lang']);
    if (!palette_mail((string)$order['email'], $m['subject'], $m['html'])) {
        error_log("PALETTE_MAIL_FAILED order={$order['order_id']}");   // alert on this prefix; the buyer saw the key on the page
    }
    return ['ok' => true, 'key' => $lic['key'], 'email_masked' => palette_mask_email((string)$order['email'])];
}

// Row id from a custom_id "palette:<id>"; null when absent; false when it belongs to someone else.
function palette_custom_row(array $res): int|false|null
{
    $cid = $res['custom_id'] ?? $res['purchase_units'][0]['custom_id'] ?? null;
    if ($cid === null || $cid === '') return null;
    return is_string($cid) && preg_match('/^palette:(\d{1,10})$/', $cid, $m) ? (int)$m[1] : false;
}

function palette_webhook(mysqli $c, array $headersLower, string $body): string
{
    $s = palette_settings($c);
    $pp = pp_settings($c);
    if (!is_string($s['webhook_id']) || $s['webhook_id'] === '' || $pp['client_id'] === '') return 'not configured';
    $event = json_decode($body, true);
    if (!is_array($event) || array_is_list($event)) return 'bad body';
    if ($s['webhook_mode'] !== '' && $s['webhook_mode'] !== $pp['mode']) {
        error_log("palette: webhook registered in {$s['webhook_mode']} mode but PayPal is now in {$pp['mode']} mode; re-run palette/setup.php");
    }
    // The event is spliced in as PayPal sent it: decoding and re-encoding could change bytes the signature covers.
    $head = json_encode([
        'auth_algo' => (string)($headersLower['paypal-auth-algo'] ?? ''), 'cert_url' => (string)($headersLower['paypal-cert-url'] ?? ''),
        'transmission_id' => (string)($headersLower['paypal-transmission-id'] ?? ''), 'transmission_sig' => (string)($headersLower['paypal-transmission-sig'] ?? ''),
        'transmission_time' => (string)($headersLower['paypal-transmission-time'] ?? ''), 'webhook_id' => $s['webhook_id'],
    ], JSON_UNESCAPED_SLASHES);
    $v = palette_pp($pp, 'POST', '/v1/notifications/verify-webhook-signature', substr($head, 0, -1) . ',"webhook_event":' . $body . '}');
    if (($v['status'] ?? 0) !== 200 || ($v['json']['verification_status'] ?? '') !== 'SUCCESS') return 'unverified';

    $type = (string)($event['event_type'] ?? '');
    $res = is_array($event['resource'] ?? null) ? $event['resource'] : [];
    if (!in_array($type, PALETTE_WEBHOOK_EVENTS, true)) return 'ignored';
    // Subscriptions carry custom_id "palette-sub:<id>" (sales carry none): lib/subscriptions.php sorts them out.
    if (str_starts_with($type, 'BILLING.SUBSCRIPTION.') || str_starts_with($type, 'PAYMENT.SALE.')) return palette_sub_event($c, $type, $res);
    $rowId = palette_custom_row($res);
    if ($rowId === false) return 'ignored';   // a companies order on the shared PayPal app
    $related = (array)($res['supplementary_data']['related_ids'] ?? []);

    if ($type === 'CHECKOUT.ORDER.APPROVED') {
        $o = palette_webhook_order($c, ['order_id' => $res['id'] ?? null], $rowId);
        if (!$o) return 'unknown order';
        if ($o['status'] === 'captured') return 'already';
        return palette_outcome(palette_capture($c, (string)$o['order_id']), 'captured', 'capture failed');
    }
    if ($type === 'PAYMENT.CAPTURE.COMPLETED') {
        $o = palette_webhook_order($c, ['order_id' => $related['order_id'] ?? null], $rowId);
        if (!$o) return 'unknown order';
        if ($o['status'] === 'captured') return 'already';
        return palette_outcome(palette_fulfil($c, $o, $res, ''), 'fulfilled', 'fulfil failed');
    }
    // REFUNDED (resource = refund, "up" link -> capture) or REVERSED (resource = the capture).
    $capId = $related['capture_id'] ?? null;
    foreach ((array)($res['links'] ?? []) as $l) {
        if (($l['rel'] ?? '') === 'up' && preg_match('#/v2/payments/captures/([A-Za-z0-9-]+)$#', (string)($l['href'] ?? ''), $m)) $capId = $m[1];
    }
    if ($type === 'PAYMENT.CAPTURE.REVERSED') $capId ??= $res['id'] ?? null;
    $o = palette_webhook_order($c, ['order_id' => $related['order_id'] ?? null, 'capture_id' => $capId], $rowId);
    if (!$o) return 'unknown order';
    palette_revoke_by_order($c, (string)$o['order_id']);
    error_log("palette: $type for order {$o['order_id']}; license revoked");
    return 'revoked';
}

// The order an event is about: by its PayPal ids, or by the custom_id row only when the event
// carries no PayPal id at all. A row that disagrees with custom_id is refused.
function palette_webhook_order(mysqli $c, array $ids, ?int $rowId): ?array
{
    $ids = array_filter($ids, fn($v) => is_string($v) && $v !== '');
    $o = null;
    foreach ($ids as $col => $val) {
        if (preg_match(PALETTE_PAYPAL_ID_RE, $val) && ($o = palette_order_by($c, $col, $val))) break;
    }
    if (!$o && !$ids && $rowId !== null) $o = palette_order_by($c, 'id', $rowId);
    if ($o && $rowId !== null && (int)$o['id'] !== $rowId) {
        error_log('palette: webhook custom_id palette:' . $rowId . ' does not match order ' . $o['order_id']);
        return null;
    }
    return $o;
}

function palette_setup_webhook(mysqli $c): array
{
    $pp = pp_settings($c);
    if ($pp['client_id'] === '' || $pp['secret'] === '') return ['ok' => false, 'error' => 'err_paused'];
    $hook = '';
    $have = [];
    $list = pp_api($pp, 'GET', '/v1/notifications/webhooks');
    foreach ((array)($list['json']['webhooks'] ?? []) as $w) {
        if (($w['url'] ?? '') === PALETTE_WEBHOOK_URL) {
            $hook = (string)($w['id'] ?? '');
            foreach ((array)($w['event_types'] ?? []) as $e) {
                if (is_array($e) && is_string($e['name'] ?? null)) $have[] = $e['name'];
            }
        }
    }
    $reused = $hook !== '';
    if (!$reused) {
        $r = pp_api($pp, 'POST', '/v1/notifications/webhooks', ['url' => PALETTE_WEBHOOK_URL,
            'event_types' => array_map(fn($e) => ['name' => $e], PALETTE_WEBHOOK_EVENTS)]);
        $hook = (string)($r['json']['id'] ?? '');
        if ($hook === '') {
            error_log('palette: webhook registration failed (HTTP ' . (int)$r['status'] . ')');
            return ['ok' => false, 'error' => 'err_paypal'];
        }
    } else {
        // Keep whatever the webhook already listens to and add the missing events.
        $have = array_values(array_unique($have));
        $all = array_values(array_unique(array_merge($have, PALETTE_WEBHOOK_EVENTS)));
        if (count($all) > count($have)) {
            $r = pp_api($pp, 'PATCH', '/v1/notifications/webhooks/' . rawurlencode($hook),
                [['op' => 'replace', 'path' => '/event_types', 'value' => array_map(fn($e) => ['name' => $e], $all)]]);
            if ($r['status'] < 200 || $r['status'] >= 300) {
                error_log('palette: webhook event update failed (HTTP ' . (int)$r['status'] . ')');
                return ['ok' => false, 'error' => 'err_paypal'];
            }
        }
    }
    palette_settings_put($c, ['webhook_id' => $hook, 'webhook_mode' => $pp['mode']] + palette_settings($c));
    return ['ok' => true, 'webhook_id' => $hook, 'mode' => $pp['mode'], 'reused' => $reused];
}
