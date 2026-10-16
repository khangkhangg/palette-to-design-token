import { strToU8, zipSync } from 'fflate';
import type { Tokens } from '../model/tokens';
import { exportFile, slug } from './index';
import type { ExportOptions } from './types';

const FORMATS = ['css', 'tailwind', 'json', 'figma', 'md'] as const;

export async function exportAll(
  tokens: Tokens,
  o: ExportOptions,
): Promise<{ filename: string; mime: 'application/zip'; bytes: Uint8Array }> {
  const files: Record<string, Uint8Array> = {};
  for (const f of FORMATS) {
    const e = exportFile(tokens, f, { ...o, tailwind: o.tailwind ?? 4 });
    files[e.filename] = strToU8(e.text);
  }
  return { filename: `${slug(tokens.site)}-tokens.zip`, mime: 'application/zip', bytes: zipSync(files) };
}
