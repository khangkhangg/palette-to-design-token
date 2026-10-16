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
    'terms_contact_h', 'terms_contact'];
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
ok('landing: FAQ with 5 questions, privacy link and the refund line', preg_match('#id="faq".*?</section>#s', $lp, $qm) === 1
    && substr_count($qm[0], '<details') === 5 && str_contains($qm[0], 'href="/palette/privacy?lang=en"') && str_contains($qm[0], h($refundEn))
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
