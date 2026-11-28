// Mama Mboga browser demo: a front end for the repository's own PHP code, which runs in a worker (PHP-WASM).
// Every number on the page (prices, totals, balances, flags) is computed by that PHP code, not by this file.

import { PROVIDERS, MOCK_SCENARIOS, createClient, createBody, watchPayment, GatewayError } from './gateway.js';

// ---- tiny helpers ----------------------------------------------------------------------------

const $ = (selector, root = document) => root.querySelector(selector);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
class Raw { constructor(s) { this.s = s; } }
const raw = (s) => new Raw(s);
const val = (v) => (v instanceof Raw ? v.s : Array.isArray(v) ? v.map(val).join('') : v == null || v === false ? '' : esc(v));
const html = (strings, ...vals) => new Raw(strings.reduce((out, s, i) => out + s + (i < vals.length ? val(vals[i]) : ''), ''));
const kes = (n) => 'KSh ' + new Intl.NumberFormat('en-KE').format(n);
const UNIT_SW = { kg: 'kilo', bunch: 'mkungu', piece: 'kipande', heap: 'rundo' };
const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

function longDate(iso) {
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
}

// ---- the PHP worker --------------------------------------------------------------------------

let worker;
let seq = 0;
const pending = new Map();

function startWorker() {
  worker = new Worker('worker.js', { type: 'module' });
  worker.onmessage = ({ data }) => {
    const p = pending.get(data.id);
    if (!p) return;
    pending.delete(data.id);
    if (data.error) p.reject(new Error(data.error));
    else p.resolve(data.result);
  };
  worker.onerror = (e) => {
    for (const p of pending.values()) p.reject(new Error(e.message || 'The PHP worker stopped.'));
    pending.clear();
  };
}

function ask(action, args = {}) {
  return new Promise((resolve, reject) => {
    const id = ++seq;
    pending.set(id, { resolve, reject });
    worker.postMessage({ id, action, args });
  });
}

/** Calls an action and keeps the shared snapshot current when the reply carries one. */
async function call(action, args = {}) {
  const r = await ask(action, args);
  if (r.ok) {
    const snap = r.data?.snapshot ?? (r.data?.board ? r.data : null);
    if (snap) S.snap = snap;
    if (r.persisted === false && !S.persistWarned) {
      S.persistWarned = true;
      flash('This browser is not keeping your changes between visits (storage is blocked). The demo still works.', false);
    }
  }
  return r;
}

// ---- state and routing -----------------------------------------------------------------------

const S = { snap: null, boot: null, bootError: null, view: 'start', param: null, draft: null, msg: '', detail: null, lang: 'sw', reminder: '', persistWarned: false, lastRef: null, quoteSeq: 0, flashNext: null,
  collect: { provider: loadProvider(), status: null, watch: null, scenario: {} } };

/** The gateway choice is remembered; the sandbox acknowledgement never is. */
function loadProvider() {
  try { return localStorage.getItem('mm-demo-gateway') === 'sandbox' ? 'sandbox' : 'mock'; } catch { return 'mock'; }
}
function saveProvider(id) {
  try { localStorage.setItem('mm-demo-gateway', id); } catch { /* storage blocked: the choice is just not remembered */ }
}

const NAV = [['start', 'Start'], ['prices', 'Prices'], ['messages', 'WhatsApp orders'], ['orders', 'Orders'], ['market', 'Market list'], ['deni', 'Deni'], ['how', 'How it works']];
const TITLES = { start: 'Start', prices: "Today's prices", messages: 'Read a WhatsApp order', orders: 'Orders', market: 'Market list', deni: 'Deni book', how: 'How this demo works' };

function parseRoute() {
  const [, view = 'start', param = null] = location.hash.split('/');
  return { view: TITLES[view] ? view : 'start', param };
}

function flash(text, ok = true) {
  const el = $('#flash');
  el.className = ok ? '' : 'is-error';
  el.innerHTML = text ? `<div class="wrap">${esc(text)}</div>` : '';
}

// ---- forms: errors next to the field ---------------------------------------------------------

function clearErrors(root) {
  for (const e of root.querySelectorAll('[data-err]')) e.remove();
  for (const f of root.querySelectorAll('[aria-invalid]')) {
    f.removeAttribute('aria-invalid');
    f.removeAttribute('aria-describedby');
  }
}

function showErrors(root, errors, summaryText) {
  clearErrors(root);
  const unmatched = [];
  let first = null;
  for (const [key, message] of Object.entries(errors || {})) {
    const field = root.querySelector(`[name="${key}"]`) || root.querySelector(`[data-field="${key}"]`);
    if (!field) { unmatched.push(message); continue; }
    const id = `err-${Math.random().toString(36).slice(2, 8)}`;
    const p = document.createElement('p');
    p.className = 'err';
    p.id = id;
    p.dataset.err = '1';
    p.textContent = message;
    const holder = field.closest('label.f, fieldset, .field-holder') || field;
    holder.after(p);
    if (field.matches('input, select, textarea')) {
      field.setAttribute('aria-invalid', 'true');
      field.setAttribute('aria-describedby', id);
      first ??= field;
    }
  }
  if (unmatched.length || (!first && summaryText)) {
    const p = document.createElement('p');
    p.className = 'formerr';
    p.dataset.err = '1';
    p.setAttribute('role', 'alert');
    p.textContent = unmatched.join(' ') || summaryText;
    root.prepend(p);
  }
  first?.focus();
}

// ---- rendering -------------------------------------------------------------------------------

function render({ focusHeading = false } = {}) {
  const main = $('#main');
  const active = document.activeElement;
  const keep = !focusHeading && active && active.id && main.contains(active) ? { id: active.id, start: active.selectionStart, end: active.selectionEnd } : null;

  $('#nav').innerHTML = NAV.map(([key, label]) => `<li><a href="#/${key}"${key === S.view ? ' aria-current="page"' : ''}>${esc(label)}</a></li>`).join('');

  let body;
  let title = TITLES[S.view];
  if (S.bootError) {
    body = html`<h1>The demo could not start</h1><p class="state err-state">${S.bootError}</p><p>The demo runs PHP as WebAssembly in a web worker, so it needs a current version of Chrome, Edge, Firefox or Safari with JavaScript on. If you are in a private window, try a normal one.</p>`;
    title = 'Could not start';
  } else if (!S.snap) {
    body = html`<div class="loading"><h1>Starting the shop</h1><p>PHP is loading in your browser. The first visit downloads about 7 MB; later visits use your browser's copy.</p></div>`;
    title = 'Starting';
  } else {
    const view = { start: viewStart, prices: viewPrices, messages: viewMessages, orders: viewOrders, market: viewMarket, deni: viewDeni, how: viewHow }[S.view];
    body = view();
    if (S.view === 'deni' && S.detail) title = S.detail.name + ', deni';
  }
  main.innerHTML = body.s;
  document.title = `${title} · Mama Mboga demo`;

  if (focusHeading) {
    const h1 = $('h1', main);
    if (h1) { h1.tabIndex = -1; h1.focus({ preventScroll: false }); }
  } else if (keep) {
    const el = document.getElementById(keep.id);
    if (el) {
      el.focus({ preventScroll: true });
      if (keep.start != null && el.setSelectionRange) try { el.setSelectionRange(keep.start, keep.end); } catch { /* not a text field */ }
    }
  }
}

async function show({ focusHeading = true } = {}) {
  const { view, param } = parseRoute();
  S.view = view;
  S.param = param;
  if (focusHeading) flash(S.flashNext ?? '');
  S.flashNext = null;
  S.detail = null;
  stopWatch();
  if (S.snap && view === 'deni' && param) {
    const r = await call('customer', { customerId: Number(param) });
    if (r.ok) S.detail = r.data;
    else { location.hash = '#/deni'; return; }
    S.reminder = '';
    const rem = await call('reminder', { customerId: Number(param), lang: S.lang });
    if (rem.ok) S.reminder = rem.data.text;
  }
  render({ focusHeading });
  if (S.detail) resumePrompts();
}

// ---- views -----------------------------------------------------------------------------------

function viewStart() {
  const s = S.snap;
  const open = s.orders.filter((o) => o.status !== 'cancelled');
  const owing = s.customers.filter((c) => c.balance > 0);
  return html`
    <div class="split">
      <section>
        <h1>The owner's morning</h1>
        <p class="lead">Mama Njeri sets the day's prices, reads the orders customers sent on WhatsApp, buys at the market, delivers, and writes down who owes what. Try each step with the sample shop.</p>
        <ol class="steps">
          <li><div><h3>Set today's prices</h3><p>Change the tomato price or the stock of kale, then look at the line total change when you read an order.</p><a href="#/prices">Open prices</a></div></li>
          <li><div><h3>Read a customer's WhatsApp message</h3><p>Pick a sample such as "Ambiguous: viazi and pilipili". Lines the reader is unsure of are marked Check, and nothing is guessed.</p><a href="#/messages">Open WhatsApp orders</a></div></li>
          <li><div><h3>See what to buy at the market</h3><p>Save the order, then open the market list. Quantities from every open order are added up per item.</p><a href="#/market">Open the market list</a></div></li>
          <li><div><h3>Deliver an order on deni</h3><p>Move a deni order to delivered. It is charged to the customer's book, up to their credit limit.</p><a href="#/orders">Open orders</a></div></li>
          <li><div><h3>Record a payment</h3><p>Pay part of a balance with an M-Pesa code. Then try the same code again, or pay more than is owed, and watch the book refuse.</p><a href="#/deni">Open the deni book</a></div></li>
          <li><div><h3>Collect deni by M-Pesa prompt</h3><p>Open a customer who owes and press Send M-Pesa prompt. The mock gateway answers in a few seconds, and the book is written only when it says succeeded. Pick "Answers after the deadline" or "status check disagrees" to see a payment held for you instead.</p><a href="#/deni">Open the deni book</a></div></li>
        </ol>
      </section>
      <aside class="rule-top" aria-labelledby="today-h">
        <h2 id="today-h" style="margin-top:0">Today in the sample shop</h2>
        <p class="muted">${longDate(s.today)}</p>
        <dl class="facts">
          <div><dt>Items on today's list</dt><dd class="num">${s.board.length}</dd></div>
          <div><dt>Open and delivered orders</dt><dd class="num">${open.length}</dd></div>
          <div><dt>Value of those orders</dt><dd class="num">${kes(s.orderTotal)}</dd></div>
          <div><dt>Customers who owe deni</dt><dd class="num">${owing.length}</dd></div>
          <div><dt>Total owed</dt><dd class="num">${kes(owing.reduce((a, c) => a + c.balance, 0))}</dd></div>
        </dl>
        <p class="small muted" style="margin-top:14px">Changes are saved in this browser only, so they are still here next time. Reset puts the starting data back.</p>
        <button type="button" class="btn quiet" data-act="reset">Reset the demo</button>
        <p class="small" style="margin-top:18px"><a href="#/how">How this demo works, and what is real</a></p>
      </aside>
    </div>`;
}

function viewPrices() {
  const s = S.snap;
  const off = s.items.filter((i) => !i.onBoard);
  const rows = s.board.map((e) => html`
    <li>
      <form class="entry" data-form="entry" data-entry="${e.entryId}" data-item="${e.itemId}" novalidate>
        <div class="name"><strong>${e.nameEn}</strong> <span class="sw">${e.nameSw}</span>${e.aliases.length ? html`<span class="hint">Also understood as: ${e.aliases.join(', ')}</span>` : ''}</div>
        <label class="f"><span class="lbl">Sold by</span>
          <select name="unit" aria-label="Sold by, ${e.nameEn}">${['kg', 'bunch', 'piece', 'heap'].map((u) => html`<option value="${u}"${u === e.unit ? raw(' selected') : ''}>${u}</option>`)}</select></label>
        <label class="f"><span class="lbl">Price (KSh)</span><input name="price" inputmode="numeric" autocomplete="off" value="${e.price}" aria-label="Price in shillings, ${e.nameEn}"></label>
        <label class="f"><span class="lbl">In stock</span><input name="available" inputmode="decimal" autocomplete="off" value="${e.available}" aria-label="In stock, ${e.nameEn}"></label>
        <label class="choice"><input type="checkbox" name="special"${e.special ? raw(' checked') : ''} aria-label="Special today, ${e.nameEn}"> Special</label>
        <label class="f note-cell"><span class="lbl">Special note</span><input name="note" maxlength="60" autocomplete="off" value="${e.note}" aria-label="Special note, ${e.nameEn}"></label>
        <div class="act">
          <button class="btn sm" type="submit" aria-label="Save ${e.nameEn}">Save</button>
          <button class="btn sm danger" type="button" data-act="remove-entry" data-entry="${e.entryId}" aria-label="Remove ${e.nameEn} from today's list">Remove</button>
        </div>
      </form>
    </li>`);
  return html`
    <h1>Today's prices</h1>
    <p class="lead">${longDate(s.today)}. Customers order from this list, and the message reader matches words against it.</p>
    ${s.board.length === 0 ? html`
      <div class="state"><p><strong>No prices are set for today.</strong> ${s.hasPrevious ? 'Copy yesterday\'s list as a starting point, then adjust the prices.' : 'Add items below.'}</p>
      ${s.hasPrevious ? html`<button type="button" class="btn" data-act="copy-previous">Copy the last list</button>` : ''}</div>` : html`
      <div class="actions" style="margin-bottom:12px"><span class="muted">${plural(s.board.length, 'item', 'items')} on today's list.</span>
        <button type="button" class="btn quiet sm" data-act="copy-previous">Add anything missing from the last list</button></div>
      <ul class="entries">
        <li class="head" aria-hidden="true"><span>Item</span><span>Sold by</span><span>Price (KSh)</span><span>In stock</span><span>Special</span><span>Note</span><span></span></li>
        ${rows}
      </ul>`}
    <div class="split even" style="margin-top:32px">
      <section>
        <h2 style="margin-top:0">Add an item to today's list</h2>
        ${off.length === 0 ? html`<p class="muted">Every item in the catalogue is already on today's list.</p>` : html`
        <form data-form="add" novalidate>
          <div class="fields">
            <label class="f"><span class="lbl">Item</span><select name="itemId">${off.map((i) => html`<option value="${i.id}" data-unit="${i.unit}">${i.nameEn} / ${i.nameSw}</option>`)}</select></label>
            <label class="f"><span class="lbl">Price (KSh)</span><input name="price" inputmode="numeric" autocomplete="off"></label>
            <label class="f"><span class="lbl">In stock</span><input name="available" inputmode="decimal" autocomplete="off" value="10"></label>
          </div>
          <button class="btn" type="submit">Add to today's list</button>
        </form>`}
      </section>
      <section>
        <h2 style="margin-top:0">Add a new product</h2>
        <p class="muted small">Aliases are other words customers use. The message reader matches them too.</p>
        <form data-form="newitem" novalidate>
          <div class="fields">
            <label class="f"><span class="lbl">English name</span><input name="name_en" autocomplete="off"></label>
            <label class="f"><span class="lbl">Swahili name</span><input name="name_sw" autocomplete="off"></label>
            <label class="f"><span class="lbl">Aliases, separated by commas</span><input name="aliases" autocomplete="off"></label>
            <label class="f"><span class="lbl">Usually sold by</span><select name="unit"><option value="kg">kg (kilo)</option><option value="bunch">bunch (mkungu)</option><option value="piece">piece (kipande)</option><option value="heap">heap (rundo)</option></select></label>
          </div>
          <button class="btn quiet" type="submit">Save product</button>
        </form>
      </section>
    </div>`;
}

const SAMPLES = [
  { what: 'A clean order', note: 'English and Swahili mixed, with a place and a time', text: 'Hi Njeri, nahitaji:\n2 kg nyanya\nsukuma wiki 3 bunch\nvitunguu nusu kilo\nndizi 6\nPlease deliver to Kasarani, Mwiki Rd saa 6 jioni\n0712 000 321' },
  { what: 'Spoken numbers', note: 'kilo moja na nusu, kitunguu saumu 1 rundo', text: 'mambo mama, naomba sukuma 2, nyanya kilo moja na nusu, kitunguu saumu 1 rundo na dhania 2. Asante sana' },
  { what: 'Ambiguous: viazi and pilipili', note: 'Two items fit each word, so a person must pick', text: 'Hello, viazi 2 kg and tomatoes 1kg, kabeji 1.\npilipili 2 kg\nsend to Roysambu' },
  { what: 'Not on the list, and a typo', note: 'maziwa is not sold here; "karot" is a spelling slip', text: 'sukuma 2\nnyanya 1 kg\nsukuma 3\nkarot 1 kg\nmatoke 2 heap\nmaziwa 2 litres\nsend to Umoja 1 saa 5 asubuhi' },
  { what: 'Wrong unit', note: 'Spinach is sold by the bunch, not the kilo', text: 'morning! 3 sukuma, 2 kg nyanya, spinach kg 1, mchicha 2 bunch, managu 2 bunch\nthanks' },
];

const STATUS_WHY = {
  matched: null,
  suggested: 'Only part of the name matched. Confirm the item.',
  ambiguous: 'More than one item fits. Pick the right one.',
  unmatched: 'Nothing on today\'s list fits. Pick an item, or leave the line out.',
};

function viewMessages() {
  const s = S.snap;
  const d = S.draft;
  return html`
    <h1>Read a WhatsApp order</h1>
    <p class="lead">Paste what a customer sent. The reader matches it against today's list and marks anything it is not sure of. It never guesses silently.</p>
    <div class="split">
      <section aria-labelledby="paste-h">
        <h2 id="paste-h" style="margin-top:0">The message</h2>
        <form data-form="read" novalidate>
          <label class="f"><span class="lbl">Customer's message</span>
            <textarea id="msg" name="message" rows="7" autocomplete="off" spellcheck="false">${S.msg}</textarea></label>
          <div class="actions" style="margin-top:10px">
            <button class="btn" type="submit">Read the message</button>
            <button class="btn quiet" type="button" data-act="clear-msg">Clear</button>
          </div>
        </form>
      </section>
      <section aria-labelledby="try-h">
        <h2 id="try-h" style="margin-top:0">Try a sample</h2>
        <p class="muted small">Invented messages. Choosing one fills the box and reads it.</p>
        <ul class="samples">${SAMPLES.map((x, i) => html`<li><button type="button" data-act="sample" data-i="${i}"><span class="what">${x.what}</span><span class="peek">${x.note}</span></button></li>`)}</ul>
        <details><summary>Words the reader knows today</summary>
          <p class="small muted">Names and aliases of items on today's list.</p>
          <ul class="small" style="padding-left:1.1rem;margin:0">${s.board.map((e) => html`<li>${e.nameEn}, ${e.nameSw}${e.aliases.length ? html` <span class="muted">(${e.aliases.join(', ')})</span>` : ''}</li>`)}</ul>
        </details>
      </section>
    </div>
    ${d ? viewDraft() : ''}`;
}

function viewDraft() {
  const s = S.snap;
  const d = S.draft;
  const c = d.customer;
  const active = d.lines.filter((l) => !l.drop);
  const toCheck = active.filter((l) => l.review && !l.edited).length;
  const noItem = active.filter((l) => !l.entry).length;
  const slotsOpen = s.slots.filter((x) => x.open);
  return html`
    <section aria-labelledby="read-h" style="margin-top:36px" id="draft">
      <h2 id="read-h" tabindex="-1">What was read</h2>
      ${d.lines.length === 0 ? html`<p class="state"><strong>No items found.</strong> Nothing in that message matched today's list. Check the words, or ask the customer to resend.</p>` : html`
        <p id="draft-summary" role="status">${plural(d.lines.length, 'line', 'lines')} read${toCheck ? `, ${toCheck} to check` : ', all clear'}${noItem ? `. ${plural(noItem, 'line has', 'lines have')} no item and will not be saved` : ''}.</p>`}
      ${d.hints.phone || d.hints.address || d.hints.time ? html`<dl class="found">
        ${d.hints.phone ? html`<div><dt>Phone</dt><dd class="num">${d.hints.phone}</dd></div>` : ''}
        ${d.hints.address ? html`<div><dt>Place</dt><dd>${d.hints.address}</dd></div>` : ''}
        ${d.hints.time ? html`<div><dt>Time asked for</dt><dd>${d.hints.time}</dd></div>` : ''}</dl>` : ''}
      ${d.lines.length ? html`
      <table class="table stack">
        <caption class="sr">Lines read from the message</caption>
        <thead><tr><th scope="col">Customer wrote</th><th scope="col">Item</th><th scope="col">Quantity</th><th scope="col" class="r">Amount</th><th scope="col">Leave out</th></tr></thead>
        <tbody>${d.lines.map((l, i) => lineRow(l, i))}</tbody>
        <tfoot><tr><td colspan="3">Total</td><td class="r num" id="draft-total">${kes(d.total ?? 0)}</td><td></td></tr></tfoot>
      </table>
      <div class="field-holder" data-field="items"></div>` : ''}
      ${d.ignored.length ? html`<p class="small muted" style="margin:12px 0 4px">These lines had no item on them, so they were not read as order lines:</p><ul class="chatter">${d.ignored.map((t) => html`<li>${t}</li>`)}</ul>` : ''}
    </section>
    ${d.lines.length ? html`
    <section aria-labelledby="cust-h" style="margin-top:36px">
      <h2 id="cust-h">Customer and delivery</h2>
      <form data-form="order" novalidate>
        <div class="fields">
          <label class="f"><span class="lbl">Name</span><input id="c-name" name="name" autocomplete="off" value="${c.name}" data-c="name"></label>
          <label class="f"><span class="lbl">Phone</span><input id="c-phone" name="phone" type="tel" inputmode="tel" autocomplete="off" value="${c.phone}" placeholder="0700 000 001" data-c="phone"></label>
          <label class="f"><span class="lbl">Or use a customer who has deni</span>
            <select id="c-known" data-act-change="known"><option value="">Choose a customer</option>${s.customers.filter((x) => x.limit > 0).map((x) => html`<option value="${x.id}">${x.name}, room ${kes(x.available)}</option>`)}</select></label>
        </div>
        <fieldset><legend>How it gets to the customer</legend>
          <label class="choice"><input type="radio" id="c-fulfilment-delivery" name="fulfilment" value="delivery" data-c="fulfilment"${c.fulfilment === 'delivery' ? raw(' checked') : ''}> Delivery</label>
          <label class="choice"><input type="radio" id="c-fulfilment-pickup" name="fulfilment" value="pickup" data-c="fulfilment"${c.fulfilment === 'pickup' ? raw(' checked') : ''}> Pickup</label>
        </fieldset>
        ${c.fulfilment === 'delivery' ? html`
        <div class="fields">
          <label class="f"><span class="lbl">Delivery time</span>
            <select name="slot" data-c="slot"><option value="">Choose a time</option>${s.slots.map((x) => html`<option value="${x.id}"${x.open ? '' : raw(' disabled')}${String(x.id) === String(c.slot) ? raw(' selected') : ''}>${x.label}, ${x.open ? x.left + ' left' : 'closed'}</option>`)}</select>
            ${d.hints.time ? html`<span class="hint">The customer asked for: ${d.hints.time}</span>` : ''}</label>
          <label class="f"><span class="lbl">Address or landmark</span><input name="address" autocomplete="off" value="${c.address}" data-c="address"></label>
        </div>` : ''}
        <fieldset><legend>Payment</legend>
          <label class="choice"><input type="radio" id="c-payment-pod" name="payment" value="pay_on_delivery" data-c="payment"${c.payment !== 'deni' ? raw(' checked') : ''}> Pay on delivery</label>
          <label class="choice"><input type="radio" id="c-payment-deni" name="payment" value="deni" data-c="payment"${c.payment === 'deni' ? raw(' checked') : ''}> Put it on deni</label>
          <span class="hint">Deni is for customers with a credit limit, and the order must fit inside it.</span>
        </fieldset>
        <button class="btn" type="submit">Save order</button>
      </form>
    </section>` : ''}`;
}

function lineRow(l, i) {
  const s = S.snap;
  const needs = l.review && !l.edited && !l.drop;
  const entry = s.board.find((e) => e.entryId === Number(l.entry));
  const alts = l.alternatives.map((id) => s.board.find((e) => e.entryId === id)).filter(Boolean);
  const why = [];
  if (STATUS_WHY[l.status]) why.push(STATUS_WHY[l.status]);
  for (const issue of l.issues) if (issue.blocking || issue.code === 'fuzzy_match' || issue.code === 'merged_duplicates') why.push(issue.message);
  if (alts.length && l.status === 'ambiguous') why.push(`Could be: ${alts.map((a) => a.nameEn).join(' or ')}.`);
  const option = (e) => html`<option value="${e.entryId}"${String(e.entryId) === String(l.entry) ? raw(' selected') : ''}>${e.nameEn} / ${e.nameSw}, ${kes(e.price)} per ${e.unit}</option>`;
  const rest = s.board.filter((e) => !alts.includes(e));
  return html`
    <tr class="${needs ? 'is-flag' : ''}" id="ln-${i}">
      <td data-label="Customer wrote"><span class="mono">${l.fragment}</span>
        ${needs ? html`<div><span class="tag">Check</span></div>` : l.edited ? html`<div><span class="tag ok">Edited</span></div>` : ''}
        ${why.length ? html`<ul class="why">${why.map((w) => html`<li>${w}</li>`)}</ul>` : ''}</td>
      <td data-label="Item"><select id="ln-${i}-entry" data-line="${i}" data-field-name="entry" aria-label="Item for line ${i + 1}, ${l.fragment}"${l.drop ? raw(' disabled') : ''}>
        <option value="">Choose an item</option>
        ${alts.length ? html`<optgroup label="Possible matches">${alts.map(option)}</optgroup><optgroup label="All items">${rest.map(option)}</optgroup>` : s.board.map(option)}
      </select></td>
      <td data-label="Quantity"><div class="qty"><input id="ln-${i}-qty" class="num" data-line="${i}" data-field-name="qty" inputmode="decimal" autocomplete="off" value="${l.qty}" aria-label="Quantity for line ${i + 1}, ${l.fragment}"${l.drop ? raw(' disabled') : ''}><span class="unit" id="ln-${i}-unit">${entry ? entry.unit : ''}</span></div>
        <p class="err" id="ln-${i}-problem" ${l.problem ? '' : raw('hidden')}>${l.problem ?? ''}</p></td>
      <td data-label="Amount" class="r num" id="ln-${i}-amount">${l.drop || l.amount == null ? '–' : kes(l.amount)}</td>
      <td data-label="Leave out"><label class="choice"><input type="checkbox" data-line="${i}" data-field-name="drop"${l.drop ? raw(' checked') : ''} aria-label="Leave out line ${i + 1}, ${l.fragment}"> Leave out</label></td>
    </tr>`;
}

function viewOrders() {
  const s = S.snap;
  const counts = {};
  for (const o of s.orders) counts[o.status] = (counts[o.status] || 0) + 1;
  const lines = (o) => o.lines.map((l) => `${l.quantity} ${l.unit} ${l.nameEn}`).join(', ');
  return html`
    <h1>Orders</h1>
    <p class="lead">${longDate(s.today)}. Move each order along. Delivering one that was put on deni charges the customer's book.</p>
    <p class="muted">${plural(s.orders.length, 'order', 'orders')}: ${['received', 'packing', 'out', 'delivered', 'cancelled'].filter((k) => counts[k]).map((k) => `${counts[k]} ${k}`).join(', ') || 'none yet'}.</p>
    ${s.orders.length === 0 ? html`<p class="state">No orders yet today. <a href="#/messages">Read a WhatsApp message</a> to make one.</p>` : html`
    <table class="table stack">
      <caption class="sr">Today's orders</caption>
      <thead><tr><th scope="col">Order</th><th scope="col">Customer</th><th scope="col">Items</th><th scope="col" class="r">Total</th><th scope="col">Payment</th><th scope="col">Status</th><th scope="col">Next</th></tr></thead>
      <tbody>${[...s.orders].reverse().map((o) => html`
        <tr id="order-${o.id}">
          <td data-label="Order"><span class="mono">${o.reference}</span>${o.reference === S.lastRef ? html`<div><span class="tag ok">New</span></div>` : ''}<div class="small muted">${o.source === 'whatsapp' ? 'WhatsApp' : 'Web menu'}</div></td>
          <td data-label="Customer">${o.customer}<div class="small muted num">${o.phone}</div><div class="small muted">${o.fulfilment === 'pickup' ? 'Pickup' : [o.slot, o.address].filter(Boolean).join(', ')}</div></td>
          <td data-label="Items" class="small">${lines(o)}</td>
          <td data-label="Total" class="r num">${kes(o.total)}</td>
          <td data-label="Payment">${o.payment === 'deni' ? 'Deni' : 'Pay on delivery'}${o.settledVia ? html`<div class="small muted">settled: ${o.settledVia}</div>` : ''}</td>
          <td data-label="Status"><strong>${o.statusLabel}</strong></td>
          <td data-label="Next"><div class="actions">${orderActions(o)}</div></td>
        </tr>`)}</tbody>
    </table>`}`;
}

function orderActions(o) {
  if (o.next.length === 0) return html`<span class="muted">Finished</span>`;
  const buttons = [];
  for (const n of o.next) {
    if (n.value === 'cancelled') continue;
    if (n.value === 'delivered' && o.payment !== 'deni') {
      buttons.push(html`<button class="btn sm" type="button" data-act="status" data-id="${o.id}" data-to="delivered" data-via="cash" aria-label="${n.label}, paid cash, ${o.reference}">${n.label}, cash</button>`);
      buttons.push(html`<button class="btn sm" type="button" data-act="status" data-id="${o.id}" data-to="delivered" data-via="mpesa" aria-label="${n.label}, paid M-Pesa, ${o.reference}">${n.label}, M-Pesa</button>`);
    } else {
      const label = n.value === 'delivered' ? `${n.label}, charge to deni` : `Mark ${n.label.toLowerCase()}`;
      buttons.push(html`<button class="btn sm" type="button" data-act="status" data-id="${o.id}" data-to="${n.value}" aria-label="${label}, ${o.reference}">${label}</button>`);
    }
  }
  buttons.push(html`<button class="btn sm danger" type="button" data-act="status" data-id="${o.id}" data-to="cancelled" aria-label="Cancel ${o.reference}">Cancel</button>`);
  return buttons;
}

function viewMarket() {
  const s = S.snap;
  const done = s.shopping.filter((l) => l.bought).length;
  const waiting = s.orders.filter((o) => o.status === 'received' || o.status === 'packing').length;
  return html`
    <h1>Market list</h1>
    <p class="lead">What to buy at the market this morning: every order still waiting to be packed, added up per item.</p>
    ${s.shopping.length === 0 ? html`<p class="state"><strong>Nothing to buy.</strong> No order is waiting to be packed. <a href="#/messages">Read a WhatsApp message</a> to add one.</p>` : html`
    <p class="muted" id="market-progress">${plural(s.shopping.length, 'line', 'lines')} from ${plural(waiting, 'order', 'orders')}. ${done} of ${s.shopping.length} bought.</p>
    <table class="table stack">
      <caption class="sr">Items to buy</caption>
      <thead><tr><th scope="col">Bought</th><th scope="col">Item</th><th scope="col" class="r">Buy</th><th scope="col" class="r">In orders</th></tr></thead>
      <tbody>${s.shopping.map((l) => html`
        <tr class="${l.bought ? 'is-done' : ''}">
          <td data-label="Bought"><label class="choice"><input type="checkbox" data-act-change="bought" data-item="${l.itemId}" data-unit="${l.unit}"${l.bought ? raw(' checked') : ''} aria-label="Bought ${l.nameEn}"> Bought</label></td>
          <td data-label="Item"><span class="done-text"><strong>${l.nameEn}</strong> <span class="sw">${l.nameSw}</span></span></td>
          <td data-label="Buy" class="r num"><strong>${l.quantity}</strong> ${l.unit} <span class="muted">(${UNIT_SW[l.unit]})</span></td>
          <td data-label="In orders" class="r num">${l.orders}</td>
        </tr>`)}</tbody>
    </table>
    <p class="small muted" style="margin-top:12px">Orders already out for delivery, delivered or cancelled are left out: they were bought earlier or never will be. Mark an order as packing or out on the Orders page to see it drop off the list.</p>`}`;
}

function viewDeni() {
  const s = S.snap;
  if (S.param && S.detail) return viewCustomer();
  const holding = s.customers.filter((c) => c.balance > 0 || c.limit > 0);
  const total = s.customers.reduce((a, c) => a + c.balance, 0);
  return html`
    <h1>Deni book</h1>
    <p class="lead">Who owes what. The book only ever adds rows: a payment or a credit note is a new row, never an edit.</p>
    <p class="muted">Total owed: <strong class="num">${kes(total)}</strong> across ${plural(s.customers.filter((c) => c.balance > 0).length, 'customer', 'customers')}.</p>
    ${needsYouBlock(s.needsYou)}
    <table class="table stack">
      <caption class="sr">Customers with deni</caption>
      <thead><tr><th scope="col">Customer</th><th scope="col" class="r">Owes</th><th scope="col" class="r">Credit limit</th><th scope="col" class="r">Room left</th><th scope="col">Oldest charge</th></tr></thead>
      <tbody>${holding.sort((a, b) => b.balance - a.balance).map((c) => html`
        <tr>
          <td data-label="Customer"><a href="#/deni/${c.id}">${c.name}</a><div class="small muted num">${c.phone}</div></td>
          <td data-label="Owes" class="r num">${kes(c.balance)}</td>
          <td data-label="Credit limit" class="r num">${kes(c.limit)}</td>
          <td data-label="Room left" class="r num">${kes(c.available)}</td>
          <td data-label="Oldest charge">${c.oldest ? c.oldest + ' days' : 'Nothing owed'}</td>
        </tr>`)}</tbody>
    </table>
    <h2>Add a customer</h2>
    <form data-form="customer" novalidate>
      <div class="fields">
        <label class="f"><span class="lbl">Name</span><input name="name" autocomplete="off"></label>
        <label class="f"><span class="lbl">Phone</span><input name="phone" type="tel" inputmode="tel" autocomplete="off" placeholder="0700 000 001"></label>
        <label class="f"><span class="lbl">Credit limit (KSh)</span><input name="limit" inputmode="numeric" autocomplete="off" value="0"><span class="hint">0 means no deni.</span></label>
      </div>
      <button class="btn" type="submit">Add customer</button>
    </form>`;
}

function viewCustomer() {
  const d = S.detail;
  const a = d.aging;
  return html`
    <p><a href="#/deni">All customers</a></p>
    <h1>${d.name}</h1>
    <p class="muted num">${d.phone}</p>
    <div class="split">
      <section aria-labelledby="stmt-h">
        <p class="big num">${kes(d.balance)} <span class="small muted" style="font-family:var(--text);font-weight:400">owed</span></p>
        <dl class="dl">
          <dt>Credit limit</dt><dd class="num">${kes(d.limit)}</dd>
          <dt>Room left</dt><dd class="num">${kes(d.available)}</dd>
          <dt>Deni orders not yet delivered</dt><dd class="num">${kes(d.pendingDeni)}</dd>
        </dl>
        <div class="aging" role="group" aria-label="What is owed, by age of the charge">
          <div><b>0 to 7 days</b><span class="num">${kes(a.current)}</span></div>
          <div><b>8 to 14 days</b><span class="num">${kes(a.days8to14)}</span></div>
          <div><b>15 to 30 days</b><span class="num">${kes(a.days15to30)}</span></div>
          <div><b>Over 30 days</b><span class="num">${kes(a.over30)}</span></div>
        </div>
        <h2 id="stmt-h">Statement</h2>
        ${d.rows.length === 0 ? html`<p class="muted">Nothing has been written in this customer's book yet.</p>` : html`
        <table class="table stack">
          <caption class="sr">Every row in the book, oldest first</caption>
          <thead><tr><th scope="col">Date</th><th scope="col">What</th><th scope="col" class="r">Amount</th><th scope="col" class="r">Balance</th></tr></thead>
          <tbody>${[...d.rows].reverse().map((r) => html`
            <tr>
              <td data-label="Date" class="num">${r.at}</td>
              <td data-label="What"><strong>${r.label}</strong>${r.method ? html` <span class="muted">${r.method === 'mpesa' ? 'M-Pesa' : 'cash'}</span>` : ''}${r.receipt ? html`<div class="small mono">${r.receipt}</div>` : ''}${r.note ? html`<div class="small muted">${r.note}</div>` : ''}</td>
              <td data-label="Amount" class="r num">${r.signed < 0 ? '−' : '+'}${kes(r.amount)}</td>
              <td data-label="Balance" class="r num">${kes(r.balance)}</td>
            </tr>`)}</tbody>
        </table>`}
      </section>
      <section aria-label="Write in the book">
        ${collectSection(d)}
        <h2>Record a payment</h2>
        <form data-form="payment" data-customer="${d.id}" novalidate>
          <div class="fields">
            <label class="f"><span class="lbl">Amount (KSh)</span><input name="amount" inputmode="numeric" autocomplete="off"></label>
          </div>
          <fieldset><legend>Paid by</legend>
            <label class="choice"><input type="radio" name="method" value="mpesa" checked> M-Pesa</label>
            <label class="choice"><input type="radio" name="method" value="cash"> Cash</label>
          </fieldset>
          <label class="f"><span class="lbl">M-Pesa code</span><input name="receipt" autocomplete="off" autocapitalize="characters" placeholder="SGH1A2B3C4"><span class="hint">10 letters and digits. Cash gets a numbered receipt instead.</span></label>
          <div style="margin-top:12px"><button class="btn" type="submit">Record payment</button></div>
        </form>
        <h2>Add a charge</h2>
        <form data-form="charge" data-customer="${d.id}" novalidate>
          <div class="fields">
            <label class="f"><span class="lbl">Amount (KSh)</span><input name="amount" inputmode="numeric" autocomplete="off"></label>
            <label class="f"><span class="lbl">Note</span><input name="note" autocomplete="off" placeholder="Goods taken on credit"></label>
          </div>
          <button class="btn quiet" type="submit">Add charge</button>
        </form>
        <details><summary>Credit note and credit limit</summary>
          <form data-form="creditnote" data-customer="${d.id}" novalidate style="margin-bottom:18px">
            <div class="fields">
              <label class="f"><span class="lbl">Credit note amount (KSh)</span><input name="amount" inputmode="numeric" autocomplete="off"></label>
              <label class="f"><span class="lbl">Reason</span><input name="note" autocomplete="off" placeholder="Returned wilted kale"></label>
            </div>
            <button class="btn quiet" type="submit">Add credit note</button>
          </form>
          <form data-form="limit" data-customer="${d.id}" novalidate>
            <label class="f"><span class="lbl">Credit limit (KSh)</span><input name="limit" inputmode="numeric" autocomplete="off" value="${d.limit}"></label>
            <div style="margin-top:10px"><button class="btn quiet" type="submit">Save limit</button></div>
          </form>
        </details>
        <h2>Reminder message</h2>
        <fieldset><legend>Language</legend>
          <label class="choice"><input type="radio" name="lang" value="sw" data-act-change="lang"${S.lang === 'sw' ? raw(' checked') : ''}> Swahili</label>
          <label class="choice"><input type="radio" name="lang" value="en" data-act-change="lang"${S.lang === 'en' ? raw(' checked') : ''}> English</label>
        </fieldset>
        <label class="f"><span class="lbl">Message to send on WhatsApp</span><textarea class="reminder" readonly rows="6" id="reminder-text">${S.reminder}</textarea></label>
        <div class="actions" style="margin-top:8px"><button class="btn quiet" type="button" data-act="copy-reminder">Copy message</button><span id="copy-note" class="small muted" role="status"></span></div>
        <p class="small muted">The real app sends nothing itself: it prepares the message and you press send in WhatsApp.</p>
      </section>
    </div>`;
}


// ---- collect by prompt -----------------------------------------------------------------------
// The page, not PHP, talks to the payment gateway. PHP (through the bridge) checks the amount, makes the short
// request id, and runs the app's own reconcile step on whatever the gateway reports.

const MOCK_LIMIT = 1000;
const defaultAmount = (d, provider) => (provider === 'mock' ? Math.min(d.balance, MOCK_LIMIT) : d.balance);
const providerName = (id) => (id === 'sandbox' ? 'Safaricom sandbox' : 'mock gateway');

const SANDBOX_ACK = 'This sends a real prompt to the number I entered. I will use only my own number. Two KES 1 payments have been completed with a PIN on this sandbox, which is a small sample, and whether approving a prompt debits a real wallet has not been checked.';

function needsYouBlock(items) {
  if (!items || items.length === 0) return '';
  return html`
    <section class="needs" aria-labelledby="needs-h">
      <h2 id="needs-h" style="margin-top:0">Prompt payments that need you</h2>
      <p class="small muted">The gateway is not sure about these, so none has been written in the book. Open the customer, check M-Pesa, and record it by hand if the money arrived.</p>
      <ul class="plain">${items.map((p) => html`<li><a href="#/deni/${p.customerId}"><strong>${p.customerName}</strong></a>, ${kes(p.amount)}, <span class="mono">${p.reference}</span><div class="small">${p.detail}</div></li>`)}</ul>
    </section>`;
}

function collectSection(d) {
  const provider = S.collect.provider;
  const sandbox = provider === 'sandbox';
  const live = S.collect.status;
  return html`
    <h2 id="collect-h" style="margin-top:0">Collect by prompt</h2>
    ${d.balance <= 0 ? html`<p class="muted">${d.name} owes nothing, so there is nothing to collect.</p>` : html`
    <p class="muted">Sends an M-Pesa prompt through a payments gateway. The book is only written when the gateway reports the payment succeeded.</p>
    <form data-form="collect" data-customer="${d.id}" novalidate aria-labelledby="collect-h">
      <fieldset><legend>Gateway</legend>
        <label class="choice"><input type="radio" name="provider" value="mock" data-act-change="provider"${!sandbox ? raw(' checked') : ''}> Mock gateway (default)</label>
        <label class="choice"><input type="radio" name="provider" value="sandbox" data-act-change="provider"${sandbox ? raw(' checked') : ''}> Safaricom sandbox</label>
      </fieldset>
      <label class="f"><span class="lbl">Amount (KSh)</span><input id="collect-amount" name="amount" inputmode="numeric" autocomplete="off" value="${defaultAmount(d, provider)}">
        <span class="hint" id="hint-mock"${sandbox ? raw(' hidden') : ''}>Up to what ${d.name} owes, ${kes(d.balance)}. The public demo key takes at most ${kes(MOCK_LIMIT)} in one prompt.</span>
        <span class="hint" id="hint-sandbox"${!sandbox ? raw(' hidden') : ''}>Up to what ${d.name} owes, ${kes(d.balance)}.</span></label>
      <div id="mock-panel"${sandbox ? raw(' hidden') : ''}>
        <label class="f"><span class="lbl">What the customer does</span><select name="scenario" id="collect-scenario">${MOCK_SCENARIOS.map(([v, t]) => html`<option value="${v}">${t}</option>`)}</select>
          <span class="hint">The mock gateway plays this out. It sends the prompt to ${d.name}'s invented number ${d.phone}, which is never contacted, and no real money moves.</span></label>
      </div>
      <div id="sandbox-panel"${!sandbox ? raw(' hidden') : ''}>
        <p class="notice"><strong>KES 1 stands in for the amount.</strong> The sandbox key is capped at KSh 100, so the prompt asks for a flat KES 1 test charge, and the book entry is marked as a sandbox test. This sends a real prompt to the phone number you enter.</p>
        <label class="f"><span class="lbl">Your own phone number</span><input name="phone" type="tel" inputmode="tel" autocomplete="off" placeholder="0700 000 000"><span class="hint">Use only your own number. It is sent to the sandbox gateway and is not kept in this demo.</span></label>
        <label class="choice ack"><input type="checkbox" name="ack" value="1"> <span>${SANDBOX_ACK}</span></label>
      </div>
      <div style="margin-top:12px"><button class="btn" type="submit" id="collect-send">Send M-Pesa prompt</button></div>
    </form>`}
    <div id="collect-live" class="live${live ? ' ' + live.tone : ''}" role="status" aria-live="polite">${live ? html`<p>${live.text}</p>` : ''}</div>
    <p class="small muted" id="collect-elapsed" aria-hidden="true"></p>
    ${d.prompts.length ? html`
      <h3 id="prompts-h">Prompts sent</h3>
      <ul class="plain prompts" aria-labelledby="prompts-h">${d.prompts.map((p) => promptItem(p))}</ul>` : ''}`;
}

function promptItem(p) {
  const sandboxTest = p.mode === 'sandbox';
  return html`
    <li id="prompt-${p.id}">
      <div><strong class="mono">${p.reference}</strong>, ${kes(p.amount)}${sandboxTest ? ' (KES 1 test charge)' : ''}, ${p.at}, ${providerName(p.mode)}</div>
      <div><strong class="state-${p.state}">${p.label}.</strong> <span class="small">${p.detail}</span></div>
      <div class="actions">
        ${p.checkable && p.sent ? html`<button class="btn quiet sm" type="button" data-act="prompt-check" data-id="${p.id}" aria-label="Check status of ${p.reference}">Check status</button>` : ''}
        ${!p.sent && p.status === 'pending' && p.mode === 'mock' ? html`<button class="btn quiet sm" type="button" data-act="prompt-resend" data-id="${p.id}" aria-label="Send ${p.reference} again">Send again</button>` : ''}
        ${!p.sent && p.status === 'pending' ? html`<button class="btn quiet sm" type="button" data-act="prompt-discard" data-id="${p.id}" aria-label="Discard ${p.reference}">Discard</button>` : ''}
      </div>
      ${p.needsOwner ? html`
        <form data-form="collect-review" data-id="${p.id}" novalidate>
          <label class="f"><span class="lbl">Note <span class="hint">optional, for example what you found in M-Pesa</span></span><input name="note" autocomplete="off" maxlength="140"></label>
          <div style="margin-top:8px"><button class="btn quiet sm" type="submit">I have looked at this</button></div>
          <p class="small muted">This only marks it seen. To put the money in the book, use Record a payment with the M-Pesa code.</p>
        </form>` : ''}
    </li>`;
}

function setLive(text, tone = '') {
  S.collect.status = text ? { text, tone } : null;
  const el = $('#collect-live');
  if (el) {
    el.className = 'live' + (tone ? ' ' + tone : '');
    el.textContent = '';
    if (text) { const p = document.createElement('p'); p.textContent = text; el.append(p); }
  }
}

function stopWatch() {
  const w = S.collect.watch;
  if (!w) return;
  w.ctrl.abort();
  clearInterval(w.timer);
  S.collect.watch = null;
  const el = $('#collect-elapsed');
  if (el) el.textContent = '';
}

function livePhaseText(mode, payment, info) {
  if (info.waitSec) return `The gateway asked us to slow down. Checking again in ${info.waitSec} seconds.`;
  if (info.error) return 'Could not read the gateway just now. Still waiting, and trying again.';
  if (info.confirming) {
    return mode === 'sandbox'
      ? 'Confirming. The gateway has the reply from the phone and is checking it with Safaricom, which can take up to 30 seconds. It is not paid, and nothing is in the book, until the gateway says succeeded.'
      : 'Confirming. The gateway has the (simulated) reply and is checking it, which takes a few seconds. It is not paid, and nothing is in the book, until the gateway says succeeded.';
  }
  return mode === 'sandbox'
    ? 'Check your phone for an M-Pesa prompt and enter your PIN to approve it, or cancel it. This can take up to 2.5 minutes.'
    : 'Prompt sent to the mock gateway. Waiting for the customer to answer.';
}

/** Poll one prompt's payment, show honest progress, and when the gateway has an answer let PHP apply the rules. */
async function watchPrompt(prompt) {
  stopWatch();
  const provider = PROVIDERS[prompt.mode];
  const client = createClient(provider);
  const ctrl = new AbortController();
  const startedAt = Date.now();
  const timer = setInterval(() => {
    const el = $('#collect-elapsed');
    if (el) el.textContent = `Waiting for ${Math.round((Date.now() - startedAt) / 1000)} s`;
  }, 1000);
  S.collect.watch = { requestId: prompt.id, ctrl, timer };
  setLive(livePhaseText(prompt.mode, null, {}));
  const result = await watchPayment({
    client, id: prompt.gatewayPaymentId, provider, signal: ctrl.signal,
    isHidden: () => document.hidden,
    whenVisible: () => new Promise((resolve) => document.addEventListener('visibilitychange', () => { if (!document.hidden) resolve(); }, { once: true })),
    onUpdate: (payment, info) => { if (!ctrl.signal.aborted && payment?.status !== 'succeeded') setLive(livePhaseText(prompt.mode, payment, info)); },
  });
  if (result.reason === 'aborted') return;
  stopWatch();
  if (result.reason === 'window' || !result.payment) {
    setLive('Still waiting after a few minutes. The request stays open: use Check status in a moment, or come back to this page later.');
    return;
  }
  await applyPayment(prompt, result.payment);
}

/** Hand a payment the gateway reported to PHP's reconcile step, then show what PHP decided. */
async function applyPayment(prompt, payment) {
  const r = await call('collectReconcile', { requestId: prompt.id, payment });
  if (!r.ok) {
    setLive(Object.values(r.errors || {}).join(' ') || r.message, 'warn');
    return;
  }
  const tone = r.data.state === 'recorded' ? 'ok' : r.data.state === 'needs_owner' ? 'warn' : '';
  // Redraw first, then say it: the live region is rebuilt by the redraw, and only text set afterwards is announced.
  await refreshCustomer();
  setLive(`${r.data.label}. ${r.data.detail}`, tone);
}

async function refreshCustomer() {
  if (!(S.view === 'deni' && S.param)) return;
  const snap = await call('snapshot');
  if (snap.ok) S.snap = snap.data;
  const r = await call('customer', { customerId: Number(S.param) });
  if (r.ok) S.detail = r.data;
  const rem = await call('reminder', { customerId: Number(S.param), lang: S.lang });
  if (rem.ok) S.reminder = rem.data.text;
  render();
}

const gatewayMessage = (e, mode) => {
  const name = providerName(mode);
  if (e instanceof GatewayError) {
    const next = mode === 'mock' ? 'Use Send again (the same request id is reused, so it cannot go out twice) or Discard.' : 'Discard it and try again.';
    if (e.kind === 'rate_limited') return `The ${name} is limiting requests from this connection, so nothing was sent. The request is saved as not sent. Wait ${e.retryAfterSec ?? 30} seconds. ${next}`;
    if (e.kind === 'unreachable' || e.kind === 'server') return `Could not reach the ${name}. The request is saved as not sent, and it may or may not have reached the gateway. ${next}`;
    if (e.kind === 'over_cap') return `The ${name} refused this as over the limit for the public demo key. Nothing was sent.`;
    if (e.kind === 'phone_not_approved') return 'The sandbox on this site only accepts phone numbers its owner has approved, and that is not one of them. Nothing was sent.';
    return `The ${name} did not accept the request: ${e.message} Nothing was sent.`;
  }
  return String(e && e.message ? e.message : e);
};

/** Send the prompt for a request PHP has saved. Returns the linked prompt, or null after telling the owner why not. */
async function sendPrompt(prompt, phone, scenario) {
  const client = createClient(PROVIDERS[prompt.mode]);
  setLive(`Sending the prompt to the ${providerName(prompt.mode)}.`);
  let payment;
  try {
    ({ payment } = await client.createPayment(createBody(prompt.mode, prompt, phone, scenario), prompt.idempotencyKey));
  } catch (e) {
    // Rate limits and network faults leave the request saved as not sent (it may or may not have reached the gateway).
    // Anything else means the gateway refused it outright, so the request is closed.
    const keep = e instanceof GatewayError && ['unreachable', 'server', 'rate_limited', 'reference_pending'].includes(e.kind);
    if (!keep) await call('collectDiscard', { requestId: prompt.id, reason: String(e && e.message ? e.message : e) });
    await refreshCustomer();
    setLive(gatewayMessage(e, prompt.mode), 'warn');
    return null;
  }
  const linked = await call('collectSent', { requestId: prompt.id, payment });
  if (!linked.ok) { setLive(Object.values(linked.errors || {}).join(' ') || linked.message, 'warn'); return null; }
  return linked.data;
}

async function submitCollect(form) {
  const v = Object.fromEntries(new FormData(form).entries());
  const mode = S.collect.provider;
  const problems = {};
  if (mode === 'sandbox') {
    if (!String(v.phone || '').trim()) problems.phone = 'Enter your own phone number.';
    if (!v.ack) problems.ack = 'Tick the box to confirm before a real prompt is sent.';
  }
  if (Object.keys(problems).length) { showErrors(form, problems); return; }
  const opened = await call('collectOpen', { customerId: form.dataset.customer, amount: v.amount, mode });
  if (!opened.ok) { showErrors(form, opened.errors, opened.message); return; }
  const prompt = opened.data;
  S.collect.scenario[prompt.id] = v.scenario || 'success';
  const phone = mode === 'sandbox' ? v.phone : S.detail.phone;
  // The sandbox acknowledgement and number are used for this one send and then cleared, never kept.
  if (mode === 'sandbox') { form.elements.ack.checked = false; form.elements.phone.value = ''; }
  const sent = await sendPrompt(prompt, phone, v.scenario);
  if (!sent) return;
  await refreshCustomer();
  await watchPrompt(sent);
}

async function checkPrompt(id) {
  const prompt = S.detail.prompts.find((p) => String(p.id) === String(id));
  if (!prompt || !prompt.gatewayPaymentId) return;
  stopWatch();
  setLive('Asking the gateway.');
  try {
    const payment = await createClient(PROVIDERS[prompt.mode]).getPayment(prompt.gatewayPaymentId);
    if (payment.status === 'pending') {
      const fresh = { ...prompt };
      await watchPrompt(fresh);
    } else {
      await applyPayment(prompt, payment);
    }
  } catch (e) {
    setLive(gatewayMessage(e, prompt.mode), 'warn');
  }
}

/** When the customer page opens, pick up any prompt that was still waiting when the page was last closed. */
async function resumePrompts() {
  const d = S.detail;
  if (!d) return;
  const waiting = d.prompts.find((p) => p.sent && p.status === 'pending' && p.checkable);
  if (waiting && !S.collect.watch) watchPrompt(waiting);
}

function viewHow() {
  return html`
    <h1>How this demo works</h1>
    <p class="lead">This is the real Mama Mboga code running in your browser, not a copy of it. Approach: PHP 8.3 compiled to WebAssembly, loaded from this site's own files.</p>
    <div class="split">
      <div>
        <h2 style="margin-top:0">What is real</h2>
        <ul>
          <li>The WhatsApp message reader, with its English, Swahili and Sheng-ish word lists, number words and flags.</li>
          <li>Price board, stock, order placing (stock and slot checks, credit room), the status flow and the market list.</li>
          <li>The deni ledger and its rules: no overpaying, a credit limit, one use of each receipt code, no back-dated rows, one charge per order.</li>
          <li>The SQLite repositories, the migrations and the sample-shop seeder. The tables really are SQLite, with the real append-only triggers.</li>
          <li>Collecting deni by prompt: the amount check (never above what is owed), the short request id, and the reconcile step that writes the book only for a clean <code>succeeded</code>, once per gateway payment, through the same ledger rules. A payment the gateway holds for review, a late success, or a success whose callback and status check disagree is shown to you and never recorded by itself.</li>
        </ul>
        <p>These are the repository's own files from <code>src/</code> and <code>migrations/</code>, copied into the page unchanged at build time. A test in the repository runs the same bridge on ordinary PHP, and the repository's unit tests cover the code underneath.</p>
        <h2>What is different from the real app</h2>
        <ul>
          <li>The web layer is not used: no HTTP router, sessions, PIN login, CSRF checks or page templates. This page is a different front end, written for the demo, that talks to the same services.</li>
          <li>The database file lives in memory inside your browser, and a copy is kept in your browser's storage so changes survive a reload.</li>
          <li>Two clocks. The shop clock is today's date at 06:30, so delivery slots are open whenever you visit. The deni book uses real time, but never earlier than its newest row.</li>
          <li>Collect by prompt is the one place the browser, not PHP, talks to the gateway. PHP running in WebAssembly has no network, so this page calls the payments gateway, polls it, and hands PHP the payment it read. The real app does it the other way round: PHP calls the gateway with curl and a server-side secret key. This page uses the gateway's public demo key.</li>
          <li>The default gateway is a mock: it plays out the outcome you pick and no phone is contacted. Its receipts start with MOCK and the book entry says it was simulated. An opt-in Safaricom sandbox choice sends a real prompt to a number you enter, behind a tick-box, as a flat KES 1 test charge that stands in for the amount; the entry is marked as a sandbox test.</li>
          <li>Nothing else is sent: no WhatsApp message and no statement link.</li>
        </ul>
        <h2>Not shown here</h2>
        <p>The customer menu and checkout, the PIN login, the delivery run sheet, daily takings, settings, signed statement links, and the installable offline app. Run the project to see them.</p>
      </div>
      <aside class="rule-top">
        <h2 style="margin-top:0">Run the real thing</h2>
        <p>Needs PHP 8.3 or newer with <code>pdo_sqlite</code> and <code>mbstring</code>, and Composer.</p>
        <pre class="mono" style="overflow-x:auto;margin:0 0 12px;padding:10px 0;border-top:1px solid var(--rule);border-bottom:1px solid var(--rule)">git clone https://github.com/Victor-Wambua/mama-mboga.git
cd mama-mboga
composer install
composer seed
composer serve</pre>
        <p>Then open <code>http://127.0.0.1:8080</code>. The owner console is at <code>/owner</code> with the demo PIN <code>246810</code>, which only protects sample data.</p>
        <h2>Size and privacy</h2>
        <p>The PHP runtime is about 18 MB unpacked and about 7 MB over the network. After the first visit your browser keeps it and the demo works offline. Your changes are stored in this browser only. The only thing that leaves it is a prompt you choose to send to the payments gateway: an amount, a short request id and a phone number (an invented one for the mock, the one you type for the sandbox).</p>
        <p class="small muted">The PHP runtime is the WordPress Playground build of PHP, under its own licences. See the <a href="THIRD-PARTY-NOTICES.txt">third-party notices</a>.</p>
        <p class="small muted">${S.boot ? `PHP ${S.boot.phpVersion} started in ${S.boot.startedMs} ms${S.boot.restored ? ', with your earlier changes restored' : ''}.` : ''}</p>
      </aside>
    </div>`;
}

// ---- messages: draft handling ----------------------------------------------------------------

async function readMessage(text) {
  S.msg = text;
  if (!text.trim()) {
    flash('Paste a message first.', false);
    $('#msg')?.focus();
    return;
  }
  flash('');
  const r = await call('parse', { message: text });
  if (!r.ok) { flash(r.message || 'The message could not be read.', false); return; }
  const data = r.data;
  S.draft = {
    lines: data.lines.map((l) => ({ ...l, edited: false, drop: false, amount: l.total, problem: null })),
    ignored: data.ignored,
    hints: { phone: data.phone, address: data.address, time: data.time },
    customer: { name: '', phone: data.phone, address: data.address, fulfilment: 'delivery', slot: '', payment: 'pay_on_delivery' },
    total: 0,
  };
  render();
  await requote();
  $('#read-h')?.focus();
}

let quoteTimer;
function scheduleQuote() {
  clearTimeout(quoteTimer);
  quoteTimer = setTimeout(requote, 120);
}

async function requote() {
  const d = S.draft;
  if (!d) return;
  const mine = ++S.quoteSeq;
  const r = await call('quote', { lines: d.lines.map((l) => ({ entry: l.drop ? '' : l.entry, qty: l.qty })) });
  if (!r.ok || mine !== S.quoteSeq || S.draft !== d) return;
  let total = 0;
  r.data.lines.forEach((q, i) => {
    const l = d.lines[i];
    l.amount = q.total;
    l.problem = q.problem;
    if (!l.drop) total += q.total ?? 0;
    const amount = $(`#ln-${i}-amount`);
    if (amount) amount.textContent = l.drop || q.total == null ? '–' : kes(q.total);
    const prob = $(`#ln-${i}-problem`);
    if (prob) { prob.textContent = q.problem ?? ''; prob.hidden = !q.problem; }
    const qty = $(`#ln-${i}-qty`);
    if (qty) { if (q.problem) { qty.setAttribute('aria-invalid', 'true'); qty.setAttribute('aria-describedby', `ln-${i}-problem`); } else { qty.removeAttribute('aria-invalid'); qty.removeAttribute('aria-describedby'); } }
  });
  d.total = total;
  const t = $('#draft-total');
  if (t) t.textContent = kes(total);
}

function updateSummary() {
  const d = S.draft;
  const active = d.lines.filter((l) => !l.drop);
  const toCheck = active.filter((l) => l.review && !l.edited).length;
  const noItem = active.filter((l) => !l.entry).length;
  const el = $('#draft-summary');
  if (el) el.textContent = `${plural(d.lines.length, 'line', 'lines')} read${toCheck ? `, ${toCheck} to check` : ', all clear'}${noItem ? `. ${plural(noItem, 'line has', 'lines have')} no item and will not be saved` : ''}.`;
}

function onLineChange(el) {
  const l = S.draft.lines[Number(el.dataset.line)];
  const field = el.dataset.fieldName;
  if (field === 'drop') { l.drop = el.checked; l.edited = true; render(); scheduleQuote(); return; }
  if (field === 'entry') {
    l.entry = el.value || null;
    l.edited = true;
    const e = S.snap.board.find((x) => String(x.entryId) === el.value);
    const unit = $(`#ln-${el.dataset.line}-unit`);
    if (unit) unit.textContent = e ? e.unit : '';
  } else if (field === 'qty') {
    l.qty = el.value;
    l.edited = true;
  }
  const row = $(`#ln-${el.dataset.line}`);
  if (row && l.edited) { row.classList.remove('is-flag'); const tag = $('.tag', row); if (tag) { tag.textContent = 'Edited'; tag.classList.add('ok'); } }
  updateSummary();
  scheduleQuote();
}

async function saveOrder(form) {
  const d = S.draft;
  clearErrors(form.closest('main'));
  const r = await call('placeOrder', {
    lines: d.lines.filter((l) => !l.drop).map((l) => ({ entry: l.entry, qty: l.qty })),
    ...d.customer,
  });
  if (!r.ok) {
    const { items, ...rest } = r.errors || {};
    showErrors(form, rest, items ? '' : r.message);
    if (items) showErrors($('#draft'), { items });
    return;
  }
  S.lastRef = r.data.reference;
  S.draft = null;
  S.msg = '';
  S.flashNext = `Order ${r.data.reference} saved. It is now on the orders list and the market list.`;
  location.hash = '#/orders';
}

// ---- events ----------------------------------------------------------------------------------

async function run(action, args, done) {
  const r = await call(action, args);
  if (r.ok) { done?.(r); render(); }
  return r;
}

document.addEventListener('click', async (ev) => {
  const el = ev.target.closest('[data-act]');
  if (!el) return;
  const act = el.dataset.act;
  if (act === 'reset') { const dlg = $('#confirm'); dlg.showModal(); return; }
  if (act === 'sample') { const i = Number(el.dataset.i); S.msg = SAMPLES[i].text; render(); await readMessage(SAMPLES[i].text); return; }
  if (act === 'clear-msg') { S.msg = ''; S.draft = null; render(); $('#msg')?.focus(); return; }
  if (act === 'copy-previous') {
    const r = await run('copyPrevious', {}, () => flash('Added anything that was missing from the last list.'));
    if (!r.ok) flash(r.message || 'Could not copy the list.', false);
    return;
  }
  if (act === 'remove-entry') {
    const r = await run('removeEntry', { entryId: el.dataset.entry }, () => flash('Removed from today\'s list.'));
    if (!r.ok) flash(r.message, false);
    return;
  }
  if (act === 'status') {
    const r = await call('changeStatus', { orderId: el.dataset.id, to: el.dataset.to, settledVia: el.dataset.via || '' });
    if (r.ok) { S.lastRef = null; flash(el.dataset.to === 'delivered' ? 'Delivered.' : el.dataset.to === 'cancelled' ? 'Order cancelled and its stock put back.' : 'Order updated.'); render(); }
    else flash(Object.values(r.errors || {}).join(' ') || r.message, false);
    return;
  }
  if (act === 'prompt-check') { await checkPrompt(el.dataset.id); return; }
  if (act === 'prompt-resend') {
    const prompt = S.detail.prompts.find((p) => String(p.id) === el.dataset.id);
    if (!prompt) return;
    const sent = await sendPrompt(prompt, S.detail.phone, S.collect.scenario[prompt.id] || 'success');
    if (sent) { await refreshCustomer(); await watchPrompt(sent); }
    return;
  }
  if (act === 'prompt-discard') {
    const r = await call('collectDiscard', { requestId: el.dataset.id, reason: 'Discarded by the owner. It was never accepted by the gateway.' });
    if (r.ok) { await refreshCustomer(); setLive('Discarded. Nothing was recorded.'); } else flash(Object.values(r.errors || {}).join(' ') || r.message, false);
    return;
  }
  if (act === 'copy-reminder') {
    const note = $('#copy-note');
    try { await navigator.clipboard.writeText($('#reminder-text').value); note.textContent = 'Copied.'; } catch { note.textContent = 'Could not copy. Select the text and copy it.'; }
  }
});

document.addEventListener('change', async (ev) => {
  const el = ev.target;
  if (el.dataset.line != null && S.draft) { onLineChange(el); return; }
  if (el.dataset.c && S.draft) {
    S.draft.customer[el.dataset.c] = el.value;
    if (el.dataset.c === 'fulfilment' || el.dataset.c === 'payment') render();
    return;
  }
  const kind = el.dataset.actChange;
  if (kind === 'known') {
    const c = S.snap.customers.find((x) => String(x.id) === el.value);
    if (c && S.draft) { S.draft.customer.name = c.name; S.draft.customer.phone = c.phone; S.draft.customer.payment = 'deni'; render(); }
  } else if (kind === 'bought') {
    const r = await call('markBought', { itemId: el.dataset.item, unit: el.dataset.unit, bought: el.checked });
    if (r.ok) render();
  } else if (kind === 'provider') {
    S.collect.provider = el.value;
    saveProvider(el.value);
    const sandbox = el.value === 'sandbox';
    for (const [id, show] of [['mock-panel', !sandbox], ['hint-mock', !sandbox], ['sandbox-panel', sandbox], ['hint-sandbox', sandbox]]) { const n = document.getElementById(id); if (n) n.hidden = !show; }
    const amount = $('#collect-amount');
    if (amount && S.detail) amount.value = defaultAmount(S.detail, el.value);
    if (!sandbox) { const form = el.form; if (form) { form.elements.ack.checked = false; form.elements.phone.value = ''; clearErrors(form); } }
  } else if (kind === 'lang') {
    S.lang = el.value;
    const rem = await call('reminder', { customerId: S.detail.id, lang: S.lang });
    if (rem.ok) { S.reminder = rem.data.text; const t = $('#reminder-text'); if (t) t.value = S.reminder; }
  } else if (el.matches('form[data-form="add"] select[name="itemId"]')) {
    /* nothing: unit comes from the item */
  }
});

document.addEventListener('input', (ev) => {
  const el = ev.target;
  if (el.dataset.line != null && S.draft && el.dataset.fieldName === 'qty') { onLineChange(el); return; }
  if (el.dataset.c && S.draft && el.type !== 'radio') S.draft.customer[el.dataset.c] = el.value;
  if (el.id === 'msg') S.msg = el.value;
  if (el.getAttribute('aria-invalid') === 'true') {
    const err = document.getElementById(el.getAttribute('aria-describedby') || '');
    if (err?.dataset.err) err.remove();
    el.removeAttribute('aria-invalid');
    el.removeAttribute('aria-describedby');
  }
});

document.addEventListener('submit', async (ev) => {
  const form = ev.target.closest('form[data-form]');
  if (!form) return;
  ev.preventDefault();
  const kind = form.dataset.form;
  const f = new FormData(form);
  const v = Object.fromEntries(f.entries());
  clearErrors(form);

  if (kind === 'read') { await readMessage(v.message || ''); return; }
  if (kind === 'order') { await saveOrder(form); return; }
  if (kind === 'collect') { await submitCollect(form); return; }
  if (kind === 'collect-review') {
    const r = await call('collectReview', { requestId: form.dataset.id, note: v.note || '' });
    if (r.ok) { flash('Marked as looked at. The book was not changed.'); await refreshCustomer(); } else showErrors(form, r.errors, r.message);
    return;
  }

  const spec = {
    entry: () => ['setEntry', { itemId: form.dataset.item, unit: v.unit, price: v.price, available: v.available, special: form.elements.special.checked, note: v.note }, `Saved ${$('strong', form).textContent}.`],
    add: () => ['addToBoard', { itemId: v.itemId, price: v.price, available: v.available }, 'Added to today\'s list.'],
    newitem: () => ['saveItem', { nameEn: v.name_en, nameSw: v.name_sw, aliases: v.aliases, unit: v.unit }, 'Product saved. Add it to today\'s list with a price.'],
    customer: () => ['addCustomer', v, 'Customer added.'],
    payment: () => ['payment', { ...v, customerId: form.dataset.customer }, 'Payment recorded.'],
    charge: () => ['charge', { ...v, customerId: form.dataset.customer }, 'Charge added.'],
    creditnote: () => ['creditNote', { ...v, customerId: form.dataset.customer }, 'Credit note added.'],
    limit: () => ['setLimit', { ...v, customerId: form.dataset.customer }, 'Credit limit saved.'],
  }[kind];
  if (!spec) return;
  const [action, args, message] = spec();
  const r = await call(action, args);
  if (!r.ok) {
    showErrors(form, r.errors, r.message);
    return;
  }
  if (S.view === 'deni' && S.param) await show({ focusHeading: false });
  else render();
  flash(message);
});

$('#confirm').addEventListener('close', async () => {
  if ($('#confirm').returnValue !== 'reset') return;
  S.draft = null;
  S.msg = '';
  const r = await ask('reset');
  if (r.ok) {
    S.snap = r.data;
    S.flashNext = 'The demo is back to its starting data.';
    if (location.hash === '#/start') await show({ focusHeading: true });
    else location.hash = '#/start';
  }
});

window.addEventListener('hashchange', () => show());

// ---- start ------------------------------------------------------------------------------------

async function boot() {
  render();
  try {
    startWorker();
    S.boot = await ask('init');
    const r = await ask('snapshot');
    if (!r.ok) throw new Error(r.message || 'The first request to PHP failed.');
    S.snap = r.data;
  } catch (error) {
    S.bootError = String(error.message || error);
    render();
    return;
  }
  await show({ focusHeading: false });
  document.body.dataset.ready = '1';
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => { /* offline copy is optional */ });
}

boot();
