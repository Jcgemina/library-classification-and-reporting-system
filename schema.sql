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

-- Academic organization hierarchy used by the Organization module.
CREATE TABLE IF NOT EXISTS colleges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    college_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_department_college_name (college_id, name),
    CONSTRAINT fk_departments_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS programs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    name VARCHAR(180) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_program_department_name (department_id, name),
    CONSTRAINT fk_programs_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS majors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    program_id INT NOT NULL,
    name VARCHAR(180) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_major_program_name (program_id, name),
    CONSTRAINT fk_majors_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    program_id INT NOT NULL,
    major_id INT DEFAULT NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(180) NOT NULL,
    year_level TINYINT UNSIGNED DEFAULT NULL,
    semester TINYINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_course_program_code (program_id, code),
    CONSTRAINT fk_courses_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
    CONSTRAINT fk_courses_major FOREIGN KEY (major_id) REFERENCES majors(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS program_prospectuses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    program_id INT NOT NULL UNIQUE,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_prospectus_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_reset_user (user_id),
    INDEX idx_password_reset_expiry (expires_at),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS email_queue (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    recipient VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL,
    reset_token CHAR(64) NOT NULL,
    email_type VARCHAR(20) NOT NULL DEFAULT 'setup',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME DEFAULT NULL,
    last_error VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_queue_pending (sent_at, available_at)
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

SET @email_type_column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'email_queue'
      AND COLUMN_NAME = 'email_type'
);
SET @add_email_type_column = IF(
    @email_type_column_exists = 0,
    "ALTER TABLE email_queue ADD COLUMN email_type VARCHAR(20) NOT NULL DEFAULT 'setup' AFTER reset_token",
    'SELECT 1'
);
PREPARE add_email_type_column FROM @add_email_type_column;
EXECUTE add_email_type_column;
DEALLOCATE PREPARE add_email_type_column;

-- Upgrade older organization installations with majors and course-major links.
SET @majors_table_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'majors');
SET @create_majors_table = IF(@majors_table_exists = 0,
    'CREATE TABLE majors (id INT AUTO_INCREMENT PRIMARY KEY, program_id INT NOT NULL, name VARCHAR(180) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_major_program_name (program_id, name), CONSTRAINT fk_majors_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE) ENGINE=InnoDB',
    'SELECT 1');
PREPARE create_majors_table FROM @create_majors_table;
EXECUTE create_majors_table;
DEALLOCATE PREPARE create_majors_table;

SET @major_column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'major_id');
SET @add_major_column = IF(@major_column_exists = 0,
    'ALTER TABLE courses ADD COLUMN major_id INT DEFAULT NULL AFTER program_id',
    'SELECT 1');
PREPARE add_major_column FROM @add_major_column;
EXECUTE add_major_column;
DEALLOCATE PREPARE add_major_column;

SET @major_fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND CONSTRAINT_NAME = 'fk_courses_major');
SET @add_major_fk = IF(@major_fk_exists = 0,
    'ALTER TABLE courses ADD CONSTRAINT fk_courses_major FOREIGN KEY (major_id) REFERENCES majors(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE add_major_fk FROM @add_major_fk;
EXECUTE add_major_fk;
DEALLOCATE PREPARE add_major_fk;

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

-- Audit trail for normal librarian and administrator actions.
CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    description VARCHAR(255) NOT NULL,
    entity_type VARCHAR(50) DEFAULT NULL,
    entity_id INT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_created_at (created_at),
    INDEX idx_activity_user_id (user_id),
    CONSTRAINT fk_activity_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Security events, including successful/failed logins and blocked requests.
CREATE TABLE IF NOT EXISTS security_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    username VARCHAR(50) DEFAULT NULL,
    event_type VARCHAR(50) NOT NULL,
    severity ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'info',
    description VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_security_created_at (created_at),
    INDEX idx_security_event_type (event_type),
    INDEX idx_security_ip_address (ip_address),
    CONSTRAINT fk_security_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- To create a sample librarian account with a properly hashed password,
-- run the included create_admin.php script from the command line (php create_admin.php).
-- Do NOT insert a plaintext or hand-typed hash directly into this table.
