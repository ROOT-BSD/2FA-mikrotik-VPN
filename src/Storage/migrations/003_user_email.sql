-- 0.10: e-mail address a user gave when enrolling the authenticator (idempotent).
-- The «how to connect» guide (PDF) is sent there; the address stays bound to the login.

CREATE TABLE IF NOT EXISTS user_emails (
    username    TEXT PRIMARY KEY,
    email       TEXT NOT NULL,
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL
);
