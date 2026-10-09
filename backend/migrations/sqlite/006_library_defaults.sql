-- Personal library defaults. Keep in sync with ../mysql/006_library_defaults.sql
ALTER TABLE users ADD COLUMN library_name TEXT NULL;
ALTER TABLE users ADD COLUMN library_loan_days INTEGER NULL;
