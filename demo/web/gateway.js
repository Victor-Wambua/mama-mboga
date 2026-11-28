// The browser's side of "Collect by prompt". PHP running in WebAssembly has no network, so this file, not PHP,
// talks to the payments gateway: it sends the prompt, polls for the answer, and hands what it read to the PHP
// bridge, which applies the app's real rules. Plain ES module, no DOM access except through injected options,
// so it can be tested in Node.

/** The gateway's publishable demo key. Public by design: it can only create capped sandbox payments and read them. */
export const DEMO_KEY = 'pk_sandbox_duka_demo_4f9c2a7e';

export const PROVIDERS = {
  mock: {
    id: 'mock',
    url: 'https://mpesa-gateway.victorwambua004.workers.dev',
    key: DEMO_KEY,
    // About 1.5 s at first, then easing off. The last value repeats.
    backoffMs: [1500, 1500, 2000, 3000, 4000, 5000],
    // The mock marks a prompt timed out after 90 s and settles it a little later.
    windowMs: 150000,
  },
  sandbox: {
    id: 'sandbox',
    url: 'https://gateway-sbx.victorwambua004.workers.dev',
    key: DEMO_KEY,
    // A real prompt takes a person tens of seconds, and the real STK Query can lag a callback by 10 to 30 s.
    backoffMs: [2000, 2000, 3000, 4000, 5000],
    windowMs: 210000,
  },
};

/** The mock gateway's scenarios, in the words of what the customer does. Only the mock accepts a `scenario`. */
export const MOCK_SCENARIOS = [
  ['success', 'Approves the prompt'],
  ['cancelled_by_user', 'Cancels the prompt'],
  ['wrong_pin', 'Enters a wrong PIN'],
  ['insufficient_funds', 'Has too little M-Pesa balance'],
  ['duplicate_callback', 'Approves, and the callback arrives twice'],
  ['callback_then_query_disagree', 'Approves, but the status check disagrees (held for review)'],
  ['timeout_no_callback', 'Never answers (times out after about 90 s)'],
  ['late_success', 'Answers after the deadline (held for review, about 2 minutes)'],
];

/** What the gateway's flags mean, for a person. */
export const FLAG_TEXT = {
  late_success: 'A success arrived after the deadline.',
  callback_query_mismatch: 'The success message and the gateway\'s own check disagree.',
  callback_field_mismatch: 'The success message did not match what was asked for.',
  unverified_success: 'A success could not be verified.',
  push_outcome_unknown: 'The gateway never learned whether the prompt was sent.',
};

export class GatewayError extends Error {
  constructor(kind, message, extra = {}) {
    super(message);
    this.name = 'GatewayError';
    this.kind = kind;
    this.status = extra.status ?? null;
    this.code = extra.code ?? null;
    this.retryAfterSec = extra.retryAfterSec ?? null;
  }
}

/** Retry-After is seconds or an HTTP date; clamp it to something a person will wait for. */
export function parseRetryAfter(header, nowMs = Date.now()) {
  if (!header) return null;
  const t = String(header).trim();
  let sec;
  if (/^\d+$/.test(t)) sec = Number(t);
  else {
    const at = Date.parse(t);
    if (Number.isNaN(at)) return null;
    sec = Math.ceil((at - nowMs) / 1000);
  }
  return Math.min(600, Math.max(1, sec));
}

/** A payment's states: these stop the poll. `timed_out` stops it too, and can still turn into a late success later. */
export const STOP_STATES = new Set(['succeeded', 'failed', 'cancelled', 'timed_out', 'needs_review']);

/**
 * @param {{url: string, key: string}} provider
 * @param {{fetch?: typeof fetch, timeoutMs?: number}} [opts]
 */
export function createClient(provider, opts = {}) {
  const base = provider.url.replace(/\/+$/, '');
  const timeoutMs = opts.timeoutMs ?? 10000;
  const doFetch = opts.fetch ?? ((...a) => globalThis.fetch(...a));

  async function call(path, { method = 'GET', body, headers = {} } = {}) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), timeoutMs);
    let res;
    try {
      res = await doFetch(base + path, {
        method,
        signal: ctrl.signal,
        cache: 'no-store',
        headers: { Accept: 'application/json', Authorization: `Bearer ${provider.key}`, ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}), ...headers },
        ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
      });
    } catch {
      throw new GatewayError('unreachable', 'Could not reach the payment gateway.');
    } finally {
      clearTimeout(timer);
    }
    let json = null;
    try { json = await res.json(); } catch { /* not JSON */ }
    if (res.ok && json && json.data !== undefined) return { status: res.status, data: json.data };
    const err = (json && json.error) || {};
    const message = err.message || `The gateway answered with HTTP ${res.status}.`;
    const extra = { status: res.status, code: err.code ?? null };
    if (res.status === 429) throw new GatewayError('rate_limited', message, { ...extra, retryAfterSec: parseRetryAfter(res.headers.get('Retry-After')) ?? 30 });
    if (err.code === 'amount_exceeds_key_cap') throw new GatewayError('over_cap', message, extra);
    if (err.code === 'phone_not_approved') throw new GatewayError('phone_not_approved', message, extra);
    if (err.code === 'reference_pending') throw new GatewayError('reference_pending', message, extra);
    if (res.status === 404) throw new GatewayError('not_found', message, extra);
    if (res.status === 400 || res.status === 401 || res.status === 409 || res.status === 422) throw new GatewayError('rejected', message, extra);
    throw new GatewayError('server', message, extra);
  }

  return {
    /** The same idempotency key with the same details returns the same payment, so resending is safe. */
    async createPayment(input, idempotencyKey) {
      const r = await call('/v1/payments', { method: 'POST', body: input, headers: { 'Idempotency-Key': idempotencyKey } });
      return { payment: r.data, replayed: r.status === 200 };
    },
    async getPayment(id) {
      return (await call(`/v1/payments/${encodeURIComponent(id)}`)).data;
    },
    /** The gateway's timeline for one payment: used only to tell "waiting for the customer" from "confirming". */
    async events(id) {
      const data = (await call(`/v1/payments/${encodeURIComponent(id)}/events`)).data;
      return Array.isArray(data) ? data : [];
    },
  };
}

/**
 * The body of a create-payment call. The sandbox is always a flat KES 1 test charge and never gets a `scenario`;
 * the mock charges what was asked and gets the scenario the owner picked.
 */
export function createBody(providerId, prompt, phone, scenario) {
  return {
    amount: prompt.charge,
    phone: String(phone).replace(/\s+/g, ''),
    reference: prompt.reference,
    description: 'Deni payment',
    ...(providerId === 'mock' ? { scenario: scenario || 'success' } : {}),
  };
}

const realSleep = (ms, signal) => new Promise((resolve) => {
  const t = setTimeout(resolve, ms);
  signal?.addEventListener('abort', () => { clearTimeout(t); resolve(); }, { once: true });
});

/**
 * Poll one payment until it reaches a state that stops the poll, the window runs out, or `signal` aborts.
 * Polls about every 1.5 s at first and eases off, pauses while the page is hidden, waits out a rate limit,
 * and tells the caller when the gateway has the customer's reply and is still confirming it.
 *
 * @param {{client: ReturnType<typeof createClient>, id: string, provider: typeof PROVIDERS.mock, onUpdate?: Function,
 *   signal?: AbortSignal, sleep?: (ms: number, signal?: AbortSignal) => Promise<void>, now?: () => number,
 *   isHidden?: () => boolean, whenVisible?: () => Promise<void>}} o
 * @returns {Promise<{payment: object | null, reason: 'stopped' | 'window' | 'aborted'}>}
 */
export async function watchPayment(o) {
  const { client, id, provider, onUpdate, signal } = o;
  const sleep = o.sleep ?? realSleep;
  const now = o.now ?? (() => Date.now());
  const isHidden = o.isHidden ?? (() => false);
  const whenVisible = o.whenVisible ?? (() => Promise.resolve());
  const started = now();
  let payment = null;
  let confirming = false;
  let polls = 0;

  while (!signal?.aborted) {
    if (isHidden()) {
      await whenVisible();
      continue;
    }
    if (now() - started > provider.windowMs) return { payment, reason: 'window' };
    try {
      payment = await client.getPayment(id);
    } catch (e) {
      if (e instanceof GatewayError && e.kind === 'rate_limited') {
        onUpdate?.(payment, { confirming, waitSec: e.retryAfterSec ?? 30, error: e });
        await sleep((e.retryAfterSec ?? 30) * 1000, signal);
        continue;
      }
      // A failed read is not an answer: keep the last state, say so, and try again a little later.
      onUpdate?.(payment, { confirming, error: e });
      await sleep(provider.backoffMs[Math.min(polls, provider.backoffMs.length - 1)], signal);
      polls++;
      continue;
    }
    if (STOP_STATES.has(payment.status)) {
      onUpdate?.(payment, { confirming: false });
      return { payment, reason: 'stopped' };
    }
    if (!confirming && polls >= 1 && polls % 2 === 1) {
      try {
        confirming = (await client.events(id)).some((ev) => ev.kind === 'callback_received');
      } catch { /* the timeline is only a hint */ }
    }
    onUpdate?.(payment, { confirming });
    await sleep(provider.backoffMs[Math.min(polls, provider.backoffMs.length - 1)], signal);
    polls++;
  }

  return { payment, reason: 'aborted' };
}
