-- Bulk scan: books are created at once as placeholders and filled in by this queue
-- (rate limited, processed by the app while scanning and by cron).
CREATE TABLE IF NOT EXISTS metadata_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    user_id INT NULL,
    isbn VARCHAR(20) NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL,
    locked_until DATETIME NULL,
    error_message VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY metadata_queue_book (book_id),
    INDEX idx_metadata_queue_due (status, next_attempt_at),
    CONSTRAINT fk_metadata_queue_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    CONSTRAINT fk_metadata_queue_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- pending: placeholder waiting for metadata, failed: nothing found (fill in by hand), NULL: done
ALTER TABLE books ADD COLUMN metadata_status VARCHAR(12) NULL;
