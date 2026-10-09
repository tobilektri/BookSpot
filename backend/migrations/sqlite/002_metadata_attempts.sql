-- Retry bookkeeping for metadata enrichment. Keep in sync with ../mysql/002_metadata_attempts.sql
ALTER TABLE books ADD COLUMN metadata_attempts INTEGER NOT NULL DEFAULT 0;
ALTER TABLE books ADD COLUMN metadata_attempted_at TEXT NULL;
