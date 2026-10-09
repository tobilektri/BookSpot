-- Locally stored covers and the one-time introduction. Keep in sync with ../mysql/005_covers_onboarding.sql
ALTER TABLE books ADD COLUMN cover_file TEXT NULL;
ALTER TABLE books ADD COLUMN cover_status TEXT NULL;
ALTER TABLE books ADD COLUMN cover_checked_at TEXT NULL;
ALTER TABLE users ADD COLUMN onboarded_at TEXT NULL;
