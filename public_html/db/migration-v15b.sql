-- =====================================================================
-- Callalily Party CMS — migration-v15b.sql  (PERBAIKAN)
--
-- Jalankan ini menggantikan migration-v15.sql.
-- AMAN dijalankan walau v15 sudah pernah jalan separuh dan berhenti dengan
-- galat #1062 — semua langkah diperiksa dulu keadaannya sebelum dikerjakan.
--
-- ---------------------------------------------------------------------
-- KENAPA v15 GAGAL
-- ---------------------------------------------------------------------
-- Galatnya: #1062 Duplicate entry '2-1' for key 'uq_ctv_urutan'
--
-- Klien nomor 2 punya dua baris dengan urutan = 1. Itu SAH di skema lama:
-- kunci uniknya (client_id, category_id, urutan), jadi tiap kategori punya
-- daftar peringkat 1–5 sendiri. Venue punya peringkat 1, Dekorasi juga
-- punya peringkat 1, dan keduanya tidak bertabrakan.
--
-- Di skema baru artinya berubah total: peringkat 1 berarti "kategori yang
-- paling diprioritaskan", dan hanya boleh ada satu. Baris lama tidak bisa
-- dikonversi — isinya nama vendor bebas, bukan kategori. Tidak ada cara
-- menebak kategori mana yang dimaksud klien sebagai prioritas pertama.
--
-- v15 hanya menghapus baris yang category_id-nya kosong. Yang bentrok
-- justru baris yang category_id-nya TERISI. Itu kelalaian saya.
--
-- Karena itu di bawah seluruh isi tabel dikosongkan. Data yang hilang
-- adalah nama-nama vendor yang sudah tidak punya tempat di skema baru.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Kosongkan seluruh baris lama
-- ---------------------------------------------------------------------
DELETE FROM `client_top_vendors`;

-- ---------------------------------------------------------------------
-- 2. Buang indeks lama, hanya bila masih ada
-- ---------------------------------------------------------------------
-- DROP INDEX IF EXISTS tidak tersedia di semua versi MySQL, jadi
-- keberadaannya diperiksa lewat information_schema lebih dulu. Tanpa ini,
-- menjalankan ulang berkas ini akan berhenti dengan galat "can't DROP".
SET @ada := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'client_top_vendors'
               AND INDEX_NAME = 'uq_ctv');
SET @sql := IF(@ada > 0,
    'ALTER TABLE `client_top_vendors` DROP INDEX `uq_ctv`',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------------
-- 3. category_id wajib terisi
-- ---------------------------------------------------------------------
ALTER TABLE `client_top_vendors`
  MODIFY COLUMN `category_id` INT UNSIGNED NOT NULL,
  MODIFY COLUMN `nama` VARCHAR(160) NOT NULL DEFAULT ''
      COMMENT 'Tidak dipakai lagi sejak v15 — disimpan untuk data lama';

-- ---------------------------------------------------------------------
-- 4. Dua kunci unik, dua kesalahan berbeda yang keduanya nyata
-- ---------------------------------------------------------------------
--   uq_ctv_urutan  menahan "prioritas 1 dan 2 sama-sama Venue"
--   uq_ctv_kat     menahan "Venue muncul di prioritas 1 sekaligus 4"

SET @ada := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'client_top_vendors'
               AND INDEX_NAME = 'uq_ctv_urutan');
SET @sql := IF(@ada = 0,
    'ALTER TABLE `client_top_vendors` ADD UNIQUE KEY `uq_ctv_urutan` (`client_id`,`urutan`)',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @ada := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'client_top_vendors'
               AND INDEX_NAME = 'uq_ctv_kat');
SET @sql := IF(@ada = 0,
    'ALTER TABLE `client_top_vendors` ADD UNIQUE KEY `uq_ctv_kat` (`client_id`,`category_id`)',
    'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- =====================================================================
-- VERIFIKASI
-- =====================================================================
-- Jalankan setelahnya. Harus mengembalikan dua baris:
--   uq_ctv_urutan  client_id, urutan
--   uq_ctv_kat     client_id, category_id
--
-- SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) kolom
--   FROM information_schema.STATISTICS
--  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_top_vendors'
--    AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY'
--  GROUP BY INDEX_NAME;
--
-- SELECT COUNT(*) FROM client_top_vendors;   -- 0, akan terisi lagi dari panel
