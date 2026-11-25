-- Core schema DDL for appsys_library.
-- Select the target database before running this file.

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) DEFAULT NULL,
    role VARCHAR(30) DEFAULT 'librarian',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

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
    UNIQUE KEY uq_major_program_id_id (program_id, id),
    CONSTRAINT fk_majors_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS program_progress (
    progress_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    program_id INT NOT NULL,
    major_id INT DEFAULT NULL,
    major_scope_id INT NOT NULL DEFAULT 0,
    progress_year SMALLINT UNSIGNED NOT NULL,
    target_quarter ENUM('Q1', 'Q2', 'Q3', 'Q4') NOT NULL DEFAULT 'Q1',
    q1_actual TINYINT(1) NOT NULL DEFAULT 0,
    q2_actual TINYINT(1) NOT NULL DEFAULT 0,
    q3_actual TINYINT(1) NOT NULL DEFAULT 0,
    q4_actual TINYINT(1) NOT NULL DEFAULT 0,
    q1_updated_month DATE DEFAULT NULL,
    q2_updated_month DATE DEFAULT NULL,
    q3_updated_month DATE DEFAULT NULL,
    q4_updated_month DATE DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_program_progress_scope_year (program_id, progress_year, major_scope_id),
    INDEX idx_program_progress_year (progress_year),
    CONSTRAINT fk_program_progress_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
    CONSTRAINT fk_program_progress_major_program FOREIGN KEY (program_id, major_id) REFERENCES majors(program_id, id) ON DELETE CASCADE
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

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_inventory_books AS
SELECT
    b.book_id,
    b.isbn,
    b.title,
    b.author,
    b.publisher,
    b.publication_year,
    b.copyright_year,
    b.description,
    b.deleted_at,
    COALESCE(c.copy_count, 0) AS copy_count
FROM books AS b
LEFT JOIN (
    SELECT book_id, COUNT(*) AS copy_count
    FROM book_copies
    GROUP BY book_id
) AS c ON c.book_id = b.book_id;

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

CREATE TRIGGER trg_books_invalidate_embedding_after_update
AFTER UPDATE ON books
FOR EACH ROW
DELETE FROM book_embeddings
WHERE book_id = NEW.book_id
  AND (NOT (OLD.title <=> NEW.title) OR NOT (OLD.description <=> NEW.description));

CREATE TRIGGER trg_courses_invalidate_embedding_after_update
AFTER UPDATE ON courses
FOR EACH ROW
DELETE FROM course_embeddings
WHERE course_id = NEW.id
  AND (NOT (OLD.code <=> NEW.code)
       OR NOT (OLD.name <=> NEW.name)
       OR NOT (OLD.description <=> NEW.description));

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
    CONSTRAINT fk_ai_jobs_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ai_jobs_result_run FOREIGN KEY (result_run_id) REFERENCES book_course_suggestion_runs(id) ON DELETE SET NULL
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

CREATE FUNCTION fn_is_within_copyright_year_window(
    p_effective_year SMALLINT UNSIGNED,
    p_years_threshold SMALLINT UNSIGNED,
    p_current_year SMALLINT UNSIGNED
)
RETURNS TINYINT UNSIGNED
DETERMINISTIC
NO SQL
RETURN CASE
    WHEN p_effective_year IS NOT NULL
     AND p_years_threshold IS NOT NULL
     AND p_years_threshold > 0
     AND p_current_year IS NOT NULL
     AND p_effective_year <= p_current_year
     AND p_effective_year >= CAST(p_current_year AS SIGNED) - CAST(p_years_threshold AS SIGNED)
    THEN 1
    ELSE 0
END;

CREATE PROCEDURE sp_get_copyright_year_metrics()
READS SQL DATA
SELECT r.range_id, r.years_threshold, COUNT(b.book_id) AS title_count
FROM copyright_year_ranges r
LEFT JOIN books b
  ON b.deleted_at IS NULL
 AND fn_is_within_copyright_year_window(
        COALESCE(b.copyright_year, b.publication_year),
        r.years_threshold,
        YEAR(CURRENT_DATE)
     ) = 1
WHERE r.is_active = 1
GROUP BY r.range_id, r.years_threshold, r.sort_order
ORDER BY r.sort_order, r.years_threshold;

CREATE TABLE IF NOT EXISTS library_configuration (
    id TINYINT UNSIGNED PRIMARY KEY,
    minimum_books_per_course SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    default_curriculum_year VARCHAR(30) NOT NULL DEFAULT '',
    institution_name VARCHAR(180) NOT NULL DEFAULT '',
    library_name VARCHAR(180) NOT NULL DEFAULT '',
    institution_address VARCHAR(255) NOT NULL DEFAULT '',
    contact_email VARCHAR(254) NOT NULL DEFAULT '',
    contact_phone VARCHAR(40) NOT NULL DEFAULT '',
    reference_policy_direction ENUM('within', 'at_least') DEFAULT NULL,
    reference_policy_years SMALLINT UNSIGNED DEFAULT NULL,
    reference_policy_source VARCHAR(255) DEFAULT NULL,
    reference_policy_effective_date DATE DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reference_age_policy_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    direction ENUM('within', 'at_least') NOT NULL,
    years_threshold SMALLINT UNSIGNED NOT NULL,
    policy_source VARCHAR(255) NOT NULL,
    effective_date DATE NOT NULL,
    changed_by INT DEFAULT NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reference_policy_versions_changed_at (changed_at),
    CONSTRAINT fk_reference_policy_versions_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

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

CREATE TABLE IF NOT EXISTS password_reset_requests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    email_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    requested_at DATETIME NOT NULL,
    INDEX idx_password_reset_request_email_time (email_hash, requested_at),
    INDEX idx_password_reset_request_ip_time (ip_hash, requested_at),
    INDEX idx_password_reset_request_time (requested_at)
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

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username_time (username, attempted_at),
    INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB;

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
    INDEX idx_security_user_event_created (user_id, event_type, created_at),
    CONSTRAINT fk_security_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
