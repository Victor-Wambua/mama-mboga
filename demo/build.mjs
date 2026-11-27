// Builds the static demo into demo/dist: the page, a worker that runs PHP-WASM, and the repository's PHP sources.
import { build } from 'esbuild';
import { cp, mkdir, readdir, readFile, rm, stat, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const repo = path.resolve(here, '..');
const dist = path.join(here, 'dist');

await rm(dist, { recursive: true, force: true });
await mkdir(dist, { recursive: true });

// 1. The worker bundles the PHP-WASM runtime (asyncify build, works in every current browser) and its .wasm file.
await build({
  entryPoints: { worker: path.join(here, 'web/worker.js'), app: path.join(here, 'web/app.js') },
  bundle: true,
  format: 'esm',
  outdir: dist,
  target: 'es2022',
  minify: true,
  loader: { '.wasm': 'file' },
  assetNames: 'php-[hash]',
  publicPath: '.',
  // Node-only branches inside the PHP-WASM glue; they are never reached in a browser.
  external: ['events', 'fs', 'path', 'os', 'crypto', 'child_process', 'zlib', 'url', 'util', 'stream', 'net', 'tls', 'http', 'https', 'worker_threads', 'node:*'],
  legalComments: 'none',
  logLevel: 'warning',
});

// 2. The real PHP sources, as one JSON file the worker writes into PHP's in-memory file system.
async function walk(dir, ext) {
  const out = [];
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) out.push(...(await walk(full, ext)));
    else if (ext.some((e) => entry.name.endsWith(e))) out.push(full);
  }
  return out.sort();
}
const files = {};
for (const [dir, ext] of [['src', ['.php']], ['migrations', ['.sql']], ['demo/php', ['.php']]]) {
  for (const file of await walk(path.join(repo, dir), ext)) {
    files['/app/' + path.relative(repo, file).split(path.sep).join('/')] = await readFile(file, 'utf8');
  }
}
await writeFile(path.join(dist, 'php-files.json'), JSON.stringify(files));

// 3. Static page, styles, icon and licence notices.
for (const f of ['index.html', 'app.css', 'icon.svg']) await cp(path.join(here, 'web', f), path.join(dist, f));
await cp(path.join(here, 'THIRD-PARTY-NOTICES.txt'), path.join(dist, 'THIRD-PARTY-NOTICES.txt'));
await cp(path.join(here, 'node_modules/@php-wasm/web-8-3/LICENSE'), path.join(dist, 'LICENSE-php-wasm.txt'));
await writeFile(path.join(dist, '.nojekyll'), '');

// 4. Offline copy: a service worker that precaches every file above.
import { createHash } from 'node:crypto';
const names = (await readdir(dist)).filter((n) => !n.startsWith('.'));
const hash = createHash('sha256');
for (const n of names) hash.update(n).update(await readFile(path.join(dist, n)));
const sw = (await readFile(path.join(here, 'web/sw.js'), 'utf8'))
  .replace('__VERSION__', hash.digest('hex').slice(0, 12))
  .replace('__FILES__', JSON.stringify(['./', ...names]));
await writeFile(path.join(dist, 'sw.js'), sw);

const sizes = [];
for (const name of await readdir(dist)) sizes.push([name, (await stat(path.join(dist, name))).size]);
console.log(sizes.map(([n, s]) => `${n.padEnd(28)} ${(s / 1024).toFixed(0).padStart(7)} KB`).join('\n'));
