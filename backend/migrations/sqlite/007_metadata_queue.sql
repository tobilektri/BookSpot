-- Bulk scan metadata queue. Keep in sync with ../mysql/007_metadata_queue.sql
CREATE TABLE IF NOT EXISTS metadata_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    book_id INTEGER NOT NULL UNIQUE REFERENCES books(id) ON DELETE CASCADE,
    user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    isbn TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    next_attempt_at TEXT NOT NULL,
    locked_until TEXT NULL,
    error_message TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_metadata_queue_due ON metadata_queue (status, next_attempt_at);
ALTER TABLE books ADD COLUMN metadata_status TEXT NULL;
