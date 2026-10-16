const REVOKE_DELAY_MS = 1000;

/** Saves `data` as `filename` through a temporary object URL, revoked once the browser has taken it. */
export function download(filename: string, data: string | Uint8Array, mime: string): void {
  const url = URL.createObjectURL(new Blob([data as BlobPart], { type: mime }));
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.hidden = true;
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), REVOKE_DELAY_MS);
}
