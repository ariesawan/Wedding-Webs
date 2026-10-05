-- =====================================================================
-- Callalily Party CMS — migration-v22.sql
-- =====================================================================
--
-- BIASANYA TIDAK PERLU DIJALANKAN MANUAL.
--
-- Mulai v22, kolom yang dibutuhkan kode ditambahkan sendiri saat halaman
-- pertama dibuka setelah unggah (inc/skema.php). Berkas ini cadangan untuk
-- hosting yang menolak ALTER TABLE dari PHP — panel akan menampilkan
-- peringatan kuning kalau itu terjadi.
--
-- Isinya gabungan semua yang tertinggal di server per 5 Oktober 2026:
--   v19  tawar-menawar pada penawaran   (BELUM jalan di server)
--   v20  template penawaran             (sudah jalan — dilewati)
--   v21  tamu akad/resepsi + kategori WO (BELUM jalan di server)
--
-- Aman dijalankan berulang kali. Jalankan lewat phpMyAdmin → tab SQL.
--
-- Backup dulu:
--   mysqldump -u USER -p NAMADB > backup-pre-v22-$(date +%F).sql
-- =====================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `v22_tambah_kolom`;
DELIMITER $$
CREATE PROCEDURE `v22_tambah_kolom`(IN p_tabel VARCHAR(64), IN p_kolom VARCHAR(64), IN p_def TEXT)
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

-- ---- v19: tawar-menawar ----
CALL v22_tambah_kolom('quotes', 'nego_nilai',   'DECIMAL(14,2) NULL COMMENT ''Angka yang diminta klien saat menawar''');
CALL v22_tambah_kolom('quotes', 'nego_catatan', 'VARCHAR(400) NOT NULL DEFAULT '''' COMMENT ''Alasan klien menawar''');
CALL v22_tambah_kolom('quotes', 'nego_at',      'DATETIME NULL');
CALL v22_tambah_kolom('quotes', 'revisi_dari',  'INT UNSIGNED NULL COMMENT ''quotes.id yang direvisi''');

-- ---- v20: template penawaran ----
CREATE TABLE IF NOT EXISTS `quote_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama` VARCHAR(120) NOT NULL,
  `deskripsi` VARCHAR(400) NOT NULL DEFAULT '',
  `tipe` ENUM('semua','tematis','budgeting') NOT NULL DEFAULT 'semua',
  `catatan_bawaan` TEXT DEFAULT NULL,
  `urutan` SMALLINT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_tpl_aktif` (`is_active`, `urutan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quote_template_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_id` INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NULL,
  `label` VARCHAR(190) NOT NULL DEFAULT '',
  `detail` VARCHAR(400) NOT NULL DEFAULT '',
  `qty` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `satuan` VARCHAR(30) NOT NULL DEFAULT 'paket',
  `harga` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `opsional` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_tpl_item` (`template_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL v22_tambah_kolom('quotes', 'template_id', 'INT UNSIGNED NULL COMMENT ''Template asal, kalau dibuat dari template''');

-- ---- v21: tamu akad & resepsi ----
CALL v22_tambah_kolom('client_wedding_info', 'tamu_akad',    'INT UNSIGNED NULL COMMENT ''Perkiraan tamu akad / pemberkatan''');
CALL v22_tambah_kolom('client_wedding_info', 'tamu_resepsi', 'INT UNSIGNED NULL COMMENT ''Perkiraan tamu resepsi''');

UPDATE client_wedding_info wi
  JOIN clients c ON c.id = wi.client_id
   SET wi.tamu_resepsi = c.guest_estimate
 WHERE wi.tamu_resepsi IS NULL AND c.guest_estimate > 0;

-- ---- v21: kategori Wedding Organizer ----
INSERT INTO `vendor_categories` (`slug`, `nama`, `urutan`, `is_custom`, `is_active`, `ikon`, `ringkas`, `is_public`)
SELECT 'wedding-organizer', 'Wedding Organizer', 5, 0, 1, '',
       'Koordinasi seluruh rangkaian acara, dari persiapan sampai hari-H.', 0
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `vendor_categories` WHERE `slug` = 'wedding-organizer');

DROP PROCEDURE IF EXISTS `v22_tambah_kolom`;

-- Penanda: kode tidak perlu mencoba lagi.
INSERT INTO `settings` (`k`, `v`, `is_secret`) VALUES ('skema_versi', '22', 0), ('skema_galat', '', 0)
ON DUPLICATE KEY UPDATE `v` = VALUES(`v`);

-- =====================================================================
-- VERIFIKASI — harus: kolom_v19 = 4, kolom_tamu = 2, kategori_wo = 1
-- =====================================================================
SELECT
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes'
      AND COLUMN_NAME IN ('nego_nilai','nego_catatan','nego_at','revisi_dari'))    AS kolom_v19,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_wedding_info'
      AND COLUMN_NAME IN ('tamu_akad','tamu_resepsi'))                             AS kolom_tamu,
  (SELECT COUNT(*) FROM vendor_categories WHERE slug = 'wedding-organizer')        AS kategori_wo;
