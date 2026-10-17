<?php
// Sends only through Resend (never PHP mail()). False for invalid addresses, a missing key or a non-2xx reply.
// With PALETTE_NO_MAIL defined (tests) the message is recorded in $GLOBALS['palette_sent'] instead of sent.
function palette_mail(string $to, string $subject, string $html): bool
{
    $to = strtolower(trim($to));
    if (preg_match('/[\r\n]/', $to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    // Test capture; a test that stubs $GLOBALS['pp_http'] exercises the real Resend path instead.
    if (defined('PALETTE_NO_MAIL') && !isset($GLOBALS['pp_http'])) {
        $GLOBALS['palette_sent'][] = ['to' => $to, 'subject' => $subject, 'html' => $html];
        return true;
    }
    $key = trim((string)getAdminSetting('resend_api_key', ''));
    if ($key === '') {
        error_log('palette_mail: resend_api_key is not set');
        return false;
    }
    $body = json_encode(['from' => 'Site to Design Token <palette@vietnamcos.com>', 'to' => [$to],
                         'subject' => str_replace(["\r", "\n"], ' ', $subject), 'html' => $html,
                         'reply_to' => 'support@vietnamcos.com'], JSON_UNESCAPED_UNICODE);
    $r = pp_http('POST', 'https://api.resend.com/emails',
                 ['Content-Type: application/json', 'Authorization: Bearer ' . $key], $body);
    if ($r['status'] >= 200 && $r['status'] < 300 && !empty($r['json']['id'])) return true;
    error_log('palette_mail: Resend returned HTTP ' . (int)$r['status']);
    return false;
}

function palette_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function palette_email_shell(string $lang, string $inner): string
{
    $lang = palette_lang($lang);
    $dir = $lang === 'ar' ? ' dir="rtl"' : '';
    return '<!doctype html><html lang="' . palette_h($lang) . '"><head><meta charset="utf-8"></head>'
        . '<body style="margin:0;padding:24px;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1c1f26">'
        . '<div' . $dir . ' style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #e1e4ea;border-radius:8px;padding:24px;line-height:1.5;font-size:15px">'
        . $inner . '</div></body></html>';
}

function palette_key_block(string $key): string
{
    return '<div dir="ltr" style="margin:16px 0;padding:14px 16px;background:#f0f2f6;border:1px solid #d5d9e2;border-radius:6px;'
        . 'font-family:Consolas,Menlo,Courier New,monospace;font-size:18px;letter-spacing:1px;text-align:center">'
        . palette_h($key) . '</div>';
}

function palette_email_steps(string $lang): string
{
    return '<p style="margin:16px 0 4px;font-weight:bold">' . palette_h(palette_t($lang, 'email_steps_title')) . '</p>'
        . '<ol style="margin:0;padding-inline-start:20px">'
        . '<li>' . palette_h(palette_t($lang, 'email_step1')) . '</li>'
        . '<li>' . palette_h(palette_t($lang, 'email_step2')) . '</li>'
        . '<li>' . palette_h(palette_t($lang, 'email_step3')) . '</li></ol>';
}

function palette_email_footer(string $lang): string
{
    $url = 'https://vietnamcos.com/palette/account';
    return '<p style="margin:16px 0 8px">' . palette_h(palette_t($lang, 'email_account_text'))
        . ' <a href="' . $url . '" style="color:#0b6b57">' . $url . '</a></p>'
        . '<p style="margin:16px 0 0;font-size:13px;color:#5b6270">' . palette_h(palette_t($lang, 'email_refund')) . '</p>';
}

// ['subject' => ..., 'html' => ...] for a freshly issued key.
function palette_license_email(string $key, string $lang): array
{
    $html = '<h1 style="font-size:20px;margin:0 0 12px">' . palette_h(palette_t($lang, 'email_license_title')) . '</h1>'
        . '<p style="margin:0 0 8px">' . palette_h(palette_t($lang, 'email_license_intro')) . '</p>'
        . '<p style="margin:16px 0 0;font-size:13px;color:#5b6270">' . palette_h(palette_t($lang, 'email_key_label')) . '</p>'
        . palette_key_block($key)
        . palette_email_steps($lang)
        . palette_email_footer($lang);
    return ['subject' => palette_t($lang, 'email_license_subject'), 'html' => palette_email_shell($lang, $html)];
}

// ['subject' => ..., 'html' => ...] listing every key (array of strings) for an address.
function palette_resend_email(array $keys, string $lang): array
{
    $html = '<h1 style="font-size:20px;margin:0 0 12px">' . palette_h(palette_t($lang, 'email_resend_title')) . '</h1>'
        . '<p style="margin:0 0 8px">' . palette_h(palette_t($lang, 'email_resend_intro', ['count' => count($keys)])) . '</p>';
    foreach ($keys as $k) $html .= palette_key_block((string)$k);
    $html .= palette_email_steps($lang) . palette_email_footer($lang);
    return ['subject' => palette_t($lang, 'email_resend_subject'), 'html' => palette_email_shell($lang, $html)];
}

// ['subject' => ..., 'html' => ...] for a new Pro + AI subscription key. $limit = analyses per month.
function palette_sub_email(string $key, string $lang, int $limit): array
{
    $url = 'https://vietnamcos.com/palette/account';
    $html = '<h1 style="font-size:20px;margin:0 0 12px">' . palette_h(palette_t($lang, 'email_sub_title')) . '</h1>'
        . '<p style="margin:0 0 8px">' . palette_h(palette_t($lang, 'email_sub_intro')) . '</p>'
        . '<p style="margin:16px 0 0;font-size:13px;color:#5b6270">' . palette_h(palette_t($lang, 'email_key_label')) . '</p>'
        . palette_key_block($key)
        . '<p style="margin:16px 0 4px;font-weight:bold">' . palette_h(palette_t($lang, 'email_sub_includes')) . '</p>'
        . '<ul style="margin:0;padding-inline-start:20px">'
        . '<li>' . palette_h(palette_t($lang, 'email_sub_inc_pro')) . '</li>'
        . '<li>' . palette_h(palette_t($lang, 'email_sub_inc_ai', ['count' => $limit])) . '</li></ul>'
        . palette_email_steps($lang)
        . '<p style="margin:16px 0 8px">' . palette_h(palette_t($lang, 'email_sub_pro_key')) . '</p>'
        . '<p style="margin:16px 0 8px">' . palette_h(palette_t($lang, 'email_sub_manage'))
        . ' <a href="' . $url . '" style="color:#0b6b57">' . $url . '</a></p>'
        . '<p style="margin:16px 0 0;font-size:13px;color:#5b6270">' . palette_h(palette_t($lang, 'email_sub_refund')) . '</p>';
    return ['subject' => palette_t($lang, 'email_sub_subject'), 'html' => palette_email_shell($lang, $html)];
}

// ['subject' => ..., 'html' => ...] confirming a cancellation; $endsOn is Y-m-d.
function palette_sub_cancel_email(string $endsOn, string $lang): array
{
    $url = PALETTE_PAYPAL_AUTOPAY_URL;
    $html = '<h1 style="font-size:20px;margin:0 0 12px">' . palette_h(palette_t($lang, 'email_cancel_title')) . '</h1>'
        . '<p style="margin:0 0 8px">' . palette_h(palette_t($lang, 'email_cancel_body', ['date' => $endsOn])) . '</p>'
        . '<p style="margin:16px 0 8px">' . palette_h(palette_t($lang, 'email_cancel_paypal'))
        . ' <a href="' . $url . '" style="color:#0b6b57">' . $url . '</a></p>';
    return ['subject' => palette_t($lang, 'email_cancel_subject'), 'html' => palette_email_shell($lang, $html)];
}
