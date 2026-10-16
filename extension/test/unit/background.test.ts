import { afterEach, describe, expect, it, vi } from 'vitest';
import { installFakeChrome } from './fake-chrome';

const activate = vi.hoisted(() => vi.fn());
vi.mock('../../src/license', () => ({ activate }));

afterEach(() => { delete (globalThis as any).chrome; vi.resetModules(); activate.mockReset(); });

const sidePanel = () => ({
  setPanelBehavior: vi.fn().mockResolvedValue(undefined),
  setOptions: vi.fn().mockResolvedValue(undefined),
});

async function boot() {
  const external: ((m: unknown, s: { url?: string }, r: (x: unknown) => void) => boolean | void)[] = [];
  const panel = sidePanel();
  (globalThis as any).chrome = { sidePanel: panel, runtime: { onMessageExternal: { addListener: (l: any) => external.push(l) } } };
  await import('../../src/background');
  return { panel, listener: external[0] };
}

describe('background', () => {
  it('disables the side panel globally so it only shows on tabs where the user opened it', async () => {
    const { panel } = await boot();
    expect(panel.setPanelBehavior).toHaveBeenCalledWith({ openPanelOnActionClick: false });
    expect(panel.setOptions).toHaveBeenCalledWith({ enabled: false });
  });

  it('activates a key sent from the checkout page and answers ok', async () => {
    activate.mockResolvedValue({ ok: true });
    const { listener } = await boot();
    const respond = vi.fn();
    expect(listener({ type: 'license', key: 'SDT-ABCD-2345-6789' }, { url: 'https://vietnamcos.com/palette/checkout?lang=en' }, respond)).toBe(true);
    await vi.waitFor(() => expect(respond).toHaveBeenCalledWith({ ok: true }));
    expect(activate).toHaveBeenCalledWith('SDT-ABCD-2345-6789');
  });

  it('accepts www and relays the failure reason', async () => {
    activate.mockResolvedValue({ ok: false, reason: 'revoked' });
    const { listener } = await boot();
    const respond = vi.fn();
    listener({ type: 'license', key: 'SDT-ABCD-2345-6789' }, { url: 'https://www.vietnamcos.com/palette/account' }, respond);
    await vi.waitFor(() => expect(respond).toHaveBeenCalledWith({ ok: false, reason: 'revoked' }));
  });

  it('answers network when activation itself rejects', async () => {
    activate.mockRejectedValue(new Error('storage quota'));
    const { listener } = await boot();
    const respond = vi.fn();
    expect(listener({ type: 'license', key: 'SDT-ABCD-2345-6789' }, { url: 'https://vietnamcos.com/palette/checkout' }, respond)).toBe(true);
    await vi.waitFor(() => expect(respond).toHaveBeenCalledWith({ ok: false, reason: 'network' }));
  });

  it('ignores other senders and malformed messages', async () => {
    const { listener } = await boot();
    const respond = vi.fn();
    const good = { url: 'https://vietnamcos.com/palette/checkout' };
    for (const [m, s] of [
      [{ type: 'license', key: 'SDT-ABCD-2345-6789' }, { url: 'https://evil.example/palette/' }],
      [{ type: 'license', key: 'SDT-ABCD-2345-6789' }, { url: 'https://vietnamcos.com.evil.example/palette/' }],
      [{ type: 'license', key: 'SDT-ABCD-2345-6789' }, { url: 'https://vietnamcos.com/courses/' }],
      [{ type: 'license', key: 'SDT-ABCD-2345-6789' }, {}],
      [{ type: 'other', key: 'x' }, good],
      [{ type: 'license', key: 5 }, good],
      [null, good],
    ] as const) expect(listener(m, s, respond)).toBeUndefined();
    expect(activate).not.toHaveBeenCalled();
    expect(respond).not.toHaveBeenCalled();
  });
});
