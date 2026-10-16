import { afterEach, describe, expect, it, vi } from 'vitest';

afterEach(() => { delete (globalThis as any).chrome; vi.resetModules(); });

describe('background', () => {
  it('disables the side panel globally so it only shows on tabs where the user opened it', async () => {
    const sidePanel = {
      setPanelBehavior: vi.fn().mockResolvedValue(undefined),
      setOptions: vi.fn().mockResolvedValue(undefined),
    };
    (globalThis as any).chrome = { sidePanel };
    await import('../../src/background');
    expect(sidePanel.setPanelBehavior).toHaveBeenCalledWith({ openPanelOnActionClick: false });
    expect(sidePanel.setOptions).toHaveBeenCalledWith({ enabled: false });
  });
});
