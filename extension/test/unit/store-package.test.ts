import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, it, expect } from 'vitest';

const pub = resolve(import.meta.dirname, '../../public');
const CHROME_LOCALES = ['ar', 'bn', 'en', 'es', 'fr', 'hi', 'zh_CN'];

describe('_locales', () => {
  it('has exactly the seven Chrome locales', () => {
    expect(readdirSync(resolve(pub, '_locales')).sort()).toEqual(CHROME_LOCALES);
  });
  for (const loc of CHROME_LOCALES) {
    it(`${loc}: appName is the untranslated product name and appDesc fits 132 chars`, () => {
      const m = JSON.parse(readFileSync(resolve(pub, '_locales', loc, 'messages.json'), 'utf8'));
      expect(Object.keys(m).sort()).toEqual(['appDesc', 'appName']);
      expect(m.appName.message).toBe('Site to Design Token');
      expect(m.appDesc.message.length).toBeGreaterThan(20);
      expect(m.appDesc.message.length).toBeLessThanOrEqual(132);
    });
  }
});

describe('icons', () => {
  for (const size of [16, 32, 48, 128]) {
    it(`icons/${size}.png is a ${size}x${size} PNG`, () => {
      const b = readFileSync(resolve(pub, 'icons', `${size}.png`));
      expect(b.subarray(0, 8).toString('hex')).toBe('89504e470d0a1a0a');
      expect(b.subarray(12, 16).toString('latin1')).toBe('IHDR');
      expect([b.readUInt32BE(16), b.readUInt32BE(20)]).toEqual([size, size]);
    });
  }
  it('the 440x280 small promo tile exists', () => {
    const f = resolve(import.meta.dirname, '../../../docs/store/promo-440x280.png');
    expect(existsSync(f)).toBe(true);
    const b = readFileSync(f);
    expect([b.readUInt32BE(16), b.readUInt32BE(20)]).toEqual([440, 280]);
  });
});

describe('screenshots', () => {
  const dir = resolve(import.meta.dirname, '../../../docs/store/screenshots');
  it('has six 1280x800 PNGs, the sixth being the AI insights shot (the store takes five: see listing.en.md)', () => {
    const files = readdirSync(dir).filter((f) => f.endsWith('.png'));
    expect(files).toHaveLength(6);
    expect(files).toContain('6-ai-insights.png');
    for (const f of files) {
      const b = readFileSync(resolve(dir, f));
      expect([b.readUInt32BE(16), b.readUInt32BE(20)], f).toEqual([1280, 800]);
    }
  });
});

describe('store listings', () => {
  const docs = resolve(import.meta.dirname, '../../../docs/store');
  for (const loc of CHROME_LOCALES) {
    it(`listing.${loc}.md: short description equals appDesc, with privacy URL and refund line`, () => {
      const md = readFileSync(resolve(docs, `listing.${loc}.md`), 'utf8');
      const desc = JSON.parse(readFileSync(resolve(pub, '_locales', loc, 'messages.json'), 'utf8')).appDesc.message;
      const short = md.split(/^## .*\r?\n/m)[2].trim();
      expect(short).toBe(desc);
      expect(md).toContain('https://vietnamcos.com/palette/privacy');
      expect(md).toMatch(/^- Homepage URL: https:\/\/vietnamcos\.com\/palette\/\r?$/m);
      expect(md).toContain('- Terms of service URL: https://vietnamcos.com/palette/terms');
      expect(md).not.toMatch(/opera/i);
      expect(md).toContain('1.49'.replace('.', loc === 'es' || loc === 'fr' ? ',' : '.'));
    });
  }
  for (const loc of CHROME_LOCALES) {
    it(`listing.${loc}.md: mentions the Pro + AI subscription, its monthly price, Brave Search and DeepSeek`, () => {
      const md = readFileSync(resolve(docs, `listing.${loc}.md`), 'utf8');
      expect(md).toContain('Pro + AI');
      expect(md).toMatch(loc === 'es' || loc === 'fr' ? /3,99/ : /3\.99/);
      expect(md).toContain('Brave Search');
      expect(md).toContain('DeepSeek');
      expect(md).toContain('100');
      // the short description is the manifest's appDesc: no price there
      const short = md.split(/^## .*\r?\n/m)[2].trim();
      expect(short).not.toMatch(/3[.,]99/);
    });
  }
  it('English listing declares Website content and Web history as collected only when the user presses Analyse', () => {
    const md = readFileSync(resolve(docs, 'listing.en.md'), 'utf8');
    expect(md).toContain('## Data disclosure');
    const disclosure = md.split('## Data disclosure')[1].split(/^## /m)[0];
    expect(disclosure).toMatch(/- Website content: the page title and description/);
    expect(disclosure).toMatch(/- Web history: the address of the analysed page, without its query string/);
    expect(disclosure).toContain('user-initiated');
    expect(disclosure).toContain('presses Analyse');
    expect(disclosure).toContain('Brave Search');
    expect(disclosure).toContain('DeepSeek');
    expect(disclosure).toContain('- Authentication information');
    expect(md).toContain('## Pricing note');
    expect(md.split('## Pricing note')[1].split(/^## /m)[0]).toContain('Pro + AI is a subscription at $3.99 a month');
  });
  it('English listing keeps the permissions unchanged and names the five screenshots to upload', () => {
    const md = readFileSync(resolve(docs, 'listing.en.md'), 'utf8');
    const perms = md.split('## Permissions justification')[1].split(/^## /m)[0];
    expect(perms.match(/^- /gm)).toHaveLength(5);
    expect(md.split('## Screenshots')[1].split(/^## /m)[0]).toContain('6-ai-insights');
  });
  it('English listing has the exact refund line and the permissions justification', () => {
    const md = readFileSync(resolve(docs, 'listing.en.md'), 'utf8');
    expect(md).toContain('All sales are final. Because Pro unlocks instantly and costs $1.49, we do not offer refunds after purchase.');
    for (const p of ['activeTab', 'scripting', 'storage', 'sidePanel', 'externally_connectable']) expect(md).toContain(`- ${p}`);
    expect(md).toContain('## Single purpose');
  });
  it('English listing justifies externally_connectable for both the apex and www checkout pages', () => {
    const md = readFileSync(resolve(docs, 'listing.en.md'), 'utf8');
    const line = md.split(/\r?\n/).find((l) => l.startsWith('- externally_connectable')) ?? '';
    expect(line).toContain('https://vietnamcos.com/palette/*');
    expect(line).toContain('https://www.vietnamcos.com/palette/*');
  });
  it('French writes the price one way everywhere: 1,49 $ US', () => {
    const md = readFileSync(resolve(docs, 'listing.fr.md'), 'utf8');
    const ui = readFileSync(resolve(import.meta.dirname, '../../src/i18n/fr.json'), 'utf8');
    const price = '1,49 $ US'; // no-break space keeps the amount and the symbol together
    for (const text of [md, ui]) {
      expect(text).toContain(price);
      expect(text.replaceAll(price, '')).not.toMatch(/1[,.]49/);
    }
  });
});
