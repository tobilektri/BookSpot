-- Covers uploaded by the user (photo of the book): never replaced by the cover sources.
ALTER TABLE books ADD COLUMN cover_uploaded_at DATETIME NULL;
