-- ============================================================
-- Migration: add Student / Admin roles to an EXISTING database
-- Run once:  sudo mysql acadance_db < database/add_roles.sql
-- (Fresh installs don't need this; schema.sql already has it.)
-- ============================================================
USE acadance_db;

ALTER TABLE users
    ADD COLUMN role ENUM('student', 'admin') NOT NULL DEFAULT 'student'
    AFTER password_hash;

-- Every existing user stays a student (default). Admins are created manually,
-- use: php database/create_admin.php "Name" email password
