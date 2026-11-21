-- Keep in sync with ../mysql/013_wishlist.sql
ALTER TABLE books ADD COLUMN wishlist INTEGER NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS wishes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE,
    created_at TEXT NOT NULL,
    UNIQUE (user_id, book_id)
);
CREATE INDEX IF NOT EXISTS idx_wishes_book ON wishes (book_id);
ALTER TABLE users ADD COLUMN wishlist_visibility TEXT NOT NULL DEFAULT 'private';
ALTER TABLE users ADD COLUMN wishlist_token TEXT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_wishlist_token ON users (wishlist_token);
