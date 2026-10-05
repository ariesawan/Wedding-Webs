-- =====================================================================
-- Callalily Party CMS — migration-v10.sql
-- Jalankan SETELAH migration-v9.sql.
--
-- Isi: Top 5 vendor menjadi per KATEGORI, bukan lima baris global.
--
-- Sebelumnya kunci uniknya (client_id, urutan) — artinya satu klien hanya
-- bisa punya lima nama vendor untuk seluruh acara. Padahal maksud catatan
-- owner: tiap jenis vendor yang dicentang punya daftar incarannya sendiri.
-- Klien yang butuh meja dan lighting punya lima calon vendor meja DAN lima
-- calon vendor lighting, bukan lima nama untuk dibagi berdua.
--
-- Backup dulu:
--   mysqldump -u USER -p NAMADB > backup-pre-v10-$(date +%F).sql
-- =====================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- category_id harus punya nilai supaya bisa masuk kunci unik.
-- Di MySQL, NULL tidak pernah dianggap bentrok pada UNIQUE, jadi kolom
-- nullable akan membuat penjagaan duplikatnya bocor.
UPDATE `client_top_vendors` SET `category_id` = 0 WHERE `category_id` IS NULL;

ALTER TABLE `client_top_vendors`
  MODIFY COLUMN `category_id` INT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE `client_top_vendors`
  DROP INDEX `uq_ctv`,
  ADD UNIQUE KEY `uq_ctv` (`client_id`, `category_id`, `urutan`),
  ADD KEY `idx_ctv_cat` (`category_id`);

COMMIT;

-- =====================================================================
-- VERIFIKASI
-- =====================================================================
-- SHOW INDEX FROM client_top_vendors WHERE Key_name = 'uq_ctv';
--   -> harus memuat 3 kolom: client_id, category_id, urutan
