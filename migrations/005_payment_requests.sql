-- "Collect deni by prompt": one row per M-Pesa prompt the owner asks the gateway to send.
-- The ledger stays append-only and untouched. A request only points at the ledger row that
-- recorded it (ledger_entry_id), and that row is written only when the gateway says `succeeded`.
-- Requests are not append-only: they mirror the gateway's current state until they settle.
CREATE TABLE payment_requests (
    id                 INTEGER PRIMARY KEY,
    reference          TEXT NOT NULL UNIQUE,
    customer_id        INTEGER NOT NULL REFERENCES customers (id) ON DELETE RESTRICT,
    amount_kes         INTEGER NOT NULL CHECK (amount_kes > 0),
    charge_kes         INTEGER NOT NULL CHECK (charge_kes > 0),
    mode               TEXT NOT NULL CHECK (mode IN ('live', 'mock', 'sandbox')),
    phone              TEXT,
    idempotency_key    TEXT NOT NULL UNIQUE,
    gateway_payment_id TEXT UNIQUE,
    status             TEXT NOT NULL DEFAULT 'pending'
                       CHECK (status IN ('pending', 'succeeded', 'failed', 'cancelled', 'timed_out', 'needs_review')),
    flags              TEXT NOT NULL DEFAULT '',
    receipt            TEXT,
    result_desc        TEXT NOT NULL DEFAULT '',
    ledger_entry_id    INTEGER UNIQUE REFERENCES ledger_entries (id),
    attention          TEXT,
    attention_note     TEXT NOT NULL DEFAULT '',
    reviewed_at        TEXT,
    last_error         TEXT NOT NULL DEFAULT '',
    created_at         TEXT NOT NULL,
    updated_at         TEXT NOT NULL
);
CREATE INDEX payment_requests_by_customer ON payment_requests (customer_id, id);
CREATE INDEX payment_requests_by_status ON payment_requests (status);
