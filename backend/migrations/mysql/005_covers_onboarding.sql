-- Locally stored covers (var/covers/) and the one-time introduction per user
ALTER TABLE books
    ADD COLUMN cover_file VARCHAR(64) NULL,
    ADD COLUMN cover_status VARCHAR(10) NULL,
    ADD COLUMN cover_checked_at DATETIME NULL;
ALTER TABLE users ADD COLUMN onboarded_at DATETIME NULL;
