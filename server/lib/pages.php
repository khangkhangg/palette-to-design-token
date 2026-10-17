<?php
// Public pages: checkout, account and privacy. Each renderer is a pure function of $ctx
// (['query' => $_GET, 'accept_language' => string, 'session' => $_SESSION]) returning the HTML;
// checkout.php / account.php / privacy.php are thin adapters. Behaviour lives in assets/checkout.js.

const PALETTE_BASE = '/palette';
const PALETTE_LANG_NAMES = ['en' => 'English', 'zh-CN' => '中文', 'hi' => 'हिन्दी', 'es' => 'Español',
                            'ar' => 'العربية', 'fr' => 'Français', 'bn' => 'বাংলা'];
const PALETTE_PRIVACY_UPDATED = '2026-10-17';
const PALETTE_TERMS_UPDATED = '2026-10-17';
const PALETTE_SUPPORT_EMAIL = 'support@vietnamcos.com';
// The extension version the checkout page offers (keep in step with extension/public/manifest.json; pages-test checks).
const PALETTE_EXT_VERSION = '0.1.0';
// Direct download, uploaded to server/download/ at deploy (npm run package:direct); never committed.
const PALETTE_DOWNLOAD_URL = PALETTE_BASE . '/download/site-to-design-token-latest.zip';
const PALETTE_US_STATES = [
    'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado',
    'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia',
    'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
    'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts',
    'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana',
    'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
    'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
    'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
    'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
    'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
];
// Strings checkout.js needs at runtime.
const PALETTE_SCRIPT_STRINGS = ['err_email', 'err_paused', 'err_consent', 'err_paypal', 'err_retry', 'err_csrf', 'co_err_region', 'co_err_network',
    'co_err_confirm', 'co_load_failed', 'co_copy', 'co_copied', 'co_copy_failed', 'co_ext_ok', 'co_ext_paste', 'co_emailed', 'co_key_emailed', 'api_resend_ok', 'acct_added',
    // plan chooser (swapped in by checkout.js when the plan changes) and the account subscription panel
    'co_item', 'co_item_ai', 'co_refund', 'co_refund_ai', 'co_card_note', 'co_note_ai', 'co_sub_pending', 'co_success_title', 'co_success_title_ai', 'acct_st_cancelled'];

// ?lang= when it is one of the 7; else the best Accept-Language match; else en.
// The escaped string with {name} placeholders replaced by trusted HTML.
function palette_t_html(string $lang, string $key, array $html): string
{
    return strtr(palette_h(palette_t($lang, $key)), array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($html)), $html));
}

// Text that must keep left-to-right order inside RTL copy (dates, emails, keys).
function palette_ltr(string $text): string
{
    return '<bdi dir="ltr">' . palette_h($text) . '</bdi>';
}

function palette_page_lang(array $query, string $acceptLanguage): string
{
    $q = $query['lang'] ?? null;
    if (is_string($q) && in_array($q, PALETTE_LANGS, true)) return $q;
    $best = 'en';
    $bestQ = 0.0;
    foreach (explode(',', $acceptLanguage) as $part) {
        $bits = explode(';', $part);
        $tag = strtolower(trim($bits[0]));
        $weight = 1.0;
        foreach (array_slice($bits, 1) as $p) {
            if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $p, $m)) $weight = (float)$m[1];
        }
        $lang = palette_match_lang($tag);
        if ($lang !== null && $weight > 0 && $weight > $bestQ) {
            $best = $lang;
            $bestQ = $weight;
        }
    }
    return $best;
}

function palette_match_lang(string $tag): ?string
{
    foreach (PALETTE_LANGS as $l) if (strtolower($l) === $tag) return $l;
    $primary = explode('-', $tag)[0];
    if ($primary === 'zh') return 'zh-CN';
    return in_array($primary, PALETTE_LANGS, true) ? $primary : null;
}

// One set of response headers for every page.
function palette_page_headers(): array
{
    return [
        'Content-Type' => 'text/html; charset=utf-8',
        'Content-Security-Policy' => "default-src 'self'; script-src 'self' https://www.paypal.com https://*.paypal.com https://*.paypalobjects.com; "
            . "frame-src https://*.paypal.com; connect-src 'self' https://*.paypal.com https://*.paypalobjects.com; "
            . "img-src 'self' data: https://*.paypal.com https://*.paypalobjects.com; style-src 'self' 'unsafe-inline'; "
            . "base-uri 'none'; form-action 'self'; object-src 'none'; frame-ancestors 'none'",
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Content-Type-Options' => 'nosniff',
        'Vary' => 'Accept-Language',
    ];
}

function palette_send_page_headers(array $extra = []): void
{
    if (headers_sent()) return;
    foreach ($extra + palette_page_headers() as $k => $v) header("$k: $v");
}

// The request as the renderers want it.
function palette_page_ctx(): array
{
    return ['query' => $_GET, 'accept_language' => (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 'session' => $_SESSION ?? []];
}

// Query values a page keeps across language links: a valid extension id and a well-formed key.
function palette_page_keep(array $query): array
{
    $keep = [];
    $ext = $query['ext'] ?? null;
    if (is_string($ext) && preg_match('/^[a-p]{32}$/', $ext)) $keep['ext'] = $ext;
    $key = is_string($query['key'] ?? null) ? palette_normalise_key($query['key']) : null;
    if ($key !== null) $keep['key'] = $key;
    return $keep;
}

function palette_page_url(string $page, string $lang, array $keep = []): string
{
    return PALETTE_BASE . '/' . $page . '?' . http_build_query(['lang' => $lang] + $keep, '', '&', PHP_QUERY_RFC3986);
}

function palette_asset(string $file): string
{
    $v = @filemtime(PALETTE_DIR . '/assets/' . $file);
    return PALETTE_BASE . '/assets/' . $file . ($v ? '?v=' . $v : '');
}

// Inline SVG icons (stroke, currentColor). Decorative: hidden from assistive tech.
function palette_icon(string $name, int $size = 16): string
{
    $paths = [
        'globe' => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'lock' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'browser' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 9h20M6 6.5h.01M9 6.5h.01"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'copy' => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
        'key' => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>',
        'alert' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'hdd' => '<rect x="2" y="13" width="20" height="8" rx="2"/><path d="M5 13 7.5 4h9L19 13M6 17h.01"/>',
        'send' => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
        'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'chat' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3"/>',
        'palette' => '<path d="M12 2a10 10 0 0 0 0 20c1 0 1.7-.8 1.7-1.7 0-.4-.2-.8-.5-1.1-.3-.3-.4-.7-.4-1.1 0-.9.7-1.7 1.7-1.7h2A5.5 5.5 0 0 0 22 11c0-5-4.5-9-10-9z"/>'
            . '<circle cx="7.5" cy="11.5" r="1.2"/><circle cx="10.5" cy="7" r="1.2"/><circle cx="15.5" cy="7.5" r="1.2"/>',
        'layers' => '<path d="m12 2 10 5-10 5L2 7z"/><path d="m2 17 10 5 10-5M2 12l10 5 10-5"/>',
        'contrast' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a10 10 0 0 0 0 20z" fill="currentColor"/>',
        'code' => '<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>',
        'minus' => '<path d="M5 12h14"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        'spark' => '<path d="m12 3 1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 3v4M17 5h4"/>',
    ];
    return '<svg class="icon icon-' . $name . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? '') . '</svg>';
}

function palette_lang_switcher(string $page, string $lang, array $keep): string
{
    $items = '';
    foreach (PALETTE_LANG_NAMES as $code => $name) {
        $cur = $code === $lang ? ' aria-current="true"' : '';
        $items .= '<li><a href="' . palette_h(palette_page_url($page, $code, $keep)) . '" hreflang="' . $code . '" lang="' . $code . '"' . $cur . '>'
            . '<span>' . palette_h($name) . '</span>' . ($cur ? palette_icon('check', 14) : '') . '</a></li>';
    }
    return '<details class="lang"><summary aria-label="' . palette_h(palette_t($lang, 'page_language') . ': ' . PALETTE_LANG_NAMES[$lang]) . '">'
        . palette_icon('globe', 15) . '<span lang="' . $lang . '">' . palette_h(PALETTE_LANG_NAMES[$lang]) . '</span>' . palette_icon('chevron', 14)
        . '</summary><ul class="lang-menu">' . $items . '</ul></details>';
}

function palette_page_shell(string $page, string $lang, array $keep, string $title, string $main, array $data = []): string
{
    $t = fn(string $k) => palette_h(palette_t($lang, $k));
    $dir = $lang === 'ar' ? 'rtl' : 'ltr';
    $json = json_encode(['lang' => $lang, 'page' => $page] + $data,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return '<!doctype html>' . "\n"
        . '<html lang="' . $lang . '" dir="' . $dir . '">' . "\n"
        . '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light dark"><title>' . palette_h($title) . '</title>'
        . '<link rel="stylesheet" href="' . palette_h(palette_asset('checkout.css')) . '">'
        . '<script src="' . palette_h(palette_asset('checkout.js')) . '" defer></script></head>' . "\n"
        . '<body class="page-' . $page . '">'
        . '<header class="topbar"><div class="topbar-in">'
        . '<a class="brand" href="' . palette_h(palette_page_url('', $lang)) . '">'
        . '<span class="mark" aria-hidden="true"></span><span class="brand-name">Site to Design Token</span>'
        . '<span class="brand-by">' . $t('page_by') . '</span></a>'
        . palette_lang_switcher($page === 'landing' ? '' : $page, $lang, $keep)
        . '</div></header>' . "\n"
        . $main . "\n"
        . '<footer class="foot"><div class="foot-in">'
        . '<a href="' . palette_h(palette_page_url('privacy', $lang)) . '">' . $t('page_privacy') . '</a>'
        . '<a href="' . palette_h(palette_page_url('terms', $lang)) . '">' . $t('page_terms') . '</a>'
        . '<a href="' . palette_h(palette_page_url('account', $lang)) . '">' . $t('page_keys') . '</a>'
        . '<a href="mailto:' . PALETTE_SUPPORT_EMAIL . '">' . $t('page_support') . ': <span dir="ltr">' . PALETTE_SUPPORT_EMAIL . '</span></a>'
        . '<span class="foot-copy">vietnamcos.com</span>'
        . '</div></footer>' . "\n"
        . '<script type="application/json" id="palette-data">' . $json . '</script>' . "\n"
        . '</body></html>' . "\n";
}

// French writes "1,49 $ US" (no-break space before $) everywhere: checkout, extension, listing.
function palette_usd(string $lang, string $amount): string
{
    return $lang === 'fr' ? str_replace('.', ',', $amount) . "\u{a0}\$ US" : '$' . $amount;
}

// The Pro price as this language writes it, from the palette settings.
function palette_price_label(string $lang): string
{
    return palette_usd($lang, palette_money(palette_money_settings(palette_settings($GLOBALS['conn']))['price']));
}

// The Pro + AI monthly price as this language writes it.
function palette_ai_price_label(string $lang): string
{
    return palette_usd($lang, palette_money(palette_ai_sale_cents(palette_settings($GLOBALS['conn']))));
}

// Whether Pro + AI can be bought right now (plan exists for the current PayPal mode).
function palette_ai_on(): bool
{
    $conn = $GLOBALS['conn'];
    return palette_ai_plan_ready(palette_settings($conn), pp_settings($conn));
}

// ?plan=ai|pro; Pro unless AI is on and asked for.
function palette_page_plan(array $query, bool $aiOn): string
{
    return $aiOn && ($query['plan'] ?? null) === 'ai' ? 'ai' : 'pro';
}

// The session's account-page token ('palette_account', used by sub_cancel): created once, never rotated, so a second tab keeps working.
function palette_account_csrf_token(): string
{
    $tok = $_SESSION['csrf_tokens']['palette_account'] ?? null;
    return is_string($tok) && preg_match('/^[0-9a-f]{64}$/', $tok) ? $tok : generateCSRFToken('palette_account');
}

// Two plan cards, radio semantics (mockup J): Pro + AI first, Pro second. Pro + AI is not selectable until its PayPal plan exists.
function palette_plan_cards(string $lang, string $plan, bool $aiOn, string $proPrice, string $aiPrice, int $analyses): string
{
    $card = function (string $id, string $name, string $price, string $per, string $badge, array $items) use ($plan, $aiOn, $lang): string {
        $disabled = $id === 'ai' && !$aiOn;
        $on = $plan === $id;
        $list = '';
        foreach ($items as $it) $list .= '<li>' . palette_icon('check', 14) . '<span>' . palette_h($it) . '</span></li>';
        return '<div class="plan-card' . ($on ? ' is-on' : '') . ($disabled ? ' is-disabled' : '') . '" role="radio" data-plan="' . $id . '"'
            . ' aria-checked="' . ($on ? 'true' : 'false') . '"' . ($disabled ? ' aria-disabled="true"' : '') . ' tabindex="' . ($on ? '0' : '-1') . '"'
            . ' aria-labelledby="plan-' . $id . '-name plan-' . $id . '-price" aria-describedby="plan-' . $id . '-items">'
            . '<div class="plan-head"><span class="plan-name"><span id="plan-' . $id . '-name">' . palette_h($name) . '</span>'
            . ($badge !== '' ? '<span class="plan-badge">' . palette_h($badge) . '</span>' : '') . '</span>'
            . '<span class="plan-price" id="plan-' . $id . '-price"><span class="num" dir="ltr">' . palette_h($price) . '</span><span class="plan-per">' . palette_h($per) . '</span></span></div>'
            . '<ul class="plan-items" id="plan-' . $id . '-items">' . $list . '</ul></div>';
    };
    $t = fn(string $k, array $v = []) => palette_t($lang, $k, $v);
    return '<div class="plan-pick" id="plan-pick" role="radiogroup" aria-labelledby="plan-h">'
        . $card('ai', 'Pro + AI', $aiPrice, $t('co_plan_month'), $aiOn ? $t('co_plan_best') : $t('co_plan_soon'),
            [$t('co_ai_i1'), $t('co_ai_i2', ['n' => $analyses]), $t('co_ai_i3'), $t('co_ai_i4'), $t('co_ai_i5')])
        . $card('pro', 'Pro', $proPrice, $t('co_plan_once'), '', [$t('co_pro_i1'), $t('co_pro_i2'), $t('co_pro_i3')])
        . '</div>';
}

// ---- sections shared by the landing and checkout pages

// Download (free) + Get Pro, and the version / browsers line. $proHref: '#buy' on checkout, the checkout URL elsewhere.
function palette_section_hero_actions(string $lang, string $price, string $proHref, ?string $aiHref = null, string $aiPrice = ''): string
{
    return '<div class="hero-actions" id="hero-actions">'
        . '<a class="btn btn-accent btn-lg" href="' . PALETTE_DOWNLOAD_URL . '" download>' . palette_icon('download', 17) . '<span>' . palette_h(palette_t($lang, 'pp_download')) . '</span></a>'
        . '<a class="btn btn-quiet btn-lg" href="' . palette_h($proHref) . '">' . palette_h(palette_t($lang, 'pp_get_pro', ['price' => $price])) . '</a>'
        . ($aiHref !== null ? '<a class="btn btn-quiet btn-lg" href="' . palette_h($aiHref) . '">' . palette_h(palette_t($lang, 'pp_get_ai', ['price' => $aiPrice])) . '</a>' : '')
        . '</div>'
        . '<p class="works-in">' . palette_t_html($lang, 'pp_works_in', ['version' => palette_ltr(PALETTE_EXT_VERSION)]) . '</p>';
}

function palette_section_features(string $lang): string
{
    $t = fn(string $k) => palette_h(palette_t($lang, $k));
    $features = '';
    foreach (['colours' => 'palette', 'system' => 'layers', 'contrast' => 'contrast', 'export' => 'code'] as $k => $icon) {
        $features .= '<li class="feature"><span class="feature-icon">' . palette_icon($icon, 18) . '</span><div>'
            . '<h3>' . $t('pp_feat_' . $k . '_h') . '</h3><p>' . $t('pp_feat_' . $k) . '</p></div></li>';
    }
    return '<section class="pp-section" id="what" aria-labelledby="what-h"><h2 id="what-h">' . $t('pp_what_h') . '</h2>'
        . '<ul class="features">' . $features . '</ul></section>';
}

// Free / Pro / Pro + AI. The wrapper becomes a focusable, labelled region (checkout.js) only when the table overflows.
// The Pro + AI header shows its monthly price once the PayPal plan exists for this mode, and "Coming soon" until then.
function palette_section_plans(string $lang, string $price): string
{
    $t = fn(string $k) => palette_h(palette_t($lang, $k));
    $aiOn = palette_ai_on();
    $yes = '<span class="plan-yes">' . palette_icon('check', 16) . '<span class="sr-only">' . $t('pp_yes') . '</span></span>';
    $no = '<span class="plan-no">' . palette_icon('minus', 16) . '<span class="sr-only">' . $t('pp_no') . '</span></span>';
    $perMonth = palette_h(palette_t($lang, 'pp_ai_n', ['n' => palette_usage_limit(palette_settings($GLOBALS['conn']))]));
    $rows = '';
    foreach ([['pp_row_scan', $yes, $yes, $yes], ['pp_row_view', $yes, $yes, $yes], ['pp_row_copy', $yes, $yes, $yes], ['pp_row_contrast', $yes, $yes, $yes],
              ['pp_row_saved', $t('pp_saved_free'), $t('pp_saved_pro'), $t('pp_saved_pro')], ['pp_row_exports', $no, $yes, $yes], ['pp_row_fixes', $no, $yes, $yes],
              ['pp_row_ai', $no, $no, $perMonth]] as [$k, $free, $pro, $ai]) {
        $rows .= '<tr><th scope="row">' . $t($k) . '</th><td>' . $free . '</td><td>' . $pro . '</td><td>' . $ai . '</td></tr>';
    }
    $aiHead = $aiOn ? palette_h(palette_t($lang, 'pp_plan_ai', ['price' => palette_ai_price_label($lang)]))
        : 'Pro + AI <span class="plan-soon">' . $t('co_plan_soon') . '</span>';
    return '<section class="pp-section" id="plans" aria-labelledby="plans-h"><h2 id="plans-h">' . $t('pp_plans_h') . '</h2>'
        . '<div class="plans-wrap" data-scroll-label="' . $t('pp_plans_h') . '"><table class="plans"><thead><tr><th scope="col"><span class="sr-only">' . $t('pp_plan_feature') . '</span></th>'
        . '<th scope="col">' . $t('pp_plan_free') . '</th>'
        . '<th scope="col" class="plan-pro">' . palette_h(palette_t($lang, 'pp_plan_pro', ['price' => $price])) . '</th>'
        . '<th scope="col" class="plan-ai">' . $aiHead . '</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div></section>';
}

function palette_section_install(string $lang): string
{
    $t = fn(string $k) => palette_h(palette_t($lang, $k));
    // chrome:// links are blocked from web pages, so the address is copyable text.
    $url = '<span class="copyable"><code dir="ltr">chrome://extensions</code>'
        . '<button type="button" class="btn btn-quiet btn-sm" data-copy="chrome://extensions" data-copy-status="install-copy-status">'
        . palette_icon('copy', 14) . '<span>' . $t('co_copy') . '</span></button></span>';
    $steps = '';
    foreach (['pp_install_1', 'pp_install_2', 'pp_install_3', 'pp_install_4', 'pp_install_5'] as $k) {
        $steps .= '<li>' . ($k === 'pp_install_2' ? palette_t_html($lang, $k, ['url' => $url]) : $t($k)) . '</li>';
    }
    return '<section class="pp-section" id="install" aria-labelledby="install-h"><h2 id="install-h">' . $t('pp_install_h') . '</h2>'
        . '<ol class="steps">' . $steps . '</ol>'
        . '<p class="sr-only" id="install-copy-status" role="status"></p>'
        . '<p class="pp-note">' . palette_icon('info', 15) . '<span>' . $t('pp_install_note') . '</span></p></section>';
}

// Strings checkout.js needs on every page (copy buttons).
function palette_script_strings(string $lang): array
{
    $strings = [];
    foreach (PALETTE_SCRIPT_STRINGS as $k) $strings[$k] = palette_t($lang, $k);
    return $strings;
}

const PALETTE_SCREENSHOTS = ['1-popup-colours.png', '2-contrast.png', '3-side-panel-light.png', '4-side-panel-dark.png', '5-export.png'];
// The AI results shot sits in the AI insights section, not in the gallery.
const PALETTE_AI_SCREENSHOT = '6-ai-insights.png';
// A short DESIGN.md excerpt for the landing page (code, so not translated).
const PALETTE_DESIGN_MD_SAMPLE = "# Design system — fernleaf.example\n\nUse only these tokens for UI.\nDo not invent new colours, fonts or spacing.\n\n"
    . "| Role    | Token           | Hex     |\n| ------- | --------------- | ------- |\n| primary | --color-primary | #1F5E4B |\n"
    . "| accent  | --color-accent  | #E0763A |\n| text    | --color-text    | #1A1A1A |\n\nSpacing: 4 px base (4, 8, 12, 16, 24, 32)";

function palette_render_landing(array $ctx): string
{
    $query = is_array($ctx['query'] ?? null) ? $ctx['query'] : [];
    $lang = palette_page_lang($query, (string)($ctx['accept_language'] ?? ''));
    $t = fn(string $k) => palette_h(palette_t($lang, $k));
    $price = palette_price_label($lang);

    $hero = '<section class="hero lp-hero">'
        . '<h1>' . $t('lp_h1') . '</h1>'
        . '<p class="lead">' . $t('lp_lead') . '</p>'
        . palette_section_hero_actions($lang, $price, palette_page_url('checkout', $lang) . '#buy',
            palette_ai_on() ? palette_page_url('checkout', $lang, ['plan' => 'ai']) . '#buy' : null, palette_ai_price_label($lang))
        . '</section>';

    $shots = '';
    foreach (PALETTE_SCREENSHOTS as $i => $file) {
        $shots .= '<figure class="shot' . ($i === 0 ? ' shot-main' : '') . '"><img src="' . PALETTE_BASE . '/assets/screenshots/' . $file . '" width="1280" height="800"'
            . ' alt="' . $t('lp_shot_' . ($i + 1)) . '"' . ($i === 0 ? '' : ' loading="lazy"') . ' decoding="async"></figure>';
    }
    $showcase = '<section class="pp-section lp-shots" id="shots" aria-labelledby="shots-h"><h2 id="shots-h" class="sr-only">' . $t('lp_shots_h') . '</h2>'
        . '<div class="shots">' . $shots . '</div></section>';

    $how = '<section class="pp-section" id="how" aria-labelledby="how-h"><h2 id="how-h">' . $t('lp_how_h') . '</h2>'
        . '<ol class="steps steps-row"><li>' . $t('lp_how_1') . '</li><li>' . $t('lp_how_2') . '</li><li>' . $t('lp_how_3') . '</li></ol></section>';

    $aiOn = palette_ai_on();
    $bullets = '';
    foreach (['lp_ins_b1', 'lp_ins_b2', 'lp_ins_b3'] as $k) $bullets .= '<li>' . palette_icon('check', 16) . '<span>' . $t($k) . '</span></li>';
    $cta = $aiOn
        ? '<a class="btn btn-accent" href="' . palette_h(palette_page_url('checkout', $lang, ['plan' => 'ai']) . '#buy') . '">'
            . palette_h(palette_t($lang, 'pp_get_ai', ['price' => palette_ai_price_label($lang)])) . '</a>'
        : '<span class="plan-soon">Pro + AI: ' . $t('co_plan_soon') . '</span>';
    $insights = '<section class="pp-section lp-insights" id="insights" aria-labelledby="insights-h"><div class="lp-ins-text">'
        . '<h2 id="insights-h">' . palette_icon('spark', 18) . $t('lp_ins_h') . '</h2><p>' . $t('lp_ins_text') . '</p>'
        . '<ul class="ticks">' . $bullets . '</ul><div class="lp-ins-cta">' . $cta . '</div></div>'
        . '<figure class="shot"><img src="' . PALETTE_BASE . '/assets/screenshots/' . PALETTE_AI_SCREENSHOT . '" width="1280" height="800"'
        . ' alt="' . $t('lp_shot_6') . '" loading="lazy" decoding="async"></figure></section>';

    $ai = '<section class="pp-section lp-ai" id="ai" aria-labelledby="ai-h"><div class="lp-ai-text">'
        . '<h2 id="ai-h">' . palette_icon('code', 18) . $t('lp_ai_h') . '</h2><p>' . $t('lp_ai_text') . '</p>'
        . '<p class="pp-aside">' . $t('lp_ai_where') . '</p></div>'
        . '<pre class="md-sample" dir="ltr" lang="en" data-scroll-label="' . $t('lp_ai_h') . '"><code>' . palette_h(PALETTE_DESIGN_MD_SAMPLE) . '</code></pre></section>';

    $privacy = '<a href="' . palette_h(palette_page_url('privacy', $lang)) . '">' . $t('lp_faq_data_link') . '</a>';
    $faqs = [
        [$t('lp_faq_free_q'), $t('lp_faq_free_a')],
        [$t('lp_faq_pro_q'), palette_h(palette_t($lang, 'lp_faq_pro_a', ['price' => $price]))],
        [$t('lp_faq_key_q'), $t('pp_key_1') . ' ' . $t('pp_key_2')],
        [$t('lp_faq_data_q'), $t('lp_faq_data_a') . ' ' . $privacy],
        [$t('lp_faq_refund_q'), $t('co_refund')],
        [$t('lp_faq_ai_q'), ($aiOn ? '' : $t('co_plan_soon') . '. ') . palette_h(palette_t($lang, 'lp_faq_ai_a', ['price' => palette_ai_price_label($lang), 'n' => palette_usage_limit(palette_settings($GLOBALS['conn']))]))],
        [$t('lp_faq_cancel_q'), $t('lp_faq_cancel_a')],
    ];
    $items = '';
    foreach ($faqs as [$q, $a]) {
        $items .= '<details class="faq-item"><summary>' . $q . palette_icon('chevron', 16) . '</summary><p>' . $a . '</p></details>';
    }
    $faq = '<section class="pp-section" id="faq" aria-labelledby="faq-h"><h2 id="faq-h">' . $t('lp_faq_h') . '</h2>'
        . '<div class="faq">' . $items . '</div></section>';

    $main = '<main class="wrap landing">' . $hero . $showcase . $how . palette_section_features($lang) . palette_section_plans($lang, $price)
        . $insights . $ai . palette_section_install($lang) . $faq . '</main>';
    return palette_page_shell('landing', $lang, [], palette_t($lang, 'lp_title'), $main, ['t' => palette_script_strings($lang)]);
}

function palette_render_terms(array $ctx): string
{
    $query = is_array($ctx['query'] ?? null) ? $ctx['query'] : [];
    $lang = palette_page_lang($query, (string)($ctx['accept_language'] ?? ''));
    $t = fn(string $k) => palette_h(palette_t($lang, $k));
    $mail = '<a href="mailto:' . PALETTE_SUPPORT_EMAIL . '" dir="ltr">' . PALETTE_SUPPORT_EMAIL . '</a>';

    $sections = '';
    foreach ([['buy', 'key', $t('terms_buy')],
              ['pay', 'card', palette_h(palette_t($lang, 'terms_pay', ['price' => palette_price_label($lang)]))],
              ['sub', 'card', palette_h(palette_t($lang, 'terms_sub', ['price' => palette_ai_price_label($lang)]))],
              ['final', 'alert', $t('co_refund')],
              ['revoke', 'lock', $t('terms_revoke')],
              ['use', 'shield', $t('terms_use')],
              ['ai', 'spark', palette_h(palette_t($lang, 'terms_ai', ['n' => palette_usage_limit(palette_settings($GLOBALS['conn']))]))],
              ['asis', 'info', $t('terms_asis')],
              ['liability', 'shield', $t('terms_liability')],
              ['changes', 'globe', $t('terms_changes')],
              ['contact', 'chat', palette_t_html($lang, 'terms_contact', ['email' => $mail])]] as [$k, $icon, $body]) {
        $sections .= '<section class="policy-item"><span class="policy-icon">' . palette_icon($icon, 18) . '</span><div>'
            . '<h2>' . $t('terms_' . $k . '_h') . '</h2><p>' . $body . '</p></div></section>';
    }
    $main = '<main class="wrap narrow"><article class="policy">'
        . '<div class="page-head"><h1>' . $t('terms_title') . '</h1><p class="lead">' . $t('terms_intro') . '</p></div>'
        . '<div class="card policy-list">' . $sections . '</div>'
        . '<p class="updated">' . palette_t_html($lang, 'priv_updated', ['date' => palette_ltr(PALETTE_TERMS_UPDATED)]) . '</p>'
        . '</article></main>';
    return palette_page_shell('terms', $lang, [], palette_t($lang, 'terms_title') . ' | Site to Design Token', $main);
}

function palette_render_checkout(array $ctx): string
{
    $conn = $GLOBALS['conn'];
    $query = is_array($ctx['query'] ?? null) ? $ctx['query'] : [];
    $lang = palette_page_lang($query, (string)($ctx['accept_language'] ?? ''));
    $keep = array_intersect_key(palette_page_keep($query), ['ext' => 1]);
    if (in_array($query['plan'] ?? null, ['ai', 'pro'], true)) $keep['plan'] = $query['plan'];
    $t = fn(string $k) => palette_h(palette_t($lang, $k));

    $settings = palette_settings($conn);
    $money = palette_money_settings($settings);
    $pp = pp_settings($conn);
    $ready = pp_ready($pp);
    $aiOn = palette_ai_plan_ready($settings, $pp);
    $plan = palette_page_plan($query, $aiOn);
    $isAi = $plan === 'ai';
    $ca = $money['tax_mode'] === 'ca';
    $fr = $lang === 'fr';
    $usd = fn(string $amount): string => palette_usd($lang, $amount);
    $price = $usd(palette_money($money['price']));
    $aiPrice = $usd(palette_money(palette_ai_sale_cents($settings)));
    $withTax = palette_total($settings, 'US', 'CA');
    $shown = $isAi ? $aiPrice : $price;

    // Pro + AI is a subscription: no sales-tax lines for it (the selects and the CA row only apply to Pro).
    $summary = '<div class="summary">'
        . '<div class="sum-row"><span id="sum-item">' . $t($isAi ? 'co_item_ai' : 'co_item') . '</span><span class="num" dir="ltr" id="sum-price">' . $shown . '</span></div>'
        . ($ca ? '<div class="sum-row sum-tax" id="tax-row" hidden><span>' . $t('co_tax') . '</span><span class="num" dir="ltr">' . palette_h($usd($withTax['tax'])) . '</span></div>' : '')
        . '<div class="sum-rule"></div>'
        . '<div class="sum-row sum-total"><span>' . $t('co_total') . '</span><span class="num" dir="ltr"><span id="total">' . $shown . '</span>' . ($fr ? '' : ' USD') . '</span></div>'
        . ($ca ? '<p class="sum-note" id="sum-note"' . ($isAi ? ' hidden' : '') . '>' . $t('co_tax_note') . '</p>' : '')
        . '</div>';

    $hero = '<section class="hero">'
        . '<span class="eyebrow">' . $t('co_eyebrow') . '</span>'
        . '<h1>' . $t('co_h1') . '</h1>'
        . '<p class="lead">' . $t('co_lead') . '</p>'
        . palette_section_hero_actions($lang, $price, '#buy')
        . $summary
        . '<ul class="trust">'
        . '<li>' . palette_icon('lock', 15) . $t('co_trust_paypal') . '</li>'
        . '<li>' . palette_icon('browser', 15) . $t('co_trust_browsers') . '</li>'
        . '</ul></section>';

    $haveKey = '<section class="pp-section" id="have-key" aria-labelledby="have-key-h"><h2 id="have-key-h">' . palette_icon('key', 18) . $t('pp_key_h') . '</h2>'
        . '<ol class="steps steps-short"><li>' . $t('pp_key_1') . '</li><li>' . $t('pp_key_2') . '</li></ol>'
        . '<p class="pp-aside">' . $t('pp_key_email') . ' <a href="#resend">' . $t('pp_key_lost') . '</a></p></section>';

    $pitch = '<div class="pitch">' . $hero . palette_section_features($lang) . palette_section_plans($lang, $price)
        . palette_section_install($lang) . $haveKey . '</div>';

    $tax = '';
    if ($ca) {
        $states = '<option value="">' . $t('co_region_pick') . '</option>';
        foreach (PALETTE_US_STATES as $code => $name) $states .= '<option value="' . $code . '">' . palette_h($name) . '</option>';
        $tax = '<div class="field-row" id="tax-fields"' . ($isAi ? ' hidden' : '') . '>'
            . '<div class="field"><label for="country">' . $t('co_country') . '</label>'
            . '<select id="country" name="country"><option value="US">' . $t('co_country_us') . '</option>'
            . '<option value="ZZ" selected>' . $t('co_country_other') . '</option></select></div>'
            . '<div class="field" id="region-wrap" hidden><label for="region">' . $t('co_region') . '</label>'
            . '<select id="region" name="region">' . $states . '</select></div>'
            . '</div>';
    }

    $pay = $ready
        ? '<div id="paypal" class="paypal is-locked" aria-busy="true"><div class="pp-loading"><span class="spinner" aria-hidden="true"></span>' . $t('co_loading') . '</div></div>'
          . '<p class="hint hint-center" id="pay-note">' . $t($isAi ? 'co_note_ai' : 'co_card_note') . '</p>'
        : '<div class="notice" role="status">' . palette_icon('alert', 18) . '<span>' . $t('err_paused') . '</span></div>';

    $form = '<form id="buy" class="buy" novalidate>'
        . '<h2 id="buy-title">' . $t('co_form_title') . '</h2>'
        . '<div class="field"><span class="field-label" id="plan-h">' . $t('co_plan_h') . '</span>'
        . palette_plan_cards($lang, $plan, $aiOn, $price, $aiPrice, palette_usage_limit($settings)) . '</div>'
        . '<div class="field"><label for="email">' . $t('co_email_label') . '</label>'
        . '<input type="email" id="email" name="email" autocomplete="email" inputmode="email" spellcheck="false" maxlength="190" required aria-describedby="email-hint">'
        . '<p class="hint" id="email-hint">' . $t('co_email_hint') . '</p></div>'
        . $tax
        . '<div class="terms"><p class="refund" id="refund">' . $t($isAi ? 'co_refund_ai' : 'co_refund') . '</p>'
        . '<label class="consent" for="consent"><input type="checkbox" id="consent" name="consent" value="1" required>'
        . '<span>' . $t('co_consent') . '</span></label></div>'
        . '<p class="error" id="buy-error" role="alert" hidden></p>'
        . '<p class="pending" id="pending" role="status" hidden>' . palette_icon('check', 18) . '<span id="pending-text"></span></p>'
        . $pay
        . '</form>'
        . '<div class="busy" id="busy" role="status" hidden><span class="spinner" aria-hidden="true"></span>' . $t('co_finishing') . '</div>';

    $done = '<div id="done" class="done" hidden>'
        . '<div class="done-badge">' . palette_icon('check', 22) . '</div>'
        . '<h2 id="done-title" tabindex="-1">' . $t($isAi ? 'co_success_title_ai' : 'co_success_title') . '</h2>'
        . '<div class="keybox"><code id="key" dir="ltr" aria-label="' . $t('co_key_label') . '"></code>'
        . '<button type="button" id="copy-key" class="btn btn-accent" data-copy-from="key">' . palette_icon('copy', 15) . '<span>' . $t('co_copy') . '</span></button></div>'
        . '<p class="copy-status" id="copy-status" role="status"></p>'
        . '<p class="ext-status" id="ext-status" role="status"></p>'
        . '<p class="emailed" id="emailed"></p>'
        . '<div class="linkbox"><span>' . $t('co_account_note') . '</span>'
        . '<a id="account-link" class="btn btn-quiet" href="' . palette_h(palette_page_url('account', $lang)) . '">' . palette_icon('user', 15) . '<span>' . $t('co_account_link') . '</span></a></div>'
        . '</div>';

    $lost = '<form id="resend" class="lost" novalidate>'
        . '<h2>' . palette_icon('mail', 16) . $t('co_lost_title') . '</h2>'
        . '<p>' . $t('co_lost_text') . '</p>'
        . '<div class="inline"><label class="sr-only" for="resend-email">' . $t('co_email_label') . '</label>'
        . '<input type="email" id="resend-email" name="email" autocomplete="email" inputmode="email" spellcheck="false" maxlength="190" required>'
        . '<button type="submit" class="btn btn-quiet">' . $t('co_lost_button') . '</button></div>'
        . '<p class="lost-status" id="resend-status" role="status"></p>'
        . '</form>';

    $main = '<main class="wrap checkout">' . $pitch
        . '<div class="side"><section class="card buy-card" aria-labelledby="buy-title">' . $form . $done . '</section>'
        . $lost . '</div></main>';

    $strings = [];
    foreach (PALETTE_SCRIPT_STRINGS as $k) $strings[$k] = palette_t($lang, $k);
    return palette_page_shell('checkout', $lang, $keep, palette_t($lang, 'co_title'), $main,
        ['t' => $strings, 'plan' => $plan, 'ai_enabled' => $aiOn, 'total' => $price, 'total_ca' => $usd($withTax['amount']), 'total_ai' => $aiPrice]);
}

function palette_session_user(array $session): int
{
    $uid = $session['user_id'] ?? null;
    return (is_int($uid) || (is_string($uid) && ctype_digit($uid))) && (int)$uid > 0 ? (int)$uid : 0;
}

function palette_render_account(array $ctx): string
{
    $conn = $GLOBALS['conn'];
    $query = is_array($ctx['query'] ?? null) ? $ctx['query'] : [];
    $lang = palette_page_lang($query, (string)($ctx['accept_language'] ?? ''));
    $keep = palette_page_keep($query);
    $t = fn(string $k) => palette_h(palette_t($lang, $k));
    $session = is_array($ctx['session'] ?? null) ? $ctx['session'] : [];
    $uid = palette_session_user($session);

    $head = '<div class="page-head"><h1>' . $t('acct_title') . '</h1><p class="lead">' . $t('acct_lead') . '</p></div>';
    if ($uid === 0) {
        // The key waits in the session, not in the login redirect URL (companies pages carry analytics).
        if (isset($keep['key'])) $_SESSION['palette_pending_key'] = $keep['key'];
        $login = '/companies/?auth=login&redirect=' . PALETTE_BASE . '/account';
        $body = '<section class="card signin">'
            . '<div class="signin-icon">' . palette_icon('user', 22) . '</div>'
            . '<p>' . $t('acct_signin_text') . '</p>'
            . '<a class="btn btn-accent btn-lg" href="' . palette_h($login) . '">' . $t('acct_signin') . palette_icon('arrow', 16) . '</a>'
            . '</section>';
    } else {
        $rows = '';
        foreach (palette_keys_for_user($conn, $uid) as $k) {
            $rows .= '<li class="keyrow' . ($k['revoked'] ? ' is-revoked' : '') . '" data-key="' . palette_h($k['key']) . '">'
                . '<div class="keyrow-main"><code dir="ltr">' . palette_h($k['key']) . '</code>'
                . '<span class="keyrow-meta"><span dir="ltr">' . palette_h($k['email_masked']) . '</span>'
                . '<span>' . palette_t_html($lang, 'acct_bought', ['date' => palette_ltr(substr((string)$k['created_at'], 0, 10))]) . '</span>'
                . ($k['revoked'] ? '<span class="badge">' . $t('acct_revoked') . '</span>' : '') . '</span>'
                . palette_account_plan_line($lang, $k) . '</div>'
                . '<button type="button" class="btn btn-quiet btn-sm" data-copy="' . palette_h($k['key']) . '">' . palette_icon('copy', 14) . '<span>' . $t('co_copy') . '</span></button>'
                . palette_account_cancel_panel($lang, $k)
                . '</li>';
        }
        $pending = is_string($session['palette_pending_key'] ?? null) ? palette_normalise_key($session['palette_pending_key']) : null;
        unset($_SESSION['palette_pending_key']);
        $prefill = is_string($query['key'] ?? null) ? mb_substr(trim($query['key']), 0, 40) : ($pending ?? '');
        $body = '<section class="card">' . ($rows !== '' ? '<ul class="keys">' . $rows . '</ul><p class="copy-status" id="copy-status" role="status"></p>' : '<p class="empty">' . palette_icon('key', 18) . $t('acct_none') . '</p>') . '</section>'
            . '<form id="claim" class="card claim" novalidate>'
            . '<h2>' . $t('acct_add_title') . '</h2>'
            . '<div class="inline"><label class="sr-only" for="claim-key">' . $t('acct_key_label') . '</label>'
            . '<input type="text" id="claim-key" name="key" value="' . palette_h($prefill) . '" placeholder="SDT-XXXX-XXXX-XXXX" dir="ltr" autocomplete="off" spellcheck="false" maxlength="40" required>'
            . '<button type="submit" class="btn btn-accent">' . $t('acct_add_button') . '</button></div>'
            . '<p class="claim-status" id="claim-status" role="status"></p>'
            . '</form>';
    }
    // Cancel by key: for a subscriber who is not signed in (key + the subscribing email); signed-in owners can use it too.
    $body .= '<form id="cancel-by" class="card claim" novalidate>'
        . '<h2>' . $t('acct_cancel_by_h') . '</h2><p class="hint">' . $t('acct_cancel_by_text') . '</p>'
        . '<div class="inline"><label class="sr-only" for="cancel-key">' . $t('acct_key_label') . '</label>'
        . '<input type="text" id="cancel-key" name="key" placeholder="SDT-XXXX-XXXX-XXXX" dir="ltr" autocomplete="off" spellcheck="false" maxlength="40" required>'
        . '<label class="sr-only" for="cancel-email">' . $t('acct_sub_email') . '</label>'
        . '<input type="email" id="cancel-email" name="email" placeholder="' . $t('acct_sub_email') . '" autocomplete="email" inputmode="email" spellcheck="false" maxlength="190" required>'
        . '<button type="submit" class="btn btn-quiet">' . $t('acct_cancel') . '</button></div>'
        . '<p class="claim-status" id="cancel-by-status" role="status"></p></form>';
    $body .= '<p class="aside"><a href="' . palette_h(palette_page_url('checkout', $lang)) . '">' . $t('acct_buy') . palette_icon('arrow', 14) . '</a></p>';

    $strings = [];
    foreach (PALETTE_SCRIPT_STRINGS as $k) $strings[$k] = palette_t($lang, $k);
    return palette_page_shell('account', $lang, $keep, palette_t($lang, 'acct_title') . ' | Site to Design Token',
        '<main class="wrap narrow">' . $head . $body . '</main>', ['t' => $strings, 'csrf' => palette_account_csrf_token()]);
}

// What a stored subscription state means right now: revoked|active|cancelled|suspended|expired. Revoked (refunded) is
// checked first: its sub_status may still say active when the post-refund PayPal cancel failed. A lapsed paid_through
// is expired whatever PayPal's last word was; cancelled keeps access until paid_through.
function palette_account_sub_state(array $k): string
{
    if (!empty($k['revoked'])) return 'revoked';
    $pt = is_string($k['paid_through'] ?? null) ? strtotime($k['paid_through']) : false;
    if ($pt === false || $pt < time()) return 'expired';
    return in_array($k['sub_status'], ['active', 'cancelled', 'suspended'], true) ? $k['sub_status'] : 'expired';
}

function palette_account_plan_line(string $lang, array $k): string
{
    $chip = fn(string $name) => '<span class="plan-chip">' . palette_h($name) . '</span>';
    if ($k['plan'] !== 'ai') return '<span class="keyrow-plan">' . $chip('Pro') . '<span>' . palette_h(palette_t($lang, 'acct_one_time')) . '</span></span>';
    $state = palette_account_sub_state($k);
    $date = palette_ltr(substr((string)$k['paid_through'], 0, 10));
    $status = $state === 'cancelled' ? palette_t_html($lang, 'acct_st_cancelled', ['date' => $date]) : palette_h(palette_t($lang, 'acct_st_' . $state));
    return '<span class="keyrow-plan">' . $chip('Pro + AI') . '<span class="sub-status is-' . $state . '" data-sub-status>' . $status . '</span>'
        . ($state === 'active' ? '<span data-sub-renews>' . palette_t_html($lang, 'acct_renews', ['date' => $date]) . '</span>' : '') . '</span>';
}

// Cancel button + in-page confirm step (no window.confirm); only for a live subscription that is not already cancelled.
function palette_account_cancel_panel(string $lang, array $k): string
{
    if ($k['revoked'] || $k['plan'] !== 'ai' || !in_array(palette_account_sub_state($k), ['active', 'suspended'], true)) return '';
    $t = fn(string $key) => palette_h(palette_t($lang, $key));
    $date = substr((string)$k['paid_through'], 0, 10);
    return '<div class="sub-panel">'
        . '<button type="button" class="btn btn-quiet btn-sm" data-cancel-open>' . $t('acct_cancel') . '</button>'
        . '<div class="cancel-confirm" role="group" aria-label="' . $t('acct_cancel') . '" hidden>'
        . '<p>' . palette_t_html($lang, 'acct_cancel_ask', ['date' => palette_ltr($date)]) . '</p>'
        . '<div class="cancel-actions"><button type="button" class="btn btn-danger btn-sm" data-cancel-yes>' . $t('acct_cancel_yes') . '</button>'
        . '<button type="button" class="btn btn-quiet btn-sm" data-cancel-keep>' . $t('acct_cancel_keep') . '</button></div></div>'
        . '<p class="sub-msg" role="status"></p></div>';
}

function palette_render_privacy(array $ctx): string
{
    $query = is_array($ctx['query'] ?? null) ? $ctx['query'] : [];
    $lang = palette_page_lang($query, (string)($ctx['accept_language'] ?? ''));
    $t = fn(string $k) => palette_h(palette_t($lang, $k));

    $sections = '';
    foreach (['reads' => 'eye', 'stores' => 'hdd', 'sends' => 'send', 'ai' => 'spark', 'server' => 'globe', 'pay' => 'card', 'email' => 'mail', 'track' => 'shield'] as $k => $icon) {
        $sections .= '<section class="policy-item"><span class="policy-icon">' . palette_icon($icon, 18) . '</span><div>'
            . '<h2>' . $t('priv_' . $k . '_h') . '</h2><p>' . $t('priv_' . $k) . '</p></div></section>';
    }
    $mail = '<a href="mailto:' . PALETTE_SUPPORT_EMAIL . '" dir="ltr">' . PALETTE_SUPPORT_EMAIL . '</a>';
    $sections .= '<section class="policy-item"><span class="policy-icon">' . palette_icon('chat', 18) . '</span><div>'
        . '<h2>' . $t('priv_contact_h') . '</h2><p>' . palette_t_html($lang, 'priv_contact', ['email' => $mail]) . '</p></div></section>';

    $main = '<main class="wrap narrow"><article class="policy">'
        . '<div class="page-head"><h1>' . $t('priv_title') . '</h1><p class="lead">' . $t('priv_intro') . '</p></div>'
        . '<div class="card policy-list">' . $sections . '</div>'
        . '<p class="updated">' . palette_t_html($lang, 'priv_updated', ['date' => palette_ltr(PALETTE_PRIVACY_UPDATED)]) . '</p>'
        . '</article></main>';
    return palette_page_shell('privacy', $lang, [], palette_t($lang, 'priv_title') . ' | Site to Design Token', $main);
}
