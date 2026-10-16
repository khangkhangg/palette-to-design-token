import type { Tokens } from './model/tokens';

export type ScanErrorReason = 'restricted' | 'no-tab' | 'failed';

export class ScanError extends Error {
  constructor(public reason: ScanErrorReason, options?: { cause?: unknown }) {
    super(reason, options);
    this.name = 'ScanError';
  }
}

const RESTRICTED = [
  'cannot access', 'cannot be scripted', 'chrome://', 'edge://', 'about:',
  'chrome-extension://', 'extensions gallery', 'webstore',
];

function classify(e: unknown): ScanError {
  const msg = (e instanceof Error ? e.message : String(e)).toLowerCase();
  const reason = RESTRICTED.some((s) => msg.includes(s)) ? 'restricted' : 'failed';
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
