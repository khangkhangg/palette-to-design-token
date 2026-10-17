// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/preact';
import { PlanSheet } from '../../src/ui/components/PlanSheet';

const mocks = vi.hoisted(() => ({ activate: vi.fn() }));
vi.mock('../../src/license', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../src/license')>()),
  activate: mocks.activate,
}));

const create = vi.fn();

beforeEach(() => {
  mocks.activate.mockReset();
  create.mockReset().mockResolvedValue({});
  (globalThis as any).chrome = { tabs: { create }, runtime: { id: 'abcdefghijklmnopabcdefghijklmnop' } };
});
afterEach(() => { cleanup(); vi.useRealTimers(); delete (globalThis as any).chrome; });

const type = (value: string) => fireEvent.input(screen.getByLabelText('License key'), { target: { value } });
const activate = () => fireEvent.click(screen.getByRole('button', { name: 'Activate' }));

describe('PlanSheet licensing', () => {
  it('Pay with PayPal is enabled and opens the checkout page for the chosen plan in a new tab', () => {
    render(<PlanSheet variant="overlay" onClose={() => {}} />);
    const pay = screen.getByRole('button', { name: /Pay \$1\.49 with PayPal/ }) as HTMLButtonElement;
    expect(pay.disabled).toBe(false);
    fireEvent.click(pay);
    expect(create).toHaveBeenCalledWith({
      url: 'https://vietnamcos.com/palette/checkout?lang=en&ext=abcdefghijklmnopabcdefghijklmnop&plan=pro',
    });
  });

  it('links to the lost-key form on the checkout page', () => {
    render(<PlanSheet variant="overlay" start="key" onClose={() => {}} />);
    const a = screen.getByRole('link', { name: 'Lost your key?' }) as HTMLAnchorElement;
    expect(a.href).toBe('https://vietnamcos.com/palette/checkout?lang=en#resend');
  });

  it('Activate is disabled until a key is typed', () => {
    render(<PlanSheet variant="overlay" start="key" onClose={() => {}} />);
    expect((screen.getByRole('button', { name: 'Activate' }) as HTMLButtonElement).disabled).toBe(true);
    type('SDT-ABCD-2345-6789');
    expect((screen.getByRole('button', { name: 'Activate' }) as HTMLButtonElement).disabled).toBe(false);
  });

  it.each([
    ['invalid', "That key isn't valid."],
    ['revoked', 'This key was refunded and no longer works.'],
    ['network', "Couldn't reach the server — try again."],
    ['rate_limited', 'Too many attempts — wait a minute and try again.'],
  ])('shows the %s message and keeps the sheet open', async (reason, message) => {
    mocks.activate.mockResolvedValue({ ok: false, reason });
    const onClose = vi.fn();
    render(<PlanSheet variant="overlay" start="key" onClose={onClose} />);
    type('SDT-ABCD-2345-6789');
    activate();
    const status = await screen.findByText(message);
    expect(status.getAttribute('role')).toBe('status');
    expect(screen.getByLabelText('License key').getAttribute('aria-invalid')).toBe('true');
    expect(onClose).not.toHaveBeenCalled();
  });

  it('is busy while the request is in flight', async () => {
    let resolve!: (v: unknown) => void;
    mocks.activate.mockReturnValue(new Promise((r) => { resolve = r; }));
    render(<PlanSheet variant="overlay" start="key" onClose={() => {}} />);
    type('SDT-ABCD-2345-6789');
    activate();
    const busy = await screen.findByRole('button', { name: 'Activating…' });
    expect((busy as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByLabelText('License key') as HTMLInputElement).disabled).toBe(true);
    await act(async () => { resolve({ ok: false, reason: 'invalid' }); });
    await screen.findByRole('button', { name: 'Activate' });
  });

  it('on success says Pro unlocked, then closes the overlay', async () => {
    mocks.activate.mockResolvedValue({ ok: true });
    const onClose = vi.fn();
    render(<PlanSheet variant="overlay" start="key" onClose={onClose} />);
    type(' sdt-abcd-2345-6789 ');
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    activate();
    await act(async () => { await Promise.resolve(); });
    expect(within(screen.getByRole('dialog')).getByText('Pro unlocked')).toBeTruthy();
    expect(mocks.activate).toHaveBeenCalledWith(' sdt-abcd-2345-6789 ');
    expect(onClose).not.toHaveBeenCalled();
    await act(async () => { vi.advanceTimersByTime(1000); });
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('on success the page variant calls onUnlocked instead of onClose', async () => {
    mocks.activate.mockResolvedValue({ ok: true });
    const onClose = vi.fn();
    const onUnlocked = vi.fn();
    render(<PlanSheet variant="page" start="key" onClose={onClose} onUnlocked={onUnlocked} />);
    type('SDT-ABCD-2345-6789');
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    activate();
    await act(async () => { await Promise.resolve(); });
    await act(async () => { vi.advanceTimersByTime(1000); });
    expect(onUnlocked).toHaveBeenCalledTimes(1);
    expect(onClose).not.toHaveBeenCalled();
  });

  it('keeps dialog semantics', () => {
    render(<PlanSheet variant="overlay" start="key" onClose={() => {}} />);
    const d = screen.getByRole('dialog');
    expect(d.getAttribute('aria-modal')).toBe('true');
    expect(d.getAttribute('aria-labelledby')).toBe('plan-title');
  });
});
