-- VPN-GW schema (idempotent)

CREATE TABLE IF NOT EXISTS totp_secrets (
    username    TEXT PRIMARY KEY,
    secret_enc  BLOB    NOT NULL,        -- nonce || sodium_crypto_secretbox(secret)
    last_step   INTEGER NOT NULL DEFAULT 0,  -- last accepted TOTP time-step (replay guard)
    created_at  TEXT    NOT NULL,
    created_by  TEXT    NOT NULL         -- 'self' | administrator login
);

CREATE TABLE IF NOT EXISTS rate_events (
    key   TEXT    NOT NULL,              -- 'ip:1.2.3.4' | 'user:ivanenko' | 'mail:ivanenko'
    ts    INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_rate_key_ts ON rate_events(key, ts);

CREATE TABLE IF NOT EXISTS lockouts (
    key       TEXT PRIMARY KEY,
    until_ts  INTEGER NOT NULL,
    reason    TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS grants (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    username    TEXT    NOT NULL,
    ip          TEXT    NOT NULL,
    granted_at  TEXT    NOT NULL,
    expires_at  TEXT    NOT NULL,
    revoked_at  TEXT
);
CREATE INDEX IF NOT EXISTS idx_grants_user ON grants(username);
