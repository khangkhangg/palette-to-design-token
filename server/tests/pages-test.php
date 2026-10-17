<?php
// Checkout, account and privacy pages rendered to strings (lib/pages.php), plus one include of each thin page file.
require __DIR__ . '/helpers.php';
require __DIR__ . '/pp-stub.php';
// CLI has no session; start one (output buffered) so companies' CSRF helpers use $_SESSION as-is.
ob_start();
session_start();

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
    && str_contains($pm[0], 'Pro, $1.49 once') && substr_count($pm[0], '<tr') === 9 && str_contains($pm[0], h(palette_t('en', 'pp_saved_free'))));
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
foreach (LANGS as $l) foreach (palette_strings($l) as $k => $v) if (preg_match('/\bOpera\b/i', $v)) $opera[] = "$l:$k";
ok('no string claims Opera support: ' . implode(', ', $opera), $opera === []);

// ---- shared footer: privacy, terms, keys, support on every page
foreach (['checkout' => checkout(['lang' => 'es']),
          'account' => palette_render_account(['query' => ['lang' => 'es'], 'accept_language' => '', 'session' => []]),
          'privacy' => palette_render_privacy(['query' => ['lang' => 'es'], 'accept_language' => '']),
          'terms' => palette_render_terms(['query' => ['lang' => 'es'], 'accept_language' => '']),
          'landing' => palette_render_landing(['query' => ['lang' => 'es'], 'accept_language' => ''])] as $name => $html) {
    ok("$name footer: privacy, terms, keys, support", preg_match('#<footer.*</footer>#s', $html, $fm) === 1
        && str_contains($fm[0], 'href="/palette/privacy?lang=es"') && str_contains($fm[0], 'href="/palette/terms?lang=es"')
        && str_contains($fm[0], 'href="/palette/account?lang=es"') && str_contains($fm[0], 'href="mailto:support@vietnamcos.com"')
        && str_contains($fm[0], h(palette_t('es', 'page_terms'))));
    ok("$name: brand links home", str_contains($html, 'class="brand" href="/palette/?lang=es"'));
}

// ---- landing (/palette/)
const LP_KEYS = ['lp_title', 'lp_h1', 'lp_lead', 'lp_shots_h', 'lp_shot_1', 'lp_shot_2', 'lp_shot_3', 'lp_shot_4', 'lp_shot_5',
    'lp_how_h', 'lp_how_1', 'lp_how_2', 'lp_how_3', 'lp_ai_h', 'lp_ai_text', 'lp_ai_where', 'lp_faq_h', 'lp_faq_free_q', 'lp_faq_free_a',
    'lp_faq_pro_q', 'lp_faq_pro_a', 'lp_faq_key_q', 'lp_faq_data_q', 'lp_faq_data_a', 'lp_faq_data_link', 'lp_faq_refund_q', 'page_terms',
    'terms_title', 'terms_intro', 'terms_buy_h', 'terms_buy', 'terms_pay_h', 'terms_pay', 'terms_final_h', 'terms_revoke_h', 'terms_revoke',
    'terms_use_h', 'terms_use', 'terms_asis_h', 'terms_asis', 'terms_liability_h', 'terms_liability', 'terms_changes_h', 'terms_changes',
    'terms_contact_h', 'terms_contact',
    'priv_ai_h', 'priv_ai', 'terms_sub_h', 'terms_sub', 'terms_ai_h', 'terms_ai', 'pp_plan_ai', 'pp_row_ai', 'pp_ai_n',
    'lp_ins_h', 'lp_ins_text', 'lp_ins_b1', 'lp_ins_b2', 'lp_ins_b3', 'lp_shot_6', 'lp_faq_ai_q', 'lp_faq_ai_a', 'lp_faq_cancel_q', 'lp_faq_cancel_a'];
$missing = [];
foreach (LANGS as $l) foreach (LP_KEYS as $k) {
    $v = palette_strings($l)[$k] ?? '';
    if (trim($v) === '' || preg_match(EMOJI_RE, $v) || ($l !== 'en' && $v === palette_t('en', $k))) $missing[] = "$l:$k";
}
ok('landing + terms strings in all 7 languages, translated, no emoji: ' . implode(', ', array_slice($missing, 0, 12)), $missing === []);
function landing(array $query = [], string $accept = ''): string
{
    return palette_render_landing(['query' => $query, 'accept_language' => $accept]);
}
$lp = landing(['lang' => 'en']);
$at = fn(string $needle) => strpos($lp, $needle);
ok('landing: download link with download attribute', preg_match('#<a[^>]*href="/palette/download/site-to-design-token-latest\.zip"[^>]*\bdownload\b#', $lp) === 1);
ok('landing: Get Pro goes to the checkout buy card', preg_match('~<a[^>]*href="/palette/checkout\?lang=en#buy"[^>]*>Get Pro — \$1\.49</a>~', $lp) === 1);
ok('landing: version and browsers', str_contains($lp, 'Works in Chrome, Edge and Brave. Version <bdi dir="ltr">' . PALETTE_EXT_VERSION . '</bdi>.'));
ok('landing: sections in order', $at('id="hero-actions"') < $at('id="shots"') && $at('id="shots"') < $at('id="how"') && $at('id="how"') < $at('id="what"')
    && $at('id="what"') < $at('id="plans"') && $at('id="plans"') < $at('id="ai"') && $at('id="ai"') < $at('id="install"') && $at('id="install"') < $at('id="faq"'));
ok('landing: five screenshots with size, alt and lazy loading after the first',
    preg_match_all('#<img src="/palette/assets/screenshots/([1-5]-[a-z-]+\.png)" width="1280" height="800" alt="([^"]+)"( loading="lazy")?#', $lp, $im) === 5
    && $im[3][0] === '' && count(array_filter(array_slice($im[3], 1))) === 4 && $im[2][0] === h(palette_t('en', 'lp_shot_1')));
$files = true;
foreach ($im[1] as $f) if (!is_file(PALETTE_DIR . '/assets/screenshots/' . $f)) $files = false;
ok('landing: screenshot files exist', $files && count($im[1]) === 5);
ok('landing: how it works has 3 steps', preg_match('#id="how".*?</section>#s', $lp, $hm) === 1 && substr_count($hm[0], '<li') === 3);
ok('landing: reuses features, plans and install from checkout', preg_match('#id="what".*?</section>#s', $lp, $a) === 1
    && preg_match('#id="what".*?</section>#s', checkout(['lang' => 'en']), $b) === 1 && $a[0] === $b[0]
    && str_contains($lp, '<table class="plans">') && str_contains($lp, 'data-copy="chrome://extensions"'));
ok('landing: AI block explains DESIGN.md', preg_match('#id="ai".*?</section>#s', $lp, $am) === 1 && str_contains($am[0], 'DESIGN.md')
    && str_contains($am[0], h(palette_t('en', 'lp_ai_text'))));
ok('landing: FAQ with 7 questions, privacy link and the refund line', preg_match('#id="faq".*?</section>#s', $lp, $qm) === 1
    && substr_count($qm[0], '<details') === 7 && str_contains($qm[0], 'href="/palette/privacy?lang=en"') && str_contains($qm[0], h($refundEn))
    && str_contains($qm[0], h(palette_t('en', 'pp_key_1'))));
ok('landing: no inline script', preg_match_all('/<script(?![^>]*\bsrc=)(?![^>]*type="application\/json")[^>]*>/', $lp) === 0);
ok('landing: no Opera', preg_match('/\bOpera\b/i', $lp) === 0);
foreach (LANGS as $l) {
    $html = landing(['lang' => $l]);
    $alts = true;
    for ($i = 1; $i <= 5; $i++) if (!str_contains($html, 'alt="' . h(palette_t($l, "lp_shot_$i")) . '"')) $alts = false;
    ok("landing $l: lang/dir, alts, no emoji, switcher, title", str_contains($html, '<html lang="' . $l . '" dir="' . ($l === 'ar' ? 'rtl' : 'ltr') . '">')
        && $alts && preg_match(EMOJI_RE, $html) === 0 && str_contains($html, 'href="/palette/?lang=fr"')
        && str_contains($html, '<title>' . h(palette_t($l, 'lp_title')) . '</title>'));
}
ok('landing fr: price as 1,49 $ US', str_contains(landing(['lang' => 'fr']), h(palette_t('fr', 'pp_get_pro', ['price' => FR_PRICE]))));
ok('landing: Accept-Language', str_contains(landing([], 'hi-IN,hi;q=0.9'), '<html lang="hi"'));
$_GET = ['lang' => 'zh-CN'];
ob_start();
include PALETTE_DIR . '/index.php';
$out = (string)ob_get_clean();
ok('index.php renders the landing', str_contains($out, '<html lang="zh-CN"') && str_contains($out, 'id="shots"'));

// ---- terms
$tm = palette_render_terms(['query' => ['lang' => 'en'], 'accept_language' => '']);
ok('terms: personal non-transferable license', str_contains($tm, 'personal, non-transferable license'));
ok('terms: PayPal, one-time, price', str_contains($tm, 'PayPal') && str_contains($tm, h(palette_t('en', 'terms_pay', ['price' => '$1.49']))));
ok('terms: exact refund line', str_contains($tm, h($refundEn)));
ok('terms: revocation, acceptable use, as is, liability', str_contains($tm, 'chargeback') && str_contains($tm, 'resell')
    && str_contains($tm, h('"as is"')) && str_contains($tm, 'limited to the amount you paid'));
ok('terms: operated by vietnamcos.com, updated date, contact', str_contains($tm, 'Operated by vietnamcos.com') && str_contains($tm, '2026-10-17')
    && str_contains($tm, 'href="mailto:support@vietnamcos.com"'));
ok('terms: no invented legal entity or governing law', !preg_match('/governing law|LLC|Ltd|Inc\.|registered/i', $tm));
foreach (LANGS as $l) {
    $p = palette_render_terms(['query' => ['lang' => $l], 'accept_language' => '']);
    $all = true;
    foreach (['terms_buy', 'terms_revoke', 'terms_use', 'terms_asis', 'terms_liability', 'terms_changes', 'co_refund'] as $k) if (!str_contains($p, h(palette_t($l, $k)))) $all = false;
    ok("terms $l: every section, lang/dir, no emoji", $all && str_contains($p, '<html lang="' . $l . '" dir="' . ($l === 'ar' ? 'rtl' : 'ltr') . '">')
        && preg_match(EMOJI_RE, $p) === 0 && str_contains($p, 'vietnamcos.com'));
}
ok('fr terms price as 1,49 $ US', str_contains(palette_render_terms(['query' => ['lang' => 'fr'], 'accept_language' => '']), h(palette_t('fr', 'terms_pay', ['price' => FR_PRICE]))));
$_GET = ['lang' => 'ar'];
ob_start();
include PALETTE_DIR . '/terms.php';
$out = (string)ob_get_clean();
ok('terms.php renders', str_contains($out, 'dir="rtl"') && str_contains($out, h(palette_t('ar', 'terms_title'))));

// ---- plans table scroll region (made focusable by checkout.js only when it overflows)
ok('plans wrapper carries its scroll label', str_contains($lp, 'class="plans-wrap" data-scroll-label="' . h(palette_t('en', 'pp_plans_h')) . '"'));

// ---- review: honest data answer, keyboard-reachable code sample, export is Pro in the lead
$missing = [];
foreach (LANGS as $l) {
    $a = palette_t($l, 'lp_faq_data_a');
    if (!str_contains($a, 'vietnamcos.com') || preg_match('/^(None|无|कोई नहीं|Ninguno|لا شيء|Aucune|কোনোটিই না)/u', $a)) $missing[] = $l;
    if (!str_contains(palette_t($l, 'lp_lead'), 'Pro')) $missing[] = "$l:lead";
}
ok('data FAQ names vietnamcos.com and does not claim "none"; lead says export is Pro: ' . implode(', ', $missing), $missing === []);
ok('en data FAQ mentions email and order', str_contains(palette_t('en', 'lp_faq_data_a'), 'we keep your email and order'));
ok('DESIGN.md sample is a labelled scroll region candidate', str_contains($lp, '<pre class="md-sample" dir="ltr" lang="en" data-scroll-label="' . h(palette_t('en', 'lp_ai_h')) . '">'));

// ---- Task 8: plan chooser
// palette_settings_put replaces everything with defaults + the given keys: merge over the stored settings.
function cfg(mysqli $c, array $a): void
{
    palette_settings_put($c, $a + palette_settings($c));
}
// Task 8 - on the checkout page (mockup J)
$AI_KEYS = ['co_plan_h', 'co_plan_best', 'co_plan_once', 'co_plan_month', 'co_plan_soon', 'co_pro_i1', 'co_pro_i2', 'co_pro_i3', 'co_ai_i1', 'co_ai_i2',
    'co_ai_i3', 'co_ai_i4', 'co_ai_i5', 'co_item_ai', 'co_refund_ai', 'co_note_ai', 'co_sub_pending', 'co_success_title_ai', 'pp_get_ai', 'acct_plan',
    'acct_one_time', 'acct_renews', 'acct_st_active', 'acct_st_cancelled', 'acct_st_suspended', 'acct_st_expired', 'acct_cancel', 'acct_cancel_ask',
    'acct_cancel_yes', 'acct_cancel_keep', 'acct_cancel_by_h', 'acct_cancel_by_text', 'acct_sub_email'];
$missing = [];
foreach (LANGS as $l) foreach ($AI_KEYS as $k) {
    $v = palette_strings($l)[$k] ?? '';
    if (trim($v) === '' || preg_match(EMOJI_RE, $v) || ($l !== 'en' && $v === palette_t('en', $k) && !in_array($k, ['acct_plan', 'co_plan_once'], true))) $missing[] = "$l:$k";
}
ok('plan + subscription strings in all 7 languages, translated, no emoji: ' . implode(', ', array_slice($missing, 0, 10)), $missing === []);
$cancelSentence = 'Cancel any time; Pro and AI stay on until the end of the month you paid for.';
ok('co_note_ai carries the cancel sentence', str_contains(palette_t('en', 'co_note_ai'), $cancelSentence));
$frAi = '';
foreach ($AI_KEYS as $k) $frAi .= palette_t('fr', $k) . ' | ';
ok('new fr strings contain no literal price', preg_match('/\d[.,]\d\d/', $frAi) === 0);

function checkoutData(string $html): array
{
    preg_match('#id="palette-data">(.*?)</script>#s', $html, $m);
    return json_decode($m[1] ?? '{}', true) ?: [];
}

// AI not set up: the Pro + AI card says coming soon and cannot be chosen; ?plan=ai falls back to Pro
cfg($conn, ['ai_paypal_plan_id' => '', 'ai_paypal_plan_mode' => '', 'ai_price_usd' => '3.99']);
$off = checkout(['lang' => 'en', 'plan' => 'ai']);
ok('ai off: two plan cards in a radiogroup', str_contains($off, 'role="radiogroup"') && substr_count($off, 'role="radio"') === 2);
ok('ai off: Pro card selected', preg_match('/<div[^>]*role="radio"[^>]*data-plan="pro"[^>]*aria-checked="true"/', $off) === 1);
ok('ai off: Pro + AI card is coming soon and disabled', preg_match('/<div[^>]*role="radio"[^>]*data-plan="ai"[^>]*aria-disabled="true"/', $off) === 1
    && str_contains($off, h(palette_t('en', 'co_plan_soon'))));
ok('ai off: page data says so', (checkoutData($off)['ai_enabled'] ?? null) === false && (checkoutData($off)['plan'] ?? null) === 'pro');

// AI set up for this PayPal mode (stub_paypal uses sandbox)
cfg($conn, ['ai_paypal_plan_id' => 'P-TESTPLAN1', 'ai_paypal_plan_mode' => 'sandbox', 'ai_paypal_plan_price' => '3.99']);
$pro = checkout(['lang' => 'en']);
$ai = checkout(['lang' => 'en', 'plan' => 'ai']);
ok('default plan is Pro', preg_match('/<div[^>]*role="radio"[^>]*data-plan="pro"[^>]*aria-checked="true"/', $pro) === 1
    && preg_match('/<div[^>]*role="radio"[^>]*data-plan="ai"[^>]*aria-checked="false"/', $pro) === 1 && (checkoutData($pro)['plan'] ?? null) === 'pro');
ok('?plan=ai selects Pro + AI', preg_match('/<div[^>]*role="radio"[^>]*data-plan="ai"[^>]*aria-checked="true"/', $ai) === 1
    && preg_match('/<div[^>]*role="radio"[^>]*data-plan="pro"[^>]*aria-checked="false"/', $ai) === 1 && (checkoutData($ai)['plan'] ?? null) === 'ai');
ok('?plan=junk is Pro', (checkoutData(checkout(['lang' => 'en', 'plan' => '"><b>']))['plan'] ?? null) === 'pro');
ok('only the selected card is in the tab order', preg_match('/data-plan="ai"[^>]*tabindex="0"/', $ai) === 1 && preg_match('/data-plan="pro"[^>]*tabindex="-1"/', $ai) === 1);
ok('cards: prices from settings, monthly wording', str_contains($ai, '$3.99') && str_contains($ai, h(palette_t('en', 'co_plan_month'))) && str_contains($ai, '$1.49')
    && str_contains($ai, h(palette_t('en', 'co_plan_once'))));
ok('cards: feature lists with the analyses limit', str_contains($ai, h(palette_t('en', 'co_ai_i1'))) && str_contains($ai, '100 AI analyses a month')
    && str_contains($ai, h(palette_t('en', 'co_pro_i1'))) && str_contains($ai, h(palette_t('en', 'co_plan_best'))));
ok('ai plan: summary, refund line and cancel sentence', str_contains($ai, h(palette_t('en', 'co_item_ai'))) && str_contains($ai, h(palette_t('en', 'co_refund_ai')))
    && str_contains($ai, h($cancelSentence)) && !str_contains($ai, '<p class="refund" id="refund">' . h($refundEn)));
ok('ai plan: consent checkbox still required', preg_match('/<input[^>]*type="checkbox"[^>]*id="consent"[^>]*required/', $ai) === 1);
ok('ai plan: no pro-only card note', !str_contains($ai, '<p class="hint hint-center" id="pay-note">' . h(palette_t('en', 'co_card_note'))));
ok('pro plan: unchanged refund line and card note', str_contains($pro, '<p class="refund" id="refund">' . h($refundEn)) && str_contains($pro, h(palette_t('en', 'co_card_note'))));
$d = checkoutData($ai);
ok('page data: both totals and the strings the script swaps', ($d['total'] ?? '') === '$1.49' && ($d['total_ai'] ?? '') === '$3.99' && ($d['ai_enabled'] ?? null) === true
    && ($d['t']['co_sub_pending'] ?? '') === palette_t('en', 'co_sub_pending') && ($d['t']['co_note_ai'] ?? '') === palette_t('en', 'co_note_ai')
    && ($d['t']['co_success_title_ai'] ?? '') === palette_t('en', 'co_success_title_ai'));
cfg($conn, ['ai_price_usd' => '5.49', 'ai_paypal_plan_price' => '5.49']);
ok('ai price follows the plan setup made for it', str_contains(checkout(['lang' => 'en', 'plan' => 'ai']), '$5.49') && (checkoutData(checkout(['lang' => 'en']))['total_ai'] ?? '') === '$5.49');
cfg($conn, ['ai_price_usd' => '6.49']);
$changed = checkout(['lang' => 'en', 'plan' => 'ai']);
ok('A18: price changed before setup ran -> Pro + AI is Coming soon, the PayPal plan price is shown, never the new one',
    (checkoutData($changed)['ai_enabled'] ?? null) === false && preg_match('/<div[^>]*role="radio"[^>]*data-plan="ai"[^>]*aria-disabled="true"/', $changed) === 1
    && str_contains($changed, h(palette_t('en', 'co_plan_soon'))) && !str_contains($changed, '$6.49') && (checkoutData($changed)['total_ai'] ?? '') === '$5.49');
cfg($conn, ['ai_price_usd' => '3.99', 'ai_paypal_plan_price' => '3.99']);
ok('language links keep the plan', str_contains($ai, 'href="/palette/checkout?lang=fr&amp;plan=ai"') && !str_contains($pro, 'plan=ai') && !str_contains($pro, ';plan='));
ok('csp unchanged by the plan chooser', palette_page_headers()['Content-Security-Policy'] === "default-src 'self'; script-src 'self' https://www.paypal.com https://*.paypal.com https://*.paypalobjects.com; "
    . "frame-src https://*.paypal.com; connect-src 'self' https://*.paypal.com https://*.paypalobjects.com; "
    . "img-src 'self' data: https://*.paypal.com https://*.paypalobjects.com; style-src 'self' 'unsafe-inline'; "
    . "base-uri 'none'; form-action 'self'; object-src 'none'; frame-ancestors 'none'");
foreach (LANGS as $l) {
    $html = checkout(['lang' => $l, 'plan' => 'ai']);
    ok("checkout $l plan=ai: strings, price, dir, no emoji", str_contains($html, h(palette_t($l, 'co_refund_ai'))) && str_contains($html, h(palette_t($l, 'co_note_ai')))
        && str_contains($html, h(palette_t($l, 'co_plan_h'))) && str_contains($html, h(palette_t($l, 'co_ai_i2', ['n' => 100])))
        && str_contains($html, $l === 'fr' ? "3,99\u{a0}\$ US" : '$3.99') && str_contains($html, '<html lang="' . $l . '" dir="' . ($l === 'ar' ? 'rtl' : 'ltr') . '">')
        && preg_match(EMOJI_RE, $html) === 0 && preg_match('/<script(?![^>]*\bsrc=)(?![^>]*type="application\/json")[^>]*>/', $html) === 0);
}
$lpAi = landing(['lang' => 'en']);
ok('landing: Get Pro + AI links to plan=ai when AI is on', preg_match('~<a[^>]*href="/palette/checkout\?lang=en&amp;plan=ai\#buy"[^>]*>Get Pro \+ AI — \$3\.99/month</a>~', $lpAi) === 1);
cfg($conn, ['ai_paypal_plan_id' => '']);
ok('landing: no Pro + AI link while AI is off', !str_contains(landing(['lang' => 'en']), 'plan=ai'));
cfg($conn, ['ai_paypal_plan_id' => 'P-TESTPLAN1']);

// ---- Task 8: account subscription panel
$_SESSION = [];
$mk = function (string $email, string $order, string $status, string $paidThrough) use ($conn): string {
    $k = palette_issue_license($conn, $email, $order, 'en', ['subscription_id' => 'I-' . $order, 'paid_through' => $paidThrough, 'period_start' => date('Y-m-d H:i:s', time() - 3600)]);
    $st = $conn->prepare('UPDATE palette_licenses SET sub_status = ?, user_id = 21 WHERE id = ?');
    $st->bind_param('si', $status, $k['id']);
    $st->execute();
    return $k['key'];
};
$future = date('Y-m-d H:i:s', time() + 20 * 86400);
$futureDay = substr($future, 0, 10);
$kAct = $mk('s1@studio.vn', 'SUBACT1', 'active', $future);
$kCan = $mk('s2@studio.vn', 'SUBCAN1', 'cancelled', $future);
$kSus = $mk('s3@studio.vn', 'SUBSUS1', 'suspended', $future);
$kExp = $mk('s4@studio.vn', 'SUBEXP1', 'active', date('Y-m-d H:i:s', time() - 86400));
$kPro = palette_issue_license($conn, 's5@studio.vn', 'SUBPRO1', 'en')['key'];
palette_claim($conn, $kPro, 21);
$acct = fn(string $l = 'en', int $uid = 21) => palette_render_account(['query' => ['lang' => $l], 'accept_language' => '', 'session' => ['user_id' => $uid]]);
$out = $acct();
$row = function (string $html, string $key): string {
    preg_match('#<li class="keyrow[^"]*"[^>]*data-key="' . preg_quote($key, '#') . '".*?</li>#s', $html, $m);
    return $m[0] ?? '';
};
$rAct = $row($out, $kAct);
ok('account ai key: plan, status and renewal date', str_contains($rAct, 'Pro + AI') && str_contains($rAct, h(palette_t('en', 'acct_st_active')))
    && str_contains($rAct, palette_t_html('en', 'acct_renews', ['date' => palette_ltr($futureDay)])));
ok('account ai key: Cancel button and an in-page confirm step', str_contains($rAct, 'data-cancel-open') && str_contains($rAct, h(palette_t('en', 'acct_cancel')))
    && preg_match('/<div[^>]*class="cancel-confirm"[^>]*hidden/', $rAct) === 1 && str_contains($rAct, palette_t_html('en', 'acct_cancel_ask', ['date' => palette_ltr($futureDay)]))
    && str_contains($rAct, 'data-cancel-yes') && str_contains($rAct, 'data-cancel-keep'));
$rCan = $row($out, $kCan);
ok('account cancelled key: ends on the date, no Cancel button', str_contains($rCan, palette_t_html('en', 'acct_st_cancelled', ['date' => palette_ltr($futureDay)])) && !str_contains($rCan, 'data-cancel-open'));
$rSus = $row($out, $kSus);
ok('account suspended key: status shown, Cancel offered', str_contains($rSus, h(palette_t('en', 'acct_st_suspended'))) && str_contains($rSus, 'data-cancel-open'));
$rExp = $row($out, $kExp);
ok('account lapsed key: expired, no Cancel button', str_contains($rExp, h(palette_t('en', 'acct_st_expired'))) && !str_contains($rExp, 'data-cancel-open'));
// a refunded key whose PayPal cancel failed still has sub_status active and a future paid_through: revoked wins
$kRev = $mk('s6@studio.vn', 'SUBREV1', 'active', $future);
$conn->query("UPDATE palette_licenses SET revoked_at = NOW() WHERE license_key = '$kRev'");
$rRev = $row($acct(), $kRev);
ok('account revoked ai key: "Revoked", never "Active · Renews", no Cancel panel', str_contains($rRev, h(palette_t('en', 'acct_st_revoked')))
    && str_contains($rRev, 'is-revoked') && !str_contains($rRev, h(palette_t('en', 'acct_st_active'))) && !str_contains($rRev, 'data-sub-renews')
    && !str_contains($rRev, 'data-cancel-open'));
foreach (LANGS as $l) ok("acct_st_revoked in $l", trim(palette_t($l, 'acct_st_revoked')) !== '' && ($l === 'en' || palette_t($l, 'acct_st_revoked') !== palette_t('en', 'acct_st_revoked')));
$rPro = $row($out, $kPro);
ok('account pro key: Pro, one-time, no Cancel', str_contains($rPro, h(palette_t('en', 'acct_one_time'))) && !str_contains($rPro, 'data-cancel-open') && !str_contains($rPro, 'Pro + AI'));
ok('account issues the palette_account CSRF token (A10d)', preg_match('#id="palette-data">(.*?)</script>#s', $out, $m) === 1
    && ($tok = json_decode($m[1], true)['csrf'] ?? '') !== '' && $tok === ($_SESSION['csrf_tokens']['palette_account'] ?? null) && preg_match('/^[0-9a-f]{64}$/', $tok) === 1
    && verifyCSRFToken($tok, 'palette_account'));
generateCSRFToken('palette_account');
$_SESSION['csrf_tokens']['palette_account'] = $tok1 = str_repeat('ab', 32);
$acct();
ok('account token is reused, not rotated, across renders', ($_SESSION['csrf_tokens']['palette_account'] ?? '') === $tok1);
ok('account page data carries the sub strings', preg_match('#id="palette-data">(.*?)</script>#s', $out, $m) === 1
    && (json_decode($m[1], true)['t']['acct_st_cancelled'] ?? '') === palette_t('en', 'acct_st_cancelled'));
$_SESSION = [];
$outOff = palette_render_account(['query' => ['lang' => 'en'], 'accept_language' => '', 'session' => []]);
ok('signed out: cancel-by-key form (key + email) and a token', preg_match('/<form[^>]*id="cancel-by"/', $outOff) === 1 && preg_match('/<input[^>]*name="key"[^>]*>/', $outOff) === 1
    && preg_match('/<input[^>]*type="email"[^>]*name="email"/', $outOff) === 1 && str_contains($outOff, h(palette_t('en', 'acct_cancel_by_h')))
    && preg_match('/^[0-9a-f]{64}$/', (string)(checkoutData($outOff)['csrf'] ?? '')) === 1);
ok('signed in also has the cancel-by-key form', preg_match('/<form[^>]*id="cancel-by"/', $out) === 1);
foreach (LANGS as $l) {
    $a = $acct($l);
    ok("account $l: sub panel strings, no emoji", str_contains($a, palette_t_html($l, 'acct_cancel_ask', ['date' => palette_ltr($futureDay)])) && str_contains($a, h(palette_t($l, 'acct_cancel_by_h')))
        && preg_match(EMOJI_RE, $a) === 0);
}
ok('checkout.js never uses window.confirm', !preg_match('/\bconfirm\s*\(/', (string)file_get_contents(PALETTE_DIR . '/assets/checkout.js')));

// ---- Task 11: privacy, terms, landing and checkout copy for Pro + AI
cfg($conn, ['ai_paypal_plan_id' => 'P-TESTPLAN1', 'ai_paypal_plan_mode' => 'sandbox', 'ai_price_usd' => '3.99', 'ai_paypal_plan_price' => '3.99', 'ai_monthly_analyses' => '100']);
$privacy = fn(string $l) => palette_render_privacy(['query' => ['lang' => $l], 'accept_language' => '']);
$terms = fn(string $l) => palette_render_terms(['query' => ['lang' => $l], 'accept_language' => '']);
$pen = $privacy('en');
ok('privacy en: AI insights only on Analyse, with only the main part of the address (ruling A14)',
    str_contains($pen, h(palette_t('en', 'priv_ai_h'))) && str_contains(palette_t('en', 'priv_ai'), 'only when you press Analyse')
    && str_contains(palette_t('en', 'priv_ai'), "the site's address (only the main part, like example.com/shop)")
    && !str_contains(palette_t('en', 'priv_ai'), 'without anything after the ?') && str_contains(palette_t('en', 'priv_ai'), 'title') && str_contains(palette_t('en', 'priv_ai'), 'description')
    && str_contains(palette_t('en', 'priv_ai'), 'summary of the design') && str_contains($pen, h(palette_t('en', 'priv_ai'))));
ok('privacy en: scrubbing, Brave Search, DeepSeek, 7-day shared cache, aggregate list, PayPal and what we keep',
    str_contains(palette_t('en', 'priv_ai'), 'email addresses, links (anything starting with http, https or www, or containing a path), @handles and long digit runs such as phone numbers') && str_contains(palette_t('en', 'priv_ai'), 'other words in the title are sent as they are') && !str_contains(palette_t('en', 'priv_ai'), 'personal details') && str_contains(palette_t('en', 'priv_ai'), 'Brave Search')
    && str_contains(palette_t('en', 'priv_ai'), 'DeepSeek, an AI provider') && str_contains(palette_t('en', 'priv_ai'), 'cached for 7 days')
    && str_contains(palette_t('en', 'priv_ai'), 'other people who analyse the same site') && str_contains(palette_t('en', 'priv_ai'), 'without any user identifiers')
    && str_contains(palette_t('en', 'priv_ai'), 'billed monthly by PayPal') && str_contains(palette_t('en', 'priv_ai'), 'email, subscription id and payment dates'));
ok('privacy en: What it sends names the optional feature; existing sections kept',
    str_contains($pen, 'Only your license key, to vietnamcos.com, to check that it is valid.') && str_contains(palette_t('en', 'priv_sends'), 'AI insights')
    && str_contains($pen, h(palette_t('en', 'priv_pay'))) && str_contains($pen, h(palette_t('en', 'priv_track'))) && str_contains($pen, '2026-10-17'));
ok('privacy en: AI section sits between What it sends and What our server processes',
    strpos($pen, h(palette_t('en', 'priv_sends_h'))) < strpos($pen, h(palette_t('en', 'priv_ai_h'))) && strpos($pen, h(palette_t('en', 'priv_ai_h'))) < strpos($pen, h(palette_t('en', 'priv_server_h'))));
foreach (LANGS as $l) {
    $p = $privacy($l);
    $ai = palette_t($l, 'priv_ai');
    ok("privacy $l: AI section names Brave Search and DeepSeek, 7 days, What it sends mentions the feature, no emoji",
        str_contains($p, h(palette_t($l, 'priv_ai_h'))) && str_contains($p, h($ai)) && str_contains($p, h(palette_t($l, 'priv_sends')))
        && str_contains($ai, 'Brave Search') && str_contains($ai, 'DeepSeek') && str_contains($ai, (string)(PALETTE_ANALYSE_CACHE_TTL / 86400)) && str_contains($ai, '@') && str_contains($ai, 'PayPal') && str_contains($ai, 'vietnamcos.com')
        && str_contains(palette_t($l, 'priv_sends'), 'Pro + AI') && preg_match(EMOJI_RE, $p) === 0);
    ok("privacy $l: the address is only its main part, with the example.com/shop example (ruling A14)",
        str_contains($ai, 'example.com/shop') && !str_contains($ai, '?'));
}
// ruling A15: the site list keeps only names and domains, and the policy says how long
$retention = ['en' => 'The list is kept until we delete it.', 'es' => 'La lista se conserva hasta que la borremos.',
    'fr' => 'La liste est conservée jusqu’à ce que nous la supprimions.', 'ar' => 'نحتفظ بهذه القائمة إلى أن نحذفها.',
    'bn' => 'আমরা মুছে না ফেলা পর্যন্ত তালিকাটি রাখা হয়।', 'hi' => 'यह सूची तब तक रखी जाती है जब तक हम इसे हटा न दें।',
    'zh-CN' => '该列表会一直保留，直到我们将其删除。'];
foreach (LANGS as $l) ok("privacy $l: site list retention sentence (ruling A15)", str_contains(palette_t($l, 'priv_ai'), $retention[$l]));
ok('privacy en: the list holds site names and domains only', str_contains(palette_t('en', 'priv_ai'), 'combined list of analysed site names and domains, without any user identifiers'));

$ten = $terms('en');
ok('terms en: subscription, monthly PayPal, renews until cancelled, cancel from account, access to end of paid month, final sales',
    str_contains($ten, h(palette_t('en', 'terms_sub_h'))) && str_contains($ten, h(palette_t('en', 'terms_sub', ['price' => '$3.99'])))
    && str_contains(palette_t('en', 'terms_sub'), 'a month, billed monthly through PayPal') && str_contains(palette_t('en', 'terms_sub'), 'renews automatically every month until you cancel')
    && str_contains(palette_t('en', 'terms_sub'), 'cancel at any time from your account page') && str_contains(palette_t('en', 'terms_sub'), 'stay on until the end of the month you already paid for')
    && str_contains(palette_t('en', 'terms_sub'), 'All sales are final') && str_contains(palette_t('en', 'terms_sub'), 'chargeback'));
ok('terms en: analyses limit from settings, may change with notice, fair use, AI may be wrong',
    str_contains($ten, h(palette_t('en', 'terms_ai', ['n' => 100]))) && str_contains($ten, 'currently 100') && str_contains($ten, 'may change with notice')
    && str_contains($ten, 'no automated or bulk use') && str_contains($ten, 'can be wrong or out of date') && str_contains($ten, 'without any guarantee'));
cfg($conn, ['ai_monthly_analyses' => '250']);
ok('terms: the analyses limit follows the setting', str_contains($terms('en'), 'currently 250'));
cfg($conn, ['ai_monthly_analyses' => '100']);
ok('terms en: Pro stays one-time; no invented legal entity', str_contains(palette_t('en', 'terms_pay'), 'does not renew') && str_contains($ten, 'Pro + AI')
    && !preg_match('/governing law|LLC|Ltd|Inc\.|registered/i', $ten));
foreach (LANGS as $l) {
    $t = $terms($l);
    $price = $l === 'fr' ? "3,99\u{a0}\$ US" : '$3.99';
    ok("terms $l: subscription and AI sections, price and limit filled in, no emoji",
        str_contains($t, h(palette_t($l, 'terms_sub_h'))) && str_contains($t, h(palette_t($l, 'terms_sub', ['price' => $price])))
        && str_contains($t, h(palette_t($l, 'terms_ai', ['n' => 100]))) && str_contains($t, h(palette_t($l, 'co_refund'))) && str_contains($t, h(palette_t($l, 'terms_liability')))
        && preg_match('/{(price|n)}/', $t) === 0 && preg_match(EMOJI_RE, $t) === 0);
}

// landing: 3-column plan comparison, AI insights section, FAQ
$lp3 = landing(['lang' => 'en']);
preg_match('#id="plans".*?</section>#s', $lp3, $pl);
ok('plans table: Free, Pro and Pro + AI columns with prices from settings', preg_match('#<thead>(.*?)</thead>#s', $pl[0], $th) === 1
    && substr_count($th[1], '<th scope="col"') === 4 && str_contains($th[1], 'Pro, $1.49 once') && str_contains($th[1], 'Pro + AI, $3.99 a month')
    && !str_contains($th[1], h(palette_t('en', 'co_plan_soon'))));
ok('plans table: every row has three value cells; AI insights row is Pro + AI only', preg_match_all('#<tr><th scope="row">.*?</tr>#s', $pl[0], $rows) === 8
    && count(array_filter($rows[0], fn($r) => substr_count($r, '<td>') !== 3)) === 0
    && preg_match('#<tr><th scope="row">AI insights</th><td>(.*?)</td><td>(.*?)</td><td>(.*?100 a month.*?)</td></tr>#s', $pl[0], $air) === 1
    && str_contains($air[1], 'Not included') && str_contains($air[2], 'Not included'));
cfg($conn, ['ai_price_usd' => '5.49', 'ai_paypal_plan_price' => '5.49']);
ok('plans table: Pro + AI price follows the plan', str_contains(landing(['lang' => 'en']), 'Pro + AI, $5.49 a month'));
cfg($conn, ['ai_price_usd' => '6.49']);
$lpCh = landing(['lang' => 'en']);
preg_match('#id="plans".*?</section>#s', $lpCh, $plCh);
ok('A18: landing after a price change, before setup: Coming soon, no plan=ai link, new price never shown', str_contains($plCh[0], h(palette_t('en', 'co_plan_soon')))
    && !str_contains($lpCh, 'plan=ai') && !str_contains($lpCh, '$6.49'));
ok('A18: terms show the PayPal plan price, not the unsynced setting', str_contains(palette_render_terms(['query' => ['lang' => 'en'], 'accept_language' => '']), h(palette_t('en', 'terms_sub', ['price' => '$5.49']))));
cfg($conn, ['ai_price_usd' => '3.99', 'ai_paypal_plan_price' => '3.99', 'ai_paypal_plan_id' => '']);
$lpOff = landing(['lang' => 'en']);
preg_match('#id="plans".*?</section>#s', $lpOff, $plOff);
preg_match('#<thead>(.*?)</thead>#s', $plOff[0], $thOff);
ok('plans table, AI off: the Pro + AI column says Coming soon instead of a price', str_contains($thOff[1], 'Pro + AI') && str_contains($thOff[1], h(palette_t('en', 'co_plan_soon')))
    && !str_contains($thOff[1], '$3.99') && !str_contains($lpOff, 'plan=ai'));
preg_match('#id="insights".*?</section>#s', $lpOff, $insOff);
ok('AI insights section, AI off: Coming soon, no checkout link', str_contains($insOff[0], h(palette_t('en', 'co_plan_soon'))) && !str_contains($insOff[0], 'checkout'));
cfg($conn, ['ai_paypal_plan_id' => 'P-TESTPLAN1']);
$lpOn = landing(['lang' => 'en']);
preg_match('#id="insights".*?</section>#s', $lpOn, $ins);
ok('AI insights section: heading, text, three bullets, Pro + AI link to the plan checkout', ($ins[0] ?? '') !== '' && str_contains($ins[0], h(palette_t('en', 'lp_ins_h'))) && str_contains($ins[0], h(palette_t('en', 'lp_ins_text')))
    && preg_match_all('#<li>#', $ins[0]) === 3 && str_contains($ins[0], h(palette_t('en', 'lp_ins_b1'))) && str_contains($ins[0], h(palette_t('en', 'lp_ins_b3')))
    && str_contains($ins[0], 'href="/palette/checkout?lang=en&amp;plan=ai#buy"') && !str_contains($ins[0], h(palette_t('en', 'co_plan_soon'))));
ok('AI insights section sits between the plans table and the DESIGN.md block', strpos($lpOn, 'id="plans"') < strpos($lpOn, 'id="insights"') && strpos($lpOn, 'id="insights"') < strpos($lpOn, 'id="ai"'));
ok('AI insights screenshot: 6-ai-insights.png, sized, lazy, alt mentions Pro + AI; files exist',
    preg_match('#<img src="/palette/assets/screenshots/6-ai-insights\.png" width="1280" height="800" alt="([^"]+)" loading="lazy"#', $ins[0], $sm) === 1
    && $sm[1] === h(palette_t('en', 'lp_shot_6')) && str_contains($sm[1], 'Pro + AI') && is_file(PALETTE_DIR . '/assets/screenshots/6-ai-insights.png')
    && is_file(PALETTE_DIR . '/../docs/store/screenshots/6-ai-insights.png'));
ok('landing: still five gallery screenshots (the sixth lives in the AI section)', preg_match_all('#<img src="/palette/assets/screenshots/[1-5]-#', $lpOn) === 5);
preg_match('#id="faq".*?</section>#s', $lpOn, $faq);
ok('FAQ gains what Pro + AI is (price and limit) and how to cancel', str_contains($faq[0], h(palette_t('en', 'lp_faq_ai_q'))) && str_contains($faq[0], h(palette_t('en', 'lp_faq_ai_a', ['price' => '$3.99', 'n' => 100])))
    && str_contains($faq[0], 'up to 100 analyses a month') && str_contains($faq[0], h(palette_t('en', 'lp_faq_cancel_q'))) && str_contains($faq[0], 'select Cancel subscription')
    && str_contains($faq[0], 'stay on until the end of the month you paid for'));
ok('FAQ data answer mentions the Analyse data', str_contains(palette_t('en', 'lp_faq_data_a'), 'Only if you use Pro + AI and press Analyse'));
foreach (LANGS as $l) {
    $h = landing(['lang' => $l]);
    $price = $l === 'fr' ? "3,99\u{a0}\$ US" : '$3.99';
    ok("landing $l: AI section, screenshot alt, 3-column table, FAQ items, no emoji",
        str_contains($h, 'alt="' . h(palette_t($l, 'lp_shot_6')) . '"') && str_contains($h, h(palette_t($l, 'lp_ins_h'))) && str_contains($h, h(palette_t($l, 'lp_ins_b2')))
        && str_contains($h, h(palette_t($l, 'pp_plan_ai', ['price' => $price]))) && str_contains($h, h(palette_t($l, 'lp_faq_ai_q'))) && str_contains($h, h(palette_t($l, 'lp_faq_cancel_a')))
        && str_contains($h, h(palette_t($l, 'lp_faq_ai_a', ['price' => $price, 'n' => 100]))) && substr_count($h, '<details class="faq-item">') === 7
        && str_contains($h, h(palette_t($l, 'pp_ai_n', ['n' => 100]))) && preg_match('/{(price|n)}/', $h) === 0 && preg_match(EMOJI_RE, $h) === 0);
}
$cen = checkout(['lang' => 'en']);
ok('checkout shares the 3-column table and its lead names both plans', str_contains($cen, 'Pro + AI, $3.99 a month') && str_contains($cen, h(palette_t('en', 'co_lead'))) && str_contains(palette_t('en', 'co_lead'), 'Pro + AI is a monthly plan'));

// ---- Task 11: the deploy runbook and the README carry the Pro + AI operations
$deploy = (string)file_get_contents(PALETTE_DIR . '/DEPLOY.md');
$readme = (string)file_get_contents(PALETTE_DIR . '/README.md');
ok('DEPLOY: search_key is set through a silent read and ssh stdin, never echoed',
    str_contains($deploy, 'read -rs') && str_contains($deploy, "printf '%s' \"\$SEARCH_KEY\" | \$SSH") && str_contains($deploy, 'stream_get_contents(STDIN)')
    && str_contains($deploy, 'palette_settings_put(\$conn, \$s)') && str_contains($deploy, 'strlen(\$t[\"search_key\"])') && !preg_match('/search_key[^\n]*echo[^\n]*\$s\[/', $deploy));
ok('DEPLOY: the settings print masks search_key', str_contains($deploy, '\$p[\"search_key\"]=\$p[\"search_key\"]!==\"\"?\"(set)\":\"\"')
    && !preg_match('/echo json_encode\(palette_settings\(\$conn\)\)/', $deploy));
ok('DEPLOY: setup.php Subscription plan in sandbox then live', str_contains($deploy, 'Create or find the subscription plan') && str_contains($deploy, '`(sandbox)`') && str_contains($deploy, '`(live)`')
    && str_contains($deploy, '`ai_paypal_plan_mode` = `live`'));
ok('DEPLOY: the daily cron line, installed in /etc/cron.d/palette with owner and mode',
    str_contains($deploy, '15 3 * * * www-data php /var/www/duykhang/companies/palette/cron-subscriptions.php') && str_contains($deploy, '/etc/cron.d/palette')
    && str_contains($deploy, 'chown root:root /etc/cron.d/palette') && str_contains($deploy, 'chmod 644 /etc/cron.d/palette'));
ok('DEPLOY: companies prerequisites (gateway files, platform key, ai_enabled)', str_contains($deploy, 'tools/api/ai-gateway.php') && str_contains($deploy, 'tools/api/ai-usage.php')
    && str_contains($deploy, 'DEEPSEEK_API_KEY') && str_contains($deploy, 'hiring settings') && str_contains($deploy, '`ai_enabled=1`'));
ok('DEPLOY: sandbox subscription checklist (subscribe, key, renewal, cancel, refund, alerts)', str_contains($deploy, '### 6c. Pro + AI subscription pass (sandbox)')
    && str_contains($deploy, 'Renewal:') && str_contains($deploy, 'Cancel from the account page') && str_contains($deploy, 'Refund: refund a sandbox sale')
    && str_contains($deploy, 'PALETTE_PAID_UNFULFILLED') && str_contains($deploy, 'PALETTE_CANCEL_FAILED') && str_contains($deploy, 'Alert on every line that starts with `PALETTE_`'));
ok('DEPLOY: known limitations from the ledger (user -1, scrubbing, lost checkout, kill switch, deferred gaps)', str_contains($deploy, 'logged as user -1') && str_contains($deploy, 'Page text is scrubbed')
    && str_contains($deploy, 'Lost pending subscription checkout') && str_contains($deploy, 'ai_enabled = 0') && str_contains($deploy, 'A8') && str_contains($deploy, 'A9') && str_contains($deploy, 'A10(c)'));
ok('DEPLOY: store checklist names the data disclosure and the five screenshots', str_contains($deploy, '**Website content**') && str_contains($deploy, '**Web history**') && str_contains($deploy, '6-ai-insights'));
ok('README: the AI/subscription contract lists the gateway helpers, user -1, ai_enabled, field names and error keys',
    str_contains($readme, '### AI insights and subscription contract (Pro + AI)') && str_contains($readme, 'ai_key_for($conn, 0)') && str_contains($readme, 'ai_provider_request')
    && str_contains($readme, 'ai_http_call') && str_contains($readme, 'ai_provider_parse') && str_contains($readme, 'PALETTE_AI_LOG_USER') && str_contains($readme, '`hiring_settings.ai_enabled = 0`')
    && str_contains($readme, 'search_unavailable') && str_contains($readme, 'spacing_base') && str_contains($readme, 'err_quota') && str_contains($readme, 'err_ended') && str_contains($readme, 'err_not_ai'));

// ---- Task 11 fix 1: the scrubbing claim matches palette_scrub_text; the data FAQ answer is gated like the other AI elements
foreach (LANGS as $l) {
    $ai = palette_t($l, 'priv_ai');
    ok("priv_ai $l: names what is removed (@handles, long digit runs), no broad 'personal details' claim, no bare 'nothing is sent'",
        str_contains($ai, '@') && str_contains($ai, 'Brave Search') && str_contains($ai, 'DeepSeek') && str_contains($ai, (string)(PALETTE_ANALYSE_CACHE_TTL / 86400))
        && ($l !== 'en' || !str_contains($ai, 'nothing is sent')));
}
$scrubSample = palette_scrub_text('Jane Smith, 12 Oak St - dentist jane@x.com @jane https://x.com/a 0912345678', 200);
ok('the scrubber leaves other words of a title alone (what the policy now says)', str_contains($scrubSample, 'Jane Smith, 12 Oak St') && !str_contains($scrubSample, '@') && !str_contains($scrubSample, '0912345678') && !str_contains($scrubSample, 'x.com'));
$listingEn = (string)file_get_contents(PALETTE_DIR . '/../docs/store/listing.en.md');
ok('en listing and disclosure use the narrow wording', substr_count($listingEn, 'links (anything starting with http, https or www, or containing a path), @handles and long digit runs such as phone numbers') === 2 && !str_contains($listingEn, 'web addresses') && !str_contains($listingEn, 'personally identifiable information') && !str_contains($listingEn, 'account details')
    && preg_match('/^- Personal communications\/contact: the buyer\'s email address, only when buying Pro or Pro \+ AI on vietnamcos\.com, used to deliver and resend licence keys and for billing \(sent via Resend; payment via PayPal\)\./m', $listingEn) === 1 && !str_contains($listingEn, 'Personal details such as'));
cfg($conn, ['ai_paypal_plan_id' => '']);
preg_match('#id="faq".*?</section>#s', landing(['lang' => 'en']), $fq);
ok('FAQ: what Pro + AI is says Coming soon while AI is off', str_contains($fq[0], h(palette_t('en', 'co_plan_soon')) . '. ' . h(palette_t('en', 'lp_faq_ai_a', ['price' => '$3.99', 'n' => 100]))));
cfg($conn, ['ai_paypal_plan_id' => 'P-TESTPLAN1']);
ok('FAQ: no Coming soon prefix while AI is on', !str_contains(landing(['lang' => 'en']), h(palette_t('en', 'co_plan_soon')) . '. ' . h(palette_t('en', 'lp_faq_ai_a'))));

// ---- Task 11 fix 2: the copy says "links", the scrubber leaves bare domains alone
ok('the scrubber leaves a bare domain in a title (so the copy must not promise to remove web addresses)', palette_scrub_text('Shop example.com - dentist', 200) === 'Shop example.com - dentist');
ok('the scrubber removes http, https and www links and host/path forms', palette_scrub_text('A https://x.com B www.y.com C z.com/a D', 200) === 'A B C D');
$webAddr = [];
foreach (LANGS as $l) if (str_contains(palette_t($l, 'priv_ai'), 'web addresses') || !str_contains(palette_t($l, 'priv_ai'), 'http') || !str_contains(palette_t($l, 'priv_ai'), 'www')) $webAddr[] = $l;
ok('priv_ai: no "web addresses" in en and every language names http/https/www links: ' . implode(',', $webAddr), $webAddr === []);
ok('en privacy page no longer says "web addresses"', !str_contains(palette_render_privacy(['query' => ['lang' => 'en'], 'accept_language' => '']), 'web addresses'));
