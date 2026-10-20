-- Adds a configurable default curriculum year for new prospectus records.
SET @default_curriculum_year_column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'library_configuration'
      AND COLUMN_NAME = 'default_curriculum_year'
);
SET @add_default_curriculum_year_column = IF(
    @default_curriculum_year_column_exists = 0,
    'ALTER TABLE library_configuration ADD COLUMN default_curriculum_year VARCHAR(30) NOT NULL DEFAULT '''' AFTER minimum_books_per_course',
    'SELECT 1'
);
PREPARE add_default_curriculum_year_column FROM @add_default_curriculum_year_column;
EXECUTE add_default_curriculum_year_column;
DEALLOCATE PREPARE add_default_curriculum_year_column;

UPDATE library_configuration
SET default_curriculum_year = CONCAT(YEAR(CURRENT_DATE), '-', YEAR(CURRENT_DATE) + 1)
WHERE default_curriculum_year = '';
