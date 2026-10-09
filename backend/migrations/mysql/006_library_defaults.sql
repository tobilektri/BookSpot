-- Personal library defaults: name prefilled for new library loans, loan period in days
-- (due date = borrowed on + loan period)
ALTER TABLE users
    ADD COLUMN library_name VARCHAR(150) NULL,
    ADD COLUMN library_loan_days SMALLINT NULL;
