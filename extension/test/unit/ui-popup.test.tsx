// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/preact';
import type { ColourToken, Tokens } from '../../src/model/tokens';
import { EMPTY_TOKENS } from '../../src/model/tokens';
import { ScanError } from '../../src/scan';
import type { Settings } from '../../src/store';
import { App } from '../../src/ui/popup/App';

const mocks = vi.hoisted(() => ({
  scanActiveTab: vi.fn(),
  recheckIfDue: vi.fn(),
  currentLicense: vi.fn(),
  removeLicense: vi.fn(),
  currentAiLicense: vi.fn(),
  aiState: vi.fn(),
  tabsCreate: vi.fn(),
  setSettings: vi.fn(),
  saveScan: vi.fn(),
  settings: { theme: 'system', lang: '', pro: false } as Settings,
}));

vi.mock('../../src/scan', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../src/scan')>()),
  scanActiveTab: mocks.scanActiveTab,
}));

vi.mock('../../src/license', () => ({
  recheckIfDue: mocks.recheckIfDue,
  currentLicense: mocks.currentLicense,
  removeLicense: mocks.removeLicense,
  currentAiLicense: mocks.currentAiLicense,
  aiState: mocks.aiState,
  accountUrl: () => 'https://vietnamcos.com/palette/account',
  checkoutUrl: (lang: string, plan?: string) => `https://vietnamcos.com/palette/checkout?lang=${lang}&plan=${plan}`,
}));

vi.mock('../../src/store', () => ({
  getSettings: async () => mocks.settings,
  setSettings: mocks.setSettings,
  saveScan: mocks.saveScan,
  onSettingsChange: () => () => {},
}));

const c = (role: ColourToken['role'], name: string, hex: string, weight: number): ColourToken =>
  ({ role, name, hex, weight, interactive: false });

const brewline = (): Tokens => ({
  ...EMPTY_TOKENS('brewline.co'),
  colours: [
    c('surface', 'surface', '#FFFDF8', 0.3),
    c('primary', 'primary', '#1F5E4B', 0.2),
    c('text', 'text', '#1B1A17', 0.2),
    c('accent', 'accent', '#E0763A', 0.05),
    c('primary', 'primary-2', '#2E7A63', 0.05),
    c('surface', 'surface-2', '#F3EEE4', 0.1),
    c('text', 'text-2', '#5C574E', 0.1),
  ],
  fonts: [{ name: 'body', family: 'DM Sans' }, { name: 'heading', family: 'Fraunces' }],
});

const writeText = vi.fn(async (_s: string) => {});

beforeEach(() => {
  mocks.settings = { theme: 'system', lang: '', pro: false };
  mocks.scanActiveTab.mockReset();
  mocks.recheckIfDue.mockReset().mockResolvedValue(undefined);
  mocks.currentLicense.mockReset().mockResolvedValue(null);
  mocks.removeLicense.mockReset().mockResolvedValue(undefined);
  mocks.currentAiLicense.mockReset().mockResolvedValue(null);
  mocks.aiState.mockReset().mockResolvedValue({ active: false, paidThrough: null, usage: null });
  mocks.tabsCreate.mockReset().mockResolvedValue({});
  (globalThis as any).chrome = { tabs: { create: mocks.tabsCreate } };
  mocks.setSettings.mockReset().mockResolvedValue(undefined);
  mocks.saveScan.mockReset().mockResolvedValue(undefined);
  writeText.mockClear();
  Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
});
afterEach(() => { cleanup(); delete (globalThis as any).chrome; });

describe('popup App', () => {
  it('starts the 30-day licence re-check on open without waiting for the scan', async () => {
    mocks.scanActiveTab.mockReturnValue(new Promise<Tokens>(() => {}));
    render(<App />);
    await waitFor(() => expect(mocks.recheckIfDue).toHaveBeenCalledTimes(1));
  });

  it('shows a scanning state, then saves the scan', async () => {
    let resolve!: (t: Tokens) => void;
    mocks.scanActiveTab.mockReturnValue(new Promise<Tokens>((r) => { resolve = r; }));
    render(<App />);
    expect(await screen.findByText('Scanning page…')).toBeTruthy();
    const tokens = brewline();
    resolve(tokens);
    await screen.findByText('#1F5E4B');
    expect(mocks.saveScan).toHaveBeenCalledWith(tokens);
  });

  it('renders role groups in order with hex and share', async () => {
    mocks.scanActiveTab.mockResolvedValue(brewline());
    render(<App />);
    await screen.findByText('#1F5E4B');
    const headings = screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent);
    expect(headings).toEqual(['Primary', 'Accent', 'Surface', 'Text']);
    expect(screen.getByText('brewline.co · 7 colours · 2 fonts')).toBeTruthy();
    expect(document.documentElement.lang).toBe('en');
    const sw = screen.getByRole('button', { name: /#1F5E4B/ });
    expect(within(sw).getByText('20%')).toBeTruthy();
  });

  it('copies a swatch hex and shows Copied', async () => {
    mocks.scanActiveTab.mockResolvedValue(brewline());
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: /#1F5E4B/ }));
    await waitFor(() => expect(writeText).toHaveBeenCalledWith('#1F5E4B'));
    expect(await screen.findByText('Copied')).toBeTruthy();
  });

  it('says Couldn\'t copy when the clipboard write fails (swatch and hex list)', async () => {
    writeText.mockRejectedValueOnce(new Error('denied')).mockRejectedValueOnce(new Error('denied'));
    mocks.scanActiveTab.mockResolvedValue(brewline());
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: /#1F5E4B/ }));
    expect(await screen.findByText("Couldn't copy")).toBeTruthy();
    expect(screen.queryByText('Copied')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Copy hex list' }));
    await waitFor(() => expect(screen.getAllByText("Couldn't copy").length).toBe(2));
  });

  it('copies the full hex list', async () => {
    mocks.scanActiveTab.mockResolvedValue(brewline());
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: 'Copy hex list' }));
    await waitFor(() => expect(writeText).toHaveBeenCalledWith(
      ['#1F5E4B', '#2E7A63', '#E0763A', '#FFFDF8', '#F3EEE4', '#1B1A17', '#5C574E'].join('\n'),
    ));
  });

  it('marks Export tokens as PRO for free users only', async () => {
    mocks.scanActiveTab.mockResolvedValue(brewline());
    const { unmount } = render(<App />);
    const btn = await screen.findByRole('button', { name: /Export tokens/ });
    expect(within(btn).getByText('PRO')).toBeTruthy();
    unmount();
    mocks.settings = { ...mocks.settings, pro: true };
    render(<App />);
    const pro = await screen.findByRole('button', { name: /Export tokens/ });
    expect(within(pro).queryByText('PRO')).toBeNull();
  });

  it('shows the restricted empty state', async () => {
    mocks.scanActiveTab.mockRejectedValue(new ScanError('restricted'));
    render(<App />);
    expect(await screen.findByText("This page can't be scanned")).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull();
    expect(screen.getByRole('alert').textContent).toContain("This page can't be scanned");
  });

  it('shows the no-access state instead of restricted', async () => {
    mocks.scanActiveTab.mockRejectedValue(new ScanError('no-access'));
    render(<App />);
    expect(await screen.findByText('Click the Site to Design Token icon on this page to scan it.')).toBeTruthy();
    expect(screen.queryByText("This page can't be scanned")).toBeNull();
  });

  it('renders English with an English lang attribute for an unsupported language', async () => {
    mocks.settings = { ...mocks.settings, lang: 'de' };
    mocks.scanActiveTab.mockResolvedValue({ ...brewline(), colours: [c('primary', 'primary', '#1F5E4B', 1)], fonts: [{ name: 'body', family: 'Inter' }] });
    render(<App />);
    expect(await screen.findByText('brewline.co · 1 colour · 1 font')).toBeTruthy();
    expect(document.documentElement.lang).toBe('en');
    expect(document.documentElement.dir).toBe('ltr');
    fireEvent.click(screen.getByRole('button', { name: 'Settings' }));
    expect((screen.getByRole('radio', { name: 'English' }) as HTMLInputElement).checked).toBe(true);
  });

  it('lists the seven languages by native name and switches live', async () => {
    mocks.settings = { ...mocks.settings, lang: 'es' };
    mocks.scanActiveTab.mockResolvedValue({ ...brewline(), colours: [c('primary', 'primary', '#1F5E4B', 1)], fonts: [{ name: 'body', family: 'Inter' }] });
    render(<App />);
    expect(await screen.findByText('brewline.co · 1 color · 1 fuente')).toBeTruthy();
    expect(document.documentElement.lang).toBe('es');
    fireEvent.click(screen.getByRole('button', { name: 'Ajustes' }));
    const radios = screen.getAllByRole('radio').filter((r) => (r as HTMLInputElement).name === 'sdt-lang');
    expect(radios.map((r) => r.closest('label')!.textContent)).toEqual(
      ['English', '中文', 'हिन्दी', 'Español', 'العربية', 'Français', 'বাংলা'],
    );
    expect(radios.map((r) => r.closest('label')!.querySelector('[lang]')?.getAttribute('lang'))).toEqual(
      ['en', 'zh-CN', 'hi', 'es', 'ar', 'fr', 'bn'],
    );
    expect((screen.getByRole('radio', { name: 'Español' }) as HTMLInputElement).checked).toBe(true);
    fireEvent.click(screen.getByRole('radio', { name: 'العربية' }));
    expect(document.documentElement.dir).toBe('rtl');
    expect(document.documentElement.lang).toBe('ar');
    expect(mocks.setSettings).toHaveBeenCalledWith({ lang: 'ar' });
    expect(screen.getByRole('button', { name: 'الإعدادات' })).toBeTruthy();
  });

  it('offers Retry when the scan fails', async () => {
    mocks.scanActiveTab.mockRejectedValueOnce(new ScanError('failed')).mockResolvedValueOnce(brewline());
    render(<App />);
    const retry = await screen.findByRole('button', { name: 'Retry' });
    expect(screen.getByRole('alert').textContent).toContain("Couldn't scan this page");
    fireEvent.click(retry);
    expect(await screen.findByText('#1F5E4B')).toBeTruthy();
  });

  it('shows the found-little note when nothing is chromatic', async () => {
    mocks.scanActiveTab.mockResolvedValue({
      ...EMPTY_TOKENS('plain.dev'),
      colours: [c('surface', 'surface', '#FFFFFF', 0.6), c('text', 'text', '#111111', 0.4)],
    });
    render(<App />);
    expect(await screen.findByText(/no clear primary or accent/)).toBeTruthy();
    expect(screen.getByText('#FFFFFF')).toBeTruthy();
  });

  it('persists and applies a theme change from the settings menu', async () => {
    mocks.scanActiveTab.mockResolvedValue(brewline());
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: 'Settings' }));
    fireEvent.click(screen.getByRole('radio', { name: 'Dark' }));
    await waitFor(() => expect(mocks.setSettings).toHaveBeenCalledWith({ theme: 'dark' }));
    expect(document.documentElement.dataset.theme).toBe('dark');
  });

  it('has keyboard-accessible tabs with placeholders', async () => {
    mocks.scanActiveTab.mockResolvedValue(brewline());
    render(<App />);
    await screen.findByText('#1F5E4B');
    expect(screen.getAllByRole('tab').map((el) => el.textContent)).toEqual(['Colours', 'Type', 'Spacing', 'Contrast']);
    fireEvent.keyDown(screen.getByRole('tab', { name: 'Colours' }), { key: 'ArrowRight' });
    const type = screen.getByRole('tab', { name: 'Type' });
    expect(type.getAttribute('aria-selected')).toBe('true');
    expect(document.activeElement).toBe(type);
    expect(screen.queryByText('#1F5E4B')).toBeNull();
  });

  describe('licence in the settings menu', () => {
    const openMenu = async () => fireEvent.click(await screen.findByRole('button', { name: 'Settings' }));

    it('offers Enter license key to free users, with no Pro status', async () => {
      mocks.scanActiveTab.mockResolvedValue(brewline());
      render(<App />);
      await openMenu();
      expect(screen.getByRole('button', { name: 'Enter license key' })).toBeTruthy();
      expect(screen.queryByText(/Pro active/)).toBeNull();
      expect(screen.queryByRole('button', { name: 'Remove key from this browser' })).toBeNull();
    });

    it('shows Pro active with the last 4 characters of the key, not the whole key', async () => {
      mocks.settings = { ...mocks.settings, pro: true };
      mocks.currentLicense.mockResolvedValue({ key: 'SDT-ABCD-2345-6789', verifiedAt: '2026-10-01T00:00:00.000Z', emailMasked: null });
      mocks.scanActiveTab.mockResolvedValue(brewline());
      render(<App />);
      await openMenu();
      expect((await screen.findByText(/Pro active/)).textContent).toBe('Pro active · SDT-…-6789');
      expect(document.body.textContent).not.toContain('ABCD');
      expect(screen.queryByRole('button', { name: 'Enter license key' })).toBeNull();
    });

    it('removes the key only after an in-menu confirm (no window.confirm), and Cancel keeps it', async () => {
      const confirmSpy = vi.spyOn(window, 'confirm');
      mocks.settings = { ...mocks.settings, pro: true };
      mocks.currentLicense.mockResolvedValue({ key: 'SDT-ABCD-2345-6789', verifiedAt: '2026-10-01T00:00:00.000Z', emailMasked: null });
      mocks.scanActiveTab.mockResolvedValue(brewline());
      render(<App />);
      await openMenu();
      fireEvent.click(await screen.findByRole('button', { name: 'Remove key from this browser' }));
      expect(screen.getByText('Remove the key? Pro turns off in this browser until you enter it again.')).toBeTruthy();
      fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
      expect(mocks.removeLicense).not.toHaveBeenCalled();
      fireEvent.click(screen.getByRole('button', { name: 'Remove key from this browser' }));
      fireEvent.click(screen.getByRole('button', { name: 'Remove key' }));
      await waitFor(() => expect(mocks.removeLicense).toHaveBeenCalledTimes(1));
      expect(await screen.findByRole('button', { name: 'Enter license key' })).toBeTruthy();
      expect(screen.queryByText(/Pro active/)).toBeNull();
      expect(confirmSpy).not.toHaveBeenCalled();
      confirmSpy.mockRestore();
    });

    const proWithKey = () => {
      mocks.settings = { ...mocks.settings, pro: true };
      mocks.currentLicense.mockResolvedValue({ key: 'SDT-ABCD-2345-6789', verifiedAt: '2026-10-01T00:00:00.000Z', emailMasked: null });
      mocks.scanActiveTab.mockResolvedValue(brewline());
    };

    it('shows the masked key left-to-right inside a bdi', async () => {
      proWithKey();
      render(<App />);
      await openMenu();
      const status = await screen.findByText(/Pro active/);
      const bdi = status.querySelector('bdi');
      expect(bdi?.getAttribute('dir')).toBe('ltr');
      expect(bdi?.textContent).toBe('SDT-…-6789');
    });

    it('moves focus into the confirm step, and back to the remove button on Cancel', async () => {
      proWithKey();
      render(<App />);
      await openMenu();
      fireEvent.click(await screen.findByRole('button', { name: 'Remove key from this browser' }));
      await waitFor(() => expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Cancel' })));
      expect(screen.getByRole('group', { name: 'Remove key from this browser' }).contains(document.activeElement)).toBe(true);
      fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
      await waitFor(() => expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Remove key from this browser' })));
    });

    it('restores Pro and says so when removing the key fails', async () => {
      const err = vi.spyOn(console, 'error').mockImplementation(() => {});
      proWithKey();
      mocks.removeLicense.mockRejectedValue(new Error('storage'));
      render(<App />);
      await openMenu();
      fireEvent.click(await screen.findByRole('button', { name: 'Remove key from this browser' }));
      fireEvent.click(screen.getByRole('button', { name: 'Remove key' }));
      expect((await screen.findByRole('status')).textContent).toContain("Couldn't remove the key — try again");
      expect(await screen.findByText(/Pro active/)).toBeTruthy();
      expect(screen.queryByRole('button', { name: 'Enter license key' })).toBeNull();
      err.mockRestore();
    });
  });

  describe('Pro + AI licence in the settings menu', () => {
    const openMenu = async () => fireEvent.click(await screen.findByRole('button', { name: 'Settings' }));
    const PRO = { key: 'SDT-ABCD-2345-6789', verifiedAt: '2026-10-01T00:00:00.000Z', emailMasked: null, plan: 'pro', paidThrough: null };
    const AI = { key: 'SDT-WXYZ-2345-1234', verifiedAt: '2026-10-16T00:00:00.000Z', emailMasked: null, plan: 'ai', paidThrough: '2026-11-17T12:00:00.000Z', usage: null, subStatus: null, lastCheck: 'ok' };
    const aiOnly = (over: object = {}, active = true) => {
      mocks.settings = { ...mocks.settings, pro: active };
      mocks.currentAiLicense.mockResolvedValue({ ...AI, ...over });
      mocks.aiState.mockResolvedValue({ active, paidThrough: AI.paidThrough, usage: null });
      mocks.scanActiveTab.mockResolvedValue(brewline());
    };

    it('shows "Pro + AI · renews <date>" with the date isolated left-to-right, and Manage subscription opens the account page', async () => {
      aiOnly();
      render(<App />);
      await openMenu();
      const status = await screen.findByText(/Pro \+ AI · renews/);
      expect(status.textContent).toMatch(/^Pro \+ AI · renews .*2026/);
      expect(status.querySelector('bdi')?.getAttribute('dir')).toBe('ltr');
      expect(screen.queryByText(/Pro active/)).toBeNull();
      fireEvent.click(screen.getByRole('button', { name: 'Manage subscription' }));
      expect(mocks.tabsCreate).toHaveBeenCalledWith({ url: 'https://vietnamcos.com/palette/account' });
    });

    it('says "ends" when the server reports the subscription cancelled', async () => {
      aiOnly({ subStatus: 'cancelled' });
      render(<App />);
      await openMenu();
      expect((await screen.findByText(/Pro \+ AI · ends/)).textContent).toMatch(/2026/);
      expect(screen.queryByText(/renews/)).toBeNull();
    });

    it('an ended plan shows "Pro + AI ended <date>" with Renew (checkout plan=ai) and still offers Enter license key', async () => {
      aiOnly({ lastCheck: 'ended' }, false);
      render(<App />);
      await openMenu();
      expect((await screen.findByText(/Pro \+ AI ended/)).textContent).toMatch(/2026/);
      expect(screen.queryByRole('button', { name: 'Manage subscription' })).toBeNull();
      expect(screen.getByRole('button', { name: 'Enter license key' })).toBeTruthy();
      fireEvent.click(screen.getByRole('button', { name: 'Renew' }));
      expect(mocks.tabsCreate).toHaveBeenCalledWith({ url: 'https://vietnamcos.com/palette/checkout?lang=en&plan=ai' });
    });

    // Ruling A19: never offer Renew while the subscription is still active (a late renewal; Renew would bill twice).
    it('an ended plan whose subscription is still active says renewal pending, with no Renew', async () => {
      aiOnly({ lastCheck: 'ended', subStatus: 'active' }, false);
      mocks.aiState.mockResolvedValue({ active: false, paidThrough: AI.paidThrough, usage: null, endsOn: AI.paidThrough, renewalPending: true });
      render(<App />);
      await openMenu();
      expect(await screen.findByText('Renewal pending — this usually takes a few minutes')).toBeTruthy();
      expect(screen.queryByText(/Pro \+ AI ended/)).toBeNull();
      expect(screen.queryByRole('button', { name: 'Renew' })).toBeNull();
    });

    it('inside the renewal grace the plan is on: renewal pending, Manage subscription, no Renew', async () => {
      aiOnly({ subStatus: 'active' });
      mocks.aiState.mockResolvedValue({ active: true, paidThrough: AI.paidThrough, usage: null, endsOn: AI.paidThrough, renewalPending: true });
      render(<App />);
      await openMenu();
      expect(await screen.findByText('Renewal pending — this usually takes a few minutes')).toBeTruthy();
      expect(screen.queryByText(/renews/)).toBeNull();
      expect(screen.getByRole('button', { name: 'Manage subscription' })).toBeTruthy();
      expect(screen.queryByRole('button', { name: 'Renew' })).toBeNull();
    });

    it('with both licences the Pro line, the AI line and two remove buttons show; removing the AI key leaves Pro on', async () => {
      mocks.currentLicense.mockResolvedValue(PRO);
      aiOnly();
      render(<App />);
      await openMenu();
      expect(await screen.findByText(/Pro active/)).toBeTruthy();
      expect(await screen.findByText(/Pro \+ AI · renews/)).toBeTruthy();
      expect(screen.queryByRole('button', { name: 'Remove key from this browser' })).toBeNull();
      fireEvent.click(screen.getByRole('button', { name: 'Remove Pro + AI key' }));
      expect(screen.getByText(/Pro \+ AI turns off in this browser/)).toBeTruthy();
      fireEvent.click(screen.getByRole('button', { name: 'Remove key' }));
      await waitFor(() => expect(mocks.removeLicense).toHaveBeenCalledWith('ai'));
      await waitFor(() => expect(screen.queryByText(/Pro \+ AI · renews/)).toBeNull());
      expect(screen.getByText(/Pro active/)).toBeTruthy();
      expect(screen.queryByRole('button', { name: 'Enter license key' })).toBeNull();
    });

    it('removing the Pro key when both exist removes only the Pro licence', async () => {
      mocks.currentLicense.mockResolvedValue(PRO);
      aiOnly();
      render(<App />);
      await openMenu();
      fireEvent.click(await screen.findByRole('button', { name: 'Remove Pro key' }));
      fireEvent.click(screen.getByRole('button', { name: 'Remove key' }));
      await waitFor(() => expect(mocks.removeLicense).toHaveBeenCalledWith('pro'));
      expect(await screen.findByText(/Pro \+ AI · renews/)).toBeTruthy();
      expect(screen.queryByText(/Pro active/)).toBeNull();
    });

    it('with only an AI licence the single remove button removes the AI key', async () => {
      aiOnly();
      render(<App />);
      await openMenu();
      fireEvent.click(await screen.findByRole('button', { name: 'Remove key from this browser' }));
      fireEvent.click(screen.getByRole('button', { name: 'Remove key' }));
      await waitFor(() => expect(mocks.removeLicense).toHaveBeenCalledWith('ai'));
      expect(await screen.findByRole('button', { name: 'Enter license key' })).toBeTruthy();
    });
  });
});
