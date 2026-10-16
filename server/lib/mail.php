<?php
// Returns false for invalid addresses (including header-injection attempts).
// With PALETTE_NO_MAIL defined (tests) the message is recorded in $GLOBALS['palette_sent'] instead of sent.
function palette_mail(string $to, string $subject, string $html): bool
{
    if (preg_match('/[\r\n]/', $to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    if (defined('PALETTE_NO_MAIL')) {
        $GLOBALS['palette_sent'][] = ['to' => $to, 'subject' => $subject, 'html' => $html];
        return true;
    }
    $name = defined('EMAIL_FROM_NAME') ? EMAIL_FROM_NAME : 'Site to Design Token';
    $addr = defined('EMAIL_FROM_ADDRESS') ? EMAIL_FROM_ADDRESS : 'noreply@vietnamcos.com';
    $name = str_replace(["\r", "\n"], '', (string)$name);
    $addr = str_replace(["\r", "\n"], '', (string)$addr);
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: $name <$addr>";
    $subject = str_replace(["\r", "\n"], ' ', $subject);
    return (bool)mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $headers);
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
