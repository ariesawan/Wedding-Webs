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

const SKEMA_VERSI = 22;

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
 * Dipanggil dari bootstrap. Hanya bekerja kalau versinya tertinggal, dan
 * paling sering sekali tiap 10 menit kalau terus gagal — supaya hosting
 * yang menolak ALTER tidak dihantam pemeriksaan di setiap halaman.
 */
function skemaPastikan(): void
{
    try {
        if ((int) setting('skema_versi', '0') >= SKEMA_VERSI) return;
        $terakhir = (int) setting('skema_coba_at', '0');
        if ($terakhir && time() - $terakhir < 600 && setting('skema_galat', '') !== '') return;
        settingSet('skema_coba_at', (string) time());
        skemaPerbarui();
    } catch (Throwable $e) {
        error_log('skema: ' . $e->getMessage());
    }
}
