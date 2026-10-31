-- Earlier reads of a book (re-reads). Keep in sync with ../mysql/011_book_reads.sql
CREATE TABLE IF NOT EXISTS book_reads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_reads_user_book ON book_reads (user_id, book_id);
CREATE INDEX IF NOT EXISTS idx_reads_user_finished ON book_reads (user_id, finished_at);
