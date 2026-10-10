-- Titles read from the DNB by older versions are looked up once more (DnbTitleRepair, run by
-- cron or backend/bin/repair-dnb-titles.php). Progress: "next book id:last book id".
INSERT IGNORE INTO settings (name, value) SELECT 'dnb_title_repair', CONCAT('0:', COALESCE(MAX(id), 0)) FROM books;
