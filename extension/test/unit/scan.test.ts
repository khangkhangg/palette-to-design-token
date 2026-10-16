import { afterEach, describe, expect, it } from 'vitest';
import { EMPTY_TOKENS } from '../../src/model/tokens';
import { ScanError, scanActiveTab, scanTab } from '../../src/scan';
import { installFakeChrome, uninstallFakeChrome } from './fake-chrome';

afterEach(uninstallFakeChrome);

const reasonOf = async (p: Promise<unknown>) => {
  try { await p; } catch (e) { return e instanceof ScanError ? e.reason : 'other'; }
  return 'none';
};

describe('scanTab', () => {
  it('injects extract.js then returns the scan result', async () => {
    const calls: any[] = [];
    const tokens = EMPTY_TOKENS('example.com');
    installFakeChrome({
      executeScript: async (inj) => { calls.push(inj); return calls.length === 1 ? [{}] : [{ result: tokens }]; },
    });
    expect(await scanTab(7)).toEqual(tokens);
    expect(calls[0]).toEqual({ target: { tabId: 7 }, files: ['extract.js'] });
    expect(calls[1].target).toEqual({ tabId: 7 });
    expect(typeof calls[1].func).toBe('function');
  });

  it.each([
    'Cannot access a chrome:// URL',
    'Cannot access contents of url "edge://settings"',
    'Cannot access contents of url "about:blank"',
    'Cannot access contents of url "chrome-extension://abc/popup.html"',
    'The extensions gallery cannot be scripted.',
    'Cannot access contents of url "https://chromewebstore.google.com/detail/x"',
    'Cannot access contents of url "https://chrome.google.com/webstore/detail/x"',
    'This page cannot be scripted due to an ExtensionsSettings policy.',
  ])('maps "%s" to restricted', async (msg) => {
    installFakeChrome({ executeScript: async () => { throw new Error(msg); } });
    expect(await reasonOf(scanTab(1))).toBe('restricted');
  });

  it.each([
    'Cannot access contents of url "https://example.com/about"',
    'Cannot access contents of url "http://localhost:5173/"',
    'Cannot access contents of url "https://example.com/?next=about:blank"',
    'Cannot access contents of the page. Extension manifest must request permission to access the respective host.',
  ])('maps "%s" (no activeTab grant) to no-access', async (msg) => {
    installFakeChrome({ executeScript: async () => { throw new Error(msg); } });
    expect(await reasonOf(scanTab(1))).toBe('no-access');
  });

  it('maps other errors to failed and keeps the cause', async () => {
    const boom = new Error('boom');
    installFakeChrome({ executeScript: async () => { throw boom; } });
    const err = await scanTab(1).catch((e) => e);
    expect(err).toBeInstanceOf(ScanError);
    expect(err.reason).toBe('failed');
    expect(err.cause).toBe(boom);
  });

  it('fails when the scan result is missing', async () => {
    installFakeChrome({ executeScript: async () => [{}] });
    expect(await reasonOf(scanTab(1))).toBe('failed');
  });
});

describe('scanActiveTab', () => {
  it('rejects with no-tab when there is no active tab or id', async () => {
    installFakeChrome({ tabs: [] });
    expect(await reasonOf(scanActiveTab())).toBe('no-tab');
    installFakeChrome({ tabs: [{}] });
    expect(await reasonOf(scanActiveTab())).toBe('no-tab');
  });

  it('scans the active tab', async () => {
    const tokens = EMPTY_TOKENS('a.com');
    let n = 0;
    installFakeChrome({ tabs: [{ id: 3 }], executeScript: async () => (n++ === 0 ? [{}] : [{ result: tokens }]) });
    expect(await scanActiveTab()).toEqual(tokens);
  });
});
