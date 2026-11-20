// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/preact';
import { installFakeChrome } from './fake-chrome';
import { brewline } from './export/brewline.tokens';

const mocks = vi.hoisted(() => ({ capture: vi.fn(), directoryMe: vi.fn() }));
vi.mock('../../src/share', async (actual) => ({ ...(await actual<typeof import('../../src/share')>()), ...mocks }));
const { ShareView } = await import('../../src/ui/sidepanel/ShareView');

const STYLES = [{ slug: 'minimal', name: 'Minimal' }, { slug: 'editorial', name: 'Editorial' }];

beforeEach(() => {
  mocks.capture.mockReset().mockResolvedValue({ blob: new Blob(['img']), width: 1600, height: 1000 });
  mocks.directoryMe.mockReset().mockResolvedValue({ status: 'active', styles: STYLES });
  Object.assign(URL, { createObjectURL: () => 'blob:shot', revokeObjectURL: () => {} });
});
// The fake chrome stays installed: preact runs effect cleanups after paint, which can be after the test ends.
afterEach(() => cleanup());

const boot = (token?: string) => {
  const fake = installFakeChrome({ tabs: [{ id: 1 }] });
  if (token) fake.data.settings = { dirToken: token };
  render(<ShareView tokens={brewline} onBack={() => {}} />);
  return fake;
};

describe('ShareView', () => {
  it('connects first: opens the connect page, waits, then moves on when the token arrives', async () => {
    const { chrome } = boot();
    const create = vi.spyOn(chrome.tabs, 'create');
    fireEvent.click(await screen.findByRole('button', { name: 'Connect account' }));
    expect(create).toHaveBeenCalledWith({ url: `https://vietnamcos.com/palette/directory/connect?ext=${chrome.runtime.id}` });
    expect(screen.getByText('Waiting for the sign-in tab')).toBeTruthy();
    await chrome.storage.local.set({ settings: { dirToken: 'tok' } });
    expect(await screen.findByRole('button', { name: 'Take screenshot' })).toBeTruthy();
    await vi.waitFor(() => expect(mocks.directoryMe).toHaveBeenCalledWith('tok'));
  });

  it('keeps Share publicly off until a style, a name and consent are all given', async () => {
    boot('tok');
    fireEvent.click(await screen.findByRole('button', { name: 'Take screenshot' }));
    const shareBtn = (await screen.findByRole('button', { name: 'Share publicly' })) as HTMLButtonElement;
    expect(mocks.capture).toHaveBeenCalledWith(brewline.site, undefined);
    expect(screen.getByText('1600 × 1000 · 1 KB')).toBeTruthy();
    expect(shareBtn.disabled).toBe(true);
    fireEvent.click(screen.getByRole('radio', { name: 'Editorial' }));
    expect(shareBtn.disabled).toBe(true);
    fireEvent.click(screen.getByRole('checkbox', { name: /no personal data/ }));
    expect(shareBtn.disabled).toBe(false);
    fireEvent.input(screen.getByRole('textbox', { name: 'Name' }), { target: { value: '  ' } });
    expect(shareBtn.disabled).toBe(true);
  });

  it('shows a capture error with Try again, and a banned member cannot share', async () => {
    mocks.capture.mockRejectedValueOnce(new Error('other_tab'));
    mocks.directoryMe.mockResolvedValue({ status: 'banned', styles: STYLES });
    boot('tok');
    fireEvent.click(await screen.findByRole('button', { name: 'Take screenshot' }));
    expect((await screen.findByRole('alert')).textContent).toMatch(/another tab/);
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    await screen.findByRole('button', { name: 'Share publicly' });
    expect((await screen.findByRole('alert')).textContent).toMatch(/can't share tokens/);
  });

  it('a revoked token clears the stored token and goes back to connect', async () => {
    mocks.directoryMe.mockRejectedValue(new Error('token_revoked'));
    const { data } = boot('tok');
    expect(await screen.findByRole('button', { name: 'Connect account' })).toBeTruthy();
    expect((data.settings as { dirToken: string }).dirToken).toBe('');
    expect(screen.getByRole('alert').textContent).toMatch(/disconnected/);
  });
});
