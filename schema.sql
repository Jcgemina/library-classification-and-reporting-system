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
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS programs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    college_id INT NOT NULL,
    name VARCHAR(180) NOT NULL,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_program_college_name (college_id, name),
    CONSTRAINT fk_programs_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS majors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    program_id INT NOT NULL,
    name VARCHAR(180) NOT NULL,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_major_program_name (program_id, name),
    CONSTRAINT fk_majors_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    program_id INT DEFAULT NULL,
    major_id INT DEFAULT NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(180) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    units TINYINT UNSIGNED DEFAULT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    semester TINYINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_course_program_code (program_id, code),
    CONSTRAINT fk_courses_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
    CONSTRAINT fk_courses_major FOREIGN KEY (major_id) REFERENCES majors(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Library titles and their individually numbered physical copies.
CREATE TABLE IF NOT EXISTS books (
    book_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    isbn VARCHAR(20) DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    author VARCHAR(180) NOT NULL,
    publisher VARCHAR(180) DEFAULT NULL,
    publication_year SMALLINT UNSIGNED DEFAULT NULL,
    copyright_year SMALLINT UNSIGNED DEFAULT NULL,
    description TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_books_isbn (isbn),
    INDEX idx_books_deleted_publication_year (deleted_at, publication_year),
    INDEX idx_books_copyright_year (copyright_year)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS book_copies (
    copy_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id INT UNSIGNED NOT NULL,
    copy_number SMALLINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_book_copies_book_number (book_id, copy_number),
    INDEX idx_book_copies_book (book_id),
    CONSTRAINT fk_book_copies_book FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS book_courses (
    book_id INT UNSIGNED NOT NULL,
    course_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (book_id, course_id),
    INDEX idx_book_courses_course (course_id),
    CONSTRAINT fk_book_courses_book FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE CASCADE,
    CONSTRAINT fk_book_courses_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS book_embeddings (
    book_id INT UNSIGNED PRIMARY KEY,
    embedding_model VARCHAR(100) NOT NULL,
    source_hash CHAR(64) NOT NULL,
    dimensions SMALLINT UNSIGNED NOT NULL,
    embedding_json JSON NOT NULL,
    generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_book_embeddings_book FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS course_embeddings (
    course_id INT PRIMARY KEY,
    embedding_model VARCHAR(100) NOT NULL,
    source_hash CHAR(64) NOT NULL,
    dimensions SMALLINT UNSIGNED NOT NULL,
    embedding_json JSON NOT NULL,
    generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_course_embeddings_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ai_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_type ENUM('embed_book', 'embed_course', 'suggest_courses') NOT NULL,
    book_id INT UNSIGNED DEFAULT NULL,
    course_id INT DEFAULT NULL,
    requested_by INT DEFAULT NULL,
    result_run_id BIGINT UNSIGNED DEFAULT NULL,
    request_key CHAR(64) DEFAULT NULL,
    status ENUM('queued', 'processing', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'queued',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until DATETIME DEFAULT NULL,
    started_at DATETIME DEFAULT NULL,
    finished_at DATETIME DEFAULT NULL,
    last_error VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ai_jobs_request_key (request_key),
    INDEX idx_ai_jobs_due (status, available_at, id),
    INDEX idx_ai_jobs_book_status (book_id, status),
    INDEX idx_ai_jobs_course_status (course_id, status),
    INDEX idx_ai_jobs_user (requested_by, id),
    CONSTRAINT fk_ai_jobs_book FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_jobs_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_jobs_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS book_course_suggestion_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id INT UNSIGNED NOT NULL,
    requested_by INT DEFAULT NULL,
    embedding_model VARCHAR(100) NOT NULL,
    relevance_model VARCHAR(100) NOT NULL,
    book_source_hash CHAR(64) NOT NULL,
    course_catalog_hash CHAR(64) NOT NULL,
    book_links_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_book_suggestion_runs_latest (book_id, id),
    CONSTRAINT fk_book_course_suggestion_runs_book FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE CASCADE,
    CONSTRAINT fk_book_course_suggestion_runs_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS book_course_suggestions (
    run_id BIGINT UNSIGNED NOT NULL,
    course_id INT NOT NULL,
    similarity_score DECIMAL(10, 8) NOT NULL,
    relevance_label ENUM('relevant', 'uncertain', 'irrelevant') NOT NULL DEFAULT 'uncertain',
    relevance_reason VARCHAR(500) DEFAULT NULL,
    decision ENUM('pending', 'approved', 'dismissed') NOT NULL DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    PRIMARY KEY (run_id, course_id),
    INDEX idx_book_course_suggestions_course (course_id),
    CONSTRAINT fk_book_course_suggestions_run FOREIGN KEY (run_id) REFERENCES book_course_suggestion_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_book_course_suggestions_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_book_course_suggestions_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

SET @suggestion_run_book_links_hash_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'book_course_suggestion_runs' AND COLUMN_NAME = 'book_links_hash');
SET @add_suggestion_run_book_links_hash = IF(@suggestion_run_book_links_hash_exists = 0,
    'ALTER TABLE book_course_suggestion_runs ADD COLUMN book_links_hash CHAR(64) DEFAULT NULL AFTER course_catalog_hash',
    'SELECT 1');
PREPARE add_suggestion_run_book_links_hash FROM @add_suggestion_run_book_links_hash;
EXECUTE add_suggestion_run_book_links_hash;
DEALLOCATE PREPARE add_suggestion_run_book_links_hash;

SET @suggestion_run_relevance_model_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'book_course_suggestion_runs' AND COLUMN_NAME = 'relevance_model');
SET @add_suggestion_run_relevance_model = IF(@suggestion_run_relevance_model_exists = 0,
    'ALTER TABLE book_course_suggestion_runs ADD COLUMN relevance_model VARCHAR(100) DEFAULT NULL AFTER embedding_model',
    'SELECT 1');
PREPARE add_suggestion_run_relevance_model FROM @add_suggestion_run_relevance_model;
EXECUTE add_suggestion_run_relevance_model;
DEALLOCATE PREPARE add_suggestion_run_relevance_model;

SET @suggestion_relevance_label_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'book_course_suggestions' AND COLUMN_NAME = 'relevance_label');
SET @add_suggestion_relevance_label = IF(@suggestion_relevance_label_exists = 0,
    'ALTER TABLE book_course_suggestions ADD COLUMN relevance_label ENUM(''relevant'', ''uncertain'', ''irrelevant'') NOT NULL DEFAULT ''uncertain'' AFTER similarity_score',
    'SELECT 1');
PREPARE add_suggestion_relevance_label FROM @add_suggestion_relevance_label;
EXECUTE add_suggestion_relevance_label;
DEALLOCATE PREPARE add_suggestion_relevance_label;

SET @suggestion_relevance_reason_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'book_course_suggestions' AND COLUMN_NAME = 'relevance_reason');
SET @add_suggestion_relevance_reason = IF(@suggestion_relevance_reason_exists = 0,
    'ALTER TABLE book_course_suggestions ADD COLUMN relevance_reason VARCHAR(500) DEFAULT NULL AFTER relevance_label',
    'SELECT 1');
PREPARE add_suggestion_relevance_reason FROM @add_suggestion_relevance_reason;
EXECUTE add_suggestion_relevance_reason;
DEALLOCATE PREPARE add_suggestion_relevance_reason;

SET @ai_jobs_result_run_column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_jobs' AND COLUMN_NAME = 'result_run_id');
SET @add_ai_jobs_result_run_column = IF(@ai_jobs_result_run_column_exists = 0,
    'ALTER TABLE ai_jobs ADD COLUMN result_run_id BIGINT UNSIGNED DEFAULT NULL AFTER requested_by',
    'SELECT 1');
PREPARE add_ai_jobs_result_run_column FROM @add_ai_jobs_result_run_column;
EXECUTE add_ai_jobs_result_run_column;
DEALLOCATE PREPARE add_ai_jobs_result_run_column;

SET @ai_jobs_result_run_fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_jobs' AND CONSTRAINT_NAME = 'fk_ai_jobs_result_run');
SET @add_ai_jobs_result_run_fk = IF(@ai_jobs_result_run_fk_exists = 0,
    'ALTER TABLE ai_jobs ADD CONSTRAINT fk_ai_jobs_result_run FOREIGN KEY (result_run_id) REFERENCES book_course_suggestion_runs(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE add_ai_jobs_result_run_fk FROM @add_ai_jobs_result_run_fk;
EXECUTE add_ai_jobs_result_run_fk;
DEALLOCATE PREPARE add_ai_jobs_result_run_fk;

CREATE TABLE IF NOT EXISTS copyright_year_ranges (
    range_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    years_threshold SMALLINT UNSIGNED NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_copyright_year_ranges_threshold (years_threshold),
    INDEX idx_copyright_year_ranges_active_order (is_active, sort_order)
) ENGINE=InnoDB;

INSERT INTO copyright_year_ranges (years_threshold, sort_order)
SELECT defaults.years_threshold, defaults.sort_order
FROM (
    SELECT 5 AS years_threshold, 1 AS sort_order
    UNION ALL SELECT 10, 2
    UNION ALL SELECT 20, 3
) AS defaults
WHERE NOT EXISTS (SELECT 1 FROM copyright_year_ranges);

CREATE TABLE IF NOT EXISTS library_configuration (
    id TINYINT UNSIGNED PRIMARY KEY,
    minimum_books_per_course SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO library_configuration (id, minimum_books_per_course)
VALUES (1, 1)
ON DUPLICATE KEY UPDATE id = 1;

-- Prospectus records assigned to a program or optional major.
CREATE TABLE IF NOT EXISTS prospectuses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    program_id INT NOT NULL,
    major_id INT DEFAULT NULL,
    curriculum_year VARCHAR(30) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_prospectuses_program (program_id),
    INDEX idx_prospectuses_major (major_id),
    CONSTRAINT fk_prospectuses_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
    CONSTRAINT fk_prospectuses_major FOREIGN KEY (major_id) REFERENCES majors(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS prospectus_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prospectus_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prospectus_document (prospectus_id),
    CONSTRAINT fk_prospectus_documents_prospectus FOREIGN KEY (prospectus_id) REFERENCES prospectuses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Remove the retired prospectus title from older installations.
SET @prospectus_title_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'prospectuses'
      AND COLUMN_NAME = 'title'
);
SET @drop_prospectus_title = IF(
    @prospectus_title_exists > 0,
    'ALTER TABLE prospectuses DROP COLUMN title',
    'SELECT 1'
);
PREPARE drop_prospectus_title FROM @drop_prospectus_title;
EXECUTE drop_prospectus_title;
DEALLOCATE PREPARE drop_prospectus_title;

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

SET @course_description_column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'description');
SET @add_course_description_column = IF(@course_description_column_exists = 0,
    'ALTER TABLE courses ADD COLUMN description VARCHAR(500) DEFAULT NULL AFTER name',
    'SELECT 1');
PREPARE add_course_description_column FROM @add_course_description_column;
EXECUTE add_course_description_column;
DEALLOCATE PREPARE add_course_description_column;

SET @course_program_nullable = 'ALTER TABLE courses MODIFY COLUMN program_id INT DEFAULT NULL';
PREPARE course_program_nullable_stmt FROM @course_program_nullable;
EXECUTE course_program_nullable_stmt;
DEALLOCATE PREPARE course_program_nullable_stmt;

SET @course_units_column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'units');
SET @add_course_units_column = IF(@course_units_column_exists = 0,
    'ALTER TABLE courses ADD COLUMN units TINYINT UNSIGNED DEFAULT NULL AFTER description',
    'SELECT 1');
PREPARE add_course_units_column FROM @add_course_units_column;
EXECUTE add_course_units_column;
DEALLOCATE PREPARE add_course_units_column;

SET @course_status_column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'status');
SET @add_course_status_column = IF(@course_status_column_exists = 0,
    'ALTER TABLE courses ADD COLUMN status ENUM(''active'', ''inactive'') NOT NULL DEFAULT ''active'' AFTER units',
    'SELECT 1');
PREPARE add_course_status_column FROM @add_course_status_column;
EXECUTE add_course_status_column;
DEALLOCATE PREPARE add_course_status_column;

-- Replace the department layer with a direct college-to-program relationship.
SET @program_college_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'college_id');
SET @add_program_college = IF(@program_college_exists = 0,
    'ALTER TABLE programs ADD COLUMN college_id INT NULL AFTER id',
    'SELECT 1');
PREPARE add_program_college FROM @add_program_college;
EXECUTE add_program_college;
DEALLOCATE PREPARE add_program_college;

SET @departments_table_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departments');
SET @program_college_data = IF(@departments_table_exists = 1,
    'UPDATE programs p INNER JOIN departments d ON d.id = p.department_id SET p.college_id = d.college_id WHERE p.college_id IS NULL',
    'SELECT 1');
PREPARE migrate_program_college FROM @program_college_data;
EXECUTE migrate_program_college;
DEALLOCATE PREPARE migrate_program_college;

SET @program_department_fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND CONSTRAINT_NAME = 'fk_programs_department');
SET @drop_program_department_fk = IF(@program_department_fk_exists = 1,
    'ALTER TABLE programs DROP FOREIGN KEY fk_programs_department',
    'SELECT 1');
PREPARE drop_program_department_fk FROM @drop_program_department_fk;
EXECUTE drop_program_department_fk;
DEALLOCATE PREPARE drop_program_department_fk;

SET @program_department_key_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND INDEX_NAME = 'uq_program_department_name');
SET @drop_program_department_key = IF(@program_department_key_exists = 1,
    'ALTER TABLE programs DROP INDEX uq_program_department_name',
    'SELECT 1');
PREPARE drop_program_department_key FROM @drop_program_department_key;
EXECUTE drop_program_department_key;
DEALLOCATE PREPARE drop_program_department_key;

SET @program_department_column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'department_id');
SET @drop_program_department_column = IF(@program_department_column_exists = 1,
    'ALTER TABLE programs DROP COLUMN department_id',
    'SELECT 1');
PREPARE drop_program_department_column FROM @drop_program_department_column;
EXECUTE drop_program_department_column;
DEALLOCATE PREPARE drop_program_department_column;

ALTER TABLE programs MODIFY COLUMN college_id INT NOT NULL;
SET @program_college_fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND CONSTRAINT_NAME = 'fk_programs_college');
SET @add_program_college_fk = IF(@program_college_fk_exists = 0,
    'ALTER TABLE programs ADD CONSTRAINT fk_programs_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE add_program_college_fk FROM @add_program_college_fk;
EXECUTE add_program_college_fk;
DEALLOCATE PREPARE add_program_college_fk;

SET @program_college_key_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND INDEX_NAME = 'uq_program_college_name');
SET @add_program_college_key = IF(@program_college_key_exists = 0,
    'ALTER TABLE programs ADD UNIQUE KEY uq_program_college_name (college_id, name)',
    'SELECT 1');
PREPARE add_program_college_key FROM @add_program_college_key;
EXECUTE add_program_college_key;
DEALLOCATE PREPARE add_program_college_key;

SET @drop_departments_table = IF(@departments_table_exists = 1, 'DROP TABLE departments', 'SELECT 1');
PREPARE drop_departments_table FROM @drop_departments_table;
EXECUTE drop_departments_table;
DEALLOCATE PREPARE drop_departments_table;

-- Remove retired tables and organization code columns from older installations.
DROP TABLE IF EXISTS prospectus_courses, program_prospectuses;
SET @drop_college_code = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colleges' AND COLUMN_NAME = 'code') > 0, 'ALTER TABLE colleges DROP COLUMN code', 'SELECT 1');
PREPARE drop_college_code_stmt FROM @drop_college_code;
EXECUTE drop_college_code_stmt;
DEALLOCATE PREPARE drop_college_code_stmt;
SET @drop_program_code = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'programs' AND COLUMN_NAME = 'code') > 0, 'ALTER TABLE programs DROP COLUMN code', 'SELECT 1');
PREPARE drop_program_code_stmt FROM @drop_program_code;
EXECUTE drop_program_code_stmt;
DEALLOCATE PREPARE drop_program_code_stmt;
SET @drop_major_code = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'majors' AND COLUMN_NAME = 'code') > 0, 'ALTER TABLE majors DROP COLUMN code', 'SELECT 1');
PREPARE drop_major_code_stmt FROM @drop_major_code;
EXECUTE drop_major_code_stmt;
DEALLOCATE PREPARE drop_major_code_stmt;

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

SET @course_year_level_column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'year_level');
SET @drop_course_year_level_column = IF(@course_year_level_column_exists > 0,
    'ALTER TABLE courses DROP COLUMN year_level',
    'SELECT 1');
PREPARE drop_course_year_level_column FROM @drop_course_year_level_column;
EXECUTE drop_course_year_level_column;
DEALLOCATE PREPARE drop_course_year_level_column;

SET @course_type_column_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'type');
SET @drop_course_type_column = IF(@course_type_column_exists > 0,
    'ALTER TABLE courses DROP COLUMN `type`',
    'SELECT 1');
PREPARE drop_course_type_column FROM @drop_course_type_column;
EXECUTE drop_course_type_column;
DEALLOCATE PREPARE drop_course_type_column;

-- To create a sample librarian account with a properly hashed password,
-- run the included create_admin.php script from the command line (php create_admin.php).
-- Do NOT insert a plaintext or hand-typed hash directly into this table.