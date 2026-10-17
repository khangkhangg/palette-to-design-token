import { useEffect, useState } from 'preact/hooks';
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

  const scan = async () => {
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
    return () => { live = false; stop(); };
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

  if (view === 'export' && tokens) {
    return <ExportScreen tokens={tokens} pro={settings.pro} onBack={() => setView('system')} onHaveKey={() => setView('key')} />;
  }

  return (
    <div class="sp">
      <header class="sp-header">
        <div class="mark" aria-hidden="true" />
        <div class="header-text">
          <span class="header-name">{t('panel.title')}</span>
          {tokens && (
            <span class="header-sub">
              {t('panel.scanned', { site: tokens.site, when: formatDateTime(tokens.scannedAt) })}
            </span>
          )}
        </div>
        {!settings.pro && (
          <button type="button" class="link-btn" onClick={() => setView('key')}>{t('panel.haveKey')}</button>
        )}
        {!settings.pro && <span class="sp-badge">{t('panel.viewOnly')}</span>}
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
      {tokens && <SystemView tokens={tokens} onExport={() => setView('export')} />}
    </div>
  );
}
