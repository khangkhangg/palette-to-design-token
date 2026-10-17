// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/preact';
import { exportAll, exportFile, type Part } from '../../src/export';
import type { Settings } from '../../src/store';
import { Header } from '../../src/ui/components/Header';
import { PlanSheet } from '../../src/ui/components/PlanSheet';
import { ExportScreen } from '../../src/ui/sidepanel/ExportScreen';
import { brewline } from './export/brewline.tokens';

const { download } = await vi.importActual<typeof import('../../src/ui/download')>('../../src/ui/download');
const mocks = vi.hoisted(() => ({ download: vi.fn() }));
vi.mock('../../src/ui/download', () => ({ download: mocks.download }));

const ALL_PARTS: Part[] = ['colours', 'typography', 'spacing', 'radius', 'shadows'];
const writeText = vi.fn();

beforeEach(() => {
  mocks.download.mockReset();
  writeText.mockReset().mockResolvedValue(undefined);
  Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
});
afterEach(() => { cleanup(); vi.unstubAllEnvs(); });

const preview = () => document.querySelector('pre')!.textContent;
const pick = (name: string) => fireEvent.click(screen.getByRole('radio', { name: new RegExp(`^${name}`) }));
const toggle = (name: string) => fireEvent.click(screen.getByRole('checkbox', { name }));

describe('ExportScreen', () => {
  it('lists formats in order with DESIGN.md selected first', () => {
    render(<ExportScreen tokens={brewline} pro onBack={() => {}} />);
    const radios = screen.getAllByRole('radio', { name: /^(DESIGN\.md|CSS|Tailwind|Figma|JSON|All)/ });
    expect(radios.map((r) => r.closest('label')!.querySelector('.fmt-label')!.textContent))
      .toEqual(['DESIGN.md', 'CSS', 'Tailwind', 'Figma', 'JSON', 'All']);
    expect((radios[0] as HTMLInputElement).checked).toBe(true);
    expect(preview()).toBe(exportFile(brewline, 'md', { parts: ALL_PARTS }).text);
    expect(screen.getByRole('button', { name: /Download DESIGN\.md/ })).toBeTruthy();
  });

  it('shows the Pro badge only when pro', () => {
    const { unmount } = render(<ExportScreen tokens={brewline} pro onBack={() => {}} />);
    expect(screen.getByText('Pro unlocked')).toBeTruthy();
    unmount();
    render(<ExportScreen tokens={brewline} pro={false} onBack={() => {}} />);
    expect(screen.queryByText('Pro unlocked')).toBeNull();
  });

  it('Back calls onBack', () => {
    const onBack = vi.fn();
    render(<ExportScreen tokens={brewline} pro onBack={onBack} />);
    fireEvent.click(screen.getByRole('button', { name: 'Back to design system' }));
    expect(onBack).toHaveBeenCalled();
  });

  it('Tailwind shows a v4/v3 toggle that switches the preview', () => {
    render(<ExportScreen tokens={brewline} pro onBack={() => {}} />);
    expect(screen.queryByRole('radio', { name: 'v4' })).toBeNull();
    pick('Tailwind');
    expect((screen.getByRole('radio', { name: 'v4' }) as HTMLInputElement).checked).toBe(true);
    expect(preview()).toBe(exportFile(brewline, 'tailwind', { parts: ALL_PARTS, tailwind: 4 }).text);
    fireEvent.click(screen.getByRole('radio', { name: 'v3' }));
    expect(preview()).toBe(exportFile(brewline, 'tailwind', { parts: ALL_PARTS, tailwind: 3 }).text);
    expect(screen.getByRole('button', { name: /Download tailwind\.config\.js/ })).toBeTruthy();
    pick('CSS');
    expect(screen.queryByRole('radio', { name: 'v3' })).toBeNull();
  });

  it('Include chips default on, change the preview, and the last one cannot be unchecked', () => {
    render(<ExportScreen tokens={brewline} pro onBack={() => {}} />);
    pick('CSS');
    for (const label of ['Colours', 'Typography', 'Spacing', 'Radius', 'Shadows']) {
      expect((screen.getByRole('checkbox', { name: label }) as HTMLInputElement).checked).toBe(true);
    }
    toggle('Typography');
    toggle('Shadows');
    const parts: Part[] = ['colours', 'spacing', 'radius'];
    expect(preview()).toBe(exportFile(brewline, 'css', { parts }).text);
    expect(preview()).not.toBe(exportFile(brewline, 'css', { parts: ALL_PARTS }).text);
    toggle('Spacing');
    toggle('Radius');
    const last = screen.getByRole('checkbox', { name: 'Colours' }) as HTMLInputElement;
    expect(last.checked).toBe(true);
    expect(last.disabled).toBe(true);
    expect(preview()).toBe(exportFile(brewline, 'css', { parts: ['colours'] }).text);
  });

  it('Copy writes exactly the preview to the clipboard', async () => {
    render(<ExportScreen tokens={brewline} pro onBack={() => {}} />);
    pick('JSON');
    fireEvent.click(screen.getByRole('button', { name: 'Copy' }));
    expect(writeText).toHaveBeenCalledWith(exportFile(brewline, 'json', { parts: ALL_PARTS }).text);
    expect(await screen.findByText('Copied')).toBeTruthy();
  });

  it('says Couldn\'t copy when the clipboard write fails', async () => {
    writeText.mockRejectedValueOnce(new Error('denied'));
    render(<ExportScreen tokens={brewline} pro onBack={() => {}} />);
    fireEvent.click(screen.getByRole('button', { name: 'Copy' }));
    expect(await screen.findByText("Couldn't copy")).toBeTruthy();
    expect(screen.queryByText('Copied')).toBeNull();
  });

  it('Download passes filename, text and mime', () => {
    render(<ExportScreen tokens={brewline} pro onBack={() => {}} />);
    pick('Figma');
    fireEvent.click(screen.getByRole('button', { name: /Download figma-variables\.json/ }));
    const e = exportFile(brewline, 'figma', { parts: ALL_PARTS });
    expect(mocks.download).toHaveBeenCalledWith('figma-variables.json', e.text, 'application/json');
  });

  it('All previews the zip contents, disables Copy, and downloads the zip bytes', async () => {
    render(<ExportScreen tokens={brewline} pro onBack={() => {}} />);
    pick('All');
    const zip = await exportAll(brewline, { parts: ALL_PARTS });
    await waitFor(() => expect(preview()).toContain('tokens.css'));
    for (const f of ['tailwind.css', 'figma-variables.json', 'tokens.json', 'DESIGN.md']) expect(preview()).toContain(f);
    expect(preview()).toContain(zip.filename);
    expect(preview()).toContain(`5 files · ${Math.max(1, Math.round(zip.bytes.length / 1024))} KB`);
    expect((screen.getByRole('button', { name: 'Copy' }) as HTMLButtonElement).disabled).toBe(true);
    fireEvent.click(screen.getByRole('button', { name: /Download brewline-co-tokens\.zip/ }));
    await waitFor(() => expect(mocks.download).toHaveBeenCalled());
    const [filename, data, mime] = mocks.download.mock.calls[0];
    expect(filename).toBe('brewline-co-tokens.zip');
    expect(data).toBeInstanceOf(Uint8Array);
    expect(mime).toBe('application/zip');
  });

  it('free users: Copy, Download and the zip open the unlock sheet and do nothing else', () => {
    render(<ExportScreen tokens={brewline} pro={false} onBack={() => {}} />);
    expect(preview()).toBe(exportFile(brewline, 'md', { parts: ALL_PARTS }).text);
    expect(screen.queryByRole('dialog')).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Copy' }));
    expect(screen.getByRole('dialog')).toBeTruthy();
    expect(writeText).not.toHaveBeenCalled();
    expect(preview()).toBeTruthy();
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.queryByRole('dialog')).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: /Download DESIGN\.md/ }));
    expect(screen.getByRole('dialog')).toBeTruthy();
    fireEvent.keyDown(document, { key: 'Escape' });

    pick('All');
    fireEvent.click(screen.getByRole('button', { name: /Download brewline-co-tokens\.zip/ }));
    expect(screen.getByRole('dialog')).toBeTruthy();
    expect(mocks.download).not.toHaveBeenCalled();
  });
});

describe('PlanSheet', () => {
  it('overlay has dialog semantics, moves focus in, closes on Escape and returns focus', async () => {
    const trigger = document.createElement('button');
    document.body.append(trigger);
    trigger.focus();
    const onClose = vi.fn();
    const { unmount } = render(<PlanSheet variant="overlay" onClose={onClose} />);
    const dialog = screen.getByRole('dialog');
    expect(dialog.getAttribute('aria-modal')).toBe('true');
    expect(dialog.getAttribute('aria-labelledby')).toBeTruthy();
    expect(dialog.contains(document.activeElement)).toBe(true);
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(onClose).toHaveBeenCalled();
    unmount();
    await waitFor(() => expect(document.activeElement).toBe(trigger));
    trigger.remove();
  });

  it('overlay close button and backdrop click close it', () => {
    const onClose = vi.fn();
    render(<PlanSheet variant="overlay" onClose={onClose} />);
    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    fireEvent.click(document.querySelector('.sheet-backdrop')!);
    expect(onClose).toHaveBeenCalledTimes(2);
  });

  it('shows both plans with prices, a live PayPal button, the refund line and key entry', () => {
    render(<PlanSheet variant="overlay" onClose={() => {}} />);
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText('$1.49')).toBeTruthy();
    expect(within(dialog).getByText('$3.99')).toBeTruthy();
    expect(dialog.querySelectorAll('.plan-card').length).toBe(2);
    expect(dialog.querySelectorAll('.plan-items li').length).toBe(8);
    const paypal = within(dialog).getByRole('button', { name: /Pay \$1\.49 with PayPal/ }) as HTMLButtonElement;
    expect(paypal.disabled).toBe(false);
    expect(within(dialog).getByText(
      'All sales are final. Because Pro unlocks instantly and costs $1.49, we do not offer refunds after purchase.',
    )).toBeTruthy();
    fireEvent.click(within(dialog).getByRole('button', { name: 'Enter it' }));
    const key = within(dialog).getByLabelText('License key') as HTMLInputElement;
    expect(key.placeholder).toBe('SDT-XXXX-XXXX-XXXX');
    expect(key.disabled).toBe(false);
    expect(within(dialog).getByRole('button', { name: 'Activate' })).toBeTruthy();
  });

  it('page variant is not a dialog and has a Back button', () => {
    const onClose = vi.fn();
    render(<PlanSheet variant="page" onClose={onClose} />);
    expect(screen.queryByRole('dialog')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Back to design system' }));
    expect(onClose).toHaveBeenCalled();
  });
});

describe('download', () => {
  it('creates an object URL, clicks a download link, then revokes the URL', () => {
    vi.useFakeTimers();
    const create = vi.fn(() => 'blob:abc');
    const revoke = vi.fn();
    Object.assign(URL, { createObjectURL: create, revokeObjectURL: revoke });
    let clicked: HTMLAnchorElement | undefined;
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) { clicked = this; });
    download('tokens.css', ':root{}', 'text/css');
    expect(create).toHaveBeenCalledTimes(1);
    const blob = (create.mock.calls[0] as unknown as [Blob])[0];
    expect(blob.type).toBe('text/css');
    expect(clicked!.download).toBe('tokens.css');
    expect(clicked!.href).toBe('blob:abc');
    vi.runAllTimers();
    expect(revoke).toHaveBeenCalledWith('blob:abc');
    expect(document.querySelector('a[download]')).toBeNull();
    click.mockRestore();
    vi.useRealTimers();
  });

  it('accepts bytes', () => {
    const create = vi.fn(() => 'blob:z');
    Object.assign(URL, { createObjectURL: create, revokeObjectURL: vi.fn() });
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
    download('a.zip', new Uint8Array([1, 2, 3]), 'application/zip');
    expect((create.mock.calls[0] as unknown as [Blob])[0].size).toBe(3);
    click.mockRestore();
  });
});

describe('Dev Pro toggle', () => {
  const props = (over = {}) => ({
    theme: 'system' as Settings['theme'], lang: 'en', busy: false,
    onTheme: () => {}, onLang: () => {}, onRescan: () => {}, ...over,
  });

  it('is in the settings menu in development builds and toggles pro', () => {
    const onTogglePro = vi.fn();
    render(<Header {...props({ onTogglePro })} />);
    fireEvent.click(screen.getByRole('button', { name: 'Settings' }));
    fireEvent.click(screen.getByRole('button', { name: 'Dev: toggle Pro' }));
    expect(onTogglePro).toHaveBeenCalled();
  });

  it('is absent from production builds', () => {
    vi.stubEnv('DEV', false);
    render(<Header {...props({ onTogglePro: () => {} })} />);
    fireEvent.click(screen.getByRole('button', { name: 'Settings' }));
    expect(screen.queryByText('Dev: toggle Pro')).toBeNull();
  });
});
