// Static server for the fixture pages (playwright.config.ts webServer).
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';

const dir = resolve(import.meta.dirname, '../fixtures');
const port = Number(process.env.PORT ?? 4517);

createServer(async (req, res) => {
  const name = new URL(req.url ?? '/', 'http://x').pathname.slice(1);
  if (!name) { res.writeHead(200).end('ok'); return; }
  // Fixtures are flat .html files; anything else (including path traversal) is not served.
  if (!/^[\w-]+\.html$/.test(name)) { res.writeHead(404).end('not found'); return; }
  try {
    const body = await readFile(join(dir, name));
    res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' }).end(body);
  } catch {
    res.writeHead(404).end('not found');
  }
}).listen(port, '127.0.0.1', () => console.log(`fixtures on http://127.0.0.1:${port}`));
