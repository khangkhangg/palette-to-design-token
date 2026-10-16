import type { Tokens } from '../model/tokens';
import { toCss } from './css';
import { toDtcg } from './dtcg';
import { toFigma } from './figma';
import { toTailwind3, toTailwind4 } from './tailwind';
import type { ExportOptions } from './types';

export type { ExportOptions, Part } from './types';
export type Format = 'css' | 'tailwind' | 'json' | 'figma' | 'md' | 'all';

export function slug(site: string): string {
  return site.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
}

export function exportFile(
  tokens: Tokens,
  format: Exclude<Format, 'all'>,
  o: ExportOptions,
): { filename: string; mime: string; text: string } {
  switch (format) {
    case 'css':
      return { filename: 'tokens.css', mime: 'text/css', text: toCss(tokens, o) };
    case 'tailwind':
      return o.tailwind === 3
        ? { filename: 'tailwind.config.js', mime: 'text/javascript', text: toTailwind3(tokens, o) }
        : { filename: 'tailwind.css', mime: 'text/css', text: toTailwind4(tokens, o) };
    case 'json':
      return { filename: 'tokens.json', mime: 'application/json', text: toDtcg(tokens, o) };
    case 'figma':
      return { filename: 'figma-variables.json', mime: 'application/json', text: toFigma(tokens, o) };
    case 'md':
      throw new Error('md exporter not implemented');
  }
}
