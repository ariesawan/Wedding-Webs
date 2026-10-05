-- =====================================================================
-- Callalily Party CMS — migration-v9.sql
-- Jalankan SETELAH migration-v8.sql.
--
-- Isi:
--   1. Room chat untuk semua vendor & klien (yang v8 lewatkan)
--   2. Grup WhatsApp sebagai room
--   3. Tabel analisa — sebab gugur DAN sebab berhasil
--   4. Tipe klien & base information dipakai dari formulir klien baru
--
-- Backup dulu:
--   mysqldump -u USER -p NAMADB > backup-pre-v9-$(date +%F).sql
-- =====================================================================

SET NAMES utf8mb4;
START TRANSACTION;


-- =====================================================================
-- 1. ROOM UNTUK SEMUA KONTAK
-- =====================================================================
-- v8 hanya membuat room dari pesan yang SUDAH ada. Vendor yang belum
-- pernah dichat karena itu tidak muncul sama sekali di daftar — persis
-- yang terjadi pada vendor Qinan. Di WhatsApp asli kontak selalu terlihat
-- walau belum pernah ada percakapan, jadi di sini pun harus begitu.
--
-- last_at sengaja NULL untuk room kosong supaya bisa diurutkan di bawah
-- percakapan yang benar-benar hidup.

INSERT IGNORE INTO `wa_chats` (`wa_number`,`nama`,`jenis`,`vendor_id`,`last_at`)
SELECT REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'.',''),
       name, 'vendor', id, NULL
FROM `vendors`
WHERE phone <> '' AND is_active = 1;

INSERT IGNORE INTO `wa_chats` (`wa_number`,`nama`,`jenis`,`client_id`,`last_at`)
SELECT REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'.',''),
       TRIM(CONCAT(name, IF(partner_name <> '', CONCAT(' & ', partner_name), ''))),
       'klien', id, NULL
FROM `clients`
WHERE phone <> '';

-- Nomor yang tersimpan dengan awalan 0 perlu dinormalkan ke 62,
-- kalau tidak room-nya tidak akan pernah cocok dengan pesan masuk.
UPDATE `wa_chats`
   SET `wa_number` = CONCAT('62', SUBSTRING(`wa_number`, 2))
 WHERE `wa_number` LIKE '0%';

-- Kalau normalisasi menghasilkan nomor kembar, sisakan yang punya pesan.
DELETE ch FROM `wa_chats` ch
JOIN `wa_chats` lain
  ON lain.wa_number = ch.wa_number AND lain.id < ch.id
WHERE ch.last_at IS NULL;

-- Room yang belum ada isinya tapi vendor/klien-nya sudah diketahui,
-- dilengkapi namanya supaya daftar tidak menampilkan nomor telanjang.
UPDATE `wa_chats` ch
  JOIN `vendors` v ON v.id = ch.vendor_id
   SET ch.nama = v.name
 WHERE ch.nama = '';

UPDATE `wa_chats` ch
  JOIN `clients` c ON c.id = ch.client_id
   SET ch.nama = TRIM(CONCAT(c.name, IF(c.partner_name <> '', CONCAT(' & ', c.partner_name), '')))
 WHERE ch.nama = '';


-- =====================================================================
-- 2. GRUP WHATSAPP
-- =====================================================================
-- Fonnte TIDAK punya API untuk membuat grup — hanya fetch-group (menyegarkan
-- daftar) dan get-whatsapp-group (membaca daftar). Jadi grupnya tetap dibuat
-- manual di HP oleh admin office, persis seperti alur di catatan owner.
-- Yang panel lakukan: menarik daftarnya, menempelkan ke pesta, lalu grup itu
-- jadi room biasa yang bisa dikirimi dari sini.
--
-- ID grup berbentuk 1203xxxxxxxxxxxxx@g.us — jauh lebih panjang dari nomor
-- biasa, karena itu kolom wa_number dilebarkan.

ALTER TABLE `wa_chats`
  MODIFY COLUMN `wa_number` VARCHAR(60) NOT NULL,
  MODIFY COLUMN `jenis` ENUM('klien','vendor','grup','lainnya') NOT NULL DEFAULT 'lainnya',
  ADD COLUMN `is_group` TINYINT(1) NOT NULL DEFAULT 0 AFTER `jenis`,
  ADD COLUMN `group_subject` VARCHAR(190) NOT NULL DEFAULT '' AFTER `is_group`,
  ADD COLUMN `synced_at` DATETIME NULL;

ALTER TABLE `wa_messages`
  MODIFY COLUMN `wa_from` VARCHAR(60) NOT NULL DEFAULT '',
  ADD COLUMN `sender_name` VARCHAR(120) NOT NULL DEFAULT ''
      COMMENT 'Pengirim di dalam grup (field member dari webhook Fonnte)';

-- Vendor mana saja yang tercatat ada di sebuah grup. Diisi manual atau
-- ditebak dari nomor pengirim yang muncul di grup.
CREATE TABLE IF NOT EXISTS `wa_group_members` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chat_id`   INT UNSIGNED NOT NULL,
  `wa_number` VARCHAR(30) NOT NULL,
  `nama`      VARCHAR(120) NOT NULL DEFAULT '',
  `vendor_id` INT UNSIGNED NULL,
  `terakhir`  DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wgm` (`chat_id`,`wa_number`),
  KEY `idx_wgm_vendor` (`vendor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 3. ANALISA — sebab gugur DAN sebab berhasil
-- =====================================================================
-- Catatan owner menyebut "analyze penyebab" dua kali di jalur gagal
-- (setelah PL, setelah penawaran) dan sekali di ujung ("wedding + analyze").
-- Ketiganya disimpan di satu tabel supaya bisa dibandingkan langsung:
-- alasan menang dan alasan kalah tidak ada gunanya kalau dipisah laporan.

CREATE TABLE IF NOT EXISTS `client_analisa` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `momen`     ENUM('pricelist','penawaran','deal','pascaacara') NOT NULL,
  `hasil`     ENUM('gagal','berhasil') NOT NULL,
  `sebab`     VARCHAR(40) NOT NULL DEFAULT '',
  `sebab_lain` VARCHAR(160) NOT NULL DEFAULT '',
  `pesaing`   VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'Kalau kalah dari WO lain',
  `selisih`   DECIMAL(14,2) NULL COMMENT 'Beda harga dengan yang dipilih klien',
  `nilai`     DECIMAL(14,2) NULL COMMENT 'Nilai deal saat berhasil',
  `skor`      TINYINT UNSIGNED NULL COMMENT 'Kepuasan 1-5, diisi pasca-acara',
  `catatan`   TEXT NULL,
  `hari_proses` SMALLINT NULL COMMENT 'Lama dari masuk sampai keputusan',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_an_client` (`client_id`,`created_at`),
  KEY `idx_an_lapor` (`hasil`,`momen`,`created_at`),
  KEY `idx_an_sebab` (`sebab`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daftar sebab bisa diubah owner, jadi disimpan di tabel — bukan enum.
CREATE TABLE IF NOT EXISTS `analisa_sebab` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode`   VARCHAR(40) NOT NULL,
  `label`  VARCHAR(120) NOT NULL,
  `hasil`  ENUM('gagal','berhasil') NOT NULL,
  `urutan` SMALLINT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_as_kode` (`kode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `analisa_sebab` (`kode`,`label`,`hasil`,`urutan`) VALUES
  -- kenapa gugur
  ('harga_tinggi',    'Harga di atas ekspektasi klien',        'gagal', 10),
  ('budget_kurang',   'Budget klien memang tidak cukup',       'gagal', 20),
  ('pilih_pesaing',   'Memilih WO lain',                       'gagal', 30),
  ('tanggal_penuh',   'Tanggal sudah terisi acara lain',       'gagal', 40),
  ('konsep_beda',     'Konsep tidak cocok',                    'gagal', 50),
  ('tidak_respons',   'Klien berhenti membalas',               'gagal', 60),
  ('keluarga',        'Keluarga tidak setuju',                 'gagal', 70),
  ('acara_batal',     'Acara ditunda atau batal',              'gagal', 80),
  ('urus_sendiri',    'Memutuskan mengurus sendiri',           'gagal', 90),
  ('lain_gagal',      'Lainnya',                               'gagal', 999),
  -- kenapa menang
  ('harga_pas',       'Harga sesuai anggaran',                 'berhasil', 10),
  ('portofolio',      'Portofolio meyakinkan',                 'berhasil', 20),
  ('rekomendasi',     'Rekomendasi teman atau vendor',         'berhasil', 30),
  ('respons_cepat',   'Respons cepat saat ditanya',            'berhasil', 40),
  ('paket_fleksibel', 'Paket bisa disesuaikan',                'berhasil', 50),
  ('vendor_lengkap',  'Jaringan vendor lengkap',               'berhasil', 60),
  ('tanggal_tersedia','Tanggal masih tersedia',                'berhasil', 70),
  ('lain_berhasil',   'Lainnya',                               'berhasil', 999)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);

-- Klien yang sudah terlanjur batal sebelum modul ini ada, dibawa masuk
-- supaya laporannya tidak bolong sejak hari pertama.
INSERT INTO `client_analisa` (`client_id`,`momen`,`hasil`,`sebab`,`catatan`,`created_at`)
SELECT id,
       CASE WHEN stage_batal <> '' THEN stage_batal ELSE 'penawaran' END,
       'gagal', 'lain_gagal', lost_reason, COALESCE(lost_at, updated_at)
FROM `clients`
WHERE stage = 'batal'
  AND id NOT IN (SELECT client_id FROM `client_analisa`);


-- =====================================================================
-- 4. PENGATURAN TAMBAHAN
-- =====================================================================
INSERT INTO `settings` (`k`,`v`,`is_secret`) VALUES
  ('wa_group_synced_at', '', 0)
ON DUPLICATE KEY UPDATE `v` = `v`;


COMMIT;

-- =====================================================================
-- VERIFIKASI
-- =====================================================================
-- SELECT jenis, COUNT(*) FROM wa_chats GROUP BY jenis;
-- SELECT hasil, COUNT(*) FROM analisa_sebab GROUP BY hasil;   -- 10 / 8
-- SELECT wa_number FROM wa_chats WHERE wa_number LIKE '0%';   -- harus kosong
