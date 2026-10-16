import type { Tokens } from '../../../src/model/tokens';

export const brewline: Tokens = {
  site: 'brewline.co',
  scannedAt: '2026-10-16T00:00:00.000Z',
  colours: [
    { name: 'primary', hex: '#1F5E4B', role: 'primary', weight: 0.3, interactive: true },
    { name: 'primary-2', hex: '#2E7A63', role: 'primary', weight: 0.1, interactive: false },
    { name: 'accent', hex: '#E0763A', role: 'accent', weight: 0.05, interactive: true },
    { name: 'surface', hex: '#FFFDF8', role: 'surface', weight: 0.4, interactive: false },
    { name: 'surface-2', hex: '#F3EEE4', role: 'surface', weight: 0.1, interactive: false },
    { name: 'text', hex: '#1B1A17', role: 'text', weight: 0.2, interactive: false },
    { name: 'text-2', hex: '#5C574E', role: 'text', weight: 0.1, interactive: false },
    { name: 'border', hex: '#E4DDD0', role: 'border', weight: 0.05, interactive: false },
  ],
  fonts: [
    { name: 'body', family: '"DM Sans", sans-serif' },
    { name: 'heading', family: 'Fraunces, serif' },
  ],
  type: [
    { name: 'display', family: 'Fraunces, serif', sizePx: 48, weight: 600, lineHeightPx: 56, chars: 100 },
    { name: 'h1', family: '"DM Sans", sans-serif', sizePx: 24, weight: 600, lineHeightPx: 32, chars: 200 },
    { name: 'body', family: '"DM Sans", sans-serif', sizePx: 16, weight: 400, lineHeightPx: 26, chars: 5000 },
    { name: 'small', family: '"DM Sans", sans-serif', sizePx: 13, weight: 500, lineHeightPx: 18, chars: 300 },
  ],
  spacing: { base: 4, scale: [4, 8, 12, 16, 24, 32, 48] },
  radius: [4, 10, 9999],
  shadows: ['0 1px 2px rgba(0, 0, 0, 0.5)', '0 6px 16px rgba(0, 0, 0, 0.6)'],
  contrast: [
    { fg: '#1B1A17', bg: '#FFFDF8', ratio: 16.8, large: false, uses: 120, where: 'body text', grade: 'AAA' },
    {
      fg: '#FFFFFF', bg: '#E0763A', ratio: 2.9, large: false, uses: 4, where: 'buttons', grade: 'fail',
      fix: { fg: '#FFFFFF', bg: '#B4501A', ratio: 4.7 },
    },
  ],
};
