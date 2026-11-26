CREATE TABLE sessions (
    id         TEXT PRIMARY KEY,
    data       TEXT NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE INDEX sessions_expiry ON sessions (expires_at);

CREATE TABLE rate_limits (
    bucket       TEXT NOT NULL,
    window_start INTEGER NOT NULL,
    hits         INTEGER NOT NULL,
    PRIMARY KEY (bucket, window_start)
);
