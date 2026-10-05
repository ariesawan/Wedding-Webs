-- ============================================================
-- MIGRASI v2 -> v3
--   mysql -u USER -p NAMA_DB < db/migration-v3.sql
-- Aman diulang.
-- ============================================================
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS calla_add_col;
DELIMITER //
CREATE PROCEDURE calla_add_col(IN tbl VARCHAR(64), IN col VARCHAR(64), IN def TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col) THEN
    SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `', col, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END //
DELIMITER ;

-- Notulen pertemuan + hasil rekaman Zoom
CALL calla_add_col('meetings', 'minutes',            'MEDIUMTEXT NULL');
CALL calla_add_col('meetings', 'minutes_at',         'DATETIME NULL');
CALL calla_add_col('meetings', 'zoom_recording_url', 'VARCHAR(600) NULL');
CALL calla_add_col('meetings', 'zoom_recording_at',  'DATETIME NULL');
CALL calla_add_col('meetings', 'zoom_duration',      'SMALLINT UNSIGNED NULL');
DROP PROCEDURE IF EXISTS calla_add_col;

-- Pintasan spreadsheet yang sering dibuka
CREATE TABLE IF NOT EXISTS sheet_links (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label      VARCHAR(120) NOT NULL,
  sheet_id   VARCHAR(160) NOT NULL,
  tab        VARCHAR(120) NOT NULL DEFAULT '',
  note       VARCHAR(255) NOT NULL DEFAULT '',
  sort_order SMALLINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sheetlinks (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
