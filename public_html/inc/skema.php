<?php
/**
 * ============================================================
 * PEMBARUAN STRUKTUR DATABASE OTOMATIS
 * ============================================================
 *
 * Selama ini setiap fitur baru datang bersama satu berkas migration-vNN.sql
 * yang harus dijalankan manual lewat phpMyAdmin. Dump server per 5 Oktober
 * memperlihatkan akibatnya: v20 sudah jalan, tetapi v19 dan v21 belum —
 * padahal kodenya sudah memakai kolom dari keduanya. Hasilnya:
 *
 *   - formulir publik gagal menyimpan (tamu_akad tidak ada), padahal baris
 *     klien sudah telanjur dibuat, jadi calon klien melihat pesan galat
 *     lalu mengirim ulang;
 *   - "Simpan data" dan "Simpan kebutuhan" di halaman klien gagal;
 *   - "Klien menawar" di penawaran gagal.
 *
 * Berkas ini menutup celah itu: begitu kode baru diunggah, kolom yang
 * dibutuhkan ditambahkan sendiri pada permintaan pertama. Penandanya disimpan
 * di settings.skema_versi, jadi setelah sekali berhasil pemeriksaannya cuma
 * satu perbandingan angka — settings sudah dibaca utuh di setiap halaman.
 *
 * Semua langkah aman diulang: kolom diperiksa dulu di information_schema,
 * baris contoh hanya dibuat kalau belum ada. Kalau pengguna database tidak
 * punya hak ALTER, galatnya dicatat ke error_log dan panel menampilkan
 * peringatan — db/migration-v24.sql tetap bisa dijalankan manual.
 */

const SKEMA_VERSI = 24;

function skemaAdaKolom(string $tabel, string $kolom): bool
{
    return (bool) one("SELECT 1 FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                      [$tabel, $kolom]);
}

function skemaAdaTabel(string $tabel): bool
{
    return (bool) one("SELECT 1 FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$tabel]);
}

function skemaTambahKolom(string $tabel, string $kolom, string $definisi): void
{
    if (!skemaAdaTabel($tabel) || skemaAdaKolom($tabel, $kolom)) return;
    try {
        db()->exec("ALTER TABLE `$tabel` ADD COLUMN `$kolom` $definisi");
    } catch (PDOException $e) {
        // Dua permintaan bersamaan bisa sama-sama mencoba menambah kolom yang
        // sama. Yang kalah cepat mendapat "Duplicate column" — itu bukan galat.
        if (!skemaAdaKolom($tabel, $kolom)) throw $e;
    }
}

/** Jalankan semua langkah yang belum. Mengembalikan pesan galat, atau '' bila beres. */
function skemaPerbarui(): string
{
    try {
        // ---- v19: tawar-menawar pada penawaran ----
        skemaTambahKolom('quotes', 'nego_nilai',   "DECIMAL(14,2) NULL COMMENT 'Angka yang diminta klien saat menawar'");
        skemaTambahKolom('quotes', 'nego_catatan', "VARCHAR(400) NOT NULL DEFAULT '' COMMENT 'Alasan klien menawar'");
        skemaTambahKolom('quotes', 'nego_at',      "DATETIME NULL");
        skemaTambahKolom('quotes', 'revisi_dari',  "INT UNSIGNED NULL COMMENT 'quotes.id yang direvisi'");

        // ---- v20: template penawaran ----
        db()->exec("CREATE TABLE IF NOT EXISTS `quote_templates` (
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
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS `quote_template_items` (
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
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        skemaTambahKolom('quotes', 'template_id', "INT UNSIGNED NULL COMMENT 'Template asal, kalau dibuat dari template'");

        // ---- v21: tamu akad & resepsi dipisah ----
        $baruTamu = skemaAdaTabel('client_wedding_info') && !skemaAdaKolom('client_wedding_info', 'tamu_resepsi');
        skemaTambahKolom('client_wedding_info', 'tamu_akad',    "INT UNSIGNED NULL COMMENT 'Perkiraan tamu akad / pemberkatan'");
        skemaTambahKolom('client_wedding_info', 'tamu_resepsi', "INT UNSIGNED NULL COMMENT 'Perkiraan tamu resepsi'");
        if ($baruTamu) {
            // Angka lama hampir selalu angka resepsi — itu yang dipakai
            // menghitung catering dan kursi.
            q("UPDATE client_wedding_info wi JOIN clients c ON c.id = wi.client_id
                  SET wi.tamu_resepsi = c.guest_estimate
                WHERE wi.tamu_resepsi IS NULL AND c.guest_estimate > 0");
        }

        // ---- v21: kategori Wedding Organizer ----
        if (skemaAdaTabel('vendor_categories')) {
            q("INSERT INTO vendor_categories (slug, nama, urutan, is_custom, is_active, ikon, ringkas, is_public)
               SELECT 'wedding-organizer', 'Wedding Organizer', 5, 0, 1, '',
                      'Koordinasi seluruh rangkaian acara, dari persiapan sampai hari-H.', 0
                FROM DUAL
               WHERE NOT EXISTS (SELECT 1 FROM vendor_categories WHERE slug = 'wedding-organizer')");
        }

        // ---- v22: penanda kapan klien melihat tautan penawaran ----
        // seen_at sudah ada sejak v8, tapi tidak ada halaman publik yang
        // mengisinya. Tidak ada kolom baru di sini — cukup dicatat bahwa
        // struktur v22 lengkap.

        skemaV23();
        skemaV24();

        settingSet('skema_versi', (string) SKEMA_VERSI);
        settingSet('skema_galat', '');
        return '';
    } catch (Throwable $e) {
        $pesan = mb_substr($e->getMessage(), 0, 300);
        error_log('skema: ' . $pesan);
        try { settingSet('skema_galat', $pesan); } catch (Throwable $e2) { /* settings pun gagal */ }
        return $pesan;
    }
}

/**
 * v23 — alur yang disepakati owner (Oktober 2026):
 *
 *   admin early : biodata awal → kirim price list (paket) → cocok → DP 30%
 *   admin office: biodata lengkap & keluarga → dekor, venue, vendor →
 *                 termin → meeting → persiapan → hari-H
 *
 * Yang ditambahkan:
 *   - tahap 'dp' (Menunggu DP) di antara price list dan deal
 *   - paket price list yang tampil di situs (memakai quote_templates)
 *   - penawaran berbentuk "paket + rincian isi"
 *   - konsep dekor di data acara
 *   - log SETIAP kiriman formulir publik, termasuk yang gagal, supaya
 *     tidak ada calon klien yang hilang tanpa jejak
 */
function skemaV23(): void
{
    // ---- tahap 'dp' ----
    // ENUM dibaca dulu lalu disisipi, bukan ditulis ulang dari ingatan: nilai
    // yang terlupa akan diubah MySQL jadi string kosong tanpa peringatan.
    $kol = one("SELECT COLUMN_TYPE t FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'stage'");
    if ($kol && !str_contains($kol['t'], "'dp'")) {
        preg_match_all("/'([^']*)'/", $kol['t'], $m);
        $nilai = $m[1];
        $pos = array_search('pricelist', $nilai, true);
        array_splice($nilai, $pos === false ? 1 : $pos + 1, 0, ['dp']);
        db()->exec("ALTER TABLE clients MODIFY COLUMN stage ENUM('" . implode("','", $nilai) . "') NOT NULL DEFAULT 'baru'");
    }
    // Tahap lama 'spesifikasi' dan 'penawaran' tidak lagi dipakai — klien di
    // sana (bila ada) belum DP, jadi kembali ke pegangan admin early.
    q("UPDATE clients SET stage = 'pricelist' WHERE stage IN ('spesifikasi','penawaran')");

    skemaTambahKolom('clients', 'paket_minat', "INT UNSIGNED NULL COMMENT 'quote_templates.id yang dipilih klien'");

    // ---- paket price list ----
    skemaTambahKolom('quote_templates', 'slug',        "VARCHAR(140) NOT NULL DEFAULT ''");
    skemaTambahKolom('quote_templates', 'ringkas',     "VARCHAR(190) NOT NULL DEFAULT '' COMMENT 'Satu kalimat di bawah nama paket'");
    skemaTambahKolom('quote_templates', 'harga',       "DECIMAL(14,2) NULL COMMENT 'Harga paket; NULL = belum diisi'");
    skemaTambahKolom('quote_templates', 'harga_mulai', "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = ditulis mulai dari'");
    skemaTambahKolom('quote_templates', 'tamu',        "SMALLINT UNSIGNED NULL COMMENT 'Perkiraan tamu paket'");
    skemaTambahKolom('quote_templates', 'tampil_web',  "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = tampil di halaman price list'");
    skemaTambahKolom('quote_templates', 'unggulan',    "TINYINT(1) NOT NULL DEFAULT 0");
    skemaTambahKolom('quote_template_items', 'kelompok', "VARCHAR(80) NOT NULL DEFAULT '' COMMENT 'Judul kelompok rincian isi'");
    skemaTambahKolom('quotes', 'paket_nama',  "VARCHAR(120) NOT NULL DEFAULT ''");
    skemaTambahKolom('quotes', 'paket_harga', "DECIMAL(14,2) NULL");
    skemaTambahKolom('quote_items', 'kelompok', "VARCHAR(80) NOT NULL DEFAULT ''");

    // ---- konsep dekor ----
    skemaTambahKolom('client_wedding_info', 'konsep_dekor', "TEXT NULL COMMENT 'Tema, warna, referensi dekor'");

    // ---- log formulir ----
    db()->exec("CREATE TABLE IF NOT EXISTS `form_masuk` (
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
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Template lama (v20) menyimpan harga per baris. Model sekarang "paket +
    // rincian isi": harga ada di paket, baris isi tanpa harga. Jumlah baris
    // isinya dipindah jadi harga paket supaya tidak tercetak sebagai
    // "tambahan" di PDF dan tidak hilang saat paketnya disunting.
    q("UPDATE quote_templates t
         JOIN (SELECT template_id, SUM(harga * qty) s FROM quote_template_items
                WHERE opsional = 0 GROUP BY template_id HAVING s > 0) x ON x.template_id = t.id
          SET t.harga = x.s, t.harga_mulai = 0
        WHERE t.harga IS NULL");
    q("UPDATE quote_template_items i JOIN quote_templates t ON t.id = i.template_id
          SET i.harga = 0
        WHERE i.opsional = 0 AND i.harga <> 0 AND t.harga IS NOT NULL");

    skemaBenihPaket();
}

/**
 * v24 — termin pembayaran & dashboard pengantin.
 *
 * Pembayaran: uang masuk dicatat per transfer di payment_receipts (bisa
 * sebagian, bisa dibatalkan dengan alasan, punya nomor kwitansi). Kolom
 * payments.terbayar adalah cache jumlah yang sudah diterima — hanya ditulis
 * bayarHitungUlang() — sehingga semua query cukup memakai amount − terbayar.
 * payments.offset_hari membekukan aturan "H-n" per klien: mengubah template
 * tidak lagi menggeser jadwal klien lama, dan tanggal yang diubah tangan
 * tidak tertimpa saat hari-H bergeser.
 *
 * Dashboard pengantin: satu token per klien (clients.portal_token), kapan
 * pertama dibuka, dan kapan klien terakhir mengisi data.
 */
function skemaV24(): void
{
    $kolomBaru = fn(string $t, string $k) => !skemaAdaKolom($t, $k);

    // ---- payments ----
    $terbayarBaru = $kolomBaru('payments', 'terbayar');
    skemaTambahKolom('payments', 'terbayar', "DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Cache jumlah penerimaan sah; hanya ditulis bayarHitungUlang()' AFTER amount");
    $offsetBaru = $kolomBaru('payments', 'offset_hari');
    skemaTambahKolom('payments', 'offset_hari', "INT NULL COMMENT 'Salinan payment_templates.offset_hari; NULL = tanggal tetap' AFTER due_date");
    skemaTambahKolom('payments', 'ingat_kode', "VARCHAR(24) NOT NULL DEFAULT '' COMMENT 'Pengingat terakhir: sebelum@YYYY-MM-DD / telat@YYYY-MM-DD'");
    skemaTambahKolom('payments', 'ingat_at', "DATETIME NULL COMMENT 'Kapan pengingat/tagihan terakhir dikirim'");

    // ---- clients ----
    skemaTambahKolom('clients', 'pengingat_bayar', "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = jangan kirim pengingat termin otomatis'");
    skemaTambahKolom('clients', 'portal_token', "CHAR(32) NULL DEFAULT NULL COMMENT 'Tautan dashboard pengantin; NULL = belum dibuat / dimatikan'");
    skemaTambahKolom('clients', 'portal_seen_at', "DATETIME NULL COMMENT 'Pertama kali dashboard dibuka klien'");
    skemaTambahKolom('clients', 'portal_isi_at', "DATETIME NULL COMMENT 'Terakhir klien mengisi data lewat dashboard'");
    if (!one("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'clients' AND INDEX_NAME = 'uq_clients_portal'")) {
        try { db()->exec("ALTER TABLE clients ADD UNIQUE KEY uq_clients_portal (portal_token)"); }
        catch (PDOException $e) { if (!str_contains($e->getMessage(), 'Duplicate key name')) throw $e; }
    }

    // ---- client_wedding_info: isian dari pengantin ----
    skemaTambahKolom('client_wedding_info', 'dekor_klien', "TEXT NULL COMMENT 'Keinginan & referensi dekor dari pengantin (dashboard)'");
    skemaTambahKolom('client_wedding_info', 'pria_nama', "VARCHAR(190) NOT NULL DEFAULT '' COMMENT 'Nama lengkap bergelar untuk undangan'");
    skemaTambahKolom('client_wedding_info', 'wanita_nama', "VARCHAR(190) NOT NULL DEFAULT '' COMMENT 'Nama lengkap bergelar untuk undangan'");

    // ---- penerimaan ----
    $penerimaanBaru = !skemaAdaTabel('payment_receipts');
    db()->exec("CREATE TABLE IF NOT EXISTS `payment_receipts` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `client_id` INT UNSIGNED NOT NULL,
        `payment_id` INT UNSIGNED NOT NULL COMMENT 'Termin tempat alokasi ini masuk',
        `kwitansi_no` VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'Sama untuk semua alokasi dari satu transfer; kosong = data lama',
        `tanggal` DATE NOT NULL COMMENT 'Tanggal dana diterima',
        `jumlah` DECIMAL(14,2) NOT NULL,
        `metode` VARCHAR(60) NOT NULL DEFAULT '',
        `pengirim` VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'Pemilik rekening pengirim, tercetak di kwitansi',
        `bukti` VARCHAR(190) NULL COMMENT 'Path relatif di folder bukti privat',
        `status` ENUM('sah','menunggu','ditolak','batal') NOT NULL DEFAULT 'sah',
        `sumber` ENUM('admin','portal','migrasi') NOT NULL DEFAULT 'admin',
        `pesan_klien` VARCHAR(400) NOT NULL DEFAULT '',
        `alasan_tolak` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Dibaca klien',
        `catatan` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Internal — tidak pernah tampil ke klien',
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
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Data lama: termin yang sudah ditandai lunas jadi satu penerimaan tanpa
    // nomor kwitansi (tidak pernah dikirim otomatis). Tanggal masa depan —
    // dulu bisa diketik bebas — dijepit ke hari ini.
    if ($penerimaanBaru) {
        q("INSERT INTO payment_receipts (client_id, payment_id, kwitansi_no, tanggal, jumlah, metode, status, sumber, catatan)
           SELECT p.client_id, p.id, '', LEAST(p.paid_at, CURDATE()), p.amount, p.method, 'sah', 'migrasi',
                  CONCAT('migrasi v24; tanggal asli ', p.paid_at)
             FROM payments p
            WHERE p.paid_at IS NOT NULL AND p.amount > 0
              AND NOT EXISTS (SELECT 1 FROM payment_receipts r WHERE r.payment_id = p.id)");
    }
    if ($terbayarBaru) {
        q("UPDATE payments SET terbayar = amount, paid_at = LEAST(paid_at, CURDATE()) WHERE paid_at IS NOT NULL");
    }
    if ($offsetBaru && skemaAdaTabel('payment_templates')) {
        q("UPDATE payments p JOIN payment_templates t ON t.kode = p.kode AND p.kode <> ''
              SET p.offset_hari = t.offset_hari");
    }

    // ---- Ringkasan: hitungan meeting per klien tanpa memindai seluruh tabel ----
    if (skemaAdaKolom('meetings', 'client_id') && !one("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'meetings' AND INDEX_NAME = 'idx_meetings_client'")) {
        try { db()->exec("ALTER TABLE meetings ADD KEY idx_meetings_client (client_id)"); }
        catch (PDOException $e) { if (!str_contains($e->getMessage(), 'Duplicate key name')) throw $e; }
    }

    // Checklist lama menulis "DP diterima dan dicatat" sebagai langkah H-90
    // yang terbuka walau DP-nya sudah lunas — di Ringkasan jadi "lewat tempo"
    // palsu. Tandai selesai pada tanggal DP-nya lunas.
    if (skemaAdaTabel('client_tasks')) {
        q("UPDATE client_tasks t
             JOIN payments p ON p.id = (SELECT p2.id FROM payments p2 WHERE p2.client_id = t.client_id
                                         ORDER BY (p2.kode = 'dealing') DESC, p2.wajib DESC, p2.sort_order, p2.id LIMIT 1)
              SET t.done_at = CAST(p.paid_at AS DATETIME)
            WHERE t.done_at IS NULL AND t.title = 'DP diterima dan dicatat' AND p.paid_at IS NOT NULL");
    }
}

/**
 * Paket contoh — KOSONGAN tapi tersusun wajar.
 *
 * Isinya diambil dari kalimat yang sudah ada di beranda (tiga susunan hari
 * Prasaja / Semanak / Sidomukti), harganya sengaja kosong: angka harga hanya
 * boleh datang dari owner. Selama harga kosong, halaman price list menulis
 * "harga dikirim lewat WhatsApp", bukan angka karangan.
 *
 * Hanya dibuat sekali (dicek lewat slug), jadi menghapus atau mengubahnya
 * dari panel tidak akan dibatalkan oleh pemeriksaan berikutnya.
 */
function skemaBenihPaket(): void
{
    if (setting('paket_benih', '') === '1') return;
    // Dua permintaan pertama yang datang bersamaan tidak boleh sama-sama
    // menanam paket. Kunci MariaDB + baca ulang penanda langsung dari tabel
    // (bukan dari cache setting per permintaan).
    if ((int) (one("SELECT GET_LOCK('callalily_benih', 10) g")['g'] ?? 0) !== 1) return;
    try {
        if ((one("SELECT v FROM settings WHERE k = 'paket_benih'")['v'] ?? '') === '1') return;
        skemaBenihPaketIsi();
    } finally {
        q("SELECT RELEASE_LOCK('callalily_benih')");
    }
}

function skemaBenihPaketIsi(): void
{

    $syarat = "Harga berlaku sampai tanggal yang tertulis di atas.\n"
            . "DP 30% untuk mengunci tanggal; sisa pembayaran mengikuti termin di bawah.\n"
            . "Vendor dan rincian final disepakati bersama admin office setelah DP.\n"
            . "Belum termasuk: sewa venue, akomodasi tim untuk acara luar kota, dan perizinan — kecuali tertulis di rincian.";

    $paket = [
        ['prasaja', 'Prasaja', 'Satu acara. Hening, hangat, rapi — akad atau pemberkatan dengan ramah tamah.', 150, 0, 10, [
            ['Wedding Organizer', 'Konsultasi & perencanaan acara', ''],
            ['Wedding Organizer', 'Koordinasi vendor', ''],
            ['Wedding Organizer', 'Tim hari-H', '±2 kru lapangan + tim inti'],
            ['Wedding Organizer', 'Rundown acara', ''],
            ['Rias & busana', 'Make up mempelai', 'Akad / pemberkatan'],
            ['Rias & busana', 'Busana mempelai', '1 set'],
            ['Dekorasi', 'Dekorasi akad / pemberkatan', ''],
            ['Dokumentasi', 'Foto', 'Akad sampai ramah tamah'],
            ['Konsumsi', 'Manajemen katering', 'Koordinasi vendor katering'],
        ]],
        ['semanak', 'Semanak', 'Akad pagi, resepsi malam. Hari penuh di tanggal yang sama.', 400, 1, 20, [
            ['Wedding Organizer', 'Perencanaan & kurasi vendor', 'Sejak DP sampai hari-H'],
            ['Wedding Organizer', 'Tim hari-H', '±4 kru lapangan + tim inti'],
            ['Wedding Organizer', 'Rundown, technical meeting & gladi', ''],
            ['Rias & busana', 'Make up mempelai', 'Akad & resepsi'],
            ['Rias & busana', 'Busana mempelai', '2 set — akad & resepsi'],
            ['Rias & busana', 'Make up keluarga inti', ''],
            ['Dekorasi', 'Dekorasi akad', ''],
            ['Dekorasi', 'Dekorasi pelaminan resepsi', 'Termasuk gate & area foto'],
            ['Dokumentasi', 'Foto & video', 'Akad & resepsi'],
            ['Acara', 'MC resepsi', ''],
            ['Acara', 'Kirab pengantin', ''],
            ['Konsumsi', 'Manajemen katering', 'Koordinasi vendor katering'],
        ]],
        ['sidomukti', 'Sidomukti', 'Rangkaian adat penuh, dari H-1 sampai larut malam.', 800, 0, 30, [
            ['Wedding Organizer', 'Perencanaan penuh', 'Sejak DP sampai hari-H'],
            ['Wedding Organizer', 'Koordinasi seluruh vendor', ''],
            ['Wedding Organizer', 'Tim hari-H', '±8 kru lapangan + tim inti, H-1 & hari-H'],
            ['Wedding Organizer', 'Rundown adat, technical meeting & gladi', ''],
            ['Rangkaian adat', 'Siraman & midodareni', 'H-1'],
            ['Rangkaian adat', 'Panggih adat', 'Hari-H'],
            ['Rias & busana', 'Make up mempelai', 'Siraman sampai resepsi'],
            ['Rias & busana', 'Busana mempelai & busana adat', ''],
            ['Rias & busana', 'Make up & busana keluarga inti', ''],
            ['Dekorasi', 'Dekorasi siraman & akad', ''],
            ['Dekorasi', 'Dekorasi pelaminan resepsi', 'Termasuk gate & area foto'],
            ['Dokumentasi', 'Foto & video', 'H-1 sampai resepsi'],
            ['Acara & hiburan', 'MC & hiburan', ''],
            ['Acara & hiburan', 'After-party', ''],
            ['Konsumsi', 'Manajemen katering', 'Koordinasi vendor katering'],
        ]],
        // Kerangka internal — tidak tampil di situs. Titik awal untuk paket
        // atau penawaran khusus: kelompoknya sudah ada, isinya tinggal diganti.
        ['template-kosong', 'Template kosong — susun sendiri', '', null, 0, 90, [
            ['Wedding Organizer', 'Koordinasi & tim hari-H', ''],
            ['Rias & busana', 'Make up mempelai', ''],
            ['Rias & busana', 'Busana mempelai', ''],
            ['Rias & busana', 'Make up keluarga inti', ''],
            ['Rias & busana', 'Make up keluarga besar / panitia', ''],
            ['Dekorasi', 'Dekorasi pelaminan', ''],
            ['Dokumentasi', 'Foto & video', ''],
            ['Acara & hiburan', 'MC', ''],
            ['Venue', 'Venue', ''],
            ['Konsumsi', 'Katering', ''],
            ['Undangan & souvenir', 'Undangan', ''],
        ]],
    ];

    foreach ($paket as [$slug, $nama, $ringkas, $tamu, $unggulan, $urutan, $isi]) {
        if (one("SELECT id FROM quote_templates WHERE slug = ?", [$slug])) continue;
        $web = $slug === 'template-kosong' ? 0 : 1;
        q("INSERT INTO quote_templates (nama, slug, ringkas, deskripsi, tipe, catatan_bawaan, urutan,
                                        is_active, harga, harga_mulai, tamu, tampil_web, unggulan)
           VALUES (?,?,?,?, 'semua', ?, ?, 1, NULL, 1, ?, ?, ?)",
          [$nama, $slug, $ringkas,
           $web ? 'Paket price list di situs. Harga belum diisi — lengkapi dari panel.' : 'Kerangka kosong untuk paket atau penawaran khusus.',
           $syarat, $urutan, $tamu, $web, $unggulan]);
        $tid = insertId();
        foreach ($isi as $i => [$kel, $label, $detail]) {
            q("INSERT INTO quote_template_items (template_id, kelompok, label, detail, qty, satuan, harga, opsional, sort_order)
               VALUES (?,?,?,?,1,'paket',0,0,?)", [$tid, $kel, $label, $detail, ($i + 1) * 10]);
        }
    }
    settingSet('paket_benih', '1');
}

/**
 * Dipanggil dari bootstrap. Hanya bekerja kalau versinya tertinggal, dan
 * paling sering sekali tiap 10 menit kalau terus gagal — supaya hosting
 * yang menolak ALTER tidak dihantam pemeriksaan di setiap halaman.
 */
function skemaPastikan(): void
{
    try {
        if ((int) setting('skema_versi', '0') >= SKEMA_VERSI) {
            // Kolom sudah lengkap tapi paket contoh belum ada — terjadi kalau
            // skema dipasang manual lewat db/migration-v23.sql. Dicoba sekali;
            // kalau gagal ditandai supaya tidak diulang di setiap halaman.
            if (setting('paket_benih', '') === '') {
                try { skemaBenihPaket(); }
                catch (Throwable $e) { settingSet('paket_benih', 'galat'); error_log('benih paket: ' . $e->getMessage()); }
            }
            return;
        }
        $terakhir = (int) setting('skema_coba_at', '0');
        if ($terakhir && time() - $terakhir < 600 && setting('skema_galat', '') !== '') return;
        settingSet('skema_coba_at', (string) time());
        skemaPerbarui();
    } catch (Throwable $e) {
        error_log('skema: ' . $e->getMessage());
    }
}
