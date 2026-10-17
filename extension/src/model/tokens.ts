export type Role = 'primary' | 'accent' | 'surface' | 'text' | 'border' | 'other';
export interface ColourToken { name: string; hex: string; role: Role; weight: number; interactive: boolean }
export interface TypeToken { name: string; family: string; sizePx: number; weight: number; lineHeightPx: number | null; chars: number }
export type Grade = 'AAA' | 'AA' | 'AA-large' | 'fail' | 'unknown';
export interface ContrastPair {
  fg: string; bg: string; ratio: number | null; large: boolean; uses: number;
  where: 'body text' | 'headings' | 'links' | 'buttons' | 'other';
  grade: Grade; fix?: { fg: string; bg: string; ratio: number };
}
export interface Tokens {
  site: string; scannedAt: string;
  colours: ColourToken[];
  fonts: { name: 'body' | 'heading' | 'mono'; family: string }[];
  type: TypeToken[];
  spacing: { base: 4 | 8 | null; scale: number[] };
  radius: number[];
  shadows: string[];
  contrast: ContrastPair[];
  /** The scanned page (origin + path, title, meta description), capped; only AI insights send it, exporters ignore it. */
  page?: PageInfo;
}
export interface PageInfo { url: string; title: string; description: string }

export const EMPTY_TOKENS = (site: string): Tokens => ({
  site,
  scannedAt: new Date().toISOString(),
  colours: [],
  fonts: [],
  type: [],
  spacing: { base: null, scale: [] },
  radius: [],
  shadows: [],
  contrast: [],
});
