<?php
/**
 * ============================================================
 * VENDOR & PERCAKAPAN PER PESTA
 * ============================================================
 *
 * Catatan penting soal WhatsApp:
 *
 * Panel ini TIDAK mengirim pesan sendiri ke WhatsApp. Pengiriman otomatis
 * dari server hanya mungkin lewat WhatsApp Business API (Meta Cloud API) atau
 * gateway pihak ketiga — keduanya butuh verifikasi bisnis, templat pesan yang
 * disetujui lebih dulu, dan biaya per percakapan.
 *
 * Yang dilakukan di sini: menyiapkan pesan lengkap dengan konteks pesta,
 * membuka WhatsApp lewat tautan wa.me, dan MENCATAT apa yang dikirim ke
 * riwayat pasangan (klien, vendor). Jadi obrolan soal pesta A tetap terpisah
 * dari pesta B walaupun vendornya sama — yang selama ini tidak terjadi kalau
 * semua percakapan menumpuk di satu utas WhatsApp.
 *
 * Balasan vendor dicatat manual (tempel isinya). Untuk sinkronisasi dua arah
 * yang sungguhan, lihat catatan di README bagian WhatsApp Business API.
 */

const VENDOR_KATEGORI = [
    'dekorasi'   => 'Dekorasi',
    'katering'   => 'Katering',
    'dokumentasi'=> 'Dokumentasi',
    'rias'       => 'Rias & busana',
    'mc'         => 'MC',
    'hiburan'    => 'Hiburan & musik',
    'venue'      => 'Venue',
    'undangan'   => 'Undangan & souvenir',
    'transport'  => 'Transportasi',
    'lainnya'    => 'Lainnya',
];

const VENDOR_STATUS = [
    'dipertimbangkan' => ['Dipertimbangkan', 'draft'],
    'dihubungi'       => ['Sudah dihubungi', 'warn'],
    'nego'            => ['Negosiasi',       'warn'],
    'deal'            => ['Deal',            'live'],
    'batal'           => ['Tidak dipakai',   'bad'],
];

function katVendor(string $k): string { return VENDOR_KATEGORI[$k] ?? ucfirst($k); }

function statusVendor(string $s): array
{
    return VENDOR_STATUS[$s] ?? [ucfirst($s), 'draft'];
}

/** Nomor WhatsApp dalam format internasional tanpa tanda plus. */
function waNomor(?string $telp): string
{
    $n = preg_replace('/\D/', '', (string) $telp);
    if ($n === '') return '';
    if (str_starts_with($n, '0'))  $n = '62' . substr($n, 1);
    if (str_starts_with($n, '620')) $n = '62' . substr($n, 3);
    return $n;
}

/** Vendor yang dipakai satu pesta, dikelompokkan per kategori. */
function vendorKlien(int $clientId): array
{
    $rows = all("SELECT cv.*, v.name, v.category, v.phone, v.email, v.instagram, v.city, v.pic_name, v.price_note
                 FROM client_vendors cv JOIN vendors v ON v.id = cv.vendor_id
                 WHERE cv.client_id = ?
                 ORDER BY FIELD(cv.status,'deal','nego','dihubungi','dipertimbangkan','batal'), v.category, v.name",
                [$clientId]);
    $out = [];
    foreach ($rows as $r) $out[$r['category']][] = $r;
    return $out;
}

/** Riwayat percakapan satu pasangan klien–vendor. */
function pesanVendor(int $clientId, int $vendorId, int $limit = 200): array
{
    return all("SELECT m.*, u.name AS oleh FROM vendor_messages m
                LEFT JOIN users u ON u.id = m.user_id
                WHERE m.client_id = ? AND m.vendor_id = ?
                ORDER BY m.created_at ASC LIMIT $limit", [$clientId, $vendorId]);
}

function catatPesan(int $clientId, int $vendorId, string $arah, string $isi, string $kanal = 'wa', ?int $userId = null): int
{
    q("INSERT INTO vendor_messages (client_id, vendor_id, direction, channel, body, user_id)
       VALUES (?,?,?,?,?,?)", [$clientId, $vendorId, $arah, $kanal, $isi, $userId]);
    return insertId();
}

/**
 * Susun pesan pembuka yang sudah berisi konteks pesta, supaya vendor langsung
 * tahu ini soal acara yang mana tanpa harus ditanya balik.
 */
function pesanPembuka(array $klien, array $cv): string
{
    $nama = trim($klien['name'] . ($klien['partner_name'] ? ' & ' . $klien['partner_name'] : ''));
    $b = [];
    $b[] = 'Halo' . ($cv['pic_name'] ? ' ' . $cv['pic_name'] : '') . ', saya dari '
         . setting('site_name', 'Callalily Party') . '.';
    $b[] = '';
    $b[] = 'Mau menanyakan ketersediaan untuk acara:';
    $b[] = '• Pasangan : ' . $nama;
    if ($klien['wedding_date']) {
        $b[] = '• Tanggal  : ' . hariID($klien['wedding_date']) . ', ' . tanggalID($klien['wedding_date']);
    }
    if ($klien['venue'] || $klien['city']) {
        $b[] = '• Lokasi   : ' . trim(($klien['venue'] ? $klien['venue'] . ', ' : '') . $klien['city']);
    }
    if ($klien['guest_estimate']) {
        $b[] = '• Tamu     : ± ' . number_format((int) $klien['guest_estimate'], 0, ',', '.') . ' orang';
    }
    $b[] = '• Kebutuhan: ' . katVendor($cv['category']);
    $b[] = '';
    $b[] = 'Apakah tanggal tersebut masih tersedia? Terima kasih.';
    return implode("\n", $b);
}

/** Tautan wa.me lengkap dengan pesan yang sudah terisi. */
function waTautan(string $telp, string $pesan = ''): string
{
    $n = waNomor($telp);
    if ($n === '') return '';
    return 'https://wa.me/' . $n . ($pesan !== '' ? '?text=' . rawurlencode($pesan) : '');
}

/** Ringkasan biaya vendor satu pesta. */
function biayaVendor(int $clientId): array
{
    $r = one("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN status='deal' THEN price ELSE 0 END),0) deal,
                     SUM(status='deal') n_deal
              FROM client_vendors WHERE client_id = ?", [$clientId]);
    return ['jumlah' => (int) ($r['n'] ?? 0), 'deal' => (float) ($r['deal'] ?? 0), 'n_deal' => (int) ($r['n_deal'] ?? 0)];
}
