import { useEffect, useRef, useState } from 'preact/hooks';
import { activate, checkoutUrl, lostKeyUrl } from '../../license';
import { renderedLang, t } from '../i18n';
import { Icon } from './Icon';

const PERKS = ['export', 'designMd', 'system', 'contrast', 'scans'] as const;
const FOCUSABLE = 'button:not([disabled]), input:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])';

export interface UnlockSheetProps {
  /** `overlay` is a modal sheet over the current screen; `page` fills the panel with a Back button. */
  variant: 'overlay' | 'page';
  onClose(): void;
  /** Called shortly after a key activates; defaults to `onClose`. */
  onUnlocked?(): void;
}

const UNLOCKED_MS = 900;
const ERROR_KEY = {
  invalid: 'unlock.errInvalid',
  revoked: 'unlock.errRevoked',
  network: 'unlock.errNetwork',
  rate_limited: 'unlock.errRateLimited',
  ended: 'unlock.errEnded',
} as const;

function Content({ onDone }: { onDone(): void }) {
  const [key, setKey] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<keyof typeof ERROR_KEY | null>(null);
  const [done, setDone] = useState(false);
  const doneRef = useRef(onDone);
  doneRef.current = onDone;
  useEffect(() => {
    if (!done) return;
    const id = setTimeout(() => doneRef.current(), UNLOCKED_MS);
    return () => clearTimeout(id);
  }, [done]);

  const submit = async (e: Event) => {
    e.preventDefault();
    if (busy || done) return;
    setBusy(true);
    setError(null);
    const r = await activate(key).catch(() => ({ ok: false as const, reason: 'network' as const }));
    setBusy(false);
    if (r.ok) setDone(true);
    else setError(r.reason);
  };

  return (
    <>
      <div class="unlock-hero">
        <div class="unlock-mark" aria-hidden="true" />
        <h2 class="unlock-title" id="unlock-title">{t('unlock.title')}</h2>
        <div class="unlock-price">
          <span class="unlock-amount">{t('unlock.price')}</span>
          <span class="unlock-note">{t('unlock.priceNote')}</span>
        </div>
      </div>
      <ul class="perks">
        {PERKS.map((p) => (
          <li class="perk" key={p}>
            <span class="perk-tick"><Icon name="check" size={12} /></span>
            <span>
              <strong>{t(`unlock.perks.${p}.title`)}</strong>{' '}
              <span class="perk-text">{t(`unlock.perks.${p}.text`)}</span>
            </span>
          </li>
        ))}
      </ul>
      <div class="unlock-actions">
        <button type="button" class="paypal" onClick={() => { void chrome.tabs.create({ url: checkoutUrl(renderedLang()) }); }}>
          <span>{t('unlock.paypal')}</span>
        </button>
        <div class="divider"><span>{t('unlock.alreadyPaid')}</span></div>
        <form class="key-row" onSubmit={submit}>
          <label class="key-field">
            <span class="sr-only">{t('unlock.keyLabel')}</span>
            <input
              class="key-input"
              type="text"
              value={key}
              disabled={busy || done}
              placeholder={t('unlock.keyPlaceholder')}
              autocomplete="off"
              spellcheck={false}
              aria-invalid={error ? 'true' : undefined}
              aria-describedby={error ? 'key-status' : undefined}
              onInput={(e) => { setKey((e.target as HTMLInputElement).value); if (error) setError(null); }}
            />
          </label>
          <button type="submit" class="key-activate" disabled={busy || done || key.trim() === ''}>
            {busy ? t('unlock.activating') : t('unlock.activate')}
          </button>
        </form>
        <div id="key-status" class={done ? 'key-ok' : 'key-error'} role="status">
          {done ? t('unlock.unlocked') : error ? t(ERROR_KEY[error]) : null}
        </div>
        <a class="lost-key" href={lostKeyUrl(renderedLang())} target="_blank" rel="noopener noreferrer">{t('unlock.lostKey')}</a>
        <p class="refund">{t('unlock.refund')}</p>
      </div>
    </>
  );
}

export function UnlockSheet({ variant, onClose, onUnlocked }: UnlockSheetProps) {
  const dialog = useRef<HTMLDivElement>(null);
  const closeBtn = useRef<HTMLButtonElement>(null);
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;

  useEffect(() => {
    if (variant !== 'overlay') return;
    const opener = document.activeElement as HTMLElement | null;
    closeBtn.current?.focus();
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        onCloseRef.current();
        return;
      }
      if (e.key !== 'Tab' || !dialog.current) return;
      const items = [...dialog.current.querySelectorAll<HTMLElement>(FOCUSABLE)];
      if (items.length === 0) return;
      const first = items[0];
      const last = items[items.length - 1];
      const active = document.activeElement;
      if (!dialog.current.contains(active)) { e.preventDefault(); first.focus(); }
      else if (e.shiftKey && active === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && active === last) { e.preventDefault(); first.focus(); }
    };
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('keydown', onKey);
      opener?.focus();
    };
  }, [variant]);

  if (variant === 'page') {
    return (
      <div class="unlock-page">
        <div class="unlock-bar">
          <button type="button" class="icon-btn" aria-label={t('panel.back')} onClick={onClose}>
            <Icon name="back" />
          </button>
        </div>
        <Content onDone={onUnlocked ?? onClose} />
      </div>
    );
  }

  return (
    <div
      class="sheet-backdrop"
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div
        class="sheet"
        ref={dialog}
        role="dialog"
        aria-modal="true"
        aria-labelledby="unlock-title"
      >
        <button ref={closeBtn} type="button" class="icon-btn sheet-close" aria-label={t('unlock.close')} onClick={onClose}>
          <Icon name="close" />
        </button>
        <Content onDone={onUnlocked ?? onClose} />
      </div>
    </div>
  );
}
