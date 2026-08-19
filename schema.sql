-- Week 1: Authentication Module
-- Database: appsys_library

CREATE DATABASE IF NOT EXISTS appsys_library CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE appsys_library;

-- Table: users
-- Used by pages/user.php for librarian and admin account management.
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,      -- store hashed password only (password_hash)
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) DEFAULT NULL,    -- wk2
    role VARCHAR(30) DEFAULT 'librarian',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- wk2
-- Upgrade older installations created before email was added.
-- This is safe to run after the table already exists.
SET @email_column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'email'
);
SET @add_email_column = IF(
    @email_column_exists = 0,
    'ALTER TABLE users ADD COLUMN email VARCHAR(100) DEFAULT NULL AFTER full_name',
    'SELECT 1'
);
PREPARE add_email_column FROM @add_email_column;
EXECUTE add_email_column;
DEALLOCATE PREPARE add_email_column;

-- Table: login_attempts (used for rate limiting / brute-force protection)
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username_time (username, attempted_at),
    INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB;

-- To create a sample librarian account with a properly hashed password,
-- run the included create_admin.php script from the command line (php create_admin.php).
-- Do NOT insert a plaintext or hand-typed hash directly into this table.
