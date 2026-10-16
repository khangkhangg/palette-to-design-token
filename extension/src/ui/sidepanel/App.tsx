import { useEffect, useState } from 'preact/hooks';
import type { Tokens } from '../../model/tokens';
import { ScanError, scanActiveTab } from '../../scan';
import { getSettings, listScans, onSettingsChange, saveScan, type Settings } from '../../store';
import { EmptyState } from '../components/EmptyState';
import { Icon } from '../components/Icon';
import { renderedLang, resolveLang, setLang, t } from '../i18n';
import { applyTheme } from '../theme';
import { viewFromSearch, type PanelView } from './open';
import { SystemView } from './SystemView';

type State =
  | { kind: 'scanning' }
  | { kind: 'ready'; tokens: Tokens }
  | { kind: 'error'; reason: 'restricted' | 'failed' };

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

function scannedWhen(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return new Intl.DateTimeFormat(renderedLang(), { dateStyle: 'medium', timeStyle: 'short' }).format(d);
}

export interface AppProps {
  initialView?: PanelView;
}

export function App({ initialView = viewFromSearch(location.search) }: AppProps) {
  const [settings, setLocal] = useState<Settings | null>(null);
  const [state, setState] = useState<State>({ kind: 'scanning' });
  const [view, setView] = useState<PanelView>(initialView);

  const scan = async () => {
    setState({ kind: 'scanning' });
    try {
      const tokens = await scanActiveTab();
      await saveScan(tokens).catch(() => {});
      setState({ kind: 'ready', tokens });
    } catch (e) {
      setState({ kind: 'error', reason: e instanceof ScanError && e.reason === 'restricted' ? 'restricted' : 'failed' });
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
      const saved = await newestScanFor(await activeHost());
      if (!live) return;
      if (saved) setState({ kind: 'ready', tokens: saved });
      else await scan();
    })();
    const stop = onSettingsChange((s) => {
      applyTheme(s.theme);
      setLocal(s);
    });
    return () => { live = false; stop(); };
  }, []);

  if (!settings) return <div class="sp" />;

  const tokens = state.kind === 'ready' ? state.tokens : null;
  const busy = state.kind === 'scanning';

  return (
    <div class="sp">
      <header class="sp-header">
        <div class="mark" aria-hidden="true" />
        <div class="header-text">
          <span class="header-name">{t('panel.title')}</span>
          {tokens && (
            <span class="header-sub">
              {t('panel.scanned', { site: tokens.site, when: scannedWhen(tokens.scannedAt) })}
            </span>
          )}
        </div>
        {!settings.pro && <span class="sp-badge">{t('panel.viewOnly')}</span>}
        <button type="button" class="icon-btn" aria-label={t('header.rescan')} disabled={busy} onClick={scan}>
          <span class={busy ? 'spin' : undefined}><Icon name="rescan" /></span>
        </button>
      </header>
      {view === 'export' ? (
        <div class="sp-body sp-placeholder">
          <p class="sp-soon">{t('export.comingSoon')}</p>
          <button type="button" class="btn btn-small" onClick={() => setView('system')}>
            <Icon name="back" size={14} />
            {t('panel.back')}
          </button>
        </div>
      ) : (
        <>
          {busy && (
            <div class="status" role="status">
              <span class="spinner" aria-hidden="true" />
              <span>{t('popup.scanning')}</span>
            </div>
          )}
          {state.kind === 'error' && state.reason === 'restricted' && (
            <EmptyState alert icon="lock" title={t('popup.restrictedTitle')} body={t('popup.restrictedBody')} />
          )}
          {state.kind === 'error' && state.reason === 'failed' && (
            <EmptyState alert icon="alert" title={t('popup.failedTitle')} body={t('popup.failedBody')}>
              <button type="button" class="btn" onClick={scan}>
                <Icon name="rescan" size={14} />
                {t('popup.retry')}
              </button>
            </EmptyState>
          )}
          {tokens && <SystemView tokens={tokens} onExport={() => setView('export')} />}
        </>
      )}
    </div>
  );
}
