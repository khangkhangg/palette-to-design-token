<?php
// Checkout, account and privacy pages rendered to strings (lib/pages.php), plus one include of each thin page file.
require __DIR__ . '/helpers.php';
require __DIR__ . '/pp-stub.php';

const LANGS = ['en', 'zh-CN', 'hi', 'es', 'ar', 'fr', 'bn'];
const FR_PRICE = "1,49\u{a0}\$ US";   // French price, no-break space before $
const NATIVE =['English', '中文', 'हिन्दी', 'Español', 'العربية', 'Français', 'বাংলা'];
// Emoji, dingbats, geometric shapes, arrows and misc symbols (house rule: icons are inline SVG).
const EMOJI_RE = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{25A0}-\x{25FF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}\x{FE0F}]/u';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function checkout(array $query = [], string $accept = ''): string
{
    return palette_render_checkout(['query' => $query, 'accept_language' => $accept]);
}

stub_reset($conn);

// ---- language choice
ok('?lang wins', palette_page_lang(['lang' => 'fr'], 'es-ES,es;q=0.9') === 'fr');
ok('Accept-Language best match', palette_page_lang([], 'de-DE,de;q=0.9,fr-CA;q=0.8,en;q=0.5') === 'fr');
ok('Accept-Language q order', palette_page_lang([], 'en;q=0.2,hi;q=0.9') === 'hi');
ok('zh maps to zh-CN', palette_page_lang([], 'zh-TW,zh;q=0.9') === 'zh-CN' && palette_page_lang([], 'zh-cn') === 'zh-CN');
ok('unknown -> en', palette_page_lang([], 'de-DE,ja;q=0.5') === 'en' && palette_page_lang([], '') === 'en');
ok('bad ?lang ignored', palette_page_lang(['lang' => '<x>'], 'ar') === 'ar' && palette_page_lang(['lang' => ['en']], '') === 'en');
ok('q=0 is refused', palette_page_lang([], 'fr;q=0,es;q=0.1') === 'es');

// ---- security headers (one helper for every page)
$hd = palette_page_headers();
ok('CSP exact', ($hd['Content-Security-Policy'] ?? '') === "default-src 'self'; script-src 'self' https://www.paypal.com https://*.paypal.com https://*.paypalobjects.com; "
    . "frame-src https://*.paypal.com; connect-src 'self' https://*.paypal.com https://*.paypalobjects.com; "
    . "img-src 'self' data: https://*.paypal.com https://*.paypalobjects.com; style-src 'self' 'unsafe-inline'; "
    . "base-uri 'none'; form-action 'self'; object-src 'none'; frame-ancestors 'none'");
ok('X-Frame-Options DENY', ($hd['X-Frame-Options'] ?? '') === 'DENY');
ok('Referrer-Policy', ($hd['Referrer-Policy'] ?? '') === 'strict-origin-when-cross-origin');
ok('html content type', ($hd['Content-Type'] ?? '') === 'text/html; charset=utf-8');

// ---- checkout in each language
$refundEn = 'All sales are final. Because Pro unlocks instantly and costs $1.49, we do not offer refunds after purchase.';
$consentEn = 'I understand purchases are final and not refundable.';
foreach (LANGS as $l) {
    $html = checkout(['lang' => $l]);
    $dir = $l === 'ar' ? 'rtl' : 'ltr';
    ok("checkout $l: html lang + dir", str_contains($html, '<html lang="' . $l . '" dir="' . $dir . '">'));
    ok("checkout $l: refund line", str_contains($html, h(palette_t($l, 'co_refund'))));
    ok("checkout $l: consent checkbox required", preg_match('/<input[^>]*type="checkbox"[^>]*id="consent"[^>]*required/', $html) === 1
        && str_contains($html, h(palette_t($l, 'co_consent'))));
    ok("checkout $l: price", $l === 'fr' ? str_contains($html, FR_PRICE) && !str_contains($html, '$1.49') : str_contains($html, '$1.49'));
    $sw = true;
    foreach (LANGS as $i => $o) {
        if (!str_contains($html, 'href="/palette/checkout?lang=' . rawurlencode($o) . '"') || !str_contains($html, NATIVE[$i])) $sw = false;
    }
    ok("checkout $l: language switcher links", $sw);
    ok("checkout $l: no emoji", preg_match(EMOJI_RE, $html) === 0);
    ok("checkout $l: strings translated", $l === 'en' || !str_contains($html, h(palette_t('en', 'co_h1'))));
    ok("checkout $l: no external fonts", !str_contains($html, 'fonts.googleapis') && !str_contains($html, 'fonts.gstatic'));
}
$en = checkout(['lang' => 'en']);
ok('en refund exact', str_contains($en, h($refundEn)));
ok('en consent exact', str_contains($en, h($consentEn)));
ok('consent label tied to checkbox', str_contains($en, 'for="consent"'));
ok('email field', preg_match('/<input[^>]*type="email"[^>]*id="email"[^>]*required/', $en) === 1);
ok('assets linked', str_contains($en, 'src="/palette/assets/checkout.js') && str_contains($en, 'href="/palette/assets/checkout.css'));
ok('no inline executable script', preg_match_all('/<script(?![^>]*\bsrc=)(?![^>]*type="application\/json")[^>]*>/', $en) === 0);
ok('success view markup', str_contains($en, 'id="done"') && str_contains($en, 'id="account-link"') && str_contains($en, 'id="copy-key"')
    && str_contains($en, h(palette_t('en', 'co_account_link'))));
ok('lost-key resend form', str_contains($en, 'id="resend"') && str_contains($en, h(palette_t('en', 'co_lost_title'))));
ok('privacy link', str_contains($en, 'href="/palette/privacy?lang=en"'));
ok('page data has strings for the script', preg_match('#<script type="application/json" id="palette-data">(.*?)</script>#s', $en, $m) === 1
    && (json_decode($m[1], true)['t']['err_retry'] ?? '') === palette_t('en', 'err_retry')
    && (json_decode($m[1], true)['t']['co_ext_ok'] ?? '') === 'Your extension is now Pro.');

// PayPal ready / paused
ok('paypal container when ready, no paused notice', str_contains($en, 'id="paypal"') && !str_contains($en, 'class="notice"'));
stub_paypal($conn, false);
$p = checkout(['lang' => 'en']);
ok('no paypal container when paused', !str_contains($p, 'id="paypal"'));
ok('paused message', preg_match('#class="notice"[^>]*>.*?Payments are paused#s', $p) === 1);
ok('paused message localised', preg_match('#class="notice"[^>]*>.*?' . preg_quote(h(palette_t('fr', 'err_paused')), '#') . '#s', checkout(['lang' => 'fr'])) === 1);
stub_paypal($conn, true);

// tax
ok('tax none: no selects, no CA note', !str_contains($en, 'id="country"') && !str_contains($en, 'id="region"')
    && !str_contains($en, h(palette_t('en', 'co_tax_note'))));
palette_settings_put($conn, ['tax_mode' => 'ca']);
$t = checkout(['lang' => 'en']);
ok('tax ca: country + state selects', preg_match('/<select[^>]*id="country"/', $t) === 1 && preg_match('/<select[^>]*id="region"/', $t) === 1);
ok('tax ca: country is US + Other only', preg_match('/id="country".*?<\/select>/s', $t, $cm) === 1 && substr_count($cm[0], '<option') === 2
    && str_contains($cm[0], '<option value="US">') && str_contains($cm[0], '<option value="ZZ" selected>'));
ok('tax ca: California listed', str_contains($t, '<option value="CA">California</option>'));
ok('tax ca: note', str_contains($t, '+ California sales tax'));
ok('tax ca: price still $1.49', str_contains($t, '$1.49'));
ok('tax ca: CA total in page data', preg_match('#id="palette-data">(.*?)</script>#s', $t, $m) === 1 && (json_decode($m[1], true)['total_ca'] ?? '') === '$1.60');
palette_settings_put($conn, ['tax_mode' => 'none']);

// escaping and query keeping
$x = checkout(['lang' => 'en', 'ext' => '"><script>alert(1)</script>']);
ok('bad ext not reflected', !str_contains($x, 'alert(1)'));
$e = checkout(['lang' => 'es', 'ext' => str_repeat('a', 32)]);
ok('switcher keeps a valid ext', str_contains($e, 'href="/palette/checkout?lang=fr&amp;ext=' . str_repeat('a', 32) . '"'));
ok('accept-language picks page language', str_contains(checkout([], 'ar,en;q=0.5'), '<html lang="ar" dir="rtl">'));

// thin page file
$_GET = ['lang' => 'bn'];
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr';
ob_start();
include PALETTE_DIR . '/checkout.php';
$out = (string)ob_get_clean();
ok('checkout.php renders', str_contains($out, '<html lang="bn" dir="ltr">') && str_contains($out, h(palette_t('bn', 'co_refund'))));

// ---- account
$k1 = palette_issue_license($conn, 'mine@studio.vn', 'ORD-A1', 'en')['key'];
$k2 = palette_issue_license($conn, 'mine@studio.vn', 'ORD-A2', 'en')['key'];
$k3 = palette_issue_license($conn, 'other@studio.vn', 'ORD-A3', 'en')['key'];
palette_claim($conn, $k1, 7);
palette_claim($conn, $k2, 7);
palette_claim($conn, $k3, 8);
palette_revoke_by_order($conn, 'ORD-A2');

$out = palette_render_account(['query' => ['lang' => 'en'], 'accept_language' => '', 'session' => []]);
ok('account logged out: login link', str_contains($out, 'href="/companies/?auth=login&amp;redirect=/palette/account"'));
ok('account logged out: no keys, no claim form', !str_contains($out, $k1) && !str_contains($out, 'id="claim"'));
$_SESSION = [];
$out = palette_render_account(['query' => ['lang' => 'en', 'key' => $k3], 'accept_language' => '', 'session' => []]);
ok('account logged out with ?key: plain login redirect, key never in the URL', str_contains($out, 'href="/companies/?auth=login&amp;redirect=/palette/account"')
    && !str_contains($out, 'redirect=/palette/account%3F') && !str_contains($out, 'auth=login&amp;redirect=/palette/account?'));
ok('account logged out with ?key: key kept in the session', ($_SESSION['palette_pending_key'] ?? null) === $k3);
$out = palette_render_account(['query' => ['lang' => 'en'], 'accept_language' => '', 'session' => ['user_id' => 7, 'palette_pending_key' => $k3]]);
ok('after login: claim prefilled from the session', preg_match('/<input[^>]*name="key"[^>]*value="' . preg_quote($k3, '/') . '"/', $out) === 1);
ok('after login: pending key cleared from the session', !array_key_exists('palette_pending_key', $_SESSION));
$out = palette_render_account(['query' => ['lang' => 'en'], 'accept_language' => '', 'session' => ['user_id' => 7, 'palette_pending_key' => '"><b>x']]);
ok('session pending key must be a well-formed key', !str_contains($out, '<b>x') && preg_match('/<input[^>]*name="key"[^>]*value=""/', $out) === 1);
$_SESSION = [];
$out = palette_render_account(['query' => ['lang' => 'en', 'key' => '"><b>x'], 'accept_language' => '', 'session' => []]);
ok('account logged out: bad key dropped', str_contains($out, 'redirect=/palette/account"') && !str_contains($out, '<b>x'));

$out = palette_render_account(['query' => ['lang' => 'en'], 'accept_language' => '', 'session' => ['user_id' => 7]]);
ok('account logged in: own keys in full', str_contains($out, $k1) && str_contains($out, $k2));
ok('account logged in: not others\' keys', !str_contains($out, $k3));
ok('account logged in: copy buttons', substr_count($out, 'data-copy="') === 2);
ok('account logged in: copy status region', preg_match('/<p[^>]*id="copy-status"[^>]*role="status"|<p[^>]*role="status"[^>]*id="copy-status"/', $out) === 1);
ok('account logged in: revoked marked', str_contains($out, h(palette_t('en', 'acct_revoked'))));
ok('account logged in: claim form', str_contains($out, 'id="claim"') && preg_match('/<input[^>]*name="key"/', $out) === 1);
ok('account logged in: no login link', !str_contains($out, 'auth=login'));
$out = palette_render_account(['query' => ['lang' => 'en', 'key' => $k3], 'accept_language' => '', 'session' => ['user_id' => '7']]);
ok('account claim prefilled from ?key', preg_match('/<input[^>]*name="key"[^>]*value="' . preg_quote($k3, '/') . '"/', $out) === 1);
$out = palette_render_account(['query' => ['lang' => 'en', 'key' => '"><b>x'], 'accept_language' => '', 'session' => ['user_id' => 7]]);
ok('account prefill escaped', !str_contains($out, '<b>x'));
$out = palette_render_account(['query' => ['lang' => 'en'], 'accept_language' => '', 'session' => ['user_id' => 99]]);
ok('account empty state', str_contains($out, h(palette_t('en', 'acct_none'))));
foreach (LANGS as $l) {
    $a = palette_render_account(['query' => ['lang' => $l], 'accept_language' => '', 'session' => ['user_id' => 7]]);
    ok("account $l: lang/dir, no emoji", str_contains($a, '<html lang="' . $l . '" dir="' . ($l === 'ar' ? 'rtl' : 'ltr') . '">')
        && preg_match(EMOJI_RE, $a) === 0 && str_contains($a, 'href="/palette/account?lang=fr"'));
}
$_GET = ['lang' => 'en'];
$_SESSION = [];
ob_start();
include PALETTE_DIR . '/account.php';
$out = (string)ob_get_clean();
ok('account.php renders', str_contains($out, 'auth=login'));

// ---- privacy
$p = palette_render_privacy(['query' => ['lang' => 'en'], 'accept_language' => '']);
ok('privacy: only the clicked tab', str_contains($p, 'Only the tab you click it on'));
ok('privacy: scans stored in your browser', str_contains($p, 'Your scans and settings are stored in your browser'));
ok('privacy: only the license key to vietnamcos.com', str_contains($p, 'Only your license key, to vietnamcos.com, to check'));
ok('privacy: PayPal, Resend, no tracking', str_contains($p, 'PayPal') && str_contains($p, 'Resend') && str_contains($p, h(palette_t('en', 'priv_track'))));
ok('privacy: server keeps your IP briefly, Cloudflare, session cookie', str_contains($p, h(palette_t('en', 'priv_server')))
    && str_contains(palette_t('en', 'priv_server'), 'IP address') && str_contains(palette_t('en', 'priv_server'), 'Cloudflare')
    && str_contains(palette_t('en', 'priv_server'), 'session cookie'));
ok('privacy: contact + date', str_contains($p, 'href="mailto:support@vietnamcos.com"') && str_contains($p, '2026-10-17'));
foreach (LANGS as $l) {
    $p = palette_render_privacy(['query' => ['lang' => $l], 'accept_language' => '']);
    $all = true;
    foreach (['priv_reads', 'priv_stores', 'priv_sends', 'priv_server_h', 'priv_server', 'priv_pay', 'priv_email', 'priv_track'] as $k) if (!str_contains($p, h(palette_t($l, $k)))) $all = false;
    ok("privacy $l: every section, lang/dir, no emoji", $all && str_contains($p, '<html lang="' . $l . '" dir="' . ($l === 'ar' ? 'rtl' : 'ltr') . '">')
        && preg_match(EMOJI_RE, $p) === 0 && str_contains($p, 'vietnamcos.com'));
}
$_GET = ['lang' => 'ar'];
ob_start();
include PALETTE_DIR . '/privacy.php';
$out = (string)ob_get_clean();
ok('privacy.php renders', str_contains($out, 'dir="rtl"'));

// i18n: page strings exist in every language (parity is in license-test)
$missing = [];
foreach (LANGS as $l) foreach (['co_h1', 'co_refund', 'co_consent', 'acct_title', 'priv_title', 'priv_updated'] as $k) {
    if (!array_key_exists($k, palette_strings($l))) $missing[] = "$l:$k";
}
ok('page keys in every language', $missing === []);
ok('co_err_confirm en exact', palette_t('en', 'co_err_confirm') === "We couldn't confirm your payment. If you were charged, your key will arrive by email — or use 'Lost your key?' below.");
ok('co_err_confirm passed to the script', str_contains($en, 'co_err_confirm'));
$missing = [];
foreach (LANGS as $l) {
    $v = palette_strings($l)['co_key_emailed'] ?? '';
    if (!str_contains($v, '{email}') || ($l !== 'en' && $v === palette_t('en', 'co_key_emailed'))) $missing[] = $l;
}
ok('co_key_emailed in every language with {email}', $missing === []);
ok('co_key_emailed passed to the script', preg_match('#id="palette-data">(.*?)</script>#s', $en, $m) === 1
    && (json_decode($m[1], true)['t']['co_key_emailed'] ?? '') === palette_t('en', 'co_key_emailed'));
$fr = '';
foreach (palette_strings('fr') as $v) $fr .= $v . ' | ';
ok('fr strings write the price as 1,49 $ US only', str_contains($fr, FR_PRICE) && preg_match('/1[,.]49/', str_replace(FR_PRICE, '', $fr)) === 0);
$frPage = checkout(['lang' => 'fr']);
ok('fr summary total is 1,49 $ US (no extra USD)', str_contains($frPage, '<span id="total">' . FR_PRICE . '</span></span>')
    && preg_match('#id="palette-data">(.*?)</script>#s', $frPage, $m) === 1 && (json_decode($m[1], true)['total'] ?? '') === FR_PRICE);
ok('co_consent en exact', palette_t('en', 'co_consent') === $consentEn && palette_t('en', 'co_refund') === $refundEn);

// ---- product page: download, what it does, free vs pro, install, entering a key
const PP_KEYS = ['pp_download', 'pp_get_pro', 'pp_works_in', 'pp_what_h', 'pp_feat_colours_h', 'pp_feat_colours', 'pp_feat_system_h',
    'pp_feat_system', 'pp_feat_contrast_h', 'pp_feat_contrast', 'pp_feat_export_h', 'pp_feat_export', 'pp_plans_h', 'pp_plan_feature',
    'pp_plan_free', 'pp_plan_pro', 'pp_yes', 'pp_no', 'pp_row_scan', 'pp_row_view', 'pp_row_copy', 'pp_row_contrast', 'pp_row_saved',
    'pp_saved_free', 'pp_saved_pro', 'pp_row_exports', 'pp_row_fixes', 'pp_install_h', 'pp_install_1', 'pp_install_2', 'pp_install_3',
    'pp_install_4', 'pp_install_5', 'pp_install_note', 'pp_key_h', 'pp_key_1', 'pp_key_2', 'pp_key_email', 'pp_key_lost'];
$missing = [];
foreach (LANGS as $l) foreach (PP_KEYS as $k) {
    $v = palette_strings($l)[$k] ?? '';
    if (trim($v) === '' || preg_match(EMOJI_RE, $v) || ($l !== 'en' && $v === palette_t('en', $k) && !in_array($k, ['pp_plan_pro'], true))) $missing[] = "$l:$k";
}
ok('product page strings in all 7 languages, translated, no emoji: ' . implode(', ', $missing), $missing === []);
ok('PALETTE_EXT_VERSION matches the extension manifest',
    PALETTE_EXT_VERSION === json_decode((string)file_get_contents(PALETTE_DIR . '/../extension/public/manifest.json'), true)['version']);
$en = checkout(['lang' => 'en']);
$pos = fn(string $needle) => strpos($en, $needle);
ok('download link: latest zip with download attribute',
    preg_match('#<a[^>]*href="/palette/download/site-to-design-token-latest\.zip"[^>]*\bdownload\b[^>]*>.*?Download the extension \(free\)#s', $en) === 1
    || preg_match('#<a[^>]*\bdownload\b[^>]*href="/palette/download/site-to-design-token-latest\.zip"[^>]*>.*?Download the extension \(free\)#s', $en) === 1);
ok('Get Pro anchors to the buy card', preg_match('#<a[^>]*href="\#buy"[^>]*>.*?Get Pro — \$1\.49#s', $en) === 1 && str_contains($en, 'id="buy"'));
ok('works-in line with version', str_contains($en, 'Works in Chrome, Edge and Brave. Version <bdi dir="ltr">' . PALETTE_EXT_VERSION . '</bdi>.'));
ok('sections in order: hero, what, plans, install, key, then the buy card',
    $pos('id="hero-actions"') !== false && $pos('id="what"') > $pos('id="hero-actions"') && $pos('id="plans"') > $pos('id="what"')
    && $pos('id="install"') > $pos('id="plans"') && $pos('id="have-key"') > $pos('id="install"') && $pos('id="buy"') > $pos('id="have-key"'));
ok('four features with inline SVG icons', preg_match('#id="what".*?</section>#s', $en, $wm) === 1 && substr_count($wm[0], '<li class="feature">') === 4
    && substr_count($wm[0], '<svg') >= 4 && str_contains($wm[0], h(palette_t('en', 'pp_feat_export'))));
ok('free vs pro table', preg_match('#id="plans".*?</section>#s', $en, $pm) === 1 && str_contains($pm[0], '<table')
    && str_contains($pm[0], 'Pro, $1.49 once') && substr_count($pm[0], '<tr') === 8 && str_contains($pm[0], h(palette_t('en', 'pp_saved_free'))));
ok('install steps: 5, chrome://extensions as copyable code, not a link', preg_match('#id="install".*?</section>#s', $en, $im) === 1
    && substr_count($im[0], '<li') === 5 && str_contains($im[0], '<code dir="ltr">chrome://extensions</code>')
    && str_contains($im[0], 'data-copy="chrome://extensions"') && !str_contains($im[0], 'href="chrome:')
    && str_contains($im[0], h(palette_t('en', 'pp_install_note'))));
ok('already bought: how to enter the key, link to the lost-key form', preg_match('#id="have-key".*?</section>#s', $en, $km) === 1
    && str_contains($km[0], 'Enter license key') && str_contains($km[0], 'href="#resend"') && str_contains($km[0], h(palette_t('en', 'pp_key_email'))));
$fr = checkout(['lang' => 'fr']);
ok('fr: Get Pro and the plan header use 1,49 $ US', str_contains($fr, h(palette_t('fr', 'pp_get_pro', ['price' => FR_PRICE])))
    && str_contains($fr, h(palette_t('fr', 'pp_plan_pro', ['price' => FR_PRICE]))));
foreach (LANGS as $l) {
    $html = checkout(['lang' => $l]);
    $all = true;
    foreach (['pp_download', 'pp_what_h', 'pp_plans_h', 'pp_install_h', 'pp_key_h', 'pp_install_note'] as $k) if (!str_contains($html, h(palette_t($l, $k)))) $all = false;
    ok("product page $l: every section rendered", $all && str_contains($html, '/palette/download/site-to-design-token-latest.zip'));
}
// Opera has no chrome.sidePanel: no page or string may claim it.
$opera = [];
foreach (LANGS as $l) foreach (palette_strings($l) as $k => $v) if (stripos($v, 'opera') !== false) $opera[] = "$l:$k";
ok('no string claims Opera support: ' . implode(', ', $opera), $opera === []);
