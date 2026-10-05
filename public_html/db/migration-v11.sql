-- =====================================================================
-- Callalily Party CMS — migration-v11.sql
-- Jalankan SETELAH migration-v10.sql.
--
-- Isi: wa_log bisa mencatat webhook yang DITOLAK.
--
-- Sebelumnya kolom arah hanya mengenal 'masuk' dan 'keluar'. Akibatnya
-- webhook yang ditolak karena token tidak cocok tidak meninggalkan jejak
-- yang bisa dibedakan — dari panel, "Fonnte tidak pernah memanggil" dan
-- "dipanggil tapi ditolak" terlihat persis sama: balasan tidak masuk.
-- =====================================================================

SET NAMES utf8mb4;
START TRANSACTION;

ALTER TABLE `wa_log`
  MODIFY COLUMN `arah` ENUM('masuk','keluar','tolak') NOT NULL;

COMMIT;

-- =====================================================================
-- VERIFIKASI
-- =====================================================================
-- SELECT arah, COUNT(*) FROM wa_log GROUP BY arah;
-- SELECT created_at, arah, LEFT(payload,120) FROM wa_log ORDER BY id DESC LIMIT 10;
