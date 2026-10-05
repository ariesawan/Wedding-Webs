-- =====================================================================
-- Callalily Party CMS — migration-v20.sql
-- Jalankan SETELAH migration-v19.sql.
--
-- Isi: TEMPLATE PENAWARAN.
--
-- Sampai v19, penawaran hanya bisa lahir dari kebutuhan vendor yang
-- dicentang di halaman klien. Itu berguna untuk klien yang sudah konsultasi,
-- tapi tidak untuk kiriman pertama — saat klien baru mengisi formulir dan
-- belum ada yang dicentang sama sekali, penawarannya lahir kosong dan admin
-- mengetik ulang isi yang sama untuk kesekian kali.
--
-- Template menyimpan susunan yang sudah baku: paket standar, rincian yang
-- selalu ikut, urutan yang sudah terbukti enak dibaca klien. Dipakai sebagai
-- titik awal, lalu disesuaikan — bukan dikunci.
--
-- Aman diulang.
--   mysqldump -u USER -p NAMADB > backup-pre-v20-$(date +%F).sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. TEMPLATE
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quote_templates` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nama`      VARCHAR(120) NOT NULL,
  `deskripsi` VARCHAR(400) NOT NULL DEFAULT ''
      COMMENT 'Kapan template ini dipakai — dibaca admin saat memilih',
  `tipe`      ENUM('semua','tematis','budgeting') NOT NULL DEFAULT 'semua'
      COMMENT 'Batasi ke tipe klien tertentu; semua = selalu muncul',
  `catatan_bawaan` TEXT DEFAULT NULL
      COMMENT 'Isi awal kolom Catatan pada penawaran — syarat, cakupan, pengecualian',
  `urutan`    SMALLINT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tpl_aktif` (`is_active`, `urutan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 2. BARIS TEMPLATE
-- ---------------------------------------------------------------------
-- Strukturnya sengaja dibuat kembar dengan quote_items. Menyalin template ke
-- penawaran jadi satu INSERT ... SELECT, bukan pemetaan kolom per kolom yang
-- diam-diam menjatuhkan field setiap kali salah satu tabel berubah.
--
-- category_id boleh NULL: banyak baris penawaran bukan vendor sama sekali —
-- biaya koordinasi, fee WO, transport tim.
CREATE TABLE IF NOT EXISTS `quote_template_items` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_id` INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NULL,
  `label`   VARCHAR(190) NOT NULL DEFAULT '',
  `detail`  VARCHAR(400) NOT NULL DEFAULT '',
  `qty`     DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `satuan`  VARCHAR(30)  NOT NULL DEFAULT 'paket',
  `harga`   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `opsional` TINYINT(1)  NOT NULL DEFAULT 0,
  `sort_order` SMALLINT  NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tpl_item` (`template_id`, `sort_order`),
  CONSTRAINT `fk_tpl_item` FOREIGN KEY (`template_id`)
    REFERENCES `quote_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 3. MENANDAI ASAL PENAWARAN
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `v20_tambah_kolom`;
DELIMITER $$
CREATE PROCEDURE `v20_tambah_kolom`(IN p_tabel VARCHAR(64), IN p_kolom VARCHAR(64), IN p_def TEXT)
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

CALL v20_tambah_kolom('quotes', 'template_id',
    'INT UNSIGNED NULL COMMENT ''Template asal, kalau dibuat dari template''');

DROP PROCEDURE IF EXISTS `v20_tambah_kolom`;


-- ---------------------------------------------------------------------
-- 4. CONTOH SATU TEMPLATE
-- ---------------------------------------------------------------------
-- Angkanya kisaran wajar Yogyakarta dan HARUS disesuaikan — yang dicontohkan
-- di sini strukturnya, bukan harganya: bagaimana baris disusun, mana yang
-- dijadikan opsional, seberapa rinci kolom detail perlu diisi.
--
-- Hanya dibuat kalau belum ada template sama sekali, supaya migrasi yang
-- terlanjur dijalankan dua kali tidak menggandakan contohnya.
INSERT INTO `quote_templates` (`nama`, `deskripsi`, `tipe`, `catatan_bawaan`, `urutan`)
-- CONCAT(), bukan ||. Di MySQL mode bawaan, || adalah OR logis — hasilnya
-- angka 0, lalu ditolak sebagai nilai kolom teks. Perilaku ini beda dengan
-- PostgreSQL dan Oracle, dan pesan galatnya ('Truncated incorrect DOUBLE')
-- sama sekali tidak menyebut penyambungan teks.
SELECT 'Paket Standar — Resepsi 500 Tamu',
       CONCAT('Susunan yang paling sering dipakai untuk resepsi gedung di Yogyakarta. ',
              'Pakai ini sebagai titik awal kiriman pertama, lalu sesuaikan harganya ',
              'menurut vendor yang benar-benar dipesan.'),
       'semua',
       'Harga berlaku 14 hari sejak penawaran diterbitkan.
Sudah termasuk koordinasi tim di hari-H dan satu kali technical meeting.
Belum termasuk: akomodasi luar kota, perizinan venue, dan biaya lembur di atas jam yang disepakati.
Perubahan setelah penawaran disetujui dihitung terpisah.',
       10
 WHERE NOT EXISTS (SELECT 1 FROM `quote_templates`);

-- Barisnya. category_id dicocokkan lewat slug supaya tetap benar walau id
-- kategori di instalasi lain berbeda.
INSERT INTO `quote_template_items`
  (`template_id`, `category_id`, `label`, `detail`, `qty`, `satuan`, `harga`, `opsional`, `sort_order`)
SELECT t.id, vc.id, x.label, x.detail, x.qty, x.satuan, x.harga, x.opsional, x.sort_order
  FROM (SELECT id FROM `quote_templates` ORDER BY id LIMIT 1) t
  JOIN (
    SELECT 'make-up'   AS slug, 'Make Up Mempelai'        AS label, 'Akad dan resepsi, termasuk satu kali trial' AS detail, 1 AS qty, 'paket' AS satuan,  6000000 AS harga, 0 AS opsional, 10 AS sort_order UNION ALL
    SELECT 'busana',           'Busana Mempelai',         'Sewa, dua set — akad dan resepsi',                    1, 'paket',  9000000, 0,  20 UNION ALL
    SELECT 'dekorasi',         'Dekorasi Pelaminan',      'Pelaminan, gate, meja penerima tamu',                 1, 'paket', 18000000, 0,  30 UNION ALL
    SELECT 'foto',             'Dokumentasi Foto',        'Dua fotografer, akad sampai resepsi selesai',         1, 'paket',  8000000, 0,  40 UNION ALL
    SELECT 'video',            'Dokumentasi Video',       'Satu videografer, cinematic highlight 3-5 menit',     1, 'paket',  7000000, 0,  50 UNION ALL
    SELECT 'catering',         'Catering',                'Prasmanan, per porsi',                              500, 'porsi',    45000, 0,  60 UNION ALL
    SELECT 'mc',               'MC Resepsi',              'Dwibahasa, termasuk gladi',                           1, 'paket',  3500000, 0,  70 UNION ALL
    SELECT 'sound-system',     'Sound System',            'Sesuai kapasitas gedung',                             1, 'paket',  4500000, 0,  80 UNION ALL
    SELECT 'music',            'Musik Akustik',           'Trio, dua sesi',                                      1, 'paket',  5000000, 0,  90 UNION ALL
    SELECT '',                 'Koordinasi Hari-H',       'Tim WO, mulai persiapan sampai acara selesai',        1, 'paket',  7500000, 0, 100 UNION ALL
    SELECT 'photo-booth',      'Photo Booth',             'Cetak tanpa batas, 4 jam',                            1, 'paket',  3500000, 1, 110 UNION ALL
    SELECT 'wcc',              'Wedding Content Creator', 'Konten vertikal untuk media sosial, same-day',        1, 'paket',  2500000, 1, 120 UNION ALL
    SELECT 'party-effect',     'Party Effect',            'Sparkular dan low fog saat masuk pelaminan',          1, 'paket',  2500000, 1, 130
  ) x ON 1 = 1
  LEFT JOIN `vendor_categories` vc ON vc.slug = x.slug
 WHERE NOT EXISTS (SELECT 1 FROM `quote_template_items`);


-- =====================================================================
-- VERIFIKASI
-- =====================================================================
SELECT (SELECT COUNT(*) FROM quote_templates)      AS template,
       (SELECT COUNT(*) FROM quote_template_items) AS baris_contoh,
       (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotes'
           AND COLUMN_NAME = 'template_id')        AS kolom_quotes;
