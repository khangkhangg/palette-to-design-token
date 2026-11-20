/** Pure: the user's text, alignment and position edits as plain sentences for DESIGN.md's "Layout adjustments". */
import { colourEntries, cleanText, radiusEntries, spaceEntries } from '../export/model';
import type { ElementEdit, ExportEdits, ExportElement, LayoutAdjustment } from '../export/types';
import type { Tokens } from '../model/tokens';
import type { Edits, ElDesc, OneOff, PageEdit, TokenSpec } from './types';

const TEXT_MAX = 60;
const quote = (s: string): string => `“${cleanText(s, TEXT_MAX)}”`;

const tagOf = (d: ElDesc): string => cleanText(d.tag, 20).toLowerCase().replace(/[^a-z0-9-]/g, '') || 'element';

/**
 * "the h1 Houseplants…" (text in curly quotes); for an element without text of its own "the article .card
 * containing Harvest log" or "the div .hero"; "a div" when there is nothing to tell it by.
 */
function name(d: ElDesc): string {
  const tag = tagOf(d);
  const text = cleanText(d.text, TEXT_MAX);
  if (text) return `the ${tag} \u201c${text}\u201d`;
  const hook = cleanText(d.hook, 40).replace(/[^\w.#-]/g, '');
  const inside = cleanText(d.inside, TEXT_MAX);
  if (inside) return `the ${tag}${hook ? ` ${hook}` : ''} containing \u201c${inside}\u201d`;
  return hook ? `the ${tag} ${hook}` : `a ${tag}`;
}

const H = { left: 'left', center: 'centre', right: 'right' } as const;

function note(p: PageEdit): string {
  switch (p.type) {
    case 'text':
      return `Change the text ${quote(p.from)} (${tagOf(p.target)}) to ${quote(p.to)}.`;
    case 'align':
      return `Align ${name(p.target)} ${H[p.value]}.`;
    case 'valign':
      return `Align ${name(p.target)} to the ${p.value} of its container.`;
    case 'move':
      return p.where === 'into'
        ? `Move ${name(p.target)} into ${name(p.anchor)}.`
        : `Move ${name(p.target)} ${p.where} ${name(p.anchor)}.`;
  }
}

/** One sentence per page edit, in the order the user made them. Page text is made inert (see cleanText). */
export const layoutNotes = (page: PageEdit[]): string[] => page.map(note);

const element = (d: ElDesc): ExportElement => {
  const text = cleanText(d.text, TEXT_MAX);
  const selector = cleanText(d.hook, 40).replace(/[^\w.#-]/g, '');
  const contains = cleanText(d.inside, TEXT_MAX);
  return { tag: tagOf(d), ...(text ? { text } : {}), ...(selector ? { selector } : {}), ...(!text && contains ? { contains } : {}) };
};

function adjustment(p: PageEdit): LayoutAdjustment {
  switch (p.type) {
    case 'text': return { type: 'text', element: element(p.target), from: cleanText(p.from, 200), to: cleanText(p.to, 200) };
    case 'align': return { type: 'align', element: element(p.target), value: p.value };
    case 'valign': return { type: 'vertical-align', element: element(p.target), value: p.value };
    case 'move': return { type: 'move', element: element(p.target), where: p.where, anchor: element(p.anchor) };
  }
}

const same = (a: string, b: string): boolean => a.toLowerCase() === b.toLowerCase();

/** Where a one-off's old token sits in the exported file (`color.primary`, `space.3`), found by its current value. */
function tokenPath(o: OneOff, edits: Edits, specs: TokenSpec[], edited: Tokens): string | null {
  if (!o.token) return null;
  const added = edits.added.find((a) => a.id === o.token);
  const spec = specs.find((s) => s.id === o.token);
  const value = added ? added.value : spec ? edits.values[spec.id] ?? spec.value : null;
  if (value === null) return null;
  if (o.kind === 'colour') {
    const name = colourEntries(edited).find((e) => same(e.value, String(value)))?.name;
    return name ? `color.${name}` : null;
  }
  if (o.kind === 'type') {
    const name = edited.type.find((x) => x.sizePx === Number(value))?.name;
    return name ? `text.${name}` : null;
  }
  const entries = o.kind === 'space' ? spaceEntries(edited) : radiusEntries(edited);
  const name = entries.find((e) => e.value === Number(value))?.name;
  return name ? `${o.kind === 'space' ? 'space' : 'radius'}.${name}` : null;
}

/**
 * The edits that are not tokens, as data for tokens.json: text, alignment and position changes, and styles changed on
 * one element only. `edited` is the token set after the user's token changes, so token paths match the exported file.
 */
export function exportEdits(edits: Edits, specs: TokenSpec[], edited: Tokens): ExportEdits {
  return {
    layoutAdjustments: edits.page.map(adjustment),
    elementEdits: edits.oneOffs.map((o): ElementEdit => ({
      element: element(o.target),
      property: o.row,
      value: o.kind === 'colour' ? String(o.value).toUpperCase() : `${o.value}px`,
      token: tokenPath(o, edits, specs, edited),
    })),
  };
}
