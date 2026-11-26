-- The deni book. Rows are only ever added: the triggers below make
-- UPDATE and DELETE fail even for someone with raw database access.
CREATE TABLE ledger_entries (
    id           INTEGER PRIMARY KEY,
    customer_id  INTEGER NOT NULL REFERENCES customers (id) ON DELETE RESTRICT,
    entry_type   TEXT NOT NULL CHECK (entry_type IN ('charge', 'payment', 'credit_note')),
    amount_kes   INTEGER NOT NULL CHECK (amount_kes > 0),
    method       TEXT CHECK (method IN ('cash', 'mpesa')),
    receipt_code TEXT UNIQUE,
    order_id     INTEGER REFERENCES orders (id),
    note         TEXT NOT NULL DEFAULT '',
    occurred_at  TEXT NOT NULL,
    CHECK (entry_type <> 'payment' OR (method IS NOT NULL AND receipt_code IS NOT NULL))
);
CREATE INDEX ledger_by_customer ON ledger_entries (customer_id, id);
CREATE UNIQUE INDEX ledger_one_charge_per_order ON ledger_entries (order_id) WHERE order_id IS NOT NULL AND entry_type = 'charge';

CREATE TRIGGER ledger_no_update BEFORE UPDATE ON ledger_entries
BEGIN
    SELECT RAISE(ABORT, 'ledger entries are append-only');
END;

CREATE TRIGGER ledger_no_delete BEFORE DELETE ON ledger_entries
BEGIN
    SELECT RAISE(ABORT, 'ledger entries are append-only');
END;
