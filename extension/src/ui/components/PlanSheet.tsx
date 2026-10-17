import { useEffect, useRef, useState } from 'preact/hooks';
import { checkoutUrl } from '../../license';
import { renderedLang, t } from '../i18n';
import { Icon } from './Icon';
import { KeyEntry } from './KeyEntry';

export type Plan = 'ai' | 'pro';
const FOCUSABLE = 'button:not([disabled]), input:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])';
const ITEMS: Record<Plan, string[]> = {
  ai: ['pro', 'analyses', 'search', 'verdict', 'cancel'],
  pro: ['exports', 'contrast', 'scans'],
};

export interface PlanSheetProps {
  /** `overlay` is a modal sheet over the current screen; `page` fills the panel with a Back button. */
  variant: 'overlay' | 'page';
  /** The plan selected first: Pro + AI from the AI section, Pro from Export. */
  plan?: Plan;
  /** `key` opens straight on the license key form ("Have a key?"). */
  start?: 'plans' | 'key';
  onClose(): void;
  /** Called shortly after a key activates; defaults to `onClose`. */
  onUnlocked?(): void;
}

function PlanCard({ plan, on, onPick }: { plan: Plan; on: boolean; onPick(): void }) {
  return (
    <label class={on ? 'plan-card is-on' : 'plan-card'}>
      <input class="sr-only" type="radio" name="sdt-plan" value={plan} checked={on} onChange={onPick} />
      <span class="plan-top">
        <span class="plan-name">
          {t(`plans.${plan}.name`)}
          {plan === 'ai' && <span class="plan-best">{t('plans.best')}</span>}
        </span>
        <span class="plan-price">
          {t(`plans.${plan}.price`)}
          <span class="plan-per">{t(`plans.${plan}.per`)}</span>
        </span>
      </span>
      <ul class="plan-items">
        {ITEMS[plan].map((item) => (
          <li key={item}><Icon name="check" size={14} /><span>{t(`plans.${plan}.items.${item}`)}</span></li>
        ))}
      </ul>
    </label>
  );
}

/** Mockup J: the Pro + AI and Pro plans, PayPal checkout in a new tab, and the license key form for buyers. */
export function PlanSheet({ variant, plan: initial = 'pro', start = 'plans', onClose, onUnlocked }: PlanSheetProps) {
  const [plan, setPlan] = useState<Plan>(initial);
  const [mode, setMode] = useState<'plans' | 'key'>(start);
  const dialog = useRef<HTMLDivElement>(null);
  const closeBtn = useRef<HTMLButtonElement>(null);
  const keyBody = useRef<HTMLDivElement>(null);
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

  // "Enter it" replaces the button that had focus: move it to the key field.
  useEffect(() => {
    if (mode === 'key' && start === 'plans') keyBody.current?.querySelector<HTMLInputElement>('.key-input')?.focus();
  }, [mode, start]);

  // Back from the key form returns to the plans when the sheet started there; otherwise it leaves.
  const back = mode === 'key' && start === 'plans' ? () => setMode('plans') : variant === 'page' ? onClose : null;
  const backLabel = mode === 'key' && start === 'plans' ? t('plans.back') : t('panel.back');

  const content = (
    <>
      <div class="plan-head">
        {back && (
          <button type="button" class="icon-btn" aria-label={backLabel} onClick={back}>
            <Icon name="back" />
          </button>
        )}
        <h2 class="plan-title" id="plan-title">{mode === 'key' ? t('plans.keyTitle') : t('plans.title')}</h2>
        {variant === 'overlay' && (
          <button ref={closeBtn} type="button" class="icon-btn plan-close" aria-label={t('unlock.close')} onClick={onClose}>
            <Icon name="close" />
          </button>
        )}
      </div>
      {mode === 'plans' ? (
        <div class="plan-body">
          <div class="plan-cards" role="radiogroup" aria-labelledby="plan-title">
            {(['ai', 'pro'] as const).map((p) => <PlanCard key={p} plan={p} on={plan === p} onPick={() => setPlan(p)} />)}
          </div>
          <div class="plan-actions">
            <button
              type="button"
              class="paypal"
              onClick={() => { void chrome.tabs.create({ url: checkoutUrl(renderedLang(), plan) }); }}
            >
              <span>{t(plan === 'ai' ? 'plans.subscribe' : 'plans.payPro')}</span>
              <Icon name="external" />
            </button>
            <p class="plan-note">{t(plan === 'ai' ? 'plans.aiNote' : 'unlock.refund')}</p>
            <p class="plan-note">
              {t('plans.haveKey')}{' '}
              <button type="button" class="link-btn plan-key-link" onClick={() => setMode('key')}>{t('plans.enterIt')}</button>
            </p>
          </div>
        </div>
      ) : (
        <div class="plan-body" ref={keyBody}>
          <p class="plan-lead">{t('plans.keyText')}</p>
          <KeyEntry onDone={onUnlocked ?? onClose} />
        </div>
      )}
    </>
  );

  if (variant === 'page') return <div class="unlock-page plan-page">{content}</div>;
  return (
    <div class="sheet-backdrop" onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}>
      <div class="sheet plan-sheet" ref={dialog} role="dialog" aria-modal="true" aria-labelledby="plan-title">
        {content}
      </div>
    </div>
  );
}
