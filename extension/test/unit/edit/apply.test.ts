import { describe, expect, it } from 'vitest';
import { applyEdits, countEdits, hasEdits, tokenSpecs } from '../../../src/edit/apply';
import { layoutNotes } from '../../../src/edit/notes';
import { NO_EDITS, newTokenName, type Edits } from '../../../src/edit/types';
import { toDesignMd } from '../../../src/export/designmd';
import { toCss } from '../../../src/export/css';
import { EMPTY_TOKENS, type Tokens } from '../../../src/model/tokens';

const tokens = (): Tokens => ({
  ...EMPTY_TOKENS('fernleaf.example'),
  colours: [
    { name: 'primary', hex: '#1F5E4B', role: 'primary', weight: 0.4, interactive: true },
    { name: 'surface', hex: '#FFFFFF', role: 'surface', weight: 0.3, interactive: false },
    { name: 'text', hex: '#1A1A1A', role: 'text', weight: 0.2, interactive: false },
  ],
  fonts: [{ name: 'body', family: 'Inter' }],
  type: [
    { name: 'h1', family: 'Georgia', sizePx: 40, weight: 700, lineHeightPx: 48, chars: 30 },
    { name: 'body', family: 'Inter', sizePx: 16, weight: 400, lineHeightPx: 24, chars: 900 },
  ],
  spacing: { base: 8, scale: [8, 16, 24] },
  radius: [4, 8, 9999],
  contrast: [{ fg: '#FFFFFF', bg: '#1F5E4B', ratio: 7.6, large: false, uses: 3, where: 'buttons', grade: 'AAA' }],
});

const edits = (over: Partial<Edits>): Edits => ({ ...NO_EDITS(), ...over });
const ALL = { parts: ['colours', 'typography', 'spacing', 'radius', 'shadows'] as const };

describe('tokenSpecs', () => {
  it('lists every editable token with a stable id and the name the exports use', () => {
    expect(tokenSpecs(tokens()).map((s) => `${s.id}=${s.name}=${s.value}`)).toEqual([
      'colour:primary=primary=#1F5E4B', 'colour:surface=surface=#FFFFFF', 'colour:text=text=#1A1A1A',
      'type:h1=text-h1=40', 'type:body=text-body=16',
      'space:8=space-1=8', 'space:16=space-2=16', 'space:24=space-3=24',
      'radius:4=radius-sm=4', 'radius:8=radius-md=8',
    ]);
  });
});

describe('applyEdits', () => {
  it('returns the same tokens when nothing that reaches a token changed', () => {
    const t = tokens();
    expect(applyEdits(t, NO_EDITS())).toBe(t);
    expect(applyEdits(t, edits({ page: [{ el: 1, type: 'align', target: { tag: 'h1', text: 'Hi', hook: '', inside: '' }, value: 'center' }] }))).toBe(t);
  });

  it('changes a colour everywhere it is exported and re-grades the contrast pairs that use it', () => {
    const out = applyEdits(tokens(), edits({ values: { 'colour:primary': '#9fd8c4' } }));
    expect(out.colours[0]).toMatchObject({ name: 'primary', hex: '#9FD8C4', role: 'primary' });
    expect(toCss(out, { parts: [...ALL.parts] })).toContain('--color-primary: #9FD8C4;');
    expect(out.contrast[0]).toMatchObject({ fg: '#FFFFFF', bg: '#9FD8C4', grade: 'fail' });
    expect(out.contrast[0].ratio).toBeLessThan(3);
    expect(out.contrast[0].fix).toBeDefined();
  });

  it('scales the line height with a type size', () => {
    const out = applyEdits(tokens(), edits({ values: { 'type:h1': 50 } }));
    expect(out.type[0]).toMatchObject({ name: 'h1', sizePx: 50, lineHeightPx: 60 });
    expect(out.type[1]).toMatchObject({ name: 'body', sizePx: 16, lineHeightPx: 24 });
  });

  it('keeps spacing and radius sorted and free of duplicates, and never touches the full radius', () => {
    const out = applyEdits(tokens(), edits({ values: { 'space:8': 24, 'radius:4': 12 } }));
    expect(out.spacing.scale).toEqual([16, 24]);
    expect(out.radius).toEqual([8, 12, 9999]);
  });

  it('adds new tokens: a colour takes the role of the token it came from', () => {
    const out = applyEdits(tokens(), edits({
      added: [
        { id: 'colour:+1', kind: 'colour', name: 'primary-2', from: 'colour:primary', value: '#0E7490' },
        { id: 'colour:+2', kind: 'colour', name: 'custom', from: null, value: '#ff00aa' },
        { id: 'type:+3', kind: 'type', name: 'text-h1-2', from: 'type:h1', value: 30 },
        { id: 'space:+4', kind: 'space', name: 'space-new', from: null, value: 20 },
        { id: 'radius:+5', kind: 'radius', name: 'radius-new', from: 'radius:8', value: 16 },
      ],
    }));
    expect(out.colours.slice(3)).toEqual([
      { name: 'primary-2', hex: '#0E7490', role: 'primary', weight: 0, interactive: true },
      { name: 'custom', hex: '#FF00AA', role: 'other', weight: 0, interactive: false },
    ]);
    expect(out.type[2]).toMatchObject({ name: 'h1-2', family: 'Georgia', sizePx: 30, lineHeightPx: 36 });
    expect(out.spacing.scale).toEqual([8, 16, 20, 24]);
    expect(out.radius).toEqual([4, 8, 16, 9999]);
  });

  it('counts each kind of change', () => {
    const e = edits({ values: { 'colour:text': '#000000' }, added: [{ id: 'space:+1', kind: 'space', name: 'space-new', from: null, value: 20 }] });
    expect(countEdits(e)).toEqual({ tokens: 2, oneOffs: 0, page: 0 });
    expect(hasEdits(e)).toBe(true);
    expect(hasEdits(NO_EDITS())).toBe(false);
  });
});

describe('newTokenName', () => {
  it('numbers from the base name and skips names already taken', () => {
    expect(newTokenName('accent', ['accent'])).toBe('accent-2');
    expect(newTokenName('primary-2', ['primary', 'primary-2'])).toBe('primary-3');
    expect(newTokenName(null, [])).toBe('custom');
    expect(newTokenName(null, ['custom'])).toBe('custom-2');
  });
});

describe('layout notes in DESIGN.md', () => {
  const h1 = { tag: 'h1', text: 'Houseplants that forgive', hook: '.title', inside: '' };
  const notes = layoutNotes([
    { el: 1, type: 'text', target: h1, from: 'Houseplants that forgive', to: 'Plants that `forgive`\n<script>' },
    { el: 1, type: 'align', target: h1, value: 'center' },
    { el: 2, type: 'valign', target: { tag: 'div', text: '', hook: '.hero', inside: '' }, value: 'bottom' },
    { el: 3, type: 'move', target: { tag: 'span', text: 'New season', hook: '', inside: '' }, where: 'after', anchor: { tag: 'p', text: 'Hardy plants', hook: '', inside: '' } },
    { el: 4, type: 'move', target: { tag: 'img', text: '', hook: '', inside: '' }, where: 'into', anchor: { tag: 'div', text: '', hook: '#gallery', inside: '' } },
    { el: 5, type: 'move', target: { tag: 'article', text: '', hook: '.card', inside: 'Harvest log' }, where: 'before', anchor: { tag: 'article', text: '', hook: '.card', inside: 'Bed planner' } },
  ]);

  it('writes one plain sentence per edit, with page text made inert', () => {
    expect(notes).toEqual([
      'Change the text \u201cHouseplants that forgive\u201d (h1) to \u201cPlants that forgive script\u201d.',
      'Align the h1 “Houseplants that forgive” centre.',
      'Align the div .hero to the bottom of its container.',
      'Move the span “New season” after the p “Hardy plants”.',
      'Move a img into the div #gallery.',
      'Move the article .card containing \u201cHarvest log\u201d before the article .card containing \u201cBed planner\u201d.',
    ]);
  });

  it('adds a Layout adjustments section only when there are notes', () => {
    const o = { parts: [...ALL.parts] };
    const plain = toDesignMd(tokens(), o);
    expect(plain).not.toContain('Layout adjustments');
    const md = toDesignMd(tokens(), { ...o, layout: notes });
    expect(md).toContain('## Layout adjustments\n\nChanges the user made to the page while previewing these tokens.');
    expect(md).toContain('\n- Align the div .hero to the bottom of its container.\n');
    expect(md.startsWith(plain.trimEnd())).toBe(true);
  });
});
