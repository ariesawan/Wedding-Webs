-- ============================================================
-- MIGRASI v1 -> v2
-- Untuk instalasi yang SUDAH berjalan. Jalankan sekali:
--   mysql -u USER -p NAMA_DB < db/migration-v2.sql
-- Aman diulang: semua pakai IF NOT EXISTS / IGNORE.
-- ============================================================

SET NAMES utf8mb4;

-- ---------- Reset kata sandi ----------
CREATE TABLE IF NOT EXISTS password_resets (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,          -- SHA-256 dari token; token asli tidak pernah disimpan
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  ip          VARCHAR(45) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reset_token (token_hash),
  KEY idx_reset_user (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Pipeline klien ----------
CREATE TABLE IF NOT EXISTS clients (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(120) NOT NULL,          -- pihak yang dihubungi
  partner_name    VARCHAR(120) NOT NULL DEFAULT '',
  email           VARCHAR(190) NOT NULL DEFAULT '',
  phone           VARCHAR(40)  NOT NULL DEFAULT '',
  instagram       VARCHAR(80)  NOT NULL DEFAULT '',
  source          ENUM('instagram','whatsapp','web','referral','vendor','walkin','lainnya') NOT NULL DEFAULT 'lainnya',

  stage           ENUM('baru','meeting','penawaran','negosiasi','deal','persiapan','harih','selesai','batal')
                  NOT NULL DEFAULT 'baru',
  stage_changed_at DATETIME NULL,

  wedding_date    DATE NULL,
  wedding_time    TIME NULL,
  venue           VARCHAR(190) NOT NULL DEFAULT '',
  city            VARCHAR(120) NOT NULL DEFAULT 'Yogyakarta',
  guest_estimate  SMALLINT UNSIGNED NULL,
  package         VARCHAR(120) NOT NULL DEFAULT '',
  budget_estimate DECIMAL(14,2) NULL,             -- perkiraan saat prospek
  deal_value      DECIMAL(14,2) NULL,             -- nilai kontrak setelah deal

  next_action     VARCHAR(190) NOT NULL DEFAULT '',
  next_action_at  DATE NULL,

  lost_reason     VARCHAR(255) NOT NULL DEFAULT '',
  lost_at         DATETIME NULL,

  event_id        INT UNSIGNED NULL,              -- dibuat otomatis saat masuk tahap deal
  notes           TEXT NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_clients_stage (stage, next_action_at),
  KEY idx_clients_wedding (wedding_date),
  KEY idx_clients_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Jejak aktivitas per klien ----------
CREATE TABLE IF NOT EXISTS client_activities (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  type       ENUM('catatan','tahap','meeting','bayar','tugas','sistem') NOT NULL DEFAULT 'catatan',
  title      VARCHAR(190) NOT NULL,
  detail     TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_act_client (client_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Termin pembayaran ----------
CREATE TABLE IF NOT EXISTS payments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id  INT UNSIGNED NOT NULL,
  label      VARCHAR(80) NOT NULL,
  amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
  due_date   DATE NULL,
  paid_at    DATE NULL,
  method     VARCHAR(60) NOT NULL DEFAULT '',
  note       VARCHAR(255) NOT NULL DEFAULT '',
  sort_order SMALLINT NOT NULL DEFAULT 0,
  KEY idx_pay_client (client_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Checklist persiapan (dihitung mundur dari hari-H) ----------
CREATE TABLE IF NOT EXISTS client_tasks (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id  INT UNSIGNED NOT NULL,
  title      VARCHAR(190) NOT NULL,
  detail     VARCHAR(255) NOT NULL DEFAULT '',
  offset_day SMALLINT NOT NULL DEFAULT 0,    -- -90 = H-90; 0 = hari-H
  due_date   DATE NULL,
  done_at    DATETIME NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  KEY idx_task_client (client_id, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Kaitkan meeting ke klien ----------
-- MySQL 5.7 tidak punya "ADD COLUMN IF NOT EXISTS", jadi dibungkus prosedur.
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

CALL calla_add_col('meetings', 'client_id', 'INT UNSIGNED NULL');
CALL calla_add_col('meetings', 'outcome',   "ENUM('','lanjut','pikir','batal') NOT NULL DEFAULT ''");
CALL calla_add_col('meetings', 'outcome_note', 'VARCHAR(500) NOT NULL DEFAULT \'\'');
DROP PROCEDURE IF EXISTS calla_add_col;

-- ---------- Pengaturan baru ----------
INSERT IGNORE INTO settings (k, v, is_secret) VALUES
  ('sheet_id',          '', 0),
  ('sheet_autosync',    '0', 0),
  ('currency_prefix',   'Rp', 0),
  ('dp_percent',        '30', 0);
