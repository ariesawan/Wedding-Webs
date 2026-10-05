-- ============================================================
-- MIGRASI v3 -> v4  ·  Susunan hari & layanan
--   mysql -u USER -p NAMA_DB < db/migration-v4.sql
-- Aman diulang.
--
-- Situs publik punya penyusun brief ("Susun harimu") yang mengumpulkan
-- rangkaian acara, layanan, dan jumlah tamu. Klien yang deal lewat WhatsApp
-- membawa data yang sama, tapi sebelumnya tidak ada tempat mencatatnya di
-- panel. Tabel ini menyamakan keduanya.
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

CALL calla_add_col('clients', 'crew_count', 'SMALLINT UNSIGNED NULL');
CALL calla_add_col('clients', 'services',   "VARCHAR(255) NOT NULL DEFAULT ''");  -- id layanan, dipisah koma
DROP PROCEDURE IF EXISTS calla_add_col;

-- Rangkaian acara per klien
CREATE TABLE IF NOT EXISTS client_segments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id  INT UNSIGNED NOT NULL,
  seg_key    VARCHAR(40) NOT NULL DEFAULT '',   -- id bawaan; kosong = segmen tambahan
  label      VARCHAR(80) NOT NULL,
  day_offset TINYINT NOT NULL DEFAULT 0,        -- -1 = H-1, 0 = hari-H
  start_time TIME NULL,
  end_time   TIME NULL,
  note       VARCHAR(190) NOT NULL DEFAULT '',
  sort_order SMALLINT NOT NULL DEFAULT 0,
  KEY idx_seg_client (client_id, day_offset, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
