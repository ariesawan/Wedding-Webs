-- =====================================================================
-- Callalily Party CMS — migration-v19.sql
-- Jalankan SETELAH migration-v18.sql.
--
-- Isi: mencatat TAWAR-MENAWAR pada penawaran.
--
-- Tabel quotes sudah menyimpan revisi, status, dan total sejak awal, tapi
-- tidak pernah menyimpan angka yang DIMINTA klien — hanya angka yang kita
-- ajukan. Padahal justru selisih itu yang menentukan revisi berikutnya, dan
-- itu yang selama ini cuma hidup di chat WhatsApp.
--
-- Alurnya jadi terbaca utuh:
--   r1  kita 40jt  →  klien minta 33jt  →  status 'revisi'
--   r2  kita 37jt  →  klien setuju      →  status 'cocok'
-- Admin office yang menerima klien ini bisa melihat seluruh riwayatnya,
-- termasuk apa yang dikorbankan untuk mencapai angka itu.
--
-- Aman diulang: memakai pemeriksaan information_schema seperti v17b.
--
--   mysqldump -u USER -p NAMADB > backup-pre-v19-$(date +%F).sql
-- =====================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `v19_tambah_kolom`;
DELIMITER $$
CREATE PROCEDURE `v19_tambah_kolom`(IN p_tabel VARCHAR(64), IN p_kolom VARCHAR(64), IN p_def TEXT)
BEGIN
    DECLARE ada INT DEFAULT 0;
    SELECT COUNT(*) INTO ada FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabel AND COLUMN_NAME = p_kolom;
    IF ada = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_tabel, '` ADD COLUMN `', p_kolom, '` ', p_def);
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
        SELECT CONCAT('DITAMBAH  : ', p_tabel, '.', p_kolom) AS hasil;
    ELSE
        SELECT CONCAT('sudah ada : ', p_tabel, '.', p_kolom) AS hasil;
    END IF;
END$$
DELIMITER ;

-- Angka yang diminta klien, bukan yang kita ajukan.
CALL v19_tambah_kolom('quotes', 'nego_nilai',
    'DECIMAL(14,2) NULL COMMENT ''Angka yang diminta klien saat menawar''');

-- Alasan menawar. Ini yang dibaca saat menyusun revisi: "kejauhan dari
-- budget mertua" beda penanganannya dengan "dekorasi terlalu ramai".
CALL v19_tambah_kolom('quotes', 'nego_catatan',
    'VARCHAR(400) NOT NULL DEFAULT '''' COMMENT ''Alasan klien menawar''');

CALL v19_tambah_kolom('quotes', 'nego_at', 'DATETIME NULL');

-- Menandai revisi ini lahir dari penawaran yang mana. Tanpa ini, urutan
-- revisi hanya bisa ditebak dari nomor — dan nomor bisa meleset kalau ada
-- penawaran yang dibuat lalu dibatalkan.
CALL v19_tambah_kolom('quotes', 'revisi_dari',
    'INT UNSIGNED NULL COMMENT ''quotes.id yang direvisi''');

DROP PROCEDURE IF EXISTS `v19_tambah_kolom`;


-- =====================================================================
-- VERIFIKASI — harus 4
-- =====================================================================
SELECT COUNT(*) AS kolom_v19
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes'
   AND COLUMN_NAME IN ('nego_nilai','nego_catatan','nego_at','revisi_dari');
