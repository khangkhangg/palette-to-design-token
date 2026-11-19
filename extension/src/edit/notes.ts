/** Pure: the user's text, alignment and position edits as plain sentences for DESIGN.md's "Layout adjustments". */
import { cleanText } from '../export/model';
import type { ElDesc, PageEdit } from './types';

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
