-- =====================================================================
-- Callalily Party CMS — migration-v14.sql
-- Jalankan SETELAH migration-v13.sql.
--
-- Isi:
--   1. Kategori vendor punya isi yang bisa dijadikan halaman publik
--   2. Formulir klien publik (subdomain) punya jejak asalnya
--
-- Sebelumnya vendor_categories hanya menyimpan nama. Cukup untuk daftar
-- centang di panel, tidak cukup untuk halaman yang dibaca calon klien —
-- dan halaman kategori adalah salah satu pintu masuk pencarian terbesar
-- untuk jasa pernikahan ("dekorasi pernikahan jogja", "catering nikah").
--
-- Backup dulu:
--   mysqldump -u USER -p NAMADB > backup-pre-v14-$(date +%F).sql
-- =====================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- =====================================================================
-- 1. KATEGORI VENDOR SEBAGAI HALAMAN
-- =====================================================================
ALTER TABLE `vendor_categories`
  ADD COLUMN `ikon`      VARCHAR(30) NOT NULL DEFAULT ''
      COMMENT 'Kunci ikon SVG di partials/ikon-vendor.php',
  ADD COLUMN `ringkas`   VARCHAR(190) NOT NULL DEFAULT ''
      COMMENT 'Satu kalimat di bawah nama pada grid',
  ADD COLUMN `isi`       TEXT NULL
      COMMENT 'Penjelasan panjang di halaman kategori (boleh HTML sederhana)',
  ADD COLUMN `dicek`     TEXT NULL
      COMMENT 'Yang perlu ditanyakan klien ke vendor jenis ini, satu per baris',
  ADD COLUMN `kisaran`   VARCHAR(90) NOT NULL DEFAULT ''
      COMMENT 'Kisaran harga wajar di Yogyakarta — dikosongkan bila terlalu bervariasi',
  ADD COLUMN `is_public` TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = tampil di situs publik',
  ADD COLUMN `seo_title` VARCHAR(160) NOT NULL DEFAULT '',
  ADD COLUMN `seo_desc`  VARCHAR(220) NOT NULL DEFAULT '';

ALTER TABLE `vendor_categories`
  ADD KEY `idx_vc_public` (`is_public`, `urutan`);

-- Isi bawaan. Sengaja hanya untuk kategori yang benar-benar sering dicari
-- orang — memublikasikan 28 halaman sekaligus, sebagian berisi dua kalimat,
-- justru menurunkan kualitas situs di mata mesin pencari.
UPDATE `vendor_categories` SET `ikon`='venue', `is_public`=1,
  `ringkas`='Gedung, pendopo, atau halaman rumah — tiap tempat punya batasannya sendiri.',
  `kisaran`='Rp 8 – 60 juta',
  `seo_title`='Venue Pernikahan Yogyakarta — Cara Memilih & Kisaran Biaya',
  `seo_desc`='Panduan memilih venue pernikahan di Yogyakarta: indoor vs outdoor, kapasitas tamu, jam pakai, dan biaya tersembunyi yang sering terlewat.'
  WHERE `slug`='venue';

UPDATE `vendor_categories` SET `ikon`='dekorasi', `is_public`=1,
  `ringkas`='Yang paling banyak difoto tamu, dan paling menentukan kesan pertama.',
  `kisaran`='Rp 10 – 80 juta',
  `seo_title`='Dekorasi Pernikahan Yogyakarta — Gaya, Biaya, dan Yang Perlu Ditanya',
  `seo_desc`='Memilih dekorasi pernikahan di Yogyakarta: beda paket dan custom, apa saja yang termasuk, dan pertanyaan yang menentukan hasil akhirnya.'
  WHERE `slug`='dekorasi';

UPDATE `vendor_categories` SET `ikon`='catering', `is_public`=1,
  `ringkas`='Satu-satunya bagian yang dicicipi semua tamu tanpa terkecuali.',
  `kisaran`='Rp 45 – 150 rb / porsi',
  `seo_title`='Catering Pernikahan Yogyakarta — Hitungan Porsi & Kisaran Harga',
  `seo_desc`='Menghitung catering pernikahan: porsi per tamu, beda prasmanan dan piring terbang, dan kesalahan hitung yang paling sering terjadi.'
  WHERE `slug`='catering';

UPDATE `vendor_categories` SET `ikon`='foto', `is_public`=1,
  `ringkas`='Setelah hari itu lewat, ini yang tersisa.',
  `kisaran`='Rp 5 – 35 juta',
  `seo_title`='Fotografer Pernikahan Yogyakarta — Memilih Gaya & Paket',
  `seo_desc`='Cara memilih fotografer pernikahan di Yogyakarta: beda gaya dokumenter dan formal, jumlah fotografer, dan berapa lama hasilnya jadi.'
  WHERE `slug`='foto';

UPDATE `vendor_categories` SET `ikon`='video', `is_public`=1,
  `ringkas`='Yang menyimpan suara — dan suara yang paling cepat dilupakan.',
  `kisaran`='Rp 6 – 40 juta',
  `seo_title`='Videografer Pernikahan Yogyakarta — Cinematic, Dokumenter, Same-Day Edit',
  `seo_desc`='Memilih videografer pernikahan: beda cinematic dan dokumenter, same-day edit, dan durasi hasil akhir yang realistis.'
  WHERE `slug`='video';

UPDATE `vendor_categories` SET `ikon`='makeup', `is_public`=1,
  `ringkas`='Delapan jam di depan kamera, di bawah lampu, dalam cuaca Yogyakarta.',
  `kisaran`='Rp 3 – 25 juta',
  `seo_title`='Make Up Artist Pernikahan Yogyakarta — Trial, Ketahanan, dan Biaya',
  `seo_desc`='Memilih MUA pernikahan di Yogyakarta: pentingnya trial, riasan yang tahan cuaca lembap, dan siapa saja yang perlu dirias.'
  WHERE `slug`='make-up';

UPDATE `vendor_categories` SET `ikon`='busana', `is_public`=1,
  `ringkas`='Sewa atau jahit — keputusannya lebih rumit dari sekadar harga.',
  `kisaran`='Rp 4 – 40 juta',
  `seo_title`='Busana Pengantin Yogyakarta — Sewa, Jahit, dan Adat Jawa',
  `seo_desc`='Busana pengantin di Yogyakarta: beda sewa dan jahit, kebaya adat Jawa, dan berapa lama waktu yang dibutuhkan.'
  WHERE `slug`='busana';

UPDATE `vendor_categories` SET `ikon`='musik', `is_public`=1,
  `ringkas`='Yang membuat tamu bertahan sampai akhir, atau pulang lebih awal.',
  `kisaran`='Rp 3 – 30 juta',
  `seo_title`='Musik & Hiburan Pernikahan Yogyakarta — Akustik, Band, Gamelan',
  `seo_desc`='Memilih musik pernikahan: akustik, full band, atau gamelan; berapa lama main, dan penyesuaian dengan susunan acara.'
  WHERE `slug`='music';

UPDATE `vendor_categories` SET `ikon`='mc', `is_public`=1,
  `ringkas`='Satu-satunya vendor yang berbicara mewakili keluarga.',
  `kisaran`='Rp 2 – 15 juta',
  `seo_title`='MC Pernikahan Yogyakarta — Adat Jawa, Bilingual, dan Tarif',
  `seo_desc`='Memilih MC pernikahan di Yogyakarta: MC adat Jawa, bilingual, dan bagaimana MC bekerja sama dengan rundown.'
  WHERE `slug`='mc';

UPDATE `vendor_categories` SET `ikon`='sound', `is_public`=1,
  `ringkas`='Tidak ada yang memuji sound bagus. Semua ingat sound jelek.',
  `kisaran`='Rp 3 – 25 juta',
  `seo_title`='Sound System & Lighting Pernikahan Yogyakarta',
  `seo_desc`='Sound system pernikahan: menghitung kebutuhan menurut jumlah tamu dan jenis venue, plus kelistrikan cadangan.'
  WHERE `slug`='sound-system';

UPDATE `vendor_categories` SET `ikon`='undangan', `is_public`=1,
  `ringkas`='Hal pertama yang dilihat tamu, berbulan-bulan sebelum acaranya.',
  `kisaran`='Rp 3 – 25 rb / lembar',
  `seo_title`='Undangan Pernikahan Yogyakarta — Cetak, Digital, dan Waktu Kirim',
  `seo_desc`='Undangan pernikahan: beda cetak dan digital, kapan harus dikirim, dan berapa banyak yang perlu dicetak.'
  WHERE `slug`='undangan';

UPDATE `vendor_categories` SET `ikon`='kue', `is_public`=1,
  `ringkas`='Bagian acara yang difoto paling banyak per menitnya.',
  `kisaran`='Rp 1,5 – 12 juta',
  `seo_title`='Wedding Cake Yogyakarta — Ukuran, Rasa, dan Pemotongan',
  `seo_desc`='Memilih kue pernikahan: ukuran menurut jumlah tamu, kue asli vs dummy, dan cuaca Yogyakarta.'
  WHERE `slug`='wedding-cake';

-- Ikon untuk kategori yang belum publik — tetap dipasang supaya kalau
-- nanti dipublikasikan, tampilannya tidak kosong.
UPDATE `vendor_categories` SET `ikon`='foto'     WHERE `slug` IN ('photo-booth','wcc');
UPDATE `vendor_categories` SET `ikon`='sound'    WHERE `slug` IN ('lighting','multimedia','genset');
UPDATE `vendor_categories` SET `ikon`='venue'    WHERE `slug` IN ('tenda','meja','kursi','ac-misty-fan');
UPDATE `vendor_categories` SET `ikon`='busana'   WHERE `slug` IN ('bridal-robes');
UPDATE `vendor_categories` SET `ikon`='undangan' WHERE `slug` IN ('souvenir','wrapping-seserahan');
UPDATE `vendor_categories` SET `ikon`='musik'    WHERE `slug` IN ('party-effect','audio-guest-book');
UPDATE `vendor_categories` SET `ikon`='venue'    WHERE `slug`='transport';


-- =====================================================================
-- 2. FORMULIR PUBLIK
-- =====================================================================
-- Klien yang masuk lewat formulir perlu bisa dibedakan dari yang dicatat
-- manual — kalau tidak, laporan sumber klien jadi menyesatkan.
ALTER TABLE `clients`
  ADD COLUMN `dari_form`  TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = masuk sendiri lewat form.callalily.party',
  ADD COLUMN `form_ip`    VARCHAR(45) NOT NULL DEFAULT '',
  ADD COLUMN `form_brief` TEXT NULL
      COMMENT 'Susunan hari mentah yang dibawa dari penyusun di beranda';

ALTER TABLE `clients`
  ADD KEY `idx_clients_form` (`dari_form`, `created_at`);

-- Penahan banjir kiriman. Tanpa ini, satu skrip bisa membuat ribuan baris
-- klien dalam semenit dan panel jadi tidak terpakai.
CREATE TABLE IF NOT EXISTS `form_throttle` (
  `ip`      VARCHAR(45) NOT NULL,
  `jumlah`  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `pertama` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `terakhir` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ip`),
  KEY `idx_ft_waktu` (`terakhir`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`k`,`v`,`is_secret`) VALUES
  ('form_url', 'https://form.callalily.party', 0),
  ('form_aktif', '1', 0)
ON DUPLICATE KEY UPDATE `v` = `v`;

COMMIT;

-- =====================================================================
-- VERIFIKASI
-- =====================================================================
-- SELECT slug, ikon, is_public, kisaran FROM vendor_categories
--   WHERE parent_id IS NULL ORDER BY is_public DESC, urutan;
-- SELECT COUNT(*) FROM vendor_categories WHERE is_public = 1;   -- 12
