-- Roles are "admin" (may manage users) and "user". Keep in sync with ../mysql/003_user_role.sql
-- SQLite cannot change a CHECK constraint, so the table is rebuilt (https://sqlite.org/lang_altertable.html#otheralter).
PRAGMA foreign_keys = OFF;
CREATE TABLE users_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('admin', 'user')),
    locale TEXT NOT NULL DEFAULT 'de',
    created_at TEXT NOT NULL,
    last_login_at TEXT NULL
);
INSERT INTO users_new (id, username, email, password_hash, role, locale, created_at, last_login_at)
    SELECT id, username, email, password_hash, CASE WHEN role = 'admin' THEN 'admin' ELSE 'user' END, locale, created_at, last_login_at FROM users;
DROP TABLE users;
ALTER TABLE users_new RENAME TO users;
PRAGMA foreign_keys = ON;
