PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    user_id        INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name      TEXT NOT NULL,
    username       TEXT NOT NULL COLLATE NOCASE UNIQUE,
    email          TEXT NOT NULL COLLATE NOCASE UNIQUE,
    password_hash  TEXT NOT NULL,
    role           TEXT NOT NULL DEFAULT 'MEMBER' CHECK (role IN ('ADMIN','MEMBER')),
    created_at     TEXT NOT NULL DEFAULT (datetime('now')),
    last_login_at  TEXT
);

CREATE TABLE IF NOT EXISTS regions (
    region_id   INTEGER PRIMARY KEY AUTOINCREMENT,
    region_name TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS mediums (
    medium_id   INTEGER PRIMARY KEY AUTOINCREMENT,
    medium_name TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS vulnerability_levels (
    level_id   INTEGER PRIMARY KEY AUTOINCREMENT,
    level_name TEXT NOT NULL UNIQUE,
    sort_order INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS tribes (
    tribe_id               INTEGER PRIMARY KEY AUTOINCREMENT,
    tribe_name             TEXT NOT NULL COLLATE NOCASE UNIQUE,
    location               TEXT NOT NULL DEFAULT '',
    description            TEXT NOT NULL DEFAULT '',
    history                TEXT NOT NULL DEFAULT '',
    cultural_background    TEXT NOT NULL DEFAULT '',
    traditional_practices  TEXT NOT NULL DEFAULT '',
    cultural_significance  TEXT NOT NULL DEFAULT '',
    image                  TEXT NOT NULL DEFAULT 'Tnalak.png',
    is_featured            INTEGER NOT NULL DEFAULT 0 CHECK (is_featured IN (0,1))
);

CREATE TABLE IF NOT EXISTS weaves (
    weave_id              INTEGER PRIMARY KEY AUTOINCREMENT,
    catalog_code          TEXT NOT NULL UNIQUE,
    tribe_id              INTEGER NOT NULL REFERENCES tribes(tribe_id),
    region_id             INTEGER NOT NULL REFERENCES regions(region_id),
    medium_id             INTEGER NOT NULL REFERENCES mediums(medium_id),
    level_id              INTEGER NOT NULL REFERENCES vulnerability_levels(level_id),
    name                  TEXT NOT NULL,
    location              TEXT NOT NULL,
    fiber                 TEXT NOT NULL,
    dyes                  TEXT NOT NULL,
    loom                  TEXT NOT NULL,
    cultural_significance TEXT NOT NULL,
    motifs                TEXT NOT NULL DEFAULT '',
    doc_source            TEXT NOT NULL DEFAULT '',
    fpic                  TEXT NOT NULL DEFAULT '',
    image                 TEXT NOT NULL DEFAULT 'Tnalak.png',
    status                TEXT NOT NULL DEFAULT 'PENDING' CHECK (status IN ('PENDING','APPROVED','REJECTED')),
    admin_feedback        TEXT NOT NULL DEFAULT '',
    submitted_by          INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at            TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at            TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS review_logs (
    log_id     INTEGER PRIMARY KEY AUTOINCREMENT,
    weave_id   INTEGER NOT NULL REFERENCES weaves(weave_id) ON DELETE CASCADE,
    admin_id   INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    action     TEXT NOT NULL CHECK (action IN ('APPROVE','REJECT')),
    feedback   TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_id   INTEGER PRIMARY KEY AUTOINCREMENT,
    identifier   TEXT NOT NULL,
    ip           TEXT,
    attempted_at INTEGER NOT NULL,
    success      INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS password_resets (
    reset_id   INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at INTEGER NOT NULL,
    used_at    INTEGER,
    created_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_weaves_status       ON weaves(status);
CREATE INDEX IF NOT EXISTS idx_weaves_tribe        ON weaves(tribe_id);
CREATE INDEX IF NOT EXISTS idx_weaves_submitted_by ON weaves(submitted_by);
CREATE INDEX IF NOT EXISTS idx_users_username      ON users(username);
CREATE INDEX IF NOT EXISTS idx_users_email         ON users(email);
CREATE INDEX IF NOT EXISTS idx_attempts_identifier ON login_attempts(identifier, attempted_at);
CREATE INDEX IF NOT EXISTS idx_resets_user         ON password_resets(user_id);
