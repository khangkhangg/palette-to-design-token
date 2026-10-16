// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/preact';
import type { ContrastPair, Tokens } from '../../src/model/tokens';
import { EMPTY_TOKENS } from '../../src/model/tokens';
import type { Settings } from '../../src/store';
import { ContrastTab, formatRatio } from '../../src/ui/popup/ContrastTab';
import { SpacingTab } from '../../src/ui/popup/SpacingTab';
import { TypeTab } from '../../src/ui/popup/TypeTab';
import { App } from '../../src/ui/popup/App';

const mocks = vi.hoisted(() => ({
  scanActiveTab: vi.fn(),
  settings: { theme: 'system', lang: '', pro: false } as Settings,
}));
vi.mock('../../src/scan', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../src/scan')>()),
  scanActiveTab: mocks.scanActiveTab,
}));
vi.mock('../../src/store', () => ({
  getSettings: async () => mocks.settings,
  setSettings: vi.fn().mockResolvedValue(undefined),
  saveScan: vi.fn().mockResolvedValue(undefined),
  onSettingsChange: () => () => {},
}));

afterEach(cleanup);

const pair = (over: Partial<ContrastPair>): ContrastPair => ({
  fg: '#1B1A17', bg: '#FFFDF8', ratio: 17.4, large: false, uses: 212, where: 'body text', grade: 'AAA', ...over,
});
const PAIRS: ContrastPair[] = [
  pair({}),
  pair({ fg: '#FFFFFF', bg: '#1F5E4B', ratio: 7.6, uses: 38, where: 'links', grade: 'AAA' }),
  pair({ fg: '#5C574E', bg: '#F3EEE4', ratio: 6.1, uses: 64, where: 'other', grade: 'AA' }),
  pair({
    fg: '#FFFFFF', bg: '#E0763A', ratio: 2.999, uses: 9, where: 'buttons', grade: 'fail',
    fix: { fg: '#FFFFFF', bg: '#B4501A', ratio: 4.7 },
  }),
  pair({ fg: '#777777', bg: '#FFFFFF', ratio: 4.48, large: true, uses: 1, where: 'headings', grade: 'AA-large' }),
  pair({ fg: '#333333', bg: 'none', ratio: null, uses: 3, where: 'headings', grade: 'unknown' }),
];

describe('TypeTab', () => {
  const type: Tokens['type'] = [
    { name: 'h1', family: '"Fraunces", serif', sizePx: 56, weight: 700, lineHeightPx: 62, chars: 40 },
    { name: 'body', family: 'DM Sans, sans-serif', sizePx: 16, weight: 400, lineHeightPx: null, chars: 900 },
  ];
  it('renders live samples with family, size and weight plus meta', () => {
    render(<TypeTab fonts={[{ name: 'body', family: 'DM Sans, sans-serif' }, { name: 'heading', family: 'Fraunces' }]} type={type} />);
    const h1 = screen.getByText('Aa Heading 1');
    expect(h1.style.fontSize).toBe('40px');
    expect(h1.style.fontWeight).toBe('700');
    expect(h1.style.fontFamily).toContain('Fraunces');
    expect(screen.getByText('Aa Body text').style.fontSize).toBe('16px');
    expect(screen.getByText('Fraunces 700 · 56/62')).toBeTruthy();
    expect(screen.getByText('DM Sans 400 · 16/–')).toBeTruthy();
    expect(screen.getByText('Heading')).toBeTruthy();
  });
  it('shows an empty state with nothing', () => {
    render(<TypeTab fonts={[]} type={[]} />);
    expect(screen.getByText('No type styles found')).toBeTruthy();
  });
});

describe('SpacingTab', () => {
  it('shows the base, proportional bars, radius and shadows', () => {
    const { container } = render(
      <SpacingTab spacing={{ base: 4, scale: [8, 16, 64] }} radius={[4, 9999]} shadows={['0 1px 2px #000']} />,
    );
    expect(screen.getByText('4px base')).toBeTruthy();
    const heights = [...container.querySelectorAll<HTMLElement>('.bar-fill')].map((b) => b.style.blockSize);
    expect(heights).toEqual(['8px', '16px', '64px']);
    expect(screen.getByText('4px')).toBeTruthy();
    expect(screen.getByText('Full')).toBeTruthy();
    expect(screen.getByText('Shadow')).toBeTruthy();
  });
  it('says when there is no consistent base and hides empty sections', () => {
    render(<SpacingTab spacing={{ base: null, scale: [5, 7] }} radius={[]} shadows={[]} />);
    expect(screen.getByText('No consistent base')).toBeTruthy();
    expect(screen.queryByText('Radius')).toBeNull();
    expect(screen.queryByText('Shadow')).toBeNull();
  });
  it('shows an empty state when everything is empty', () => {
    render(<SpacingTab spacing={{ base: null, scale: [] }} radius={[]} shadows={[]} />);
    expect(screen.getByText('No spacing found')).toBeTruthy();
  });
});

describe('ContrastTab', () => {
  it('summarises pass, fail and unknown counts', () => {
    const { container } = render(<ContrastTab contrast={PAIRS} pro={false} />);
    const n = (c: string) => container.querySelector(`.${c} .sum-n`)?.textContent;
    expect(n('sum-pass')).toBe('4');
    expect(n('sum-fail')).toBe('1');
    expect(n('sum-unknown')).toBe('1');
  });
  it('hides the unknown card when none are unknown', () => {
    const { container } = render(<ContrastTab contrast={PAIRS.slice(0, 3)} pro={false} />);
    expect(container.querySelector('.sum-unknown')).toBeNull();
  });
  it('renders each row with colours, where, uses, floored ratio and grade text', () => {
    render(<ContrastTab contrast={PAIRS} pro={false} />);
    expect(screen.getByText('#1B1A17 on #FFFDF8')).toBeTruthy();
    expect(screen.getByText('Body text · 212 uses')).toBeTruthy();
    expect(screen.getByText('17.4:1')).toBeTruthy();
    expect(screen.getAllByText('AAA', { selector: '.grade' })).toHaveLength(2);
    expect(screen.getByText('2.99:1')).toBeTruthy();
    expect(screen.getByText('Fails')).toBeTruthy();
    expect(screen.getByText('AA', { selector: '.grade' })).toBeTruthy();
    expect(screen.getByText('Check manually')).toBeTruthy();
    expect(screen.getByText('AA large', { selector: '.grade' })).toBeTruthy();
    expect(screen.getByText('Headings · 1 use')).toBeTruthy();
    expect(screen.getByText('#333333 on image or gradient')).toBeTruthy();
  });
  it('floors ratios instead of rounding up', () => {
    expect(formatRatio(4.499)).toBe('4.49');
    expect(formatRatio(4.5)).toBe('4.5');
    expect(formatRatio(2.9)).toBe('2.9');
  });
  it('locks the fix for free users without revealing fix colours', () => {
    const onUnlock = vi.fn();
    const { container } = render(<ContrastTab contrast={PAIRS} pro={false} onUnlock={onUnlock} />);
    expect(screen.getByText('PRO')).toBeTruthy();
    expect(screen.getByText('Unlock to see the fix')).toBeTruthy();
    expect(container.innerHTML.toLowerCase()).not.toContain('b4501a');
    expect(container.innerHTML).not.toContain('180, 80, 26');
    fireEvent.click(screen.getByRole('button', { name: 'Unlock' }));
    expect(onUnlock).toHaveBeenCalledTimes(1);
  });
  it('shows before/after and the fixed ratio for Pro', () => {
    const { container } = render(<ContrastTab contrast={PAIRS} pro />);
    expect(screen.queryByText('PRO')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Unlock' })).toBeNull();
    const buttons = [...container.querySelectorAll<HTMLElement>('.fix-btn')];
    expect(buttons).toHaveLength(2);
    expect(buttons[0].style.background).toBe('rgb(224, 118, 58)');
    expect(buttons[1].style.background).toBe('rgb(180, 80, 26)');
    expect(screen.getByText('Fixed ratio 4.7:1')).toBeTruthy();
  });
  it('says no automatic fix exists for a Pro fail without a fix', () => {
    const noFix = pair({ fg: '#FFFFFF', bg: '#E0763A', ratio: 2.9, where: 'buttons', grade: 'fail' });
    render(<ContrastTab contrast={[noFix]} pro />);
    expect(screen.getByText(/No automatic fix found/)).toBeTruthy();
    expect(screen.queryByText('Sample')).toBeNull();
  });
  it('shows an empty state with no pairs', () => {
    render(<ContrastTab contrast={[]} pro={false} />);
    expect(screen.getByText('No colour pairs found')).toBeTruthy();
  });
});

describe('popup App tabs', () => {
  it('shows the contrast summary when the Contrast tab is selected', async () => {
    mocks.scanActiveTab.mockResolvedValue({ ...EMPTY_TOKENS('brewline.co'), contrast: PAIRS });
    render(<App />);
    fireEvent.click(await screen.findByRole('tab', { name: 'Contrast' }));
    const panel = screen.getByRole('tabpanel');
    expect(within(panel).getByText('pass WCAG AA')).toBeTruthy();
    expect(within(panel).getByText('#FFFFFF on #E0763A')).toBeTruthy();
  });
});
