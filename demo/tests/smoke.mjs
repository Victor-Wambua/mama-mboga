// Headless-browser smoke test of the demo's key flows. Usage: node tests/smoke.mjs [baseUrl]
// Starts its own static server on demo/dist unless a base URL is given. Needs `npm run build` first.
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import assert from 'node:assert/strict';

const dist = path.join(path.dirname(fileURLToPath(import.meta.url)), '../dist');
const types = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.wasm': 'application/wasm', '.svg': 'image/svg+xml', '.txt': 'text/plain' };

let base = process.argv[2];
let server;
if (!base) {
  server = createServer(async (req, res) => {
    const url = new URL(req.url, 'http://x');
    const file = path.normalize(path.join(dist, url.pathname === '/' ? 'index.html' : url.pathname));
    try {
      res.writeHead(200, { 'content-type': types[path.extname(file)] ?? 'application/octet-stream' }).end(await readFile(file));
    } catch { res.writeHead(404).end(); }
  }).listen(0, '127.0.0.1');
  await new Promise((r) => server.on('listening', r));
  base = `http://127.0.0.1:${server.address().port}/`;
}

const pw = await import(process.env.PLAYWRIGHT_MODULE ?? 'playwright');
const browser = await pw.chromium.launch(process.env.PW_EXECUTABLE ? { executablePath: process.env.PW_EXECUTABLE } : {});
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, serviceWorkers: 'block' });
const page = await ctx.newPage();
const problems = [];
page.on('pageerror', (e) => problems.push(e.message));
page.on('console', (m) => { if (m.type() === 'error') problems.push(m.text()); });

const step = async (name, fn) => {
  try {
    await fn();
  } catch (error) {
    console.log('FAILED  ' + name + '\n' + (await page.locator('main').innerText().catch(() => '')).slice(0, 1500));
    console.log(JSON.stringify(await page.evaluate(() => [0, 1, 2, 3].map((i) => [document.querySelector('#ln-' + i + '-amount')?.textContent, document.querySelector('#ln-' + i + '-entry')?.value, document.querySelector('#ln-' + i + '-qty')?.value, document.querySelector('#ln-' + i + '-problem')?.textContent])).catch(() => null)));
    throw error;
  }
  console.log('ok  ' + name);
};
const go = async (view) => { await page.goto(`${base}#/${view}`); await page.waitForSelector('main h1'); };

await page.goto(base);
await page.waitForSelector('body[data-ready="1"]', { timeout: 90000 });

await step('page has one h1, the honesty banner and a title', async () => {
  assert.equal(await page.locator('h1').count(), 1);
  assert.match(await page.locator('.banner').innerText(), /Static demo\. Runs in your browser on invented data; nothing is sent anywhere/);
  assert.match(await page.title(), /Mama Mboga demo/);
});

await step('price board comes from the seeded SQLite database and can be changed', async () => {
  await go('prices');
  const row = page.locator('form.entry', { hasText: 'Tomatoes' });
  await row.locator('input[name="price"]').fill('150');
  await row.getByRole('button', { name: /^Save/ }).click();
  await page.waitForFunction(() => document.querySelector('#flash')?.textContent.includes('Saved Tomatoes'));
  assert.equal(await page.locator('form.entry', { hasText: 'Tomatoes' }).locator('input[name="price"]').inputValue(), '150');
  const bad = page.locator('form.entry', { hasText: 'Kale' });
  await bad.locator('input[name="price"]').fill('0');
  await bad.getByRole('button', { name: /^Save/ }).click();
  await bad.locator('.err').waitFor();
  assert.match(await bad.locator('.err').innerText(), /whole number of shillings/);
});

await step('ambiguous and unknown items are flagged, never guessed', async () => {
  await go('messages');
  await page.click('button[data-act="sample"][data-i="2"]');
  await page.locator('#draft', { hasText: 'viazi 2 kg' }).waitFor();
  await page.waitForSelector('#draft-total');
  assert.match(await page.locator('#draft-summary').innerText(), /2 to check/);
  const flagged = page.locator('tr.is-flag');
  assert.equal(await flagged.count(), 2);
  assert.match(await flagged.first().innerText(), /More than one item fits/);
  await go('messages');
  await page.click('button[data-act="sample"][data-i="3"]');
  await page.locator('#draft', { hasText: 'maziwa' }).waitFor();
  await page.waitForSelector('#draft-total');
  assert.match(await page.locator('#draft').innerText(), /maziwa/);
  assert.match(await page.locator('#draft').innerText(), /Nothing on today's list fits/);
});

const pick = async (selector, text) => {
  const value = await page.locator(`${selector} option`, { hasText: text }).first().getAttribute('value');
  await page.selectOption(selector, value);
};

await step('resolving a flagged line updates the price, the order saves and reaches the market list', async () => {
  await go('messages');
  await page.click('button[data-act="sample"][data-i="2"]');
  await page.locator('#draft', { hasText: 'viazi 2 kg' }).waitFor();
  await page.waitForSelector('#draft-total');
  const before = await page.locator('#draft-total').textContent();
  await pick('#ln-0-entry', 'Irish potatoes');
  await pick('#ln-3-entry', 'Chilli');
  await page.waitForFunction(() => /190/.test(document.querySelector('#ln-0-amount').textContent) && /100/.test(document.querySelector("#ln-3-amount").textContent));
  assert.match(await page.locator('#ln-0-amount').innerText(), /KSh\s190/); // 2 kg at KSh 95
  assert.match(await page.locator('#draft-summary').innerText(), /all clear/);
  // an amount the item cannot be sold in is refused with a message
  await page.fill('#ln-1-qty', '0.3');
  await page.locator('#ln-1-problem:not([hidden])').waitFor();
  await page.fill('#ln-1-qty', '1');
  await page.locator('#ln-1-problem[hidden]').waitFor({ state: 'attached' });
  // saving without a name or phone is refused next to the fields
  await page.click('button:has-text("Save order")');
  await page.locator('input[name="name"][aria-invalid="true"]').waitFor();
  await page.fill('#c-name', 'Test Customer');
  await page.fill('#c-phone', '0700 000 777');
  await page.check('#c-fulfilment-pickup');
  await page.click('button:has-text("Save order")');
  await page.waitForSelector('table:has-text("Test Customer")');
  assert.match(await page.locator('#flash').innerText(), /Order MM-\w+ saved/);
  await go('market');
  assert.match(await page.locator('main').innerText(), /Irish potatoes/);
});

await step('market list items can be ticked off', async () => {
  await go('market');
  const box = page.getByLabel('Bought Irish potatoes');
  await box.check();
  await page.waitForFunction(() => document.querySelector('#market-progress').textContent.includes('1 of'));
  assert.equal(await page.getByLabel('Bought Irish potatoes').isChecked(), true);
});

await step('delivering a deni order charges the ledger', async () => {
  await go('deni');
  const owed = async (name) => {
    const text = await page.locator('tr', { hasText: name }).first().locator('td[data-label="Owes"]').innerText();
    return Number(text.replace(/\D/g, ''));
  };
  await go('messages');
  await page.click('button[data-act="sample"][data-i="1"]');
  await page.locator('#draft', { hasText: 'nyanya kg 1.5' }).waitFor();
  await page.waitForSelector('#draft-total');
  await pick('#c-known', 'Wanjiru Kamau');
  await page.check('#c-fulfilment-pickup');
  await page.check('#c-payment-deni');
  await page.click('button:has-text("Save order")');
  await page.waitForSelector('table:has-text("Wanjiru Kamau")');
  const row = page.locator('tr', { hasText: 'Wanjiru Kamau' }).filter({ hasText: 'Deni' }).first();
  const total = Number((await row.locator('td[data-label="Total"]').innerText()).replace(/\D/g, ''));
  const ref = (await row.locator('td[data-label="Order"] .mono').innerText()).trim();
  for (const label of ['Mark packing', 'Mark ready', 'charge to deni']) {
    await page.locator(`tr:has-text("${ref}") button`, { hasText: label }).first().click();
    await page.waitForTimeout(300);
  }
  await go('deni');
  assert.ok((await owed('Wanjiru Kamau')) >= total);
});

await step('deni book refuses a reused M-Pesa code and an overpayment', async () => {
  await go('deni');
  await page.locator('a', { hasText: 'Hassan Mohamed' }).first().click();
  await page.waitForSelector('h1:has-text("Hassan Mohamed")');
  const form = page.locator('form[data-form="payment"]');
  await form.locator('input[name="amount"]').fill('100');
  await form.locator('input[name="receipt"]').fill('SGH1A2B3C4');
  await form.getByRole('button', { name: 'Record payment' }).click();
  await page.waitForFunction(() => document.querySelector('#flash').textContent.includes('Payment recorded'));
  await form.locator('input[name="amount"]').fill('50');
  await form.locator('input[name="receipt"]').fill('SGH1A2B3C4');
  await form.getByRole('button', { name: 'Record payment' }).click();
  await form.locator('.err').first().waitFor();
  assert.match(await page.locator('form[data-form="payment"] .err').first().innerText(), /already been recorded/);
  await form.locator('input[name="amount"]').fill('9999999');
  await form.locator('input[name="receipt"]').fill('SGH9Z8Y7X6');
  await form.getByRole('button', { name: 'Record payment' }).click();
  await page.waitForFunction(() => /more than the/.test(document.querySelector('form[data-form="payment"] .err')?.textContent ?? ''));
  // the reminder is composed by the PHP code in both languages
  assert.match(await page.locator('#reminder-text').inputValue(), /Habari/);
  await page.check('input[name="lang"][value="en"]');
  await page.waitForFunction(() => document.querySelector('#reminder-text').value.startsWith('Hello'));
});

await step('changes survive a reload, and reset restores the starting data', async () => {
  await page.reload();
  await page.waitForSelector('body[data-ready="1"]', { timeout: 90000 });
  await go('prices');
  assert.equal(await page.locator('form.entry', { hasText: 'Tomatoes' }).locator('input[name="price"]').inputValue(), '150');
  await go('start');
  await page.click('button[data-act="reset"]');
  await page.click('dialog button[value="reset"]');
  await page.waitForFunction(() => document.querySelector('#flash').textContent.includes('starting data'));
  await go('prices');
  assert.notEqual(await page.locator('form.entry', { hasText: 'Tomatoes' }).locator('input[name="price"]').inputValue(), '150');
});

await step('keyboard: skip link works and Escape closes the reset dialog', async () => {
  await page.reload();
  await page.waitForSelector('body[data-ready="1"]', { timeout: 90000 });
  await page.keyboard.press('Tab');
  assert.equal(await page.evaluate(() => document.activeElement.className), 'skip');
  await go('start');
  await page.click('button[data-act="reset"]');
  await page.keyboard.press('Escape');
  assert.equal(await page.evaluate(() => document.querySelector('#confirm').open), false);
});

await step('no horizontal scroll at 320px', async () => {
  await page.setViewportSize({ width: 320, height: 800 });
  for (const v of ['start', 'prices', 'messages', 'orders', 'market', 'deni', 'how']) {
    await go(v);
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${v} scrolls sideways at 320px`);
  }
});

await browser.close();
server?.close();
assert.deepEqual(problems.filter((p) => !/favicon/.test(p)), [], 'console errors');
console.log('all smoke checks passed');
