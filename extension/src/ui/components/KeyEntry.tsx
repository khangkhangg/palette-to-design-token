import { useEffect, useRef, useState } from 'preact/hooks';
import { activate, lostKeyUrl } from '../../license';
import { renderedLang, t } from '../i18n';

const UNLOCKED_MS = 900;
const ERROR_KEY = {
  invalid: 'unlock.errInvalid',
  revoked: 'unlock.errRevoked',
  network: 'unlock.errNetwork',
  rate_limited: 'unlock.errRateLimited',
  ended: 'unlock.errEnded',
} as const;

/** The license key form: activates a Pro or Pro + AI key, says why one failed, and calls onDone shortly after success. */
export function KeyEntry({ onDone }: { onDone(): void }) {
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
    <div class="key-entry">
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
            dir="ltr"
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
    </div>
  );
}
