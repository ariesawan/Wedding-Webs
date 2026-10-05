-- =====================================================================
-- Callalily Party CMS — migration-v21.sql
-- Jalankan SETELAH migration-v20.sql.
--
-- Isi dua hal:
--   1. Tamu akad dan tamu resepsi dipisah
--   2. Kategori "Wedding Organizer" — satu-satunya yang dicentang otomatis
--
-- Soal tamu: selama ini hanya ada clients.guest_estimate, satu angka. Padahal
-- akad dan resepsi hampir selalu beda jauh — akad 100 orang di masjid, resepsi
-- 500 di gedung. Satu angka memaksa admin memilih mana yang dicatat, dan
-- angka yang tidak dicatat itu justru yang menentukan biaya catering,
-- kursi, dan tenda.
--
-- guest_estimate TIDAK dihapus: dia tetap jadi angka utama untuk penawaran
-- dan daftar klien, diturunkan dari tamu resepsi lalu tamu akad — aturan yang
-- sama dengan wedding_date. Menghapusnya berarti menyentuh belasan query di
-- penawaran, analisa, dan papan pipeline demi keuntungan nol.
--
-- Aman diulang.
--   mysqldump -u USER -p NAMADB > backup-pre-v21-$(date +%F).sql
-- =====================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `v21_tambah_kolom`;
DELIMITER $$
CREATE PROCEDURE `v21_tambah_kolom`(IN p_tabel VARCHAR(64), IN p_kolom VARCHAR(64), IN p_def TEXT)
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


-- ---------------------------------------------------------------------
-- 1. TAMU AKAD DAN TAMU RESEPSI
-- ---------------------------------------------------------------------
-- Ditaruh di client_wedding_info, bukan clients, karena satu baris dengan
-- akad_tanggal dan resepsi_tanggal — fakta tentang acaranya, bukan tentang
-- kliennya.
CALL v21_tambah_kolom('client_wedding_info', 'tamu_akad',
    'INT UNSIGNED NULL COMMENT ''Perkiraan tamu akad / pemberkatan''');
CALL v21_tambah_kolom('client_wedding_info', 'tamu_resepsi',
    'INT UNSIGNED NULL COMMENT ''Perkiraan tamu resepsi''');

-- Angka lama dipindahkan ke tamu_resepsi. Alasannya: saat hanya ada satu
-- kolom, yang ditulis admin hampir selalu angka resepsi — itu yang dipakai
-- menghitung catering dan kursi. Menebaknya sebagai akad akan salah untuk
-- hampir semua baris yang sudah ada.
UPDATE client_wedding_info wi
  JOIN clients c ON c.id = wi.client_id
   SET wi.tamu_resepsi = c.guest_estimate
 WHERE wi.tamu_resepsi IS NULL
   AND c.guest_estimate IS NOT NULL
   AND c.guest_estimate > 0;


-- ---------------------------------------------------------------------
-- 2. KATEGORI WEDDING ORGANIZER
-- ---------------------------------------------------------------------
-- Belum pernah ada, padahal inilah jasa yang sebenarnya dijual Callalily —
-- sisanya vendor yang dikoordinasikan. Karena itu dia satu-satunya yang
-- dicentang otomatis di formulir publik: klien yang mengisi formulir
-- wedding organizer memang sudah pasti membutuhkan wedding organizer.
--
-- urutan 5 supaya muncul paling depan, sebelum Make Up (10).
INSERT INTO `vendor_categories` (`slug`, `nama`, `urutan`, `is_custom`, `is_active`, `ikon`, `ringkas`, `is_public`)
SELECT 'wedding-organizer', 'Wedding Organizer', 5, 0, 1, '', 'Koordinasi seluruh rangkaian acara, dari persiapan sampai hari-H.', 0
 WHERE NOT EXISTS (SELECT 1 FROM `vendor_categories` WHERE `slug` = 'wedding-organizer');

DROP PROCEDURE IF EXISTS `v21_tambah_kolom`;


-- =====================================================================
-- VERIFIKASI
-- =====================================================================
SELECT (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_wedding_info'
           AND COLUMN_NAME IN ('tamu_akad','tamu_resepsi'))          AS kolom_tamu,
       (SELECT COUNT(*) FROM vendor_categories
         WHERE slug = 'wedding-organizer')                            AS kategori_wo,
       (SELECT COUNT(*) FROM client_wedding_info WHERE tamu_resepsi IS NOT NULL) AS terisi_dari_lama;
