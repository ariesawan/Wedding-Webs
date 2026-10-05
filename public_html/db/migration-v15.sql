-- =====================================================================
-- Callalily Party CMS — migration-v15.sql
-- Jalankan SETELAH migration-v14.sql.
--
-- Isi: Top 5 vendor berubah arti — dari lima NAMA vendor menjadi lima
--      KATEGORI yang diprioritaskan.
--
-- Salah paham sebelumnya: "Top 5 vendor" dikira daftar nama vendor incaran
-- klien, jadi tiap kategori yang dicentang membuka lima kolom teks kosong.
-- Yang dimaksud owner berbeda dan lebih berguna: dari semua jenis vendor
-- yang dibutuhkan, MANA LIMA yang harus dapat kualitas terbaik.
--
-- Kenapa ini penting: langsung menyambung ke mode budgeting. Lima kategori
-- teratas mendapat alokasi utama, sisanya menyesuaikan anggaran yang tersisa.
-- Tanpa peringkat ini, plafon dibagi rata — dan pembagian rata hampir selalu
-- salah, karena tidak ada pasangan yang menganggap souvenir sepenting venue.
--
-- Backup dulu:
--   mysqldump -u USER -p NAMADB > backup-pre-v15-$(date +%F).sql
-- =====================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- Baris lama berisi nama vendor bebas yang sekarang tidak punya arti lagi.
-- Dihapus, bukan dibiarkan: baris tanpa category_id yang sah akan muncul
-- sebagai prioritas kosong dan membingungkan pembacanya.
DELETE FROM `client_top_vendors` WHERE `category_id` IS NULL OR `category_id` = 0;

ALTER TABLE `client_top_vendors`
  DROP INDEX `uq_ctv`;

-- Satu peringkat hanya boleh diisi satu kategori, dan satu kategori hanya
-- boleh muncul di satu peringkat. Dua kunci unik, dua kesalahan berbeda
-- yang keduanya nyata: "prioritas 1 dan 2 sama-sama Venue" dan
-- "Venue muncul di prioritas 1 sekaligus 4".
ALTER TABLE `client_top_vendors`
  MODIFY COLUMN `category_id` INT UNSIGNED NOT NULL,
  MODIFY COLUMN `nama` VARCHAR(160) NOT NULL DEFAULT ''
      COMMENT 'Tidak dipakai lagi sejak v15 — disimpan untuk data lama',
  ADD UNIQUE KEY `uq_ctv_urutan` (`client_id`, `urutan`),
  ADD UNIQUE KEY `uq_ctv_kat`    (`client_id`, `category_id`);

COMMIT;

-- =====================================================================
-- VERIFIKASI
-- =====================================================================
-- SHOW INDEX FROM client_top_vendors WHERE Key_name LIKE 'uq_ctv%';
--   -> uq_ctv_urutan (client_id, urutan)
--   -> uq_ctv_kat    (client_id, category_id)
--
-- SELECT c.name, t.urutan, vc.nama
--   FROM client_top_vendors t
--   JOIN clients c ON c.id = t.client_id
--   JOIN vendor_categories vc ON vc.id = t.category_id
--  ORDER BY t.client_id, t.urutan;
