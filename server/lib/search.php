<?php
// Web search for the AI insights add-on (Brave or Tavily) and host normalisation.
// HTTP goes through companies' pp_http (stubbable via $GLOBALS['pp_http']); it takes no timeout
// argument, so the search call uses pp_http's own default timeout. search_key is a secret: never logged.

const PALETTE_SEARCH_MAX = 8;

// [['title','url','snippet','host'] ...] (max 8, first result per host), or null when the provider
// is unavailable: no key, unknown provider, empty query, transport failure/timeout, non-2xx or non-JSON reply.
function palette_search(array $settings, string $q): ?array
{
    $q = trim($q);
    $key = trim((string)($settings['search_key'] ?? ''));
    $provider = (string)($settings['search_provider'] ?? '');
    if ($provider === '') $provider = 'brave';
    if ($q === '' || $key === '' || !in_array($provider, ['brave', 'tavily'], true)) return null;

    if ($provider === 'brave') {
        $r = pp_http('GET', 'https://api.search.brave.com/res/v1/web/search?q=' . rawurlencode($q) . '&count=' . PALETTE_SEARCH_MAX,
                     ['Accept: application/json', 'X-Subscription-Token: ' . $key], null);
        $rows = $r['json']['web']['results'] ?? null;
        $snippetField = 'description';
    } else {
        $r = pp_http('POST', 'https://api.tavily.com/search',
                     ['Content-Type: application/json', 'Accept: application/json'],
                     json_encode(['api_key' => $key, 'query' => $q, 'max_results' => PALETTE_SEARCH_MAX], JSON_UNESCAPED_UNICODE));
        $rows = $r['json']['results'] ?? null;
        $snippetField = 'content';
    }
    $status = (int)($r['status'] ?? 0);
    if ($status < 200 || $status >= 300 || !is_array($rows)) {
        error_log('palette_search: ' . $provider . ' returned HTTP ' . $status);   // provider + status only, never the key
        return null;
    }

    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !is_string($row['url'] ?? null)) continue;
        $host = palette_host($row['url']);
        if ($host === null || isset($seen[$host])) continue;
        $seen[$host] = true;
        $out[] = ['title' => is_string($row['title'] ?? null) ? $row['title'] : '', 'url' => $row['url'],
                  'snippet' => is_string($row[$snippetField] ?? null) ? $row[$snippetField] : '', 'host' => $host];
        if (count($out) >= PALETTE_SEARCH_MAX) break;
    }
    return $out;
}

// Lowercased host without port, leading www. and trailing dot; null for anything that is not a public
// http(s) site. Search-engine/social redirect wrappers (q|url|u|uddg holding an http(s) URL) are unwrapped
// one level. Punycode is left as given; there is no public-suffix list, so m.example.com stays as is.
function palette_host(string $url): ?string
{
    $host = palette_host_of($url);
    if ($host === null) return null;
    if (palette_is_redirector($host)) {
        $p = parse_url(trim($url));
        parse_str((string)($p['query'] ?? ''), $params);
        foreach (['q', 'url', 'u', 'uddg'] as $k) {
            $v = $params[$k] ?? null;
            if (is_string($v) && preg_match('~^https?://~i', $v)) {
                return palette_host_of($v);   // one level only; an invalid target is invalid
            }
        }
    }
    return $host;
}

function palette_host_of(string $url): ?string
{
    $p = parse_url(trim($url));
    if (!is_array($p) || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true)) return null;
    $h = strtolower(rtrim((string)($p['host'] ?? ''), '.'));
    if ($h === '' || $h[0] === '[' || filter_var($h, FILTER_VALIDATE_IP)) return null;   // IPv6 / IPv4 literals
    if (str_starts_with($h, 'www.')) $h = substr($h, 4);
    if (!str_contains($h, '.') || !preg_match('/^[a-z0-9._-]+$/', $h) || preg_match('/(^|\.)\.|\.\./', $h)) return null;
    if (preg_match('/\.(\d+)$/', $h)) return null;                                      // numeric TLD: an obfuscated IP
    if ($h === 'localhost' || preg_match('/\.(local|localhost|internal|test)$/', $h)) return null;
    return $h;
}

function palette_is_redirector(string $host): bool
{
    return (bool)preg_match('/^google\.[a-z]{2,3}(\.[a-z]{2})?$/', $host)
        || in_array($host, ['bing.com', 'duckduckgo.com', 'l.facebook.com', 't.co'], true);
}
