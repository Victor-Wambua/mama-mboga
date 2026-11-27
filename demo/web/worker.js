// Runs the repository's PHP under PHP-WASM, off the main thread, and keeps the SQLite file in IndexedDB.
import { loadPHPRuntime, PHP } from '@php-wasm/universal';
import * as phpLoader from '../node_modules/@php-wasm/web-8-3/asyncify/php_8_3.js';

const DB_PATH = '/data/mama-mboga.sqlite';
const IDB_NAME = 'mama-mboga-demo';
const READ_ONLY = new Set(['snapshot', 'parse', 'quote', 'customer', 'reminder']);

let php;
let ready;

function idb() {
  return new Promise((resolve, reject) => {
    const open = indexedDB.open(IDB_NAME, 1);
    open.onupgradeneeded = () => open.result.createObjectStore('db');
    open.onsuccess = () => resolve(open.result);
    open.onerror = () => reject(open.error);
  });
}
async function idbGet(key) {
  try {
    const db = await idb();
    return await new Promise((resolve, reject) => {
      const req = db.transaction('db').objectStore('db').get(key);
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  } catch {
    return undefined;
  }
}
async function idbPut(key, value) {
  try {
    const db = await idb();
    await new Promise((resolve, reject) => {
      const tx = db.transaction('db', 'readwrite');
      tx.objectStore('db').put(value, key);
      tx.oncomplete = resolve;
      tx.onerror = () => reject(tx.error);
    });
    return true;
  } catch {
    return false; // private window or storage blocked: the demo still works, it just forgets on reload
  }
}
async function idbClear() {
  try {
    const db = await idb();
    await new Promise((resolve) => {
      const tx = db.transaction('db', 'readwrite');
      tx.objectStore('db').clear();
      tx.oncomplete = resolve;
      tx.onerror = resolve;
    });
  } catch {
    /* nothing to clear */
  }
}

async function start() {
  const started = performance.now();
  const runtime = await loadPHPRuntime(phpLoader, {});
  php = new PHP(runtime);
  const files = await (await fetch('php-files.json')).json();
  for (const [file, content] of Object.entries(files)) {
    php.mkdir(file.slice(0, file.lastIndexOf('/')));
    php.writeFile(file, content);
  }
  php.mkdir('/data');
  const saved = await idbGet('sqlite');
  if (saved) php.writeFile(DB_PATH, new Uint8Array(saved));
  return { restored: Boolean(saved), startedMs: Math.round(performance.now() - started), phpVersion: (await php.run({ code: '<?php echo PHP_VERSION;' })).text };
}

async function call(action, args) {
  const response = await php.run({
    scriptPath: '/app/demo/php/run.php',
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: new TextEncoder().encode(JSON.stringify({ action, args })),
  });
  let result;
  try {
    result = JSON.parse(response.text);
  } catch {
    result = { ok: false, errors: {}, message: 'The PHP code returned something unexpected: ' + response.text.slice(0, 300) + (response.errors || '') };
  }
  if (result.ok && !READ_ONLY.has(action)) {
    const bytes = php.readFileAsBuffer(DB_PATH);
    result.persisted = await idbPut('sqlite', bytes.slice().buffer);
  }
  return result;
}

self.onmessage = async ({ data }) => {
  const { id, action, args } = data;
  try {
    if (action === 'init') {
      ready = start();
      self.postMessage({ id, result: await ready });
      return;
    }
    await ready;
    if (action === 'reset') {
      if (php.fileExists(DB_PATH)) php.unlink(DB_PATH);
      await idbClear();
      const result = await call('snapshot', {});
      if (result.ok) result.persisted = await idbPut('sqlite', php.readFileAsBuffer(DB_PATH).slice().buffer);
      self.postMessage({ id, result });
      return;
    }
    self.postMessage({ id, result: await call(action, args ?? {}) });
  } catch (error) {
    self.postMessage({ id, error: String(error && error.message ? error.message : error) });
  }
};
