// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanUrl, connectUrl, errorCode, upload, uploadForm } from '../../src/share';

afterEach(() => vi.unstubAllGlobals());

/** Records what upload() does with XMLHttpRequest and answers with `status` and `response`. */
function fakeXhr(status: number, response: unknown, event: 'load' | 'error' = 'load') {
  const seen: { method?: string; url?: string; headers: Record<string, string>; body?: unknown } = { headers: {} };
  class X {
    status = status;
    response = response;
    responseType = '';
    upload: { onprogress?: (e: { loaded: number; total: number }) => void } = {};
    onload?: () => void;
    onerror?: () => void;
    onabort?: () => void;
    open(method: string, url: string) { seen.method = method; seen.url = url; }
    setRequestHeader(k: string, v: string) { seen.headers[k] = v; }
    abort() { this.onabort?.(); }
    send(body: unknown) {
      seen.body = body;
      this.upload.onprogress?.({ loaded: 5, total: 10 });
      queueMicrotask(() => (event === 'load' ? this.onload?.() : this.onerror?.()));
    }
  }
  vi.stubGlobal('XMLHttpRequest', X);
  return seen;
}

const form = () =>
  uploadForm({ url: 'https://fernleaf.example/shop?utm=x#top', title: 'Fernleaf', style: 'editorial', tokens: '{"a":1}', figma: '{"b":2}', shot: new Blob(['img'], { type: 'image/webp' }) });

describe('share', () => {
  it('cleans the query string and fragment off the URL', () => {
    expect(cleanUrl('https://fernleaf.example/shop/?q=1&r=2#top')).toBe('https://fernleaf.example/shop/');
    expect(cleanUrl('https://fernleaf.example')).toBe('https://fernleaf.example/');
  });

  it('builds the connect URL from API_BASE and the extension id', () => {
    expect(connectUrl('abc')).toBe('https://vietnamcos.com/palette/directory/connect?ext=abc');
  });

  it('builds the upload form with every field and file', () => {
    const f = form();
    expect(f.get('url')).toBe('https://fernleaf.example/shop');
    expect(f.get('title')).toBe('Fernleaf');
    expect(f.get('style')).toBe('editorial');
    expect(f.get('consent')).toBe('1');
    expect((f.get('tokens') as File).name).toBe('tokens.json');
    expect((f.get('figma') as File).name).toBe('figma-variables.json');
    expect((f.get('shot') as File).name).toBe('screenshot.webp');
    const noFigma = uploadForm({ url: 'https://a.example/', title: 't', style: 's', tokens: '{}', shot: new Blob() });
    expect(noFigma.has('figma')).toBe(false);
  });

  it('maps statuses and bodies to error codes', () => {
    expect(errorCode(0, null)).toBe('offline');
    expect(errorCode(401, { ok: false, error: 'token_revoked' })).toBe('token_revoked');
    expect(errorCode(403, null)).toBe('blocked');
    expect(errorCode(413, null)).toBe('too_large');
    expect(errorCode(429, { ok: false, error: 'rate_limited' })).toBe('rate_limited');
    expect(errorCode(422, { ok: false, error: 'no_style' })).toBe('no_style');
    expect(errorCode(507, { ok: false, error: 'disk_full' })).toBe('disk_full');
    expect(errorCode(500, null)).toBe('failed');
  });

  it('posts with the Bearer token, reports progress and resolves with the new item', async () => {
    const seen = fakeXhr(200, { ok: true, slug: 'fernleaf-7k2q', url: 'https://vietnamcos.com/palette/directory/fernleaf-7k2q' });
    const progress = vi.fn();
    const f = form();
    const r = await upload('tok-1', f, progress, new AbortController().signal);
    expect(seen.method).toBe('POST');
    expect(seen.url).toBe('https://vietnamcos.com/palette/api.php?action=dir_upload');
    expect(seen.headers.Authorization).toBe('Bearer tok-1');
    expect(seen.body).toBe(f);
    expect(progress).toHaveBeenCalledWith(5, 10);
    expect(r.slug).toBe('fernleaf-7k2q');
  });

  it('rejects with the error code, offline on a network error and aborted on cancel', async () => {
    fakeXhr(429, { ok: false, error: 'rate_limited', message: 'Slow down' });
    await expect(upload('t', form(), () => {}, new AbortController().signal)).rejects.toThrow('rate_limited');
    fakeXhr(200, { ok: false, error: 'bad_image' });
    await expect(upload('t', form(), () => {}, new AbortController().signal)).rejects.toThrow('bad_image');
    fakeXhr(0, null, 'error');
    await expect(upload('t', form(), () => {}, new AbortController().signal)).rejects.toThrow('offline');
    fakeXhr(200, { ok: true });
    const c = new AbortController();
    const p = upload('t', form(), () => {}, c.signal);
    c.abort();
    await expect(p).rejects.toThrow('aborted');
  });
});
