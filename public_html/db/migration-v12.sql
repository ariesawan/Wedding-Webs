-- =====================================================================
-- Callalily Party CMS — migration-v12.sql
-- Jalankan SETELAH migration-v11.sql.
--
-- Isi: terjemahan disimpan di database supaya bisa ditulis ulang dari
-- panel, bukan dikunci di berkas PHP.
--
-- Berkas inc/lang/en.php tetap ada dan tetap dipakai — tapi turun pangkat
-- jadi BIBIT, bukan sumber kebenaran. Urutan bacanya:
--   1. tabel translations   (hasil suntingan owner — paling kuat)
--   2. inc/lang/en.php      (terjemahan bawaan)
--   3. teks Indonesia asli  (kalau dua-duanya kosong)
--
-- Dengan begitu terjemahan yang tidak cocok bisa diperbaiki sendiri tanpa
-- menunggu deploy, dan memperbarui berkas bibit tidak akan menimpa
-- suntingan yang sudah dibuat.
-- =====================================================================

SET NAMES utf8mb4;
START TRANSACTION;

CREATE TABLE IF NOT EXISTS `translations` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lang`    VARCHAR(5) NOT NULL DEFAULT 'en',
  `src_hash` CHAR(40) NOT NULL
      COMMENT 'sha1 teks sumber — dipakai sebagai kunci unik',
  `src`     TEXT NOT NULL COMMENT 'Teks Indonesia apa adanya, termasuk tag <em>/<b>',
  `dst`     TEXT NULL COMMENT 'Terjemahan. NULL/kosong = pakai bibit atau teks asli',
  `dipakai` TINYINT(1) NOT NULL DEFAULT 1
      COMMENT '0 = tidak lagi ditemukan di kode, disimpan agar suntingan tidak hilang',
  `updated_by` INT UNSIGNED NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tr` (`lang`, `src_hash`),
  KEY `idx_tr_pakai` (`lang`, `dipakai`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

-- =====================================================================
-- VERIFIKASI
-- =====================================================================
-- SELECT COUNT(*) FROM translations;
-- SELECT COUNT(*) FROM translations WHERE dst <> '';   -- sudah diterjemahkan
