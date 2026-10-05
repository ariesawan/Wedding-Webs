-- ============================================================
-- MIGRASI v4 -> v5  ·  Vendor & percakapan per pesta
--   mysql -u USER -p NAMA_DB < db/migration-v5.sql
-- Aman diulang.
-- ============================================================
SET NAMES utf8mb4;

-- Daftar vendor induk — dipakai ulang di banyak pesta
CREATE TABLE IF NOT EXISTS vendors (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  category   VARCHAR(40)  NOT NULL DEFAULT 'lainnya',
  phone      VARCHAR(40)  NOT NULL DEFAULT '',
  email      VARCHAR(190) NOT NULL DEFAULT '',
  instagram  VARCHAR(80)  NOT NULL DEFAULT '',
  city       VARCHAR(80)  NOT NULL DEFAULT '',
  pic_name   VARCHAR(80)  NOT NULL DEFAULT '',
  price_note VARCHAR(190) NOT NULL DEFAULT '',
  rating     TINYINT UNSIGNED NULL,
  note       TEXT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vendor_kat (category, is_active),
  KEY idx_vendor_nama (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vendor yang dipakai pesta tertentu, beserta statusnya
CREATE TABLE IF NOT EXISTS client_vendors (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id  INT UNSIGNED NOT NULL,
  vendor_id  INT UNSIGNED NOT NULL,
  status     ENUM('dipertimbangkan','dihubungi','nego','deal','batal') NOT NULL DEFAULT 'dipertimbangkan',
  price      DECIMAL(14,2) NULL,
  note       VARCHAR(255) NOT NULL DEFAULT '',
  sort_order SMALLINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cv (client_id, vendor_id),
  KEY idx_cv_client (client_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Riwayat percakapan: SELALU terikat pasangan (klien, vendor).
-- Inilah yang membuat obrolan soal pesta A tidak tercampur dengan pesta B
-- meski vendornya sama.
CREATE TABLE IF NOT EXISTS vendor_messages (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id  INT UNSIGNED NOT NULL,
  vendor_id  INT UNSIGNED NOT NULL,
  direction  ENUM('keluar','masuk','catatan') NOT NULL DEFAULT 'keluar',
  channel    ENUM('wa','telepon','email','tatap','lainnya') NOT NULL DEFAULT 'wa',
  body       TEXT NOT NULL,
  user_id    INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vm (client_id, vendor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (k, v, is_secret) VALUES ('wa_template_vendor', '', 0);
