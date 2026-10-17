import { unzipSync } from 'fflate';
import { useEffect, useMemo, useState } from 'preact/hooks';
import { exportAll, exportFile, slug, type Format, type Part } from '../../export';
import type { Tokens } from '../../model/tokens';
import { Icon } from '../components/Icon';
import { PlanSheet } from '../components/PlanSheet';
import { download } from '../download';
import { t } from '../i18n';
import { useCopy } from '../popup/ColoursTab';

const FORMATS: Format[] = ['md', 'css', 'tailwind', 'figma', 'json', 'all'];
const PARTS: Part[] = ['colours', 'typography', 'spacing', 'radius', 'shadows'];

interface ZipPreview { text: string }

async function zipPreview(tokens: Tokens, parts: Part[]): Promise<ZipPreview> {
  const zip = await exportAll(tokens, { parts });
  const names = Object.keys(unzipSync(zip.bytes));
  const kb = Math.max(1, Math.round(zip.bytes.length / 1024));
  const list = names.map((n) => `  ${n}`).join('\n');
  return { text: `${zip.filename}\n\n${list}\n\n${t('export.zipSummary', { count: names.length, size: kb })}` };
}

export interface ExportScreenProps {
  tokens: Tokens;
  pro: boolean;
  onBack(): void;
  /** Free users: opens the unlock view to enter a key. */
  onHaveKey?(): void;
}

export function ExportScreen({ tokens, pro, onBack, onHaveKey }: ExportScreenProps) {
  const [format, setFormat] = useState<Format>('md');
  const [tailwind, setTailwind] = useState<3 | 4>(4);
  const [on, setOn] = useState<Part[]>(PARTS);
  const [sheet, setSheet] = useState(false);
  const [zip, setZip] = useState<ZipPreview | null>(null);
  const { copied, failed, copy } = useCopy();

  const parts = useMemo(() => PARTS.filter((p) => on.includes(p)), [on]);
  const file = useMemo(
    () => (format === 'all' ? null : exportFile(tokens, format, { parts, tailwind })),
    [tokens, format, parts, tailwind],
  );
  const filename = file ? file.filename : `${slug(tokens.site)}-tokens.zip`;

  useEffect(() => {
    if (format !== 'all') return;
    let live = true;
    setZip(null);
    void zipPreview(tokens, parts).then((z) => { if (live) setZip(z); });
    return () => { live = false; };
  }, [format, tokens, parts]);

  const togglePart = (p: Part) =>
    setOn((cur) => (cur.includes(p) ? (cur.length > 1 ? cur.filter((x) => x !== p) : cur) : [...cur, p]));

  const onCopy = () => {
    if (!file) return;
    if (!pro) return setSheet(true);
    void copy('export', file.text);
  };
  const onDownload = async () => {
    if (!pro) return setSheet(true);
    if (file) return download(file.filename, file.text, file.mime);
    const zipFile = await exportAll(tokens, { parts });
    download(zipFile.filename, zipFile.bytes, zipFile.mime);
  };

  const previewText = file ? file.text : zip ? zip.text : t('export.zipBuilding');

  return (
    <div class="sp export">
      <header class="export-header">
        <button type="button" class="icon-btn" aria-label={t('panel.back')} onClick={onBack}>
          <Icon name="back" />
        </button>
        <h1 class="export-title">{t('export.title')}</h1>
        {!pro && onHaveKey && (
          <button type="button" class="link-btn" onClick={onHaveKey}>{t('panel.haveKey')}</button>
        )}
        {pro && (
          <span class="pro-badge">
            <Icon name="check" size={12} />
            {t('export.proUnlocked')}
          </span>
        )}
      </header>

      <div class="export-body">
        <section class="sp-section" aria-labelledby="ex-format">
          <h2 class="sp-h" id="ex-format">{t('export.format')}</h2>
          <div class="fmt-grid" role="radiogroup" aria-labelledby="ex-format">
            {FORMATS.map((f) => (
              <label class="fmt" key={f}>
                <input type="radio" name="sdt-format" value={f} checked={format === f} onChange={() => setFormat(f)} />
                <span class="fmt-label">{t(`export.formats.${f}.label`)}</span>
                <span class="fmt-sub">{t(`export.formats.${f}.sub`)}</span>
              </label>
            ))}
          </div>
          {format === 'tailwind' && (
            <div class="seg seg-2" role="radiogroup" aria-label={t('export.tailwindVersion')}>
              {([4, 3] as const).map((v) => (
                <label class="seg-opt" key={v}>
                  <input type="radio" name="sdt-tailwind" value={v} checked={tailwind === v} onChange={() => setTailwind(v)} />
                  <span>{t(`export.v${v}`)}</span>
                </label>
              ))}
            </div>
          )}
        </section>

        <section class="sp-section" aria-labelledby="ex-include">
          <h2 class="sp-h" id="ex-include">{t('export.include')}</h2>
          <div class="chip-row">
            {PARTS.map((p) => {
              const checked = on.includes(p);
              return (
                <label class="part" key={p}>
                  <input
                    type="checkbox"
                    checked={checked}
                    disabled={checked && on.length === 1}
                    onChange={() => togglePart(p)}
                  />
                  <span>{t(`export.parts.${p}`)}</span>
                </label>
              );
            })}
          </div>
        </section>

        <section class="sp-section preview-section" aria-labelledby="ex-preview">
          <div class="preview-head">
            <h2 class="sp-h" id="ex-preview">{t('export.preview')}</h2>
            <span class="preview-file">{filename}</span>
          </div>
          <pre class="code" tabIndex={0} aria-label={t('export.previewLabel', { file: filename })}>{previewText}</pre>
        </section>
      </div>

      <footer class="export-footer">
        <button type="button" class="ex-btn" disabled={format === 'all'} onClick={onCopy}>
          <Icon name={copied === 'export' ? 'check' : failed === 'export' ? 'alert' : 'copy'} />
          <span aria-live="polite">
            {copied === 'export' ? t('export.copied') : failed === 'export' ? t('export.copyFailed') : t('export.copy')}
          </span>
        </button>
        <button type="button" class="ex-btn ex-btn-primary" onClick={() => void onDownload()}>
          <Icon name="download" />
          <span class="ex-btn-label">{t('export.download', { file: filename })}</span>
        </button>
      </footer>

      {sheet && !pro && <PlanSheet variant="overlay" plan="pro" onClose={() => setSheet(false)} />}
    </div>
  );
}
