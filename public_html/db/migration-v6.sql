-- ============================================================
-- MIGRASI v5 -> v6  ·  Penyusun brief bisa diedit dari panel
--   mysql -u USER -p NAMA_DB < db/migration-v6.sql
--
-- Sebelumnya daftar acara, layanan, dan paket ditulis langsung di
-- JavaScript index.php — mengubahnya berarti menyunting kode.
-- Setelah migrasi ini, situs publik DAN panel membaca sumber yang sama.
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS site_moments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mkey       VARCHAR(40) NOT NULL,
  label      VARCHAR(80) NOT NULL,
  day_offset TINYINT NOT NULL DEFAULT 0,        -- -1 = H-1, 0 = hari-H
  start_h    DECIMAL(4,2) NOT NULL DEFAULT 8,   -- jam desimal: 9.5 = 09.30
  end_h      DECIMAL(4,2) NOT NULL DEFAULT 10,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_mkey (mkey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_services (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  skey       VARCHAR(40) NOT NULL,
  label      VARCHAR(80) NOT NULL,
  note       VARCHAR(120) NOT NULL DEFAULT '',
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_skey (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_presets (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pkey       VARCHAR(40) NOT NULL,
  label      VARCHAR(80) NOT NULL,
  moments    VARCHAR(255) NOT NULL DEFAULT '',  -- mkey dipisah koma
  services   VARCHAR(255) NOT NULL DEFAULT '',  -- skey dipisah koma
  guests     SMALLINT UNSIGNED NOT NULL DEFAULT 300,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_pkey (pkey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Isi awal = persis nilai yang selama ini ada di index.php,
-- jadi tampilan situs tidak berubah sedikit pun setelah migrasi.
INSERT IGNORE INTO site_moments (mkey,label,day_offset,start_h,end_h,sort_order) VALUES
  ('siraman', 'Siraman',            -1, 15.00, 17.00, 10),
  ('midoda',  'Midodareni',         -1, 19.00, 21.00, 20),
  ('akad',    'Akad / Pemberkatan',  0,  8.00,  9.50, 30),
  ('ramah',   'Ramah tamah',         0,  9.50, 12.00, 40),
  ('panggih', 'Panggih adat',        0, 10.00, 11.00, 50),
  ('ressiang','Resepsi siang',       0, 12.00, 15.00, 60),
  ('resmalam','Resepsi malam',       0, 18.50, 22.00, 70),
  ('after',   'After-party',         0, 22.00, 24.00, 80);

INSERT IGNORE INTO site_services (skey,label,note,sort_order) VALUES
  ('plan',  'Perencanaan penuh',      '9–12 bulan',        10),
  ('dok',   'Dokumentasi foto+video', '2 kamera + drone',  20),
  ('mc',    'MC & hiburan',           'MC + akustik',      30),
  ('kater', 'Manajemen katering',     'koordinasi vendor', 40);

INSERT IGNORE INTO site_presets (pkey,label,moments,services,guests,sort_order) VALUES
  ('prasaja',  'Prasaja',   'akad,ramah',                                   'kater',              150, 10),
  ('semanak',  'Semanak',   'akad,resmalam',                                'plan,dok,mc,kater',  400, 20),
  ('sidomukti','Sidomukti', 'siraman,midoda,akad,panggih,resmalam,after',   'plan,dok,mc,kater',  800, 30);

INSERT IGNORE INTO settings (k, v, is_secret) VALUES
  ('crew_per',      '100',  0),
  ('guest_min',     '50',   0),
  ('guest_max',     '1500', 0),
  ('guest_step',    '25',   0),
  ('guest_default', '300',  0);
