-- =====================================================================
-- Callalily Party CMS — migration-v23.sql  (gabungan v22 + v23)
-- =====================================================================
--
-- BIASANYA TIDAK PERLU DIJALANKAN MANUAL.
--
-- Kolom dan tabel yang dibutuhkan kode ditambahkan sendiri saat halaman
-- pertama dibuka setelah unggah (inc/skema.php). Berkas ini cadangan untuk
-- hosting yang menolak ALTER TABLE dari PHP — panel menampilkan peringatan
-- kuning kalau itu terjadi.
--
-- Isinya:
--   v19–v21  tawar-menawar, template, tamu akad/resepsi, kategori WO
--   v23      tahap "Menunggu DP", paket price list, konsep dekor,
--            log formulir (form_masuk)
--
-- Paket contoh (Prasaja, Semanak, Sidomukti, Template kosong) TIDAK ditulis
-- di sini — dibuat otomatis saat halaman pertama dibuka setelah berkas ini
-- dijalankan.
--
-- Aman dijalankan berulang kali. Jalankan lewat phpMyAdmin → tab SQL.
--
-- Backup dulu:
--   mysqldump -u USER -p NAMADB > backup-pre-v23-$(date +%F).sql
-- =====================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `v23_tambah_kolom`;
DELIMITER $$
CREATE PROCEDURE `v23_tambah_kolom`(IN p_tabel VARCHAR(64), IN p_kolom VARCHAR(64), IN p_def TEXT)
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
CALL v23_tambah_kolom('quotes', 'nego_nilai',   'DECIMAL(14,2) NULL COMMENT ''Angka yang diminta klien saat menawar''');
CALL v23_tambah_kolom('quotes', 'nego_catatan', 'VARCHAR(400) NOT NULL DEFAULT '''' COMMENT ''Alasan klien menawar''');
CALL v23_tambah_kolom('quotes', 'nego_at',      'DATETIME NULL');
CALL v23_tambah_kolom('quotes', 'revisi_dari',  'INT UNSIGNED NULL COMMENT ''quotes.id yang direvisi''');

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

CALL v23_tambah_kolom('quotes', 'template_id', 'INT UNSIGNED NULL COMMENT ''Template asal, kalau dibuat dari template''');

-- ---- v21: tamu akad & resepsi ----
CALL v23_tambah_kolom('client_wedding_info', 'tamu_akad',    'INT UNSIGNED NULL COMMENT ''Perkiraan tamu akad / pemberkatan''');
CALL v23_tambah_kolom('client_wedding_info', 'tamu_resepsi', 'INT UNSIGNED NULL COMMENT ''Perkiraan tamu resepsi''');

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


-- ---- v23: tahap "Menunggu DP" ----
-- ENUM ditulis lengkap. Prosedur menolak berjalan kalau ada nilai tahap di
-- data yang tidak dikenal (nilai seperti itu akan berubah jadi kosong).
DROP PROCEDURE IF EXISTS `v23_tahap_dp`;
DELIMITER $$
CREATE PROCEDURE `v23_tahap_dp`()
BEGIN
    DECLARE tipe TEXT;
    DECLARE asing INT DEFAULT 0;
    SELECT COLUMN_TYPE INTO tipe FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'stage';
    IF tipe LIKE '%''dp''%' THEN
        SELECT 'sudah ada : clients.stage dp' AS hasil;
    ELSE
        SELECT COUNT(*) INTO asing FROM clients
         WHERE stage NOT IN ('baru','pricelist','spesifikasi','penawaran','deal','persiapan','harih','selesai','batal');
        IF asing > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ada nilai stage yang tidak dikenal. Hubungi pengembang sebelum melanjutkan.';
        END IF;
        ALTER TABLE clients MODIFY COLUMN stage
          ENUM('baru','pricelist','dp','spesifikasi','penawaran','deal','persiapan','harih','selesai','batal')
          NOT NULL DEFAULT 'baru';
        SELECT 'DITAMBAH  : clients.stage dp' AS hasil;
    END IF;
END$$
DELIMITER ;
CALL v23_tahap_dp();
DROP PROCEDURE IF EXISTS `v23_tahap_dp`;

-- Tahap lama Spesifikasi & Penawaran tidak dipakai lagi (belum DP → admin early).
UPDATE clients SET stage = 'pricelist' WHERE stage IN ('spesifikasi','penawaran');

CALL v23_tambah_kolom('clients', 'paket_minat', 'INT UNSIGNED NULL COMMENT ''quote_templates.id yang dipilih klien''');

-- ---- v23: paket price list ----
CALL v23_tambah_kolom('quote_templates', 'slug',        'VARCHAR(140) NOT NULL DEFAULT ''''');
CALL v23_tambah_kolom('quote_templates', 'ringkas',     'VARCHAR(190) NOT NULL DEFAULT '''' COMMENT ''Satu kalimat di bawah nama paket''');
CALL v23_tambah_kolom('quote_templates', 'harga',       'DECIMAL(14,2) NULL COMMENT ''Harga paket; NULL = belum diisi''');
CALL v23_tambah_kolom('quote_templates', 'harga_mulai', 'TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''1 = ditulis mulai dari''');
CALL v23_tambah_kolom('quote_templates', 'tamu',        'SMALLINT UNSIGNED NULL COMMENT ''Perkiraan tamu paket''');
CALL v23_tambah_kolom('quote_templates', 'tampil_web',  'TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = tampil di halaman price list''');
CALL v23_tambah_kolom('quote_templates', 'unggulan',    'TINYINT(1) NOT NULL DEFAULT 0');
CALL v23_tambah_kolom('quote_template_items', 'kelompok', 'VARCHAR(80) NOT NULL DEFAULT '''' COMMENT ''Judul kelompok rincian isi''');
CALL v23_tambah_kolom('quotes', 'paket_nama',  'VARCHAR(120) NOT NULL DEFAULT ''''');
CALL v23_tambah_kolom('quotes', 'paket_harga', 'DECIMAL(14,2) NULL');
CALL v23_tambah_kolom('quote_items', 'kelompok', 'VARCHAR(80) NOT NULL DEFAULT ''''');

-- ---- v23: konsep dekor ----
CALL v23_tambah_kolom('client_wedding_info', 'konsep_dekor', 'TEXT NULL COMMENT ''Tema, warna, referensi dekor''');

-- ---- v23: log formulir — setiap kiriman tercatat, termasuk yang gagal ----
CREATE TABLE IF NOT EXISTS `form_masuk` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `status` ENUM('tersimpan','ulang','galat','ditolak','bot') NOT NULL,
  `client_id` INT UNSIGNED NULL,
  `nama` VARCHAR(190) NOT NULL DEFAULT '',
  `wa` VARCHAR(40) NOT NULL DEFAULT '',
  `paket` VARCHAR(140) NOT NULL DEFAULT '',
  `pesan` VARCHAR(400) NOT NULL DEFAULT '',
  `payload` MEDIUMTEXT NULL,
  `ditangani` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_form_waktu` (`created_at`), KEY `idx_form_status` (`status`),
  KEY `idx_form_wa` (`wa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS `v23_tambah_kolom`;

-- Penanda: kode tidak perlu mencoba lagi. paket_benih sengaja tidak disentuh
-- supaya paket contoh dibuat otomatis saat halaman berikutnya dibuka.
INSERT INTO `settings` (`k`, `v`, `is_secret`) VALUES ('skema_versi', '23', 0), ('skema_galat', '', 0)
ON DUPLICATE KEY UPDATE `v` = VALUES(`v`);

-- =====================================================================
-- VERIFIKASI — harus: kolom_v19 = 4, kolom_tamu = 2, kategori_wo = 1,
--              tahap_dp = 1, kolom_paket = 7, tabel_form = 1
-- =====================================================================
SELECT
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes'
      AND COLUMN_NAME IN ('nego_nilai','nego_catatan','nego_at','revisi_dari'))    AS kolom_v19,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_wedding_info'
      AND COLUMN_NAME IN ('tamu_akad','tamu_resepsi'))                             AS kolom_tamu,
  (SELECT COUNT(*) FROM vendor_categories WHERE slug = 'wedding-organizer')        AS kategori_wo,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'stage'
      AND COLUMN_TYPE LIKE '%''dp''%')                                             AS tahap_dp,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quote_templates'
      AND COLUMN_NAME IN ('slug','ringkas','harga','harga_mulai','tamu','tampil_web','unggulan')) AS kolom_paket,
  (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'form_masuk')                 AS tabel_form;
