-- Keep in sync with ../mysql/012_cover_upload.sql
ALTER TABLE books ADD COLUMN cover_uploaded_at TEXT NULL;
