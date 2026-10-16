import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { EMPTY_TOKENS } from '../../src/model/tokens';
import { getSettings, listScans, onSettingsChange, saveScan, setSettings } from '../../src/store';
import { installFakeChrome, uninstallFakeChrome } from './fake-chrome';

beforeEach(() => { installFakeChrome(); });
afterEach(uninstallFakeChrome);

describe('settings', () => {
  it('has defaults', async () => {
    expect(await getSettings()).toEqual({ theme: 'system', lang: '', pro: false });
  });

  it('persists partial updates merged over defaults', async () => {
    await setSettings({ theme: 'dark' });
    expect(await getSettings()).toEqual({ theme: 'dark', lang: '', pro: false });
    await setSettings({ lang: 'vi' });
    expect(await getSettings()).toEqual({ theme: 'dark', lang: 'vi', pro: false });
  });

  it('notifies subscribers with merged settings until unsubscribed', async () => {
    const seen: unknown[] = [];
    const off = onSettingsChange((s) => seen.push(s));
    await setSettings({ theme: 'dark' });
    expect(seen).toEqual([{ theme: 'dark', lang: '', pro: false }]);
    off();
    await setSettings({ theme: 'light' });
    expect(seen).toHaveLength(1);
  });

  it('ignores changes to other keys', async () => {
    const seen: unknown[] = [];
    onSettingsChange((s) => seen.push(s));
    await saveScan(EMPTY_TOKENS('a.com'));
    expect(seen).toEqual([]);
  });
});

describe('scans', () => {
  const sites = ['a.com', 'b.com', 'c.com', 'd.com'];

  it('is empty initially', async () => {
    expect(await listScans()).toEqual([]);
  });

  it('keeps 3 newest when free', async () => {
    for (const s of sites) await saveScan(EMPTY_TOKENS(s));
    expect((await listScans()).map((t) => t.site)).toEqual(['d.com', 'c.com', 'b.com']);
  });

  it('keeps all when pro', async () => {
    await setSettings({ pro: true });
    for (const s of sites) await saveScan(EMPTY_TOKENS(s));
    expect(await listScans()).toHaveLength(4);
  });

  it('replaces the same site and moves it to the front', async () => {
    await saveScan(EMPTY_TOKENS('a.com'));
    await saveScan(EMPTY_TOKENS('b.com'));
    await saveScan(EMPTY_TOKENS('a.com'));
    expect((await listScans()).map((t) => t.site)).toEqual(['a.com', 'b.com']);
  });
});
