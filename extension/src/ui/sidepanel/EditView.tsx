import { useState } from 'preact/hooks';
import { countEdits } from '../../edit/apply';
import type {
  Align, Command, EditorState, ElDesc, PageEdit, Row, Selected, TokenKind, TokenSpec, TokenValue, VAlign,
} from '../../edit/types';
import { Icon, type IconName } from '../components/Icon';
import { plural, t } from '../i18n';

/** A token as the panel lists it: its current value, and what it was scanned as (null for a token the user added). */
interface LiveToken { id: string; kind: TokenKind; name: string; value: TokenValue; orig: TokenValue | null }

const KINDS: TokenKind[] = ['colour', 'type', 'space', 'radius'];
const KIND_TITLE: Record<TokenKind, string> = {
  colour: 'panel.colours', type: 'edit.typeSizes', space: 'panel.spacing', radius: 'panel.radius',
};
const H_ALIGN: [Align, IconName][] = [['left', 'alignLeft'], ['center', 'alignCenter'], ['right', 'alignRight']];
const V_ALIGN: [VAlign, IconName][] = [['top', 'alignTop'], ['middle', 'alignMiddle'], ['bottom', 'alignBottom']];
const HEX = /^#?[0-9a-f]{6}$/i;
const MAX_PX = 999;

const show = (kind: TokenKind, v: TokenValue): string => (kind === 'colour' ? String(v).toUpperCase() : `${v}px`);

function liveTokens(specs: TokenSpec[], state: EditorState): LiveToken[] {
  return [
    ...specs.map((s) => ({ id: s.id, kind: s.kind, name: s.name, value: state.edits.values[s.id] ?? s.value, orig: s.value })),
    ...state.edits.added.map((a) => ({ id: a.id, kind: a.kind, name: a.name, value: a.value, orig: null })),
  ];
}

/** "“Shop the range”" for an element with text, else its tag and class or id. */
const nameOf = (d: ElDesc): string =>
  d.text ? `\u201c${d.text}\u201d` : d.inside ? `${d.tag}${d.hook} \u201c${d.inside}\u201d` : `${d.tag}${d.hook}`;

function Value({ kind, value, was }: { kind: TokenKind; value: TokenValue; was?: boolean }) {
  return (
    <span class={was ? 'ed-val ed-was' : 'ed-val'}>
      {kind === 'colour' && <span class="legend-dot" style={{ backgroundColor: String(value) }} aria-hidden="true" />}
      {show(kind, value)}
    </span>
  );
}

function ColourControl({ id, value, onChange }: { id: string; value: string; onChange(v: string): void }) {
  // The text field keeps what is being typed until it is a whole hex value; the page answers with the stored one.
  const [draft, setDraft] = useState<string | null>(null);
  return (
    <span class="ed-ctl">
      <label class="ed-sw" style={{ backgroundColor: value }}>
        <input
          type="color" id={`${id}-c`} value={value.toLowerCase()} aria-label={t('edit.pickColour')}
          onInput={(e) => onChange(e.currentTarget.value)}
        />
      </label>
      <input
        class="ed-hex" id={`${id}-h`} type="text" maxLength={7} spellcheck={false} aria-label={t('edit.hexValue')}
        value={draft ?? value.toUpperCase()}
        onInput={(e) => {
          const v = e.currentTarget.value.trim();
          setDraft(v);
          if (HEX.test(v)) onChange(v.startsWith('#') ? v : `#${v}`);
        }}
        onBlur={() => setDraft(null)}
      />
    </span>
  );
}

function NumberControl({ id, value, onChange }: { id: string; value: number; onChange(v: number): void }) {
  const [draft, setDraft] = useState<string | null>(null);
  const step = (d: number) => onChange(Math.min(MAX_PX, Math.max(0, Math.round(value) + d)));
  return (
    <span class="ed-ctl">
      <button type="button" class="ed-step" aria-label={t('edit.decrease')} onClick={() => step(-1)}><Icon name="minus" size={12} /></button>
      <input
        class="ed-num" id={`${id}-n`} type="text" inputMode="decimal" maxLength={6} aria-label={t('edit.pxValue')}
        value={draft ?? String(value)}
        onInput={(e) => {
          const v = e.currentTarget.value.trim();
          setDraft(v);
          if (/^\d{1,3}(\.\d{1,2})?$/.test(v)) onChange(Number(v));
        }}
        onBlur={() => setDraft(null)}
      />
      <span class="ed-unit">px</span>
      <button type="button" class="ed-step" aria-label={t('edit.increase')} onClick={() => step(1)}><Icon name="plus" size={12} /></button>
    </span>
  );
}

function Control({ id, kind, value, onChange }: { id: string; kind: TokenKind; value: TokenValue; onChange(v: TokenValue): void }) {
  return kind === 'colour'
    ? <ColourControl id={id} value={String(value)} onChange={onChange} />
    : <NumberControl id={id} value={Number(value)} onChange={onChange} />;
}

function TextField({ el, text, send }: { el: number; text: string; send(cmd: Command): void }) {
  const [draft, setDraft] = useState<string | null>(null);
  return (
    <label class="ed-pf">
      <span>{t('edit.text')}</span>
      <input
        class="ed-field" id={`ed-text-${el}`} type="text" value={draft ?? text}
        onInput={(e) => { setDraft(e.currentTarget.value); send({ type: 'setText', el, text: e.currentTarget.value }); }}
        onBlur={() => setDraft(null)}
      />
    </label>
  );
}

interface InspectorProps { selected: Selected; tokens: LiveToken[]; uses: Record<string, number>; send(cmd: Command): void }

function Inspector({ selected, tokens, uses, send }: InspectorProps) {
  const [scope, setScope] = useState<'token' | 'element'>('token');
  const el = selected.el;
  const byId = new Map(tokens.map((x) => [x.id, x]));
  const toggle = <V extends Align | VAlign>(current: V | null, v: V): V | null => (current === v ? null : v);

  const meta = (row: Row) => {
    const token = row.token ? byId.get(row.token) : undefined;
    if (scope === 'token') {
      if (!token) return <span>{t('edit.noToken')}</span>;
      return (
        <>
          <span class="ed-tk">{token.name}</span>
          <span>{plural('edit.elements', uses[token.id] ?? 0)}</span>
          {row.overridden && <span class="ed-pill ed-pill-warn">{t('edit.overridden')}</span>}
        </>
      );
    }
    if (row.overridden) {
      return (
        <>
          <span class="ed-pill ed-pill-warn">{t('edit.edited')}</span>
          {token && <span>{t('edit.wasToken', { token: token.name })}</span>}
        </>
      );
    }
    return <span>{token ? t('edit.fromToken', { token: token.name }) : t('edit.noTokenShort')}</span>;
  };

  return (
    <div class="ed-insp">
      <div class="ed-insp-head">
        <code>{selected.desc.tag}</code>
        <b title={nameOf(selected.desc)}>{selected.desc.text || selected.desc.hook || selected.desc.tag}</b>
        <span class="ed-size">{selected.width}×{selected.height}</span>
        <button type="button" class="note-close" aria-label={t('edit.deselect')} onClick={() => send({ type: 'select', el: null })}>
          <Icon name="close" size={14} />
        </button>
      </div>

      <div class="ed-pg">
        {selected.text !== null && <TextField key={el} el={el} text={selected.text} send={send} />}
        <div class="ed-pf">
          <span>{t('edit.align')}</span>
          <span class="ed-als">
            <span class="ed-al" role="group" aria-label={t('edit.alignH')}>
              {H_ALIGN.map(([v, icon]) => (
                <button
                  type="button" key={v} aria-pressed={selected.align === v} aria-label={t(`edit.to.${v}`)} title={t(`edit.to.${v}`)}
                  onClick={() => send({ type: 'setAlign', el, axis: 'h', value: toggle(selected.align, v) })}
                >
                  <Icon name={icon} size={14} />
                </button>
              ))}
            </span>
            <span class="ed-al" role="group" aria-label={t('edit.alignV')}>
              {V_ALIGN.map(([v, icon]) => (
                <button
                  type="button" key={v} aria-pressed={selected.valign === v} aria-label={t(`edit.to.${v}`)} title={t(`edit.to.${v}`)}
                  disabled={!selected.canValign}
                  onClick={() => send({ type: 'setAlign', el, axis: 'v', value: toggle(selected.valign, v) })}
                >
                  <Icon name={icon} size={14} />
                </button>
              ))}
            </span>
          </span>
        </div>
        <p class="ed-hint">{selected.canValign ? t('edit.dragHint') : `${t('edit.noValign')} ${t('edit.dragHint')}`}</p>
      </div>

      {selected.rows.length > 0 && (
        <>
          <div class="seg seg-2 ed-scope" role="radiogroup" aria-label={t('edit.scopeLabel')}>
            {(['token', 'element'] as const).map((s) => (
              <label class="seg-opt" key={s}>
                <input type="radio" name="sdt-edit-scope" value={s} checked={scope === s} onChange={() => setScope(s)} />
                <span>{t(s === 'token' ? 'edit.scopeToken' : 'edit.scopeElement')}</span>
              </label>
            ))}
          </div>
          <p class="ed-hint">{t(scope === 'token' ? 'edit.hintToken' : 'edit.hintElement')}</p>
          <ul class="ed-rows">
            {selected.rows.map((row) => {
              const token = row.token ? byId.get(row.token) : undefined;
              const hl = scope === 'token' && token ? token.id : null;
              return (
                <li
                  key={row.key}
                  onMouseEnter={hl ? () => send({ type: 'highlight', token: hl }) : undefined}
                  onMouseLeave={hl ? () => send({ type: 'highlight', token: null }) : undefined}
                >
                  <span class="ed-name">{t(`edit.rows.${row.label}`)}</span>
                  {scope === 'token'
                    ? token
                      ? <Control id={`ed-t-${row.key}`} kind={token.kind} value={token.value} onChange={(v) => send({ type: 'setToken', id: token.id, value: v })} />
                      : <Value kind={row.kind} value={row.value} />
                    : <Control id={`ed-e-${row.key}`} kind={row.kind} value={row.value} onChange={(v) => send({ type: 'setOneOff', el, row: row.key, value: v })} />}
                  <span class="ed-meta">{meta(row)}</span>
                </li>
              );
            })}
          </ul>
        </>
      )}
    </div>
  );
}

function pageEditText(p: PageEdit): string {
  switch (p.type) {
    case 'text': return `“${p.to}”`;
    case 'align': return t(`edit.to.${p.value}`);
    case 'valign': return t(`edit.to.${p.value}`);
    case 'move': return t('edit.moved');
  }
}

function Changes({ state, tokens, send }: { state: EditorState; tokens: LiveToken[]; send(cmd: Command): void }) {
  const { edits, uses } = state;
  const byId = new Map(tokens.map((x) => [x.id, x]));
  const changed = tokens.filter((x) => x.orig === null || x.id in edits.values);
  const counts = countEdits(edits);
  if (!counts.tokens && !counts.oneOffs && !counts.page) return null;
  return (
    <section class="sp-section" aria-labelledby="ed-changes">
      <h2 class="sp-h ed-h" id="ed-changes">
        {t('edit.changes')}
        <button type="button" class="link-btn" onClick={() => send({ type: 'reset' })}>{t('edit.resetAll')}</button>
      </h2>
      {changed.length > 0 && (
        <div class="ed-group">
          <p class="ed-group-title">{t('edit.tokenChanges')} <span class="ed-pill ed-pill-pass">{t('edit.inExport')}</span></p>
          <ul class="ed-rows">
            {changed.map((x) => (
              <li
                key={x.id}
                onMouseEnter={() => send({ type: 'highlight', token: x.id })}
                onMouseLeave={() => send({ type: 'highlight', token: null })}
              >
                <span class="ed-name ed-mono">{x.name}</span>
                <button
                  type="button" class="ed-mini"
                  onClick={() => send(x.orig === null ? { type: 'removeToken', id: x.id } : { type: 'setToken', id: x.id, value: x.orig })}
                >
                  {t('edit.undo')}
                </button>
                <span class="ed-meta">
                  {x.orig === null
                    ? <span class="ed-pill ed-pill-pass">{t('edit.new')}</span>
                    : <><Value kind={x.kind} value={x.orig} was /><Icon name="arrow" size={11} /></>}
                  <Value kind={x.kind} value={x.value} />
                  <span>{plural('edit.elements', uses[x.id] ?? 0)}</span>
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
      {edits.oneOffs.length > 0 && (
        <div class="ed-group">
          <p class="ed-group-title">{t('edit.oneOffs')} <span class="ed-pill ed-pill-warn">{t('edit.notInExport')}</span></p>
          <ul class="ed-rows">
            {edits.oneOffs.map((o) => {
              const token = o.token ? byId.get(o.token) : undefined;
              return (
                <li key={`${o.el}-${o.row}`}>
                  <button type="button" class="ed-name ed-link" onClick={() => send({ type: 'select', el: o.el })}>
                    {nameOf(o.target)} · {t(`edit.rows.${o.label}`)}
                  </button>
                  <Value kind={o.kind} value={o.value} />
                  <span class="ed-acts">
                    <button type="button" class="ed-mini ed-mini-go" onClick={() => send({ type: 'promote', el: o.el, row: o.row, mode: 'new' })}>
                      {t('edit.saveNew')}
                    </button>
                    {token && (
                      <button type="button" class="ed-mini" onClick={() => send({ type: 'promote', el: o.el, row: o.row, mode: 'update' })}>
                        {t('edit.updateToken', { token: token.name })}
                      </button>
                    )}
                    <button type="button" class="ed-mini" onClick={() => send({ type: 'setOneOff', el: o.el, row: o.row, value: null })}>
                      {t('edit.undo')}
                    </button>
                  </span>
                </li>
              );
            })}
          </ul>
        </div>
      )}
      {edits.page.length > 0 && (
        <div class="ed-group">
          <p class="ed-group-title">{t('edit.pageEdits')} <span class="ed-pill">{t('edit.inDesignMd')}</span></p>
          <ul class="ed-rows">
            {edits.page.map((p) => (
              <li key={`${p.el}-${p.type}`}>
                <button type="button" class="ed-name ed-link" onClick={() => send({ type: 'select', el: p.el })}>
                  {p.type === 'text' ? `“${p.from}”` : nameOf(p.target)} · {t(`edit.page.${p.type}`)}
                </button>
                <button type="button" class="ed-mini" onClick={() => send({ type: 'undoPage', el: p.el, edit: p.type })}>{t('edit.undo')}</button>
                <span class="ed-meta">{pageEditText(p)}</span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  );
}

export interface EditViewProps {
  /** The scanned tokens that can be edited. */
  specs: TokenSpec[];
  state: EditorState;
  send(cmd: Command): void;
}

/** The side panel while "Edit on page" is on: the picked element, the changes so far, and every token as a control. */
export function EditView({ specs, state, send }: EditViewProps) {
  const tokens = liveTokens(specs, state);
  return (
    <div class="sp-body ed">
      {state.selected
        ? <Inspector key={state.selected.el} selected={state.selected} tokens={tokens} uses={state.uses} send={send} />
        : <p class="ed-empty">{t('edit.hintPick')}</p>}
      <Changes state={state} tokens={tokens} send={send} />
      {KINDS.map((kind) => {
        const list = tokens.filter((x) => x.kind === kind);
        if (!list.length) return null;
        return (
          <section class="sp-section" key={kind} aria-labelledby={`ed-kind-${kind}`}>
            <h2 class="sp-h" id={`ed-kind-${kind}`}>{t(KIND_TITLE[kind])}</h2>
            <ul class="ed-rows">
              {list.map((x) => (
                <li
                  key={x.id}
                  onMouseEnter={() => send({ type: 'highlight', token: x.id })}
                  onMouseLeave={() => send({ type: 'highlight', token: null })}
                >
                  <span class="ed-name ed-mono">{x.name}</span>
                  <Control id={`ed-k-${x.id}`} kind={x.kind} value={x.value} onChange={(v) => send({ type: 'setToken', id: x.id, value: v })} />
                  <span class="ed-meta">
                    <span>{plural('edit.elements', state.uses[x.id] ?? 0)}</span>
                    {x.orig === null && <span class="ed-pill ed-pill-pass">{t('edit.new')}</span>}
                  </span>
                </li>
              ))}
            </ul>
          </section>
        );
      })}
    </div>
  );
}
