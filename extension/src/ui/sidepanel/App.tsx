import { useEffect, useMemo, useRef, useState } from 'preact/hooks';
import { applyEdits, countEdits, hasEdits, tokenSpecs } from '../../edit/apply';
import { openEditor, type EditorLink } from '../../edit/client';
import { layoutNotes } from '../../edit/notes';
import { NO_EDITS, type Command, type EditorState } from '../../edit/types';
import type { Tokens } from '../../model/tokens';
import { scanActiveTab, shownReason, type ShownScanError } from '../../scan';
import { recheckIfDue } from '../../license';
import { getSettings, listScans, onSettingsChange, saveScan, type Settings } from '../../store';
import { EmptyState } from '../components/EmptyState';
import { Icon } from '../components/Icon';
import { formatDateTime, resolveLang, setLang, t } from '../i18n';
import { applyTheme } from '../theme';
import { viewFromSearch, type PanelView } from './open';
import { PlanSheet } from '../components/PlanSheet';
import { EditView } from './EditView';
import { ExportScreen } from './ExportScreen';
import { SystemView } from './SystemView';

async function activeHost(): Promise<string | null> {
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    return tab?.url ? new URL(tab.url).hostname || null : null;
  } catch {
    return null;
  }
}

async function newestScanFor(host: string | null): Promise<Tokens | null> {
  if (!host) return null;
  const scans = await listScans().catch(() => [] as Tokens[]);
  return scans
    .filter((s) => s.site === host)
    .reduce<Tokens | null>((best, s) => (!best || s.scannedAt > best.scannedAt ? s : best), null);
}

const NOTE_KEY: Record<ShownScanError, string> = {
  restricted: 'popup.restrictedTitle',
  'no-access': 'popup.noAccess',
  failed: 'popup.failedTitle',
};

export interface AppProps {
  initialView?: PanelView;
}

export function App({ initialView = viewFromSearch(location.search) }: AppProps) {
  const [settings, setLocal] = useState<Settings | null>(null);
  const [tokens, setTokens] = useState<Tokens | null>(null);
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState<ShownScanError | null>(null);
  const [view, setView] = useState<PanelView>(initialView);
  /** The on-page editor's last report. It outlives the link so token changes still reach the export after the page goes away. */
  const [edit, setEdit] = useState<EditorState | null>(null);
  const [editing, setEditing] = useState(false);
  const [editBusy, setEditBusy] = useState(false);
  const [lost, setLost] = useState(false);
  const link = useRef<EditorLink | null>(null);

  const specs = useMemo(() => (tokens ? tokenSpecs(tokens) : []), [tokens]);
  const edits = edit?.edits;
  const edited = useMemo(() => (tokens && edits ? applyEdits(tokens, edits) : tokens), [tokens, edits]);
  const layout = useMemo(() => layoutNotes(edits?.page ?? []), [edits]);

  const send = (cmd: Command) => link.current?.send(cmd);

  /** Ends the editing session and puts the page back; the edits are forgotten. */
  const closeEditor = () => {
    link.current?.close();
    link.current = null;
    setEdit(null);
    setEditing(false);
    setLost(false);
  };

  const toggleEditing = async () => {
    if (!tokens || editBusy) return;
    if (link.current) {
      link.current.send({ type: 'pick', on: !editing });
      setEditing(!editing);
      return;
    }
    setEditBusy(true);
    setError(null);
    setLost(false);
    try {
      link.current = await openEditor(specs, edits && { values: edits.values, added: edits.added }, {
        onState: setEdit,
        onLost: () => {
          // The page reloaded or navigated: what was drawn on it is gone, the token changes are not.
          link.current = null;
          setEditing(false);
          setEdit((s) => {
            if (!s) return s;
            const kept = { ...NO_EDITS(), values: s.edits.values, added: s.edits.added };
            setLost(hasEdits(s.edits));
            return hasEdits(kept) ? { picking: false, selected: null, uses: {}, edits: kept } : null;
          });
        },
      });
      setEditing(true);
    } catch (e) {
      setError(shownReason(e));
    } finally {
      setEditBusy(false);
    }
  };

  const scan = async () => {
    // A scan must read the page as the site made it, so the editor's changes come off first.
    closeEditor();
    setBusy(true);
    setError(null);
    try {
      const next = await scanActiveTab();
      await saveScan(next).catch(() => {});
      setTokens(next);
    } catch (e) {
      // A failed re-scan keeps whatever is already shown; the error becomes a dismissible note.
      setError(shownReason(e));
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => {
    let live = true;
    (async () => {
      const s = await getSettings();
      if (!live) return;
      applyTheme(s.theme);
      setLang(resolveLang(s.lang, navigator.language));
      setLocal(s);
      void recheckIfDue().catch(() => {});
      const saved = await newestScanFor(await activeHost());
      if (!live) return;
      if (saved) {
        setTokens(saved);
        setBusy(false);
      } else await scan();
    })();
    const stop = onSettingsChange((s) => {
      applyTheme(s.theme);
      setLang(resolveLang(s.lang, navigator.language));
      setLocal(s);
    });
    return () => { live = false; stop(); link.current?.close(); };
  }, []);

  if (!settings) return <div class="sp" />;
  if (view === 'unlock' || view === 'key') {
    return (
      <PlanSheet
        variant="page"
        plan="pro"
        start={view === 'key' ? 'key' : 'plans'}
        onClose={() => setView('system')}
        onUnlocked={() => setView(tokens ? 'export' : 'system')}
      />
    );
  }

  if (view === 'export' && edited) {
    return (
      <ExportScreen tokens={edited} layout={layout} pro={settings.pro} onBack={() => setView('system')} onHaveKey={() => setView('key')} />
    );
  }

  const counts = edits ? countEdits(edits) : null;
  const caption = counts && hasEdits(edits!) ? t('edit.caption', { ...counts }) : undefined;

  return (
    <div class="sp">
      <header class="sp-header">
        <div class="mark" aria-hidden="true" />
        <div class="header-text">
          <span class="header-name" title={t('panel.title')}>{t('panel.title')}</span>
          {tokens && (
            <span class="header-sub">
              {t('panel.scanned', { site: tokens.site, when: formatDateTime(tokens.scannedAt) })}
            </span>
          )}
        </div>
        {!settings.pro && (
          <button type="button" class="link-btn" onClick={() => setView('key')}>{t('panel.haveKey')}</button>
        )}
        <button
          type="button" class="icon-btn" aria-label={t('edit.toggle')} title={t('edit.toggle')} aria-pressed={editing}
          disabled={!tokens || busy || editBusy} onClick={() => void toggleEditing()}
        >
          <Icon name="cursor" />
        </button>
        <button type="button" class="icon-btn" aria-label={t('header.rescan')} disabled={busy} onClick={scan}>
          <span class={busy ? 'spin' : undefined}><Icon name="rescan" /></span>
        </button>
      </header>
      {busy && (
        <div class="status" role="status">
          <span class="spinner" aria-hidden="true" />
          <span>{t('popup.scanning')}</span>
        </div>
      )}
      {error && tokens && (
        <p class="note sp-note" role="status">
          <Icon name="alert" size={14} />
          <span>{t(NOTE_KEY[error])}</span>
          <button type="button" class="note-close" aria-label={t('panel.dismiss')} onClick={() => setError(null)}>
            <Icon name="close" size={14} />
          </button>
        </p>
      )}
      {lost && (
        <p class="note sp-note" role="status">
          <Icon name="info" size={14} />
          <span>{t('edit.lost')}</span>
          <button type="button" class="note-close" aria-label={t('panel.dismiss')} onClick={() => setLost(false)}>
            <Icon name="close" size={14} />
          </button>
        </p>
      )}
      {error === 'restricted' && !tokens && (
        <EmptyState alert icon="lock" title={t('popup.restrictedTitle')} body={t('popup.restrictedBody')} />
      )}
      {error === 'no-access' && !tokens && (
        <EmptyState alert icon="info" title={t('popup.noAccess')} />
      )}
      {error === 'failed' && !tokens && (
        <EmptyState alert icon="alert" title={t('popup.failedTitle')} body={t('popup.failedBody')}>
          <button type="button" class="btn" onClick={scan}>
            <Icon name="rescan" size={14} />
            {t('popup.retry')}
          </button>
        </EmptyState>
      )}
      {tokens && edited && editing && edit && (
        <>
          <EditView specs={specs} state={edit} send={send} />
          <footer class="sp-footer">
            <button type="button" class="sp-export" onClick={() => setView('export')}>
              <Icon name="download" size={16} />
              {t('panel.exportDesignSystem')}
              {counts && counts.tokens > 0 && <span class="ed-count">{counts.tokens}</span>}
            </button>
            <span class="sp-caption">{caption ?? t('panel.exportFormats')}</span>
          </footer>
        </>
      )}
      {tokens && edited && !(editing && edit) && (
        <SystemView tokens={edited} source={tokens} caption={caption} onExport={() => setView('export')} />
      )}
    </div>
  );
}
