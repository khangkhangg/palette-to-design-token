import type { Settings } from '../store';

const QUERY = '(prefers-color-scheme: dark)';
let stopFollowing: (() => void) | null = null;

/** Sets data-theme="light"|"dark" on <html>; 'system' tracks prefers-color-scheme live. */
export function applyTheme(pref: Settings['theme']): void {
  stopFollowing?.();
  stopFollowing = null;
  const root = document.documentElement;
  if (pref !== 'system') {
    root.dataset.theme = pref;
    return;
  }
  const mq = typeof matchMedia === 'function' ? matchMedia(QUERY) : null;
  const sync = () => { root.dataset.theme = mq?.matches ? 'dark' : 'light'; };
  sync();
  if (mq) {
    mq.addEventListener('change', sync);
    stopFollowing = () => mq.removeEventListener('change', sync);
  }
}
