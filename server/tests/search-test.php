<?php
// Web search adapter (Brave/Tavily) and host normalisation. HTTP is stubbed via $GLOBALS['pp_http'].
require __DIR__ . '/helpers.php';

$log = tempnam(sys_get_temp_dir(), 'sl');   // keeps expected error_log lines out of the test output
ini_set('error_log', $log);
$calls = [];
$reply = ['status' => 200, 'json' => []];
$GLOBALS['pp_http'] = function ($method, $url, $headers, $body) use (&$calls, &$reply) {
    $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
    return $reply;
};

// ---- Brave
$reply = ['status' => 200, 'json' => ['web' => ['results' => [
    ['title' => 'Ridge', 'url' => 'https://www.ridge.example/a?x=1', 'description' => 'Wallets'],
    ['title' => 'Ridge again', 'url' => 'https://ridge.example/b', 'description' => 'dup host'],
    ['title' => 'Local', 'url' => 'http://intranet.local/', 'description' => 'dropped'],
    ['title' => 'Other', 'url' => 'https://other.example/', 'description' => 'Other site'],
]]]];
$s = ['search_provider' => 'brave', 'search_key' => 'SECRET-KEY'];
$r = palette_search($s, 'wallets & más/ü');
ok('brave: one GET call', count($calls) === 1 && $calls[0]['method'] === 'GET');
ok('brave: url and urlencoded query', $calls[0]['url'] === 'https://api.search.brave.com/res/v1/web/search?q=' . rawurlencode('wallets & más/ü') . '&count=8'
    || $calls[0]['url'] === 'https://api.search.brave.com/res/v1/web/search?q=' . urlencode('wallets & más/ü') . '&count=8');
ok('brave: Accept header', in_array('Accept: application/json', $calls[0]['headers'], true));
ok('brave: token header', in_array('X-Subscription-Token: SECRET-KEY', $calls[0]['headers'], true));
ok('brave: no body', $calls[0]['body'] === null);
ok('brave: parsed, null host dropped, duplicate host keeps first', is_array($r) && count($r) === 2
    && $r[0] === ['title' => 'Ridge', 'url' => 'https://www.ridge.example/a?x=1', 'snippet' => 'Wallets', 'host' => 'ridge.example']
    && $r[1]['host'] === 'other.example');

// max 8
$many = [];
for ($i = 0; $i < 12; $i++) $many[] = ['title' => "t$i", 'url' => "https://h$i.example/", 'description' => 'd'];
$reply = ['status' => 200, 'json' => ['web' => ['results' => $many]]];
ok('max 8 results', count(palette_search($s, 'q')) === 8);

// ---- Tavily
$calls = [];
$reply = ['status' => 200, 'json' => ['results' => [
    ['title' => 'T1', 'url' => 'https://tav.example/x', 'content' => 'body one'],
    ['title' => 'T2', 'url' => 'https://www.google.com/url?q=https://ridge.example/x', 'content' => 'body two'],
]]];
$r = palette_search(['search_provider' => 'tavily', 'search_key' => 'TKEY'], 'rugs');
ok('tavily: one POST to /search', count($calls) === 1 && $calls[0]['method'] === 'POST' && $calls[0]['url'] === 'https://api.tavily.com/search');
$b = json_decode((string)$calls[0]['body'], true);
ok('tavily: json body', $b === ['api_key' => 'TKEY', 'query' => 'rugs', 'max_results' => 8]);
ok('tavily: content-type header', in_array('Content-Type: application/json', $calls[0]['headers'], true));
ok('tavily: parsed with snippet from content and unwrapped host', is_array($r) && count($r) === 2
    && $r[0]['snippet'] === 'body one' && $r[0]['host'] === 'tav.example' && $r[1]['host'] === 'ridge.example');

// ---- unavailable
$calls = [];
ok('no key -> null', palette_search(['search_provider' => 'brave', 'search_key' => ''], 'q') === null);
ok('no key -> no HTTP call', $calls === []);
ok('unknown provider -> null, no call', palette_search(['search_provider' => 'nope', 'search_key' => 'k'], 'q') === null && $calls === []);
ok('missing provider defaults to brave', (function () use (&$calls, &$reply) {
    $reply = ['status' => 200, 'json' => ['web' => ['results' => []]]];
    $r = palette_search(['search_key' => 'k'], 'q');
    return $r === [] && str_contains($calls[0]['url'], 'brave.com');
})());
$reply = ['status' => 500, 'json' => ['message' => 'boom']];
ok('500 -> null', palette_search($s, 'q') === null);
$reply = ['status' => 0, 'json' => ['message' => 'timeout']];
ok('timeout (status 0) -> null', palette_search($s, 'q') === null);
$reply = ['status' => 200, 'json' => null];
ok('non-JSON 200 -> null', palette_search($s, 'q') === null);
$reply = ['status' => 200, 'json' => ['web' => ['results' => [['title' => 'x']]]]];
ok('malformed result entries are dropped', palette_search($s, 'q') === []);
ok('empty query -> null', palette_search($s, '   ') === null);
$reply = ['status' => 200, 'json' => ['query' => ['original' => 'q'], 'type' => 'search']];
ok('brave 2xx without web key -> [] (no results, not unavailable)', palette_search($s, 'q') === []);
$calls = [];
$reply = ['status' => 200, 'json' => ['web' => ['results' => []]]];
palette_search($s, str_repeat('é', 250));
parse_str((string)parse_url($calls[0]['url'], PHP_URL_QUERY), $qs);
ok('query capped at 200 characters', ($qs['q'] ?? '') === str_repeat('é', 200));

// secret never logged
file_put_contents($log, "");
$reply = ['status' => 500, 'json' => ['message' => 'SECRET-KEY echoed']];
palette_search($s, 'q');
$logged = (string)file_get_contents($log);
@unlink($log);
ok('error_log names provider and status, never the key', str_contains($logged, 'brave') && str_contains($logged, '500') && !str_contains($logged, 'SECRET-KEY'));
unset($GLOBALS['pp_http']);

// ---- palette_host
$cases = [
    ['https://WWW.Example.com/a?utm=1', 'example.com'],
    ['https://www.google.com/url?q=https://ridge.example/x', 'ridge.example'],
    ['https://www.google.co.uk/url?url=https%3A%2F%2Fridge.example%2Fx', 'ridge.example'],
    ['https://www.bing.com/ck/a?u=https%3A%2F%2Fbing-target.example%2F', 'bing-target.example'],
    ['https://duckduckgo.com/l/?uddg=https%3A%2F%2Fddg.example%2Fp', 'ddg.example'],
    ['https://l.facebook.com/l.php?u=https%3A%2F%2Ffb.example%2F', 'fb.example'],
    ['https://t.co/abc?url=https://tco.example/', 'tco.example'],
    ['https://www.google.com/url?q=not-a-url', 'google.com'],
    ['https://www.google.com/url?q=https://www.google.com/url?q=https://deep.example/', 'google.com'],
    ['http://127.0.0.1', null],
    ['http://127.0.0.1:8080/x', null],
    ['http://[::1]/', null],
    ['http://[2001:db8::1]:443/', null],
    ['http://localhost/', null],
    ['http://localhost:3000/', null],
    ['http://intranet.local', null],
    ['http://a.localhost', null],
    ['http://svc.internal', null],
    ['http://foo.test', null],
    ['http://intranet', null],
    ['http://0x7f.0x0.0x0.0x1/', null],
    ['http://foo.0x7f000001/', null],
    ['http://0177.0.0.01/', null],
    ['http://shop.0xabc/', null],
    ['https://0x.example/', '0x.example'],
    ['ftp://example.com/', null],
    ['javascript:alert(1)', null],
    ['example.com', null],
    ['', null],
    ['https://example.com:8443/x', 'example.com'],
    ['https://m.example.com/', 'm.example.com'],
    ['https://Shop.Example.COM./', 'shop.example.com'],
    ['https://xn--bcher-kva.example/', 'xn--bcher-kva.example'],
    ['https://user:pw@example.com/', 'example.com'],
    ['https://www.google.com/url?q=http://127.0.0.1/', null],
    ['https://www.google.com/url?q=https://deep.example.local/', null],
    ['https://notgoogle.com/url?q=https://x.example/', 'notgoogle.com'],
];
foreach ($cases as [$in, $want]) ok('palette_host(' . $in . ') = ' . var_export($want, true), palette_host($in) === $want);

// ---- palette_http
$stubbed = false;
$GLOBALS['pp_http'] = function () use (&$stubbed) { $stubbed = true; return ['status' => 204, 'json' => null]; };
$r = palette_http('GET', 'http://example.invalid/', [], null, 2);
ok('palette_http delegates to the pp_http stub when set', $stubbed && $r['status'] === 204);
unset($GLOBALS['pp_http']);
$t = microtime(true);
$r = palette_http('GET', 'http://10.255.255.1/', [], null, 2);
$el = microtime(true) - $t;
ok('palette_http: unroutable address returns status 0 within the timeout', $r['status'] === 0 && $el < 4);
