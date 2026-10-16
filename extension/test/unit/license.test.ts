import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { activate, checkoutUrl, lostKeyUrl, recheckIfDue } from '../../src/license';
import { getSettings, setSettings } from '../../src/store';
import { installFakeChrome, uninstallFakeChrome } from './fake-chrome';

const KEY = 'SDT-ABCD-2345-6789';
const DAY = 86_400_000;
let data: Record<string, unknown>;
const fetchMock = vi.fn();

const reply = (status: number, body: unknown) => Promise.resolve({ status, json: async () => body } as Response);
const notJson = () => Promise.resolve({ status: 200, json: async () => { throw new SyntaxError('Unexpected token <'); } } as unknown as Response);
const lic = (verifiedAt: string) => ({ key: KEY, verifiedAt, emailMasked: 'a***@x.com' });

beforeEach(() => {
  ({ data } = installFakeChrome());
  fetchMock.mockReset();
  vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => { uninstallFakeChrome(); vi.unstubAllGlobals(); vi.useRealTimers(); });

describe('activate', () => {
  it('posts the normalised key as JSON, stores the licence and turns Pro on', async () => {
    fetchMock.mockReturnValue(reply(200, { ok: true, valid: true, revoked: false, email_masked: 'a***@x.com' }));
    expect(await activate('  sdt-abcd-2345-6789 ')).toEqual({ ok: true });
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('https://vietnamcos.com/palette/api.php?action=verify');
    expect(init.method).toBe('POST');
    expect(JSON.parse(init.body)).toEqual({ key: KEY });
    expect((data.license as any).key).toBe(KEY);
    expect((data.license as any).emailMasked).toBe('a***@x.com');
    expect((await getSettings()).pro).toBe(true);
  });
  it('maps look-alike characters (O to 0, I and L to 1) before sending', async () => {
    fetchMock.mockReturnValue(reply(200, { ok: true, valid: true, revoked: false, email_masked: null }));
    await activate('SDT-ABCD-23O5-6I89');
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ key: 'SDT-ABCD-2305-6189' });
  });
  it('accepts keys typed with spaces or without dashes, like the server', async () => {
    fetchMock.mockImplementation(() => reply(200, { ok: true, valid: true, revoked: false, email_masked: null }));
    expect(await activate('SDT ABCD 2345 6789')).toEqual({ ok: true });
    expect(await activate('sdtabcd23456789')).toEqual({ ok: true });
    expect(await activate('sdt_abcd.23o5/6i8l')).toEqual({ ok: true });
    expect(fetchMock.mock.calls.map((c) => JSON.parse(c[1].body).key)).toEqual([KEY, KEY, 'SDT-ABCD-2305-6181']);
  });
  it('treats a 200 without a clear verdict as a network failure, not an invalid key', async () => {
    fetchMock.mockReturnValueOnce(reply(200, {}));
    expect(await activate(KEY)).toEqual({ ok: false, reason: 'network' });
    fetchMock.mockReturnValueOnce(reply(200, { ok: false }));
    expect(await activate(KEY)).toEqual({ ok: false, reason: 'network' });
    fetchMock.mockReturnValueOnce(reply(200, { revoked: true }));
    expect(await activate(KEY)).toEqual({ ok: false, reason: 'network' });
    fetchMock.mockReturnValueOnce(notJson());
    expect(await activate(KEY)).toEqual({ ok: false, reason: 'network' });
  });
  it('rejects malformed keys locally without a network call', async () => {
    expect(await activate('nonsense')).toEqual({ ok: false, reason: 'invalid' });
    expect(await activate('SDT-ABCD-2345-678U')).toEqual({ ok: false, reason: 'invalid' });
    expect(fetchMock).not.toHaveBeenCalled();
  });
  it('maps server answers to reasons and leaves Pro unchanged', async () => {
    fetchMock.mockReturnValueOnce(reply(200, { ok: true, valid: false, revoked: false, email_masked: null }));
    expect(await activate(KEY)).toEqual({ ok: false, reason: 'invalid' });
    fetchMock.mockReturnValueOnce(reply(200, { ok: true, valid: false, revoked: true, email_masked: null }));
    expect(await activate(KEY)).toEqual({ ok: false, reason: 'revoked' });
    fetchMock.mockReturnValueOnce(reply(429, { ok: false, error: 'api_rate_limited' }));
    expect(await activate(KEY)).toEqual({ ok: false, reason: 'rate_limited' });
    fetchMock.mockReturnValueOnce(reply(500, {}));
    expect(await activate(KEY)).toEqual({ ok: false, reason: 'network' });
    expect(data.license).toBeUndefined();
    expect((await getSettings()).pro).toBe(false);
  });
  it('a rejected fetch is a network failure and Pro stays as it was', async () => {
    await setSettings({ pro: true });
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));
    expect(await activate(KEY)).toEqual({ ok: false, reason: 'network' });
    expect((await getSettings()).pro).toBe(true);
  });
  it('aborts the request after 10 seconds', async () => {
    vi.useFakeTimers();
    fetchMock.mockImplementation((_u: string, init: RequestInit) => new Promise((_res, rej) => {
      init.signal!.addEventListener('abort', () => rej(new DOMException('aborted', 'AbortError')));
    }));
    const p = activate(KEY);
    await vi.advanceTimersByTimeAsync(10_000);
    expect(await p).toEqual({ ok: false, reason: 'network' });
  });
});

describe('recheckIfDue', () => {
  const now = new Date('2026-10-17T00:00:00Z');
  const ago = (days: number) => new Date(now.getTime() - days * DAY).toISOString();

  it('does nothing without a licence', async () => {
    await recheckIfDue(now);
    expect(fetchMock).not.toHaveBeenCalled();
  });
  it('is not due at 29 days', async () => {
    data.license = lic(ago(29));
    await recheckIfDue(now);
    expect(fetchMock).not.toHaveBeenCalled();
  });
  it('is due at 31 days and a valid answer refreshes verifiedAt', async () => {
    data.license = lic(ago(31));
    fetchMock.mockReturnValue(reply(200, { ok: true, valid: true, revoked: false, email_masked: 'a***@x.com' }));
    await recheckIfDue(now);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect((data.license as any).verifiedAt).toBe(now.toISOString());
  });
  it('revoked removes the licence and turns Pro off', async () => {
    data.license = lic(ago(31));
    await setSettings({ pro: true });
    fetchMock.mockReturnValue(reply(200, { ok: true, valid: false, revoked: true, email_masked: null }));
    await recheckIfDue(now);
    expect(data.license).toBeUndefined();
    expect((await getSettings()).pro).toBe(false);
  });
  it('invalid also turns Pro off', async () => {
    data.license = lic(ago(40));
    await setSettings({ pro: true });
    fetchMock.mockReturnValue(reply(200, { ok: true, valid: false, revoked: false, email_masked: null }));
    await recheckIfDue(now);
    expect((await getSettings()).pro).toBe(false);
  });
  it.each([
    ['an empty 200', () => reply(200, {})],
    ['a 200 with ok:false', () => reply(200, { ok: false })],
    ['a 200 with valid:false but no ok', () => reply(200, { valid: false, revoked: false })],
    ['a non-JSON 200', notJson],
    ['a 500', () => reply(500, { ok: false })],
    ['a 503', () => reply(503, {})],
    ['a 302', () => reply(302, {})],
  ])('%s keeps Pro and the licence', async (_label, res) => {
    data.license = lic(ago(31));
    await setSettings({ pro: true });
    fetchMock.mockReturnValueOnce(res());
    await recheckIfDue(now);
    expect(data.license).toEqual(lic(ago(31)));
    expect((await getSettings()).pro).toBe(true);
  });
  it('an unparseable verifiedAt is due once, then rewritten so it does not recheck on every open', async () => {
    data.license = lic('not a date');
    await setSettings({ pro: true });
    fetchMock.mockRejectedValueOnce(new TypeError('offline'));
    await recheckIfDue(now);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect((data.license as any).verifiedAt).toBe(now.toISOString());
    expect((await getSettings()).pro).toBe(true);
    await recheckIfDue(now);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });
  it('an unparseable verifiedAt with a valid answer is rewritten to now', async () => {
    data.license = lic('');
    fetchMock.mockReturnValue(reply(200, { ok: true, valid: true, revoked: false, email_masked: 'a***@x.com' }));
    await recheckIfDue(now);
    expect((data.license as any).verifiedAt).toBe(now.toISOString());
  });
  it('a network error or rate limit keeps Pro and the licence', async () => {
    data.license = lic(ago(31));
    await setSettings({ pro: true });
    fetchMock.mockRejectedValueOnce(new TypeError('offline'));
    await recheckIfDue(now);
    fetchMock.mockReturnValueOnce(reply(429, {}));
    await recheckIfDue(now);
    expect(data.license).toBeDefined();
    expect((await getSettings()).pro).toBe(true);
  });
});

describe('checkoutUrl', () => {
  it('carries the language and this extension id', () => {
    expect(checkoutUrl('fr')).toBe('https://vietnamcos.com/palette/checkout?lang=fr&ext=abcdefghijklmnopabcdefghijklmnop');
  });
});

describe('lostKeyUrl', () => {
  it('points at the resend form on the checkout page', () => {
    expect(lostKeyUrl('fr')).toBe('https://vietnamcos.com/palette/checkout?lang=fr#resend');
  });
});
