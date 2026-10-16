// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/preact';
import type { ColourToken, Tokens } from '../../src/model/tokens';
import { EMPTY_TOKENS } from '../../src/model/tokens';
import { ScanError } from '../../src/scan';
import type { Settings } from '../../src/store';
import { App as PopupApp } from '../../src/ui/popup/App';
import { addDictionary } from '../../src/ui/i18n';
import { App } from '../../src/ui/sidepanel/App';

const mocks = vi.hoisted(() => ({
  scanActiveTab: vi.fn(),
  listScans: vi.fn(),
  saveScan: vi.fn(),
  setSettings: vi.fn(),
  settings: { theme: 'system', lang: '', pro: false } as Settings,
  listeners: [] as ((s: Settings) => void)[],
}));

vi.mock('../../src/scan', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../src/scan')>()),
  scanActiveTab: mocks.scanActiveTab,
}));
vi.mock('../../src/store', () => ({
  getSettings: async () => mocks.settings,
  setSettings: mocks.setSettings,
  listScans: mocks.listScans,
  saveScan: mocks.saveScan,
  onSettingsChange: (cb: (s: Settings) => void) => {
    mocks.listeners.push(cb);
    return () => { mocks.listeners = mocks.listeners.filter((l) => l !== cb); };
  },
}));

const c = (role: ColourToken['role'], name: string, hex: string, weight: number): ColourToken =>
  ({ role, name, hex, weight, interactive: false });

const brewline = (over: Partial<Tokens> = {}): Tokens => ({
  ...EMPTY_TOKENS('brewline.co'),
  scannedAt: '2026-10-16T10:00:00.000Z',
  colours: [
    c('primary', 'primary', '#1F5E4B', 0.41),
    c('accent', 'accent', '#E0763A', 0.07),
    c('surface', 'surface', '#F3EEE4', 0.18),
    c('text', 'text', '#1B1A17', 0.11),
  ],
  fonts: [{ name: 'body', family: 'DM Sans' }],
  type: [
    { name: 'h1', family: 'Fraunces, serif', sizePx: 48, weight: 600, lineHeightPx: 56, chars: 20 },
    { name: 'body', family: 'DM Sans', sizePx: 16, weight: 400, lineHeightPx: 26, chars: 900 },
  ],
  spacing: { base: 4, scale: [4, 8, 16] },
  radius: [4, 10],
  shadows: ['0 1px 2px rgba(0,0,0,.5)'],
  ...over,
});

const tab = { id: 7, url: 'https://brewline.co/menu' };
const sidePanel = { setOptions: vi.fn(), open: vi.fn() };
const tabsQuery = vi.fn();

beforeEach(() => {
  mocks.settings = { theme: 'system', lang: '', pro: false };
  mocks.listeners = [];
  mocks.scanActiveTab.mockReset().mockResolvedValue(brewline());
  mocks.listScans.mockReset().mockResolvedValue([]);
  mocks.saveScan.mockReset().mockResolvedValue(undefined);
  mocks.setSettings.mockReset().mockResolvedValue(undefined);
  sidePanel.setOptions.mockReset().mockResolvedValue(undefined);
  sidePanel.open.mockReset().mockResolvedValue(undefined);
  tabsQuery.mockReset().mockResolvedValue([tab]);
  (globalThis as any).chrome = { tabs: { query: tabsQuery }, sidePanel };
});
afterEach(() => { cleanup(); delete (globalThis as any).chrome; });

describe('side panel App', () => {
  it('shows the newest saved scan for the active tab host without rescanning', async () => {
    mocks.listScans.mockResolvedValue([
      brewline({ site: 'other.dev', colours: [c('primary', 'primary', '#123456', 1)] }),
      brewline({ scannedAt: '2026-10-15T10:00:00.000Z', colours: [c('primary', 'primary', '#AAAAAA', 1)] }),
      brewline(),
    ]);
    render(<App />);
    expect((await screen.findAllByText('#1F5E4B')).length).toBeGreaterThan(0);
    expect(screen.queryByText('#AAAAAA')).toBeNull();
    expect(screen.queryByText('#123456')).toBeNull();
    expect(mocks.scanActiveTab).not.toHaveBeenCalled();
  });

  it('scans and saves when there is no saved scan for the host', async () => {
    mocks.listScans.mockResolvedValue([brewline({ site: 'other.dev' })]);
    render(<App />);
    await screen.findAllByText('#1F5E4B');
    expect(mocks.scanActiveTab).toHaveBeenCalledTimes(1);
    expect(mocks.saveScan).toHaveBeenCalledWith(brewline());
  });

  it('Re-scan scans again and saves', async () => {
    mocks.listScans.mockResolvedValue([brewline()]);
    mocks.scanActiveTab.mockResolvedValue(brewline({ colours: [c('primary', 'primary', '#0A0B0C', 1)] }));
    render(<App />);
    await screen.findAllByText('#1F5E4B');
    fireEvent.click(screen.getByRole('button', { name: 'Re-scan page' }));
    expect((await screen.findAllByText('#0A0B0C')).length).toBeGreaterThan(0);
    expect(mocks.scanActiveTab).toHaveBeenCalledTimes(1);
    expect(mocks.saveScan).toHaveBeenCalledTimes(1);
  });

  it('renders sections in order with a proportional strip and role legend', async () => {
    mocks.listScans.mockResolvedValue([brewline()]);
    const { container } = render(<App />);
    await screen.findAllByText('#1F5E4B');
    expect(screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent))
      .toEqual(['Colours', 'Typography', 'Spacing · 4px base', 'Radius', 'Shadows']);
    const segs = [...container.querySelectorAll<HTMLElement>('.strip-seg')];
    expect(segs.map((s) => s.style.flexGrow)).toEqual(['0.41', '0.07', '0.18', '0.11']);
    const legend = screen.getByRole('list', { name: 'Colour roles' });
    expect(within(legend).getAllByRole('listitem').map((li) => li.textContent))
      .toEqual(['Primary#1F5E4B', 'Accent#E0763A', 'Surface#F3EEE4', 'Text#1B1A17']);
    expect(screen.getByText('Aa Heading 1')).toBeTruthy();
  });

  it('hides empty sections', async () => {
    mocks.listScans.mockResolvedValue([brewline({ radius: [], shadows: [], spacing: { base: null, scale: [] }, type: [] })]);
    render(<App />);
    await screen.findAllByText('#1F5E4B');
    expect(screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent)).toEqual(['Colours']);
  });

  it('shows View only for free users, not for pro', async () => {
    mocks.listScans.mockResolvedValue([brewline()]);
    const { unmount } = render(<App />);
    expect(await screen.findByText('View only')).toBeTruthy();
    unmount();
    mocks.settings = { ...mocks.settings, pro: true };
    render(<App />);
    await screen.findAllByText('#1F5E4B');
    expect(screen.queryByText('View only')).toBeNull();
  });

  it('switches to the export view from the footer and honours initialView', async () => {
    mocks.listScans.mockResolvedValue([brewline()]);
    const { unmount } = render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: /Export design system/ }));
    expect(await screen.findByText('Export is coming in the next update.')).toBeTruthy();
    expect(screen.queryByText('#1F5E4B')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Back to design system' }));
    expect((await screen.findAllByText('#1F5E4B')).length).toBeGreaterThan(0);
    unmount();
    render(<App initialView="export" />);
    expect(await screen.findByText('Export is coming in the next update.')).toBeTruthy();
  });

  it('shows the format caption under the export button', async () => {
    mocks.listScans.mockResolvedValue([brewline()]);
    render(<App />);
    expect(await screen.findByText('CSS · Tailwind · Figma · JSON · DESIGN.md for AI')).toBeTruthy();
  });

  it('shows restricted and failed states', async () => {
    mocks.scanActiveTab.mockRejectedValueOnce(new ScanError('restricted'));
    const { unmount } = render(<App />);
    expect(await screen.findByText("This page can't be scanned")).toBeTruthy();
    unmount();
    mocks.scanActiveTab.mockRejectedValueOnce(new ScanError('failed')).mockResolvedValueOnce(brewline());
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: 'Retry' }));
    expect((await screen.findAllByText('#1F5E4B')).length).toBeGreaterThan(0);
  });

  it('follows language changes made elsewhere', async () => {
    mocks.listScans.mockResolvedValue([brewline()]);
    render(<App />);
    await screen.findAllByText('#1F5E4B');
    addDictionary('es', { panel: { title: 'Sistema de diseño' } });
    expect(screen.getByText('Design system')).toBeTruthy();
    act(() => mocks.listeners.forEach((l) => l({ ...mocks.settings, lang: 'es' })));
    expect(screen.getByText('Sistema de diseño')).toBeTruthy();
    expect(document.documentElement.lang).toBe('es');
  });

  it('follows theme and plan changes made elsewhere via onSettingsChange', async () => {
    mocks.listScans.mockResolvedValue([brewline()]);
    mocks.settings = { ...mocks.settings, theme: 'light' };
    render(<App />);
    await screen.findAllByText('#1F5E4B');
    expect(document.documentElement.dataset.theme).toBe('light');
    act(() => mocks.listeners.forEach((l) => l({ ...mocks.settings, theme: 'dark' })));
    expect(document.documentElement.dataset.theme).toBe('dark');
    act(() => mocks.listeners.forEach((l) => l({ ...mocks.settings, theme: 'dark', pro: true })));
    await waitFor(() => expect(screen.queryByText('View only')).toBeNull());
  });
});

describe('popup opens the side panel', () => {
  const clickWhenReady = async (name: string | RegExp) => {
    const btn = await screen.findByRole('button', { name });
    await waitFor(() => expect(tabsQuery).toHaveBeenCalled());
    await act(async () => { await Promise.resolve(); });
    tabsQuery.mockClear();
    fireEvent.click(btn);
  };

  it('Open full view opens the system view using the cached tab, inside the click', async () => {
    render(<PopupApp />);
    await clickWhenReady('Open full view');
    // synchronous: no awaited tabs.query before open, setOptions not awaited before open
    expect(tabsQuery).not.toHaveBeenCalled();
    expect(sidePanel.open).toHaveBeenCalledWith({ tabId: 7 });
    expect(sidePanel.setOptions).toHaveBeenCalledWith({ tabId: 7, path: 'sidepanel.html?view=system', enabled: true });
    expect(sidePanel.setOptions.mock.invocationCallOrder[0]).toBeLessThan(sidePanel.open.mock.invocationCallOrder[0]);
    expect(screen.queryByRole('status')).toBeNull();
  });

  it('Export tokens opens the export view', async () => {
    render(<PopupApp />);
    await clickWhenReady(/Export tokens/);
    expect(sidePanel.open).toHaveBeenCalledWith({ tabId: 7 });
    expect(sidePanel.setOptions).toHaveBeenCalledWith({ tabId: 7, path: 'sidepanel.html?view=export', enabled: true });
  });

  it('tells the user when the panel fails to open', async () => {
    const err = vi.spyOn(console, 'error').mockImplementation(() => {});
    sidePanel.open.mockRejectedValue(new Error('gesture'));
    render(<PopupApp />);
    await clickWhenReady('Open full view');
    expect((await screen.findByRole('status')).textContent).toContain("Couldn't open the full view.");
    expect(err).toHaveBeenCalled();
    err.mockRestore();
  });

  it('tells the user when there is no active tab', async () => {
    const err = vi.spyOn(console, 'error').mockImplementation(() => {});
    tabsQuery.mockResolvedValue([]);
    render(<PopupApp />);
    await clickWhenReady('Open full view');
    expect((await screen.findByRole('status')).textContent).toContain("Couldn't open the full view.");
    expect(sidePanel.open).not.toHaveBeenCalled();
    err.mockRestore();
  });
});
