-- Apply this migration to add configurable reference-age assessments.
SET @reference_policy_direction_column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'library_configuration' AND COLUMN_NAME = 'reference_policy_direction'
);
SET @add_reference_policy_direction_column = IF(
    @reference_policy_direction_column_exists = 0,
    'ALTER TABLE library_configuration ADD COLUMN reference_policy_direction ENUM(''within'', ''at_least'') DEFAULT NULL AFTER default_curriculum_year',
    'SELECT 1'
);
PREPARE add_reference_policy_direction_column FROM @add_reference_policy_direction_column;
EXECUTE add_reference_policy_direction_column;
DEALLOCATE PREPARE add_reference_policy_direction_column;

SET @reference_policy_years_column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'library_configuration' AND COLUMN_NAME = 'reference_policy_years'
);
SET @add_reference_policy_years_column = IF(
    @reference_policy_years_column_exists = 0,
    'ALTER TABLE library_configuration ADD COLUMN reference_policy_years SMALLINT UNSIGNED DEFAULT NULL AFTER reference_policy_direction',
    'SELECT 1'
);
PREPARE add_reference_policy_years_column FROM @add_reference_policy_years_column;
EXECUTE add_reference_policy_years_column;
DEALLOCATE PREPARE add_reference_policy_years_column;
