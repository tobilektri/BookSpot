-- Keep in sync with ../mysql/008_metadata_retry.sql
ALTER TABLE books ADD COLUMN metadata_next_attempt_at TEXT NULL;
UPDATE books SET metadata_checked_at = NULL, metadata_attempts = 0
 WHERE (cover_url IS NULL OR cover_url = '') AND cover_file IS NULL AND isbn13 IS NOT NULL AND metadata_status IS NULL;
