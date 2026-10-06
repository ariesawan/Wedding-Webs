-- =====================================================================
-- Callalily Party CMS — migration-v24.sql  (gabungan v22 + v23 + v24)
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
--   v24      termin pembayaran (cicilan, kwitansi, pengingat) dan
--            dashboard pengantin
--
-- Paket contoh (Prasaja, Semanak, Sidomukti, Template kosong) TIDAK ditulis
-- di sini — dibuat otomatis saat halaman pertama dibuka setelah berkas ini
-- dijalankan.
--
-- Aman dijalankan berulang kali. Jalankan lewat phpMyAdmin → tab SQL.
--
-- Backup dulu:
--   mysqldump -u USER -p NAMADB > backup-pre-v24-$(date +%F).sql
-- =====================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `v24_tambah_kolom`;
DELIMITER $$
CREATE PROCEDURE `v24_tambah_kolom`(IN p_tabel VARCHAR(64), IN p_kolom VARCHAR(64), IN p_def TEXT)
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
CALL v24_tambah_kolom('quotes', 'nego_nilai',   'DECIMAL(14,2) NULL COMMENT ''Angka yang diminta klien saat menawar''');
CALL v24_tambah_kolom('quotes', 'nego_catatan', 'VARCHAR(400) NOT NULL DEFAULT '''' COMMENT ''Alasan klien menawar''');
CALL v24_tambah_kolom('quotes', 'nego_at',      'DATETIME NULL');
CALL v24_tambah_kolom('quotes', 'revisi_dari',  'INT UNSIGNED NULL COMMENT ''quotes.id yang direvisi''');

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

CALL v24_tambah_kolom('quotes', 'template_id', 'INT UNSIGNED NULL COMMENT ''Template asal, kalau dibuat dari template''');

-- ---- v21: tamu akad & resepsi ----
CALL v24_tambah_kolom('client_wedding_info', 'tamu_akad',    'INT UNSIGNED NULL COMMENT ''Perkiraan tamu akad / pemberkatan''');
CALL v24_tambah_kolom('client_wedding_info', 'tamu_resepsi', 'INT UNSIGNED NULL COMMENT ''Perkiraan tamu resepsi''');

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

CALL v24_tambah_kolom('clients', 'paket_minat', 'INT UNSIGNED NULL COMMENT ''quote_templates.id yang dipilih klien''');

-- ---- v23: paket price list ----
CALL v24_tambah_kolom('quote_templates', 'slug',        'VARCHAR(140) NOT NULL DEFAULT ''''');
CALL v24_tambah_kolom('quote_templates', 'ringkas',     'VARCHAR(190) NOT NULL DEFAULT '''' COMMENT ''Satu kalimat di bawah nama paket''');
CALL v24_tambah_kolom('quote_templates', 'harga',       'DECIMAL(14,2) NULL COMMENT ''Harga paket; NULL = belum diisi''');
CALL v24_tambah_kolom('quote_templates', 'harga_mulai', 'TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''1 = ditulis mulai dari''');
CALL v24_tambah_kolom('quote_templates', 'tamu',        'SMALLINT UNSIGNED NULL COMMENT ''Perkiraan tamu paket''');
CALL v24_tambah_kolom('quote_templates', 'tampil_web',  'TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = tampil di halaman price list''');
CALL v24_tambah_kolom('quote_templates', 'unggulan',    'TINYINT(1) NOT NULL DEFAULT 0');
CALL v24_tambah_kolom('quote_template_items', 'kelompok', 'VARCHAR(80) NOT NULL DEFAULT '''' COMMENT ''Judul kelompok rincian isi''');
CALL v24_tambah_kolom('quotes', 'paket_nama',  'VARCHAR(120) NOT NULL DEFAULT ''''');
CALL v24_tambah_kolom('quotes', 'paket_harga', 'DECIMAL(14,2) NULL');
CALL v24_tambah_kolom('quote_items', 'kelompok', 'VARCHAR(80) NOT NULL DEFAULT ''''');

-- ---- v23: konsep dekor ----
CALL v24_tambah_kolom('client_wedding_info', 'konsep_dekor', 'TEXT NULL COMMENT ''Tema, warna, referensi dekor''');

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

-- ---- v24: termin pembayaran (cicilan, kwitansi, pengingat) ----
CALL v24_tambah_kolom('payments', 'terbayar',   'DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT ''Cache jumlah penerimaan sah'' AFTER amount');
CALL v24_tambah_kolom('payments', 'offset_hari', 'INT NULL COMMENT ''Salinan payment_templates.offset_hari; NULL = tanggal tetap'' AFTER due_date');
CALL v24_tambah_kolom('payments', 'ingat_kode', 'VARCHAR(24) NOT NULL DEFAULT ''''');
CALL v24_tambah_kolom('payments', 'ingat_at',   'DATETIME NULL');
CALL v24_tambah_kolom('clients', 'pengingat_bayar', 'TINYINT(1) NOT NULL DEFAULT 1');

-- ---- v24: dashboard pengantin ----
CALL v24_tambah_kolom('clients', 'portal_token',   'CHAR(32) NULL DEFAULT NULL');
CALL v24_tambah_kolom('clients', 'portal_seen_at', 'DATETIME NULL');
CALL v24_tambah_kolom('clients', 'portal_isi_at',  'DATETIME NULL');
CALL v24_tambah_kolom('client_wedding_info', 'dekor_klien', 'TEXT NULL');
CALL v24_tambah_kolom('client_wedding_info', 'pria_nama',   'VARCHAR(190) NOT NULL DEFAULT ''''');
CALL v24_tambah_kolom('client_wedding_info', 'wanita_nama', 'VARCHAR(190) NOT NULL DEFAULT ''''');

DROP PROCEDURE IF EXISTS `v24_indeks_portal`;
DELIMITER $$
CREATE PROCEDURE `v24_indeks_portal`()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'clients' AND INDEX_NAME = 'uq_clients_portal') THEN
        ALTER TABLE clients ADD UNIQUE KEY uq_clients_portal (portal_token);
    END IF;
END$$
DELIMITER ;
CALL v24_indeks_portal();
DROP PROCEDURE IF EXISTS `v24_indeks_portal`;

-- ---- v24: penerimaan (uang masuk per transfer) ----
CREATE TABLE IF NOT EXISTS `payment_receipts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `payment_id` INT UNSIGNED NOT NULL,
  `kwitansi_no` VARCHAR(30) NOT NULL DEFAULT '',
  `tanggal` DATE NOT NULL,
  `jumlah` DECIMAL(14,2) NOT NULL,
  `metode` VARCHAR(60) NOT NULL DEFAULT '',
  `pengirim` VARCHAR(120) NOT NULL DEFAULT '',
  `bukti` VARCHAR(190) NULL,
  `status` ENUM('sah','menunggu','ditolak','batal') NOT NULL DEFAULT 'sah',
  `sumber` ENUM('admin','portal','migrasi') NOT NULL DEFAULT 'admin',
  `pesan_klien` VARCHAR(400) NOT NULL DEFAULT '',
  `alasan_tolak` VARCHAR(255) NOT NULL DEFAULT '',
  `catatan` VARCHAR(255) NOT NULL DEFAULT '',
  `user_id` INT UNSIGNED NULL,
  `batal_at` DATETIME NULL,
  `batal_oleh` INT UNSIGNED NULL,
  `batal_alasan` VARCHAR(255) NOT NULL DEFAULT '',
  `kwitansi_wa_at` DATETIME NULL,
  `dicek_at` DATETIME NULL,
  `dicek_oleh` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pr_payment` (`payment_id`, `status`),
  KEY `idx_pr_client` (`client_id`, `tanggal`),
  KEY `idx_pr_kw` (`kwitansi_no`),
  KEY `idx_pr_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data lama: termin yang sudah lunas jadi satu penerimaan tanpa nomor kwitansi.
-- Aman diulang (NOT EXISTS), tanggal masa depan dijepit ke hari ini.
INSERT INTO payment_receipts (client_id, payment_id, kwitansi_no, tanggal, jumlah, metode, status, sumber, catatan)
SELECT p.client_id, p.id, '', LEAST(p.paid_at, CURDATE()), p.amount, p.method, 'sah', 'migrasi',
       CONCAT('migrasi v24; tanggal asli ', p.paid_at)
  FROM payments p
 WHERE p.paid_at IS NOT NULL AND p.amount > 0
   AND NOT EXISTS (SELECT 1 FROM payment_receipts r WHERE r.payment_id = p.id);
UPDATE payments SET terbayar = amount, paid_at = LEAST(paid_at, CURDATE())
 WHERE paid_at IS NOT NULL AND terbayar = 0;
UPDATE payments p JOIN payment_templates t ON t.kode = p.kode AND p.kode <> ''
   SET p.offset_hari = t.offset_hari
 WHERE p.offset_hari IS NULL AND p.paid_at IS NULL AND t.offset_hari IS NOT NULL;

DROP PROCEDURE IF EXISTS `v24_tambah_kolom`;

-- Template lama menyimpan harga per baris; model sekarang "paket + rincian
-- isi" — jumlahnya dipindah jadi harga paket (sekali saja: hanya template
-- yang harga paketnya masih kosong).
UPDATE quote_templates t
  JOIN (SELECT template_id, SUM(harga * qty) s FROM quote_template_items
         WHERE opsional = 0 GROUP BY template_id HAVING s > 0) x ON x.template_id = t.id
   SET t.harga = x.s, t.harga_mulai = 0
 WHERE t.harga IS NULL;
UPDATE quote_template_items i JOIN quote_templates t ON t.id = i.template_id
   SET i.harga = 0
 WHERE i.opsional = 0 AND i.harga <> 0 AND t.harga IS NOT NULL;

-- Penanda: kode tidak perlu mencoba lagi. paket_benih sengaja tidak disentuh
-- supaya paket contoh dibuat otomatis saat halaman berikutnya dibuka.
INSERT INTO `settings` (`k`, `v`, `is_secret`) VALUES ('skema_versi', '24', 0), ('skema_galat', '', 0)
ON DUPLICATE KEY UPDATE `v` = VALUES(`v`);

-- =====================================================================
-- VERIFIKASI — harus: kolom_v19 = 4, kolom_tamu = 2, kategori_wo = 1,
--              tahap_dp = 1, kolom_paket = 7, tabel_form = 1,
--              kolom_bayar = 4, tabel_penerimaan = 1, kolom_portal = 3
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
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'form_masuk')                 AS tabel_form,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
      AND COLUMN_NAME IN ('terbayar','offset_hari','ingat_kode','ingat_at'))       AS kolom_bayar,
  (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_receipts')           AS tabel_penerimaan,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients'
      AND COLUMN_NAME IN ('portal_token','portal_seen_at','portal_isi_at'))        AS kolom_portal;
