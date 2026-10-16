import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, it, expect } from 'vitest';

const manifest = JSON.parse(
  readFileSync(resolve(import.meta.dirname, '../../public/manifest.json'), 'utf8'),
);

describe('manifest', () => {
  it('is MV3 with the product name', () => {
    expect(manifest.manifest_version).toBe(3);
    expect(manifest.name).toBe('Site to Design Token');
  });
  it('requests exactly the allowed permissions and no host permissions', () => {
    expect([...manifest.permissions].sort()).toEqual(['activeTab', 'scripting', 'sidePanel', 'storage']);
    expect(manifest.host_permissions).toBeUndefined();
  });
  it('wires side panel, popup and service worker', () => {
    expect(manifest.side_panel.default_path).toBe('sidepanel.html');
    expect(manifest.action.default_popup).toBe('popup.html');
    expect(manifest.background.service_worker).toBe('background.js');
  });
});
