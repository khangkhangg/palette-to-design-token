import { useEffect, useRef, useState } from 'preact/hooks';
import type { Settings } from '../../store';
import { AVAILABLE_LANGS, LANG_NAMES, formatDateTime, t } from '../i18n';
import { Icon } from './Icon';

const THEMES: { value: Settings['theme']; key: string }[] = [
  { value: 'light', key: 'settings.themeLight' },
  { value: 'dark', key: 'settings.themeDark' },
  { value: 'system', key: 'settings.themeSystem' },
];
/** Development builds only, so not translated: the literal is compiled out of production bundles (checked by npm run e2e). */
const DEV_PRO_LABEL = 'Dev: toggle Pro';

export interface HeaderProps {
  subtitle?: string;
  theme: Settings['theme'];
  lang: string;
  busy: boolean;
  onTheme(theme: Settings['theme']): void;
  onLang(lang: string): void;
  onRescan(): void;
  onOpenFull?(): void;
  /** Development builds only: flips Pro for testing the gate. */
  onTogglePro?(): void;
  /** Pro status and the stored licence key, for the licence section of the menu. */
  pro?: boolean;
  licenseKey?: string | null;
  /** Shown to free users: opens the side panel's unlock view. */
  onEnterKey?(): void;
  /** Shown to Pro users, behind an in-menu confirm step; says which licence to remove. */
  onRemoveKey?(plan: LicensePlan): void;
  /** The Pro + AI licence stored in this browser: active, or ended (kept so it can be renewed). */
  ai?: AiMenuInfo | null;
  /** Active Pro + AI: opens the account page. */
  onManage?(): void;
  /** Ended Pro + AI: opens checkout for the plan. */
  onRenew?(): void;
}

export type LicensePlan = 'pro' | 'ai';
export interface AiMenuInfo {
  active: boolean;
  /** ISO date the paid period ends (or ended). */
  paidThrough: string | null;
  /** The subscription is cancelled: access runs until paidThrough. */
  cancelled: boolean;
  /** Still active, but the renewal has not reached us yet: say so and never offer Renew (ruling A19). */
  renewalPending?: boolean;
}

/** "SDT-…-6789": enough to tell keys apart without showing the key. */
export function maskKey(key: string): string {
  return `SDT-…-${key.slice(-4)}`;
}

const MARK = '';

/** "Pro active · {key}" with the key isolated left-to-right, so RTL text does not reorder it. */
function ProActiveKey({ keyText }: { keyText: string }) {
  const [before, after = ''] = t('settings.proActiveKey', { key: MARK }).split(MARK);
  return <>{before}<bdi dir="ltr">{keyText}</bdi>{after}</>;
}

/** The Pro + AI status line, with its date isolated left-to-right. */
function AiStatus({ ai }: { ai: AiMenuInfo }) {
  if (ai.renewalPending) return <>{t('ai.renewalPending')}</>;
  const id = !ai.active ? 'settings.aiEnded' : ai.cancelled ? 'settings.aiEnds' : 'settings.aiRenews';
  const [before, after = ''] = t(id, { date: MARK }).split(MARK);
  return <>{before}<bdi dir="ltr">{ai.paidThrough ? formatDateTime(ai.paidThrough) : ''}</bdi>{after}</>;
}

export function Header({
  subtitle, theme, lang, busy, onTheme, onLang, onRescan, onOpenFull, onTogglePro, pro, licenseKey, onEnterKey, onRemoveKey,
  ai, onManage, onRenew,
}: HeaderProps) {
  const [open, setOpen] = useState(false);
  const [confirming, setConfirming] = useState<LicensePlan | null>(null);
  useEffect(() => { if (!open) setConfirming(null); }, [open]);
  const cancelBtn = useRef<HTMLButtonElement>(null);
  const removeBtns = useRef<Partial<Record<LicensePlan, HTMLButtonElement | null>>>({});
  /** Set by Cancel: focus goes back to that remove button once it is rendered again. */
  const backToRemove = useRef<LicensePlan | null>(null);
  useEffect(() => {
    if (confirming) cancelBtn.current?.focus();
    else if (backToRemove.current) {
      removeBtns.current[backToRemove.current]?.focus();
      backToRemove.current = null;
    }
  }, [confirming]);
  const aiActive = !!ai?.active;
  /** Which licences "remove" can act on: those shown. A Pro flag with no stored key (dev toggle) still offers Pro. */
  const plans: LicensePlan[] = [...(licenseKey ? ['pro' as const] : []), ...(ai ? ['ai' as const] : [])];
  if (plans.length === 0 && pro) plans.push('pro');
  const removeLabel = (p: LicensePlan) => (plans.length > 1 ? t(p === 'ai' ? 'settings.removeAiKey' : 'settings.removeProKey') : t('settings.removeKey'));
  const wrap = useRef<HTMLDivElement>(null);
  const trigger = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!open) return;
    const onDown = (e: PointerEvent) => {
      if (!wrap.current?.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') { setOpen(false); trigger.current?.focus(); }
    };
    document.addEventListener('pointerdown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('pointerdown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <header class="header">
      <div class="mark" aria-hidden="true" />
      <div class="header-text">
        <span class="header-name">{t('app.name')}</span>
        {subtitle && <span class="header-sub">{subtitle}</span>}
      </div>
      <div class="menu-wrap" ref={wrap}>
        <button
          ref={trigger}
          type="button"
          class="icon-btn"
          aria-label={t('header.settings')}
          aria-haspopup="true"
          aria-expanded={open}
          aria-controls="settings-menu"
          onClick={() => setOpen((o) => !o)}
        >
          <Icon name="settings" />
        </button>
        {open && (
          <div id="settings-menu" class="menu" role="group" aria-label={t('header.settings')}>
            <fieldset class="menu-group">
              <legend class="menu-label">{t('settings.theme')}</legend>
              <div class="seg">
                {THEMES.map((o) => (
                  <label class="seg-opt" key={o.value}>
                    <input
                      type="radio"
                      name="sdt-theme"
                      value={o.value}
                      checked={theme === o.value}
                      onChange={() => onTheme(o.value)}
                    />
                    <span>{t(o.key)}</span>
                  </label>
                ))}
              </div>
            </fieldset>
            <fieldset class="menu-group">
              <legend class="menu-label">{t('settings.language')}</legend>
              <div class="menu-list">
                {AVAILABLE_LANGS.map((code) => (
                  <label class="menu-opt" key={code}>
                    <input
                      type="radio"
                      name="sdt-lang"
                      value={code}
                      checked={lang === code}
                      onChange={() => onLang(code)}
                    />
                    <span lang={code}>{LANG_NAMES[code]}</span>
                    <span class="menu-check"><Icon name="check" size={14} /></span>
                  </label>
                ))}
              </div>
            </fieldset>
            {!pro && onEnterKey && (
              <button
                type="button"
                class="menu-action"
                onClick={() => { setOpen(false); onEnterKey(); }}
              >
                <Icon name="key" size={14} />
                <span>{t('settings.enterKey')}</span>
              </button>
            )}
            {(pro || ai) && (
              <div class="menu-license">
                {(licenseKey || (pro && !aiActive)) && (
                  <p class="menu-pro">
                    <Icon name="check" size={14} />
                    <span>{licenseKey ? <ProActiveKey keyText={maskKey(licenseKey)} /> : t('settings.proActive')}</span>
                  </p>
                )}
                {ai && (
                  <p class={ai.active ? 'menu-pro' : 'menu-pro menu-pro-ended'}>
                    <Icon name={ai.renewalPending ? 'info' : ai.active ? 'check' : 'alert'} size={14} />
                    <span><AiStatus ai={ai} /></span>
                  </p>
                )}
                {ai?.active && onManage && (
                  <button type="button" class="menu-action" onClick={() => { setOpen(false); onManage(); }}>
                    {t('settings.manageSubscription')}
                  </button>
                )}
                {ai && !ai.active && !ai.renewalPending && onRenew && (
                  <button type="button" class="menu-action" onClick={() => { setOpen(false); onRenew(); }}>
                    {t('settings.renew')}
                  </button>
                )}
                {onRemoveKey && !confirming && plans.map((p) => (
                  <button
                    key={p}
                    ref={(el) => { removeBtns.current[p] = el; }}
                    type="button"
                    class="menu-action menu-action-quiet"
                    onClick={() => setConfirming(p)}
                  >
                    {removeLabel(p)}
                  </button>
                ))}
                {onRemoveKey && confirming && (
                  <div class="menu-confirm" role="group" aria-label={removeLabel(confirming)}>
                    <p class="menu-confirm-text">{t(confirming === 'ai' ? 'settings.removeConfirmAi' : 'settings.removeConfirm')}</p>
                    <div class="menu-confirm-actions">
                      <button
                        ref={cancelBtn}
                        type="button"
                        class="btn btn-small"
                        onClick={() => { backToRemove.current = confirming; setConfirming(null); }}
                      >
                        {t('settings.cancel')}
                      </button>
                      <button
                        type="button"
                        class="btn btn-small btn-danger"
                        onClick={() => { setConfirming(null); onRemoveKey(confirming); }}
                      >
                        {t('settings.removeConfirmYes')}
                      </button>
                    </div>
                  </div>
                )}
              </div>
            )}
            {(import.meta.env.DEV || import.meta.env.MODE === 'development') && onTogglePro && (
              <button type="button" class="menu-dev" onClick={onTogglePro}>{DEV_PRO_LABEL}</button>
            )}
          </div>
        )}
      </div>
      {onOpenFull && (
        <button type="button" class="icon-btn" aria-label={t('popup.openFullView')} onClick={onOpenFull}>
          <Icon name="panel" />
        </button>
      )}
      <button
        type="button"
        class="icon-btn"
        aria-label={t('header.rescan')}
        disabled={busy}
        onClick={onRescan}
      >
        <span class={busy ? 'spin' : undefined}><Icon name="rescan" /></span>
      </button>
    </header>
  );
}
