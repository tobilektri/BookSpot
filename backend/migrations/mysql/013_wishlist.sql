-- Wishlist. A wished book that is not part of the collection is a books row with
-- wishlist = 1 (hidden from lists, shelves and statistics); who wishes for it is in
-- wishes. Every user's wishlist is private unless shared with the instance or by link.
-- Keep in sync with ../sqlite/013_wishlist.sql
ALTER TABLE books ADD COLUMN wishlist TINYINT(1) NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS wishes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    book_id INT NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY wishes_unique (user_id, book_id),
    CONSTRAINT fk_wishes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_wishes_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    INDEX idx_wishes_book (book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE users ADD COLUMN wishlist_visibility VARCHAR(10) NOT NULL DEFAULT 'private';
ALTER TABLE users ADD COLUMN wishlist_token VARCHAR(64) NULL;
CREATE UNIQUE INDEX idx_users_wishlist_token ON users (wishlist_token);
