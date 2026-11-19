// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/preact';
import type { EditorHandlers } from '../../src/edit/client';
import { NO_EDITS, type Command, type EditorState, type Edits, type Selected, type TokenSpec } from '../../src/edit/types';
import type { ColourToken, Tokens } from '../../src/model/tokens';
import { EMPTY_TOKENS } from '../../src/model/tokens';
import { ScanError } from '../../src/scan';
import type { Settings } from '../../src/store';
import { App } from '../../src/ui/sidepanel/App';

const mocks = vi.hoisted(() => ({
  scanActiveTab: vi.fn(),
  listScans: vi.fn(),
  openEditor: vi.fn(),
  settings: { theme: 'system', lang: 'en', pro: true } as Settings,
}));

vi.mock('../../src/scan', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../src/scan')>()),
  scanActiveTab: mocks.scanActiveTab,
}));
vi.mock('../../src/license', () => ({
  recheckIfDue: async () => {}, activate: vi.fn(), checkoutUrl: () => '', lostKeyUrl: () => '',
  currentLicense: async () => null, currentAiLicense: async () => null, aiState: async () => ({ active: false, paidThrough: null, usage: null }),
  accountUrl: () => '', removeLicense: async () => {},
}));
vi.mock('../../src/store', () => ({
  getSettings: async () => mocks.settings,
  setSettings: vi.fn(),
  listScans: mocks.listScans,
  saveScan: async () => {},
  onSettingsChange: () => () => {},
}));
vi.mock('../../src/edit/client', () => ({ openEditor: mocks.openEditor }));

const c = (role: ColourToken['role'], name: string, hex: string): ColourToken => ({ role, name, hex, weight: 0.2, interactive: false });
const brewline = (): Tokens => ({
  ...EMPTY_TOKENS('brewline.co'),
  scannedAt: '2026-10-16T10:00:00.000Z',
  colours: [c('primary', 'primary', '#1F5E4B'), c('surface', 'surface', '#F3EEE4'), c('text', 'text', '#1B1A17')],
  fonts: [{ name: 'body', family: 'DM Sans' }],
  type: [{ name: 'body', family: 'DM Sans', sizePx: 16, weight: 400, lineHeightPx: 26, chars: 900 }],
  spacing: { base: 4, scale: [4, 8, 16] },
  radius: [4, 10],
});

/** What the fake page editor was opened with, the commands it received, and a way to answer as the page would. */
let opened: { specs: TokenSpec[]; replay?: Pick<Edits, 'values' | 'added'>; handlers: EditorHandlers; sent: Command[]; closed: boolean }[];
const last = () => opened[opened.length - 1];
const pageSays = (over: Partial<EditorState>) =>
  act(() => last().handlers.onState({ picking: true, selected: null, uses: { 'colour:primary': 6 }, edits: NO_EDITS(), ...over }));

const button: Selected = {
  el: 3, desc: { tag: 'a', text: 'Order now', hook: '.btn', inside: '' }, width: 120, height: 40, text: 'Order now',
  rows: [
    { key: 'background-color', label: 'background', kind: 'colour', value: '#1F5E4B', token: 'colour:primary', overridden: false },
    { key: 'border-radius', label: 'radius', kind: 'radius', value: 10, token: 'radius:10', overridden: false },
    { key: 'color', label: 'color', kind: 'colour', value: '#C80078', token: null, overridden: false },
  ],
  align: null, valign: null, canValign: true,
};

async function startEditing() {
  render(<App />);
  await screen.findAllByText('#1F5E4B'); // the toggle is disabled until there is a scan to edit
  fireEvent.click(screen.getByRole('button', { name: 'Edit on page' }));
  await screen.findByText('Click anything on the page to see the tokens it uses.');
}

beforeEach(() => {
  opened = [];
  mocks.listScans.mockReset().mockResolvedValue([brewline()]);
  mocks.scanActiveTab.mockReset().mockResolvedValue(brewline());
  mocks.openEditor.mockReset().mockImplementation(async (specs, replay, handlers) => {
    const session = { specs, replay, handlers, sent: [] as Command[], closed: false };
    opened.push(session);
    queueMicrotask(() => handlers.onState({ picking: true, selected: null, uses: {}, edits: NO_EDITS() }));
    return { send: (cmd: Command) => { session.sent.push(cmd); }, close: () => { session.closed = true; } };
  });
  (globalThis as any).chrome = { tabs: { query: async () => [{ id: 7, url: 'https://brewline.co/menu' }] } };
});
afterEach(() => { cleanup(); delete (globalThis as any).chrome; });

describe('Edit on page', () => {
  it('opens the page editor with every editable token and lists them as controls', async () => {
    await startEditing();
    expect(last().specs.map((s) => s.id)).toEqual([
      'colour:primary', 'colour:surface', 'colour:text', 'type:body', 'space:4', 'space:8', 'space:16', 'radius:4', 'radius:10',
    ]);
    expect(last().replay).toBeUndefined();
    expect(screen.getByRole('button', { name: 'Edit on page' }).getAttribute('aria-pressed')).toBe('true');
    const colours = screen.getByRole('region', { name: 'Colours' });
    fireEvent.input(within(colours).getAllByLabelText('Hex value')[0], { target: { value: '#7a3fc4' } });
    expect(last().sent).toContainEqual({ type: 'setToken', id: 'colour:primary', value: '#7a3fc4' });
    fireEvent.click(within(screen.getByRole('region', { name: 'Radius' })).getAllByRole('button', { name: 'Increase' })[1]);
    expect(last().sent).toContainEqual({ type: 'setToken', id: 'radius:10', value: 11 });
  });

  it('edits the picked element by token or as a one-off, and aligns it', async () => {
    await startEditing();
    await pageSays({ selected: button });
    const insp = screen.getByText('Order now', { selector: 'b' }).closest('.ed-insp') as HTMLElement;
    expect(within(insp).getByText('6 elements')).toBeTruthy();
    expect(within(insp).getByText(/No token matches/)).toBeTruthy();

    fireEvent.input(within(insp).getAllByLabelText('Hex value')[0], { target: { value: '#0E7490' } });
    expect(last().sent).toContainEqual({ type: 'setToken', id: 'colour:primary', value: '#0E7490' });

    fireEvent.click(within(insp).getByLabelText('Only this element'));
    fireEvent.input(within(insp).getByLabelText('Value in pixels'), { target: { value: '24' } });
    expect(last().sent).toContainEqual({ type: 'setOneOff', el: 3, row: 'border-radius', value: 24 });

    fireEvent.click(within(within(insp).getByRole('group', { name: 'Vertical alignment' })).getByRole('button', { name: 'Bottom' }));
    expect(last().sent).toContainEqual({ type: 'setAlign', el: 3, axis: 'v', value: 'bottom' });
    fireEvent.input(within(insp).getByLabelText('Text'), { target: { value: 'Order today' } });
    expect(last().sent).toContainEqual({ type: 'setText', el: 3, text: 'Order today' });
  });

  it('lists every change with its own undo, and exports the edited tokens with the layout notes', async () => {
    await startEditing();
    const target = { tag: 'h1', text: 'Fresh coffee', hook: '', inside: '' };
    await pageSays({
      edits: {
        values: { 'colour:primary': '#7A3FC4' },
        added: [{ id: 'radius:+1', kind: 'radius', name: 'radius-new', from: 'radius:10', value: 24 }],
        oneOffs: [{ el: 3, target: button.desc, row: 'color', label: 'color', kind: 'colour', value: '#000000', token: 'colour:surface' }],
        page: [{ el: 5, type: 'align', target, value: 'center' }],
      },
    });
    const group = (title: string) => screen.getByText(title).closest('.ed-group') as HTMLElement;
    fireEvent.click(within(group('Token changes')).getAllByRole('button', { name: 'Undo' })[0]);
    expect(last().sent).toContainEqual({ type: 'setToken', id: 'colour:primary', value: '#1F5E4B' });
    fireEvent.click(within(group('Token changes')).getAllByRole('button', { name: 'Undo' })[1]);
    expect(last().sent).toContainEqual({ type: 'removeToken', id: 'radius:+1' });
    fireEvent.click(within(group('One-off element edits')).getByRole('button', { name: 'Save as new token' }));
    expect(last().sent).toContainEqual({ type: 'promote', el: 3, row: 'color', mode: 'new' });
    fireEvent.click(within(group('One-off element edits')).getByRole('button', { name: 'Update surface' }));
    expect(last().sent).toContainEqual({ type: 'promote', el: 3, row: 'color', mode: 'update' });
    fireEvent.click(within(group('Page edits')).getByRole('button', { name: 'Undo' }));
    expect(last().sent).toContainEqual({ type: 'undoPage', el: 5, edit: 'align' });
    expect(screen.getByText('Token changes: 2 · One-off edits: 1 · Page edits: 1')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: /Export design system/ }));
    const md = (await screen.findByLabelText('Preview of DESIGN.md')).textContent!;
    expect(md).toContain('| primary | `--color-primary` | #7A3FC4 |');
    expect(md).toContain('- `--radius-lg`: 24px');
    expect(md).not.toContain('#000000');
    expect(md).toContain('## Layout adjustments');
    expect(md).toContain('- Align the h1 “Fresh coffee” centre.');
  });

  it('turning it off keeps the changes on the page and in the read-only view', async () => {
    await startEditing();
    await pageSays({ edits: { ...NO_EDITS(), values: { 'colour:primary': '#7A3FC4' } } });
    fireEvent.click(screen.getByRole('button', { name: 'Edit on page' }));
    expect(last().sent).toContainEqual({ type: 'pick', on: false });
    expect(last().closed).toBe(false);
    expect((await screen.findAllByText('#7A3FC4')).length).toBeGreaterThan(0);
    expect(screen.queryByText('#1F5E4B')).toBeNull();
    expect(screen.getByText('Token changes: 1 · One-off edits: 0 · Page edits: 0')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Edit on page' }));
    expect(last().sent).toContainEqual({ type: 'pick', on: true });
    expect(opened).toHaveLength(1);
  });

  it('when the page goes away it keeps the token changes, says what was cleared, and replays them next time', async () => {
    await startEditing();
    await pageSays({
      edits: { ...NO_EDITS(), values: { 'colour:primary': '#7A3FC4' }, page: [{ el: 5, type: 'align', target: button.desc, value: 'center' }] },
    });
    act(() => last().handlers.onLost());
    expect(await screen.findByText(/The page changed, so one-off and page edits were cleared/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Edit on page' }).getAttribute('aria-pressed')).toBe('false');
    expect(screen.getByText('Token changes: 1 · One-off edits: 0 · Page edits: 0')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Edit on page' }));
    await screen.findByText('Click anything on the page to see the tokens it uses.');
    expect(opened).toHaveLength(2);
    expect(last().replay).toEqual({ values: { 'colour:primary': '#7A3FC4' }, added: [] });
  });

  it('re-scanning ends the session first, so the scan reads the page as the site made it', async () => {
    await startEditing();
    await pageSays({ edits: { ...NO_EDITS(), values: { 'colour:primary': '#7A3FC4' } } });
    fireEvent.click(screen.getByRole('button', { name: 'Re-scan page' }));
    expect(last().closed).toBe(true);
    expect((await screen.findAllByText('#1F5E4B')).length).toBeGreaterThan(0);
    expect(screen.queryByText('#7A3FC4')).toBeNull();
  });

  it('shows why the editor could not start', async () => {
    mocks.openEditor.mockRejectedValue(new ScanError('no-access'));
    render(<App />);
    await screen.findAllByText('#1F5E4B');
    fireEvent.click(screen.getByRole('button', { name: 'Edit on page' }));
    expect(await screen.findByText('Click the Site to Design Token icon on this page to scan it.')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Edit on page' }).getAttribute('aria-pressed')).toBe('false');
  });
});
