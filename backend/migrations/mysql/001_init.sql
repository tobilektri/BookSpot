-- BookSpot schema (MariaDB 10.6+ / MySQL 8.0+)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'member') NOT NULL DEFAULT 'member',
    locale VARCHAR(5) NOT NULL DEFAULT 'de',
    created_at DATETIME NOT NULL,
    last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- columns_config: JSON array of columns, each {"label": "L", "rows": ["L1", "L2", ...]}
-- The number of entries in "rows" is the number of compartments (Fächer) in that column;
-- the strings are the compartment names that the CSV import matches against.
CREATE TABLE IF NOT EXISTS shelves (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    room VARCHAR(100) NULL,
    description TEXT NULL,
    columns_config JSON NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    isbn13 VARCHAR(13) NULL UNIQUE,
    isbn10 VARCHAR(10) NULL,
    asin VARCHAR(10) NULL,
    title VARCHAR(500) NOT NULL,
    authors VARCHAR(255) NOT NULL DEFAULT '',
    publisher VARCHAR(150) NULL,
    published_year SMALLINT NULL,
    pages INT NOT NULL DEFAULT 0,
    genre VARCHAR(100) NULL,
    language VARCHAR(10) NULL,
    description TEXT NULL,
    cover_url VARCHAR(500) NULL,
    format VARCHAR(30) NULL,
    price DECIMAL(8,2) NULL,
    acquired_at DATE NULL,
    entry_type ENUM('owned', 'library_loan', 'personal_loan') NOT NULL DEFAULT 'owned',
    shelf_id INT NULL,
    shelf_col INT NULL,
    shelf_row INT NULL,
    loan_from VARCHAR(150) NULL,
    loan_start DATE NULL,
    loan_due DATE NULL,
    loan_user_id INT NULL,
    lent_to VARCHAR(100) NULL,
    lent_at DATE NULL,
    status ENUM('active', 'returned', 'discarded') NOT NULL DEFAULT 'active',
    metadata_checked_at DATETIME NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_books_shelf FOREIGN KEY (shelf_id) REFERENCES shelves(id) ON DELETE SET NULL,
    CONSTRAINT fk_books_loan_user FOREIGN KEY (loan_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_books_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_books_isbn10 (isbn10),
    INDEX idx_books_shelf_coords (shelf_id, shelf_col, shelf_row),
    INDEX idx_books_loan_due (entry_type, status, loan_due),
    INDEX idx_books_title (title(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    book_id INT NOT NULL,
    reading_status ENUM('unread', 'reading', 'read', 'abandoned') NOT NULL DEFAULT 'unread',
    rating TINYINT UNSIGNED NULL,
    pages_read INT NOT NULL DEFAULT 0,
    started_at DATE NULL,
    finished_at DATE NULL,
    notes TEXT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY user_book_unique (user_id, book_id),
    CONSTRAINT fk_ub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ub_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    INDEX idx_ub_finished (user_id, finished_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    endpoint TEXT NOT NULL,
    endpoint_hash CHAR(64) NOT NULL UNIQUE,
    public_key VARCHAR(255) NOT NULL,
    auth_token VARCHAR(255) NOT NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    book_id INT NOT NULL,
    kind VARCHAR(30) NOT NULL,
    due_date DATE NOT NULL,
    sent_at DATETIME NOT NULL,
    UNIQUE KEY notification_unique (user_id, book_id, kind, due_date),
    CONSTRAINT fk_nl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_nl_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    username VARCHAR(100) NOT NULL,
    attempted_at DATETIME NOT NULL,
    INDEX idx_login_attempts (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    name VARCHAR(100) PRIMARY KEY,
    value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
