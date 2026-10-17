// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/preact';
import type { AiResult } from '../../src/ai';
import type { ColourToken, Tokens } from '../../src/model/tokens';
import { EMPTY_TOKENS } from '../../src/model/tokens';
import { PlanSheet } from '../../src/ui/components/PlanSheet';
import { formatDate, setLang } from '../../src/ui/i18n';
import { AiSection } from '../../src/ui/sidepanel/AiSection';
import { ExportScreen } from '../../src/ui/sidepanel/ExportScreen';
import { installFakeChrome, uninstallFakeChrome } from './fake-chrome';

const mocks = vi.hoisted(() => ({ analyse: vi.fn(), activate: vi.fn() }));
vi.mock('../../src/ai', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../src/ai')>()),
  analyse: mocks.analyse,
}));
vi.mock('../../src/license', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../src/license')>()),
  activate: mocks.activate,
}));

const DAY = 86_400_000;
const iso = (ms: number) => new Date(Date.now() + ms).toISOString();
const c = (role: ColourToken['role'], hex: string): ColourToken => ({ role, name: role, hex, weight: 0.2, interactive: false });
const brewline = (over: Partial<Tokens> = {}): Tokens => ({
  ...EMPTY_TOKENS('brewline.co'),
  colours: [c('primary', '#1F5E4B'), c('accent', '#E0763A'), c('surface', '#F3EEE4')],
  page: { url: 'https://brewline.co/menu', title: 'Brewline', description: 'Coffee' },
  ...over,
});
const RESULT: AiResult = {
  sector: 'Specialty coffee', tags: ['Online shop', 'Premium, craft'], verdict: 'competitive',
  summary: 'A calm, editorial look that reads as premium craft coffee.',
  colours: [
    { hex: '#1F5E4B', title: 'Deep green: natural, trusted', meaning: 'Signals origin and a grown-up brand.' },
    { hex: '#E0763A', title: 'Warm orange: appetite, energy', meaning: 'Draws the eye to buy buttons.' },
  ],
  fit: { level: 'strong', reason: 'Matches what buyers expect.' },
  works: ['Clear serif-plus-sans pairing', 'Consistent 4px spacing'], improve: ['Orange buttons fail contrast'],
  sites: [
    { name: 'Ridge Roasters', domain: 'ridge.example', url: 'https://ridge.example/', why: 'Same sector, similar green palette', relation: 'competitor', source: 'web' },
    { name: 'Saltline Roastery', domain: 'saltline.example', url: 'https://saltline.example/', why: 'Same audience, bolder colours', relation: 'similar', source: 'ai' },
  ],
  search_unavailable: false,
};
const USAGE = { used: 37, limit: 100, resetsOn: '2026-11-01' };
const aiLicense = (over: Record<string, unknown> = {}) => ({
  key: 'SDT-ABCD-2345-6789', verifiedAt: iso(0), emailMasked: null, plan: 'ai', paidThrough: iso(20 * DAY),
  usage: USAGE, subStatus: 'active', lastCheck: 'ok', lastAttemptAt: iso(0), ...over,
});

let data: Record<string, unknown>;
let create: ReturnType<typeof vi.fn<(p: unknown) => Promise<unknown>>>;

beforeEach(() => {
  setLang('en');
  const fake = installFakeChrome();
  data = fake.data;
  create = vi.fn(async (_p: unknown): Promise<unknown> => ({}));
  fake.chrome.tabs.create = create;
  mocks.analyse.mockReset();
  mocks.activate.mockReset();
});
afterEach(() => { cleanup(); vi.useRealTimers(); uninstallFakeChrome(); setLang('en'); });

/** Matches the innermost element whose whole text (across <bdi> isolates) equals or matches `want`. */
const text = (want: string | RegExp) => (_: string, el: Element | null) => {
  const ok = (e: Element) => {
    const s = (e.textContent ?? '').replace(/\s+/g, ' ').trim();
    return typeof want === 'string' ? s === want : want.test(s);
  };
  return !!el && ok(el) && ![...el.children].some(ok);
};
const ready = async () => screen.findByRole('button', { name: 'Analyse brewline.co' });

describe('AI section: not subscribed (G)', () => {
  it('explains the three things, the price, and opens the plan chooser on Pro + AI', async () => {
    render(<AiSection tokens={brewline()} />);
    expect(await screen.findByRole('heading', { name: 'AI insights' })).toBeTruthy();
    expect(screen.getByText("Ask AI about this site's design")).toBeTruthy();
    for (const f of ['Competitors and look-alikes', 'Is it competitive?', 'What the colours say']) expect(screen.getByText(f)).toBeTruthy();
    expect(screen.getByText('Pro + AI · 100 analyses a month')).toBeTruthy();
    expect(screen.getByText('$3.99')).toBeTruthy();
    expect(screen.getByText('Includes everything in Pro. Cancel any time.')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /^Analyse/ })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'See Pro + AI' }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByRole('heading', { name: 'Choose your plan' })).toBeTruthy();
    expect((within(dialog).getByRole('radio', { name: /Pro \+ AI/ }) as HTMLInputElement).checked).toBe(true);
    fireEvent.click(within(dialog).getByRole('button', { name: /Subscribe with PayPal/ }));
    expect(create).toHaveBeenCalledWith({ url: 'https://vietnamcos.com/palette/checkout?lang=en&ext=abcdefghijklmnopabcdefghijklmnop&plan=ai' });
    expect(mocks.analyse).not.toHaveBeenCalled();
  });

  it('shows the ended date and Renew when the plan lapsed (G variant)', async () => {
    data.licenseAi = aiLicense({ lastCheck: 'ended', paidThrough: '2026-10-01T00:00:00.000Z' });
    render(<AiSection tokens={brewline()} />);
    expect(await screen.findByText(text(`Your Pro + AI plan ended on ${formatDate('2026-10-01T00:00:00.000Z')}.`))).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Renew Pro + AI' }));
    expect(create).toHaveBeenCalledWith({ url: expect.stringMatching(/\/checkout\?lang=en&ext=.*&plan=ai$/) });
  });
});

describe('AI section: ready (H)', () => {
  beforeEach(() => { data.licenseAi = aiLicense(); });

  it('shows usage, the What gets sent note with the privacy link, and the Analyse button', async () => {
    render(<AiSection tokens={brewline()} />);
    await ready();
    expect(screen.getByText(text('63 of 100 analyses left'))).toBeTruthy();
    expect(screen.getByText(text(`resets ${formatDate('2026-11-01', false)}`))).toBeTruthy();
    expect(screen.getByText('What gets sent')).toBeTruthy();
    expect(screen.getByText(/^This page's address, title and description, and the design summary above.*It goes to vietnamcos\.com, a web search, and our AI provider \(DeepSeek\)/)).toBeTruthy();
    const privacy = screen.getByRole('link', { name: 'Privacy policy' }) as HTMLAnchorElement;
    expect(privacy.href).toBe('https://vietnamcos.com/palette/privacy?lang=en');
    expect(privacy.target).toBe('_blank');
    expect(privacy.rel).toBe('noopener noreferrer');
    expect(screen.getByText('Uses 1 analysis. Takes about 15 seconds.')).toBeTruthy();
  });

  it('"Don\'t show this note again" is stored and respected next time', async () => {
    const { unmount } = render(<AiSection tokens={brewline()} />);
    await ready();
    fireEvent.click(screen.getByRole('checkbox', { name: "Don't show this note again" }));
    await waitFor(() => expect(data.aiNoteHidden).toBe(true));
    expect(screen.getByText('What gets sent')).toBeTruthy();
    unmount();
    render(<AiSection tokens={brewline()} />);
    await ready();
    expect(screen.queryByText('What gets sent')).toBeNull();
  });

  it('disables Analyse for a page that is not a public website', async () => {
    render(<AiSection tokens={brewline({ site: '127.0.0.1', page: { url: 'http://127.0.0.1:4517/a.html', title: '', description: '' } })} />);
    const btn = await screen.findByRole('button', { name: 'Analyse 127.0.0.1' }) as HTMLButtonElement;
    expect(btn.disabled).toBe(true);
    expect(screen.getByText(/Only public websites can be analysed/)).toBeTruthy();
  });

  it('out of analyses: disabled button and the reset date, no upsell', async () => {
    data.licenseAi = aiLicense({ usage: { used: 100, limit: 100, resetsOn: '2026-11-01' } });
    render(<AiSection tokens={brewline()} />);
    expect(((await ready()) as HTMLButtonElement).disabled).toBe(true);
    expect(screen.getByText(text(`You've used all 100 analyses this month. Resets ${formatDate('2026-11-01', false)}.`))).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'See Pro + AI' })).toBeNull();
  });
});

describe('AI section: running', () => {
  beforeEach(() => { data.licenseAi = aiLicense(); });

  it('announces progress steps in a live region that was already mounted, focuses Cancel, and Cancel aborts and refocuses Analyse', async () => {
    let signal: AbortSignal | undefined;
    mocks.analyse.mockImplementation((_t, _p, _l, s: AbortSignal) => {
      signal = s;
      return new Promise((resolve) => s.addEventListener('abort', () => resolve({ ok: false, reason: 'cancelled' })));
    });
    const tokens = brewline();
    render(<AiSection tokens={tokens} />);
    const btn = await ready();
    const live = document.querySelector('[aria-live="polite"]')!;
    expect(live.textContent).toBe('');
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    btn.focus();
    fireEvent.click(btn);
    expect(document.querySelector('[aria-live="polite"]')).toBe(live); // same node: the change is read out
    expect(live.textContent).toBe('Searching the web…');
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Cancel' }));
    expect(mocks.analyse).toHaveBeenCalledWith(tokens, { url: 'https://brewline.co/menu', title: 'Brewline', description: 'Coffee' }, 'en', expect.any(AbortSignal));
    await act(async () => { vi.advanceTimersByTime(5000); });
    expect(screen.getByText('Asking the AI…', { selector: '.ai-step' })).toBeTruthy();
    expect(live.textContent).toBe('Asking the AI…');
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(signal!.aborted).toBe(true);
    vi.useRealTimers();
    const again = await ready();
    expect(document.activeElement).toBe(again);
    expect(live.textContent).toBe('');
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('after results, focus moves to the results heading and "Results ready" is announced', async () => {
    mocks.analyse.mockResolvedValue({ ok: true, result: RESULT, cached: false, usage: { ...USAGE, used: 38 } });
    render(<AiSection tokens={brewline()} />);
    const btn = await ready();
    btn.focus();
    fireEvent.click(btn);
    await screen.findByText('Competitive', { selector: '.ai-verdict' });
    const heading = screen.getByRole('heading', { name: 'AI insights' });
    await waitFor(() => expect(document.activeElement).toBe(heading));
    expect(heading.getAttribute('tabindex')).toBe('-1');
    expect(heading.closest('.ai-results')).toBeTruthy();
    expect(document.querySelector('[aria-live="polite"]')!.textContent).toBe('Results ready');
    // Run again from the results: the icon button goes away while running, then focus returns to the new results.
    mocks.analyse.mockResolvedValueOnce({ ok: false, reason: 'cancelled' });
    fireEvent.click(screen.getByRole('button', { name: 'Run again' }));
    await waitFor(() => expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Run again' })));
  });

  it.each([
    ['timeout', 'The analysis took too long. No analysis was used.'],
    ['unavailable', "The AI isn't available right now. No analysis was used."],
    ['network', "Couldn't reach the server. Check your connection and try again."],
    ['rate_limited', 'Too many analyses in a short time. Wait a minute and try again.'],
    ['bad_request', "We couldn't analyse this page."],
    ['invalid', "The AI's answer couldn't be read. Try again."],
  ])('shows the %s error inline with Try again, and focuses it', async (reason, text) => {
    mocks.analyse.mockResolvedValueOnce({ ok: false, reason }).mockResolvedValueOnce({ ok: true, result: RESULT, cached: false, usage: { ...USAGE, used: 38 } });
    render(<AiSection tokens={brewline()} />);
    fireEvent.click(await ready());
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain(text);
    if (reason === 'bad_request' || reason === 'invalid') expect(alert.textContent).not.toContain('No analysis was used');
    await waitFor(() => expect(document.activeElement).toBe(alert));
    fireEvent.click(within(alert).getByRole('button', { name: 'Try again' }));
    expect(await screen.findByText('Competitive', { selector: '.ai-verdict' })).toBeTruthy();
    expect(mocks.analyse).toHaveBeenCalledTimes(2);
  });

  it('an out-of-quota answer disables the button with the reset date', async () => {
    mocks.analyse.mockResolvedValue({ ok: false, reason: 'quota', resetsOn: '2026-11-01' });
    render(<AiSection tokens={brewline()} />);
    fireEvent.click(await ready());
    expect(await screen.findByText(text(/You've used all 100 analyses this month/))).toBeTruthy();
    expect(((await ready()) as HTMLButtonElement).disabled).toBe(true);
  });

  it('the hidden token cap has its own message, not "out of analyses", and disables Analyse', async () => {
    mocks.analyse.mockResolvedValue({ ok: false, reason: 'token_cap', resetsOn: '2026-11-01' });
    render(<AiSection tokens={brewline()} />);
    fireEvent.click(await ready());
    expect(await screen.findByText(text(`This month's AI limit is used up. It resets on ${formatDate('2026-11-01', false)}.`))).toBeTruthy();
    expect(screen.queryByText(text(/You've used all/))).toBeNull();
    expect(((await ready()) as HTMLButtonElement).disabled).toBe(true);
  });

  it('a token-cap answer without a date says so without a date', async () => {
    data.licenseAi = aiLicense({ usage: null });
    mocks.analyse.mockResolvedValue({ ok: false, reason: 'token_cap' });
    render(<AiSection tokens={brewline()} />);
    fireEvent.click(await ready());
    expect(await screen.findByText(text("This month's AI limit is used up."))).toBeTruthy();
  });

  it('an out-of-quota answer without a reset date never shows "Resets ."', async () => {
    data.licenseAi = aiLicense({ usage: null });
    mocks.analyse.mockResolvedValue({ ok: false, reason: 'quota' });
    render(<AiSection tokens={brewline()} />);
    fireEvent.click(await ready());
    expect(await screen.findByText(text("You've used all 100 analyses this month."))).toBeTruthy();
    expect(document.body.textContent).not.toMatch(/Resets/);
  });

  it('a later licence refresh with fresh usage clears the out-of-quota state', async () => {
    mocks.analyse.mockResolvedValue({ ok: false, reason: 'quota', resetsOn: '2026-11-01' });
    render(<AiSection tokens={brewline()} />);
    fireEvent.click(await ready());
    await screen.findByText(text(/You've used all 100 analyses this month/));
    // The period renewed: the stored licence now reports fresh usage.
    await act(async () => { await chrome.storage.local.set({ licenseAi: aiLicense({ usage: { used: 0, limit: 100, resetsOn: '2026-12-01' } }) }); });
    await waitFor(() => expect(((screen.getByRole('button', { name: 'Analyse brewline.co' })) as HTMLButtonElement).disabled).toBe(false));
    expect(screen.queryByText(text(/You've used all/))).toBeNull();
  });

  it('an ended answer returns the card to G with the date', async () => {
    mocks.analyse.mockResolvedValue({ ok: false, reason: 'ended', endedOn: '2026-10-15' });
    render(<AiSection tokens={brewline()} />);
    fireEvent.click(await ready());
    expect(await screen.findByText(text(`Your Pro + AI plan ended on ${formatDate('2026-10-15')}.`))).toBeTruthy();
  });
});

describe('AI section: results (I)', () => {
  beforeEach(() => { data.licenseAi = aiLicense(); });

  const showResult = async (over: Partial<AiResult> = {}, cached = false) => {
    mocks.analyse.mockResolvedValue({ ok: true, result: { ...RESULT, ...over }, cached, usage: { used: 38, limit: 100, resetsOn: '2026-11-01' } });
    render(<AiSection tokens={brewline()} />);
    fireEvent.click(await ready());
    await waitFor(() => expect(document.querySelector('.ai-verdict')).toBeTruthy());
  };

  it('renders every section of mockup I', async () => {
    await showResult();
    const tags = screen.getByRole('list', { name: 'Sector' });
    expect(within(tags).getAllByRole('listitem').map((li) => li.textContent)).toEqual(['Specialty coffee', 'Online shop', 'Premium, craft']);
    expect(screen.getByText(text('brewline.co · 62 analyses left'))).toBeTruthy();
    expect(screen.getByRole('img', { name: 'Scale: behind, on par, competitive. This site: Competitive.' })).toBeTruthy();
    for (const l of ['Behind', 'On par']) expect(screen.getByText(l)).toBeTruthy();
    expect(screen.getByText('for its sector')).toBeTruthy();
    expect(screen.getByText(RESULT.summary)).toBeTruthy();
    expect(screen.getByRole('heading', { name: 'What the colours say' })).toBeTruthy();
    expect(screen.getByText('Deep green: natural, trusted')).toBeTruthy();
    expect((document.querySelector('.ai-chip') as HTMLElement).style.backgroundColor).toBe('rgb(31, 94, 75)');
    const fit = document.querySelector('.ai-fit')!;
    expect(fit.textContent).toContain('Strong fit for Specialty coffee');
    expect(fit.textContent).toContain('Matches what buyers expect.');
    expect(screen.getByRole('heading', { name: 'Works' })).toBeTruthy();
    expect(screen.getByRole('heading', { name: 'Could be better' })).toBeTruthy();
    expect(screen.getByText('Orange buttons fail contrast')).toBeTruthy();
    expect(screen.getByRole('heading', { name: 'Check these sites' })).toBeTruthy();
    expect(screen.getByText(/"Web" sites were found by search/)).toBeTruthy();
    expect(screen.getByText(text(/^AI can be wrong\. Analysed .* · used 38 of 100$/))).toBeTruthy();
    expect(screen.queryByText(/Shared result/)).toBeNull();
    expect(screen.queryByText(/Web search unavailable/)).toBeNull();
  });

  it('links only web-found sites (new tab); AI suggestions are plain text with a "check it" badge', async () => {
    await showResult();
    const links = screen.getAllByRole('link', { name: /Roast/ }) as HTMLAnchorElement[];
    expect(links.map((a) => a.href)).toEqual(['https://ridge.example/']);
    expect(links[0].target).toBe('_blank');
    expect(links[0].rel).toBe('noopener noreferrer');
    expect(links[0].textContent).toContain('(opens in a new tab)');
    expect(links[0].querySelector('.ai-badge')!.textContent).toBe('Web');
    expect(links[0].textContent).toContain('ridge.example');
    // ruling A13: a model-made domain is never one click away
    const items = [...document.querySelectorAll('.ai-site-list > li')];
    expect(items).toHaveLength(2);
    const aiItem = items[1];
    expect(aiItem.querySelector('a')).toBeNull();
    expect(aiItem.textContent).toContain('Saltline Roastery');
    expect(aiItem.textContent).toContain('saltline.example');
    expect(aiItem.textContent).not.toContain('(opens in a new tab)');
    expect(aiItem.querySelector('.ai-badge')!.textContent).toBe('AI · check it');
  });

  it('says when web search was unavailable, and when the result was a shared cached one', async () => {
    await showResult({ search_unavailable: true }, true);
    expect(screen.getByText('Web search unavailable; suggestions are from AI only.')).toBeTruthy();
    expect(screen.getByText('Shared result from the last 7 days: no analysis used.')).toBeTruthy();
  });

  it('shows the verdict as text for each level, never colour alone', async () => {
    await showResult({ verdict: 'behind', fit: { level: 'weak', reason: '' } });
    expect(screen.getByText('Behind', { selector: '.ai-verdict' })).toBeTruthy();
    expect(document.querySelector('.ai-fit')!.textContent).toContain('Weak fit');
  });

  it('a stored result in another language says so, with "Run again in <language>"', async () => {
    data.aiResults = [{ host: 'brewline.co', result: RESULT, usage: null, analysedAt: '2026-10-17T09:00:00.000Z', lang: 'fr', cached: false }];
    mocks.analyse.mockResolvedValue({ ok: false, reason: 'cancelled' });
    render(<AiSection tokens={brewline()} />);
    const hint = await screen.findByRole('button', { name: 'Run again in English' });
    fireEvent.click(hint);
    expect(mocks.analyse).toHaveBeenCalledWith(expect.anything(), expect.anything(), 'en', expect.any(AbortSignal));
  });

  it('a stored result in the UI language shows no language hint', async () => {
    data.aiResults = [{ host: 'brewline.co', result: RESULT, usage: null, analysedAt: '2026-10-17T09:00:00.000Z', lang: 'en', cached: false }];
    render(<AiSection tokens={brewline()} />);
    await screen.findByText('Competitive', { selector: '.ai-verdict' });
    expect(screen.queryByText(/Run again in/)).toBeNull();
  });

  it('shows the stored result without calling the API; Run again calls it', async () => {
    data.aiResults = [{ host: 'brewline.co', result: RESULT, usage: { used: 38, limit: 100, resetsOn: '2026-11-01' }, analysedAt: '2026-10-17T09:00:00.000Z', lang: 'en', cached: false }];
    mocks.analyse.mockResolvedValue({ ok: true, result: { ...RESULT, verdict: 'on_par' }, cached: false, usage: { used: 39, limit: 100, resetsOn: '2026-11-01' } });
    render(<AiSection tokens={brewline({ site: 'www.brewline.co' })} />);
    expect(await screen.findByText('Competitive', { selector: '.ai-verdict' })).toBeTruthy();
    expect(screen.getByText(text(`AI can be wrong. Analysed ${formatDate('2026-10-17T09:00:00.000Z')} · used 38 of 100`))).toBeTruthy();
    expect(mocks.analyse).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Run again' }));
    expect(await screen.findByText('On par', { selector: '.ai-verdict' })).toBeTruthy();
    expect(mocks.analyse).toHaveBeenCalledTimes(1);
  });

  it('renders right-to-left in Arabic with hosts and numbers isolated left-to-right', async () => {
    setLang('ar');
    await (async () => {
      mocks.analyse.mockResolvedValue({ ok: true, result: RESULT, cached: false, usage: { used: 38, limit: 100, resetsOn: '2026-11-01' } });
      render(<AiSection tokens={brewline()} />);
      const btn = await screen.findByRole('button', { name: /brewline\.co/ });
      expect(btn.querySelector('bdi[dir="ltr"]')!.textContent).toBe('brewline.co');
      fireEvent.click(btn);
      await screen.findByText('منافس', { selector: '.ai-verdict' });
    })();
    expect(document.documentElement.dir).toBe('rtl');
    expect(screen.getByRole('heading', { name: 'رؤى الذكاء الاصطناعي' })).toBeTruthy();
    expect([...document.querySelectorAll('.ai-site-domain')].map((b) => [b.tagName, b.getAttribute('dir')])).toEqual([['BDI', 'ltr'], ['BDI', 'ltr']]);
    expect(document.querySelector('.ai-footer bdi[dir="ltr"]')).toBeTruthy();
  });
});

describe('PlanSheet (J)', () => {
  it('defaults to Pro from Export, and switching plans changes the button, note and checkout link', () => {
    render(<PlanSheet variant="overlay" plan="pro" onClose={() => {}} />);
    expect((screen.getByRole('radio', { name: /^Pro\s*\$1\.49/ }) as HTMLInputElement).checked).toBe(true);
    expect(screen.getByText('Most useful')).toBeTruthy();
    expect(screen.getByText(
      'All sales are final. Because Pro unlocks instantly and costs $1.49, we do not offer refunds after purchase.',
    )).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: /Pay \$1\.49 with PayPal/ }));
    expect(create).toHaveBeenLastCalledWith({ url: 'https://vietnamcos.com/palette/checkout?lang=en&ext=abcdefghijklmnopabcdefghijklmnop&plan=pro' });
    fireEvent.click(screen.getByRole('radio', { name: /Pro \+ AI/ }));
    expect(screen.getByText(/Billed monthly through PayPal/)).toBeTruthy();
    for (const item of ['Everything in Pro', '100 AI analyses a month', 'Cancel any time from your account']) expect(screen.getByText(item)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: /Subscribe with PayPal/ }));
    expect(create).toHaveBeenLastCalledWith({ url: expect.stringMatching(/&plan=ai$/) });
  });

  it('Export opens it on Pro', () => {
    render(<ExportScreen tokens={brewline()} pro={false} onBack={() => {}} />);
    fireEvent.click(screen.getByRole('button', { name: /^Download/ }));
    const dialog = screen.getByRole('dialog');
    expect((within(dialog).getByRole('radio', { name: /^Pro\s*\$1\.49/ }) as HTMLInputElement).checked).toBe(true);
  });

  it('"Already have a key? Enter it" opens the key form, and Back returns to the plans', () => {
    render(<PlanSheet variant="overlay" plan="ai" onClose={() => {}} />);
    expect(screen.getByText('Already have a key?')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Enter it' }));
    expect(screen.getByRole('heading', { name: 'Enter your license key' })).toBeTruthy();
    expect(screen.getByLabelText('License key')).toBeTruthy();
    expect(document.activeElement).toBe(screen.getByLabelText('License key'));
    fireEvent.click(screen.getByRole('button', { name: 'Back to plans' }));
    expect(screen.getByRole('heading', { name: 'Choose your plan' })).toBeTruthy();
  });

  it('after an AI key activates from the AI section, the card turns ready', async () => {
    mocks.activate.mockImplementation(async () => { data.licenseAi = aiLicense(); return { ok: true, plan: 'ai' }; });
    render(<AiSection tokens={brewline()} />);
    fireEvent.click(await screen.findByRole('button', { name: 'See Pro + AI' }));
    fireEvent.click(screen.getByRole('button', { name: 'Enter it' }));
    fireEvent.input(screen.getByLabelText('License key'), { target: { value: 'SDT-ABCD-2345-6789' } });
    fireEvent.click(screen.getByRole('button', { name: 'Activate' }));
    expect(await screen.findByText('Pro + AI unlocked')).toBeTruthy();
    expect(await screen.findByRole('button', { name: 'Analyse brewline.co' }, { timeout: 3000 })).toBeTruthy();
    expect(screen.queryByRole('dialog')).toBeNull();
  });
});
