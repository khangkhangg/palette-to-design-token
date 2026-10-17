import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { activate, aiState, checkoutUrl, currentAiLicense, currentLicense, lostKeyUrl, recheckIfDue, removeLicense } from '../../src/license';
import { getSettings, setSettings } from '../../src/store';
import type { AiLicense } from '../../src/license';
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
    expect(await activate('  sdt-abcd-2345-6789 ')).toEqual({ ok: true, plan: 'pro' });
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
    expect(await activate('SDT ABCD 2345 6789')).toEqual({ ok: true, plan: 'pro' });
    expect(await activate('sdtabcd23456789')).toEqual({ ok: true, plan: 'pro' });
    expect(await activate('sdt_abcd.23o5/6i8l')).toEqual({ ok: true, plan: 'pro' });
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

describe('currentLicense and removeLicense', () => {
  it('reads the stored licence, or null', async () => {
    expect(await currentLicense()).toBeNull();
    data.license = lic('2026-10-01T00:00:00.000Z');
    expect(await currentLicense()).toEqual({ ...lic('2026-10-01T00:00:00.000Z'), plan: 'pro', paidThrough: null });
  });
  it('removing the key forgets the licence and turns Pro off, without a network call', async () => {
    data.license = lic('2026-10-01T00:00:00.000Z');
    await setSettings({ pro: true });
    await removeLicense();
    expect(data.license).toBeUndefined();
    expect((await getSettings()).pro).toBe(false);
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

describe('Pro + AI licences', () => {
  const AI_KEY = 'SDT-WXYZ-2345-6789';
  const now = new Date('2026-10-17T00:00:00Z');
  const iso = (ms: number) => new Date(now.getTime() + ms).toISOString();
  const usage = { used: 4, limit: 100, resets_on: '2026-11-01' };
  const aiOk = (paidThrough: string, extra: object = {}) =>
    reply(200, { ok: true, valid: true, revoked: false, email_masked: 'a***@x.com', plan: 'ai', ai_active: true, paid_through: paidThrough, usage, ...extra });
  const lapsed = () => reply(200, { ok: true, valid: false, revoked: false, plan: 'ai', ai_active: false, paid_through: iso(-DAY) });
  const aiLic = (over: object = {}) => ({
    key: AI_KEY, verifiedAt: iso(-2 * DAY), emailMasked: null, plan: 'ai', paidThrough: iso(5 * DAY),
    usage: { used: 1, limit: 100, resetsOn: '2026-11-01' }, lastCheck: 'ok', ...over,
  });

  const aiRec = () => data.licenseAi as AiLicense;
  beforeEach(() => { vi.useFakeTimers(); vi.setSystemTime(now); });

  it('activating an ai key stores licenseAi, keeps an existing pro licence and turns Pro on', async () => {
    data.license = lic('2026-10-01T00:00:00.000Z');
    fetchMock.mockReturnValue(aiOk(iso(20 * DAY)));
    expect(await activate(AI_KEY)).toEqual({ ok: true, plan: 'ai' });
    expect(aiRec().key).toBe(AI_KEY);
    expect(aiRec().plan).toBe('ai');
    expect(aiRec().paidThrough).toBe(iso(20 * DAY));
    expect(aiRec().usage).toEqual({ used: 4, limit: 100, resetsOn: '2026-11-01' });
    expect(data.license).toEqual(lic('2026-10-01T00:00:00.000Z'));
    expect((await getSettings()).pro).toBe(true);
    expect(await aiState()).toEqual({ active: true, paidThrough: iso(20 * DAY), usage: { used: 4, limit: 100, resetsOn: '2026-11-01' } });
  });
  it('a pro key still lands in license, not licenseAi', async () => {
    fetchMock.mockReturnValue(reply(200, { ok: true, valid: true, revoked: false, email_masked: null }));
    await activate(KEY);
    expect(data.license).toBeDefined();
    expect(data.licenseAi).toBeUndefined();
    expect(await aiState()).toEqual({ active: false, paidThrough: null, usage: null });
  });
  it('an ai key that verifies as lapsed on activation is "ended" and stores nothing', async () => {
    fetchMock.mockReturnValue(lapsed());
    expect(await activate(AI_KEY)).toEqual({ ok: false, reason: 'ended' });
    expect(data.licenseAi).toBeUndefined();
    expect((await getSettings()).pro).toBe(false);
  });
  it('reads the optional sub_status from verify', async () => {
    fetchMock.mockReturnValue(aiOk(iso(5 * DAY), { sub_status: 'cancelled' }));
    await activate(AI_KEY);
    expect((await currentAiLicense())?.subStatus).toBe('cancelled');
  });

  describe('aiState', () => {
    it('is active before paidThrough', async () => {
      data.licenseAi = aiLic();
      expect((await aiState()).active).toBe(true);
    });
    it('is inactive past paidThrough after a clean check', async () => {
      data.licenseAi = aiLic({ paidThrough: iso(-DAY) });
      expect((await aiState()).active).toBe(false);
    });
    it('stays active up to 3 days past paidThrough when the last check failed on the network, then stops', async () => {
      data.licenseAi = aiLic({ paidThrough: iso(-2 * DAY), lastCheck: 'network' });
      expect((await aiState()).active).toBe(true);
      data.licenseAi = aiLic({ paidThrough: iso(-4 * DAY), lastCheck: 'network' });
      expect((await aiState()).active).toBe(false);
    });
    it('is inactive when the server said the plan ended, whatever the dates say', async () => {
      data.licenseAi = aiLic({ lastCheck: 'ended' });
      expect((await aiState()).active).toBe(false);
    });
    it('is inactive with no licence', async () => {
      expect(await aiState()).toEqual({ active: false, paidThrough: null, usage: null });
    });
  });

  describe('recheckIfDue for ai', () => {
    it('is not due within 24 hours, due after', async () => {
      data.licenseAi = aiLic({ verifiedAt: iso(-23 * 3600_000) });
      await recheckIfDue(now);
      expect(fetchMock).not.toHaveBeenCalled();
      data.licenseAi = aiLic({ verifiedAt: iso(-25 * 3600_000) });
      fetchMock.mockReturnValue(aiOk(iso(30 * DAY)));
      await recheckIfDue(now);
      expect(fetchMock).toHaveBeenCalledTimes(1);
      expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ key: AI_KEY });
    });
    it('a valid answer updates paidThrough, usage and verifiedAt', async () => {
      data.licenseAi = aiLic({ verifiedAt: iso(-2 * DAY), lastCheck: 'network' });
      fetchMock.mockReturnValue(aiOk(iso(30 * DAY)));
      await recheckIfDue(now);
      const l = aiRec();
      expect(l.paidThrough).toBe(iso(30 * DAY));
      expect(l.verifiedAt).toBe(now.toISOString());
      expect(l.usage).toEqual({ used: 4, limit: 100, resetsOn: '2026-11-01' });
      expect((await aiState()).active).toBe(true);
    });
    it('a lapsed answer keeps the key, marks it ended and drops Pro when there is no pro licence', async () => {
      data.licenseAi = aiLic({ verifiedAt: iso(-2 * DAY) });
      await setSettings({ pro: true });
      fetchMock.mockReturnValue(lapsed());
      await recheckIfDue(now);
      expect(aiRec().key).toBe(AI_KEY);
      expect(aiRec().paidThrough).toBe(iso(-DAY));
      expect((await aiState()).active).toBe(false);
      expect((await getSettings()).pro).toBe(false);
    });
    it('a lapsed answer leaves Pro on when a pro licence exists', async () => {
      data.license = lic(iso(-DAY));
      data.licenseAi = aiLic({ verifiedAt: iso(-2 * DAY) });
      await setSettings({ pro: true });
      fetchMock.mockReturnValue(lapsed());
      await recheckIfDue(now);
      expect((await getSettings()).pro).toBe(true);
    });
    it('revoked removes licenseAi', async () => {
      data.licenseAi = aiLic({ verifiedAt: iso(-2 * DAY) });
      await setSettings({ pro: true });
      fetchMock.mockReturnValue(reply(200, { ok: true, valid: false, revoked: true, plan: 'ai' }));
      await recheckIfDue(now);
      expect(data.licenseAi).toBeUndefined();
      expect((await getSettings()).pro).toBe(false);
    });
    it('a network failure keeps the licence and Pro, then Pro lapses 3 days after paidThrough', async () => {
      data.licenseAi = aiLic({ verifiedAt: iso(-2 * DAY), paidThrough: iso(-2 * DAY) });
      await setSettings({ pro: true });
      fetchMock.mockRejectedValue(new TypeError('offline'));
      await recheckIfDue(now);
      expect(data.licenseAi).toBeDefined();
      expect((await getSettings()).pro).toBe(true);
      vi.setSystemTime(new Date(now.getTime() + 2 * DAY));
      await recheckIfDue(new Date());
      expect((await getSettings()).pro).toBe(false);
      expect(data.licenseAi).toBeDefined();
    });
    it('after the pro licence is revoked, an active ai licence still keeps Pro on', async () => {
      data.license = lic(iso(-31 * DAY));
      data.licenseAi = aiLic({ verifiedAt: iso(-3600_000) });
      await setSettings({ pro: true });
      fetchMock.mockReturnValue(reply(200, { ok: true, valid: false, revoked: true, email_masked: null }));
      await recheckIfDue(now);
      expect(data.license).toBeUndefined();
      expect((await getSettings()).pro).toBe(true);
    });
  });

  describe('removeLicense', () => {
    it('removes only the licence asked for and recomputes Pro', async () => {
      data.license = lic('2026-10-01T00:00:00.000Z');
      data.licenseAi = aiLic();
      await setSettings({ pro: true });
      await removeLicense('ai');
      expect(data.licenseAi).toBeUndefined();
      expect(data.license).toBeDefined();
      expect((await getSettings()).pro).toBe(true);
      await removeLicense('pro');
      expect(data.license).toBeUndefined();
      expect((await getSettings()).pro).toBe(false);
    });
    it('removing pro keeps Pro on while ai is active', async () => {
      data.license = lic('2026-10-01T00:00:00.000Z');
      data.licenseAi = aiLic();
      await setSettings({ pro: true });
      await removeLicense('pro');
      expect(data.licenseAi).toBeDefined();
      expect((await getSettings()).pro).toBe(true);
    });
  });

  it('checkoutUrl takes an optional plan', () => {
    expect(checkoutUrl('fr', 'ai')).toBe('https://vietnamcos.com/palette/checkout?lang=fr&ext=abcdefghijklmnopabcdefghijklmnop&plan=ai');
    expect(checkoutUrl('fr', 'pro')).toBe('https://vietnamcos.com/palette/checkout?lang=fr&ext=abcdefghijklmnopabcdefghijklmnop&plan=pro');
  });

  describe('boundary and renewal lag', () => {
    it('clock skew: paidThrough 5 s ago with a fresh verifiedAt is still active, and a recheck runs', async () => {
      data.licenseAi = aiLic({ verifiedAt: iso(-60_000), paidThrough: iso(-5_000) });
      await setSettings({ pro: true });
      expect((await aiState()).active).toBe(true);
      fetchMock.mockReturnValue(aiOk(iso(30 * DAY)));
      await recheckIfDue(now);
      expect(fetchMock).toHaveBeenCalledTimes(1);
      expect(aiRec().paidThrough).toBe(iso(30 * DAY));
      expect((await getSettings()).pro).toBe(true);
    });
    it('the ok grace is one hour', async () => {
      data.licenseAi = aiLic({ paidThrough: iso(-30 * 60_000) });
      expect((await aiState()).active).toBe(true);
      data.licenseAi = aiLic({ paidThrough: iso(-2 * 3600_000) });
      expect((await aiState()).active).toBe(false);
    });
    it('throttles the past-paidThrough recheck to once per 30 minutes', async () => {
      data.licenseAi = aiLic({ verifiedAt: iso(-60_000), paidThrough: iso(-5_000) });
      fetchMock.mockRejectedValue(new TypeError('offline'));
      await recheckIfDue(now);
      expect(fetchMock).toHaveBeenCalledTimes(1);
      expect(aiRec().lastCheck).toBe('network');
      await recheckIfDue(new Date(now.getTime() + 10 * 60_000));
      expect(fetchMock).toHaveBeenCalledTimes(1);
      await recheckIfDue(new Date(now.getTime() + 31 * 60_000));
      expect(fetchMock).toHaveBeenCalledTimes(2);
    });
    it('an ended record is retried within 3 days of paidThrough and returns to ok when renewed', async () => {
      data.licenseAi = aiLic({ verifiedAt: iso(-3600_000), paidThrough: iso(-DAY), lastCheck: 'ended', lastAttemptAt: iso(-3600_000) });
      fetchMock.mockReturnValue(aiOk(iso(30 * DAY)));
      await recheckIfDue(now);
      expect(fetchMock).toHaveBeenCalledTimes(1);
      expect(aiRec().lastCheck).toBe('ok');
      expect((await aiState()).active).toBe(true);
      expect((await getSettings()).pro).toBe(true);
    });
    it('an ended record is not retried once paidThrough is more than 3 days old', async () => {
      data.licenseAi = aiLic({ verifiedAt: iso(-3600_000), paidThrough: iso(-4 * DAY), lastCheck: 'ended', lastAttemptAt: iso(-3600_000) });
      await recheckIfDue(now);
      expect(fetchMock).not.toHaveBeenCalled();
    });
    it('an ended record that is then revoked is removed', async () => {
      data.licenseAi = aiLic({ verifiedAt: iso(-3600_000), paidThrough: iso(-DAY), lastCheck: 'ended', lastAttemptAt: iso(-3600_000) });
      await setSettings({ pro: false });
      fetchMock.mockReturnValue(reply(200, { ok: true, valid: false, revoked: true, plan: 'ai' }));
      await recheckIfDue(now);
      expect(data.licenseAi).toBeUndefined();
    });
    it('keeps the previous subStatus when the server omits it', async () => {
      data.licenseAi = aiLic({ verifiedAt: iso(-2 * DAY), subStatus: 'cancelled' });
      fetchMock.mockReturnValue(aiOk(iso(30 * DAY)));
      await recheckIfDue(now);
      expect(aiRec().subStatus).toBe('cancelled');
    });
    it('leaves Settings.pro alone when no licence is stored (dev toggle, seeded state)', async () => {
      await setSettings({ pro: true });
      await recheckIfDue(now);
      expect((await getSettings()).pro).toBe(true);
    });
  });
});
