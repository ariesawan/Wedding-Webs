<?php
/**
 * FORMULIR PUBLIK — penyimpanan & jejak setiap kiriman
 *
 * Keluhan yang diperbaiki: "kadang kiriman formulir tidak ke-track di
 * dashboard". Penyebabnya bukan satu, jadi penanganannya juga berlapis:
 *
 *   1. SETIAP kiriman dicatat ke tabel form_masuk SEBELUM apa pun dikerjakan —
 *      termasuk yang ditolak penjagaan, gagal validasi, atau galat database.
 *      Isi lengkapnya ikut tersimpan, jadi admin selalu bisa menghubungi
 *      balik walau klien gagal terbentuk.
 *   2. Klien dibuat dalam SATU transaksi, dengan tindakan berikutnya langsung
 *      di INSERT. Dulu next_action diisi belakangan — kalau langkah tengah
 *      gagal, klien terbentuk tanpa tugas dan tidak muncul di daftar mana pun.
 *   3. Nomor WA dicocokkan dari 9 digit terakhir, bukan teks persis.
 *      "0812…", "+62 812…", "62812…" adalah orang yang sama.
 *   4. Klien lama yang berstatus "tidak jadi" diaktifkan lagi ke Prospek baru.
 *      Dulu kirimannya diam-diam menempel di arsip dan tidak terlihat.
 *   5. Admin early diberi tahu lewat WhatsApp (kalau gateway tersambung).
 */
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/paket.php';

const FORM_SUMBER = ['instagram' => 'Instagram', 'referral' => 'Rekomendasi teman',
                     'vendor' => 'Vendor lain', 'web' => 'Google / situs',
                     'walkin' => 'Datang langsung', 'lainnya' => 'Lainnya'];

/** 0812… / +62 812… / 812… → 62812… */
function formWaNormal(string $wa): string
{
    $n = preg_replace('/\D/', '', $wa);
    if (str_starts_with($n, '0'))  $n = '62' . substr($n, 1);
    if (str_starts_with($n, '8'))  $n = '62' . $n;
    return $n;
}

/**
 * Token formulir tanpa sesi.
 *
 * Dulu token disimpan di sesi PHP. Sesi di shared hosting dibersihkan setelah
 * ±24 menit tanpa aktivitas — calon klien yang mengisi pelan-pelan, atau
 * membiarkan tab terbuka lalu kembali, kirimannya ditolak "halaman
 * kedaluwarsa". Token bertanda tangan HMAC tidak bergantung pada sesi: yang
 * dibuktikan hanya bahwa halaman ini benar-benar dimuat dari situs kita.
 */
function formToken(): string
{
    $ts = time();
    return $ts . '.' . substr(hash_hmac('sha256', 'formulir|' . $ts, APP_KEY), 0, 32);
}

/** '' = sah; 'palsu' = tidak bertanda tangan; 'cepat' = dikirim < 3 detik. */
function formTokenCek(string $tok): string
{
    if (!preg_match('/^(\d{9,11})\.([0-9a-f]{32})$/', $tok, $m)) return 'palsu';
    $sah = substr(hash_hmac('sha256', 'formulir|' . $m[1], APP_KEY), 0, 32);
    if (!hash_equals($sah, $m[2])) return 'palsu';
    $umur = time() - (int) $m[1];
    if ($umur < 3) return 'cepat';
    // Umur maksimum sengaja panjang (30 hari): tab yang dibiarkan terbuka
    // berhari-hari tetap kiriman manusia.
    if ($umur > 30 * 86400) return 'palsu';
    return '';
}

/** Ambil & rapikan isian dari $_POST (atau payload log). */
function formIsi(array $src): array
{
    $isi = [
        'pria'    => mb_substr(trim((string) ($src['pria'] ?? '')), 0, 120),
        'wanita'  => mb_substr(trim((string) ($src['wanita'] ?? '')), 0, 120),
        'wa'      => mb_substr(trim((string) ($src['wa'] ?? '')), 0, 30),
        'email'   => mb_substr(trim((string) ($src['email'] ?? '')), 0, 160),
        'ig'      => mb_substr(ltrim(trim((string) ($src['ig'] ?? '')), '@'), 0, 79),
        'tanggal' => mb_substr(trim((string) ($src['tanggal'] ?? '')), 0, 10),
        'kota'    => mb_substr(trim((string) ($src['kota'] ?? '')), 0, 90),
        'venue'   => mb_substr(trim((string) ($src['venue'] ?? '')), 0, 190),
        'tamu'    => (int) preg_replace('/\D/', '', (string) ($src['tamu'] ?? '0')),
        // Angka pertama saja: "50-70 juta" atau "Rp 50.000.000 - 70.000.000"
        // tidak boleh digabung jadi satu angka raksasa yang ditolak database.
        'budget'  => preg_match('/\d[\d.,]*/', (string) ($src['budget'] ?? ''), $mb)
                     ? (int) preg_replace('/\D/', '', preg_replace('/[.,]\d{1,2}$/', '', $mb[0])) : 0,
        'paket'   => mb_substr(trim((string) ($src['paket'] ?? '')), 0, 80),
        'sumber'  => trim((string) ($src['sumber'] ?? '')),
        'catatan' => mb_substr(trim((string) ($src['catatan'] ?? '')), 0, 2000),
        'brief'   => mb_substr(trim((string) ($src['brief'] ?? '')), 0, 4000),
    ];
    if (!isset(FORM_SUMBER[$isi['sumber']])) $isi['sumber'] = 'web';
    if ($isi['tamu'] > 30000) $isi['tamu'] = 30000;
    // "50 juta", "75jt" — orang lebih sering menulis begini daripada nol lengkap.
    if ($isi['budget'] > 0 && $isi['budget'] < 100000
        && preg_match('/\b(jt|juta)\b|\djt/i', (string) ($src['budget'] ?? ''))) {
        $isi['budget'] *= 1000000;
    }
    if ($isi['budget'] > 999999999999) $isi['budget'] = 0;   // batas DECIMAL(14,2)
    return $isi;
}

/** Daftar pesan galat validasi (kosong = sah). */
function formValidasi(array $isi): array
{
    $g = [];
    if ($isi['pria'] === '' && $isi['wanita'] === '') $g[] = 'Nama mempelai belum diisi.';
    $wa = formWaNormal($isi['wa']);
    if (strlen($wa) < 10 || strlen($wa) > 15) $g[] = 'Nomor WhatsApp belum benar.';
    if ($isi['email'] !== '' && !filter_var($isi['email'], FILTER_VALIDATE_EMAIL)) {
        $g[] = 'Alamat email tidak valid.';
    }
    if ($isi['tanggal'] !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $isi['tanggal']);
        if (!$d || $d->format('Y-m-d') !== $isi['tanggal']) $g[] = 'Tanggal tidak terbaca.';
    }
    return $g;
}

/** Ringkasan satu baris isian, untuk catatan klien. */
function formRingkas(array $isi): string
{
    return implode(' · ', array_filter([
        trim($isi['pria'] . ' & ' . $isi['wanita'], ' &'),
        $isi['tanggal'] !== '' ? 'tanggal ' . $isi['tanggal'] : '',
        $isi['tamu'] > 0 ? '±' . $isi['tamu'] . ' tamu' : '',
        $isi['venue'], $isi['kota'],
        $isi['budget'] > 0 ? 'budget ' . rupiah((float) $isi['budget']) : '',
        $isi['paket'] !== '' ? 'paket ' . $isi['paket'] : '',
    ]));
}

/** Paket (baris quote_templates) dari slug/id isian; null bila "belum tahu". */
function formPaket(string $kunci): ?array
{
    if ($kunci === '') return null;
    $t = paketBySlug($kunci);
    if (!$t && ctype_digit($kunci)) {
        $t = one("SELECT * FROM quote_templates WHERE id = ? AND is_active = 1", [(int) $kunci]) ?: null;
    }
    return $t ?: null;
}

/**
 * Catat kiriman ke form_masuk. Mengembalikan id baris (0 kalau tabelnya
 * belum ada — pencatatan yang gagal bukan alasan menolak calon klien).
 */
function formLog(string $status, array $isi, string $ip, string $pesan = '', ?int $clientId = null): int
{
    try {
        // Kiriman yang ditolak/bot dari satu IP dibatasi 20 baris per jam —
        // supaya log ini tidak bisa dibanjiri. Kiriman yang lolos selalu dicatat.
        if (in_array($status, ['ditolak', 'bot'], true)) {
            $n = (int) (one("SELECT COUNT(*) n FROM form_masuk WHERE ip = ? AND status IN ('ditolak','bot')
                              AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)", [mb_substr($ip, 0, 45)])['n'] ?? 0);
            if ($n >= 20) { error_log("form_masuk: $status dari $ip tidak dicatat (batas per jam)"); return 0; }
        }
        $nama  = trim($isi['pria'] . ($isi['pria'] !== '' && $isi['wanita'] !== '' ? ' & ' : '') . $isi['wanita']);
        $paket = formPaket($isi['paket'])['nama'] ?? $isi['paket'];
        q("INSERT INTO form_masuk (ip, status, client_id, nama, wa, paket, pesan, payload)
           VALUES (?,?,?,?,?,?,?,?)",
          [mb_substr($ip, 0, 45), $status, $clientId, mb_substr($nama, 0, 190),
           mb_substr(formWaNormal($isi['wa']) ?: $isi['wa'], 0, 40), mb_substr($paket, 0, 140),
           mb_substr($pesan, 0, 400),
           json_encode($isi, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
        return insertId();
    } catch (Throwable $e) {
        error_log('form_masuk: ' . $e->getMessage() . ' · ' . json_encode($isi, JSON_UNESCAPED_UNICODE));
        return 0;
    }
}

function formLogSet(int $logId, string $status, ?int $clientId, string $pesan): void
{
    if ($logId <= 0) return;
    try {
        q("UPDATE form_masuk SET status = ?, client_id = ?, pesan = ? WHERE id = ?",
          [$status, $clientId, mb_substr($pesan, 0, 400), $logId]);
    } catch (Throwable $e) { error_log('form_masuk: ' . $e->getMessage()); }
}

/**
 * Klien yang nomornya sama (9 digit terakhir). Yang masih aktif didahulukan.
 * Klien yang acaranya sudah selesai tidak dicocokkan: kiriman baru dari nomor
 * itu (klien lama yang kembali, atau adik yang memakai nomor sama) adalah
 * prospek baru, bukan alasan menimpa catatan acara yang sudah lewat.
 */
function formCariKlien(string $wa): ?array
{
    $ekor = substr(formWaNormal($wa), -9);
    if (strlen($ekor) < 9) return null;
    return one("SELECT id, stage, name, partner_name FROM clients
                WHERE phone <> '' AND stage <> 'selesai'
                  AND RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 9) = ?
                ORDER BY (stage = 'batal'), id DESC LIMIT 1", [$ekor]) ?: null;
}

/**
 * Simpan kiriman jadi klien. Satu transaksi: kalau satu langkah gagal,
 * semuanya batal dan kiriman tetap ada di form_masuk sebagai 'galat'.
 *
 * @return array{id:int, ulang:bool, info:string}
 */
function formSimpan(array $isi, string $ip, ?int $userId = null): array
{
    $wa    = formWaNormal($isi['wa']);
    $paket = formPaket($isi['paket']);
    $sumber = $isi['sumber'];

    $catatan = $isi['catatan'];
    if ($paket) $catatan = trim('Paket diminati: ' . $paket['nama'] . "\n" . $catatan);
    if ($isi['brief'] !== '') {
        $catatan = trim($catatan . "\n\n--- Susunan hari dari penyusun ---\n" . $isi['brief']);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ada  = formCariKlien($wa);
        $info = '';
        if ($ada && stageSudahDeal($ada['stage'])) {
            // Klien yang sudah DP: datanya dipegang admin office. Kiriman
            // formulir hanya ditempel sebagai catatan, tidak menimpa tanggal,
            // venue, atau paket yang sudah disepakati.
            $id = (int) $ada['id'];
            q("UPDATE clients SET notes = TRIM(CONCAT(COALESCE(notes,''), '\n\n[kiriman formulir ', DATE_FORMAT(NOW(), '%d/%m/%Y'), ']\n', ?)),
                 updated_at = NOW() WHERE id = ?", [trim(formRingkas($isi) . "\n" . $catatan), $id]);
            $info = 'Klien sudah DP — kiriman ditempel di catatan, data tidak ditimpa.';
            clientLog($id, 'catatan', 'Mengirim formulir lagi', $info, $userId);
        } elseif ($ada) {
            $id = (int) $ada['id'];
            // Nilai baru menang kalau diisi, nilai lama bertahan kalau kosong.
            // Kiriman ulang biasanya diisi seadanya — jangan sampai menghapus
            // data yang sudah lebih lengkap.
            q("UPDATE clients SET
                 name = COALESCE(NULLIF(?,''), name),
                 partner_name = COALESCE(NULLIF(?,''), partner_name),
                 email = COALESCE(NULLIF(?,''), email),
                 instagram = COALESCE(NULLIF(?,''), instagram),
                 wedding_date = COALESCE(?, wedding_date),
                 guest_estimate = IF(? > 0, ?, guest_estimate),
                 city = COALESCE(NULLIF(?,''), city),
                 venue = COALESCE(NULLIF(?,''), venue),
                 budget_estimate = IF(? > 0, ?, budget_estimate),
                 paket_minat = COALESCE(?, paket_minat),
                 package = IF(? <> '', ?, package),
                 notes = TRIM(CONCAT(COALESCE(notes,''), '\n\n[kiriman ulang formulir ', DATE_FORMAT(NOW(), '%d/%m/%Y'), ']\n', ?)),
                 form_brief = COALESCE(NULLIF(?,''), form_brief),
                 dari_form = 1, updated_at = NOW()
               WHERE id = ?",
              [$isi['pria'] ?: $isi['wanita'], $isi['pria'] !== '' ? $isi['wanita'] : '',
               $isi['email'], $isi['ig'],
               $isi['tanggal'] ?: null, $isi['tamu'], $isi['tamu'],
               $isi['kota'], $isi['venue'], $isi['budget'], $isi['budget'],
               $paket ? (int) $paket['id'] : null,
               $paket['nama'] ?? '', $paket['nama'] ?? '',
               $catatan, $isi['brief'], $id]);

            if ($ada['stage'] === 'batal') {
                clientSetStage($id, 'baru', $userId, 'Mengirim formulir lagi');
                $info = 'Klien lama (tidak jadi) diaktifkan lagi sebagai prospek baru.';
            } else {
                $info = 'Nomor sudah terdaftar — data klien diperbarui.';
            }
            // Prospek yang belum dibalas: tugasnya disegarkan ke hari ini.
            // Tahap yang lebih jauh (DP, office) tidak disentuh — tugas
            // "tagih DP" jauh lebih penting daripada "balas formulir".
            if (in_array($ada['stage'], ['baru', 'batal'], true)) {
                q("UPDATE clients SET next_action = ?, next_action_at = CURDATE() WHERE id = ?",
                  ['Balas kiriman formulir & kirim price list', $id]);
            }
            clientLog($id, 'catatan', 'Mengirim formulir lagi', $info, $userId);
        } else {
            q("INSERT INTO clients
                (name, partner_name, phone, email, instagram, wedding_date,
                 guest_estimate, city, venue, budget_estimate, source, notes,
                 stage, stage_changed_at, pic_role, paket_minat, package,
                 next_action, next_action_at,
                 dari_form, form_ip, form_brief, created_by, created_at)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'baru', NOW(), 'admin_biasa', ?, ?,
                       ?, CURDATE(), 1, ?, ?, ?, NOW())",
              [$isi['pria'] ?: $isi['wanita'], $isi['pria'] !== '' ? $isi['wanita'] : '',
               $wa, $isi['email'], $isi['ig'], $isi['tanggal'] ?: null,
               $isi['tamu'] ?: null, $isi['kota'], $isi['venue'], $isi['budget'] ?: null,
               $sumber, $catatan,
               $paket ? (int) $paket['id'] : null, $paket['nama'] ?? '',
               'Balas kiriman formulir & kirim price list',
               mb_substr($ip, 0, 45), $isi['brief'], $userId]);
            $id = insertId();
            clientLog($id, 'catatan', 'Masuk lewat formulir publik',
                      $paket ? 'Paket diminati: ' . $paket['nama'] : 'Belum memilih paket', $userId);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Di luar transaksi: room chat & notifikasi boleh gagal tanpa membatalkan klien.
    try { require_once __DIR__ . '/chat.php'; chatSinkronKlien($id); }
    catch (Throwable $e) { error_log('form chat: ' . $e->getMessage()); }

    return ['id' => $id, 'ulang' => (bool) $ada, 'info' => $info];
}

/** Kabari admin early lewat WA. Diam kalau gateway belum tersambung. */
function formKabariAdmin(int $clientId, array $isi, bool $ulang): void
{
    try {
        require_once __DIR__ . '/wa.php';
        $tujuan = setting('wa_admin_biasa', '');
        if ($tujuan === '' || !waSiap()) return;
        $paket = formPaket($isi['paket']);
        $nama  = trim($isi['pria'] . ($isi['pria'] !== '' && $isi['wanita'] !== '' ? ' & ' : '') . $isi['wanita']);
        $teks  = ($ulang ? "Kiriman ulang formulir\n" : "Prospek baru dari formulir\n")
               . "\n" . $nama
               . "\nWA: " . formWaNormal($isi['wa'])
               . ($isi['tanggal'] !== '' ? "\nTanggal: " . tanggalID($isi['tanggal']) : '')
               . ($isi['tamu'] > 0 ? "\nTamu: ±" . number_format($isi['tamu'], 0, ',', '.') : '')
               . "\nPaket: " . ($paket['nama'] ?? 'belum memilih')
               . "\n\nBuka: " . url('admin/klien.php?id=' . $clientId);
        waKirim($tujuan, $teks);
    } catch (Throwable $e) { error_log('form notif: ' . $e->getMessage()); }
}

/* ---------- throttle per IP ---------- */
function formBolehKirim(string $ip): bool
{
    try {
        q("DELETE FROM form_throttle WHERE terakhir < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $r = one("SELECT jumlah FROM form_throttle
                  WHERE ip = ? AND terakhir > DATE_SUB(NOW(), INTERVAL 1 HOUR)", [$ip]);
        return !$r || (int) $r['jumlah'] < 10;
    } catch (Throwable $e) {
        return true;   // tabel belum ada — jangan halangi calon klien
    }
}

function formCatatKirim(string $ip): void
{
    try {
        q("INSERT INTO form_throttle (ip, jumlah) VALUES (?, 1)
           ON DUPLICATE KEY UPDATE
             jumlah = IF(terakhir < DATE_SUB(NOW(), INTERVAL 1 HOUR), 1, jumlah + 1),
             -- Ditulis eksplisit: kalau jumlah direset 1 → 1, nilainya tidak
             -- berubah dan ON UPDATE CURRENT_TIMESTAMP tidak ikut jalan.
             terakhir = NOW()", [$ip]);
    } catch (Throwable $e) { /* pencatatan gagal bukan alasan menolak */ }
}

/**
 * Kolom untuk daftar. Isi kiriman (payload) ikut hanya kalau ukurannya wajar —
 * daftar ini dimuat di banyak halaman panel, jadi satu baris raksasa tidak
 * boleh bisa menjatuhkan semuanya.
 */
const FORM_KOLOM_DAFTAR = "f.id, f.created_at, f.ip, f.status, f.client_id, f.nama, f.wa, f.paket, f.pesan, f.ditangani,
                           IF(CHAR_LENGTH(f.payload) > 20000, NULL, f.payload) AS payload";

/** Jumlah kiriman yang perlu dicek — untuk lencana menu. */
function formPerluCekJumlah(int $hari = 30): int
{
    // Dihitung sekali per permintaan: lencana menu dan Ringkasan sama-sama memakainya.
    static $memo = [];
    if (isset($memo[$hari])) return $memo[$hari];
    try {
        return $memo[$hari] = (int) (one("SELECT COUNT(*) n FROM form_masuk f
                    WHERE f.status IN ('galat','ditolak') AND f.client_id IS NULL
                      AND f.ditangani = 0 AND CHAR_LENGTH(f.wa) >= 10
                      AND f.created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                      AND NOT EXISTS (SELECT 1 FROM form_masuk g
                                      WHERE g.wa = f.wa AND g.client_id IS NOT NULL AND g.id > f.id)", [$hari])['n'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** Kiriman yang belum jadi klien dan belum ditangani — untuk dashboard. */
function formPerluCek(int $hari = 30): array
{
    try {
        return all("SELECT " . FORM_KOLOM_DAFTAR . " FROM form_masuk f
                    WHERE f.status IN ('galat','ditolak') AND f.client_id IS NULL
                      AND f.ditangani = 0 AND CHAR_LENGTH(f.wa) >= 10
                      AND f.created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                      AND NOT EXISTS (SELECT 1 FROM form_masuk g
                                      WHERE g.wa = f.wa AND g.client_id IS NOT NULL AND g.id > f.id)
                    ORDER BY f.id DESC LIMIT 50", [$hari]);
    } catch (Throwable $e) {
        return [];
    }
}
