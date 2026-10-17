import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { analyse, designSummary, publicPage, storedResult, type AiResult } from '../../src/ai';
import type { ColourToken, ContrastPair, Tokens } from '../../src/model/tokens';
import { EMPTY_TOKENS } from '../../src/model/tokens';
import { installFakeChrome, uninstallFakeChrome } from './fake-chrome';

const c = (role: ColourToken['role'], name: string, hex: string): ColourToken => ({ role, name, hex, weight: 0.1, interactive: false });
const pair = (fg: string, bg: string, ratio: number | null, grade: ContrastPair['grade']): ContrastPair =>
  ({ fg, bg, ratio, grade, large: false, uses: 1, where: 'body text' });

const tokens = (over: Partial<Tokens> = {}): Tokens => ({
  ...EMPTY_TOKENS('www.Brewline.co'),
  colours: [c('primary', 'primary', '#1F5E4B'), c('accent', 'accent', '#E0763A'), c('surface', 'surface', '#F3EEE4')],
  fonts: [{ name: 'heading', family: '"Fraunces", serif' }, { name: 'body', family: 'DM Sans, sans-serif' }],
  type: [
    { name: 'h1', family: 'Fraunces', sizePx: 48, weight: 600, lineHeightPx: 56, chars: 20 },
    { name: 'body', family: 'DM Sans', sizePx: 16, weight: 400, lineHeightPx: 26, chars: 900 },
    { name: 'small', family: 'DM Sans', sizePx: 16, weight: 600, lineHeightPx: 22, chars: 90 },
  ],
  spacing: { base: 4, scale: [4, 8, 16] },
  radius: [4, 10, 9999],
  contrast: [pair('#FFFFFF', '#E0763A', 2.9, 'fail'), pair('#1B1A17', '#F3EEE4', 14.1, 'AAA'), pair('#5D6270', '#FFFFFF', 4.6, 'AA')],
  page: { url: 'https://www.brewline.co/menu', title: 'Brewline — Specialty coffee', description: 'Small-batch roasts, shipped weekly.' },
  ...over,
});

const RESULT: AiResult = {
  sector: 'Specialty coffee', tags: ['Online shop'], verdict: 'competitive', summary: 'Calm and premium.',
  colours: [{ hex: '#1f5e4b', title: 'Deep green', meaning: 'Trust.' }],
  fit: { level: 'strong', reason: 'Matches the sector.' }, works: ['Pairing'], improve: ['Contrast'],
  sites: [{ name: 'Ridge', domain: 'ridge.example', url: 'https://ridge.example/', why: 'Same sector', relation: 'competitor', source: 'web' }],
  search_unavailable: false,
};
const USAGE = { used: 38, limit: 100, resets_on: '2026-11-01' };
const AI_LICENSE = {
  key: 'SDT-ABCD-2345-6789', verifiedAt: '2026-10-17T00:00:00.000Z', emailMasked: null, plan: 'ai',
  paidThrough: '2026-11-16T00:00:00.000Z', usage: { used: 30, limit: 100, resetsOn: '2026-11-01' },
  subStatus: 'active', lastCheck: 'ok', lastAttemptAt: null,
};

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
let fetchMock: ReturnType<typeof vi.fn>;
let data: Record<string, unknown>;

beforeEach(() => {
  ({ data } = installFakeChrome());
  data.licenseAi = structuredClone(AI_LICENSE);
  fetchMock = vi.fn(async () => json(200, { ok: true, result: RESULT, cached: false, usage: USAGE }));
  vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => { vi.unstubAllGlobals(); vi.useRealTimers(); uninstallFakeChrome(); });

const page = (t: Tokens = tokens()) => ({ url: t.page!.url, title: t.page!.title, description: t.page!.description });
const sentBody = () => JSON.parse(fetchMock.mock.calls[0][1].body as string);

describe('designSummary', () => {
  it('keeps role colours, families, type sizes, spacing, radius and contrast within the caps', () => {
    expect(designSummary(tokens())).toEqual({
      colours: [{ role: 'primary', hex: '#1F5E4B' }, { role: 'accent', hex: '#E0763A' }, { role: 'surface', hex: '#F3EEE4' }],
      fonts: ['Fraunces', 'DM Sans'],
      type_sizes: [48, 16],
      spacing_base: 4,
      radius: [4, 10, 9999],
      contrast: { pass: 2, fail: 1, failing: [{ fg: '#FFFFFF', bg: '#E0763A', ratio: 2.9 }] },
    });
  });

  it('caps every list (10 colours, 3 fonts, 12 sizes, 6 radii, 6 failing pairs)', () => {
    const many = tokens({
      colours: Array.from({ length: 14 }, (_, i) => c('other', `c${i}`, `#0000${(i + 16).toString(16)}`)),
      fonts: ['A', 'B', 'C', 'D'].map((f) => ({ name: 'body' as const, family: f })),
      type: Array.from({ length: 20 }, (_, i) => ({ name: `t${i}`, family: 'A', sizePx: 10 + i, weight: 400, lineHeightPx: null, chars: 1 })),
      radius: [1, 2, 3, 4, 5, 6, 7, 8],
      contrast: Array.from({ length: 9 }, (_, i) => pair('#FFFFFF', `#00000${i}`, 1.5, 'fail')),
    });
    const s = designSummary(many);
    expect(s.colours).toHaveLength(10);
    expect(s.fonts).toEqual(['A', 'B', 'C']);
    expect(s.type_sizes).toHaveLength(12);
    expect(s.type_sizes[0]).toBe(29);
    expect(s.radius).toEqual([1, 2, 3, 4, 5, 6]);
    expect(s.contrast.failing).toHaveLength(6);
    expect(s.contrast.fail).toBe(9);
  });

  it('never includes page text, the site or the scan time', () => {
    const s = JSON.stringify(designSummary(tokens()));
    for (const leak of ['Brewline', 'brewline', 'Small-batch', 'menu', 'scannedAt', 'site']) expect(s).not.toContain(leak);
  });

  it('skips failing pairs with an image behind them', () => {
    const s = designSummary(tokens({ contrast: [pair('#FFFFFF', 'none', null, 'fail')] }));
    expect(s.contrast).toEqual({ pass: 0, fail: 1, failing: [] });
  });
});

describe('publicPage', () => {
  it('strips the query string and fragment, and gives the host without www.', () => {
    expect(publicPage('https://www.Brewline.co/menu?utm_source=x&id=7#top')).toEqual({ url: 'https://www.brewline.co/menu', host: 'brewline.co' });
    expect(publicPage('http://brewline.example:4517/shop/')).toEqual({ url: 'http://brewline.example:4517/shop/', host: 'brewline.example' });
  });

  it.each([
    'chrome://extensions', 'file:///C:/x.html', 'ftp://example.com/', 'http://localhost:3000/', 'http://127.0.0.1:4517/a.html',
    'http://192.168.1.10/', 'http://[::1]/', 'http://intranet/', 'http://printer.local/', 'http://app.internal/',
    'http://site.test/', 'http://dev.localhost/', 'http://0x7f.1/', 'not a url',
  ])('refuses %s', (url) => {
    expect(publicPage(url)).toBeNull();
  });
});

describe('analyse', () => {
  it('posts the key, the clean url, capped title and description, the design summary and lang', async () => {
    const long = tokens({ page: { url: 'https://brewline.co/menu?session=abc#x', title: 'T'.repeat(250), description: 'D'.repeat(400) } });
    const r = await analyse(long, page(long), 'fr');
    expect(r).toEqual({ ok: true, result: RESULT, cached: false, usage: { used: 38, limit: 100, resetsOn: '2026-11-01' } });
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('https://vietnamcos.com/palette/api.php?action=analyse');
    expect(init.method).toBe('POST');
    expect(init.headers).toEqual({ 'Content-Type': 'application/json' });
    const body = sentBody();
    expect(Object.keys(body).sort()).toEqual(['description', 'design', 'key', 'lang', 'title', 'url']);
    expect(body.url).toBe('https://brewline.co/menu');
    expect(body.title).toHaveLength(200);
    expect(body.description).toHaveLength(300);
    expect(body.key).toBe('SDT-ABCD-2345-6789');
    expect(body.lang).toBe('fr');
    expect(body.design).toEqual(designSummary(long));
  });

  it('refuses a private host without calling the API', async () => {
    const r = await analyse(tokens(), { ...page(), url: 'http://127.0.0.1:4517/light-marketing.html' }, 'en');
    expect(r).toEqual({ ok: false, reason: 'unsupported' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('answers not_ai without a request when no Pro + AI key is stored', async () => {
    delete data.licenseAi;
    expect(await analyse(tokens(), page(), 'en')).toEqual({ ok: false, reason: 'not_ai' });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('keeps the last result per host and the fresh usage on the stored licence', async () => {
    await analyse(tokens(), page(), 'en');
    const stored = await storedResult('brewline.co');
    expect(stored).toMatchObject({ result: RESULT, cached: false, lang: 'en', usage: { used: 38, limit: 100, resetsOn: '2026-11-01' } });
    expect(typeof stored!.analysedAt).toBe('string');
    expect(await storedResult('WWW.brewline.co')).toEqual(stored);
    expect((data.licenseAi as typeof AI_LICENSE).usage).toEqual({ used: 38, limit: 100, resetsOn: '2026-11-01' });
  });

  it('keeps at most 30 hosts, dropping the least recently used', async () => {
    for (let i = 0; i < 32; i++) {
      const t = tokens({ site: `site${i}.com`, page: { url: `https://site${i}.com/`, title: '', description: '' } });
      await analyse(t, page(t), 'en');
    }
    const list = data.aiResults as { host: string }[];
    expect(list).toHaveLength(30);
    expect(list[0].host).toBe('site31.com');
    expect(await storedResult('site0.com')).toBeNull();
    expect(await storedResult('site2.com')).not.toBeNull();
    expect((data.aiResults as { host: string }[])[0].host).toBe('site2.com');
  });

  it('marks a shared cached result', async () => {
    fetchMock.mockResolvedValue(json(200, { ok: true, result: RESULT, cached: true, usage: USAGE }));
    const r = await analyse(tokens(), page(), 'en');
    expect(r.ok && r.cached).toBe(true);
    expect((await storedResult('brewline.co'))?.cached).toBe(true);
  });

  it.each([
    [403, { ok: false, error: 'err_not_ai' }, { ok: false, reason: 'not_ai' }],
    [403, { ok: false, error: 'err_ended', ended_on: '2026-10-01' }, { ok: false, reason: 'ended', endedOn: '2026-10-01' }],
    [403, { ok: false, error: 'err_ended', ended_on: null }, { ok: false, reason: 'ended' }],
    [429, { ok: false, error: 'err_quota', resets_on: '2026-11-01', usage: { used: 100, limit: 100, resets_on: '2026-11-01' } },
      { ok: false, reason: 'quota', resetsOn: '2026-11-01' }],
    [429, { ok: false, error: 'api_rate_limited' }, { ok: false, reason: 'rate_limited' }],
    [503, { ok: false, error: 'err_ai_unavailable' }, { ok: false, reason: 'unavailable' }],
    [400, { ok: false, error: 'err_bad_request' }, { ok: false, reason: 'unsupported' }],
    [413, { ok: false, error: 'err_too_large' }, { ok: false, reason: 'unsupported' }],
    [502, '<html>bad gateway</html>', { ok: false, reason: 'unavailable' }],
    [200, { ok: false }, { ok: false, reason: 'unavailable' }],
  ])('maps HTTP %i %j', async (status, body, want) => {
    fetchMock.mockResolvedValue(typeof body === 'string' ? new Response(body, { status }) : json(status, body));
    expect(await analyse(tokens(), page(), 'en')).toEqual(want);
    expect(await storedResult('brewline.co')).toBeNull();
  });

  it('stores the usage an out-of-quota answer reports', async () => {
    fetchMock.mockResolvedValue(json(429, { ok: false, error: 'err_quota', resets_on: '2026-11-01', usage: { used: 100, limit: 100, resets_on: '2026-11-01' } }));
    await analyse(tokens(), page(), 'en');
    expect((data.licenseAi as typeof AI_LICENSE).usage).toEqual({ used: 100, limit: 100, resetsOn: '2026-11-01' });
  });

  it('answers network when the request fails', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));
    expect(await analyse(tokens(), page(), 'en')).toEqual({ ok: false, reason: 'network' });
  });

  it('gives up after 45 s with timeout', async () => {
    vi.useFakeTimers();
    fetchMock.mockImplementation((_url: string, init: RequestInit) => new Promise((_, reject) => {
      init.signal!.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
    }));
    const pending = analyse(tokens(), page(), 'en');
    await vi.advanceTimersByTimeAsync(44_999);
    let settled = false;
    void pending.then(() => { settled = true; });
    await Promise.resolve();
    expect(settled).toBe(false);
    await vi.advanceTimersByTimeAsync(1);
    expect(await pending).toEqual({ ok: false, reason: 'timeout' });
  });

  it('answers cancelled when the caller aborts', async () => {
    fetchMock.mockImplementation((_url: string, init: RequestInit) => new Promise((_, reject) => {
      init.signal!.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
    }));
    const ctrl = new AbortController();
    const pending = analyse(tokens(), page(), 'en', ctrl.signal);
    ctrl.abort();
    expect(await pending).toEqual({ ok: false, reason: 'cancelled' });
  });

  it('drops anything outside the schema and rebuilds unsafe links', async () => {
    fetchMock.mockResolvedValue(json(200, {
      ok: true, cached: false, usage: USAGE,
      result: {
        ...RESULT, verdict: 'amazing', extra: 'x', tags: ['ok', 3],
        sites: [{ ...RESULT.sites[0], url: 'javascript:alert(1)', source: 'web' }, { name: 'Bad', domain: 'not a domain', url: 'https://x/', why: '', relation: 'similar', source: 'ai' }],
      },
    }));
    const r = await analyse(tokens(), page(), 'en');
    expect(r.ok).toBe(true);
    if (!r.ok) return;
    expect('extra' in r.result).toBe(false);
    expect(r.result.verdict).toBe('on_par');
    expect(r.result.tags).toEqual(['ok']);
    expect(r.result.sites).toEqual([{ ...RESULT.sites[0], url: 'https://ridge.example/' }]);
  });
});
