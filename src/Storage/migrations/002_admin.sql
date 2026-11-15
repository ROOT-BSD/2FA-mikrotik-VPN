-- 0.2: settings edited in the admin panel + local administrator accounts (idempotent)

CREATE TABLE IF NOT EXISTS settings (
    key         TEXT PRIMARY KEY,
    value       BLOB    NOT NULL,          -- plain text, or nonce || secretbox(value) when encrypted = 1
    encrypted   INTEGER NOT NULL DEFAULT 0,
    updated_at  TEXT    NOT NULL,
    updated_by  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS admins (
    username       TEXT PRIMARY KEY,
    password_hash  TEXT NOT NULL,           -- password_hash(): Argon2id
    created_at     TEXT NOT NULL,
    updated_at     TEXT NOT NULL,
    last_login_at  TEXT,
    last_login_ip  TEXT
);
