-- ============================================================
-- MIGRASI v6 -> v7  ·  Room WhatsApp dua arah
--   mysql -u USER -p NAMA_DB < db/migration-v7.sql
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

-- Status pengiriman tiap pesan
CALL calla_add_col('vendor_messages', 'wa_status', "ENUM('lokal','antre','terkirim','sampai','dibaca','gagal') NOT NULL DEFAULT 'lokal'");
CALL calla_add_col('vendor_messages', 'wa_id',     'VARCHAR(120) NULL');
CALL calla_add_col('vendor_messages', 'wa_error',  'VARCHAR(400) NULL');
CALL calla_add_col('vendor_messages', 'sent_at',   'DATETIME NULL');

-- Nomor pengirim disimpan supaya balasan bisa dicocokkan
CALL calla_add_col('vendor_messages', 'wa_from',   'VARCHAR(30) NOT NULL DEFAULT ""');
DROP PROCEDURE IF EXISTS calla_add_col;

-- Pesan masuk yang belum bisa dicocokkan ke vendor/klien mana pun.
-- Tanpa ini, balasan dari nomor yang belum terdaftar akan hilang begitu saja.
CREATE TABLE IF NOT EXISTS wa_inbox (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wa_from    VARCHAR(30) NOT NULL,
  nama       VARCHAR(120) NOT NULL DEFAULT '',
  body       TEXT NOT NULL,
  wa_id      VARCHAR(120) NULL,
  handled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_inbox (handled_at, created_at),
  KEY idx_inbox_from (wa_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catatan mentah dari penyedia, untuk menelusuri kalau ada yang tidak sampai
CREATE TABLE IF NOT EXISTS wa_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  arah       ENUM('masuk','keluar') NOT NULL,
  payload    MEDIUMTEXT NULL,
  http_code  SMALLINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_walog (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (k, v, is_secret) VALUES
  ('wa_provider',      'none', 0),
  ('wa_phone_id',      '',     0),
  ('wa_token',         '',     1),
  ('wa_verify_token',  '',     1),
  ('wa_gateway_url',   '',     0),
  ('wa_gateway_token', '',     1),
  ('wa_gateway_ftarget','target', 0),
  ('wa_gateway_fbody', 'message', 0),
  ('wa_gateway_auth',  'header', 0);
