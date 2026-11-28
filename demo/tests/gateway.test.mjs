// Unit tests for the browser's gateway client and poller. No network: fetch is a stub. Run with `node --test tests/gateway.test.mjs`.
import test from 'node:test';
import assert from 'node:assert/strict';
import { createClient, createBody, watchPayment, parseRetryAfter, GatewayError, PROVIDERS, DEMO_KEY, STOP_STATES } from '../web/gateway.js';

const reply = (status, body, headers = {}) => ({ ok: status >= 200 && status < 300, status, headers: { get: (k) => headers[k] ?? headers[k.toLowerCase()] ?? null }, json: async () => body });
const payment = (status, extra = {}) => ({ id: 'pay_1', reference: 'DENI-ABCDEF', amount: 300, status, flags: [], ...extra });

function stubFetch(handler) {
  const calls = [];
  const fetch = async (url, init) => { calls.push({ url, init }); return handler(url, init, calls.length); };
  return { fetch, calls };
}

test('the demo key and hosts are the public ones, and the sandbox is a different host from the mock', () => {
  assert.equal(DEMO_KEY, 'pk_sandbox_duka_demo_4f9c2a7e');
  assert.equal(PROVIDERS.mock.url, 'https://mpesa-gateway.victorwambua004.workers.dev');
  assert.equal(PROVIDERS.sandbox.url, 'https://gateway-sbx.victorwambua004.workers.dev');
  assert.ok(PROVIDERS.sandbox.windowMs > PROVIDERS.mock.windowMs);
});

test('the mock body carries the scenario, the sandbox body never does and is a flat KES 1', () => {
  const prompt = { charge: 300, reference: 'DENI-ABCDEF' };
  assert.deepEqual(createBody('mock', prompt, '0700 000 010', 'wrong_pin'), { amount: 300, phone: '0700000010', reference: 'DENI-ABCDEF', description: 'Deni payment', scenario: 'wrong_pin' });
  assert.equal(createBody('mock', prompt, '0700000010').scenario, 'success');
  const sandbox = createBody('sandbox', { charge: 1, reference: 'DENI-ABCDEF' }, '0711 222 333', 'success');
  assert.equal('scenario' in sandbox, false);
  assert.equal(sandbox.amount, 1);
});

test('createPayment sends the key, the idempotency key and the body, and flags a replay', async () => {
  const { fetch, calls } = stubFetch(() => reply(200, { data: payment('pending') }));
  const client = createClient(PROVIDERS.mock, { fetch });
  const r = await client.createPayment({ amount: 300 }, 'mm-0123456789ab');
  assert.equal(calls[0].url, 'https://mpesa-gateway.victorwambua004.workers.dev/v1/payments');
  assert.equal(calls[0].init.method, 'POST');
  assert.equal(calls[0].init.headers.Authorization, `Bearer ${DEMO_KEY}`);
  assert.equal(calls[0].init.headers['Idempotency-Key'], 'mm-0123456789ab');
  assert.deepEqual(JSON.parse(calls[0].init.body), { amount: 300 });
  assert.equal(r.replayed, true);
  assert.equal(r.payment.id, 'pay_1');
});

test('gateway errors are named: rate limit with Retry-After, cap, rejection, not found, unreachable', async () => {
  const kinds = async (status, body, headers) => {
    const { fetch } = stubFetch(() => reply(status, body, headers));
    return createClient(PROVIDERS.mock, { fetch }).getPayment('pay_1').catch((e) => e);
  };
  const limited = await kinds(429, { error: { code: 'rate_limited', message: 'slow' } }, { 'Retry-After': '17' });
  assert.equal(limited.kind, 'rate_limited');
  assert.equal(limited.retryAfterSec, 17);
  assert.equal((await kinds(422, { error: { code: 'amount_exceeds_key_cap', message: 'cap' } })).kind, 'over_cap');
  assert.equal((await kinds(422, { error: { code: 'validation_error', message: 'bad phone' } })).kind, 'rejected');
  assert.equal((await kinds(404, { error: { code: 'not_found', message: 'none' } })).kind, 'not_found');
  assert.equal((await kinds(500, null)).kind, 'server');
  const down = await createClient(PROVIDERS.mock, { fetch: async () => { throw new TypeError('network'); } }).getPayment('pay_1').catch((e) => e);
  assert.equal(down.kind, 'unreachable');
  assert.ok(down instanceof GatewayError);
});

test('Retry-After accepts seconds and dates and is clamped', () => {
  assert.equal(parseRetryAfter('12'), 12);
  assert.equal(parseRetryAfter('99999'), 600);
  assert.equal(parseRetryAfter(null), null);
  assert.equal(parseRetryAfter(new Date(1_000_000 + 8_000).toUTCString(), 1_000_000), 8);
});

// A clock the tests control, so back-off and the window can be checked without waiting.
function clock() {
  let t = 0;
  const waits = [];
  return { now: () => t, sleep: async (ms) => { waits.push(ms); t += ms; }, waits, advance: (ms) => { t += ms; } };
}

test('polling backs off 1.5 s, 1.5 s, 2 s, 3 s, 4 s, 5 s and stops at a final state', async () => {
  const c = clock();
  let n = 0;
  const client = { getPayment: async () => (++n < 7 ? payment('pending') : payment('succeeded', { mpesa_receipt: 'MOCKEBFCD9' })), events: async () => [] };
  const r = await watchPayment({ client, id: 'pay_1', provider: PROVIDERS.mock, now: c.now, sleep: c.sleep });
  assert.equal(r.reason, 'stopped');
  assert.equal(r.payment.status, 'succeeded');
  assert.deepEqual(c.waits, [1500, 1500, 2000, 3000, 4000, 5000]);
  assert.equal(n, 7, 'no poll after the final state');
});

test('every state that ends the poll ends it, and pending does not', () => {
  for (const s of ['succeeded', 'failed', 'cancelled', 'timed_out', 'needs_review']) assert.ok(STOP_STATES.has(s), s);
  assert.equal(STOP_STATES.has('pending'), false);
});

test('while the page is hidden it does not poll, and it carries on when the page is visible', async () => {
  const c = clock();
  let hidden = true;
  let polls = 0;
  const client = { getPayment: async () => { polls++; return payment('succeeded', { mpesa_receipt: 'MOCKEBFCD9' }); }, events: async () => [] };
  let waited = 0;
  const r = await watchPayment({ client, id: 'pay_1', provider: PROVIDERS.mock, now: c.now, sleep: c.sleep, isHidden: () => hidden, whenVisible: async () => { waited++; hidden = false; } });
  assert.equal(waited, 1);
  assert.equal(polls, 1, 'nothing was polled while hidden');
  assert.equal(r.payment.status, 'succeeded');
});

test('a rate limit while polling waits out Retry-After and carries on', async () => {
  const c = clock();
  let n = 0;
  const client = {
    getPayment: async () => { if (++n === 1) throw new GatewayError('rate_limited', 'slow', { retryAfterSec: 9 }); return payment('cancelled'); },
    events: async () => [],
  };
  const seen = [];
  const r = await watchPayment({ client, id: 'pay_1', provider: PROVIDERS.mock, now: c.now, sleep: c.sleep, onUpdate: (p, info) => seen.push(info) });
  assert.deepEqual(c.waits, [9000]);
  assert.equal(r.payment.status, 'cancelled');
  assert.equal(seen[0].waitSec, 9);
});

test('a failed read is not an answer: the last state is kept and it tries again', async () => {
  const c = clock();
  let n = 0;
  const client = {
    getPayment: async () => { n++; if (n === 2) throw new GatewayError('unreachable', 'down'); return n < 4 ? payment('pending') : payment('succeeded', { mpesa_receipt: 'MOCKEBFCD9' }); },
    events: async () => [],
  };
  const errors = [];
  const r = await watchPayment({ client, id: 'pay_1', provider: PROVIDERS.mock, now: c.now, sleep: c.sleep, onUpdate: (p, info) => { if (info.error) errors.push(p?.status); } });
  assert.deepEqual(errors, ['pending']);
  assert.equal(r.payment.status, 'succeeded');
});

test('"confirming" is only reported once the gateway shows it received the callback', async () => {
  const c = clock();
  let n = 0;
  let callbackSeen = false;
  const client = {
    getPayment: async () => (++n < 5 ? payment('pending') : payment('succeeded', { mpesa_receipt: 'MOCKEBFCD9' })),
    events: async () => (callbackSeen ? [{ kind: 'callback_received' }] : [{ kind: 'daraja_accepted' }]),
  };
  const phases = [];
  await watchPayment({
    client, id: 'pay_1', provider: PROVIDERS.mock, now: c.now, sleep: async (ms) => { if (n >= 2) callbackSeen = true; await c.sleep(ms); },
    onUpdate: (p, info) => phases.push([p.status, info.confirming]),
  });
  const pendingPhases = phases.filter(([s]) => s === 'pending').map(([, confirming]) => confirming);
  assert.equal(pendingPhases[0], false, 'a fresh prompt is waiting for the customer, not confirming');
  assert.ok(pendingPhases.includes(true), 'after the callback it is confirming');
  assert.equal(phases.at(-1)[0], 'succeeded');
  assert.equal(phases.at(-1)[1], false);
});

test('a timeline that cannot be read does not stop the poll', async () => {
  const c = clock();
  let n = 0;
  const client = { getPayment: async () => (++n < 4 ? payment('pending') : payment('failed')), events: async () => { throw new GatewayError('server', 'no'); } };
  const r = await watchPayment({ client, id: 'pay_1', provider: PROVIDERS.mock, now: c.now, sleep: c.sleep });
  assert.equal(r.payment.status, 'failed');
});

test('the poll gives up at the end of the window and says so', async () => {
  const c = clock();
  const client = { getPayment: async () => payment('pending'), events: async () => [] };
  const r = await watchPayment({ client, id: 'pay_1', provider: PROVIDERS.mock, now: c.now, sleep: c.sleep });
  assert.equal(r.reason, 'window');
  assert.equal(r.payment.status, 'pending');
  assert.ok(c.waits.reduce((a, b) => a + b, 0) >= PROVIDERS.mock.windowMs);
});

test('an abort ends the poll without another request', async () => {
  const ctrl = new AbortController();
  let n = 0;
  const client = { getPayment: async () => { n++; ctrl.abort(); return payment('pending'); }, events: async () => [] };
  const r = await watchPayment({ client, id: 'pay_1', provider: PROVIDERS.mock, signal: ctrl.signal, sleep: async () => {} });
  assert.equal(r.reason, 'aborted');
  assert.equal(n, 1);
});
