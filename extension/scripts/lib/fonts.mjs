// Geist as inline @font-face rules, so pages rendered via setContent need no file or network access.
import { readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const dir = resolve(import.meta.dirname, '../../public/fonts');

export function geistCss() {
  return [[400, 'Regular'], [500, 'Medium'], [600, 'SemiBold'], [700, 'Bold']]
    .map(([w, n]) => {
      const b64 = readFileSync(join(dir, `Geist-${n}.woff2`)).toString('base64');
      return `@font-face{font-family:'Geist';font-weight:${w};src:url(data:font/woff2;base64,${b64}) format('woff2')}`;
    })
    .join('');
}
