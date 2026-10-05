-- =====================================================================
-- Callalily Party CMS — migration-v13.sql
-- Jalankan SETELAH migration-v12.sql.
--
-- Isi: data demografi mempelai.
--
-- Kenapa baru sekarang: halaman Analisa diminta menghasilkan setelan iklan,
-- dan setelan pertama di Meta Ads Manager maupun TikTok Ads adalah RENTANG
-- USIA. Data itu tidak pernah dikumpulkan, jadi grafiknya memang tidak
-- mungkin dibuat — bukan soal tampilan, memang tidak ada angkanya.
--
-- Usia disimpan sebagai angka, bukan tanggal lahir. Dua alasan:
--   1. WO jarang tahu tanggal lahir klien, tapi hampir selalu tahu kisaran
--      usianya dari obrolan. Kolom yang tidak pernah terisi tidak berguna.
--   2. Yang dibutuhkan iklan adalah rentang (25–29, 30–34), bukan tanggal.
-- Konsekuensinya usia menjadi usang seiring waktu — itu diterima, karena
-- yang dipakai adalah usia SAAT mereka jadi klien, dan itu memang tetap.
--
-- Backup dulu:
--   mysqldump -u USER -p NAMADB > backup-pre-v13-$(date +%F).sql
-- =====================================================================

SET NAMES utf8mb4;
START TRANSACTION;

ALTER TABLE `clients`
  ADD COLUMN `usia_pria`   TINYINT UNSIGNED NULL COMMENT 'Usia saat jadi klien',
  ADD COLUMN `usia_wanita` TINYINT UNSIGNED NULL,
  ADD COLUMN `kerja_pria`   VARCHAR(80) NOT NULL DEFAULT ''
      COMMENT 'Pekerjaan — proksi kelas pendapatan untuk penargetan iklan',
  ADD COLUMN `kerja_wanita` VARCHAR(80) NOT NULL DEFAULT '';

-- Indeks gabungan: laporan usia selalu disaring tahap dulu, baru diagregasi.
ALTER TABLE `clients`
  ADD KEY `idx_clients_usia` (`stage`, `usia_pria`, `usia_wanita`);

COMMIT;

-- =====================================================================
-- VERIFIKASI
-- =====================================================================
-- SELECT COUNT(*) total,
--        SUM(usia_pria IS NOT NULL)   ada_pria,
--        SUM(usia_wanita IS NOT NULL) ada_wanita
--   FROM clients;
