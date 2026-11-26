CREATE TABLE settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE items (
    id           INTEGER PRIMARY KEY,
    name_en      TEXT NOT NULL UNIQUE,
    name_sw      TEXT NOT NULL,
    aliases      TEXT NOT NULL DEFAULT '',
    default_unit TEXT NOT NULL CHECK (default_unit IN ('kg', 'bunch', 'piece', 'heap')),
    active       INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE board_entries (
    id              INTEGER PRIMARY KEY,
    board_date      TEXT NOT NULL,
    item_id         INTEGER NOT NULL REFERENCES items (id),
    unit            TEXT NOT NULL CHECK (unit IN ('kg', 'bunch', 'piece', 'heap')),
    price_kes       INTEGER NOT NULL CHECK (price_kes > 0),
    available_milli INTEGER NOT NULL CHECK (available_milli >= 0),
    special         INTEGER NOT NULL DEFAULT 0,
    special_note    TEXT NOT NULL DEFAULT '',
    UNIQUE (board_date, item_id)
);

CREATE TABLE delivery_slots (
    id        INTEGER PRIMARY KEY,
    slot_date TEXT NOT NULL,
    starts_at TEXT NOT NULL,
    ends_at   TEXT NOT NULL,
    capacity  INTEGER NOT NULL CHECK (capacity >= 0),
    UNIQUE (slot_date, starts_at)
);
