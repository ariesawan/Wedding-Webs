<?php
/**
 * ============================================================
 * DASHBOARD PENGANTIN (portal klien)
 * ============================================================
 *
 * Halaman pribadi untuk pasangan yang sudah DP: /p/{token}.
 *
 * Akses = token 128-bit di URL (dikirim lewat WhatsApp), seperti tautan
 * price list. Tanpa kata sandi dan tanpa OTP — orang tua harus bisa membuka
 * dan mengisi data keluarga tanpa HP pengantin. Penjagaannya:
 *   - token bisa diputar / dimatikan admin, mati otomatis saat klien batal,
 *     dan semua portal bisa dimatikan sekaligus dari Pengaturan;
 *   - SATU pembaca data (portalData) dengan daftar putih kolom — catatan
 *     internal, harga vendor, tautan host Zoom tidak pernah dibaca;
 *   - formulir hanya mengubah data keluarga, prosesi, dan referensi dekor;
 *     tanggal, tamu, lokasi, email/telepon klien hanya-baca;
 *   - cek versi di dua sisi (portal & panel) supaya tidak saling menimpa,
 *     log perubahan dengan nilai lama, batas simpan per hari, kunci H-30.
 */
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/paket.php';
require_once __DIR__ . '/bayar.php';

const PORTAL_ADAT = ['' => '— tidak ada / belum ditentukan —', 'jawa' => 'Jawa', 'chinese' => 'Chinese',
                     'batak' => 'Batak', 'lainnya' => 'Suku lainnya'];

/* ============================================================
   TOKEN
   ============================================================ */

/** Token klien; dibuat sekali (aman dipanggil berulang). */
function portalToken(int $clientId): string
{
    $baru = bin2hex(random_bytes(16));
    q("UPDATE clients SET portal_token = ? WHERE id = ? AND portal_token IS NULL", [$baru, $clientId]);
    return (string) (one("SELECT portal_token FROM clients WHERE id = ?", [$clientId])['portal_token'] ?? '');
}

function portalPutar(int $clientId): string
{
    $baru = bin2hex(random_bytes(16));
    q("UPDATE clients SET portal_token = ? WHERE id = ?", [$baru, $clientId]);
    return $baru;
}

function portalMatikan(int $clientId): void
{
    q("UPDATE clients SET portal_token = NULL WHERE id = ?", [$clientId]);
}

/** URL selalu dari BASE_URL — host form.* diarahkan .htaccess ke formulir. */
function portalUrl(string $token): string
{
    return url('p/' . $token);
}

/** URL dashboard klien yang sudah deal (membuat token bila belum ada). */
function portalUrlKlien(int $clientId): string
{
    $c = one("SELECT stage FROM clients WHERE id = ?", [$clientId]);
    if (!$c || !stageSudahDeal($c['stage']) || setting('portal_aktif', '1') === '0') return '';
    $t = portalToken($clientId);
    return $t !== '' ? portalUrl($t) : '';
}

/** Klien dari token; hanya kolom yang aman dibaca. */
function portalKlien(string $t): ?array
{
    if (strlen($t) !== 32 || !ctype_xdigit($t)) return null;
    try {
        return one("SELECT id, name, partner_name, stage, wedding_date, wedding_time, venue, city,
                           portal_seen_at, portal_isi_at
                    FROM clients WHERE portal_token = ?", [strtolower($t)]);
    } catch (Throwable $e) {
        return null;   // kolom belum ada (hosting tanpa hak ALTER)
    }
}

/**
 * 'mati'   → halaman "tautan tidak aktif" (404)
 * 'praDeal'→ halaman netral "aktif setelah DP"
 * 'aktif'  → lengkap + formulir (kecuali terkunci)
 * 'baca'   → lengkap, hanya-baca (hari-H / selesai)
 */
function portalStatus(?array $c): string
{
    if (!$c || setting('portal_aktif', '1') === '0' || $c['stage'] === 'batal') return 'mati';
    if (!stageSudahDeal($c['stage'])) return 'praDeal';
    if ($c['stage'] === 'selesai') {
        // Tutup otomatis 60 hari setelah acara bila sudah lunas.
        $sisa = (float) (one("SELECT COALESCE(SUM(amount - terbayar),0) v FROM payments WHERE client_id = ? AND paid_at IS NULL", [$c['id']])['v'] ?? 0);
        if ($c['wedding_date'] && hariKe($c['wedding_date']) < -60 && $sisa <= 0.5) return 'mati';
        return 'baca';
    }
    return $c['stage'] === 'harih' ? 'baca' : 'aktif';
}

/** Formulir dikunci untuk cetak undangan & naskah MC. */
function portalKunci(array $c): bool
{
    if (in_array($c['stage'], ['harih', 'selesai'], true)) return true;
    $h = (int) setting('portal_kunci_hari', '30');
    return $h > 0 && $c['wedding_date'] && hariKe($c['wedding_date']) !== null && hariKe($c['wedding_date']) <= $h;
}

function portalBot(): bool
{
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    return $ua === '' || (bool) preg_match('/bot|crawl|spider|preview|curl|wget|fonnte|whatsapp|facebookexternalhit|telegram|slack|discord|python|go-http|java\//', $ua);
}

/* ============================================================
   DATA — satu pembaca, daftar putih kolom
   ============================================================ */
const PORTAL_KOLOM_INFO = ['akad_tanggal', 'akad_jam', 'akad_lokasi', 'resepsi_tanggal', 'resepsi_jam', 'resepsi_lokasi',
                           'prosesi_adat', 'prosesi_adat_lainnya', 'prosesi_adat_detail',
                           'pria_anak_ke', 'pria_dari', 'pria_alamat', 'wanita_anak_ke', 'wanita_dari', 'wanita_alamat',
                           'tamu_akad', 'tamu_resepsi', 'dekor_klien', 'pria_nama', 'wanita_nama'];

function portalInfo(int $clientId): array
{
    $kol = implode(', ', array_map(fn($k) => "`$k`", PORTAL_KOLOM_INFO));
    try { $wi = one("SELECT $kol FROM client_wedding_info WHERE client_id = ?", [$clientId]); }
    catch (Throwable $e) { $wi = null; }
    return $wi ?: array_fill_keys(PORTAL_KOLOM_INFO, null);
}

/** [pihak_peran => [nama, nama_undangan, status, telepon]] */
function portalKeluarga(int $clientId): array
{
    $out = [];
    foreach (all("SELECT pihak, peran, nama, nama_undangan, status, telepon FROM client_family WHERE client_id = ?", [$clientId]) as $f) {
        $out[$f['pihak'] . '_' . $f['peran']] = $f;
    }
    return $out;
}

function portalMeeting(int $clientId): array
{
    // Hanya meeting yang memang untuk klien ini (kontaknya cocok): meeting
    // teknis dengan vendor yang kebetulan ditautkan ke klien tidak ikut.
    $c = one("SELECT email, phone FROM clients WHERE id = ?", [$clientId]);
    $email = strtolower(trim((string) ($c['email'] ?? '')));
    $hp = waNomor((string) ($c['phone'] ?? ''));
    $out = [];
    foreach (all("SELECT id, title, start_at, end_at, mode, location_text, notes, zoom_join_url, zoom_passcode, meet_url,
                         client_email, client_phone
                  FROM meetings WHERE client_id = ? AND status = 'scheduled' AND end_at >= NOW()
                  ORDER BY start_at LIMIT 5", [$clientId]) as $m) {
        $cocok = ($email !== '' && strtolower(trim((string) $m['client_email'])) === $email)
              || ($hp !== '' && waNomor((string) $m['client_phone']) === $hp);
        if (!$cocok) continue;
        unset($m['client_email'], $m['client_phone']);
        $out[] = $m;
    }
    return $out;
}

function portalVendor(int $clientId): array
{
    try {
        return all("SELECT v.name, v.category FROM client_vendors cv JOIN vendors v ON v.id = cv.vendor_id
                    WHERE cv.client_id = ? AND cv.status = 'deal' ORDER BY v.category, v.name", [$clientId]);
    } catch (Throwable $e) {
        return [];
    }
}

/** Semua price list / penawaran yang disetujui (utama + tambahan). */
function portalDokumen(int $clientId): array
{
    return all("SELECT id, nomor, paket_nama, token, decided_at, jenis FROM quotes
                WHERE client_id = ? AND status = 'cocok' ORDER BY decided_at, id", [$clientId]);
}

/** Sidik jari bagian formulir — dibandingkan sebelum menulis. */
function portalVersi(int $clientId, string $bagian): string
{
    $wi = portalInfo($clientId);
    $isi = match ($bagian) {
        'keluarga' => [portalKeluarga($clientId), array_intersect_key($wi, array_flip(
                        ['pria_anak_ke', 'pria_dari', 'pria_alamat', 'wanita_anak_ke', 'wanita_dari', 'wanita_alamat', 'pria_nama', 'wanita_nama']))],
        'prosesi'  => array_intersect_key($wi, array_flip(['prosesi_adat', 'prosesi_adat_lainnya', 'prosesi_adat_detail'])),
        'dekor'    => [$wi['dekor_klien'] ?? null],
        default    => [],
    };
    return sha1(json_encode($isi));
}

/* ============================================================
   "YANG PERLU KALIAN LAKUKAN"
   ============================================================ */
function portalLangkah(array $c, array $rk, array $kel, array $wi, array $meet, bool $kunci): array
{
    $out = [];
    $b = $rk['berikutnya'];
    if ($b && $b['due_date'] && hariKe($b['due_date']) !== null && hariKe($b['due_date']) <= 14) {
        $out[] = ['#bayar', 'Transfer ' . $b['label'] . ' ' . rupiah($b['sisa']) . ' sebelum ' . tanggalID($b['due_date'])];
    }
    if (!$kunci) {
        $kurang = false;
        foreach (['pria', 'wanita'] as $p) {
            $ada = array_filter(['ayah', 'ibu', 'wali'], fn($r) => isset($kel[$p . '_' . $r]));
            if (!$ada) $kurang = true;
            foreach ($ada as $r) if (trim((string) $kel[$p . '_' . $r]['nama_undangan']) === '') $kurang = true;
        }
        if ($kurang) $out[] = ['#keluarga', 'Lengkapi data orang tua (nama untuk undangan)'];
        if (trim((string) ($wi['prosesi_adat_detail'] ?? '')) === '' && ($wi['prosesi_adat'] ?? '') !== '') {
            $out[] = ['#prosesi', 'Tuliskan urutan prosesi adat dari keluarga'];
        }
        if (trim((string) ($wi['dekor_klien'] ?? '')) === '') $out[] = ['#dekor', 'Kirim referensi dekor impian kalian'];
    }
    foreach ($meet as $m) {
        if (strtotime($m['start_at']) - time() <= 7 * 86400) {
            $out[] = ['#meeting', 'Meeting ' . hariID(substr($m['start_at'], 0, 10)) . ', ' . tanggalID(substr($m['start_at'], 0, 10)) . ' pukul ' . date('H.i', strtotime($m['start_at']))];
            break;
        }
    }
    return array_slice($out, 0, 4);
}

/* ============================================================
   SIMPAN DARI PORTAL
   ============================================================ */

/** Batas 30 simpan per klien per hari. */
function portalBolehSimpan(int $clientId): bool
{
    $n = (int) (one("SELECT COUNT(*) n FROM client_activities WHERE client_id = ? AND user_id IS NULL
                     AND title LIKE 'Dashboard:%' AND created_at >= CURDATE()", [$clientId])['n'] ?? 0);
    return $n < 30;
}

function portalTeks(?string $v, int $max): string
{
    return mb_substr(trim(str_replace("\r", '', (string) $v)), 0, $max);
}

/**
 * Simpan bagian formulir. Mengembalikan '' bila berhasil, atau pesan galat.
 * $bagian: keluarga | prosesi | dekor
 */
function portalSimpan(array $c, string $bagian, array $post): string
{
    $cid = (int) $c['id'];
    if (portalStatus($c) !== 'aktif' || portalKunci($c)) return 'Data sudah dikunci. Hubungi PIC kalian untuk perubahan.';
    if (!portalBolehSimpan($cid)) return 'Sudah terlalu sering disimpan hari ini. Coba lagi besok atau hubungi PIC.';
    if (!hash_equals(portalVersi($cid, $bagian), (string) ($post['v'] ?? ''))) {
        return 'Data ini baru saja diperbarui tim kami. Periksa lagi isiannya, lalu simpan.';
    }

    $diff = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q("INSERT IGNORE INTO client_wedding_info (client_id) VALUES (?)", [$cid]);
        $wi = portalInfo($cid);
        $catat = function (string $label, $lama, $baru) use (&$diff) {
            if ((string) $lama !== (string) $baru) $diff[] = $label . ': ' . ($lama === null || $lama === '' ? '—' : "'" . mb_strimwidth((string) $lama, 0, 60, '…') . "'")
                . ' → ' . ($baru === null || $baru === '' ? '—' : "'" . mb_strimwidth((string) $baru, 0, 60, '…') . "'");
        };

        if ($bagian === 'keluarga') {
            $angka = fn($v) => ((int) $v >= 1 && (int) $v <= 20) ? (int) $v : null;
            $set = [];
            foreach (['pria', 'wanita'] as $p) {
                $baru = [
                    "{$p}_nama"   => portalTeks($post["{$p}_nama"] ?? '', 190),
                    "{$p}_anak_ke" => $angka($post["{$p}_anak_ke"] ?? 0),
                    "{$p}_dari"    => $angka($post["{$p}_dari"] ?? 0),
                ];
                // Alamat tersamar di layar: kosong = tidak berubah.
                $al = portalTeks($post["{$p}_alamat"] ?? '', 255);
                if ($al !== '') $baru["{$p}_alamat"] = $al;
                foreach ($baru as $k => $v) { $catat($k, $wi[$k] ?? null, $v); $set[$k] = $v; }
            }
            $kol = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($set)));
            q("UPDATE client_wedding_info SET $kol WHERE client_id = ?", [...array_values($set), $cid]);

            $lamaKel = portalKeluarga($cid);
            foreach (['pria', 'wanita'] as $p) {
                foreach (['ayah', 'ibu', 'wali'] as $r) {
                    $k = "{$p}_{$r}";
                    $nama = portalTeks($post["{$k}_nama"] ?? '', 120);
                    $lama = $lamaKel[$k] ?? null;
                    if ($nama === '') {
                        if ($lama) {
                            q("DELETE FROM client_family WHERE client_id = ? AND pihak = ? AND peran = ?", [$cid, $p, $r]);
                            $diff[] = ucfirst($r) . " ($p) dihapus: '" . $lama['nama'] . "'";
                        }
                        continue;
                    }
                    $und = portalTeks($post["{$k}_undangan"] ?? '', 190);
                    $st  = ($post["{$k}_status"] ?? '') === 'almarhum' ? 'almarhum' : 'hidup';
                    $tel = preg_replace('/[^\d+]/', '', (string) ($post["{$k}_telepon"] ?? ''));
                    $tel = mb_substr($tel, 0, 30);
                    if ($tel === '' && $lama) $tel = (string) $lama['telepon'];   // tersamar: kosong = tetap
                    q("INSERT INTO client_family (client_id, pihak, peran, nama, nama_undangan, status, telepon, catatan, urutan)
                       VALUES (?,?,?,?,?,?,?, '', ?)
                       ON DUPLICATE KEY UPDATE nama = VALUES(nama), nama_undangan = VALUES(nama_undangan),
                                               status = VALUES(status), telepon = VALUES(telepon)",
                      [$cid, $p, $r, $nama, $und, $st, $tel, ['ayah' => 1, 'ibu' => 2, 'wali' => 3][$r]]);
                    $label = ucfirst($r) . " ($p)";
                    $catat($label, $lama['nama'] ?? null, $nama);
                    $catat($label . ' undangan', $lama['nama_undangan'] ?? null, $und);
                    $catat($label . ' status', $lama['status'] ?? null, $st);
                    if (($lama['telepon'] ?? '') !== $tel) $diff[] = $label . ' telepon diubah';
                }
            }
        } elseif ($bagian === 'prosesi') {
            $adat = array_key_exists((string) ($post['prosesi_adat'] ?? ''), PORTAL_ADAT) ? (string) $post['prosesi_adat'] : '';
            $set = ['prosesi_adat' => $adat, 'prosesi_adat_lainnya' => portalTeks($post['prosesi_adat_lainnya'] ?? '', 120),
                    'prosesi_adat_detail' => portalTeks($post['prosesi_adat_detail'] ?? '', 4000)];
            foreach ($set as $k => $v) $catat($k, $wi[$k] ?? null, $v);
            q("UPDATE client_wedding_info SET prosesi_adat = ?, prosesi_adat_lainnya = ?, prosesi_adat_detail = ? WHERE client_id = ?",
              [...array_values($set), $cid]);
        } elseif ($bagian === 'dekor') {
            $v = portalTeks($post['dekor_klien'] ?? '', 4000);
            $catat('Referensi dekor', $wi['dekor_klien'] ?? null, $v);
            q("UPDATE client_wedding_info SET dekor_klien = ? WHERE client_id = ?", [$v, $cid]);
        } else {
            throw new RuntimeException('Bagian tidak dikenal.');
        }

        $sebelumnya = $c['portal_isi_at'];
        if ($diff) {
            q("UPDATE clients SET portal_isi_at = NOW() WHERE id = ?", [$cid]);
            clientLog($cid, 'catatan', 'Dashboard: ' . ['keluarga' => 'data keluarga', 'prosesi' => 'prosesi adat', 'dekor' => 'referensi dekor'][$bagian]
                      . ' diperbarui klien', implode("\n", $diff), null);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('portal simpan: ' . $e->getMessage());
        return 'Ada gangguan saat menyimpan. Coba lagi sebentar.';
    }

    // Kabari admin office — paling sering sekali tiap 2 jam.
    if ($diff && (!$sebelumnya || strtotime($sebelumnya) < time() - 7200)) {
        try {
            $tujuan = (string) setting('wa_admin_office', '');
            if ($tujuan !== '' && waSiap()) {
                waKirim($tujuan, $c['name'] . ($c['partner_name'] ? ' & ' . $c['partner_name'] : '') . ' memperbarui data lewat dashboard pengantin. Cek: '
                    . url('admin/klien.php?id=' . $cid) . '#datalengkap');
            }
        } catch (Throwable $e) { /* notifikasi bukan alasan gagal */ }
    }
    return '';
}

/* ============================================================
   KIRIM TAUTAN
   ============================================================ */
function portalTeksWA(int $clientId): string
{
    $c = one("SELECT name, stage FROM clients WHERE id = ?", [$clientId]);
    $url = portalUrl(portalToken($clientId));
    return 'Halo ' . $c['name'] . ', ini dashboard pengantin kalian: ' . $url
         . "\n\nJadwal & kwitansi pembayaran, jadwal meeting, dan formulir data keluarga ada di sana."
         . "\n\nTautan ini pribadi: boleh diteruskan ke orang tua untuk mengisi data keluarga, tapi mohon jangan dibagikan di grup ya."
         . "\n\nPIC kalian: " . waTampil(waNomorPic((string) $c['stage']));
}

function portalKirim(int $clientId, ?int $userId): array
{
    $c = one("SELECT id, phone, stage FROM clients WHERE id = ?", [$clientId]);
    if (!$c || !stageSudahDeal($c['stage'])) return ['ok' => false, 'error' => 'Dashboard aktif setelah DP lunas.'];
    $teks = portalTeksWA($clientId);
    $waUrl = $c['phone'] ? 'https://wa.me/' . waNomor($c['phone']) . '?text=' . rawurlencode($teks) : '';
    if (!waSiap() || !$c['phone']) return ['ok' => false, 'wa_url' => $waUrl, 'error' => 'Gateway WhatsApp belum tersambung.'];
    require_once __DIR__ . '/chat.php';
    $room = chatSinkronKlien($clientId);
    if (!$room) return ['ok' => false, 'wa_url' => $waUrl, 'error' => 'Nomor WhatsApp klien tidak valid.'];
    $r = chatKirim($room, $teks, $userId, ['client_id' => $clientId]);
    if (!empty($r['ok'])) clientLog($clientId, 'sistem', 'Tautan dashboard pengantin dikirim', '', $userId);
    return $r + ['wa_url' => $waUrl];
}

/** "•••• 7320" — data pribadi yang sudah tersimpan tidak ditampilkan utuh. */
function portalSamar(?string $v, int $sisa = 4): string
{
    $v = trim((string) $v);
    if ($v === '') return '';
    return '•••• ' . mb_substr($v, -$sisa);
}
