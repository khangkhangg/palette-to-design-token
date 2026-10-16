import { useEffect, useRef, useState } from 'preact/hooks';
import type { Settings } from '../../store';
import { AVAILABLE_LANGS, LANG_NAMES, t } from '../i18n';
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
}

export function Header({ subtitle, theme, lang, busy, onTheme, onLang, onRescan, onOpenFull, onTogglePro }: HeaderProps) {
  const [open, setOpen] = useState(false);
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
