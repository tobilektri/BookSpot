-- Keep in sync with ../mysql/009_dnb_covers.sql
UPDATE books SET cover_status = NULL, cover_checked_at = NULL WHERE cover_status = 'none' AND cover_file IS NULL;
