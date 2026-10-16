import type { Tokens } from './model/tokens';

export type ScanErrorReason = 'restricted' | 'no-access' | 'no-tab' | 'failed';

export class ScanError extends Error {
  constructor(public reason: ScanErrorReason, options?: { cause?: unknown }) {
    super(reason, options);
    this.name = 'ScanError';
  }
}

/** Pages no extension may script, whatever the user does. */
const RESTRICTED = [
  'cannot be scripted', 'chrome://', 'edge://', 'about:', 'chrome-extension://',
  'extensions gallery', 'webstore', 'chromewebstore.google.com',
];
const STORE_URL = /^https?:\/\/(chromewebstore\.google\.com|chrome\.google\.com\/webstore|microsoftedge\.microsoft\.com\/addons)/;

function classify(e: unknown): ScanError {
  const raw = e instanceof Error ? e.message : String(e);
  const msg = raw.toLowerCase();
  // `Cannot access contents of url "https://…"`: an ordinary page without an activeTab grant
  // (the panel scanned a tab the user never clicked the toolbar icon on), not a forbidden page.
  const url = /"(https?:\/\/[^"]*)"/i.exec(raw)?.[1];
  let reason: ScanErrorReason;
  if (url) reason = STORE_URL.test(url.toLowerCase()) ? 'restricted' : 'no-access';
  else if (RESTRICTED.some((s) => msg.includes(s))) reason = 'restricted';
  else if (msg.includes('cannot access')) reason = 'no-access';
  else reason = 'failed';
  return new ScanError(reason, { cause: e });
}
export async function scanTab(tabId: number): Promise<Tokens> {
  let results: { result?: unknown }[];
  try {
    await chrome.scripting.executeScript({ target: { tabId }, files: ['extract.js'] });
    results = await chrome.scripting.executeScript({
      target: { tabId },
      func: () => (globalThis as any).__sdtScan(),
    });
  } catch (e) {
    throw classify(e);
  }
  const tokens = results?.[0]?.result as Tokens | undefined;
  if (!tokens) throw new ScanError('failed');
  return tokens;
}

export async function scanActiveTab(): Promise<Tokens> {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab || tab.id === undefined) throw new ScanError('no-tab');
  return scanTab(tab.id);
}

/** The reason a UI shows for a failed scan (`no-tab` reads as a generic failure). */
export type ShownScanError = 'restricted' | 'no-access' | 'failed';

export function shownReason(e: unknown): ShownScanError {
  return e instanceof ScanError && (e.reason === 'restricted' || e.reason === 'no-access') ? e.reason : 'failed';
}
