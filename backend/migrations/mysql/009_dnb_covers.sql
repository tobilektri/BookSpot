-- New cover source (DNB / VLB publisher covers): books that had no cover anywhere are tried
-- again right away instead of after 30 days.
UPDATE books SET cover_status = NULL, cover_checked_at = NULL WHERE cover_status = 'none' AND cover_file IS NULL;
