import { useEffect, useRef } from 'preact/hooks';
import { t } from '../i18n';
import { Icon } from './Icon';

const PERKS = ['export', 'designMd', 'system', 'contrast', 'scans'] as const;
const FOCUSABLE = 'button:not([disabled]), input:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])';

export interface UnlockSheetProps {
  /** `overlay` is a modal sheet over the current screen; `page` fills the panel with a Back button. */
  variant: 'overlay' | 'page';
  onClose(): void;
}

function Content() {
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
        <button type="button" class="paypal" disabled>
          <span>{t('unlock.paypal')}</span>
          <span class="paypal-soon">{t('unlock.comingSoon')}</span>
        </button>
        <div class="divider"><span>{t('unlock.alreadyPaid')}</span></div>
        <div class="key-row">
          <label class="key-field">
            <span class="sr-only">{t('unlock.keyLabel')}</span>
            <input class="key-input" type="text" disabled placeholder={t('unlock.keyPlaceholder')} />
          </label>
          <button type="button" class="key-activate" disabled>{t('unlock.activate')}</button>
        </div>
        <p class="refund">{t('unlock.refund')}</p>
      </div>
    </>
  );
}

export function UnlockSheet({ variant, onClose }: UnlockSheetProps) {
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
        <Content />
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
        <Content />
      </div>
    </div>
  );
}
