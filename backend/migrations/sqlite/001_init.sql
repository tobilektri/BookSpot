-- BookSpot schema (SQLite fallback). Keep in sync with ../mysql/001_init.sql
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'member' CHECK (role IN ('admin', 'member')),
    locale TEXT NOT NULL DEFAULT 'de',
    created_at TEXT NOT NULL,
    last_login_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS shelves (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    room TEXT NULL,
    description TEXT NULL,
    columns_config TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS books (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    isbn13 TEXT NULL UNIQUE,
    isbn10 TEXT NULL,
    asin TEXT NULL,
    title TEXT NOT NULL COLLATE NOCASE,
    authors TEXT NOT NULL DEFAULT '' COLLATE NOCASE,
    publisher TEXT NULL,
    published_year INTEGER NULL,
    pages INTEGER NOT NULL DEFAULT 0,
    genre TEXT NULL COLLATE NOCASE,
    language TEXT NULL,
    description TEXT NULL,
    cover_url TEXT NULL,
    format TEXT NULL,
    price REAL NULL,
    acquired_at TEXT NULL,
    entry_type TEXT NOT NULL DEFAULT 'owned' CHECK (entry_type IN ('owned', 'library_loan', 'personal_loan')),
    shelf_id INTEGER NULL REFERENCES shelves(id) ON DELETE SET NULL,
    shelf_col INTEGER NULL,
    shelf_row INTEGER NULL,
    loan_from TEXT NULL,
    loan_start TEXT NULL,
    loan_due TEXT NULL,
    loan_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    lent_to TEXT NULL,
    lent_at TEXT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'returned', 'discarded')),
    metadata_checked_at TEXT NULL,
    created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_books_isbn10 ON books (isbn10);
CREATE INDEX IF NOT EXISTS idx_books_shelf_coords ON books (shelf_id, shelf_col, shelf_row);
CREATE INDEX IF NOT EXISTS idx_books_loan_due ON books (entry_type, status, loan_due);
CREATE INDEX IF NOT EXISTS idx_books_title ON books (title);

CREATE TABLE IF NOT EXISTS user_books (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE,
    reading_status TEXT NOT NULL DEFAULT 'unread' CHECK (reading_status IN ('unread', 'reading', 'read', 'abandoned')),
    rating INTEGER NULL,
    pages_read INTEGER NOT NULL DEFAULT 0,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    notes TEXT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, book_id)
);
CREATE INDEX IF NOT EXISTS idx_ub_finished ON user_books (user_id, finished_at);

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    endpoint TEXT NOT NULL,
    endpoint_hash TEXT NOT NULL UNIQUE,
    public_key TEXT NOT NULL,
    auth_token TEXT NOT NULL,
    user_agent TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS notification_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE,
    kind TEXT NOT NULL,
    due_date TEXT NOT NULL,
    sent_at TEXT NOT NULL,
    UNIQUE (user_id, book_id, kind, due_date)
);

CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL,
    username TEXT NOT NULL,
    attempted_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_login_attempts ON login_attempts (ip, attempted_at);

CREATE TABLE IF NOT EXISTS settings (
    name TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
