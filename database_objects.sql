-- Optional reporting, validation, and audit objects for appsys_library.
-- Run schema.sql first so the referenced tables and base view exist.
USE appsys_library;

CREATE TABLE IF NOT EXISTS database_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('book', 'course') NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(20) NOT NULL,
    database_user VARCHAR(288) NOT NULL,
    before_data JSON DEFAULT NULL,
    after_data JSON DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_database_audit_entity_created (entity_type, entity_id, created_at),
    INDEX idx_database_audit_created (created_at)
) ENGINE=InnoDB;

DROP PROCEDURE IF EXISTS sp_get_course_book_coverage;
DROP PROCEDURE IF EXISTS sp_get_under_supplied_courses;
DROP VIEW IF EXISTS vw_course_book_coverage;
DROP VIEW IF EXISTS vw_book_course_catalog;
DROP FUNCTION IF EXISTS fn_effective_book_year;
CREATE FUNCTION fn_effective_book_year(
    p_copyright_year SMALLINT UNSIGNED,
    p_publication_year SMALLINT UNSIGNED
)
RETURNS SMALLINT UNSIGNED
DETERMINISTIC
NO SQL
RETURN COALESCE(p_copyright_year, p_publication_year);

DROP FUNCTION IF EXISTS fn_course_meets_book_minimum;
CREATE FUNCTION fn_course_meets_book_minimum(
    p_book_count BIGINT UNSIGNED,
    p_minimum_books SMALLINT UNSIGNED
)
RETURNS TINYINT UNSIGNED
DETERMINISTIC
NO SQL
RETURN CASE
    WHEN p_book_count >= p_minimum_books THEN 1
    ELSE 0
END;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_book_course_catalog AS
SELECT
    b.book_id,
    b.isbn,
    b.title AS book_title,
    b.author,
    fn_effective_book_year(b.copyright_year, b.publication_year) AS effective_year,
    b.deleted_at AS book_archived_at,
    c.id AS course_id,
    c.code AS course_code,
    c.name AS course_name,
    c.status AS course_status,
    p.id AS program_id,
    p.name AS program_name,
    m.id AS major_id,
    m.name AS major_name
FROM book_courses AS bc
INNER JOIN books AS b ON b.book_id = bc.book_id
INNER JOIN courses AS c ON c.id = bc.course_id
LEFT JOIN programs AS p ON p.id = c.program_id
LEFT JOIN majors AS m ON m.id = c.major_id;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_course_book_coverage AS
SELECT
    c.id AS course_id,
    c.code AS course_code,
    c.name AS course_name,
    c.status AS course_status,
    c.program_id,
    p.name AS program_name,
    COUNT(DISTINCT CASE WHEN b.deleted_at IS NULL THEN b.book_id END) AS active_book_count,
    COALESCE(SUM(CASE WHEN b.deleted_at IS NULL THEN b.copy_count ELSE 0 END), 0) AS active_copy_count,
    COALESCE(config.minimum_books_per_course, 1) AS minimum_books_per_course,
    fn_course_meets_book_minimum(
        COUNT(DISTINCT CASE WHEN b.deleted_at IS NULL THEN b.book_id END),
        COALESCE(config.minimum_books_per_course, 1)
    ) AS meets_book_minimum
FROM courses AS c
LEFT JOIN programs AS p ON p.id = c.program_id
LEFT JOIN book_courses AS bc ON bc.course_id = c.id
LEFT JOIN vw_inventory_books AS b ON b.book_id = bc.book_id
LEFT JOIN library_configuration AS config ON config.id = 1
GROUP BY
    c.id,
    c.code,
    c.name,
    c.status,
    c.program_id,
    p.name,
    config.minimum_books_per_course;

CREATE PROCEDURE sp_get_course_book_coverage(IN p_program_id INT)
READS SQL DATA
SELECT *
FROM vw_course_book_coverage
WHERE p_program_id IS NULL OR program_id = p_program_id
ORDER BY program_name, course_code;

CREATE PROCEDURE sp_get_under_supplied_courses(IN p_program_id INT)
READS SQL DATA
SELECT *
FROM vw_course_book_coverage
WHERE meets_book_minimum = 0
  AND (p_program_id IS NULL OR program_id = p_program_id)
ORDER BY program_name, course_code;

DELIMITER $$

DROP TRIGGER IF EXISTS trg_courses_validate_major_before_insert$$
CREATE TRIGGER trg_courses_validate_major_before_insert
BEFORE INSERT ON courses
FOR EACH ROW
BEGIN
    IF NEW.major_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM majors
        WHERE id = NEW.major_id
          AND program_id = NEW.program_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The selected major must belong to the selected program.';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_courses_validate_major_before_update$$
CREATE TRIGGER trg_courses_validate_major_before_update
BEFORE UPDATE ON courses
FOR EACH ROW
BEGIN
    IF NEW.major_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM majors
        WHERE id = NEW.major_id
          AND program_id = NEW.program_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The selected major must belong to the selected program.';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_books_audit_after_insert$$
CREATE TRIGGER trg_books_audit_after_insert
AFTER INSERT ON books
FOR EACH ROW
INSERT INTO database_audit_logs (
    entity_type, entity_id, action, database_user, after_data
)
VALUES (
    'book', NEW.book_id, 'insert', USER(),
    JSON_OBJECT(
        'isbn', NEW.isbn,
        'title', NEW.title,
        'author', NEW.author,
        'publisher', NEW.publisher,
        'publication_year', NEW.publication_year,
        'copyright_year', NEW.copyright_year,
        'deleted_at', NEW.deleted_at
    )
)$$

DROP TRIGGER IF EXISTS trg_books_audit_after_update$$
CREATE TRIGGER trg_books_audit_after_update
AFTER UPDATE ON books
FOR EACH ROW
INSERT INTO database_audit_logs (
    entity_type, entity_id, action, database_user, before_data, after_data
)
SELECT
    'book',
    NEW.book_id,
    CASE
        WHEN OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN 'archive'
        WHEN OLD.deleted_at IS NOT NULL AND NEW.deleted_at IS NULL THEN 'restore'
        ELSE 'update'
    END,
    USER(),
    JSON_OBJECT(
        'isbn', OLD.isbn,
        'title', OLD.title,
        'author', OLD.author,
        'publisher', OLD.publisher,
        'publication_year', OLD.publication_year,
        'copyright_year', OLD.copyright_year,
        'deleted_at', OLD.deleted_at
    ),
    JSON_OBJECT(
        'isbn', NEW.isbn,
        'title', NEW.title,
        'author', NEW.author,
        'publisher', NEW.publisher,
        'publication_year', NEW.publication_year,
        'copyright_year', NEW.copyright_year,
        'deleted_at', NEW.deleted_at
    )
WHERE NOT (OLD.isbn <=> NEW.isbn)
   OR NOT (OLD.title <=> NEW.title)
   OR NOT (OLD.author <=> NEW.author)
   OR NOT (OLD.publisher <=> NEW.publisher)
   OR NOT (OLD.publication_year <=> NEW.publication_year)
   OR NOT (OLD.copyright_year <=> NEW.copyright_year)
   OR NOT (OLD.deleted_at <=> NEW.deleted_at)$$

DROP TRIGGER IF EXISTS trg_books_audit_after_delete$$
CREATE TRIGGER trg_books_audit_after_delete
AFTER DELETE ON books
FOR EACH ROW
INSERT INTO database_audit_logs (
    entity_type, entity_id, action, database_user, before_data
)
VALUES (
    'book', OLD.book_id, 'delete', USER(),
    JSON_OBJECT(
        'isbn', OLD.isbn,
        'title', OLD.title,
        'author', OLD.author,
        'publisher', OLD.publisher,
        'publication_year', OLD.publication_year,
        'copyright_year', OLD.copyright_year,
        'deleted_at', OLD.deleted_at
    )
)$$

DROP TRIGGER IF EXISTS trg_courses_audit_after_insert$$
CREATE TRIGGER trg_courses_audit_after_insert
AFTER INSERT ON courses
FOR EACH ROW
INSERT INTO database_audit_logs (
    entity_type, entity_id, action, database_user, after_data
)
VALUES (
    'course', NEW.id, 'insert', USER(),
    JSON_OBJECT(
        'program_id', NEW.program_id,
        'major_id', NEW.major_id,
        'code', NEW.code,
        'name', NEW.name,
        'status', NEW.status
    )
)$$

DROP TRIGGER IF EXISTS trg_courses_audit_after_update$$
CREATE TRIGGER trg_courses_audit_after_update
AFTER UPDATE ON courses
FOR EACH ROW
INSERT INTO database_audit_logs (
    entity_type, entity_id, action, database_user, before_data, after_data
)
SELECT
    'course',
    NEW.id,
    'update',
    USER(),
    JSON_OBJECT(
        'program_id', OLD.program_id,
        'major_id', OLD.major_id,
        'code', OLD.code,
        'name', OLD.name,
        'status', OLD.status
    ),
    JSON_OBJECT(
        'program_id', NEW.program_id,
        'major_id', NEW.major_id,
        'code', NEW.code,
        'name', NEW.name,
        'status', NEW.status
    )
WHERE NOT (OLD.program_id <=> NEW.program_id)
   OR NOT (OLD.major_id <=> NEW.major_id)
   OR NOT (OLD.code <=> NEW.code)
   OR NOT (OLD.name <=> NEW.name)
   OR NOT (OLD.status <=> NEW.status)$$

DROP TRIGGER IF EXISTS trg_courses_audit_after_delete$$
CREATE TRIGGER trg_courses_audit_after_delete
AFTER DELETE ON courses
FOR EACH ROW
INSERT INTO database_audit_logs (
    entity_type, entity_id, action, database_user, before_data
)
VALUES (
    'course', OLD.id, 'delete', USER(),
    JSON_OBJECT(
        'program_id', OLD.program_id,
        'major_id', OLD.major_id,
        'code', OLD.code,
        'name', OLD.name,
        'status', OLD.status
    )
)$$

DELIMITER ;
