-- Earlier reads of a book (re-reads). The current read stays in user_books;
-- statistics count both. Keep in sync with ../sqlite/011_book_reads.sql
CREATE TABLE IF NOT EXISTS book_reads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    book_id INT NOT NULL,
    started_at DATE NULL,
    finished_at DATE NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_reads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_reads_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    INDEX idx_reads_user_book (user_id, book_id),
    INDEX idx_reads_user_finished (user_id, finished_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
