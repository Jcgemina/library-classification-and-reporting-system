-- Apply this migration to an existing appsys_library database before using Reports.
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
    CONSTRAINT fk_program_progress_major FOREIGN KEY (major_id) REFERENCES majors(id) ON DELETE CASCADE
) ENGINE=InnoDB;
