// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { applyTheme } from '../../src/ui/theme';

type Listener = () => void;

function fakeMedia(dark: boolean) {
  const listeners = new Set<Listener>();
  const mq = {
    matches: dark,
    addEventListener: (_: string, l: Listener) => listeners.add(l),
    removeEventListener: (_: string, l: Listener) => listeners.delete(l),
  };
  vi.stubGlobal('matchMedia', vi.fn(() => mq));
  return {
    listeners,
    set(next: boolean) { mq.matches = next; [...listeners].forEach((l) => l()); },
  };
}

const theme = () => document.documentElement.dataset.theme;

beforeEach(() => { delete document.documentElement.dataset.theme; });
afterEach(() => { applyTheme('light'); vi.unstubAllGlobals(); });

describe('applyTheme', () => {
  it('sets an explicit theme', () => {
    fakeMedia(true);
    applyTheme('light');
    expect(theme()).toBe('light');
    applyTheme('dark');
    expect(theme()).toBe('dark');
  });

  it("'system' follows prefers-color-scheme live", () => {
    const media = fakeMedia(true);
    applyTheme('system');
    expect(theme()).toBe('dark');
    media.set(false);
    expect(theme()).toBe('light');
    media.set(true);
    expect(theme()).toBe('dark');
  });

  it('stops following the system once an explicit theme is chosen', () => {
    const media = fakeMedia(false);
    applyTheme('system');
    expect(media.listeners.size).toBe(1);
    applyTheme('light');
    expect(media.listeners.size).toBe(0);
    media.set(true);
    expect(theme()).toBe('light');
  });
});
