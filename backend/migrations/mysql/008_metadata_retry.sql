-- When the next metadata lookup is due (growing intervals while a source is down, monthly for
-- books still without cover). Books that were accepted as final without any cover are checked again.
ALTER TABLE books ADD COLUMN metadata_next_attempt_at DATETIME NULL;
UPDATE books SET metadata_checked_at = NULL, metadata_attempts = 0
 WHERE (cover_url IS NULL OR cover_url = '') AND cover_file IS NULL AND isbn13 IS NOT NULL AND metadata_status IS NULL;
