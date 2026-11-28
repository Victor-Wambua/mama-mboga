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
// Both gateways are stubbed for every request: this test never reaches a real gateway.
const MOCK_HOST = 'mpesa-gateway.victorwambua004.workers.dev';
const SANDBOX_HOST = 'gateway-sbx.victorwambua004.workers.dev';
const gw = { posts: [], gets: [], payments: new Map(), callback: false, release: false, final: 'succeeded', flags: [], failNextPost: false, seq: 0, aborted: 0 };
const cors = { 'access-control-allow-origin': '*', 'access-control-allow-headers': 'Authorization, Content-Type, Idempotency-Key', 'access-control-allow-methods': 'GET, POST, OPTIONS', 'access-control-expose-headers': 'Retry-After' };
const json = (route, status, body) => route.fulfill({ status, headers: { ...cors, 'content-type': 'application/json' }, body: JSON.stringify(body) });
await ctx.route(/workers\.dev\//, async (route) => {
  const req = route.request();
  const url = new URL(req.url());
  if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: cors });
  if (req.method() === 'POST' && url.pathname === '/v1/payments') {
    if (gw.failNextPost) { gw.failNextPost = false; gw.aborted++; gw.posts.push({ host: url.host, failed: true, key: req.headers()['idempotency-key'] }); return route.abort('failed'); }
    const body = JSON.parse(req.postData());
    const key = req.headers()['idempotency-key'];
    gw.posts.push({ host: url.host, body, key, auth: req.headers().authorization });
    const existing = [...gw.payments.values()].find((p) => p.key === key);
    if (existing) return json(route, 200, { data: existing.public });
    const id = `pay_stub${++gw.seq}`;
    const public_ = { id, reference: body.reference, amount: body.amount, currency: 'KES', phone_masked: '2547****000', status: 'pending', flags: [] };
    gw.payments.set(id, { key, public: public_, host: url.host });
    return json(route, 202, { data: public_ });
  }
  const m = url.pathname.match(/^\/v1\/payments\/(pay_stub\d+)(\/events)?$/);
  const rec = m && gw.payments.get(m[1]);
  if (!rec) return json(route, 404, { error: { code: 'not_found', message: 'No such payment.' } });
  if (m[2]) return json(route, 200, { data: [{ kind: gw.callback ? 'callback_received' : 'daraja_accepted' }] });
  gw.gets.push({ host: url.host, id: m[1] });
  if (gw.release) {
    const receipt = `MOCKSTUB${String(gw.seq).padStart(2, '0')}`;
    rec.public = { ...rec.public, status: gw.final, flags: gw.flags, mpesa_receipt: receipt, result_code: 0 };
  }
  return json(route, 200, { data: rec.public });
});
const resetGateway = () => { Object.assign(gw, { callback: false, release: false, final: 'succeeded', flags: [], failNextPost: false }); gw.posts.length = 0; gw.gets.length = 0; };
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
  assert.match(await page.locator('.banner').innerText(), /Static demo\. Runs in your browser on invented data\. Nothing leaves it unless you send an M-Pesa prompt to the mock payments gateway/);
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
  // The sample shop's prices move a little with the day of the year, so read them from the list instead of assuming them.
  const priceOf = async (selector, item) => { const t = await page.locator(`${selector} option`, { hasText: item }).first().textContent(); const m = t.match(/KSh\s*([\d,]+)/); assert.ok(m, `no price in option text: ${JSON.stringify(t)}`); return Number(m[1].replace(/,/g, '')); };
  const potatoes = await priceOf('#ln-0-entry', 'Irish potatoes');
  const chilli = await priceOf('#ln-3-entry', 'Chilli');
  await pick('#ln-0-entry', 'Irish potatoes');
  await pick('#ln-3-entry', 'Chilli');
  await page.waitForFunction(([a, b]) => document.querySelector('#ln-0-amount').textContent.includes(String(a)) && document.querySelector('#ln-3-amount').textContent.includes(String(b)), [potatoes * 2, chilli * 2]);
  assert.match(await page.locator('#ln-0-amount').innerText(), new RegExp(`KSh\\s${potatoes * 2}`)); // 2 kg at the listed price
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
  // Wanjiru owes money whatever the date: the step above just charged her an order. (Who else owes depends on the day.)
  await page.locator('a', { hasText: 'Wanjiru Kamau' }).first().click();
  await page.waitForSelector('h1:has-text("Wanjiru Kamau")');
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

// ---- collect by prompt: the page talks to the (stubbed) gateway, PHP applies the rules -----------------------

const balanceNow = async () => Number((await page.locator('p.big').innerText()).match(/KSh\s([\d,]+)/)[1].replace(/,/g, ''));
const live = () => page.locator('#collect-live');
const openDebtor = async (name, owes) => {
  await go('deni');
  const add = page.locator('form[data-form="customer"]');
  await add.locator('input[name="name"]').fill(name);
  await add.locator('input[name="phone"]').fill('0711 222 333');
  await add.locator('input[name="limit"]').fill('5000');
  await add.getByRole('button', { name: 'Add customer' }).click();
  await page.waitForFunction(() => document.querySelector('#flash').textContent.includes('Customer added'));
  await page.locator('a', { hasText: name }).first().click();
  await page.waitForSelector(`h1:has-text("${name}")`);
  const charge = page.locator('form[data-form="charge"]');
  await charge.locator('input[name="amount"]').fill(String(owes));
  await charge.locator('input[name="note"]').fill('Notebook balance');
  await charge.getByRole('button', { name: 'Add charge' }).click();
  await page.waitForFunction((n) => document.querySelector('p.big')?.textContent.includes(n), new Intl.NumberFormat('en-KE').format(owes));
};

await step('collect by prompt: the amount defaults to the balance up to the demo key limit, the mock is the default, and nothing is paid while it waits or confirms', async () => {
  resetGateway();
  await openDebtor('Prompt Mock', 1500);
  const form = page.locator('form[data-form="collect"]');
  assert.equal(await form.getByLabel('Mock gateway (default)').isChecked(), true);
  assert.equal(await form.locator('input[name="amount"]').inputValue(), '1000', 'KSh 1,500 owed, capped at what the public demo key takes');
  assert.match(await form.locator('#hint-mock').innerText(), /at most KSh\s1,000/);
  assert.equal(await form.locator('#sandbox-panel').isHidden(), true);
  await form.getByRole('button', { name: 'Send M-Pesa prompt' }).click();
  await live().getByText(/Prompt sent to the mock gateway/).waitFor();
  const sent = gw.posts.at(-1);
  assert.equal(sent.host, MOCK_HOST);
  assert.equal(sent.auth, 'Bearer pk_sandbox_duka_demo_4f9c2a7e');
  assert.equal(sent.body.amount, 1000);
  assert.equal(sent.body.phone, '0711222333', 'the customer\'s own number, spaces removed');
  assert.equal(sent.body.scenario, 'success');
  assert.match(sent.body.reference, /^DENI-[A-Z0-9]{6}$/);
  assert.ok(sent.key.length >= 8);
  assert.equal(await balanceNow(), 1500, 'sending a prompt writes nothing in the book');
  gw.callback = true; // the gateway has the callback and is still checking it
  await live().getByText(/^Confirming\./).waitFor({ timeout: 20000 });
  assert.equal(await balanceNow(), 1500, 'confirming is not paid');
  assert.equal(await page.locator('#prompts-h').count(), 1);
  assert.match(await page.locator('.prompts li').first().innerText(), /Waiting\./);
  gw.release = true;
  await live().getByText(/Paid and recorded/).waitFor({ timeout: 20000 });
  assert.equal(await balanceNow(), 500);
  const row = page.locator('tbody tr', { hasText: 'MOCKSTUB' }).first();
  assert.match(await row.innerText(), /Mock gateway, simulated/);
  assert.equal(gw.posts.filter((p) => p.host === SANDBOX_HOST).length, 0, 'the sandbox is never touched unless chosen');
  assert.ok(gw.gets.every((g) => g.host === MOCK_HOST));
  // checking again records nothing more
  await page.reload();
  await page.waitForSelector('body[data-ready="1"]', { timeout: 90000 });
  await go('deni');
  await page.locator('a', { hasText: 'Prompt Mock' }).first().click();
  await page.waitForSelector('h1:has-text("Prompt Mock")');
  assert.equal(await balanceNow(), 500);
  assert.equal(await page.locator('.prompts li').count(), 1);
});

await step('collect by prompt: an amount above the balance is refused next to the field before anything is sent', async () => {
  resetGateway();
  const form = page.locator('form[data-form="collect"]');
  await form.locator('input[name="amount"]').fill('501');
  await form.getByRole('button', { name: 'Send M-Pesa prompt' }).click();
  await form.locator('.err').first().waitFor();
  assert.match(await form.locator('.err').first().innerText(), /up to/);
  assert.equal(gw.posts.length, 0);
});

await step('collect by prompt: a payment the gateway holds for review is shown to the owner and never recorded', async () => {
  resetGateway();
  gw.final = 'needs_review';
  gw.flags = ['late_success'];
  const form = page.locator('form[data-form="collect"]');
  await form.locator('input[name="amount"]').fill('200');
  await form.locator('select[name="scenario"]').selectOption('late_success');
  await form.getByRole('button', { name: 'Send M-Pesa prompt' }).click();
  await live().getByText(/Prompt sent/).waitFor();
  assert.equal(gw.posts.at(-1).body.scenario, 'late_success');
  gw.release = true;
  await live().getByText(/Needs you/).waitFor({ timeout: 20000 });
  assert.match(await live().innerText(), /arrived after the deadline/);
  assert.equal(await balanceNow(), 500, 'a held payment is never written, even with a receipt on it');
  await go('deni');
  assert.match(await page.locator('.needs').innerText(), /Prompt payments that need you[\s\S]*Prompt Mock/);
  await page.locator('a', { hasText: 'Prompt Mock' }).first().click();
  await page.waitForSelector('h1:has-text("Prompt Mock")');
  await page.locator('form[data-form="collect-review"] input[name="note"]').fill('Nothing in M-Pesa');
  await page.getByRole('button', { name: 'I have looked at this' }).click();
  await page.waitForFunction(() => document.querySelector('#flash').textContent.includes('book was not changed'));
  await go('deni');
  assert.equal(await page.locator('.needs').count(), 0);
});

await step('collect by prompt: the Safaricom sandbox needs the acknowledgement, sends a flat KES 1 with no scenario, and does not remember the tick', async () => {
  resetGateway();
  await go('deni');
  await page.locator('a', { hasText: 'Prompt Mock' }).first().click();
  await page.waitForSelector('h1:has-text("Prompt Mock")');
  const form = page.locator('form[data-form="collect"]');
  await form.getByLabel('Safaricom sandbox').check();
  assert.equal(await form.locator('#sandbox-panel').isVisible(), true);
  assert.match(await form.locator('#sandbox-panel').innerText(), /KES 1 stands in for the amount/);
  assert.equal(await form.locator('input[name="phone"]').inputValue(), '', 'no phone is pre-filled');
  assert.equal(await form.locator('input[name="ack"]').isChecked(), false);
  assert.match(await form.locator('label.ack').innerText(), /real prompt to the number I entered[\s\S]*only my own number[\s\S]*has not been checked/);
  await form.locator('input[name="amount"]').fill('300');
  await form.getByRole('button', { name: 'Send M-Pesa prompt' }).click();
  await form.locator('.err').first().waitFor();
  assert.equal(gw.posts.length, 0, 'nothing is sent without the number and the tick');
  await form.locator('input[name="phone"]').fill('0700 111 222');
  await form.getByRole('button', { name: 'Send M-Pesa prompt' }).click();
  await page.waitForFunction(() => document.querySelector('form[data-form="collect"] .err')?.textContent.includes('Tick the box'));
  assert.equal(gw.posts.length, 0);
  await form.locator('input[name="ack"]').check();
  await form.getByRole('button', { name: 'Send M-Pesa prompt' }).click();
  await live().getByText(/Check your phone for an M-Pesa prompt/).waitFor();
  const sent = gw.posts.at(-1);
  assert.equal(sent.host, SANDBOX_HOST);
  assert.equal(sent.body.amount, 1, 'a flat KES 1 test charge');
  assert.equal('scenario' in sent.body, false);
  assert.equal(sent.body.phone, '0700111222');
  assert.equal(await form.locator('input[name="ack"]').isChecked(), false, 'the tick is cleared after a send');
  assert.equal(await form.locator('input[name="phone"]').inputValue(), '', 'and so is the number');
  assert.equal(await balanceNow(), 500);
  gw.release = true;
  await live().getByText(/Paid and recorded/).waitFor({ timeout: 30000 });
  assert.equal(await balanceNow(), 200, 'the KSh 300 it stands in for');
  assert.match(await page.locator('tbody tr', { hasText: 'Sandbox test' }).first().innerText(), /Sandbox test: KSh\s1 charged stands in for KSh\s300/);
  assert.match(await page.locator('.prompts li').first().innerText(), /KES 1 test charge/);
  // the choice is remembered, the tick is not
  await page.reload();
  await page.waitForSelector('body[data-ready="1"]', { timeout: 90000 });
  await go('deni');
  await page.locator('a', { hasText: 'Prompt Mock' }).first().click();
  await page.waitForSelector('h1:has-text("Prompt Mock")');
  assert.equal(await page.locator('form[data-form="collect"]').getByLabel('Safaricom sandbox').isChecked(), true);
  assert.equal(await page.locator('form[data-form="collect"] input[name="ack"]').isChecked(), false);
  await page.locator('form[data-form="collect"]').getByLabel('Mock gateway (default)').check();
});

await step('collect by prompt: a network failure leaves a saved request that sends again with the same idempotency key', async () => {
  resetGateway();
  gw.failNextPost = true;
  const form = page.locator('form[data-form="collect"]');
  await form.locator('input[name="amount"]').fill('100');
  await form.getByRole('button', { name: 'Send M-Pesa prompt' }).click();
  await live().getByText(/Could not reach the mock gateway/).waitFor();
  await page.locator('.prompts li', { hasText: 'Not sent yet' }).first().waitFor();
  await page.getByRole('button', { name: /^Send .* again$/ }).click();
  await live().getByText(/Prompt sent/).waitFor();
  const [first, second] = gw.posts;
  assert.equal(first.failed, true);
  assert.equal(second.key, first.key, 'the retry reuses the request\'s idempotency key');
  gw.release = true;
  await live().getByText(/Paid and recorded/).waitFor({ timeout: 20000 });
  assert.equal(await balanceNow(), 100);
});

await step('collect by prompt: controls are labelled, status is a live region, and the page fits 320px', async () => {
  resetGateway();
  const form = page.locator('form[data-form="collect"]');
  assert.equal(await page.locator('#collect-live[role="status"][aria-live="polite"]').count(), 1);
  for (const label of ['Amount (KSh)', 'What the customer does', 'Mock gateway (default)', 'Safaricom sandbox']) assert.equal(await form.getByLabel(label).count(), 1, label);
  await page.setViewportSize({ width: 320, height: 800 });
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'customer page scrolls sideways at 320px');
  for (const b of await form.locator('button, input[type="radio"], input[type="checkbox"], select, input:not([type="radio"]):not([type="checkbox"])').all()) {
    if (!(await b.isVisible())) continue;
    const box = await b.boundingBox();
    assert.ok(box.height >= 22, 'a control is too small to tap');
  }
  assert.ok((await form.getByRole('button', { name: 'Send M-Pesa prompt' }).boundingBox()).height >= 44);
  await page.setViewportSize({ width: 1280, height: 900 });
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
// The one network failure we cause on purpose shows up as a console error; anything else is a real problem.
let abortsToIgnore = gw.aborted;
assert.deepEqual(problems.filter((p) => !/favicon/.test(p) && !(abortsToIgnore > 0 && /ERR_FAILED/.test(p) && abortsToIgnore--)), [], 'console errors');
console.log('all smoke checks passed');
