// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createEditor, type Editor, type Measure } from '../../../src/edit/editor';
import type { EditorState, TokenSpec } from '../../../src/edit/types';

const SPECS: TokenSpec[] = [
  { id: 'colour:primary', kind: 'colour', name: 'primary', value: '#1F5E4B' },
  { id: 'colour:surface', kind: 'colour', name: 'surface', value: '#FFFFFF' },
  { id: 'colour:text', kind: 'colour', name: 'text', value: '#1A1A1A' },
  { id: 'type:h1', kind: 'type', name: 'text-h1', value: 40 },
  { id: 'type:body', kind: 'type', name: 'text-body', value: 16 },
  { id: 'space:16', kind: 'space', name: 'space-2', value: 16 },
  { id: 'radius:8', kind: 'radius', name: 'radius-md', value: 8 },
];

const PAGE = `
  <div id="hero" style="display:flex; flex-direction:column; background-color: rgb(255, 255, 255); padding: 16px">
    <h1 id="h" style="color: rgb(26, 26, 26); font-size: 40px">Houseplants</h1>
    <p id="p" style="color: rgb(26, 26, 26); font-size: 16px">Hardy <em>plants</em></p>
    <a id="a" style="background-color: rgb(31, 94, 75); color: rgb(255, 255, 255); font-size: 16px; border-top-left-radius: 8px; padding: 16px">Shop</a>
    <a id="b" style="background-color: rgb(31, 94, 75); color: rgb(255, 255, 255); font-size: 16px; border-top-left-radius: 8px; padding: 16px">Quiz</a>
    <span id="odd" style="color: rgb(200, 0, 120); font-size: 13px">Odd</span>
  </div>`;

/** jsdom lays nothing out: every element gets a box so none is skipped. */
const measure: Measure = {
  rect: () => ({ left: 0, top: 0, width: 100, height: 20 }),
  style: (el) => getComputedStyle(el),
};

let editor: Editor;
let state: EditorState;
const $ = (id: string) => document.getElementById(id) as HTMLElement;
const idOf = (id: string): number => {
  editor.handle({ type: 'select', el: null });
  $(id).dispatchEvent(new MouseEvent('click', { bubbles: true, composed: true }));
  return state.selected!.el;
};
const row = (key: string) => state.selected!.rows.find((r) => r.key === key)!;

beforeEach(() => {
  document.body.innerHTML = PAGE;
  editor = createEditor(document, (s) => { state = s; }, measure);
  editor.handle({ type: 'init', specs: SPECS });
});
afterEach(() => editor.destroy());

describe('indexing', () => {
  it('counts the elements that use each token', () => {
    expect(state.uses).toEqual({
      // the <em> inherits its paragraph's colour and size
      'colour:primary': 2, 'colour:surface': 3, 'colour:text': 3, 'type:h1': 1, 'type:body': 4, 'space:16': 3, 'radius:8': 2,
    });
    expect(state.picking).toBe(true);
    expect(state.selected).toBeNull();
  });

  it('describes the clicked element and the token behind each style', () => {
    idOf('a');
    expect(state.selected).toMatchObject({ desc: { tag: 'a', text: 'Shop', hook: '#a', inside: '' }, text: 'Shop', align: null, valign: null });
    expect(state.selected!.rows.map((r) => `${r.key}:${r.value}:${r.token}`)).toEqual([
      'color:#FFFFFF:colour:surface', 'background-color:#1F5E4B:colour:primary', 'font-size:16:type:body',
      'border-radius:8:radius:8', 'padding:16:space:16',
    ]);
    idOf('odd');
    expect(row('color')).toMatchObject({ value: '#C80078', token: null });
    expect(row('font-size').token).toBeNull();
  });
});

describe('token edits', () => {
  it('repaint every element that uses the token, through one CSS variable, and undo cleanly', () => {
    editor.handle({ type: 'setToken', id: 'colour:primary', value: '#7a3fc4' });
    expect(state.edits.values).toEqual({ 'colour:primary': '#7A3FC4' });
    const bg = $('a').style.getPropertyValue('background-color');
    expect(bg).toMatch(/^var\(--sdt-edit-/);
    expect($('b').style.getPropertyValue('background-color')).toBe(bg);
    expect($('a').style.getPropertyPriority('background-color')).toBe('important');
    expect(document.documentElement.style.getPropertyValue(bg.slice(4, -1))).toBe('#7A3FC4');
    expect($('h').style.getPropertyValue('color')).toBe('rgb(26, 26, 26)');

    editor.handle({ type: 'setToken', id: 'colour:primary', value: '#1f5e4b' });
    expect(state.edits.values).toEqual({});
    expect($('a').style.getPropertyValue('background-color')).toBe('rgb(31, 94, 75)');
    expect($('a').style.getPropertyPriority('background-color')).toBe('');
    expect(document.documentElement.getAttribute('style') ?? '').not.toContain('--sdt-edit');
  });

  it('ignore values that are not a colour or a sane size', () => {
    editor.handle({ type: 'setToken', id: 'colour:primary', value: 'nope' });
    editor.handle({ type: 'setToken', id: 'type:h1', value: -4 });
    expect(state.edits.values).toEqual({});
  });
});

describe('one-off edits', () => {
  it('change one element only, and are dropped when set back to the token value', () => {
    const a = idOf('a');
    editor.handle({ type: 'setOneOff', el: a, row: 'border-radius', value: 24 });
    expect($('a').style.getPropertyValue('border-radius')).toBe('24px');
    expect($('b').style.getPropertyValue('border-radius')).toBe('');
    expect(state.edits.oneOffs).toMatchObject([{ el: a, row: 'border-radius', label: 'radius', value: 24, token: 'radius:8' }]);
    expect(row('border-radius')).toMatchObject({ value: 24, token: 'radius:8', overridden: true });

    editor.handle({ type: 'setOneOff', el: a, row: 'border-radius', value: 8 });
    expect(state.edits.oneOffs).toEqual([]);
    expect($('a').style.getPropertyValue('border-radius')).toBe('');
  });

  it('"update token" pushes the value to every element that uses the token', () => {
    const a = idOf('a');
    editor.handle({ type: 'setOneOff', el: a, row: 'background-color', value: '#0E7490' });
    editor.handle({ type: 'promote', el: a, row: 'background-color', mode: 'update' });
    expect(state.edits).toMatchObject({ values: { 'colour:primary': '#0E7490' }, oneOffs: [], added: [] });
    expect($('b').style.getPropertyValue('background-color')).toMatch(/^var\(/);
  });

  it('"save as new token" names it after the old one, moves the element to it, and can be undone', () => {
    const a = idOf('a');
    editor.handle({ type: 'setOneOff', el: a, row: 'background-color', value: '#0E7490' });
    editor.handle({ type: 'promote', el: a, row: 'background-color', mode: 'new' });
    const [added] = state.edits.added;
    expect(added).toMatchObject({ kind: 'colour', name: 'primary-2', from: 'colour:primary', value: '#0E7490' });
    expect(state.uses[added.id]).toBe(1);
    expect(state.uses['colour:primary']).toBe(1);
    // (jsdom cannot resolve var(), so the repainted row itself is checked in the browser e2e)
    expect($('a').style.getPropertyValue('background-color')).toMatch(/^var\(--sdt-edit-/);

    editor.handle({ type: 'removeToken', id: added.id });
    expect(state.edits.added).toEqual([]);
    expect(state.uses['colour:primary']).toBe(2);
    expect($('a').style.getPropertyValue('background-color')).toBe('rgb(31, 94, 75)');
  });

  it('a style with no token can only become a new token', () => {
    const odd = idOf('odd');
    editor.handle({ type: 'setOneOff', el: odd, row: 'font-size', value: 14 });
    editor.handle({ type: 'promote', el: odd, row: 'font-size', mode: 'update' });
    expect(state.edits.oneOffs).toHaveLength(1);
    editor.handle({ type: 'promote', el: odd, row: 'font-size', mode: 'new' });
    expect(state.edits.added).toMatchObject([{ kind: 'type', name: 'text-custom', from: null, value: 14 }]);
  });
});

describe('page edits', () => {
  it('rewrites text from the panel, records before and after, and undoes it', () => {
    const h = idOf('h');
    editor.handle({ type: 'setText', el: h, text: 'Plants' });
    editor.handle({ type: 'setText', el: h, text: 'Plants that forgive' });
    expect($('h').textContent).toBe('Plants that forgive');
    expect(state.edits.page).toMatchObject([{ el: h, type: 'text', from: 'Houseplants', to: 'Plants that forgive' }]);
    editor.handle({ type: 'undoPage', el: h, edit: 'text' });
    expect($('h').textContent).toBe('Houseplants');
    expect(state.edits.page).toEqual([]);
  });

  it('does not offer panel text editing for an element with child elements', () => {
    const p = idOf('p');
    expect(state.selected!.text).toBeNull();
    editor.handle({ type: 'setText', el: p, text: 'gone' });
    expect($('p').innerHTML).toBe('Hardy <em>plants</em>');
  });

  it('aligns an element inside a column and a container\'s own contents', () => {
    const h = idOf('h');
    editor.handle({ type: 'setAlign', el: h, axis: 'h', value: 'center' });
    expect($('h').style.getPropertyValue('text-align')).toBe('center');
    expect($('h').style.getPropertyValue('align-self')).toBe('center');
    editor.handle({ type: 'setAlign', el: h, axis: 'v', value: 'bottom' });
    expect($('h').style.getPropertyValue('margin-top')).toBe('auto');
    expect(state.selected).toMatchObject({ align: 'center', valign: 'bottom', canValign: true });
    expect(state.edits.page.map((p) => p.type)).toEqual(['align', 'valign']);

    editor.handle({ type: 'setAlign', el: h, axis: 'h', value: null });
    expect($('h').style.getPropertyValue('align-self')).toBe('');
    expect(state.edits.page.map((p) => p.type)).toEqual(['valign']);

    const hero = idOf('hero');
    expect(state.selected!.desc).toEqual({ tag: 'div', text: '', hook: '#hero', inside: 'Houseplants' });
    editor.handle({ type: 'setAlign', el: hero, axis: 'v', value: 'middle' });
    expect($('hero').style.getPropertyValue('justify-content')).toBe('center');
    editor.handle({ type: 'setAlign', el: hero, axis: 'h', value: 'right' });
    expect($('hero').style.getPropertyValue('align-items')).toBe('flex-end');
  });
});

describe('reset and teardown', () => {
  it('put the page back exactly', () => {
    const shape = () => document.body.innerHTML;
    const before = shape();
    const a = idOf('a');
    editor.handle({ type: 'setToken', id: 'type:body', value: 18 });
    editor.handle({ type: 'setOneOff', el: a, row: 'padding', value: 20 });
    editor.handle({ type: 'setOneOff', el: a, row: 'color', value: '#000000' });
    editor.handle({ type: 'promote', el: a, row: 'color', mode: 'new' });
    editor.handle({ type: 'setAlign', el: a, axis: 'h', value: 'right' });
    editor.handle({ type: 'setText', el: a, text: 'Buy' });
    expect(shape()).not.toBe(before);

    editor.handle({ type: 'reset' });
    expect(state.edits).toEqual({ values: {}, added: [], oneOffs: [], page: [] });
    expect(shape()).toBe(before);

    editor.handle({ type: 'setToken', id: 'type:body', value: 18 });
    editor.destroy();
    expect(shape()).toBe(before);
    expect(document.querySelector('sdt-edit-overlay')).toBeNull();
    expect(document.documentElement.getAttribute('style') ?? '').toBe('');
  });

  it('replays token changes on a fresh page', () => {
    editor.destroy();
    editor = createEditor(document, (s) => { state = s; }, measure);
    editor.handle({
      type: 'init', specs: SPECS,
      replay: { values: { 'colour:primary': '#7A3FC4' }, added: [{ id: 'colour:+x', kind: 'colour', name: 'primary-2', from: 'colour:primary', value: '#0E7490' }] },
    });
    expect(state.edits.values).toEqual({ 'colour:primary': '#7A3FC4' });
    expect(state.edits.added).toHaveLength(1);
    expect($('a').style.getPropertyValue('background-color')).toMatch(/^var\(/);
  });

  it('stops listening when picking is turned off', () => {
    editor.handle({ type: 'pick', on: false });
    $('a').dispatchEvent(new MouseEvent('click', { bubbles: true, composed: true }));
    expect(state.selected).toBeNull();
    expect(state.picking).toBe(false);
  });
});
