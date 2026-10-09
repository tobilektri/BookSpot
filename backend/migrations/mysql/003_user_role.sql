-- Roles are "admin" (may manage users) and "user"
ALTER TABLE users MODIFY role ENUM('admin', 'member', 'user') NOT NULL DEFAULT 'user';
UPDATE users SET role = 'user' WHERE role = 'member';
ALTER TABLE users MODIFY role ENUM('admin', 'user') NOT NULL DEFAULT 'user';
