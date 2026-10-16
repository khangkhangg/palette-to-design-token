<?php
const PALETTE_LANGS = ['en', 'zh-CN', 'hi', 'es', 'ar', 'fr', 'bn'];

function palette_lang(string $lang): string
{
    return in_array($lang, PALETTE_LANGS, true) ? $lang : 'en';
}

function palette_strings(string $lang): array
{
    static $cache = [];
    $lang = palette_lang($lang);
    return $cache[$lang] ??= (array)require PALETTE_DIR . '/lib/i18n/' . $lang . '.php';
}

// Looks up $key in $lang, falls back to en, then to the key itself. {name} placeholders are replaced from $vars.
function palette_t(string $lang, string $key, array $vars = []): string
{
    $s = palette_strings($lang)[$key] ?? palette_strings('en')[$key] ?? $key;
    foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', (string)$v, $s);
    return $s;
}
