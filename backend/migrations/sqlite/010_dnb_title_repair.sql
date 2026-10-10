-- Keep in sync with ../mysql/010_dnb_title_repair.sql
INSERT OR IGNORE INTO settings (name, value) SELECT 'dnb_title_repair', '0:' || COALESCE(MAX(id), 0) FROM books;
