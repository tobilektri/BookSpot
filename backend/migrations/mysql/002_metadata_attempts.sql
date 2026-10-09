-- Retry bookkeeping for metadata enrichment (partial answers, e.g. Google Books rate limit)
ALTER TABLE books ADD COLUMN metadata_attempts INT NOT NULL DEFAULT 0, ADD COLUMN metadata_attempted_at DATETIME NULL;
