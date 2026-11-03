-- Track the signed-in user responsible for each offertory record.
-- Run once on the church database; safe to rerun.
SET @has_recorded_by = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'offertory'
      AND COLUMN_NAME = 'recorded_by'
);
SET @sql = IF(
    @has_recorded_by = 0,
    'ALTER TABLE offertory ADD COLUMN recorded_by INT NULL AFTER witnessed_by',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
