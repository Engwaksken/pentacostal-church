-- ============================================================================
-- REMOVE CHURCH ZONES COMPLETELY
-- ============================================================================
-- This church does not use zones, and the code no longer reads or writes them.
-- This script removes the zone data from the database:
--   1. Harvests in the 'Zone' category become 'General', then 'Zone' is removed
--      from the category ENUM (church_harvests.harvest_category or harvest_type,
--      whichever exists).
--   2. Foreign keys and indexes on zone columns are dropped.
--   3. Zone columns are dropped: members.church_zone_id, members.zone_id,
--      bulk_message_campaigns.zone_id, church_harvests.zone_id, church_harvests.zone_name.
--   4. Zone tables are dropped: church_zones, zones.
--
-- BACK UP THE DATABASE FIRST (phpMyAdmin > Export, or
--     mysqldump -u USER -p DATABASE > before_remove_zones.sql
-- ). THIS CHANGE IS PERMANENT: zone names, zone leaders and each member's zone
-- are deleted and cannot be recovered without the backup.
--
-- Run it once on this site's database (phpMyAdmin > SQL, or the mysql client).
-- Table/column changes commit immediately in MySQL, so there is no rollback.
-- Every step checks information_schema first, so it is safe to run again: steps
-- that were already applied do nothing ("DO 0").
-- Works on MySQL 5.7+/8 and MariaDB 10.x (no stored procedures needed).
-- ============================================================================


-- ---------------------------------------------------------------------------
-- 0. PREVIEW (read only): what this script will remove.
-- ---------------------------------------------------------------------------

-- Zone columns and tables that exist now.
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (COLUMN_NAME IN ('church_zone_id', 'zone_id', 'zone_name')
       OR TABLE_NAME IN ('church_zones', 'zones'))
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- Foreign keys on zone columns or pointing at a zone table. Step 2 drops the ones
-- on members, bulk_message_campaigns and church_harvests. If this lists a key on
-- any OTHER table, drop it by hand first (ALTER TABLE `t` DROP FOREIGN KEY `name`;)
-- or step 4 will fail with a foreign key error.
SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME IS NOT NULL
  AND (COLUMN_NAME IN ('church_zone_id', 'zone_id')
       OR REFERENCED_TABLE_NAME IN ('church_zones', 'zones'));


-- ---------------------------------------------------------------------------
-- 1. HARVESTS: 'Zone' category -> 'General', then remove 'Zone' from the ENUM.
--    church_admin/install.php creates church_harvests.harvest_category;
--    member/_church_engagement.php creates church_harvests.harvest_type.
--    Only the column that exists is changed.
-- ---------------------------------------------------------------------------

SET @sql = (SELECT IF(COUNT(*) > 0,
    'UPDATE `church_harvests` SET `harvest_category` = ''General'' WHERE `harvest_category` = ''Zone''',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'church_harvests' AND COLUMN_NAME = 'harvest_category');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = (SELECT IF(COUNT(*) > 0,
    'ALTER TABLE `church_harvests` MODIFY `harvest_category` ENUM(''Sunday School'',''General'') NOT NULL',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'church_harvests' AND COLUMN_NAME = 'harvest_category'
    AND COLUMN_TYPE LIKE '%''Zone''%');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = (SELECT IF(COUNT(*) > 0,
    'UPDATE `church_harvests` SET `harvest_type` = ''General'' WHERE `harvest_type` = ''Zone''',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'church_harvests' AND COLUMN_NAME = 'harvest_type');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = (SELECT IF(COUNT(*) > 0,
    'ALTER TABLE `church_harvests` MODIFY `harvest_type` ENUM(''Sunday School'',''General'') NOT NULL DEFAULT ''General''',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'church_harvests' AND COLUMN_NAME = 'harvest_type'
    AND COLUMN_TYPE LIKE '%''Zone''%');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;


-- ---------------------------------------------------------------------------
-- 2. FOREIGN KEYS, then INDEXES, on zone columns.
--    The repository has no CREATE statement for church_zones or for the
--    members.church_zone_id column's keys, so the names are looked up here.
--    (Known name: church_harvests index idx_church_harvests_zone_id, or the
--    automatic name zone_id from INDEX(zone_id).)
-- ---------------------------------------------------------------------------

-- members: foreign keys on church_zone_id / zone_id or pointing at a zone table
SET @sql = (SELECT IFNULL(CONCAT('ALTER TABLE `members` ',
      GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')), 'DO 0')
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND REFERENCED_TABLE_NAME IS NOT NULL
    AND (COLUMN_NAME IN ('church_zone_id', 'zone_id') OR REFERENCED_TABLE_NAME IN ('church_zones', 'zones')));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- bulk_message_campaigns: foreign keys on zone_id
SET @sql = (SELECT IFNULL(CONCAT('ALTER TABLE `bulk_message_campaigns` ',
      GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')), 'DO 0')
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bulk_message_campaigns' AND REFERENCED_TABLE_NAME IS NOT NULL
    AND (COLUMN_NAME = 'zone_id' OR REFERENCED_TABLE_NAME IN ('church_zones', 'zones')));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- church_harvests: foreign keys on zone_id
SET @sql = (SELECT IFNULL(CONCAT('ALTER TABLE `church_harvests` ',
      GROUP_CONCAT(DISTINCT CONCAT('DROP FOREIGN KEY `', CONSTRAINT_NAME, '`') SEPARATOR ', ')), 'DO 0')
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'church_harvests' AND REFERENCED_TABLE_NAME IS NOT NULL
    AND (COLUMN_NAME = 'zone_id' OR REFERENCED_TABLE_NAME IN ('church_zones', 'zones')));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- members: indexes on church_zone_id / zone_id
SET @sql = (SELECT IFNULL(CONCAT('ALTER TABLE `members` ',
      GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')), 'DO 0')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND INDEX_NAME <> 'PRIMARY'
    AND COLUMN_NAME IN ('church_zone_id', 'zone_id'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- bulk_message_campaigns: indexes on zone_id
SET @sql = (SELECT IFNULL(CONCAT('ALTER TABLE `bulk_message_campaigns` ',
      GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')), 'DO 0')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bulk_message_campaigns' AND INDEX_NAME <> 'PRIMARY'
    AND COLUMN_NAME = 'zone_id');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- church_harvests: indexes on zone_id / zone_name (e.g. idx_church_harvests_zone_id, zone_id)
SET @sql = (SELECT IFNULL(CONCAT('ALTER TABLE `church_harvests` ',
      GROUP_CONCAT(DISTINCT CONCAT('DROP INDEX `', INDEX_NAME, '`') SEPARATOR ', ')), 'DO 0')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'church_harvests' AND INDEX_NAME <> 'PRIMARY'
    AND COLUMN_NAME IN ('zone_id', 'zone_name'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;


-- ---------------------------------------------------------------------------
-- 3. ZONE COLUMNS
-- ---------------------------------------------------------------------------

SET @sql = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `members` DROP COLUMN `church_zone_id`', 'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'church_zone_id');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Older bulk-message code looked for members.zone_id; dropped if it exists.
SET @sql = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `members` DROP COLUMN `zone_id`', 'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND COLUMN_NAME = 'zone_id');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `bulk_message_campaigns` DROP COLUMN `zone_id`', 'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bulk_message_campaigns' AND COLUMN_NAME = 'zone_id');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `church_harvests` DROP COLUMN `zone_id`', 'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'church_harvests' AND COLUMN_NAME = 'zone_id');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE `church_harvests` DROP COLUMN `zone_name`', 'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'church_harvests' AND COLUMN_NAME = 'zone_name');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;


-- ---------------------------------------------------------------------------
-- 4. ZONE TABLES
--    `zones` was only ever a fallback name the code looked for; it is dropped
--    only if it exists. Check the preview above first if your database has a
--    `zones` table that belongs to something else.
--    Fails with a foreign key error if another table still references these
--    tables (see the second preview query); drop that key and run this again.
-- ---------------------------------------------------------------------------

DROP TABLE IF EXISTS `church_zones`;
DROP TABLE IF EXISTS `zones`;


-- ---------------------------------------------------------------------------
-- 5. CHECK (read only): both queries should return no rows.
-- ---------------------------------------------------------------------------

SELECT TABLE_NAME, COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (COLUMN_NAME IN ('church_zone_id', 'zone_id', 'zone_name')
       OR TABLE_NAME IN ('church_zones', 'zones'));

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'church_harvests'
  AND COLUMN_NAME IN ('harvest_category', 'harvest_type') AND COLUMN_TYPE LIKE '%''Zone''%';
