import { useEffect, useMemo, useRef, useState } from 'preact/hooks';
import { exportFile, type ExportEdits, type Part } from '../../export';
import type { Tokens } from '../../model/tokens';
import { capture, cleanUrl, connectUrl, directoryMe, upload, uploadForm, type Shot, type Style } from '../../share';
import { getSettings, onSettingsChange, setSettings } from '../../store';
import { EmptyState } from '../components/EmptyState';
import { Icon } from '../components/Icon';
import { t } from '../i18n';

const ALL_PARTS: Part[] = ['colours', 'typography', 'spacing', 'radius', 'shadows'];
const ERRORS = ['offline', 'navigated', 'other_tab', 'restricted', 'rate_limited', 'too_large', 'token_revoked', 'blocked'];
const kb = (n: number) => Math.max(1, Math.round(n / 1024));

export interface ShareViewProps {
  tokens: Tokens;
  layout?: string[];
  edits?: ExportEdits;
  onBack(): void;
}

/** Share to directory (mockups E2 to E7): connect, capture, review, upload. */
export function ShareView({ tokens, layout, edits, onBack }: ShareViewProps) {
  const [token, setToken] = useState<string | null>(null);
  const [waiting, setWaiting] = useState(false);
  const [busy, setBusy] = useState<'capture' | 'upload' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [blocked, setBlocked] = useState(false);
  const [shot, setShot] = useState<Shot | null>(null);
  const [styles, setStyles] = useState<Style[]>([]);
  const [title, setTitle] = useState(tokens.page?.title || tokens.site);
  const [style, setStyle] = useState('');
  const [figma, setFigma] = useState(true);
  const [consent, setConsent] = useState(false);
  const [sent, setSent] = useState<[number, number]>([0, 0]);
  const [shared, setShared] = useState<string | null>(null);
  const tab = useRef<number | undefined>(undefined);
  const abort = useRef<AbortController | undefined>(undefined);
  const url = cleanUrl(tokens.page?.url ?? `https://${tokens.site}/`);
  const site = { site: tokens.site };

  useEffect(() => {
    let live = true;
    void getSettings().then((s) => live && setToken(s.dirToken ?? ''));
    // The tab the user opened the panel on; opening the connect tab makes another tab active.
    void chrome.tabs.query({ active: true, currentWindow: true }).then(([x]) => {
      if (x?.url && new URL(x.url).hostname === tokens.site) tab.current = x.id;
    }, () => {});
    const stop = onSettingsChange((s) => setToken(s.dirToken ?? ''));
    return () => { live = false; stop(); abort.current?.abort(); };
  }, []);

  useEffect(() => {
    if (!token) return;
    setWaiting(false);
    directoryMe(token).then((me) => {
      setStyles(me.styles);
      setBlocked(me.status !== 'active');
    }, (e: Error) => fail(e.message));
  }, [token]);

  const preview = useMemo(() => (shot ? URL.createObjectURL(shot.blob) : ''), [shot]);
  useEffect(() => () => { if (preview) URL.revokeObjectURL(preview); }, [preview]);

  const fail = (code: string) => {
    if (code === 'token_revoked') void setSettings({ dirToken: '' });
    if (code === 'blocked') setBlocked(true);
    setError(code);
  };

  const connect = () => {
    setError(null);
    setWaiting(true);
    void chrome.tabs.create({ url: connectUrl(chrome.runtime.id) });
  };

  const takeShot = async () => {
    setBusy('capture');
    setError(null);
    try {
      setShot(await capture(tokens.site, tab.current));
    } catch (e) {
      fail((e as Error).message);
    } finally {
      setBusy(null);
    }
  };

  const share = async () => {
    if (!token || !shot) return;
    const o = { parts: ALL_PARTS, layout, edits };
    const form = uploadForm({
      url,
      title: title.trim(),
      style,
      tokens: exportFile(tokens, 'json', o).text,
      figma: figma ? exportFile(tokens, 'figma', o).text : undefined,
      shot: shot.blob,
    });
    abort.current = new AbortController();
    setBusy('upload');
    setError(null);
    setSent([0, 0]);
    try {
      setShared((await upload(token, form, (a, b) => setSent([a, b]), abort.current.signal)).url);
    } catch (e) {
      if ((e as Error).message !== 'aborted') fail((e as Error).message);
    } finally {
      setBusy(null);
    }
  };

  const step = !token || shared || busy === 'upload' ? null : shot ? 2 : 1;
  const canShare = consent && !!style && !!title.trim() && !blocked;
  const shown = error ?? (blocked && token ? 'blocked' : null);
  const errorText = shown && t(`share.errors.${ERRORS.includes(shown) ? shown : 'failed'}`, site);

  let body;
  if (token === null) body = null;
  else if (!token) {
    body = waiting ? (
      <div class="status" role="status">
        <span class="spinner" aria-hidden="true" />
        <b>{t('share.waitingTitle')}</b>
        <span>{t('share.waitingBody')}</span>
        <button type="button" class="btn btn-small" onClick={() => setWaiting(false)}>{t('share.cancel')}</button>
      </div>
    ) : (
      <EmptyState icon="key" title={t('share.connectTitle')} body={t('share.connectBody')}>
        <button type="button" class="btn btn-primary" onClick={connect}>{t('share.connect')}</button>
      </EmptyState>
    );
  } else if (shared) {
    body = (
      <EmptyState icon="check" title={t('share.sharedTitle')} body={t('share.sharedBody', { name: title.trim() })}>
        <button type="button" class="btn" onClick={() => void chrome.tabs.create({ url: shared })}>
          <Icon name="external" size={14} />{t('share.openDirectory')}
        </button>
      </EmptyState>
    );
  } else if (busy === 'upload') {
    body = (
      <div class="status" role="status">
        <b>{t('share.uploading')}</b>
        <span class="share-bar"><i style={{ inlineSize: `${sent[1] ? (100 * sent[0]) / sent[1] : 0}%` }} /></span>
        <span>{t('share.progress', { sent: kb(sent[0]), total: kb(sent[1]) })}</span>
        <button type="button" class="btn btn-small" onClick={() => abort.current?.abort()}>{t('share.cancel')}</button>
      </div>
    );
  } else if (busy === 'capture') {
    body = (
      <div class="status" role="status">
        <span class="spinner" aria-hidden="true" />
        <b>{t('share.capturing')}</b>
        <span>{t('share.capturingBody')}</span>
      </div>
    );
  } else if (!shot) {
    body = (
      <EmptyState icon="layers" title={t('share.shotTitle')} body={t('share.shotBody', site)}>
        <button type="button" class="btn btn-primary" onClick={() => void takeShot()}>
          {error ? t('share.tryAgain') : t('share.takeShot')}
        </button>
      </EmptyState>
    );
  } else {
    body = (
      <>
        <figure class="share-shot">
          <img src={preview} alt={t('share.shotAlt', site)} />
          <figcaption>{`${shot.width} × ${shot.height} · ${kb(shot.blob.size)} KB`}</figcaption>
        </figure>
        <button type="button" class="btn btn-small" onClick={() => void takeShot()}>{t('share.retake')}</button>
        <label class="share-fld">
          <span>{t('share.name')}</span>
          <input class="key-input" value={title} maxLength={120} onInput={(e) => setTitle(e.currentTarget.value)} />
        </label>
        <fieldset class="share-fld">
          <legend>{t('share.style')}</legend>
          <div class="chip-row" role="radiogroup">
            {styles.map((s) => (
              <label class="part" key={s.slug}>
                <input type="radio" name="sdt-style" value={s.slug} checked={style === s.slug} onChange={() => setStyle(s.slug)} />
                <span>{s.name}</span>
              </label>
            ))}
          </div>
          <span class="sp-caption">{t('share.styleHint')}</span>
        </fieldset>
        <label class="share-fld">
          <span>{t('share.website')}</span>
          <input class="key-input" value={url} readOnly />
        </label>
        <fieldset class="share-fld">
          <legend>{t('share.files')}</legend>
          <div class="chip-row">
            <label class="part"><input type="checkbox" checked disabled /><span>tokens.json</span></label>
            <label class="part"><input type="checkbox" checked={figma} onChange={() => setFigma(!figma)} /><span>{t('share.fileFigma')}</span></label>
            <label class="part"><input type="checkbox" checked disabled /><span>{t('share.fileScreenshot')}</span></label>
          </div>
        </fieldset>
        <label class="share-check">
          <input type="checkbox" checked={consent} onChange={() => setConsent(!consent)} />
          <span>{t('share.consent')}</span>
        </label>
      </>
    );
  }

  return (
    <div class="sp export">
      <header class="export-header">
        <button type="button" class="icon-btn" aria-label={t('share.back')} onClick={onBack}>
          <Icon name="back" />
        </button>
        <h1 class="export-title">{t('share.title')}</h1>
        {step && <span class="share-step">{t('share.step', { n: step })}</span>}
      </header>
      <div class="export-body">
        {errorText && (
          <p class="note" role="alert">
            <Icon name="alert" size={14} />
            <span>{errorText}</span>
          </p>
        )}
        {body}
      </div>
      {token && shot && !shared && busy !== 'upload' && (
        <footer class="export-footer">
          <button type="button" class="ex-btn ex-btn-primary" disabled={!canShare} onClick={() => void share()}>
            {error ? t('share.tryAgain') : t('share.sharePublicly')}
          </button>
        </footer>
      )}
      {shared && (
        <footer class="export-footer">
          <button type="button" class="ex-btn" onClick={onBack}>{t('share.back')}</button>
        </footer>
      )}
    </div>
  );
}
