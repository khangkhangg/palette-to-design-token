export interface ShadowLayer { color: string; offsetX: number; offsetY: number; blur: number; spread: number }

/** Split on `sep` outside parentheses. */
function splitTop(s: string, sep: RegExp | string): string[] {
  const out: string[] = [];
  let depth = 0;
  let cur = '';
  for (const ch of s) {
    if (ch === '(') depth++;
    else if (ch === ')') depth = Math.max(0, depth - 1);
    const isSep = depth === 0 && (typeof sep === 'string' ? ch === sep : sep.test(ch));
    if (isSep) { out.push(cur); cur = ''; } else cur += ch;
  }
  out.push(cur);
  return out.map((p) => p.trim()).filter(Boolean);
}

const LENGTH = /^[+-]?(\d+\.?\d*|\.\d+)(px)?$/;
const COLOUR = /^(#[0-9a-f]+|[a-z-]+(\(.*\))?)$/i;

function parseLayer(css: string): ShadowLayer | null {
  let color: string | null = null;
  const nums: number[] = [];
  for (const tok of splitTop(css, /\s/)) {
    if (tok.toLowerCase() === 'inset') return null;
    if (LENGTH.test(tok)) nums.push(parseFloat(tok));
    else if (COLOUR.test(tok) && color === null) color = tok;
    else return null;
  }
  if (color === null || color.toLowerCase() === 'none' || nums.length < 2 || nums.length > 4) return null;
  const [offsetX, offsetY, blur = 0, spread = 0] = nums;
  if (blur < 0) return null;
  return { color, offsetX, offsetY, blur, spread };
}

/** Parse a CSS box-shadow value; null when it has an inset layer or anything unparseable. */
export function parseShadow(css: string): ShadowLayer[] | null {
  const layers: ShadowLayer[] = [];
  for (const part of splitTop(css, ',')) {
    const l = parseLayer(part);
    if (!l) return null;
    layers.push(l);
  }
  return layers.length ? layers : null;
}
