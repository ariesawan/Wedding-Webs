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
 * peringatan — migration-v22.sql tetap bisa dijalankan manual.
 */

const SKEMA_VERSI = 23;

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

    skemaBenihPaket();
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
