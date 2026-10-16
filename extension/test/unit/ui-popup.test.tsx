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
  setSettings: vi.fn(),
  saveScan: vi.fn(),
  settings: { theme: 'system', lang: '', pro: false } as Settings,
}));

vi.mock('../../src/scan', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../src/scan')>()),
  scanActiveTab: mocks.scanActiveTab,
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
  mocks.setSettings.mockReset().mockResolvedValue(undefined);
  mocks.saveScan.mockReset().mockResolvedValue(undefined);
  writeText.mockClear();
  Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
});
afterEach(cleanup);

describe('popup App', () => {
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

  it('renders English with an English lang attribute for an unshipped browser language', async () => {
    mocks.settings = { ...mocks.settings, lang: 'es' };
    mocks.scanActiveTab.mockResolvedValue({ ...brewline(), colours: [c('primary', 'primary', '#1F5E4B', 1)], fonts: [{ name: 'body', family: 'Inter' }] });
    render(<App />);
    expect(await screen.findByText('brewline.co · 1 colour · 1 font')).toBeTruthy();
    expect(document.documentElement.lang).toBe('en');
    expect(document.documentElement.dir).toBe('ltr');
    fireEvent.click(screen.getByRole('button', { name: 'Settings' }));
    expect((screen.getByRole('radio', { name: 'English' }) as HTMLInputElement).checked).toBe(true);
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
});
