-- =====================================================================
-- Callalily Party CMS — migration-v8.sql
-- Menyesuaikan skema dengan flow operasional owner + modul chat WA.
--
-- Disusun dari dump asli (tuguasri_wo.sql), BUKAN dari asumsi:
--   settings  -> kolom (k, v, is_secret)
--   payments  -> tabel termin yang sudah ada
--   clients   -> name/partner_name/phone/instagram/source
--
-- Backup dulu:
--   mysqldump -u USER -p NAMADB > backup-pre-v8-$(date +%F).sql
-- =====================================================================

SET NAMES utf8mb4;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
START TRANSACTION;


-- =====================================================================
-- 1. PIPELINE — sisipkan tahap "pricelist", ganti meeting -> spesifikasi
-- =====================================================================
-- Flow owner: Client -> PL -> (cocok) -> Spesifikasi -> Penawaran ->
--             (cocok) -> Kontrak -> handover admin office
-- 'meeting' dihapus sebagai tahap karena di flow owner konsultasi terjadi
-- DI DALAM tahap spesifikasi, bukan tahap tersendiri.
-- 'negosiasi' dilebur ke 'penawaran' (owner hanya kenal cocok/tidak cocok).

ALTER TABLE `clients`
  MODIFY COLUMN `stage` VARCHAR(20) NOT NULL DEFAULT 'baru';

UPDATE `clients` SET `stage` = 'spesifikasi' WHERE `stage` = 'meeting';
UPDATE `clients` SET `stage` = 'penawaran'   WHERE `stage` = 'negosiasi';

ALTER TABLE `clients`
  MODIFY COLUMN `stage` ENUM(
    'baru',         -- client nanya, belum dikirim PL
    'pricelist',    -- PL terkirim, menunggu jawaban
    'spesifikasi',  -- cocok dengan PL, isi Base Information (konsultasi di sini)
    'penawaran',    -- penawaran terkirim, menunggu jawaban
    'deal',         -- kontrak ditandatangani -> handover ke admin office
    'persiapan',    -- grup WA jalan, vendor masuk
    'harih',
    'selesai',
    'batal'
  ) NOT NULL DEFAULT 'baru';


-- =====================================================================
-- 2. CLIENTS — kolom baru
-- =====================================================================

ALTER TABLE `clients`
  ADD COLUMN `tipe_klien` ENUM('','tematis','budgeting') NOT NULL DEFAULT ''
      COMMENT 'tematis=paket ikut request; budgeting=paket ikut plafon budget',
  ADD COLUMN `sumber_detail` VARCHAR(120) NOT NULL DEFAULT ''
      COMMENT 'Tahu dari mana, versi bebas (source enum tetap dipakai)',
  ADD COLUMN `pl_sent_at`         DATETIME NULL,
  ADD COLUMN `spesifikasi_at`     DATETIME NULL,
  ADD COLUMN `penawaran_sent_at`  DATETIME NULL,
  ADD COLUMN `contract_signed_at` DATETIME NULL,
  ADD COLUMN `pic_role` ENUM('admin_biasa','admin_office') NOT NULL DEFAULT 'admin_biasa',
  ADD COLUMN `handover_at` DATETIME NULL,
  ADD COLUMN `stage_batal` VARCHAR(20) NOT NULL DEFAULT ''
      COMMENT 'Gugur di tahap mana — untuk analyze penyebab';

ALTER TABLE `clients`
  ADD KEY `idx_clients_pic`  (`pic_role`, `stage`),
  ADD KEY `idx_clients_tipe` (`tipe_klien`);


-- =====================================================================
-- 3. MASTER JENIS VENDOR (foto 5 & 6) — hierarkis, bisa ditambah owner
-- =====================================================================
-- vendors.category sekarang varchar berisi 10 kategori hardcode di PHP.
-- Catatan owner: "Jenis Vendor = diisi sendiri" -> harus tabel, bukan const.

CREATE TABLE IF NOT EXISTS `vendor_categories` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` INT UNSIGNED NULL,
  `slug`      VARCHAR(60)  NOT NULL,
  `nama`      VARCHAR(120) NOT NULL,
  `urutan`    SMALLINT NOT NULL DEFAULT 0,
  `is_custom` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vc_slug` (`slug`),
  KEY `idx_vc_parent` (`parent_id`, `urutan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `vendor_categories` (`slug`,`nama`,`urutan`) VALUES
  ('make-up','Make Up',10),
  ('busana','Busana',20),
  ('bridal-robes','Bridal Robes',30),
  ('foto','Foto',40),
  ('video','Video',50),
  ('wcc','WCC (Wedding Content Creator)',60),
  ('photo-booth','Photo Booth',70),
  ('audio-guest-book','Audio Guest Book',80),
  ('dekorasi','Dekorasi',90),
  ('music','Music',100),
  ('mc','MC',110),
  ('sound-system','Sound System',120),
  ('lighting','Lighting',130),
  ('multimedia','Multimedia',140),
  ('party-effect','Party Effect',150),
  ('genset','Genset / Kelistrikan',160),
  ('ac-misty-fan','AC / Misty Fan',170),
  ('venue','Venue',180),
  ('tenda','Tenda',190),
  ('meja','Meja',200),
  ('kursi','Kursi',210),
  ('catering','Catering / Food & Beverages',220),
  ('wedding-cake','Kue Pernikahan',230),
  ('undangan','Undangan',240),
  ('souvenir','Souvenir',250),
  ('wrapping-seserahan','Wrapping Seserahan / Hantaran',260),
  ('transport','Transportasi',270),
  ('lainnya','Lainnya',999)
ON DUPLICATE KEY UPDATE `nama` = VALUES(`nama`);

-- Sub-kategori (Make Up & Busana punya 3 tingkat yang sama)
INSERT INTO `vendor_categories` (`parent_id`,`slug`,`nama`,`urutan`)
SELECT id,'makeup-mempelai','Makeup Mempelai',1 FROM vendor_categories WHERE slug='make-up'
UNION ALL SELECT id,'makeup-keluarga-inti','Makeup Keluarga Inti',2 FROM vendor_categories WHERE slug='make-up'
UNION ALL SELECT id,'makeup-panitia','Makeup Keluarga Besar / Panitia',3 FROM vendor_categories WHERE slug='make-up'
UNION ALL SELECT id,'busana-mempelai','Busana Mempelai',1 FROM vendor_categories WHERE slug='busana'
UNION ALL SELECT id,'busana-keluarga-inti','Busana Keluarga Inti',2 FROM vendor_categories WHERE slug='busana'
UNION ALL SELECT id,'busana-panitia','Busana Keluarga Besar / Panitia',3 FROM vendor_categories WHERE slug='busana'
UNION ALL SELECT id,'undangan-digital','Undangan Digital',1 FROM vendor_categories WHERE slug='undangan'
UNION ALL SELECT id,'undangan-cetak','Undangan Cetak',2 FROM vendor_categories WHERE slug='undangan'
ON DUPLICATE KEY UPDATE `nama` = VALUES(`nama`);

-- Petakan kategori lama (const VENDOR_KATEGORI) ke slug baru
UPDATE `vendors` SET `category`='dekorasi'  WHERE `category`='dekorasi';
UPDATE `vendors` SET `category`='catering'  WHERE `category`='katering';
UPDATE `vendors` SET `category`='foto'      WHERE `category`='dokumentasi';
UPDATE `vendors` SET `category`='make-up'   WHERE `category`='rias';
UPDATE `vendors` SET `category`='music'     WHERE `category`='hiburan';
UPDATE `vendors` SET `category`='undangan'  WHERE `category`='undangan';


-- =====================================================================
-- 4. BASE INFORMATION (foto 4) — 1:1 dengan clients
-- =====================================================================

CREATE TABLE IF NOT EXISTS `client_wedding_info` (
  `client_id` INT UNSIGNED NOT NULL,
  `akad_tanggal` DATE NULL,
  `akad_jam`     TIME NULL,
  `akad_lokasi`  VARCHAR(190) NOT NULL DEFAULT '',
  `resepsi_tanggal` DATE NULL,
  `resepsi_jam`     TIME NULL,
  `resepsi_lokasi`  VARCHAR(190) NOT NULL DEFAULT '',
  `prosesi_adat`         VARCHAR(40)  NOT NULL DEFAULT '' COMMENT 'jawa|chinese|batak|lainnya',
  `prosesi_adat_lainnya` VARCHAR(120) NOT NULL DEFAULT '',
  `prosesi_adat_detail`  TEXT NULL COMMENT 'Blank isi manual — urutan prosesi',
  `venue_tipe`   SET('indoor','outdoor') NULL COMMENT 'Boleh dua-duanya',
  `jenis_acara`  ENUM('','standing','sitting') NOT NULL DEFAULT '',
  `sitting_mode` ENUM('','per_seat','per_block') NOT NULL DEFAULT ''
      COMMENT 'per_seat=nama tiap kursi; per_block=piring terbang, duduk bebas',
  `catatan` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jenis vendor yang dibutuhkan klien (multi-select saat isi spesifikasi)
CREATE TABLE IF NOT EXISTS `client_vendor_needs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id`   INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  `budget_alokasi` DECIMAL(14,2) NULL COMMENT 'Dipakai saat tipe_klien=budgeting',
  `vendor_id`   INT UNSIGNED NULL,
  `note`        VARCHAR(255) NOT NULL DEFAULT '',
  `sort_order`  SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cvn` (`client_id`,`category_id`),
  KEY `idx_cvn_cat` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Top 5 Vendor pilihan klien — "blank isi sendiri"
CREATE TABLE IF NOT EXISTS `client_top_vendors` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `urutan`    TINYINT UNSIGNED NOT NULL,
  `nama`      VARCHAR(160) NOT NULL,
  `category_id` INT UNSIGNED NULL,
  `vendor_id` INT UNSIGNED NULL,
  `note`      VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ctv` (`client_id`,`urutan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 5. PENAWARAN — dibangun dari sistem, dikirim ke WA klien
-- =====================================================================
-- Menjawab "PL statis atau per segmen": PL itu turunan dari tipe_klien.
--   tematis   -> item disusun dari request, harga mengikuti kebutuhan
--   budgeting -> plafon dikunci, item diisi sampai plafon habis (stop vendor)

CREATE TABLE IF NOT EXISTS `quotes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id`  INT UNSIGNED NOT NULL,
  `nomor`      VARCHAR(40) NOT NULL,
  `revisi`     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `tipe`       ENUM('tematis','budgeting') NOT NULL DEFAULT 'tematis',
  `jenis`      ENUM('pricelist','penawaran') NOT NULL DEFAULT 'penawaran'
      COMMENT 'pricelist=kiriman awal; penawaran=setelah spesifikasi',
  `status`     ENUM('draf','terkirim','cocok','revisi','tidak_cocok') NOT NULL DEFAULT 'draf',
  `plafon`     DECIMAL(14,2) NULL COMMENT 'Diisi saat tipe=budgeting',
  `subtotal`   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `diskon`     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `valid_until` DATE NULL,
  `catatan`    TEXT NULL,
  `token`      CHAR(32) NOT NULL COMMENT 'Untuk tautan publik penawaran',
  `sent_at`    DATETIME NULL,
  `sent_wa_id` VARCHAR(120) NULL,
  `seen_at`    DATETIME NULL,
  `decided_at` DATETIME NULL,
  `alasan`     VARCHAR(400) NOT NULL DEFAULT '' COMMENT 'Analyze penyebab bila tidak cocok',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quote_token` (`token`),
  KEY `idx_quote_client` (`client_id`,`created_at`),
  KEY `idx_quote_status` (`status`,`valid_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quote_items` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quote_id`    INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NULL,
  `label`       VARCHAR(190) NOT NULL,
  `detail`      VARCHAR(400) NOT NULL DEFAULT '',
  `qty`         DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `satuan`      VARCHAR(30) NOT NULL DEFAULT 'paket',
  `harga`       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `jumlah`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `opsional`    TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order`  SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_qi_quote` (`quote_id`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 6. TERMIN 30/20/30/20 (foto 8)
-- =====================================================================
-- Template di DB supaya owner bisa ubah persen tanpa deploy ulang.
-- Nilainya di-snapshot ke `payments` saat generate, jadi kontrak lama
-- tidak ikut berubah kalau template diedit.

CREATE TABLE IF NOT EXISTS `payment_templates` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kode`   VARCHAR(30) NOT NULL,
  `label`  VARCHAR(80) NOT NULL,
  `persen` DECIMAL(5,2) NOT NULL,
  `offset_hari` INT NULL COMMENT 'Hari sebelum hari-H; NULL = saat tanda tangan',
  `urutan` TINYINT NOT NULL,
  `wajib`  TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pt_kode` (`kode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `payment_templates` (`kode`,`label`,`persen`,`offset_hari`,`urutan`,`wajib`) VALUES
  ('dealing',  'Dealing (tanda tangan kontrak)', 30.00, NULL, 1, 1),
  ('termin2',  'Termin ke-2',                    20.00,   60, 2, 0),
  ('termin3',  'Termin ke-3',                    30.00,   30, 3, 0),
  ('pelunasan','Pelunasan',                      20.00,    7, 4, 0)
ON DUPLICATE KEY UPDATE `persen`=VALUES(`persen`), `offset_hari`=VALUES(`offset_hari`);

ALTER TABLE `payments`
  ADD COLUMN `kode` VARCHAR(30) NOT NULL DEFAULT '' AFTER `client_id`,
  ADD COLUMN `persen` DECIMAL(5,2) NULL,
  ADD COLUMN `wajib` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `clients`
  ADD COLUMN `payment_mode` ENUM('termin','full') NOT NULL DEFAULT 'termin';


-- =====================================================================
-- 7. CHAT WHATSAPP — satu room per nomor, seperti WhatsApp Web
-- =====================================================================
-- Masalah struktur lama: vendor_messages dikunci ke (client_id, vendor_id),
-- jadi nomor yang belum jadi vendor/klien jatuh ke wa_inbox dan tidak punya
-- room. Akibatnya percakapan tidak pernah utuh — itu sebabnya room-nya
-- terasa tidak bisa dipakai.
--
-- Struktur baru: wa_chats (satu baris per nomor) + wa_messages (semua pesan,
-- dua arah). Tag client_id/vendor_id jadi OPSIONAL, cuma untuk menyaring
-- percakapan per pesta — bukan syarat supaya pesan bisa masuk.

CREATE TABLE IF NOT EXISTS `wa_chats` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `wa_number` VARCHAR(20) NOT NULL COMMENT 'Normalisasi 62xxx tanpa plus',
  `nama`      VARCHAR(120) NOT NULL DEFAULT '',
  `jenis`     ENUM('klien','vendor','lainnya') NOT NULL DEFAULT 'lainnya',
  `client_id` INT UNSIGNED NULL COMMENT 'Bila nomor ini milik klien',
  `vendor_id` INT UNSIGNED NULL COMMENT 'Bila nomor ini milik vendor',
  `room_client_id` INT UNSIGNED NULL COMMENT 'Pesta aktif untuk vendor multi-pesta',
  `last_at`   DATETIME NULL,
  `last_body` VARCHAR(220) NOT NULL DEFAULT '',
  `last_dir`  ENUM('masuk','keluar','catatan') NOT NULL DEFAULT 'masuk',
  `unread`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `pinned`    TINYINT(1) NOT NULL DEFAULT 0,
  `archived`  TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wachat_num` (`wa_number`),
  KEY `idx_wachat_list` (`archived`,`pinned`,`last_at`),
  KEY `idx_wachat_client` (`client_id`),
  KEY `idx_wachat_vendor` (`vendor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wa_messages` (
  `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chat_id`   INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NULL COMMENT 'Tag pesta — opsional',
  `vendor_id` INT UNSIGNED NULL,
  `direction` ENUM('masuk','keluar','catatan') NOT NULL DEFAULT 'keluar',
  `channel`   ENUM('wa','telepon','email','tatap','lainnya') NOT NULL DEFAULT 'wa',
  `body`      TEXT NOT NULL,
  `media_url`  VARCHAR(600) NULL,
  `media_name` VARCHAR(190) NULL,
  `media_type` VARCHAR(60)  NULL,
  `wa_id`     VARCHAR(120) NULL COMMENT 'inboxid Fonnte / message id Cloud API',
  `reply_to`  VARCHAR(120) NULL COMMENT 'wa_id pesan yang dibalas',
  `wa_status` ENUM('lokal','antre','terkirim','sampai','dibaca','gagal') NOT NULL DEFAULT 'lokal',
  `wa_error`  VARCHAR(400) NULL,
  `wa_from`   VARCHAR(20) NOT NULL DEFAULT '',
  `user_id`   INT UNSIGNED NULL,
  `sent_at`   DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wam_chat` (`chat_id`,`id`),
  KEY `idx_wam_client` (`client_id`,`created_at`),
  KEY `idx_wam_waid` (`wa_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Pindahkan data lama ----------
-- Room dari vendor_messages
INSERT IGNORE INTO `wa_chats` (`wa_number`,`nama`,`jenis`,`vendor_id`)
SELECT DISTINCT
       CASE WHEN vm.wa_from <> '' THEN vm.wa_from
            ELSE REPLACE(REPLACE(REPLACE(v.phone,' ',''),'-',''),'+','') END,
       v.name, 'vendor', v.id
FROM `vendor_messages` vm
JOIN `vendors` v ON v.id = vm.vendor_id
WHERE COALESCE(NULLIF(vm.wa_from,''), v.phone) <> '';

-- Room dari wa_inbox (nomor yang belum dikenali)
INSERT IGNORE INTO `wa_chats` (`wa_number`,`nama`,`jenis`)
SELECT DISTINCT wa_from, MAX(nama), 'lainnya'
FROM `wa_inbox` WHERE wa_from <> '' GROUP BY wa_from;

-- Room untuk setiap klien yang punya nomor
INSERT IGNORE INTO `wa_chats` (`wa_number`,`nama`,`jenis`,`client_id`)
SELECT REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),
       TRIM(CONCAT(name, IF(partner_name <> '', CONCAT(' & ', partner_name), ''))),
       'klien', id
FROM `clients` WHERE phone <> '';

-- Pesan lama dari vendor_messages
INSERT INTO `wa_messages`
  (`chat_id`,`client_id`,`vendor_id`,`direction`,`channel`,`body`,
   `wa_id`,`wa_status`,`wa_error`,`wa_from`,`user_id`,`sent_at`,`created_at`)
SELECT ch.id, vm.client_id, vm.vendor_id, vm.direction, vm.channel, vm.body,
       vm.wa_id, vm.wa_status, vm.wa_error, vm.wa_from, vm.user_id, vm.sent_at, vm.created_at
FROM `vendor_messages` vm
JOIN `vendors` v  ON v.id = vm.vendor_id
JOIN `wa_chats` ch ON ch.wa_number = CASE WHEN vm.wa_from <> '' THEN vm.wa_from
       ELSE REPLACE(REPLACE(REPLACE(v.phone,' ',''),'-',''),'+','') END;

-- Pesan lama dari wa_inbox
INSERT INTO `wa_messages`
  (`chat_id`,`direction`,`channel`,`body`,`wa_id`,`wa_status`,`wa_from`,`created_at`)
SELECT ch.id, 'masuk', 'wa', wi.body, wi.wa_id, 'sampai', wi.wa_from, wi.created_at
FROM `wa_inbox` wi
JOIN `wa_chats` ch ON ch.wa_number = wi.wa_from;

-- Segarkan ringkasan tiap room
UPDATE `wa_chats` ch
JOIN (SELECT chat_id, MAX(id) mid FROM `wa_messages` GROUP BY chat_id) x ON x.chat_id = ch.id
JOIN `wa_messages` m ON m.id = x.mid
SET ch.last_at = m.created_at,
    ch.last_body = LEFT(m.body, 220),
    ch.last_dir = m.direction;


-- =====================================================================
-- 8. PENGATURAN — routing WA (foto 7)
-- =====================================================================
INSERT INTO `settings` (`k`,`v`,`is_secret`) VALUES
  ('wa_admin_biasa',  '6281905503634', 0),
  ('wa_admin_office', '6281215797320', 0),
  ('wa_web_target',   'admin_office',  0),
  ('quote_prefix',    'CLP',           0),
  ('quote_valid_days','14',            0)
ON DUPLICATE KEY UPDATE `v` = VALUES(`v`);


COMMIT;

-- =====================================================================
-- VERIFIKASI
-- =====================================================================
-- SELECT stage, COUNT(*) FROM clients GROUP BY stage;
-- SELECT COUNT(*) FROM wa_chats;  SELECT COUNT(*) FROM wa_messages;
-- SELECT SUM(persen) FROM payment_templates WHERE is_active = 1;   -- 100.00
--
-- Tabel lama SENGAJA tidak dihapus. Setelah panel jalan normal 1-2 minggu:
--   RENAME TABLE vendor_messages TO _old_vendor_messages,
--                wa_inbox        TO _old_wa_inbox;
