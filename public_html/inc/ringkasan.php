<?php
/**
 * ============================================================
 * RINGKASAN — logika dashboard admin per peran (tanpa HTML)
 * ============================================================
 *
 * Admin early dan admin office mendapat ANTREAN: satu daftar berurutan
 * "kerjakan dari atas" yang menggabungkan semua hal yang bisa tergelincir —
 * formulir gagal, konsultasi tanpa hasil, DP jatuh tempo, price list yang
 * belum dijawab, termin, checklist, hari-H yang mendekat. Satu klien = satu
 * baris; alasan lain jadi chip kecil. Setiap baris memberi 1–2 tombol yang
 * memanggil handler yang SUDAH ADA (klien.php, jadwal.php, penawaran.php,
 * formulir.php) — tidak ada jalan pintas baru untuk mengubah data.
 *
 * Owner mendapat gambaran bisnis: angka bulan ini, keputusan yang menunggu,
 * antrean tim, minggu ini, arus kas, tanggal terisi, dan corong.
 *
 * Aturan uang (v24): sisa = amount − terbayar; uang diterima = jumlah
 * payment_receipts berstatus 'sah' per tanggal terima. Piutang hanya dari
 * klien yang sudah DP (deal/persiapan/harih/selesai) — DP yang masih
 * ditunggu ditampilkan terpisah, bukan dicampur ke piutang.
 *
 * Tanpa window function / CTE: schema.sql menjanjikan MySQL 5.7+.
 */
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/bayar.php';
require_once __DIR__ . '/paket.php';
require_once __DIR__ . '/vendor.php';
require_once __DIR__ . '/formulir.php';
require_once __DIR__ . '/wa.php';

/** Baris antrean yang langsung terlihat; sisanya di balik "Tampilkan n lainnya". */
const RINGKASAN_MAKS = 25;

const HARI_PENDEK  = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
const BULAN_PENDEK = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

/* ============================================================
   TEKS BANTU
   ============================================================ */

/** "Reza & Intan" */
function namaPasangan(array $r): string
{
    $a = trim((string) ($r['name'] ?? $r['nama'] ?? ''));
    $b = trim((string) ($r['partner_name'] ?? ''));
    return $b !== '' ? $a . ' & ' . $b : ($a !== '' ? $a : 'Tanpa nama');
}

/** Daftar tahap untuk IN (...). Hanya dari konstanta — tidak pernah input pengguna. */
function sqlTahap(array $tahap): string
{
    return "'" . implode("','", array_map(fn($t) => preg_replace('/[^a-z]/', '', $t), $tahap)) . "'";
}

/** "Sab 10 Okt" — atau "10 Okt 2027" bila beda tahun. */
function tglPendek(?string $d, bool $hari = true): string
{
    if (!$d) return '—';
    $t = strtotime(substr($d, 0, 10));
    if ($t === false) return '—';
    $s = (int) date('j', $t) . ' ' . BULAN_PENDEK[(int) date('n', $t)];
    if (date('Y', $t) !== date('Y')) $s .= ' ' . date('Y', $t);
    return ($hari ? HARI_PENDEK[(int) date('w', $t)] . ' ' : '') . $s;
}

/** "10.00" */
function jamTeks(?string $dt): string
{
    return $dt ? date('H.i', strtotime($dt)) : '';
}

/**
 * Tenggat dibaca manusia: 'hari ini' / 'besok' / '3 hari lagi' / 'Kam 8 Okt'
 * (lebih dari 6 hari) / 'lewat 3 hari'. Untuk tagihan, langkah, tindak
 * lanjut. Hari-H tetap memakai labelHari() (H-n).
 */
function labelTempo(?string $date): string
{
    $d = hariKe($date);
    if ($d === null) return '—';
    if ($d === 0) return 'hari ini';
    if ($d === 1) return 'besok';
    if ($d > 1 && $d <= 6) return $d . ' hari lagi';
    if ($d > 6) return tglPendek($date);
    return 'lewat ' . abs($d) . ' hari';
}

/** Kejadian yang sudah lewat: 'hari ini' / 'kemarin' / '3 hari lalu' / '2 Sep'. */
function labelLalu(?string $dt): string
{
    $d = hariKe($dt);
    if ($d === null) return '—';
    if ($d >= 0) return 'hari ini';
    if ($d === -1) return 'kemarin';
    if ($d >= -30) return abs($d) . ' hari lalu';
    return tglPendek($dt, false);
}

/** '45 menit' / '19 jam' / '2 hari' */
function durasiTeks(int $menit): string
{
    if ($menit < 60) return max(1, $menit) . ' menit';
    if ($menit < 48 * 60) return (int) round($menit / 60) . ' jam';
    return (int) floor($menit / 1440) . ' hari';
}

function median(array $v): ?float
{
    $v = array_values(array_filter($v, fn($x) => $x !== null && $x !== ''));
    if (!$v) return null;
    sort($v, SORT_NUMERIC);
    $n = count($v);
    return $n % 2 ? (float) $v[intdiv($n, 2)] : ((float) $v[$n / 2 - 1] + (float) $v[$n / 2]) / 2;
}

/** '29%' bila penyebut cukup besar, selain itu '1 dari 4' — angka kecil jujur sebagai hitungan. */
function persenAtau(int $a, int $b, int $min = 10): string
{
    if ($b <= 0) return '—';
    return $b >= $min ? round($a / $b * 100) . '%' : $a . ' dari ' . $b;
}

/** "1–6 Sep": hari yang sama di bulan lalu (pembanding yang adil di awal bulan). */
function labelPeriodeLalu(): string
{
    $awal = strtotime('first day of last month');
    $hariIni = (int) date('j');
    $akhir = min($hariIni, (int) date('t', $awal));
    $bln = BULAN_PENDEK[(int) date('n', $awal)];
    return $akhir === 1 ? '1 ' . $bln : '1–' . $akhir . ' ' . $bln;
}

/**
 * Selisih dengan periode pembanding. Selalu dengan glyph ▲▼= dan kata;
 * persen hanya bila dasar ≥ 10. null bila keduanya nol.
 * @return array{teks:string,kelas:string}|null
 */
function deltaTeks(float $ini, float $lalu, bool $naikBaik = true, bool $rp = false): ?array
{
    if ($ini == 0.0 && $lalu == 0.0) return null;
    $sel = $ini - $lalu;
    $per = labelPeriodeLalu();
    if (abs($sel) < 0.5) return ['teks' => '= sama dengan ' . $per, 'kelas' => 'sama'];
    $angka = $rp ? rupiah(abs($sel), true) : (string) (int) round(abs($sel));
    $pct = $lalu >= 10 && !$rp ? ' (' . ($sel > 0 ? '+' : '−') . round(abs($sel) / $lalu * 100) . '%)' : '';
    $naik = $sel > 0;
    return ['teks' => ($naik ? '▲ ' : '▼ ') . $angka . $pct . ' vs ' . $per,
            'kelas' => $naik === $naikBaik ? 'baik' : 'buruk'];
}

/** Nomor WA admin tim untuk "Tanya admin" — kosong bila belum diisi (jangan jatuh ke nomor publik). */
function waAdminTim(string $stage): string
{
    $k = stageSudahDeal($stage) ? 'wa_admin_office' : 'wa_admin_biasa';
    return waNomor((string) setting($k, ''));
}

function modeTemu(string $m): string
{
    return ['meet' => 'Google Meet', 'zoom' => 'Zoom', 'onsite' => 'Tatap muka', 'phone' => 'Telepon'][$m] ?? 'Pertemuan';
}

/* ============================================================
   TEKS WHATSAPP (tautan wa.me — dikirim manusia, bukan otomatis)
   ============================================================ */

function teksSapaProspek(array $c): string
{
    $paket = trim((string) ($c['paket_minat_nama'] ?? $c['q_paket'] ?? ''));
    $tgl = !empty($c['wedding_date']) && $c['wedding_date'] >= date('Y-m-d') ? ' untuk tanggal ' . tanggalID($c['wedding_date']) : '';
    return 'Halo ' . ($c['name'] ?? '') . ', kami dari Callalily Party. Terima kasih sudah menghubungi kami'
         . $tgl . '. Boleh kami kirimkan price list' . ($paket !== '' ? ' paket ' . $paket : '') . ' lewat WhatsApp ini?';
}

function teksFollowUpPL(array $c, array $q): string
{
    $paket = trim((string) ($q['q_paket'] ?? $q['paket_nama'] ?? ''));
    $tok = (string) ($q['q_token'] ?? $q['token'] ?? '');
    $kirim = (string) ($q['q_sent_at'] ?? $q['sent_at'] ?? '');
    $d = $kirim !== '' ? hariKe($kirim) : null;
    $kapanKirim = $d === null ? '' : ($d === 0 ? ' hari ini' : ($d === -1 ? ' kemarin' : ' pada ' . tanggalID(substr($kirim, 0, 10))));
    return 'Halo ' . ($c['name'] ?? '') . ', kami ingin menanyakan price list' . ($paket !== '' ? ' ' . $paket : '')
         . ' yang kami kirim' . $kapanKirim . '.' . ($tok !== '' ? ' Bisa dibuka lagi di sini: ' . url('penawaran.php?t=' . $tok) : '')
         . "\n\nAda yang ingin ditanyakan atau disesuaikan? Kami bantu.";
}

function teksBalasFormulir(array $f): string
{
    return 'Halo ' . trim((string) ($f['nama'] ?? '')) . ', terima kasih sudah mengisi formulir di situs Callalily Party.'
         . ' Datanya sudah kami terima. Boleh kami lanjutkan lewat WhatsApp ini untuk mengirim price list'
         . (trim((string) ($f['paket'] ?? '')) !== '' ? ' paket ' . $f['paket'] : '') . '?';
}

/** Pengingat pertemuan. Tautan HOST Zoom (zoom_start_url) tidak pernah ikut. */
function teksPengingatTemu(array $m): string
{
    $nama = trim((string) ($m['client_name'] ?? ''));
    $kapan = hariID($m['start_at']) . ', ' . tanggalID(substr($m['start_at'], 0, 10)) . ' pukul ' . jamTeks($m['start_at']) . ' WIB';
    $di = match ($m['mode'] ?? '') {
        'zoom'   => !empty($m['zoom_join_url']) ? "\nTautan Zoom: " . $m['zoom_join_url'] . (!empty($m['zoom_passcode']) ? ' (kode sandi ' . $m['zoom_passcode'] . ')' : '') : '',
        'meet'   => !empty($m['meet_url']) ? "\nTautan Google Meet: " . $m['meet_url'] : '',
        'onsite' => !empty($m['location_text']) ? "\nTempat: " . $m['location_text'] : '',
        'phone'  => "\nKami yang akan menelepon ke nomor ini.",
        default  => '',
    };
    return 'Halo' . ($nama !== '' ? ' ' . $nama : '') . ', kami mengingatkan jadwal ' . mb_strtolower(trim((string) ($m['title'] ?: 'pertemuan')))
         . ' ' . $kapan . '.' . $di . "\n\nSampai bertemu!";
}

/* ============================================================
   KESIAPAN DEAL — dipakai Ringkasan DAN kartu deal di klien.php
   ============================================================ */

/**
 * Enam hal yang harus beres sebelum persiapan dimulai, berurutan.
 * $r: data_lengkap_at, lokasi, ada_dekor, n_vendor (status <> batal),
 *     n_termin, n_meeting (status <> canceled, setelah serah terima), id.
 * @return array<int, array{kunci:string,label:string,ok:bool,href:string,aksi:string}>
 */
function kesiapanDeal(array $r): array
{
    $id = (int) ($r['id'] ?? 0);
    $k = 'klien.php?id=' . $id;
    return [
        ['kunci' => 'biodata', 'label' => 'biodata lengkap', 'ok' => !empty($r['data_lengkap_at']), 'href' => $k . '#datalengkap', 'aksi' => 'Isi biodata lengkap →'],
        ['kunci' => 'venue',   'label' => 'venue',           'ok' => trim((string) ($r['lokasi'] ?? '')) !== '', 'href' => $k . '#data', 'aksi' => 'Pilih venue →'],
        ['kunci' => 'dekor',   'label' => 'konsep dekor',    'ok' => !empty($r['ada_dekor']), 'href' => $k . '#dekor', 'aksi' => 'Tulis konsep dekor →'],
        ['kunci' => 'vendor',  'label' => 'vendor',          'ok' => (int) ($r['n_vendor'] ?? 0) > 0, 'href' => $k . '#vendor', 'aksi' => 'Pilih vendor →'],
        ['kunci' => 'termin',  'label' => 'termin',          'ok' => (int) ($r['n_termin'] ?? 0) > 1, 'href' => $k . '#uang', 'aksi' => 'Susun termin →'],
        ['kunci' => 'meeting', 'label' => 'meeting',         'ok' => (int) ($r['n_meeting'] ?? 0) > 0, 'href' => 'jadwal.php?new=1&client=' . $id, 'aksi' => 'Jadwalkan meeting →'],
    ];
}

function kesiapanJumlah(array $siap): int
{
    return count(array_filter($siap, fn($s) => $s['ok']));
}

/* ============================================================
   ANTREAN — penyusun baris
   ============================================================ */

/** Satu alasan calon baris. prio = urutan aturan (lebih kecil menang di tier yang sama). */
function calon(int $tier, int $prio, string $alasan, array $kapan, array $aksi = [], array $lain = [],
               array $saring = [], string $chip = '', int $telat = 0): array
{
    return compact('tier', 'prio', 'alasan', 'kapan', 'aksi', 'lain', 'saring', 'chip', 'telat');
}

function kapan(string $teks, bool $telat = false): array
{
    return ['teks' => $teks, 'telat' => $telat];
}

function aksiPost(string $label, string $action, array $field, ?string $konfirmasi = null, bool $utama = false): array
{
    return ['jenis' => 'post', 'label' => $label, 'action' => $action, 'field' => $field, 'konfirmasi' => $konfirmasi, 'utama' => $utama];
}

function aksiLink(string $label, string $href, bool $utama = false): array
{
    return ['jenis' => 'link', 'label' => $label, 'href' => $href, 'utama' => $utama];
}

/** Tautan wa.me; dibuang bila nomornya kosong. */
function aksiWa(?string $telp, string $teks = '', string $label = 'WA ↗'): ?array
{
    $u = waTautan((string) $telp, $teks);
    return $u === '' ? null : ['jenis' => 'wa', 'label' => $label, 'href' => $u, 'utama' => false];
}

/**
 * Gabungkan calon alasan menjadi satu baris. Tier terkecil menang; alasan
 * lain menjadi chip. $dasar: kunci, nama, href, chip (tambahan), tunda,
 * form, dibuat, titik.
 */
function barisAntreanDari(array $calon, array $dasar): ?array
{
    $calon = array_values(array_filter($calon));
    if (!$calon) return null;
    usort($calon, fn($a, $b) => [$a['tier'], $a['prio']] <=> [$b['tier'], $b['prio']]);
    $u = $calon[0];
    $chip = [];
    foreach (array_slice($calon, 1) as $c) if ($c['chip'] !== '' && !in_array($c['chip'], $chip, true)) $chip[] = $c['chip'];
    foreach ($dasar['chip'] ?? [] as $c) {
        // Chip yang sudah terbaca di kalimat alasan tidak perlu diulang.
        if (!is_array($c) && (in_array($c, $chip, true) || str_contains($u['alasan'], $c))) continue;
        $chip[] = $c;
    }
    $saring = [];
    foreach ($calon as $c) foreach ($c['saring'] as $s) $saring[$s] = true;
    foreach ($dasar['saring'] ?? [] as $s) $saring[$s] = true;
    return [
        'kunci'  => $dasar['kunci'],
        'tier'   => $u['tier'],
        'nama'   => $dasar['nama'],
        'href'   => $dasar['href'],
        'alasan' => $u['alasan'],
        'kapan'  => $u['kapan'],
        'chip'   => array_slice($chip, 0, 3),
        'aksi'   => array_values(array_filter($u['aksi'])),
        'lain'   => array_values(array_filter($u['lain'])),
        'tunda'  => $dasar['tunda'] ?? null,
        'saring' => array_keys($saring),
        'telat'  => max(array_map(fn($c) => $c['tier'] === $u['tier'] ? $c['telat'] : 0, $calon)),
        'form'   => (int) ($dasar['form'] ?? 0),
        'dibuat' => (string) ($dasar['dibuat'] ?? ''),
        'titik'  => $dasar['titik'] ?? null,
    ];
}

/** Urutkan: tier, paling telat, dari formulir dulu, paling lama dibuat. */
function antreanUrut(array $baris): array
{
    usort($baris, fn($a, $b) => [$a['tier'], -$a['telat'], -$a['form'], $a['dibuat']]
                            <=> [$b['tier'], -$b['telat'], -$b['form'], $b['dibuat']]);
    return $baris;
}

/** Info tunda untuk menu "Lainnya ▾". */
function tundaInfo(array $c): array
{
    $teks = trim((string) ($c['next_action'] ?? ''));
    return ['id' => (int) $c['id'], 'teks' => $teks !== '' ? $teks : stageNext((string) $c['stage'])];
}

/** Tombol hasil pertemuan (jadwal.php act=outcome). */
function aksiHasilTemu(array $m): array
{
    $id = (int) $m['id'];
    if (!empty($m['client_id'])) {
        return [
            [aksiPost('Lanjut', 'jadwal.php', ['act' => 'outcome', 'id' => $id, 'outcome' => 'lanjut'], null, true),
             aksiPost('Masih dipikir', 'jadwal.php', ['act' => 'outcome', 'id' => $id, 'outcome' => 'pikir'])],
            [aksiLink('Tidak lanjut… (isi alasan)', 'jadwal.php?edit=' . $id)],
        ];
    }
    return [
        [aksiLink('Catat hasil →', 'jadwal.php?edit=' . $id, true),
         aksiPost('Tutup', 'jadwal.php', ['act' => 'done', 'id' => $id], 'Tutup pertemuan ini tanpa mencatat hasil?')],
        [],
    ];
}

/** Baris yatim (pertemuan tanpa klien) yang sudah lewat. */
function barisTemuYatim(array $m): array
{
    [$aksi, $lain] = aksiHasilTemu($m);
    return barisAntreanDari([calon(1, 2, 'Pertemuan ' . tglPendek($m['start_at']) . ' belum dicatat hasilnya',
                                    kapan(labelLalu($m['start_at']), true), $aksi, $lain, [], '', (int) $m['hari_lalu'])],
                            ['kunci' => 'm' . (int) $m['id'], 'nama' => trim((string) ($m['client_name'] ?: $m['title'])) ?: 'Pertemuan',
                             'href' => 'jadwal.php?edit=' . (int) $m['id'], 'dibuat' => (string) $m['start_at']]);
}

/** Baris WA yatim (nomor yang belum dikenal sebagai klien). */
function barisWaYatim(array $w): array
{
    return barisAntreanDari([calon(2, 11, (int) $w['unread'] . ' pesan WhatsApp belum dibalas · ' . mb_strimwidth((string) $w['last_body'], 0, 60, '…'),
                                    kapan(labelLalu($w['last_at'])), [aksiLink('Balas →', 'chat.php?chat=' . (int) $w['id'], true)])],
                            ['kunci' => 'w' . (int) $w['id'], 'nama' => trim((string) ($w['nama'] ?: $w['wa_number'])),
                             'href' => 'chat.php?chat=' . (int) $w['id'], 'dibuat' => (string) $w['last_at']]);
}

/** Calon dari WA belum dibaca milik klien. */
function calonWa(array $w): array
{
    return calon(2, 11, (int) $w['unread'] . ' pesan WhatsApp belum dibalas · ' . mb_strimwidth((string) $w['last_body'], 0, 60, '…'),
                 kapan(labelLalu($w['last_at'])), [aksiLink('Balas →', 'chat.php?chat=' . (int) $w['id'], true)], [], [], 'WA belum dibalas');
}

/** Ringkas hitungan tier & saringan. */
function antreanHitung(array $baris): array
{
    $tier = [1 => 0, 2 => 0, 3 => 0];
    $saring = [];
    foreach ($baris as $b) {
        $tier[$b['tier']]++;
        foreach ($b['saring'] as $s) $saring[$s] = ($saring[$s] ?? 0) + 1;
    }
    return [$tier, $saring];
}

/** Pertemuan dalam lingkup peran: 30 hari ke belakang sampai 7 hari ke depan. */
function temuLingkup(array $tahap, array $peranPembuat): array
{
    $in = sqlTahap($tahap);
    $peran = sqlTahap($peranPembuat);
    return all("SELECT m.id, m.client_id, m.client_name, m.client_phone, c.phone AS c_phone, m.title, m.mode,
                       m.start_at, m.end_at, m.location_text, m.zoom_join_url, m.zoom_passcode, m.meet_url,
                       m.reminder_sent_at, m.sync_error, c.stage,
                       (m.end_at < NOW()) AS sudah_lewat,
                       DATEDIFF(CURDATE(), DATE(m.start_at)) AS hari_lalu
                  FROM meetings m
                  LEFT JOIN clients c ON c.id = m.client_id
                 WHERE m.status = 'scheduled'
                   AND m.start_at >= CURDATE() - INTERVAL 30 DAY
                   AND m.start_at <  CURDATE() + INTERVAL 8 DAY
                   AND (c.stage IN ($in)
                        OR (m.client_id IS NULL
                            AND m.created_by IN (SELECT u.id FROM users u WHERE u.role IN ($peran))))
                 ORDER BY m.start_at
                 LIMIT 40");
}

/** Pisahkan pertemuan: agenda (belum selesai), terbuka ≤14 hari, terbuka lama (15–30 hari). */
function temuPisah(array $temu): array
{
    $agenda = $terbuka = [];
    $lama = 0;
    foreach ($temu as $m) {
        if (!(int) $m['sudah_lewat']) { $agenda[] = $m; continue; }
        if ((int) $m['hari_lalu'] <= 14) $terbuka[] = $m; else $lama++;
    }
    return [$agenda, $terbuka, $lama];
}

/* ============================================================
   ADMIN EARLY — prospek sampai DP masuk
   ============================================================ */

function ringkasanEarly(): array
{
    $hasil = ['antrean' => [], 'galat' => [], 'agenda' => [], 'tanggal' => [], 'angka' => [],
              'berikutnya' => null, 'nKlien' => 0, 'tier' => [1 => 0, 2 => 0, 3 => 0], 'saring' => []];
    $hariIni = date('Y-m-d');
    $gateway = waSiap();
    $dpPersen = (int) setting('dp_percent', '30') ?: 30;
    $klien = [];

    try {
        $in = sqlTahap(TAHAP_EARLY);
        $klien = all("SELECT c.id, c.name, c.partner_name, c.phone, c.stage, c.source, c.dari_form,
                             c.created_at, c.next_action, c.next_action_at, c.wedding_date, c.deal_value,
                             c.paket_minat, t.nama AS paket_minat_nama, t.harga AS paket_minat_harga,
                             GREATEST(0, TIMESTAMPDIFF(HOUR, c.created_at, NOW())) AS umur_jam,
                             DATEDIFF(CURDATE(), c.next_action_at) AS telat_hari,
                             (c.wedding_date IS NOT NULL AND c.wedding_date < CURDATE()) AS tanggal_lewat,
                             q.id AS q_id, q.nomor AS q_nomor, q.jenis AS q_jenis, q.status AS q_status,
                             q.total AS q_total, q.paket_nama AS q_paket, q.token AS q_token,
                             q.sent_at AS q_sent_at, q.seen_at AS q_seen_at, q.valid_until AS q_valid,
                             q.nego_nilai AS q_nego, q.nego_catatan AS q_nego_catatan, q.nego_at AS q_nego_at,
                             p.id AS dp_id, p.label AS dp_label, p.amount AS dp_amount, p.terbayar AS dp_terbayar,
                             (p.amount - p.terbayar) AS dp_sisa, p.due_date AS dp_due, p.paid_at AS dp_paid,
                             DATEDIFF(p.due_date, CURDATE()) AS dp_hari
                        FROM clients c
                        LEFT JOIN quote_templates t ON t.id = c.paket_minat
                        LEFT JOIN quotes q ON q.id = (
                              SELECT q2.id FROM quotes q2
                               WHERE q2.client_id = c.id AND q2.status <> 'revisi'
                               ORDER BY (q2.jenis = 'pricelist') DESC, q2.id DESC LIMIT 1)
                        LEFT JOIN payments p ON c.stage = 'dp' AND p.id = (
                              SELECT p2.id FROM payments p2 WHERE p2.client_id = c.id
                               ORDER BY (p2.kode = 'dealing') DESC, p2.wajib DESC, p2.sort_order, p2.id LIMIT 1)
                       WHERE c.stage IN ($in)
                       ORDER BY c.next_action_at IS NULL, c.next_action_at, c.created_at
                       LIMIT 150");
        $hasil['nKlien'] = count($klien);

        $form = formPerluCek(30);
        $temu = temuLingkup(TAHAP_EARLY, ['admin_early']);
        [$agenda, $terbuka, $lama] = temuPisah($temu);
        $hasil['agenda'] = $agenda;

        $wa = [];
        if ($gateway) {
            $wa = all("SELECT ch.id, ch.client_id, ch.nama, ch.wa_number, ch.unread, ch.last_at, ch.last_body, c.stage
                         FROM wa_chats ch
                         LEFT JOIN clients c ON c.id = ch.client_id
                        WHERE ch.archived = 0 AND ch.is_group = 0 AND ch.unread > 0 AND ch.last_dir = 'masuk'
                          AND ch.jenis IN ('klien','lainnya')
                          AND (ch.client_id IS NULL OR c.stage IN ($in))
                        ORDER BY ch.last_at DESC LIMIT 10");
        }

        $temuPer = $waPer = [];
        $baris = [];
        foreach ($terbuka as $m) {
            if ($m['client_id']) $temuPer[(int) $m['client_id']][] = $m;
            else $baris[] = barisTemuYatim($m);
        }
        foreach ($wa as $w) {
            if ($w['client_id']) $waPer[(int) $w['client_id']] = $w;
            else $baris[] = barisWaYatim($w);
        }
        foreach ($form as $f) {
            $baris[] = barisAntreanDari([calon(1, 1, 'Kiriman formulir gagal jadi klien · ' . ($f['pesan'] ?: 'perlu dicek'),
                kapan(labelLalu($f['created_at']), true),
                [aksiPost('Jadikan klien', 'formulir.php', ['act' => 'jadikan', 'id' => (int) $f['id']], null, true),
                 aksiWa($f['wa'], teksBalasFormulir($f))],
                [aksiPost('Abaikan (sudah ditangani)', 'formulir.php', ['act' => 'abaikan', 'id' => (int) $f['id']])],
                ['formulir'], '', max(0, -(int) hariKe($f['created_at'])))],
                ['kunci' => 'f' . (int) $f['id'], 'nama' => trim((string) ($f['nama'] ?: $f['wa'])), 'href' => 'formulir.php',
                 'chip' => array_filter([$f['paket'] ? 'paket ' . $f['paket'] : '', $f['wa']]), 'form' => 1, 'dibuat' => (string) $f['created_at']]);
        }

        foreach ($klien as $c) {
            $calon = alasanEarly($c, ['hariIni' => $hariIni, 'gateway' => $gateway, 'dpPersen' => $dpPersen,
                                      'temu' => $temuPer[(int) $c['id']] ?? [], 'wa' => $waPer[(int) $c['id']] ?? null]);
            $b = barisAntreanDari($calon, [
                'kunci' => 'c' . (int) $c['id'], 'nama' => namaPasangan($c), 'href' => 'klien.php?id=' . (int) $c['id'] . '#langkah',
                'chip' => chipEarly($c, $calon), 'tunda' => tundaInfo($c), 'saring' => [saringEarly($c['stage'])],
                'form' => (int) $c['dari_form'], 'dibuat' => (string) $c['created_at'],
            ]);
            if ($b) $baris[] = $b;
        }
        if ($lama > 0) {
            $baris[] = barisAntreanDari([calon(3, 16, $lama . ' konsultasi lama (15–30 hari) belum ditutup',
                                         kapan('15–30 hari'), [aksiLink('Buka jadwal →', 'jadwal.php', true)])],
                                       ['kunci' => 'mlama', 'nama' => 'Jadwal', 'href' => 'jadwal.php']);
        }
        $hasil['antrean'] = antreanUrut(array_values(array_filter($baris)));
        [$hasil['tier'], $hasil['saring']] = antreanHitung($hasil['antrean']);

        // "Berikutnya" bila antrean bersih: tindakan bertanggal terdekat.
        foreach ($klien as $c) {
            if ($c['next_action_at'] && $c['next_action_at'] > $hariIni) {
                $hasil['berikutnya'] = ['nama' => namaPasangan($c), 'id' => (int) $c['id'],
                                        'teks' => $c['next_action'] ?: stageNext($c['stage']), 'tanggal' => $c['next_action_at']];
                break;
            }
        }
    } catch (Throwable $e) {
        $hasil['galat']['antrean'] = true;
        error_log('ringkasanEarly antrean: ' . $e->getMessage());
    }

    try { $hasil['tanggal'] = tanggalTerisi(); }
    catch (Throwable $e) { $hasil['galat']['tanggal'] = true; error_log('ringkasan tanggal: ' . $e->getMessage()); }

    try {
        $n = ['baru' => 0, 'pricelist' => 0, 'dp' => 0];
        $dpSisa = 0.0; $dpKlien = 0;
        foreach ($klien as $c) {
            $n[saringEarly($c['stage'])]++;
            if ($c['stage'] === 'dp' && $c['dp_id'] && !$c['dp_paid']) { $dpSisa += (float) $c['dp_sisa']; $dpKlien++; }
        }
        $x = one("SELECT
            (SELECT COUNT(*) FROM clients WHERE contract_signed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS dp_bln,
            (SELECT COUNT(*) FROM clients
               WHERE contract_signed_at >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
                 AND contract_signed_at <  CURDATE() - INTERVAL 1 MONTH + INTERVAL 1 DAY) AS dp_lalu,
            (SELECT COUNT(*) FROM clients
               WHERE dari_form = 1 AND created_at >= NOW() - INTERVAL 90 DAY
                 AND created_at < NOW() - INTERVAL 24 HOUR) AS form_n24,
            (SELECT COUNT(*) FROM clients
               WHERE dari_form = 1 AND created_at >= NOW() - INTERVAL 90 DAY
                 AND created_at < NOW() - INTERVAL 24 HOUR
                 AND pl_sent_at >= created_at AND pl_sent_at <= created_at + INTERVAL 24 HOUR) AS form_cepat24") ?: [];
        $hasil['angka'] = [
            'aktif' => count($klien), 'n' => $n, 'dpSisa' => $dpSisa, 'dpKlien' => $dpKlien,
            'dpBln' => (int) ($x['dp_bln'] ?? 0), 'dpLalu' => (int) ($x['dp_lalu'] ?? 0),
            'formN' => (int) ($x['form_n24'] ?? 0), 'formCepat' => (int) ($x['form_cepat24'] ?? 0),
        ];
    } catch (Throwable $e) {
        $hasil['galat']['angka'] = true;
        error_log('ringkasanEarly angka: ' . $e->getMessage());
    }
    return $hasil;
}

/** Kunci saringan untuk tahap pra-DP. Tahap lama ikut "price list". */
function saringEarly(string $stage): string
{
    return match ($stage) { 'baru' => 'baru', 'dp' => 'dp', default => 'pricelist' };
}

/** Chip tambahan baris early: tahap, asal formulir, paket, status price list. */
function chipEarly(array $c, array $calon): array
{
    $chip = [stageLabel($c['stage'])];
    if ((int) $c['dari_form']) $chip[] = 'dari formulir';
    $paket = trim((string) ($c['q_paket'] ?: $c['paket_minat_nama']));
    if ($paket !== '') $chip[] = 'paket ' . $paket;
    if ($c['q_status'] === 'terkirim' && $c['q_valid']) {
        $d = hariKe($c['q_valid']);
        if ($d !== null && $d >= 0 && $d <= 3) $chip[] = ['teks' => 'berlaku s.d. ' . tglPendek($c['q_valid'], false), 'nada' => 'ember'];
    }
    return $chip;
}

/** Tombol tagih DP: lewat gateway bila tersambung, kalau tidak tautan wa.me berisi teks yang sama. */
function aksiTagihDp(array $c, array $ctx): ?array
{
    if (!$c['dp_id']) return null;
    if ($ctx['gateway']) {
        return aksiPost('Tagih DP', 'klien.php', ['act' => 'pay_kirim_tagihan', 'id' => (int) $c['id'], 'payment_id' => (int) $c['dp_id']]);
    }
    $dpRow = ['label' => $c['dp_label'], 'amount' => $c['dp_amount'], 'terbayar' => $c['dp_terbayar'], 'due_date' => $c['dp_due']];
    return aksiWa($c['phone'], bayarTeksTagihan($c, [$dpRow]), 'Tagih DP ↗');
}

function aksiDpMasuk(array $c): array
{
    return ['jenis' => 'dp', 'label' => 'DP masuk ✓', 'utama' => true, 'client_id' => (int) $c['id'],
            'sisa' => (float) $c['dp_sisa'], 'nama' => namaPasangan($c), 'dp_label' => (string) $c['dp_label']];
}

/**
 * Aturan antrean admin early (E1–E16). Fungsi murni: tidak ada SQL.
 * Mengembalikan SEMUA alasan yang cocok; barisAntreanDari() memilih yang
 * paling mendesak dan menjadikan sisanya chip.
 */
function alasanEarly(array $c, array $ctx): array
{
    $hariIni = $ctx['hariIni'];
    $id = (int) $c['id'];
    $tunda = $c['next_action_at'] && $c['next_action_at'] > $hariIni;
    $tglLewat = (bool) (int) $c['tanggal_lewat'];
    $wa = aksiWa($c['phone']);
    $calon = [];

    // ---- DP ----
    if ($c['stage'] === 'dp' && $c['dp_id'] && !$c['dp_paid']) {
        $sisa = (float) $c['dp_sisa'];
        $judul = (float) $c['dp_terbayar'] > 0 ? 'Kekurangan DP ' . rupiah($sisa, true) : $c['dp_label'] . ' ' . rupiah($sisa, true);
        $aksi = [aksiDpMasuk($c), aksiTagihDp($c, $ctx)];
        $lain = [aksiLink('Catat sebagian / tanggal lain →', 'klien.php?id=' . $id . '#uang'), $wa];
        $h = $c['dp_hari'] === null ? null : (int) $c['dp_hari'];
        if ($h === null) {
            $calon[] = calon(2, 5, $judul . ' ditagih — belum ada tanggal tempo', kapan('tanpa tempo'), $aksi, $lain, ['dp'], 'DP ditagih');
        } elseif ($h < 0 && !$tunda) {
            $calon[] = calon(1, 3, $judul . ' lewat ' . abs($h) . ' hari', kapan('lewat ' . abs($h) . ' hari', true), $aksi, $lain, ['dp'], 'DP lewat tempo', abs($h));
        } elseif ($h < 0) {
            $calon[] = calon(3, 12, $judul . ' lewat ' . abs($h) . ' hari · ditunda sampai ' . tglPendek($c['next_action_at']),
                             kapan('lewat ' . abs($h) . ' hari', true), $aksi, $lain, ['dp'], 'DP lewat tempo', abs($h));
        } elseif ($h <= 1) {
            $calon[] = calon(2, 5, $judul . ' jatuh tempo ' . labelTempo($c['dp_due']), kapan(labelTempo($c['dp_due'])), $aksi, $lain, ['dp'], 'DP ' . labelTempo($c['dp_due']));
        } elseif ($h <= 7) {
            $calon[] = calon(3, 12, $judul . ' jatuh tempo ' . tglPendek($c['dp_due']), kapan(labelTempo($c['dp_due'])), $aksi, $lain, ['dp'], 'DP ' . tglPendek($c['dp_due']));
        }
    }

    // ---- Price list belum terkirim ----
    $aksiPL = function () use ($c, $id): array {
        if ($c['q_id'] && $c['q_status'] === 'draf') {
            return (float) $c['q_total'] > 0
                ? aksiLink('Periksa & kirim ' . $c['q_nomor'] . ' →', 'penawaran.php?id=' . (int) $c['q_id'], true)
                : aksiLink('Lengkapi price list →', 'penawaran.php?id=' . (int) $c['q_id'], true);
        }
        if (!$c['q_id'] && $c['paket_minat'] && $c['paket_minat_nama'] !== null) {   // paket bisa sudah dihapus
            return aksiPost('Siapkan price list ' . $c['paket_minat_nama'], 'penawaran.php',
                            ['act' => 'buat', 'jenis' => 'pricelist', 'client_id' => $id, 'template_id' => (int) $c['paket_minat']], null, true);
        }
        return aksiLink('Pilih paket →', 'penawaran.php?client=' . $id, true);
    };
    $belumKirim = $c['stage'] === 'baru' && !$c['q_sent_at'];
    $dariForm = (int) $c['dari_form'] ? ' · dari formulir' : '';
    if ($belumKirim && (int) $c['umur_jam'] >= 24 && !$tunda && !$tglLewat) {
        $calon[] = calon(1, 4, 'Menunggu price list ' . durasiTeks((int) $c['umur_jam'] * 60) . $dariForm,
                         kapan(durasiTeks((int) $c['umur_jam'] * 60), true), [$aksiPL(), aksiWa($c['phone'], teksSapaProspek($c))],
                         [], ['baru'], 'price list belum dikirim', intdiv((int) $c['umur_jam'], 24));
    }
    if ($belumKirim && (int) $c['umur_jam'] < 24) {
        $paket = trim((string) ($c['paket_minat_nama'] ?? ''));
        $calon[] = calon(2, 6, 'Prospek baru' . $dariForm . ' — kirim price list' . ($paket !== '' ? ' ' . $paket : ''),
                         kapan(durasiTeks(max(1, (int) $c['umur_jam']) * 60)), [$aksiPL(), aksiWa($c['phone'], teksSapaProspek($c))],
                         [], ['baru'], 'prospek baru');
    }

    // ---- Price list terkirim ----
    $terkirim = $c['q_status'] === 'terkirim';
    $aksiCocok = null;
    if ($terkirim && (float) $c['q_total'] > 0) {
        $tot = (float) $c['q_total'];
        $aksiCocok = aksiPost('Klien cocok → tagih DP', 'penawaran.php', ['act' => 'cocok', 'id' => (int) $c['q_id']],
            'Klien cocok dengan ' . $c['q_nomor'] . ' (' . rupiah($tot) . ')? DP ' . $ctx['dpPersen'] . '% (' . rupiah(round($tot * $ctx['dpPersen'] / 100)) . ') langsung ditagih.');
    }
    if ($terkirim && $c['q_nego'] !== null) {
        $catat = trim((string) $c['q_nego_catatan']);
        $calon[] = calon(2, 7, 'Klien menawar ' . rupiah((float) $c['q_nego'], true) . ' dari ' . rupiah((float) $c['q_total'], true) . ($catat !== '' ? ' · ' . $catat : ''),
                         kapan($c['q_nego_at'] ? labelLalu($c['q_nego_at']) : 'menawar'),
                         [aksiLink('Buka price list →', 'penawaran.php?id=' . (int) $c['q_id'], true), $wa], [], ['pricelist'], 'menawar');
    }
    $plStage = in_array($c['stage'], ['pricelist', 'spesifikasi', 'penawaran'], true);
    if ($terkirim && $plStage && (!$c['next_action_at'] || $c['next_action_at'] <= $hariIni)) {
        $alasan = $c['q_seen_at'] ? 'Price list dibuka ' . labelLalu($c['q_seen_at']) . ', belum menjawab'
                                  : 'Price list belum dibuka sejak ' . labelLalu($c['q_sent_at']);
        $telat = $c['next_action_at'] ? max(0, (int) $c['telat_hari']) : 0;
        $calon[] = calon(2, 8, $alasan, kapan($c['next_action_at'] ? labelTempo($c['next_action_at']) : labelLalu($c['q_sent_at']), $telat > 0),
                         [aksiWa($c['phone'], teksFollowUpPL($c, $c), 'Tanya kabar ↗'), $aksiCocok],
                         [aksiLink('Buka price list →', 'penawaran.php?id=' . (int) $c['q_id'])], ['pricelist'],
                         $c['q_seen_at'] ? 'dibuka ' . labelLalu($c['q_seen_at']) : 'belum dibuka', $telat);
    }

    // ---- DP tanpa termin ----
    if ($c['stage'] === 'dp' && !$c['dp_id']) {
        $calon[] = calon(2, 9, 'Nilai deal kosong — DP belum bisa ditagih', kapan('perlu nilai'),
                         [aksiLink($c['q_id'] ? 'Buka price list →' : 'Isi nilai deal →', $c['q_id'] ? 'penawaran.php?id=' . (int) $c['q_id'] : 'klien.php?id=' . $id . '#data', true)],
                         [], ['dp'], 'nilai deal kosong');
    }

    // ---- Tindak lanjut jatuh tempo (lainnya) ----
    if ($c['next_action_at'] && $c['next_action_at'] <= $hariIni && !$tglLewat) {
        $calon[] = calon(2, 10, $c['next_action'] ?: stageNext($c['stage']), kapan(labelTempo($c['next_action_at']), $c['next_action_at'] < $hariIni),
                         [aksiLink('Buka →', 'klien.php?id=' . $id . '#langkah', true), $wa], [], [], 'tindak lanjut ' . labelTempo($c['next_action_at']),
                         max(0, (int) $c['telat_hari']));
    }

    // ---- Pertemuan tanpa hasil & WhatsApp ----
    foreach ($ctx['temu'] as $m) {
        [$aksi, $lain] = aksiHasilTemu($m);
        $calon[] = calon(1, 2, 'Konsultasi ' . tglPendek($m['start_at']) . ' belum dicatat hasilnya', kapan(labelLalu($m['start_at']), true),
                         $aksi, $lain, [], 'hasil konsultasi belum dicatat', (int) $m['hari_lalu']);
    }
    if ($ctx['wa']) $calon[] = calonWa($ctx['wa']);

    // ---- Segera ----
    if ($terkirim && $tunda && $c['q_seen_at'] && strtotime($c['q_seen_at']) >= time() - 72 * 3600) {
        $calon[] = calon(3, 13, 'Baru membuka price list ' . labelLalu($c['q_seen_at']) . ' — sapa selagi hangat', kapan(labelLalu($c['q_seen_at'])),
                         [aksiWa($c['phone'], teksFollowUpPL($c, $c), 'Sapa ↗'), $aksiCocok], [], ['pricelist'], 'dibuka ' . labelLalu($c['q_seen_at']));
    }
    if ($tglLewat) {
        $calon[] = calon(3, 14, 'Tanggal rencana ' . tglPendek($c['wedding_date'], false) . ' sudah lewat — tanya ulang atau tandai tidak jadi',
                         kapan('tanggal lewat'), [$wa, aksiLink('Tandai tidak jadi →', 'klien.php?id=' . $id . '#langkah')], [], [], 'tanggal lewat');
    }
    if (!$c['next_action_at']) {
        $calon[] = calon(3, 15, 'Belum ada langkah berikutnya', kapan('tanpa tanggal'),
                         [aksiLink('Buka →', 'klien.php?id=' . $id . '#langkah', true), $wa], [], [], 'tanpa langkah');
    }
    return $calon;
}

/* ============================================================
   ADMIN OFFICE — setelah DP sampai hari-H
   ============================================================ */

function ringkasanOffice(): array
{
    $hasil = ['antrean' => [], 'galat' => [], 'agenda' => [], 'terminAgenda' => [], 'acara' => [],
              'hero' => null, 'harih30' => [], 'angka' => [], 'berikutnya' => null, 'nKlien' => 0,
              'tier' => [1 => 0, 2 => 0, 3 => 0], 'saring' => [], 'selanjutnya' => null];
    $hariIni = date('Y-m-d');
    $gateway = waSiap();
    $acara = [];

    try {
        $in = sqlTahap(TAHAP_OFFICE);
        $acara = all("SELECT c.id, c.name, c.partner_name, c.phone, c.stage, c.wedding_date, c.wedding_time, c.city,
                             c.deal_value, c.next_action, c.next_action_at, c.handover_at, c.data_lengkap_at, c.created_at,
                             COALESCE(NULLIF(wi.resepsi_lokasi,''), NULLIF(wi.akad_lokasi,''), NULLIF(c.venue,'')) AS lokasi,
                             DATEDIFF(c.wedding_date, CURDATE()) AS hari_ke_h,
                             DATEDIFF(CURDATE(), DATE(c.handover_at)) AS hari_sejak_dp,
                             (TRIM(COALESCE(wi.konsep_dekor,'')) <> '') AS ada_dekor,
                             (SELECT COUNT(*) FROM client_vendors v WHERE v.client_id = c.id AND v.status <> 'batal') AS n_vendor,
                             (SELECT COUNT(*) FROM payments p WHERE p.client_id = c.id) AS n_termin,
                             (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.client_id = c.id) AS tagihan_total,
                             (SELECT COALESCE(SUM(p.terbayar),0) FROM payments p WHERE p.client_id = c.id) AS tagihan_masuk,
                             (SELECT COUNT(*) FROM meetings m
                               WHERE m.client_id = c.id AND m.status <> 'canceled'
                                 AND (c.handover_at IS NULL OR m.start_at >= c.handover_at)) AS n_meeting,
                             (SELECT COUNT(*) FROM client_tasks t WHERE t.client_id = c.id) AS n_tugas,
                             (SELECT COUNT(*) FROM client_tasks t WHERE t.client_id = c.id AND t.done_at IS NOT NULL) AS n_tugas_selesai,
                             (SELECT COUNT(*) FROM client_tasks t
                               WHERE t.client_id = c.id AND t.done_at IS NULL AND t.due_date < CURDATE()) AS n_tugas_telat
                        FROM clients c
                        LEFT JOIN client_wedding_info wi ON wi.client_id = c.id
                       WHERE c.stage IN ($in)
                       ORDER BY c.wedding_date IS NULL, c.wedding_date, c.id
                       LIMIT 100");
        $hasil['nKlien'] = count($acara);
        $hasil['acara'] = $acara;

        $termin = all("SELECT p.id, p.client_id, p.kode, p.label, p.amount, p.terbayar, (p.amount - p.terbayar) AS sisa,
                              p.due_date, DATEDIFF(p.due_date, CURDATE()) AS hari_ke_tempo, p.ingat_kode, p.ingat_at,
                              c.name, c.partner_name, c.phone, c.stage, c.wedding_date, c.created_at,
                              c.next_action, c.next_action_at
                         FROM payments p
                         JOIN clients c ON c.id = p.client_id
                        WHERE p.paid_at IS NULL AND p.amount > p.terbayar
                          AND p.due_date IS NOT NULL AND p.due_date <= CURDATE() + INTERVAL 7 DAY
                          AND c.stage IN ('deal','persiapan','harih','selesai')
                        ORDER BY p.due_date, p.client_id, p.sort_order
                        LIMIT 40");
        // Termasuk langkah SETELAH acara (H+1 bereskan barang, H+3 testimoni)
        // milik klien yang sudah 'selesai' — cron memindahkan tahapnya di H+1,
        // tepat saat langkah itu jatuh tempo.
        $tugas = all("SELECT t.id, t.client_id, t.title, t.due_date, DATEDIFF(t.due_date, CURDATE()) AS hari_ke_tempo,
                             c.name, c.partner_name, c.phone, c.stage, c.wedding_date, c.next_action, c.next_action_at, c.created_at
                        FROM client_tasks t
                        JOIN clients c ON c.id = t.client_id
                       WHERE t.done_at IS NULL AND t.due_date IS NOT NULL
                         AND t.due_date <= CURDATE() + INTERVAL 7 DAY
                         AND (c.stage IN ($in)
                              OR (c.stage = 'selesai' AND t.offset_day > 0 AND t.due_date >= CURDATE() - INTERVAL 30 DAY))
                       ORDER BY t.due_date, t.sort_order
                       LIMIT 60");
        $temu = temuLingkup(TAHAP_OFFICE, ['admin_office', 'editor']);
        [$agenda, $terbuka, $lama] = temuPisah($temu);
        $hasil['agenda'] = $agenda;

        $wa = [];
        if ($gateway) {
            $wa = all("SELECT ch.id, ch.client_id, ch.vendor_id, ch.jenis, ch.nama, ch.wa_number, ch.unread, ch.last_at, ch.last_body, c.stage
                         FROM wa_chats ch
                         LEFT JOIN clients c ON c.id = ch.client_id
                        WHERE ch.archived = 0 AND ch.is_group = 0 AND ch.unread > 0 AND ch.last_dir = 'masuk'
                          AND (ch.jenis = 'vendor' OR c.stage IN ('deal','persiapan','harih'))
                        ORDER BY ch.last_at DESC LIMIT 10");
        }

        // Kelompokkan per klien.
        $terminPer = $tugasPer = $temuPer = $waPer = [];
        $baris = [];
        foreach ($termin as $p) {
            $terminPer[(int) $p['client_id']][] = $p;
            $h = (int) $p['hari_ke_tempo'];
            if ($h >= 0 && $h <= 7) {
                $k = $p['client_id'] . '|' . $p['due_date'];
                $hasil['terminAgenda'][$k] ??= ['client_id' => (int) $p['client_id'], 'nama' => namaPasangan($p), 'tanggal' => $p['due_date'], 'n' => 0, 'rp' => 0.0];
                $hasil['terminAgenda'][$k]['n']++;
                $hasil['terminAgenda'][$k]['rp'] += (float) $p['sisa'];
            }
        }
        $hasil['terminAgenda'] = array_values($hasil['terminAgenda']);
        foreach ($tugas as $t) $tugasPer[(int) $t['client_id']][] = $t;
        foreach ($terbuka as $m) {
            if ($m['client_id']) $temuPer[(int) $m['client_id']][] = $m;
            else $baris[] = barisTemuYatim($m);
        }
        foreach ($wa as $w) {
            if ($w['client_id']) $waPer[(int) $w['client_id']] = $w;
            else {
                $b = barisWaYatim($w);
                if ($w['jenis'] === 'vendor') $b['chip'] = ['vendor'];
                $baris[] = $b;
            }
        }

        // Klien selesai yang masih punya tagihan / langkah pasca-acara ikut
        // antrean dari kolom baris itu sendiri.
        $adaId = array_flip(array_map(fn($a) => (int) $a['id'], $acara));
        $semua = $acara;
        foreach ($terminPer + $tugasPer as $cid => $rows) {
            if (isset($adaId[$cid])) continue;
            $p = $rows[0];
            $semua[] = ['id' => $cid, 'name' => $p['name'], 'partner_name' => $p['partner_name'], 'phone' => $p['phone'],
                        'stage' => $p['stage'], 'wedding_date' => $p['wedding_date'], 'next_action' => $p['next_action'],
                        'next_action_at' => $p['next_action_at'], 'created_at' => $p['created_at'], 'tamu' => true];
            $adaId[$cid] = true;
        }

        foreach ($semua as $c) {
            $cid = (int) $c['id'];
            $siap = empty($c['tamu']) && $c['stage'] === 'deal' ? kesiapanDeal($c) : null;
            $calon = alasanOffice($c, ['hariIni' => $hariIni, 'gateway' => $gateway, 'siap' => $siap,
                                       'termin' => $terminPer[$cid] ?? [], 'tugas' => $tugasPer[$cid] ?? [],
                                       'temu' => $temuPer[$cid] ?? [], 'wa' => $waPer[$cid] ?? null]);
            $saring = [];
            if ($c['stage'] === 'deal') $saring[] = 'deal';
            if (isset($c['hari_ke_h']) && $c['hari_ke_h'] !== null && (int) $c['hari_ke_h'] >= 0 && (int) $c['hari_ke_h'] <= 30) $saring[] = 'harih';
            $chip = [stageLabel($c['stage'])];
            if (!empty($c['wedding_date'])) $chip[] = ((int) ($c['hari_ke_h'] ?? hariKe($c['wedding_date'])) >= 0 ? labelHari($c['wedding_date']) : 'acara ' . tglPendek($c['wedding_date'], false));
            $b = barisAntreanDari($calon, [
                'kunci' => 'c' . $cid, 'nama' => namaPasangan($c), 'href' => 'klien.php?id=' . $cid . '#langkah',
                'chip' => $chip, 'tunda' => tundaInfo($c), 'saring' => $saring,
                'dibuat' => (string) ($c['wedding_date'] ?? '9999'), 'titik' => $siap,
            ]);
            if ($b) $baris[] = $b;
        }
        if ($lama > 0) {
            $baris[] = barisAntreanDari([calon(3, 16, $lama . ' meeting lama (15–30 hari) belum ditutup',
                                         kapan('15–30 hari'), [aksiLink('Buka jadwal →', 'jadwal.php', true)])],
                                       ['kunci' => 'mlama', 'nama' => 'Jadwal', 'href' => 'jadwal.php']);
        }
        $hasil['antrean'] = antreanUrut(array_values(array_filter($baris)));
        [$hasil['tier'], $hasil['saring']] = antreanHitung($hasil['antrean']);

        foreach ($acara as $c) {
            if ($hasil['hero'] === null && $c['hari_ke_h'] !== null && (int) $c['hari_ke_h'] >= 0) { $hasil['hero'] = $c; continue; }
            if ($hasil['hero'] !== null && $c['hari_ke_h'] !== null && (int) $c['hari_ke_h'] >= 0) {
                if ((int) $c['hari_ke_h'] <= 30) { if (count($hasil['harih30']) < 5) $hasil['harih30'][] = $c; }
                elseif (!$hasil['selanjutnya']) $hasil['selanjutnya'] = $c;
            }
        }
        $dekat = array_filter($acara, fn($c) => $c['next_action_at'] && $c['next_action_at'] > $hariIni);
        usort($dekat, fn($a, $b) => strcmp($a['next_action_at'], $b['next_action_at']));
        if ($dekat) {
            $c = reset($dekat);
            $hasil['berikutnya'] = ['nama' => namaPasangan($c), 'id' => (int) $c['id'],
                                    'teks' => $c['next_action'] ?: stageNext($c['stage']), 'tanggal' => $c['next_action_at']];
        }
    } catch (Throwable $e) {
        $hasil['galat']['antrean'] = true;
        error_log('ringkasanOffice antrean: ' . $e->getMessage());
    }

    try {
        $x = one("SELECT
              COALESCE(SUM(CASE WHEN p.due_date < CURDATE() THEN p.amount - p.terbayar END), 0) AS rp_lewat,
              COUNT(CASE WHEN p.due_date < CURDATE() THEN 1 END) AS n_lewat,
              MIN(CASE WHEN p.due_date < CURDATE() THEN p.due_date END) AS tempo_tertua,
              COALESCE(SUM(CASE WHEN p.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY
                                THEN p.amount - p.terbayar END), 0) AS rp_30,
              COUNT(CASE WHEN p.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY THEN 1 END) AS n_30,
              COUNT(CASE WHEN p.due_date IS NULL THEN 1 END) AS n_tanpa_tgl
            FROM payments p
            JOIN clients c ON c.id = p.client_id
           WHERE p.paid_at IS NULL AND p.amount > p.terbayar
             AND c.stage IN ('deal','persiapan','harih','selesai')") ?: [];
        $n = ['deal' => 0, 'persiapan' => 0, 'harih' => 0];
        $tugasLewat = 0; $acaraLewat = 0;
        foreach ($acara as $c) {
            $n[$c['stage']] = ($n[$c['stage']] ?? 0) + 1;
            $tugasLewat += (int) $c['n_tugas_telat'];
            if ((int) $c['n_tugas_telat'] > 0) $acaraLewat++;
        }
        $hasil['angka'] = [
            'acara' => count($acara), 'n' => $n,
            'rp30' => (float) ($x['rp_30'] ?? 0), 'n30' => (int) ($x['n_30'] ?? 0), 'tanpaTgl' => (int) ($x['n_tanpa_tgl'] ?? 0),
            'rpLewat' => (float) ($x['rp_lewat'] ?? 0), 'nLewat' => (int) ($x['n_lewat'] ?? 0),
            'tertua' => $x['tempo_tertua'] ?? null, 'tugasLewat' => $tugasLewat, 'acaraLewat' => $acaraLewat,
        ];
    } catch (Throwable $e) {
        $hasil['galat']['angka'] = true;
        error_log('ringkasanOffice angka: ' . $e->getMessage());
    }
    return $hasil;
}

/** Tombol tagih termin: gateway atau wa.me dengan teks pengingat yang sama dengan cron. */
function aksiTagihTermin(array $c, array $rows, string $jenis, bool $gateway): ?array
{
    if (!$rows) return null;
    if ($gateway) {
        $f = ['act' => 'pay_kirim_tagihan', 'id' => (int) $c['id'], 'jenis' => $jenis];
        foreach ($rows as $i => $p) $f['payment_ids[' . $i . ']'] = (int) $p['id'];
        return aksiPost('Kirim tagihan', 'klien.php', $f);
    }
    return aksiWa($c['phone'], bayarTeksTagihan($c, $rows, $jenis), 'Tagih via WA ↗');
}

function aksiLunas(array $c, array $p): array
{
    return aksiPost('Lunas ✓', 'klien.php', ['act' => 'pay_paid', 'id' => (int) $c['id'], 'payment_id' => (int) $p['id']],
        'Catat ' . $p['label'] . ' ' . rupiah(bayarSisa($p)) . ' dari ' . namaPasangan($c) . ' sudah diterima HARI INI lewat transfer? '
        . 'Untuk tanggal, cara bayar, atau jumlah lain, buka tab Pembayaran.', true);
}

/** "Termin ke-2 Rp 17 jt" atau "3 termin · Rp 59,5 jt" */
function teksTermin(array $rows): string
{
    $tot = array_sum(array_map(fn($p) => (float) $p['sisa'], $rows));
    return count($rows) === 1 ? $rows[0]['label'] . ' ' . rupiah($tot, true) : count($rows) . ' termin · ' . rupiah($tot, true);
}

/**
 * Aturan antrean admin office (O1–O16). Fungsi murni: tidak ada SQL.
 */
function alasanOffice(array $c, array $ctx): array
{
    $hariIni = $ctx['hariIni'];
    $id = (int) $c['id'];
    $tunda = $c['next_action_at'] && $c['next_action_at'] > $hariIni;
    $wa = aksiWa($c['phone'] ?? '');
    $kUang = 'klien.php?id=' . $id . '#uang';
    $kCek = 'klien.php?id=' . $id . '#checklist';
    $calon = [];

    // ---- Termin ----
    $lewat = $dekat = $minggu = [];
    foreach ($ctx['termin'] as $p) {
        $h = (int) $p['hari_ke_tempo'];
        if ($h < 0) $lewat[] = $p; elseif ($h <= 1) $dekat[] = $p; else $minggu[] = $p;
    }
    if ($lewat) {
        $h = abs((int) $lewat[0]['hari_ke_tempo']);
        $teks = $lewat[0]['label'] . ' ' . rupiah((float) $lewat[0]['sisa'], true) . ' lewat ' . $h . ' hari'
              . (count($lewat) > 1 ? ' (+' . (count($lewat) - 1) . ' termin)' : '');
        $aksi = [aksiLunas($c, $lewat[0]), aksiTagihTermin($c, $lewat, 'telat', $ctx['gateway'])];
        $lain = [aksiLink('Catat pembayaran lain →', $kUang), $wa];
        // Uang lewat tempo TIDAK ikut ditunda oleh next_action: klien
        // persiapan hampir selalu punya tindakan bertanggal di depan, jadi
        // tagihan telat akan selamanya turun ke "Segera". Yang menurunkannya
        // hanya tagihan/pengingat yang baru terkirim (≤ 2 hari): beri klien
        // waktu menjawab sebelum ditagih lagi.
        $ingatAt = null;
        foreach ($lewat as $p) if ($p['ingat_at'] && (!$ingatAt || $p['ingat_at'] > $ingatAt)) $ingatAt = $p['ingat_at'];
        if ($ingatAt && strtotime($ingatAt) >= strtotime('-2 day')) {
            $calon[] = calon(3, 12, $teks . ' · ditagih ' . labelLalu($ingatAt) . ', tunggu konfirmasi', kapan('lewat ' . $h . ' hari', true),
                             $aksi, $lain, ['tagihan'], 'termin lewat tempo', $h);
        } else {
            $calon[] = calon(1, 1, $teks, kapan('lewat ' . $h . ' hari', true), $aksi, $lain, ['tagihan'], 'termin lewat tempo', $h);
            if ($ingatAt) $calon[] = calon(9, 99, '', kapan(''), [], [], [], 'terakhir ditagih ' . labelLalu($ingatAt));
        }
    }
    if ($dekat) {
        $calon[] = calon(2, 4, teksTermin($dekat) . ' jatuh tempo ' . labelTempo($dekat[0]['due_date']), kapan(labelTempo($dekat[0]['due_date'])),
                         [aksiTagihTermin($c, $dekat, 'sebelum', $ctx['gateway']), aksiLunas($c, $dekat[0])],
                         [aksiLink('Catat pembayaran lain →', $kUang)], ['tagihan'], 'termin ' . labelTempo($dekat[0]['due_date']));
    }
    if ($minggu) {
        $calon[] = calon(3, 12, teksTermin($minggu) . ' jatuh tempo ' . tglPendek($minggu[0]['due_date']), kapan(labelTempo($minggu[0]['due_date'])),
                         [aksiTagihTermin($c, $minggu, 'sebelum', $ctx['gateway']), aksiLink('Pembayaran →', $kUang)],
                         [], ['tagihan'], 'termin ' . labelTempo($minggu[0]['due_date']));
    }

    if (!empty($c['tamu'])) {
        // Klien selesai: hanya urusan uang dan langkah setelah acara.
        if ($ctx['tugas']) {
            $t0 = $ctx['tugas'][0];
            $telat = (int) $t0['hari_ke_tempo'] < 0;
            $calon[] = calon($telat ? 2 : 3, $telat ? 5 : 13,
                             (count($ctx['tugas']) > 1 ? count($ctx['tugas']) . ' langkah setelah acara · ' : 'Langkah setelah acara: ') . $t0['title'],
                             kapan(labelTempo($t0['due_date']), $telat),
                             [aksiPost('✓ ' . mb_strimwidth($t0['title'], 0, 34, '…'), 'klien.php',
                                       ['act' => 'task_toggle', 'id' => $id, 'task_id' => (int) $t0['id'], 'hanya' => 'selesai'], null, true),
                              aksiLink('Checklist →', $kCek)],
                             [], ['checklist'], 'langkah setelah acara', $telat ? abs((int) $t0['hari_ke_tempo']) : 0);
        }
        return $calon;
    }

    // ---- Hari-H dekat ----
    $hk = $c['hari_ke_h'] === null ? null : (int) $c['hari_ke_h'];
    $belumLunas = (float) $c['tagihan_total'] - (float) $c['tagihan_masuk'];
    if ($hk !== null && $hk >= 0 && $hk <= 7 && ((int) $c['n_tugas_telat'] > 0 || $belumLunas > 0.5)) {
        $bag = [$hk === 0 ? 'Hari ini' : 'H-' . $hk];
        if ((int) $c['n_tugas_telat'] > 0) $bag[] = (int) $c['n_tugas_telat'] . ' langkah lewat';
        if ($belumLunas > 0.5) $bag[] = rupiah($belumLunas, true) . ' belum lunas';
        $calon[] = calon(1, 2, implode(' · ', $bag), kapan($hk === 0 ? 'hari ini' : 'H-' . $hk, true),
                         [aksiLink('Checklist →', $kCek, true), $belumLunas > 0.5 ? aksiLink('Pembayaran →', $kUang) : $wa],
                         [$wa], ['checklist'], 'H-' . $hk);
    }

    // ---- Pertemuan tanpa hasil ----
    foreach ($ctx['temu'] as $m) {
        [$aksi, $lain] = aksiHasilTemu($m);
        $judul = trim(preg_split('/\s+[·—–-]\s+/u', trim((string) $m['title']))[0] ?? '') ?: 'Meeting';
        $calon[] = calon(1, 3, $judul . ' ' . tglPendek($m['start_at']) . ' belum dicatat hasilnya',
                         kapan(labelLalu($m['start_at']), true), $aksi, $lain, [], 'meeting belum dicatat', (int) $m['hari_lalu']);
    }

    // ---- Checklist ----
    $tLewat = $tMinggu = [];
    foreach ($ctx['tugas'] as $t) { if ((int) $t['hari_ke_tempo'] < 0) $tLewat[] = $t; else $tMinggu[] = $t; }
    $tombolTugas = fn(array $t) => aksiPost('✓ ' . mb_strimwidth($t['title'], 0, 34, '…'), 'klien.php',
        ['act' => 'task_toggle', 'id' => $id, 'task_id' => (int) $t['id'], 'hanya' => 'selesai'], null, true);
    // Langkah lewat tempo TIDAK ikut ditunda next_action: tahap persiapan
    // selalu punya tindakan bertanggal di depan (diisi sistem), jadi kalau
    // ditunda, langkah yang telat justru hilang dari antrean.
    if ($tLewat && ($hk === null || $hk > 7)) {
        $tua = $tLewat[0];
        $teks = count($tLewat) === 1
            ? 'Langkah lewat: ' . $tua['title'] . ' (' . labelTempo($tua['due_date']) . ')'
            : count($tLewat) . ' langkah lewat · tertua: ' . $tua['title'] . ' (' . labelTempo($tua['due_date']) . ')';
        $calon[] = calon(2, 5, $teks, kapan(labelTempo($tua['due_date']), true), [$tombolTugas($tua), aksiLink('Checklist →', $kCek)],
                         [], ['checklist'], count($tLewat) . ' langkah lewat', abs((int) $tua['hari_ke_tempo']));
    } elseif ($tLewat) {
        $calon[] = calon(9, 99, '', kapan(''), [], [], ['checklist'], count($tLewat) . ' langkah lewat');
    }
    if ($tMinggu) {
        $dek = $tMinggu[0];
        $teks = count($tMinggu) === 1 ? 'Langkah ' . labelTempo($dek['due_date']) . ': ' . $dek['title']
                                      : count($tMinggu) . ' langkah minggu ini · terdekat: ' . $dek['title'] . ' (' . tglPendek($dek['due_date']) . ')';
        $calon[] = calon((int) $dek['hari_ke_tempo'] === 0 ? 2 : 3, 13, $teks, kapan(labelTempo($dek['due_date'])),
                         [$tombolTugas($dek), aksiLink('Checklist →', $kCek)], [], ['checklist'], count($tMinggu) . ' langkah minggu ini');
    }

    // ---- Penyusunan (tahap deal) ----
    $siap = $ctx['siap'];
    if ($siap !== null) {
        $nSiap = kesiapanJumlah($siap);
        $kurang = array_values(array_filter($siap, fn($s) => !$s['ok']));
        if ($nSiap === 6) {
            $calon[] = calon(2, 6, 'Siap masuk persiapan — semua bagian penyusunan lengkap', kapan('siap'),
                             [aksiPost('Mulai persiapan →', 'klien.php', ['act' => 'stage', 'id' => $id, 'stage' => 'persiapan'],
                                       'Mulai persiapan untuk ' . namaPasangan($c) . '? Checklist H-90 sampai H+3 dibuat otomatis.', true)],
                             [], ['deal'], 'siap persiapan');
        } else {
            $hs = $c['hari_sejak_dp'] === null ? 0 : (int) $c['hari_sejak_dp'];
            $aksi = [aksiLink($kurang[0]['aksi'], $kurang[0]['href'], true), $wa];
            if ($hs > stageSla('deal')) {
                if (!$tunda) {
                    $calon[] = calon(2, 7, 'Penyusunan ' . $hs . ' hari · ' . $nSiap . '/6 siap — berikutnya: ' . $kurang[0]['label'],
                                     kapan($hs . ' hari', true), $aksi, [], ['deal'], $nSiap . '/6 siap', $hs - stageSla('deal'));
                }
            } else {
                $teks = (int) $c['n_meeting'] === 0 ? 'sapa & jadwalkan meeting pertama' : 'berikutnya: ' . $kurang[0]['label'];
                if ((int) $c['n_meeting'] === 0) $aksi[0] = aksiLink('Jadwalkan meeting →', 'jadwal.php?new=1&client=' . $id, true);
                $calon[] = calon(3, 14, 'Baru diserahkan ' . labelLalu($c['handover_at']) . ' · ' . $teks, kapan(labelLalu($c['handover_at'])),
                                 $aksi, [], ['deal'], $nSiap . '/6 siap');
            }
        }
        if (empty($c['wedding_date'])) {
            $calon[] = calon(2, 8, 'Tanggal acara belum pasti — termin & checklist belum bisa dihitung', kapan('tanpa tanggal'),
                             [aksiLink('Isi tanggal →', 'klien.php?id=' . $id . '#data', true), $wa], [], ['deal'], 'tanggal belum pasti');
        }
    }

    // ---- Tindak lanjut ----
    if ($c['next_action'] === TINDAKAN_RAGU) {
        $calon[] = calon(2, 9, 'Klien ragu melanjutkan — owner melihatnya di ringkasan', kapan(labelTempo($c['next_action_at'])),
                         [aksiLink('Buka →', 'klien.php?id=' . $id . '#langkah', true), $wa], [], [], 'ragu');
    } elseif ($c['next_action_at'] && $c['next_action_at'] <= $hariIni) {
        $calon[] = calon(2, 10, $c['next_action'] ?: stageNext($c['stage']), kapan(labelTempo($c['next_action_at']), $c['next_action_at'] < $hariIni),
                         [aksiLink('Buka →', 'klien.php?id=' . $id . '#langkah', true), $wa], [], [], 'tindak lanjut ' . labelTempo($c['next_action_at']),
                         max(0, -(int) hariKe($c['next_action_at'])));
    }
    if ($ctx['wa']) $calon[] = calonWa($ctx['wa']);
    if (!$c['next_action_at']) {
        $calon[] = calon(3, 15, 'Belum ada langkah berikutnya', kapan('tanpa tanggal'),
                         [aksiLink('Buka →', 'klien.php?id=' . $id . '#langkah', true), $wa], [], [], 'tanpa langkah');
    }
    // Calon "chip saja" (tier 9) tidak boleh jadi alasan utama.
    $utama = array_filter($calon, fn($x) => $x['tier'] < 9);
    return $utama ? $calon : [];
}

/* ============================================================
   TANGGAL TERISI — dipakai early (daftar) dan owner (grafik)
   ============================================================ */

function tanggalTerisi(): array
{
    return all("SELECT c.id, c.name, c.partner_name, c.wedding_date, c.wedding_time, c.stage,
                       COALESCE(NULLIF(wi.resepsi_lokasi, ''), NULLIF(wi.akad_lokasi, ''), NULLIF(c.venue, '')) AS lokasi
                  FROM clients c
                  LEFT JOIN client_wedding_info wi ON wi.client_id = c.id
                 WHERE c.stage IN ('dp','deal','persiapan','harih')
                   AND c.wedding_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                   AND c.wedding_date <  DATE_FORMAT(CURDATE() + INTERVAL 6 MONTH, '%Y-%m-01')
                 ORDER BY c.wedding_date, c.id
                 LIMIT 120");
}

/** Tanggal dengan lebih dari satu acara. @return array<string,int> */
function tanggalBentrok(array $rows): array
{
    $n = [];
    foreach ($rows as $r) $n[$r['wedding_date']] = ($n[$r['wedding_date']] ?? 0) + 1;
    return array_filter($n, fn($x) => $x > 1);
}

/* ============================================================
   OWNER — gambaran bisnis
   ============================================================ */

function ringkasanOwner(): array
{
    $h = ['galat' => []];
    $bagian = function (string $k, callable $f) use (&$h) {
        try { $h[$k] = $f(); }
        catch (Throwable $e) { $h[$k] = null; $h['galat'][$k] = true; error_log('ringkasanOwner ' . $k . ': ' . $e->getMessage()); }
    };
    $komit = "'deal','persiapan','harih','selesai'";

    $bagian('bulan', fn() => one("SELECT
        (SELECT COUNT(*) FROM clients WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS masuk,
        (SELECT COUNT(*) FROM clients WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND dari_form = 1) AS masuk_form,
        (SELECT COUNT(*) FROM clients
           WHERE created_at >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
             AND created_at <  CURDATE() - INTERVAL 1 MONTH + INTERVAL 1 DAY) AS masuk_lalu,
        (SELECT COUNT(*) FROM clients WHERE contract_signed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS dp_n,
        (SELECT COALESCE(SUM(deal_value), 0) FROM clients WHERE contract_signed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS dp_nilai,
        (SELECT COUNT(*) FROM clients
           WHERE contract_signed_at >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
             AND contract_signed_at <  CURDATE() - INTERVAL 1 MONTH + INTERVAL 1 DAY) AS dp_n_lalu,
        (SELECT COALESCE(SUM(jumlah), 0) FROM payment_receipts
           WHERE status = 'sah' AND tanggal >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS kas,
        (SELECT COUNT(DISTINCT client_id) FROM payment_receipts
           WHERE status = 'sah' AND tanggal >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS kas_klien,
        (SELECT COALESCE(SUM(jumlah), 0) FROM payment_receipts
           WHERE status = 'sah' AND tanggal >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
             AND tanggal <= CURDATE() - INTERVAL 1 MONTH) AS kas_lalu,
        (SELECT COALESCE(SUM(p.amount - p.terbayar), 0) FROM payments p JOIN clients c ON c.id = p.client_id
           WHERE p.paid_at IS NULL AND p.amount > p.terbayar AND p.due_date < CURDATE() AND c.stage IN ($komit)) AS lewat_rp,
        (SELECT COUNT(*) FROM payments p JOIN clients c ON c.id = p.client_id
           WHERE p.paid_at IS NULL AND p.amount > p.terbayar AND p.due_date < CURDATE() AND c.stage IN ($komit)) AS lewat_n,
        (SELECT MIN(p.due_date) FROM payments p JOIN clients c ON c.id = p.client_id
           WHERE p.paid_at IS NULL AND p.amount > p.terbayar AND p.due_date < CURDATE() AND c.stage IN ($komit)) AS lewat_tertua") ?: []);

    $bagian('keputusan', fn() => keputusanOwner());

    $bagian('tim', function () use ($komit) {
        $e = sqlTahap(TAHAP_EARLY); $o = sqlTahap(TAHAP_OFFICE);
        $r = one("SELECT
            SUM(c.stage IN ($e)) AS e_aktif,
            SUM(c.stage IN ($e) AND c.next_action_at < CURDATE()) AS e_lewat,
            SUM(c.stage IN ($e) AND c.next_action_at = CURDATE()) AS e_hari_ini,
            MIN(CASE WHEN c.stage IN ($e) AND c.next_action_at < CURDATE() THEN c.next_action_at END) AS e_tertua,
            SUM(c.stage = 'baru') AS n_baru,
            SUM(c.stage IN ('pricelist','spesifikasi','penawaran')) AS n_pricelist,
            SUM(c.stage = 'dp') AS n_dp,
            SUM(c.stage IN ($o)) AS o_aktif,
            SUM(c.stage IN ($o) AND c.next_action_at < CURDATE()) AS o_lewat,
            SUM(c.stage IN ($o) AND c.next_action_at = CURDATE()) AS o_hari_ini,
            MIN(CASE WHEN c.stage IN ($o) AND c.next_action_at < CURDATE() THEN c.next_action_at END) AS o_tertua,
            SUM(c.stage = 'deal') AS n_deal,
            SUM(c.stage = 'persiapan') AS n_persiapan,
            SUM(c.stage = 'harih') AS n_harih,
            SUM(c.stage = 'deal' AND c.data_lengkap_at IS NULL AND c.handover_at < NOW() - INTERVAL 14 DAY) AS o_dl_basi,
            (SELECT COUNT(*) FROM payments p JOIN clients c2 ON c2.id = p.client_id
               WHERE c2.stage = 'dp' AND p.paid_at IS NULL AND p.due_date < CURDATE()
                 AND p.id = (SELECT p3.id FROM payments p3 WHERE p3.client_id = c2.id
                              ORDER BY (p3.kode = 'dealing') DESC, p3.wajib DESC, p3.sort_order, p3.id LIMIT 1)) AS e_dp_lewat,
            (SELECT COUNT(*) FROM payments p JOIN clients c2 ON c2.id = p.client_id
               WHERE p.paid_at IS NULL AND p.amount > p.terbayar AND p.due_date < CURDATE()
                 AND c2.stage IN ($komit)) AS o_termin_lewat,
            (SELECT COUNT(*) FROM client_tasks t JOIN clients c3 ON c3.id = t.client_id
               WHERE t.done_at IS NULL AND t.due_date < CURDATE() AND c3.stage IN ($o)) AS o_tugas_lewat,
            (SELECT COUNT(*) FROM meetings m JOIN clients c4 ON c4.id = m.client_id
               WHERE m.status = 'scheduled' AND m.end_at < NOW() AND m.start_at >= CURDATE() - INTERVAL 30 DAY
                 AND c4.stage IN ($e)) AS e_temu_terbuka,
            (SELECT COUNT(*) FROM meetings m JOIN clients c4 ON c4.id = m.client_id
               WHERE m.status = 'scheduled' AND m.end_at < NOW() AND m.start_at >= CURDATE() - INTERVAL 30 DAY
                 AND c4.stage IN ($o)) AS o_temu_terbuka
          FROM clients c
         WHERE c.stage IN ($e, $o)") ?: [];
        $r['e_form'] = formPerluCekJumlah(30);
        return $r;
    });

    $bagian('minggu', fn() => all("SELECT * FROM (
          SELECT 'temu' AS jenis, m.id AS ref_id, m.client_id,
                 m.client_name AS nama, m.start_at AS waktu, m.end_at AS selesai, m.mode AS ket,
                 COALESCE(m.zoom_join_url, m.meet_url) AS tautan, NULLIF(m.location_text, '') AS lokasi,
                 COALESCE(NULLIF(c.phone, ''), m.client_phone) AS phone,
                 CASE WHEN c.stage IN ($komit) THEN 'office'
                      WHEN c.stage IS NOT NULL THEN 'early'
                      ELSE COALESCE(u.role, '') END AS tim,
                 NULL AS nilai, NULL AS n
            FROM meetings m
            LEFT JOIN clients c ON c.id = m.client_id
            LEFT JOIN users u   ON u.id = m.created_by
           WHERE m.status = 'scheduled' AND m.end_at >= NOW()
             AND m.start_at >= CURDATE() - INTERVAL 1 DAY
             AND m.start_at <  CURDATE() + INTERVAL 8 DAY
          UNION ALL
          SELECT 'termin', MIN(p.id), c.id,
                 TRIM(CONCAT(c.name, IF(c.partner_name <> '', CONCAT(' & ', c.partner_name), ''))),
                 CAST(p.due_date AS DATETIME), NULL, MIN(p.label), NULL, NULL, MIN(c.phone), 'office',
                 SUM(p.amount - p.terbayar), COUNT(*)
            FROM payments p
            JOIN clients c ON c.id = p.client_id
           WHERE p.paid_at IS NULL AND p.amount > p.terbayar
             AND p.due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY
             AND c.stage IN ($komit)
           GROUP BY c.id, c.name, c.partner_name, p.due_date
          UNION ALL
          SELECT 'hari_h', c.id, c.id,
                 TRIM(CONCAT(c.name, IF(c.partner_name <> '', CONCAT(' & ', c.partner_name), ''))),
                 CAST(CONCAT(c.wedding_date, ' ', COALESCE(c.wedding_time, '00:00:00')) AS DATETIME), NULL,
                 c.stage, NULL,
                 COALESCE(NULLIF(wi.resepsi_lokasi, ''), NULLIF(wi.akad_lokasi, ''), NULLIF(c.venue, '')),
                 c.phone, 'office',
                 (SELECT COUNT(*) FROM client_tasks t WHERE t.client_id = c.id AND t.done_at IS NULL AND t.due_date < CURDATE()),
                 NULL
            FROM clients c
            LEFT JOIN client_wedding_info wi ON wi.client_id = c.id
           WHERE c.stage IN ('deal','persiapan','harih')
             AND c.wedding_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY
        ) a
        ORDER BY waktu, FIELD(jenis, 'hari_h', 'temu', 'termin')
        LIMIT 40"));

    $bagian('kas', fn() => kasOwner());
    $bagian('tanggal', fn() => tanggalTerisi());
    $bagian('corong', fn() => corongOwner());
    $bagian('perhatian', fn() => perhatianOwner());
    return $h;
}

/**
 * Antrean owner sendiri: hanya yang butuh keputusannya, atau yang sudah
 * lolos dari antrean tim. Satu baris per klien (alasan paling berat menang).
 */
function keputusanOwner(): array
{
    $rows = all("SELECT * FROM (
      SELECT 1 AS urut, 'tawar' AS jenis, q.id AS ref_id, q.client_id,
             TRIM(CONCAT(c.name, IF(c.partner_name <> '', CONCAT(' & ', c.partner_name), ''))) AS nama,
             c.phone, c.stage, q.nomor AS judul, q.nego_catatan AS info,
             DATE(q.nego_at) AS tanggal, DATEDIFF(CURDATE(), DATE(q.nego_at)) AS hari,
             q.total AS nilai_a, q.nego_nilai AS nilai_b
        FROM quotes q JOIN clients c ON c.id = q.client_id
       WHERE q.nego_nilai IS NOT NULL AND q.status = 'terkirim'
         AND c.stage IN ('baru','pricelist','spesifikasi','penawaran')
      UNION ALL
      SELECT 2, 'ragu', c.id, c.id,
             TRIM(CONCAT(c.name, IF(c.partner_name <> '', CONCAT(' & ', c.partner_name), ''))),
             c.phone, c.stage,
             (SELECT m.title FROM meetings m WHERE m.client_id = c.id AND m.outcome = 'batal' ORDER BY m.start_at DESC LIMIT 1),
             (SELECT m.outcome_note FROM meetings m WHERE m.client_id = c.id AND m.outcome = 'batal' ORDER BY m.start_at DESC LIMIT 1),
             c.next_action_at, DATEDIFF(CURDATE(), c.next_action_at), c.deal_value,
             (SELECT COALESCE(SUM(p.terbayar), 0) FROM payments p WHERE p.client_id = c.id)
        FROM clients c
       WHERE c.stage IN ('deal','persiapan','harih') AND c.next_action = ?
      UNION ALL
      SELECT 3, 'dp', p.id, c.id,
             TRIM(CONCAT(c.name, IF(c.partner_name <> '', CONCAT(' & ', c.partner_name), ''))),
             c.phone, c.stage, p.label, DATE_FORMAT(c.wedding_date, '%Y-%m-%d'),
             p.due_date, DATEDIFF(CURDATE(), p.due_date), p.amount - p.terbayar, c.deal_value
        FROM clients c
        JOIN payments p ON p.id = (SELECT p2.id FROM payments p2 WHERE p2.client_id = c.id
                                    ORDER BY (p2.kode = 'dealing') DESC, p2.wajib DESC, p2.sort_order, p2.id LIMIT 1)
       WHERE c.stage = 'dp' AND p.paid_at IS NULL AND p.due_date < CURDATE() - INTERVAL 3 DAY
      UNION ALL
      SELECT 4, 'termin', p.id, c.id,
             TRIM(CONCAT(c.name, IF(c.partner_name <> '', CONCAT(' & ', c.partner_name), ''))),
             c.phone, c.stage, p.label, p.ingat_kode,
             p.due_date, DATEDIFF(CURDATE(), p.due_date), p.amount - p.terbayar, NULL
        FROM payments p JOIN clients c ON c.id = p.client_id
       WHERE p.paid_at IS NULL AND p.amount > p.terbayar
         AND p.due_date < CURDATE() - INTERVAL 7 DAY
         AND c.stage IN ('deal','persiapan','harih','selesai')
      UNION ALL
      SELECT 5, 'temu', m.id, m.client_id, m.client_name, m.client_phone, COALESCE(c.stage, ''),
             m.title, COALESCE(u.name, ''),
             DATE(m.start_at), DATEDIFF(CURDATE(), DATE(m.start_at)), NULL, NULL
        FROM meetings m
        LEFT JOIN clients c ON c.id = m.client_id
        LEFT JOIN users u   ON u.id = m.created_by
       WHERE m.status = 'scheduled' AND m.end_at < NOW() - INTERVAL 3 DAY
      UNION ALL
      SELECT 6, 'formulir', f.id, NULL, f.nama, f.wa, '', f.paket, f.pesan,
             DATE(f.created_at), DATEDIFF(CURDATE(), DATE(f.created_at)), NULL, NULL
        FROM form_masuk f
       WHERE f.status IN ('galat','ditolak') AND f.client_id IS NULL AND f.ditangani = 0
         AND CHAR_LENGTH(f.wa) >= 10
         AND f.created_at <  NOW() - INTERVAL 24 HOUR
         AND f.created_at >  NOW() - INTERVAL 30 DAY
         AND NOT EXISTS (SELECT 1 FROM form_masuk g WHERE g.wa = f.wa AND g.client_id IS NOT NULL AND g.id > f.id)
      UNION ALL
      SELECT 7, 'klien', c.id, c.id,
             TRIM(CONCAT(c.name, IF(c.partner_name <> '', CONCAT(' & ', c.partner_name), ''))),
             c.phone, c.stage, c.next_action, NULL,
             c.next_action_at, DATEDIFF(CURDATE(), c.next_action_at), NULL, NULL
        FROM clients c
       WHERE c.stage IN ('baru','pricelist','dp','spesifikasi','penawaran','deal','persiapan','harih')
         AND c.next_action_at < CURDATE() - INTERVAL 2 DAY
    ) k
    ORDER BY urut, hari DESC
    LIMIT 40", [TINDAKAN_RAGU]);

    $gateway = waSiap();
    $out = [];
    foreach ($rows as $r) {
        $kunci = match (true) {
            $r['jenis'] === 'formulir' => 'f' . $r['ref_id'],
            $r['jenis'] === 'temu' && !$r['client_id'] => 'm' . $r['ref_id'],
            default => 'c' . $r['client_id'],
        };
        $alasan = alasanKeputusan($r);
        if (isset($out[$kunci])) {
            if (count($out[$kunci]['chip']) < 3) $out[$kunci]['chip'][] = $alasan['chip'];
            continue;
        }
        $hari = max(0, (int) $r['hari']);
        $tim = $r['jenis'] === 'formulir' ? 'admin early' : ($r['stage'] === '' ? '' : (stageSudahDeal((string) $r['stage']) ? 'admin office' : 'admin early'));
        $chip = [];
        if ($hari >= 7) $chip[] = ['teks' => 'macet ' . $hari . ' hari', 'nada' => 'rose'];
        if ($tim !== '') $chip[] = $tim;
        $out[$kunci] = [
            'kunci' => $kunci, 'tier' => 1, 'nama' => trim((string) $r['nama']) ?: 'Tanpa nama',
            'href' => match ($r['jenis']) { 'formulir' => 'formulir.php', 'temu' => 'jadwal.php?edit=' . (int) $r['ref_id'],
                                            default => 'klien.php?id=' . (int) $r['client_id'] },
            'alasan' => $alasan['teks'], 'kapan' => kapan($r['tanggal'] ? labelLalu($r['tanggal']) : '', $hari > 0),
            'chip' => $chip, 'aksi' => array_values(array_filter(aksiKeputusan($r, $alasan, $gateway))), 'lain' => [],
            'tunda' => null, 'saring' => [], 'telat' => $hari, 'form' => 0, 'dibuat' => '', 'titik' => null,
        ];
    }
    return array_values($out);
}

/** Kalimat alasan untuk satu baris keputusan owner. */
function alasanKeputusan(array $r): array
{
    $info = trim((string) $r['info']);
    return match ($r['jenis']) {
        'tawar' => ['teks' => 'Klien menawar ' . rupiah((float) $r['nilai_b'], true) . ' dari ' . rupiah((float) $r['nilai_a'], true)
                       . ((float) $r['nilai_a'] > 0 ? ' (−' . round((1 - (float) $r['nilai_b'] / (float) $r['nilai_a']) * 100) . '%)' : '')
                       . ($info !== '' ? ' · ' . $info : ''), 'chip' => 'menawar'],
        'ragu'  => ['teks' => 'Ragu melanjutkan' . ($r['judul'] ? ' setelah ' . mb_strtolower((string) $r['judul']) : '')
                       . ' · kontrak ' . rupiah((float) $r['nilai_a'], true) . ', sudah dibayar ' . rupiah((float) $r['nilai_b'], true)
                       . ($info !== '' ? ' · ' . $info : ''), 'chip' => 'ragu'],
        'dp'    => ['teks' => ($r['judul'] ?: 'DP') . ' ' . rupiah((float) $r['nilai_a'], true) . ' lewat ' . (int) $r['hari'] . ' hari'
                       . ($info !== '' ? ' · tanggal ' . tanggalID($info) . ' masih ditahan' : ''), 'chip' => 'DP lewat tempo'],
        'termin'=> ['teks' => $r['judul'] . ' ' . rupiah((float) $r['nilai_a'], true) . ' lewat ' . (int) $r['hari'] . ' hari'
                       . (str_starts_with($info, 'telat@') ? ' · pengingat otomatis sudah terkirim' : ''), 'chip' => 'termin lewat tempo'],
        'temu'  => ['teks' => 'Pertemuan ' . tglPendek($r['tanggal']) . ' belum ditutup' . ($info !== '' ? ' · dibuat oleh ' . $info : ''),
                    'chip' => 'pertemuan belum ditutup'],
        'formulir' => ['teks' => 'Kiriman formulir gagal ' . (int) $r['hari'] . ' hari lalu belum ditangani · ' . ($info ?: 'perlu dicek'),
                       'chip' => 'formulir'],
        default => ['teks' => 'Tindak lanjut lewat ' . (int) $r['hari'] . ' hari · ' . ($r['judul'] ?: stageNext((string) $r['stage'])),
                    'chip' => 'tindak lanjut lewat ' . (int) $r['hari'] . ' hari'],
    };
}

function aksiKeputusan(array $r, array $alasan, bool $gateway): array
{
    $cid = (int) $r['client_id'];
    $tanya = function () use ($r, $alasan): ?array {
        $n = waAdminTim((string) $r['stage']);
        if ($n === '') return null;
        return ['jenis' => 'wa', 'label' => 'Tanya ' . (stageSudahDeal((string) $r['stage']) ? 'admin office' : 'admin early') . ' ↗',
                'href' => 'https://wa.me/' . $n . '?text=' . rawurlencode('Halo, soal ' . $r['nama'] . ': ' . $alasan['teks'] . '. Bagaimana perkembangannya?'),
                'utama' => false];
    };
    $buka = aksiLink('Buka klien →', 'klien.php?id=' . $cid);
    return match ($r['jenis']) {
        'tawar'  => [aksiLink('Buka price list →', 'penawaran.php?id=' . (int) $r['ref_id'], true), aksiWa($r['phone'])],
        'ragu'   => [aksiPost('Sudah dibahas ✓', 'klien.php', ['act' => 'nextaction', 'id' => $cid, 'next_action' => stageNext((string) $r['stage']),
                                                               'next_action_at' => date('Y-m-d', strtotime('+3 day'))], null, true),
                     aksiWa($r['phone']), $buka],
        'dp'     => [$tanya(), $buka],
        'termin' => [$gateway ? aksiPost('Kirim tagihan', 'klien.php', ['act' => 'pay_kirim_tagihan', 'id' => $cid, 'payment_id' => (int) $r['ref_id'], 'jenis' => 'telat'])
                              : aksiWa($r['phone'], bayarTeksTagihan(['id' => $cid, 'name' => explode(' & ', (string) $r['nama'])[0], 'stage' => $r['stage']],
                                       [['label' => $r['judul'], 'amount' => $r['nilai_a'], 'terbayar' => 0, 'due_date' => $r['tanggal']]], 'telat'), 'Tagih via WA ↗'),
                     $tanya(), $buka],
        'temu'   => array_merge(aksiHasilTemu(['id' => $r['ref_id'], 'client_id' => $r['client_id']])[0],
                                $r['client_id'] ? [aksiPost('Tutup tanpa hasil', 'jadwal.php', ['act' => 'done', 'id' => (int) $r['ref_id']], 'Tutup pertemuan ini tanpa mencatat hasil?')] : []),
        'formulir' => [aksiPost('Jadikan klien', 'formulir.php', ['act' => 'jadikan', 'id' => (int) $r['ref_id']], null, true),
                       aksiWa($r['phone'], teksBalasFormulir(['nama' => $r['nama'], 'paket' => $r['judul']])),
                       aksiPost('Abaikan', 'formulir.php', ['act' => 'abaikan', 'id' => (int) $r['ref_id']])],
        default  => [aksiLink('Buka →', 'klien.php?id=' . $cid . '#langkah', true), $tanya()],
    };
}

/** Arus kas: 6 bulan ke belakang diterima, 6 bulan ke depan dijadwalkan. */
function kasOwner(): array
{
    $rows = all("SELECT 'masuk' AS jenis, DATE_FORMAT(r.tanggal, '%Y-%m') AS bln,
                        SUM(r.jumlah) AS rp, COUNT(DISTINCT r.client_id) AS n, NULL AS tertua
                   FROM payment_receipts r
                  WHERE r.status = 'sah'
                    AND r.tanggal >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH, '%Y-%m-01')
                  GROUP BY DATE_FORMAT(r.tanggal, '%Y-%m')
                 UNION ALL
                 SELECT 'jadwal',
                        CASE WHEN p.due_date IS NULL THEN 'tanpa'
                             WHEN p.due_date < CURDATE() THEN 'lewat'
                             WHEN p.due_date >= DATE_FORMAT(CURDATE() + INTERVAL 7 MONTH, '%Y-%m-01') THEN 'nanti'
                             ELSE DATE_FORMAT(p.due_date, '%Y-%m') END,
                        SUM(p.amount - p.terbayar), COUNT(*), MIN(p.due_date)
                   FROM payments p
                   JOIN clients c ON c.id = p.client_id
                  WHERE p.paid_at IS NULL AND p.amount > p.terbayar
                    AND c.stage IN ('deal','persiapan','harih','selesai')
                  GROUP BY CASE WHEN p.due_date IS NULL THEN 'tanpa'
                                WHEN p.due_date < CURDATE() THEN 'lewat'
                                WHEN p.due_date >= DATE_FORMAT(CURDATE() + INTERVAL 7 MONTH, '%Y-%m-01') THEN 'nanti'
                                ELSE DATE_FORMAT(p.due_date, '%Y-%m') END
                 UNION ALL
                 SELECT 'dp', 'dp', COALESCE(SUM(p.amount - p.terbayar), 0), COUNT(*), MIN(p.due_date)
                   FROM clients c
                   JOIN payments p ON p.id = (SELECT p2.id FROM payments p2 WHERE p2.client_id = c.id
                                               ORDER BY (p2.kode = 'dealing') DESC, p2.wajib DESC, p2.sort_order, p2.id LIMIT 1)
                  WHERE c.stage = 'dp' AND p.paid_at IS NULL");
    $slot = [];
    $awal = strtotime(date('Y-m-01') . ' -5 month');
    for ($i = 0; $i < 12; $i++) {
        $t = strtotime('+' . $i . ' month', $awal);
        $slot[date('Y-m', $t)] = ['bln' => date('Y-m', $t), 'label' => BULAN_PENDEK[(int) date('n', $t)],
                                  'tahun' => date('Y', $t), 'masuk' => 0.0, 'jadwal' => 0.0, 'nMasuk' => 0, 'nJadwal' => 0,
                                  'kini' => date('Y-m', $t) === date('Y-m'), 'depan' => date('Y-m', $t) > date('Y-m')];
    }
    $fakta = ['lewat' => null, 'tanpa' => null, 'nanti' => null, 'dp' => null];
    foreach ($rows as $r) {
        if ($r['jenis'] === 'masuk' && isset($slot[$r['bln']])) { $slot[$r['bln']]['masuk'] = (float) $r['rp']; $slot[$r['bln']]['nMasuk'] = (int) $r['n']; }
        elseif ($r['jenis'] === 'jadwal' && isset($slot[$r['bln']])) { $slot[$r['bln']]['jadwal'] = (float) $r['rp']; $slot[$r['bln']]['nJadwal'] = (int) $r['n']; }
        elseif (array_key_exists($r['bln'], $fakta)) $fakta[$r['bln']] = ['rp' => (float) $r['rp'], 'n' => (int) $r['n'], 'tertua' => $r['tertua']];
    }
    return ['slot' => array_values($slot), 'fakta' => $fakta];
}

/** Corong 12 bulan: tingkat tertinggi yang dicapai tiap prospek + kecepatan price list. */
function corongOwner(): array
{
    $r = one("SELECT
          COUNT(*) AS prospek,
          SUM(x.lv >= 1) AS pl, SUM(x.lv >= 2) AS cocok, SUM(x.lv >= 3) AS dp,
          SUM(x.stage = 'selesai') AS selesai,
          SUM(x.lv = 0 AND x.stage =  'batal') AS gugur0, SUM(x.lv = 0 AND x.stage <> 'batal') AS aktif0,
          SUM(x.lv = 1 AND x.stage =  'batal') AS gugur1, SUM(x.lv = 1 AND x.stage <> 'batal') AS aktif1,
          SUM(x.lv = 2 AND x.stage =  'batal') AS gugur2, SUM(x.lv = 2 AND x.stage <> 'batal') AS aktif2,
          SUM(x.lv = 3 AND x.stage =  'batal') AS gugur3, SUM(x.lv = 3 AND x.stage NOT IN ('batal','selesai')) AS aktif3,
          SUM(x.f90 = 1 AND x.tua24 = 1) AS form_n24,
          SUM(x.f90 = 1 AND x.tua24 = 1 AND x.menit_pl <= 1440) AS form_cepat24,
          GROUP_CONCAT(CASE WHEN x.f90 = 1 THEN x.menit_pl END) AS menit_pl_list
        FROM (
          SELECT c.stage,
                 CASE
                   WHEN c.contract_signed_at IS NOT NULL OR c.stage IN ('deal','persiapan','harih','selesai') THEN 3
                   WHEN c.stage = 'dp' OR (c.stage = 'batal' AND c.stage_batal = 'deal')
                        OR EXISTS (SELECT 1 FROM quotes q WHERE q.client_id = c.id AND q.status = 'cocok') THEN 2
                   WHEN c.pl_sent_at IS NOT NULL OR c.stage IN ('pricelist','spesifikasi','penawaran')
                        OR EXISTS (SELECT 1 FROM quotes q WHERE q.client_id = c.id AND q.sent_at IS NOT NULL) THEN 1
                   ELSE 0
                 END AS lv,
                 (c.dari_form = 1 AND c.created_at >= NOW() - INTERVAL 90 DAY) AS f90,
                 (c.created_at < NOW() - INTERVAL 24 HOUR) AS tua24,
                 CASE WHEN c.pl_sent_at >= c.created_at THEN TIMESTAMPDIFF(MINUTE, c.created_at, c.pl_sent_at) END AS menit_pl
            FROM clients c
           WHERE c.created_at >= CURDATE() - INTERVAL 12 MONTH
        ) x") ?: [];
    $menit = array_map('intval', array_filter(explode(',', (string) ($r['menit_pl_list'] ?? '')), 'strlen'));
    $r['median'] = count($menit) >= 3 ? median($menit) : null;
    $r['n_median'] = count($menit);
    return $r;
}

/** Peringatan pengaturan & kesehatan situs. Kosong = tidak ada yang perlu. */
function perhatianOwner(): array
{
    // Batas per cabang, bukan untuk seluruh UNION: kalau tidak, 30 artikel
    // ber-SEO rendah menggeser hitungan galat formulir sampai hilang.
    $rows = all("(SELECT 'sinkron' AS jenis, m.id, m.client_name AS judul, m.sync_error AS ket, NULL AS n
                    FROM meetings m WHERE m.sync_error IS NOT NULL AND m.status = 'scheduled' ORDER BY m.start_at LIMIT 10)
                 UNION ALL
                 (SELECT 'seo', p.id, p.title, CONCAT(p.seo_score, '/100'), NULL
                    FROM posts p WHERE p.status = 'published' AND p.seo_score < 70 ORDER BY p.seo_score LIMIT 5)
                 UNION ALL
                 (SELECT 'paket', t.id, t.nama, 'tampil di situs tanpa harga', NULL
                    FROM quote_templates t WHERE t.is_active = 1 AND t.tampil_web = 1 AND t.harga IS NULL LIMIT 10)
                 UNION ALL
                 (SELECT 'form', NULL, f.status, NULL, COUNT(*)
                    FROM form_masuk f WHERE f.created_at >= NOW() - INTERVAL 30 DAY
                   GROUP BY f.status)
                 UNION ALL
                 (SELECT 'analisa', NULL, NULL, NULL, COUNT(*)
                    FROM clients c
                   WHERE c.stage IN ('batal','selesai')
                     AND NOT EXISTS (SELECT 1 FROM client_analisa a WHERE a.client_id = c.id))");
    $out = ['sinkron' => [], 'seo' => [], 'paket' => [], 'form' => [], 'analisa' => 0];
    foreach ($rows as $r) {
        if ($r['jenis'] === 'form') $out['form'][(string) $r['judul']] = (int) $r['n'];
        elseif ($r['jenis'] === 'analisa') $out['analisa'] = (int) $r['n'];
        else $out[$r['jenis']][] = $r;
    }
    return $out;
}
