-- ============================================================
-- Callalily Party CMS — skema database
-- MySQL 5.7+ / MariaDB 10.3+ (standar cPanel)
-- Import lewat phpMyAdmin, atau:
--   mysql -u USER -p NAMA_DB < db/schema.sql
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- ---------- Pengguna admin ----------
CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(100) NOT NULL,
  email          VARCHAR(190) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('owner','editor') NOT NULL DEFAULT 'editor',
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at  DATETIME NULL,
  last_login_ip  VARCHAR(45) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Throttle login ----------
CREATE TABLE IF NOT EXISTS login_attempts (
  ip            VARCHAR(45) NOT NULL PRIMARY KEY,
  tries         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_try      DATETIME NULL,
  locked_until  DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Pengaturan key-value ----------
CREATE TABLE IF NOT EXISTS settings (
  k          VARCHAR(64) NOT NULL PRIMARY KEY,
  v          TEXT NULL,
  is_secret  TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Galeri (foto & video) ----------
CREATE TABLE IF NOT EXISTS gallery (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug         VARCHAR(120) NOT NULL,           -- nama berkas tanpa ekstensi: foto/<slug>.jpg
  media_type   ENUM('photo','video') NOT NULL DEFAULT 'photo',
  video_file   VARCHAR(160) NULL,               -- video/<video_file>
  who          VARCHAR(120) NOT NULL,           -- "Winda & Zakki"
  caption      VARCHAR(190) NOT NULL DEFAULT '',
  alt_text     VARCHAR(190) NOT NULL DEFAULT '',-- untuk SEO gambar
  event_id     INT UNSIGNED NULL,               -- kaitkan ke event bila ada
  width        SMALLINT UNSIGNED NULL,
  height       SMALLINT UNSIGNED NULL,
  has_webp     TINYINT(1) NOT NULL DEFAULT 0,
  sort_order   INT NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_gallery_slug (slug),
  KEY idx_gallery_pub (is_published, sort_order),
  KEY idx_gallery_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Event (upcoming & sudah terselenggara) ----------
CREATE TABLE IF NOT EXISTS events (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(190) NOT NULL,
  slug          VARCHAR(190) NOT NULL,
  couple        VARCHAR(120) NOT NULL DEFAULT '',   -- "Ichsan & Dewa"
  event_date    DATETIME NOT NULL,
  end_date      DATETIME NULL,
  venue         VARCHAR(190) NOT NULL DEFAULT '',
  city          VARCHAR(120) NOT NULL DEFAULT 'Yogyakarta',
  category      VARCHAR(60)  NOT NULL DEFAULT 'Resepsi',
  guest_count   SMALLINT UNSIGNED NULL,
  description   TEXT NULL,
  cover         VARCHAR(120) NULL,                  -- uploads/event/<cover>.jpg
  cover_alt     VARCHAR(190) NOT NULL DEFAULT '',
  is_featured   TINYINT(1) NOT NULL DEFAULT 0,
  is_published  TINYINT(1) NOT NULL DEFAULT 1,
  meta_title       VARCHAR(190) NOT NULL DEFAULT '',
  meta_description VARCHAR(255) NOT NULL DEFAULT '',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_events_slug (slug),
  KEY idx_events_date (is_published, event_date),
  KEY idx_events_featured (is_featured)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Blog ----------
CREATE TABLE IF NOT EXISTS categories (
  id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name  VARCHAR(80) NOT NULL,
  slug  VARCHAR(80) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  UNIQUE KEY uq_cat_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posts (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title            VARCHAR(190) NOT NULL,
  slug             VARCHAR(190) NOT NULL,
  excerpt          VARCHAR(400) NOT NULL DEFAULT '',
  content          MEDIUMTEXT NOT NULL,
  cover            VARCHAR(120) NULL,                 -- uploads/blog/<cover>.jpg
  cover_alt        VARCHAR(190) NOT NULL DEFAULT '',
  category_id      INT UNSIGNED NULL,
  tags             VARCHAR(255) NOT NULL DEFAULT '',  -- dipisah koma
  meta_title       VARCHAR(190) NOT NULL DEFAULT '',
  meta_description VARCHAR(255) NOT NULL DEFAULT '',
  focus_keyword    VARCHAR(120) NOT NULL DEFAULT '',
  seo_score        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  noindex          TINYINT(1) NOT NULL DEFAULT 0,
  status           ENUM('draft','published') NOT NULL DEFAULT 'draft',
  published_at     DATETIME NULL,
  author_id        INT UNSIGNED NULL,
  views            INT UNSIGNED NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_posts_slug (slug),
  KEY idx_posts_live (status, published_at),
  KEY idx_posts_cat (category_id),
  FULLTEXT KEY ft_posts (title, excerpt, content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Jadwal meeting klien ----------
CREATE TABLE IF NOT EXISTS meetings (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title            VARCHAR(190) NOT NULL,
  client_name      VARCHAR(120) NOT NULL,
  client_email     VARCHAR(190) NOT NULL,
  client_phone     VARCHAR(40) NOT NULL DEFAULT '',
  extra_emails     VARCHAR(500) NOT NULL DEFAULT '',  -- undangan tambahan, dipisah koma
  start_at         DATETIME NOT NULL,
  end_at           DATETIME NOT NULL,
  timezone         VARCHAR(64) NOT NULL DEFAULT 'Asia/Jakarta',
  mode             ENUM('onsite','zoom','meet','phone') NOT NULL DEFAULT 'meet',
  location_text    VARCHAR(255) NOT NULL DEFAULT '',  -- untuk mode onsite
  notes            TEXT NULL,
  event_id         INT UNSIGNED NULL,

  -- Google Calendar
  gcal_event_id    VARCHAR(190) NULL,
  gcal_html_link   VARCHAR(500) NULL,
  meet_url         VARCHAR(500) NULL,

  -- Zoom
  zoom_meeting_id  VARCHAR(60) NULL,
  zoom_join_url    VARCHAR(500) NULL,
  zoom_start_url   TEXT NULL,                          -- URL host, jangan pernah dikirim ke klien
  zoom_passcode    VARCHAR(60) NULL,

  status           ENUM('scheduled','done','canceled') NOT NULL DEFAULT 'scheduled',
  client_id        INT UNSIGNED NULL,                   -- kaitan ke pipeline klien
  minutes            MEDIUMTEXT NULL,                   -- notulen pertemuan
  minutes_at         DATETIME NULL,
  zoom_recording_url VARCHAR(600) NULL,
  zoom_recording_at  DATETIME NULL,
  zoom_duration      SMALLINT UNSIGNED NULL,
  outcome          ENUM('','lanjut','pikir','batal') NOT NULL DEFAULT '',
  outcome_note     VARCHAR(500) NOT NULL DEFAULT '',
  sync_error       VARCHAR(500) NULL,
  reminder_sent_at DATETIME NULL,
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_meetings_time (status, start_at),
  KEY idx_meetings_email (client_email),
  KEY idx_meetings_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Jejak audit ----------
CREATE TABLE IF NOT EXISTS audit_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  action     VARCHAR(80) NOT NULL,
  target     VARCHAR(120) NOT NULL DEFAULT '',
  detail     VARCHAR(500) NOT NULL DEFAULT '',
  ip         VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Reset kata sandi ----------
CREATE TABLE IF NOT EXISTS password_resets (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,          -- SHA-256 dari token; token asli tidak pernah disimpan
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  ip          VARCHAR(45) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reset_token (token_hash),
  KEY idx_reset_user (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Pipeline klien ----------
CREATE TABLE IF NOT EXISTS clients (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(120) NOT NULL,          -- pihak yang dihubungi
  partner_name    VARCHAR(120) NOT NULL DEFAULT '',
  email           VARCHAR(190) NOT NULL DEFAULT '',
  phone           VARCHAR(40)  NOT NULL DEFAULT '',
  instagram       VARCHAR(80)  NOT NULL DEFAULT '',
  source          ENUM('instagram','whatsapp','web','referral','vendor','walkin','lainnya') NOT NULL DEFAULT 'lainnya',

  stage           ENUM('baru','meeting','penawaran','negosiasi','deal','persiapan','harih','selesai','batal')
                  NOT NULL DEFAULT 'baru',
  stage_changed_at DATETIME NULL,

  wedding_date    DATE NULL,
  wedding_time    TIME NULL,
  venue           VARCHAR(190) NOT NULL DEFAULT '',
  city            VARCHAR(120) NOT NULL DEFAULT 'Yogyakarta',
  guest_estimate  SMALLINT UNSIGNED NULL,
  crew_count      SMALLINT UNSIGNED NULL,
  services        VARCHAR(255) NOT NULL DEFAULT '',   -- id layanan, dipisah koma
  package         VARCHAR(120) NOT NULL DEFAULT '',
  budget_estimate DECIMAL(14,2) NULL,             -- perkiraan saat prospek
  deal_value      DECIMAL(14,2) NULL,             -- nilai kontrak setelah deal

  next_action     VARCHAR(190) NOT NULL DEFAULT '',
  next_action_at  DATE NULL,

  lost_reason     VARCHAR(255) NOT NULL DEFAULT '',
  lost_at         DATETIME NULL,

  event_id        INT UNSIGNED NULL,              -- dibuat otomatis saat masuk tahap deal
  notes           TEXT NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_clients_stage (stage, next_action_at),
  KEY idx_clients_wedding (wedding_date),
  KEY idx_clients_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Jejak aktivitas per klien ----------
CREATE TABLE IF NOT EXISTS client_activities (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  type       ENUM('catatan','tahap','meeting','bayar','tugas','sistem') NOT NULL DEFAULT 'catatan',
  title      VARCHAR(190) NOT NULL,
  detail     TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_act_client (client_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Termin pembayaran ----------
CREATE TABLE IF NOT EXISTS payments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id  INT UNSIGNED NOT NULL,
  label      VARCHAR(80) NOT NULL,
  amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
  due_date   DATE NULL,
  paid_at    DATE NULL,
  method     VARCHAR(60) NOT NULL DEFAULT '',
  note       VARCHAR(255) NOT NULL DEFAULT '',
  sort_order SMALLINT NOT NULL DEFAULT 0,
  KEY idx_pay_client (client_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Checklist persiapan (dihitung mundur dari hari-H) ----------
CREATE TABLE IF NOT EXISTS client_tasks (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id  INT UNSIGNED NOT NULL,
  title      VARCHAR(190) NOT NULL,
  detail     VARCHAR(255) NOT NULL DEFAULT '',
  offset_day SMALLINT NOT NULL DEFAULT 0,    -- -90 = H-90; 0 = hari-H
  due_date   DATE NULL,
  done_at    DATETIME NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  KEY idx_task_client (client_id, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_segments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id  INT UNSIGNED NOT NULL,
  seg_key    VARCHAR(40) NOT NULL DEFAULT '',
  label      VARCHAR(80) NOT NULL,
  day_offset TINYINT NOT NULL DEFAULT 0,
  start_time TIME NULL,
  end_time   TIME NULL,
  note       VARCHAR(190) NOT NULL DEFAULT '',
  sort_order SMALLINT NOT NULL DEFAULT 0,
  KEY idx_seg_client (client_id, day_offset, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sheet_links (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label      VARCHAR(120) NOT NULL,
  sheet_id   VARCHAR(160) NOT NULL,
  tab        VARCHAR(120) NOT NULL DEFAULT '',
  note       VARCHAR(255) NOT NULL DEFAULT '',
  sort_order SMALLINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sheetlinks (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daftar vendor induk — dipakai ulang di banyak pesta
CREATE TABLE IF NOT EXISTS vendors (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  category   VARCHAR(40)  NOT NULL DEFAULT 'lainnya',
  phone      VARCHAR(40)  NOT NULL DEFAULT '',
  email      VARCHAR(190) NOT NULL DEFAULT '',
  instagram  VARCHAR(80)  NOT NULL DEFAULT '',
  city       VARCHAR(80)  NOT NULL DEFAULT '',
  pic_name   VARCHAR(80)  NOT NULL DEFAULT '',
  price_note VARCHAR(190) NOT NULL DEFAULT '',
  rating     TINYINT UNSIGNED NULL,
  note       TEXT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vendor_kat (category, is_active),
  KEY idx_vendor_nama (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vendor yang dipakai pesta tertentu, beserta statusnya
CREATE TABLE IF NOT EXISTS client_vendors (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id  INT UNSIGNED NOT NULL,
  vendor_id  INT UNSIGNED NOT NULL,
  status     ENUM('dipertimbangkan','dihubungi','nego','deal','batal') NOT NULL DEFAULT 'dipertimbangkan',
  price      DECIMAL(14,2) NULL,
  note       VARCHAR(255) NOT NULL DEFAULT '',
  sort_order SMALLINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cv (client_id, vendor_id),
  KEY idx_cv_client (client_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Riwayat percakapan: SELALU terikat pasangan (klien, vendor).
-- Inilah yang membuat obrolan soal pesta A tidak tercampur dengan pesta B
-- meski vendornya sama.
CREATE TABLE IF NOT EXISTS vendor_messages (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id  INT UNSIGNED NOT NULL,
  vendor_id  INT UNSIGNED NOT NULL,
  direction  ENUM('keluar','masuk','catatan') NOT NULL DEFAULT 'keluar',
  wa_status  ENUM('lokal','antre','terkirim','sampai','dibaca','gagal') NOT NULL DEFAULT 'lokal',
  wa_id      VARCHAR(120) NULL,
  wa_error   VARCHAR(400) NULL,
  wa_from    VARCHAR(30) NOT NULL DEFAULT '',
  sent_at    DATETIME NULL,
  channel    ENUM('wa','telepon','email','tatap','lainnya') NOT NULL DEFAULT 'wa',
  body       TEXT NOT NULL,
  user_id    INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vm (client_id, vendor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS wa_inbox (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wa_from    VARCHAR(30) NOT NULL,
  nama       VARCHAR(120) NOT NULL DEFAULT '',
  body       TEXT NOT NULL,
  wa_id      VARCHAR(120) NULL,
  handled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_inbox (handled_at, created_at),
  KEY idx_inbox_from (wa_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catatan mentah dari penyedia, untuk menelusuri kalau ada yang tidak sampai
CREATE TABLE IF NOT EXISTS wa_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  arah       ENUM('masuk','keluar') NOT NULL,
  payload    MEDIUMTEXT NULL,
  http_code  SMALLINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_walog (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Data awal
-- ============================================================
INSERT IGNORE INTO categories (name, slug, description) VALUES
  ('Tips Persiapan', 'tips-persiapan', 'Panduan praktis menyiapkan hari pernikahan.'),
  ('Adat & Tradisi',  'adat-tradisi',   'Prosesi adat Jawa dan maknanya.'),
  ('Venue & Vendor',  'venue-vendor',   'Rekomendasi lokasi dan vendor di Yogyakarta.'),
  ('Cerita Klien',    'cerita-klien',   'Kisah di balik acara yang kami pegang.');

INSERT IGNORE INTO settings (k, v, is_secret) VALUES
  ('site_name',        'Callalily Party', 0),
  ('site_description', 'Wedding organizer Yogyakarta. Kami memegang hari pernikahan Anda dari subuh sampai lewat tengah malam.', 0),
  ('contact_email',    'callalily.party@yahoo.com', 0),
  ('wa_number',        '6281234567890', 0),
  ('ig_url',           'https://instagram.com/callalilyparty', 0),
  ('address_street',   'Jl. Magelang KM 9, Denggung', 0),
  ('address_city',     'Sleman', 0),
  ('address_region',   'Daerah Istimewa Yogyakarta', 0),
  ('address_zip',      '55511', 0),
  ('area_served',      'Yogyakarta, Sleman, Bantul, Kulon Progo, Magelang, Solo, Semarang', 0),
  ('meeting_duration', '60', 0),
  ('mail_from_name',   'Callalily Party', 0),
  ('mail_method',      'mail', 0),
  ('wa_template_vendor','', 0),
  ('crew_per',      '100',  0),
  ('guest_min',     '50',   0),
  ('guest_max',     '1500', 0),
  ('guest_step',    '25',   0),
  ('guest_default', '300',  0),
  ('wa_provider',      'none', 0),
  ('wa_phone_id',      '',     0),
  ('wa_gateway_url',   '',     0),
  ('wa_gateway_ftarget','target', 0),
  ('wa_gateway_fbody', 'message', 0),
  ('wa_gateway_auth',  'header', 0);

-- Impor 12 item galeri yang sudah ada di situs supaya tidak hilang saat migrasi.
INSERT IGNORE INTO gallery (slug, media_type, video_file, who, caption, alt_text, sort_order) VALUES
  ('ichsan-dewa-01',  'photo', NULL,                  'Ichsan & Dewa',   'Resepsi', 'Resepsi pernikahan Ichsan dan Dewa di Yogyakarta', 10),
  ('ichsan-dewa-02',  'photo', NULL,                  'Ichsan & Dewa',   'Resepsi', 'Momen resepsi Ichsan dan Dewa', 20),
  ('ichsan-dewa-03',  'photo', NULL,                  'Ichsan & Dewa',   'Dekor @valeriedecoration', 'Dekorasi pelaminan oleh Valerie Decoration', 30),
  ('adin-erik',       'video', 'adin-erik.mp4',       'Adin & Erik',     'Lapangan Lumbini, Borobudur', 'Video pernikahan Adin dan Erik di Lapangan Lumbini Borobudur', 40),
  ('ichsan-dewa-04',  'photo', NULL,                  'Ichsan & Dewa',   'Dekor @valeriedecoration', 'Detail dekorasi resepsi Ichsan dan Dewa', 50),
  ('ichsan-dewa-05',  'photo', NULL,                  'Ichsan & Dewa',   'Bersama @bersama.asmara', 'Sesi foto bersama Bersama Asmara', 60),
  ('winda-zakki-01',  'photo', NULL,                  'Winda & Zakki',   'Resepsi · dekor @royal_kinan', 'Resepsi Winda dan Zakki dengan dekor Royal Kinan', 70),
  ('winda-zakki-02',  'photo', NULL,                  'Winda & Zakki',   'Resepsi · dekor @royal_kinan', 'Pelaminan Winda dan Zakki', 80),
  ('dream-come-true', 'video', 'dream-come-true.mp4', 'Dream come true', 'Dari perencanaan sampai hari-H', 'Video dokumenter proses wedding organizer Callalily Party', 90),
  ('hadni-fadel-01',  'photo', NULL,                  'Hadni & Fadel',   'Love story · foto @agita', 'Sesi love story Hadni dan Fadel', 100),
  ('hadni-fadel-02',  'photo', NULL,                  'Hadni & Fadel',   'Love story · foto @agita', 'Prewedding Hadni dan Fadel', 110),
  ('hadni-fadel-03',  'photo', NULL,                  'Hadni & Fadel',   'Love story · foto @agita', 'Potret Hadni dan Fadel', 120);

-- ---------- Pengaturan tambahan v2 ----------
INSERT IGNORE INTO settings (k, v, is_secret) VALUES
  ('sheet_enabled',  '0', 0),
  ('sheet_id',       '', 0),
  ('sheet_autosync', '0', 0),
  ('currency_prefix','Rp', 0),
  ('dp_percent',     '30', 0);
