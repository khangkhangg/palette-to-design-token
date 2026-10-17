import type { ComponentChildren } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import { analyse, publicPage, siteHost, storedResult, type AiResult, type AnalyseReason, type StoredResult } from '../../ai';
import { API_BASE } from '../../config';
import { aiState, checkoutUrl, currentAiLicense, type AiUsage } from '../../license';
import type { Tokens } from '../../model/tokens';
import { Icon, type IconName } from '../components/Icon';
import { PlanSheet } from '../components/PlanSheet';
import { formatDate, plural, renderedLang, t } from '../i18n';

const NOTE_KEY = 'aiNoteHidden';
const LICENSE_KEY = 'licenseAi';
/** The second progress line ("Asking the AI…") shows after this; the server does not stream its steps. */
const STEP_MS = 5000;
const FEATURES: { id: string; icon: IconName }[] = [
  { id: 'sites', icon: 'search' },
  { id: 'verdict', icon: 'trend' },
  { id: 'colours', icon: 'palette' },
];
const VERDICTS = ['behind', 'on_par', 'competitive'] as const;
const FIT_ICON: Record<AiResult['fit']['level'], IconName> = { strong: 'check', ok: 'info', weak: 'alert' };
/** Site initials sit on one of these, picked by domain: white text passes AA on each, in both themes. */
const LOGO = ['#2E4A3B', '#1D3557', '#6B4E2E', '#B5532A', '#55606E', '#5B3F8C', '#1F6F78'];
const ERROR_KEY: Partial<Record<AnalyseReason, string>> = {
  network: 'ai.errors.network',
  timeout: 'ai.errors.timeout',
  unavailable: 'ai.errors.unavailable',
  rate_limited: 'ai.errors.rateLimited',
  unsupported: 'ai.unsupported',
};

type Plan =
  | { kind: 'loading' }
  | { kind: 'locked' }
  | { kind: 'ended'; endedOn: string | null }
  | { kind: 'active'; usage: AiUsage | null };

async function readNoteHidden(): Promise<boolean> {
  try {
    return (await chrome.storage.local.get(NOTE_KEY))[NOTE_KEY] === true;
  } catch {
    return false;
  }
}

async function loadPlan(): Promise<Plan> {
  const [lic, state] = await Promise.all([currentAiLicense(), aiState()]);
  if (!lic) return { kind: 'locked' };
  if (!state.active) return { kind: 'ended', endedOn: lic.paidThrough };
  return { kind: 'active', usage: state.usage };
}

/** Fills {name} in a translated string with markup, keeping the translation's word order. */
function Rich({ text, parts }: { text: string; parts: Record<string, ComponentChildren> }) {
  const out: ComponentChildren[] = [];
  let last = 0;
  for (const m of text.matchAll(/\{(\w+)\}/g)) {
    out.push(text.slice(last, m.index), m[1] in parts ? parts[m[1]] : m[0]);
    last = m.index! + m[0].length;
  }
  out.push(text.slice(last));
  return <>{out}</>;
}

/** A translated string whose {vars} are isolated left-to-right (hosts, dates, numbers). */
const ltr = (key: string, vars: Record<string, string | number>) => (
  <Rich text={t(key)} parts={Object.fromEntries(Object.entries(vars).map(([k, v]) => [k, <bdi dir="ltr">{v}</bdi>]))} />
);

function Head({ sub, children }: { sub: ComponentChildren; children?: ComponentChildren }) {
  return (
    <div class="ai-head">
      <span class="ai-icon" aria-hidden="true"><Icon name="sparkles" size={18} /></span>
      <div class="ai-head-text">
        <h2 class="ai-title" id="ai-title">{t('ai.title')}</h2>
        <span class="ai-sub">{sub}</span>
      </div>
      {children}
    </div>
  );
}

function UsageBar({ usage }: { usage: AiUsage }) {
  const left = Math.max(0, usage.limit - usage.used);
  const pct = usage.limit > 0 ? Math.round((left / usage.limit) * 100) : 0;
  return (
    <div class="ai-usage">
      <div class="ai-usage-text">
        <span class="ai-usage-left">{ltr('ai.left', { left, limit: usage.limit })}</span>
        <span class="ai-usage-reset">{ltr('ai.resets', { date: formatDate(usage.resetsOn, false) })}</span>
      </div>
      <div class="ai-bar" aria-hidden="true"><div class="ai-bar-fill" style={{ inlineSize: `${pct}%` }} /></div>
    </div>
  );
}

function ErrorNote({ reason, onRetry }: { reason: AnalyseReason; onRetry?(): void }) {
  const key = ERROR_KEY[reason];
  if (!key) return null;
  return (
    <div class="ai-error" role="alert">
      <Icon name="alert" size={14} />
      <span>{t(key)}</span>
      {onRetry && <button type="button" class="link-btn" onClick={onRetry}>{t('ai.retry')}</button>}
    </div>
  );
}

function Locked({ endedOn, ended, onSee }: { ended: boolean; endedOn: string | null; onSee(): void }) {
  return (
    <section class="ai-card" aria-labelledby="ai-title">
      <Head sub={t('ai.lockedSub')} />
      {ended && (
        <p class="ai-ended" role="status">
          <Icon name="alert" size={14} />
          <span>{endedOn ? ltr('ai.ended', { date: formatDate(endedOn) }) : t('ai.endedNoDate')}</span>
        </p>
      )}
      <ul class="ai-features">
        {FEATURES.map((f) => (
          <li key={f.id}>
            <Icon name={f.icon} size={16} />
            <span><strong>{t(`ai.features.${f.id}.title`)}</strong> <span class="ai-muted">{t(`ai.features.${f.id}.text`)}</span></span>
          </li>
        ))}
      </ul>
      <div class="ai-price">
        <span>{t('ai.planLine')}</span>
        <span class="ai-price-amount">{t('plans.ai.price')}<span class="ai-price-per">{t('plans.ai.per')}</span></span>
      </div>
      {ended ? (
        <button type="button" class="ai-cta" onClick={() => { void chrome.tabs.create({ url: checkoutUrl(renderedLang(), 'ai') }); }}>
          {t('ai.renew')}
        </button>
      ) : (
        <button type="button" class="ai-cta" onClick={onSee}>{t('ai.seePlan')}</button>
      )}
      <span class="ai-caption">{t('ai.includes')}</span>
    </section>
  );
}

function Verdict({ result }: { result: AiResult }) {
  const at = VERDICTS.indexOf(result.verdict);
  const label = t(`ai.verdicts.${result.verdict}`);
  return (
    <section class="ai-box" aria-labelledby="ai-verdict">
      <h3 class="ai-h" id="ai-verdict">{t('ai.verdict')}</h3>
      <div class="ai-verdict-line">
        <span class={`ai-verdict ai-v-${result.verdict}`}>{label}</span>
        <span class="ai-muted">{t('ai.forSector')}</span>
      </div>
      <div class="ai-scale" role="img" aria-label={t('ai.scale', { verdict: label })}>
        {VERDICTS.map((v, i) => <span key={v} class={i === at ? `ai-seg is-on ai-v-${v}` : 'ai-seg'} />)}
      </div>
      <div class="ai-scale-labels" aria-hidden="true">
        {VERDICTS.map((v, i) => <span key={v} class={i === at ? 'is-on' : undefined}>{t(`ai.verdicts.${v}`)}</span>)}
      </div>
      {result.summary && <p class="ai-summary" dir="auto">{result.summary}</p>}
    </section>
  );
}

function Results({ host, entry, usage, busy, quota, onRun }: {
  host: string; entry: StoredResult; usage: AiUsage | null; busy: boolean; quota: boolean; onRun(): void;
}) {
  const { result } = entry;
  const left = usage ? Math.max(0, usage.limit - usage.used) : null;
  const shown = entry.usage ?? usage;
  const tags = [result.sector, ...result.tags].filter((x) => x !== '');
  return (
    <section class="ai-results" aria-labelledby="ai-title">
      <Head sub={<><bdi dir="ltr">{host}</bdi>{left !== null && <> · {plural('ai.leftShort', left)}</>}</>}>
        <button type="button" class="icon-btn" aria-label={t('ai.runAgain')} title={t('ai.runAgain')} disabled={busy || quota} onClick={onRun}>
          <Icon name="rescan" />
        </button>
      </Head>
      {tags.length > 0 && (
        <ul class="ai-tags" aria-label={t('ai.tags')}>
          {tags.map((tag, i) => <li key={i} dir="auto">{tag}</li>)}
        </ul>
      )}
      <Verdict result={result} />
      {(result.colours.length > 0 || result.fit.reason) && (
        <section class="ai-box" aria-labelledby="ai-colours">
          <h3 class="ai-h" id="ai-colours">{t('ai.coloursSay')}</h3>
          {result.colours.map((c) => (
            <div class="ai-meaning" key={c.hex}>
              <span class="ai-chip" style={{ backgroundColor: c.hex }} title={c.hex} aria-hidden="true" />
              <div class="ai-meaning-text">
                <span class="ai-meaning-title" dir="auto">{c.title}</span>
                <span class="ai-meaning-body" dir="auto">{c.meaning}</span>
              </div>
            </div>
          ))}
          <div class={`ai-fit ai-fit-${result.fit.level}`}>
            <Icon name={FIT_ICON[result.fit.level]} size={15} />
            <span>
              <Rich text={t('ai.fitFor')} parts={{ fit: <strong>{t(`ai.fit.${result.fit.level}`)}</strong>, sector: <bdi>{result.sector}</bdi> }} />
              {result.fit.reason && <span class="ai-fit-reason" dir="auto">{result.fit.reason}</span>}
            </span>
          </div>
        </section>
      )}
      {(result.works.length > 0 || result.improve.length > 0) && (
        <section class="ai-box ai-two" aria-label={`${t('ai.works')} · ${t('ai.improve')}`}>
          <div class="ai-col">
            <h3 class="ai-h ai-h-good">{t('ai.works')}</h3>
            <ul class="ai-list">{result.works.map((w, i) => <li key={i} dir="auto">{w}</li>)}</ul>
          </div>
          <div class="ai-col">
            <h3 class="ai-h ai-h-bad">{t('ai.improve')}</h3>
            <ul class="ai-list">{result.improve.map((w, i) => <li key={i} dir="auto">{w}</li>)}</ul>
          </div>
        </section>
      )}
      <section class="ai-box ai-sites" aria-labelledby="ai-sites">
        <h3 class="ai-h" id="ai-sites">{t('ai.sites')}</h3>
        {result.search_unavailable && (
          <p class="ai-search-down"><Icon name="info" size={14} /><span>{t('ai.searchDown')}</span></p>
        )}
        {result.sites.length === 0 && <p class="ai-muted ai-small">{t('ai.noSites')}</p>}
        <ul class="ai-site-list">
          {result.sites.map((s) => (
            <li key={s.domain}>
              <a class="ai-site" href={s.url} target="_blank" rel="noopener noreferrer">
                <span class="ai-logo" style={{ backgroundColor: LOGO[[...s.domain].reduce((n, ch) => n + ch.charCodeAt(0), 0) % LOGO.length] }} aria-hidden="true">
                  {(s.name.trim()[0] ?? s.domain[0]).toUpperCase()}
                </span>
                <span class="ai-site-text">
                  <span class="ai-site-name"><bdi>{s.name}</bdi> <bdi class="ai-site-domain" dir="ltr">{s.domain}</bdi></span>
                  {s.why && <span class="ai-site-why" dir="auto">{s.why}</span>}
                </span>
                <span class={s.source === 'web' ? 'ai-badge ai-badge-web' : 'ai-badge ai-badge-ai'}>
                  {t(s.source === 'web' ? 'ai.sourceWeb' : 'ai.sourceAi')}
                </span>
                <span class="sr-only">{t('ai.newTab')}</span>
              </a>
            </li>
          ))}
        </ul>
        {result.sites.length > 0 && <p class="ai-muted ai-small">{t('ai.sourcesNote')}</p>}
      </section>
      <p class="ai-footer">
        {shown
          ? ltr('ai.footer', { date: formatDate(entry.analysedAt), used: shown.used, limit: shown.limit })
          : ltr('ai.footerNoUsage', { date: formatDate(entry.analysedAt) })}
      </p>
      {entry.cached && <p class="ai-footer">{t('ai.cached')}</p>}
    </section>
  );
}

export interface AiSectionProps {
  tokens: Tokens;
}

/** The AI insights card at the bottom of the side panel (mockups G, H, running, I, out of analyses, ended). */
export function AiSection({ tokens }: AiSectionProps) {
  const host = siteHost(tokens.site);
  const page = {
    url: tokens.page?.url || `https://${tokens.site}/`,
    title: tokens.page?.title ?? '',
    description: tokens.page?.description ?? '',
  };
  const supported = publicPage(page.url) !== null;
  const [plan, setPlan] = useState<Plan>({ kind: 'loading' });
  const [entry, setEntry] = useState<StoredResult | null>(null);
  const [noteHidden, setNoteHidden] = useState<boolean | null>(null);
  const [hideNext, setHideNext] = useState(false);
  const [running, setRunning] = useState<0 | 1 | null>(null);
  const [error, setError] = useState<AnalyseReason | null>(null);
  const [quotaReset, setQuotaReset] = useState<string | null>(null);
  const [sheet, setSheet] = useState(false);
  const ctrl = useRef<AbortController | null>(null);

  const refresh = () => { void loadPlan().then(setPlan).catch(() => setPlan({ kind: 'locked' })); };

  useEffect(() => {
    let live = true;
    void (async () => {
      const [p, stored, note] = await Promise.all([
        loadPlan().catch((): Plan => ({ kind: 'locked' })),
        storedResult(host).catch(() => null),
        readNoteHidden(),
      ]);
      if (!live) return;
      setPlan(p);
      setEntry(stored);
      setNoteHidden(note);
    })();
    const onChange = (changes: Record<string, chrome.storage.StorageChange>, area: string) => {
      if (area === 'local' && changes[LICENSE_KEY]) refresh();
    };
    chrome.storage?.onChanged?.addListener(onChange);
    return () => {
      live = false;
      chrome.storage?.onChanged?.removeListener(onChange);
      ctrl.current?.abort();
      ctrl.current = null;
    };
  }, [host]);

  useEffect(() => {
    if (running !== 0) return;
    const id = setTimeout(() => setRunning((s) => (s === 0 ? 1 : s)), STEP_MS);
    return () => clearTimeout(id);
  }, [running]);

  const usage = plan.kind === 'active' ? plan.usage : null;
  const quota = quotaReset !== null || (usage !== null && usage.used >= usage.limit);
  const resetsOn = quotaReset ?? usage?.resetsOn ?? '';

  const run = async () => {
    if (running !== null) return;
    const c = new AbortController();
    ctrl.current = c;
    setError(null);
    setRunning(0);
    const r = await analyse(tokens, page, renderedLang(), c.signal);
    if (ctrl.current !== c) return;
    ctrl.current = null;
    setRunning(null);
    if (r.ok) {
      setEntry({ result: r.result, usage: r.usage, analysedAt: new Date().toISOString(), lang: renderedLang(), cached: r.cached });
      setPlan({ kind: 'active', usage: r.usage });
      return;
    }
    if (r.reason === 'cancelled') return;
    if (r.reason === 'not_ai') return setPlan({ kind: 'locked' });
    if (r.reason === 'ended') return setPlan({ kind: 'ended', endedOn: r.endedOn ?? null });
    if (r.reason === 'quota') return setQuotaReset(r.resetsOn ?? usage?.resetsOn ?? '');
    setError(r.reason);
  };
  const cancel = () => {
    ctrl.current?.abort();
    ctrl.current = null;
    setRunning(null);
  };

  if (plan.kind === 'loading' || noteHidden === null) return null;

  const sheetEl = sheet && (
    <PlanSheet variant="overlay" plan="ai" onClose={() => { setSheet(false); refresh(); }} />
  );

  if (plan.kind !== 'active') {
    return (
      <>
        <Locked ended={plan.kind === 'ended'} endedOn={plan.kind === 'ended' ? plan.endedOn : null} onSee={() => setSheet(true)} />
        {sheetEl}
      </>
    );
  }

  if (running !== null) {
    return (
      <section class="ai-card" aria-labelledby="ai-title" aria-busy="true">
        <Head sub={<bdi dir="ltr">{host}</bdi>} />
        <div class="ai-running" role="status">
          <span class="spinner" aria-hidden="true" />
          <span class="ai-step">{t(running === 0 ? 'ai.searching' : 'ai.asking')}</span>
          <span class="ai-caption">{t('ai.runningNote')}</span>
        </div>
        <button type="button" class="btn ai-cancel" onClick={cancel}>{t('ai.cancel')}</button>
      </section>
    );
  }

  const quotaNote = quota && (
    <p class="ai-quota" role="status">
      <Icon name="info" size={14} />
      <span>{ltr('ai.quota', { limit: usage?.limit ?? 100, date: formatDate(resetsOn, false) })}</span>
    </p>
  );

  if (entry) {
    return (
      <div class="ai-wrap">
        {error && <ErrorNote reason={error} onRetry={supported && !quota ? run : undefined} />}
        {quotaNote}
        <Results host={host} entry={entry} usage={usage} busy={false} quota={quota || !supported} onRun={run} />
      </div>
    );
  }

  return (
    <section class="ai-card" aria-labelledby="ai-title">
      <Head sub={t('ai.readySub')} />
      {usage && <UsageBar usage={usage} />}
      {!noteHidden && (
        <>
          <div class="ai-sent">
            <span class="ai-sent-title"><Icon name="lock" size={15} />{t('ai.sentTitle')}</span>
            <span class="ai-sent-body">{t('ai.sentBody')}</span>
            <a class="ai-link" href={`${API_BASE}/privacy?lang=${encodeURIComponent(renderedLang())}`} target="_blank" rel="noopener noreferrer">
              {t('ai.privacy')}
            </a>
          </div>
          <label class="ai-check">
            <input
              type="checkbox"
              checked={hideNext}
              onChange={(e) => {
                const on = (e.target as HTMLInputElement).checked;
                setHideNext(on);
                void chrome.storage.local.set({ [NOTE_KEY]: on }).catch(() => {});
              }}
            />
            <span>{t('ai.hideNote')}</span>
          </label>
        </>
      )}
      {!supported && <p class="ai-quota" role="status"><Icon name="info" size={14} /><span>{t('ai.unsupported')}</span></p>}
      {quotaNote}
      {error && <ErrorNote reason={error} onRetry={supported && !quota ? run : undefined} />}
      <button type="button" class="ai-cta" disabled={quota || !supported} onClick={run}>
        <Icon name="sparkle" size={16} />
        <span>{ltr('ai.analyse', { host })}</span>
      </button>
      <span class="ai-caption">{t('ai.usesOne')}</span>
    </section>
  );
}
