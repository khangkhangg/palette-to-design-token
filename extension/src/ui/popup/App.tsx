import { useEffect, useState } from 'preact/hooks';
import type { Tokens } from '../../model/tokens';
import { scanActiveTab, shownReason, type ShownScanError } from '../../scan';
import { recheckIfDue } from '../../license';
import { getSettings, onSettingsChange, saveScan, setSettings, type Settings } from '../../store';
import { EmptyState } from '../components/EmptyState';
import { Header } from '../components/Header';
import { Icon } from '../components/Icon';
import { Tabs } from '../components/Tabs';
import { plural, renderedLang, resolveLang, setLang, t } from '../i18n';
import { applyTheme } from '../theme';
import { activeTabId, openSidePanel, type PanelView } from '../sidepanel/open';
import { ColoursTab, orderedColours, useCopy } from './ColoursTab';
import { ContrastTab } from './ContrastTab';
import { SpacingTab } from './SpacingTab';
import { TypeTab } from './TypeTab';

type State =
  | { kind: 'scanning' }
  | { kind: 'ready'; tokens: Tokens }
  | { kind: 'error'; reason: ShownScanError };

const TAB_IDS = ['colours', 'type', 'spacing', 'contrast'] as const;

export interface AppProps {
  /** Defaults to opening the side panel on its export view. */
  onExport?(tokens: Tokens, settings: Settings): void;
}

export function App({ onExport }: AppProps) {
  const [settings, setLocal] = useState<Settings | null>(null);
  const [lang, setLangState] = useState('en');
  const [state, setState] = useState<State>({ kind: 'scanning' });
  const [tab, setTab] = useState<string>('colours');
  const [tabId, setTabId] = useState<number | null>(null);
  const [panelError, setPanelError] = useState(false);
  const { copied, failed, copy } = useCopy();

  const scan = async () => {
    setState({ kind: 'scanning' });
    try {
      const tokens = await scanActiveTab();
      await saveScan(tokens).catch(() => {});
      setState({ kind: 'ready', tokens });
    } catch (e) {
      setState({ kind: 'error', reason: shownReason(e) });
    }
  };

  useEffect(() => { void activeTabId().then(setTabId); }, []);

  const openPanel = (view: PanelView) => {
    setPanelError(false);
    const fail = (cause?: unknown) => { console.error('Could not open the side panel', cause); setPanelError(true); };
    if (tabId === null) return fail();
    try {
      openSidePanel(tabId, view).catch(fail);
    } catch (e) {
      fail(e);
    }
  };

  useEffect(() => {
    let live = true;
    (async () => {
      const s = await getSettings();
      if (!live) return;
      applyTheme(s.theme);
      const l = resolveLang(s.lang, navigator.language);
      setLang(l);
      setLangState(renderedLang());
      setLocal(s);
      void recheckIfDue().catch(() => {});
      await scan();
    })();
    const stop = onSettingsChange((s) => {
      applyTheme(s.theme);
      setLocal(s);
    });
    return () => { live = false; stop(); };
  }, []);

  if (!settings) return <div class="app" />;

  const changeTheme = (theme: Settings['theme']) => {
    applyTheme(theme);
    setLocal({ ...settings, theme });
    void setSettings({ theme });
  };
  const changeLang = (l: string) => {
    setLang(l);
    setLangState(renderedLang());
    setLocal({ ...settings, lang: l });
    void setSettings({ lang: l });
  };

  const tokens = state.kind === 'ready' ? state.tokens : null;
  const subtitle = tokens
    ? t('header.subtitle', {
      site: tokens.site,
      counts: t('header.counts', {
        colours: plural('header.colours', tokens.colours.length),
        fonts: plural('header.fonts', tokens.fonts.length),
      }),
    })
    : undefined;

  return (
    <div class="app">
      <Header
        subtitle={subtitle}
        theme={settings.theme}
        lang={lang}
        busy={state.kind === 'scanning'}
        onTheme={changeTheme}
        onLang={changeLang}
        onRescan={scan}
        onOpenFull={() => openPanel('system')}
        onTogglePro={() => {
          setLocal({ ...settings, pro: !settings.pro });
          void setSettings({ pro: !settings.pro });
        }}
      />
      {panelError && <p class="note" role="status"><Icon name="alert" size={14} /><span>{t('popup.openFailed')}</span></p>}
      {state.kind === 'scanning' && (
        <div class="status" role="status">
          <span class="spinner" aria-hidden="true" />
          <span>{t('popup.scanning')}</span>
        </div>
      )}
      {state.kind === 'error' && state.reason === 'restricted' && (
        <EmptyState alert icon="lock" title={t('popup.restrictedTitle')} body={t('popup.restrictedBody')} />
      )}
      {state.kind === 'error' && state.reason === 'no-access' && (
        <EmptyState alert icon="info" title={t('popup.noAccess')} />
      )}
      {state.kind === 'error' && state.reason === 'failed' && (
        <EmptyState alert icon="alert" title={t('popup.failedTitle')} body={t('popup.failedBody')}>
          <button type="button" class="btn" onClick={scan}>
            <Icon name="rescan" size={14} />
            {t('popup.retry')}
          </button>
        </EmptyState>
      )}
      {tokens && (
        <>
          <Tabs
            tabs={TAB_IDS.map((id) => ({ id, label: t(`tabs.${id}`) }))}
            active={tab}
            label={t('tabs.label')}
            onChange={setTab}
          >
            {tab === 'colours' && <ColoursTab colours={tokens.colours} />}
            {tab === 'type' && <TypeTab fonts={tokens.fonts} type={tokens.type} />}
            {tab === 'spacing' && <SpacingTab spacing={tokens.spacing} radius={tokens.radius} shadows={tokens.shadows} />}
            {tab === 'contrast' && <ContrastTab contrast={tokens.contrast} pro={settings.pro} onUnlock={() => openPanel('unlock')} />}
          </Tabs>
          <footer class="footer">
            <button
              type="button"
              class="btn"
              disabled={tokens.colours.length === 0}
              onClick={() => copy('list', orderedColours(tokens.colours).map((c) => c.hex).join('\n'))}
            >
              <Icon name={copied === 'list' ? 'check' : failed === 'list' ? 'alert' : 'copy'} />
              <span aria-live="polite">
                {copied === 'list' ? t('colours.copied') : failed === 'list' ? t('colours.copyFailed') : t('popup.copyHexList')}
              </span>
            </button>
            <button type="button" class="btn btn-primary" onClick={() => (onExport ?? (() => openPanel('export')))(tokens, settings)}>
              {t('popup.exportTokens')}
              {!settings.pro && <span class="badge">{t('popup.pro')}</span>}
            </button>
          </footer>
        </>
      )}
    </div>
  );
}
