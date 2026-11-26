CREATE TABLE customers (
    id               INTEGER PRIMARY KEY,
    name             TEXT NOT NULL,
    phone            TEXT NOT NULL UNIQUE,
    credit_limit_kes INTEGER NOT NULL DEFAULT 0 CHECK (credit_limit_kes >= 0),
    created_at       TEXT NOT NULL
);

CREATE TABLE orders (
    id             INTEGER PRIMARY KEY,
    reference      TEXT NOT NULL UNIQUE,
    board_date     TEXT NOT NULL,
    customer_id    INTEGER REFERENCES customers (id),
    customer_name  TEXT NOT NULL,
    customer_phone TEXT NOT NULL,
    fulfilment     TEXT NOT NULL CHECK (fulfilment IN ('delivery', 'pickup')),
    slot_id        INTEGER REFERENCES delivery_slots (id),
    address        TEXT NOT NULL DEFAULT '',
    note           TEXT NOT NULL DEFAULT '',
    status         TEXT NOT NULL CHECK (status IN ('received', 'packing', 'out', 'delivered', 'cancelled')),
    payment_mode   TEXT NOT NULL CHECK (payment_mode IN ('pay_on_delivery', 'deni')),
    settled_via    TEXT CHECK (settled_via IN ('cash', 'mpesa', 'deni')),
    total_kes      INTEGER NOT NULL CHECK (total_kes >= 0),
    source         TEXT NOT NULL CHECK (source IN ('web', 'whatsapp')),
    created_at     TEXT NOT NULL
);
CREATE INDEX orders_by_date ON orders (board_date, status);
CREATE INDEX orders_by_slot ON orders (slot_id);

CREATE TABLE order_lines (
    id             INTEGER PRIMARY KEY,
    order_id       INTEGER NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
    item_id        INTEGER NOT NULL,
    name_en        TEXT NOT NULL,
    name_sw        TEXT NOT NULL,
    unit           TEXT NOT NULL,
    quantity_milli INTEGER NOT NULL CHECK (quantity_milli > 0),
    unit_price_kes INTEGER NOT NULL CHECK (unit_price_kes > 0)
);
CREATE INDEX order_lines_by_order ON order_lines (order_id);

CREATE TABLE shopping_checks (
    board_date TEXT NOT NULL,
    item_id    INTEGER NOT NULL,
    unit       TEXT NOT NULL,
    PRIMARY KEY (board_date, item_id, unit)
);
