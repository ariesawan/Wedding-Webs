-- =====================================================================
-- Callalily Party CMS — migration-v16.sql
-- Jalankan SETELAH migration-v15b.sql.
--
-- Isi: kategori vendor punya gambar sendiri.
--
-- Kenapa disimpan sebagai SLUG, bukan jalur lengkap:
-- saveGalleryImage() menghasilkan empat berkas dari satu unggahan —
-- <slug>.jpg, <slug>-thumb.jpg, dan pasangan .webp-nya. Menyimpan jalur
-- lengkap salah satunya berarti tiga sisanya harus ditebak ulang di setiap
-- tempat yang memakainya. Dengan slug, keempatnya dirangkai dari satu
-- sumber yang sama, dan penghapusan cukup memanggil deleteImageSet().
--
-- Backup dulu:
--   mysqldump -u USER -p NAMADB > backup-pre-v16-$(date +%F).sql
-- =====================================================================

SET NAMES utf8mb4;
START TRANSACTION;

ALTER TABLE `vendor_categories`
  ADD COLUMN `gambar` VARCHAR(120) NOT NULL DEFAULT ''
      COMMENT 'Slug berkas di DIR_FOTO, tanpa ekstensi. Kosong = tanpa gambar',
  ADD COLUMN `gambar_alt` VARCHAR(190) NOT NULL DEFAULT ''
      COMMENT 'Teks alternatif — dibaca pembaca layar dan dipakai Google Images',
  ADD COLUMN `gambar_w` SMALLINT UNSIGNED NULL,
  ADD COLUMN `gambar_h` SMALLINT UNSIGNED NULL,
  ADD COLUMN `gambar_webp` TINYINT(1) NOT NULL DEFAULT 0;

-- Lebar dan tinggi disimpan supaya bisa ditulis di atribut img. Tanpa itu
-- peramban tidak tahu ruang yang harus disiapkan sebelum gambarnya turun,
-- dan halaman melompat saat gambar muncul — Google menghitungnya sebagai
-- Cumulative Layout Shift, salah satu Core Web Vitals.

COMMIT;

-- =====================================================================
-- VERIFIKASI
-- =====================================================================
-- SELECT slug, nama, gambar, gambar_w, gambar_h FROM vendor_categories
--  WHERE is_public = 1 ORDER BY urutan;
