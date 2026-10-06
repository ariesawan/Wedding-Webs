<?php
/**
 * ============================================================
 * PIPELINE KLIEN — dari prospek masuk sampai hari-H selesai
 * ============================================================
 *
 * Masalah yang dipecahkan: jadwal meeting saja tidak menjawab "lalu apa".
 * Modul ini memberi setiap klien satu tahap yang jelas dan satu tindakan
 * berikutnya yang punya tanggal. Kalau tindakan itu lewat tanggalnya,
 * klien muncul di daftar "perlu ditindaklanjuti" — itulah mekanisme yang
 * mencegah prospek hilang begitu saja.
 *
 * Alur yang disepakati owner (Oktober 2026), dua pegangan:
 *
 *   ADMIN EARLY
 *   baru       isi biodata awal, kirim price list (paket)  → pricelist  (otomatis saat terkirim)
 *   pricelist  klien cocok                                → dp         (termin disusun, DP ditagih)
 *   dp         DP 30% diterima                            → deal       (serah ke admin office)
 *
 *   ADMIN OFFICE
 *   deal       biodata lengkap & keluarga, dekor, venue,
 *              vendor, termin, meeting                    → persiapan  (checklist dibuat)
 *   persiapan  H-7                                        → hari-H     (cron)
 *   hari-H     acara lewat                                → selesai    (cron)
 *
 * Alur gagal:
 *   dari tahap mana pun → batal (wajib isi alasan, supaya bisa dievaluasi)
 *
 * Tahap 'spesifikasi' dan 'penawaran' dari versi sebelumnya masih dikenal
 * (untuk data lama) tapi tidak lagi ada di jalur: penggalian spesifikasi
 * kini dikerjakan admin office SETELAH DP.
 */

const PIPE_STAGES = [
    'baru' => [
        'label' => 'Prospek baru',
        'desc'  => 'Masuk dari DM, WhatsApp, atau formulir. Isi biodata awal, lalu kirim price list.',
        'next'  => 'Lengkapi biodata awal & kirim price list',
        'sla'   => 1,
    ],
    'pricelist' => [
        'label' => 'Price list terkirim',
        'desc'  => 'Price list sudah dikirim, menunggu jawaban cocok atau tidak.',
        'next'  => 'Tanyakan tanggapan price list',
        'sla'   => 3,
    ],
    'dp' => [
        'label' => 'Menunggu DP',
        'desc'  => 'Klien cocok. DP 30% sudah ditagih — begitu masuk, klien diserahkan ke admin office.',
        'next'  => 'Tagih DP 30% & konfirmasi transfer',
        'sla'   => 3,
    ],
    'deal' => [
        'label' => 'Deal · penyusunan',
        'desc'  => 'DP masuk, dipegang admin office: biodata lengkap & keluarga, dekor, venue, vendor, termin, dan meeting.',
        'next'  => 'Lengkapi biodata & keluarga, susun dekor dan venue',
        'sla'   => 7,
    ],
    'persiapan' => [
        'label' => 'Persiapan',
        'desc'  => 'Checklist berjalan, vendor dikunci, termin ditagih sesuai jadwal.',
        'next'  => 'Kerjakan langkah checklist terdekat',
        'sla'   => 7,
    ],
    'harih' => [
        'label' => 'Hari-H',
        'desc'  => 'Minggu terakhir sampai hari pelaksanaan.',
        'next'  => 'Technical meeting dan gladi',
        'sla'   => 1,
    ],
    'selesai' => [
        'label' => 'Selesai',
        'desc'  => 'Acara sudah berjalan. Tinggal evaluasi dan testimoni.',
        'next'  => 'Catat analisa keberhasilan',
        'sla'   => 7,
    ],
    'batal' => [
        'label' => 'Batal',
        'desc'  => 'Tidak berlanjut. Sebabnya dicatat di menu Analisa.',
        'next'  => '',
        'sla'   => 99,
    ],
    // Tahap lama — hanya untuk membaca data sebelum v23.
    'spesifikasi' => [
        'label' => 'Spesifikasi (lama)',
        'desc'  => 'Tahap versi lama. Pindahkan ke Price list atau Menunggu DP.',
        'next'  => 'Pindahkan ke tahap yang sesuai',
        'sla'   => 1,
    ],
    'penawaran' => [
        'label' => 'Penawaran (lama)',
        'desc'  => 'Tahap versi lama. Pindahkan ke Price list atau Menunggu DP.',
        'next'  => 'Pindahkan ke tahap yang sesuai',
        'sla'   => 1,
    ],
];

/** Tahap yang masih berjalan, berurutan — dipakai papan pipeline. */
const PIPE_ACTIVE = ['baru', 'pricelist', 'dp', 'deal', 'persiapan', 'harih'];

/** Pegangan per peran. Tahap lama ikut admin early karena belum DP. */
const TAHAP_EARLY  = ['baru', 'pricelist', 'dp', 'spesifikasi', 'penawaran'];
const TAHAP_OFFICE = ['deal', 'persiapan', 'harih'];

/**
 * Tindakan yang dipasang saat klien yang sudah DP menjawab "tidak lanjut" di
 * pertemuan. Teksnya dipakai sebagai penanda di Ringkasan owner — kalau diubah
 * tangan, artinya sudah ditangani dan barisnya hilang dengan sendirinya.
 */
const TINDAKAN_RAGU = 'Klien ragu melanjutkan — bahas dengan owner';

/** Tahap yang boleh dipilih di "Ubah tahap manual". */
const TAHAP_PILIHAN = ['baru', 'pricelist', 'dp', 'deal', 'persiapan', 'harih', 'selesai', 'batal'];

function stageLabel(string $s): string { return PIPE_STAGES[$s]['label'] ?? $s; }
function stageNext(string $s): string  { return PIPE_STAGES[$s]['next']  ?? ''; }
function stageSla(string $s): int      { return PIPE_STAGES[$s]['sla']   ?? 3; }

/** Posisi tahap di jalur normal. Selesai paling ujung, batal di luar jalur. */
function stageUrut(string $s): int
{
    if ($s === 'selesai') return count(PIPE_ACTIVE);
    $i = array_search($s, PIPE_ACTIVE, true);
    return $i === false ? -1 : $i;
}

/** Tahap yang sudah melewati deal — dipegang admin office. */
function stageSudahDeal(string $s): bool
{
    return in_array($s, ['deal', 'persiapan', 'harih', 'selesai'], true);
}

/**
 * Titik gugur untuk menu Analisa. Nilainya harus salah satu momen di
 * client_analisa: pricelist, penawaran, deal, pascaacara.
 *
 * Sebelumnya apa pun selain 'pricelist' dianggap 'penawaran' — prospek yang
 * mundur sebelum menerima apa-apa ikut tercatat "gugur setelah penawaran",
 * dan laporan analisa jadi menyalahkan harga untuk klien yang belum pernah
 * melihat harga.
 */
function momenGugur(string $stage): string
{
    // 'dp' dihitung gugur di titik deal: klien sudah cocok dengan harga,
    // yang gagal adalah komitmennya — sebab yang perlu dianalisa berbeda.
    return match ($stage) {
        'baru', 'pricelist'              => 'pricelist',
        'spesifikasi', 'penawaran'       => 'penawaran',
        'dp', 'deal', 'persiapan', 'harih' => 'deal',
        'selesai'                        => 'pascaacara',
        default                          => 'penawaran',
    };
}

/**
 * Majukan tahap HANYA kalau tujuannya ada di depan tahap sekarang.
 *
 * Dipakai oleh perpindahan otomatis (penawaran terkirim, pertemuan
 * dijadwalkan). Tanpa penjagaan ini, mengirim price list ke klien yang
 * sudah deal — misalnya untuk tambahan paket — menarik kliennya mundur ke
 * tahap Price list dan melepasnya dari admin office.
 */
function clientMajuKe(int $id, string $stage, ?int $userId = null, string $note = ''): array
{
    $c = one("SELECT stage FROM clients WHERE id = ?", [$id]);
    if (!$c || in_array($c['stage'], ['batal', 'selesai'], true)) return ['changed' => false, 'info' => []];
    if (stageUrut($stage) <= stageUrut($c['stage'])) return ['changed' => false, 'info' => []];
    return clientSetStage($id, $stage, $userId, $note);
}

/**
 * Checklist bawaan, dihitung mundur dari tanggal pernikahan.
 * Angka negatif = berapa hari sebelum hari-H.
 */
const TASK_TEMPLATE = [
    [-90, 'Kontrak ditandatangani kedua pihak',        'Simpan salinan PDF-nya.'],
    [-90, 'DP diterima dan dicatat',                   'Konfirmasi ke klien setelah dana masuk.'],
    [-75, 'Kunci daftar vendor',                       'Dekor, katering, dokumentasi, rias, hiburan.'],
    [-60, 'Survei lokasi bersama klien',               'Ukur area, cek akses kendaraan, titik listrik.'],
    [-45, 'Draf rundown versi pertama',                'Kirim ke klien untuk dikoreksi.'],
    [-30, 'Technical meeting dengan semua vendor',     'Samakan jam masuk, area kerja, dan PIC.'],
    [-30, 'Rundown dikunci',                           'Setelah ini perubahan hanya untuk hal mendesak.'],
    [-21, 'Susunan kru dan pembagian pos',             'Tentukan koordinator lapangan.'],
    [-14, 'Pelunasan ditagih',                         'Sesuai termin di kontrak.'],
    [-14, 'Konfirmasi ulang seluruh vendor',           'Telepon satu per satu, jangan hanya chat.'],
    [-7,  'Gladi bersih',                              'Terutama untuk prosesi adat dan urutan masuk.'],
    [-3,  'Cek cuaca dan siapkan rencana cadangan',    'Terpal, tenda tambahan, atau pemindahan area.'],
    [-1,  'Briefing kru terakhir',                     'Bagikan rundown cetak dan nomor darurat.'],
    [-1,  'Serah terima barang klien',                 'Seserahan, mahar, buku tamu, souvenir.'],
    [0,   'Hari-H — eksekusi',                         'Kru pertama tiba sesuai crew call.'],
    [1,   'Bereskan dan kembalikan barang klien',      'Cek daftar serah terima.'],
    [3,   'Minta testimoni dan izin pakai foto',       'Selagi kesannya masih hangat.'],
];

/** Termin pembayaran bawaan berdasarkan nilai kontrak dan tanggal acara. */
function paymentTemplate(float $deal, ?string $weddingDate, int $dpPercent = 30): array
{
    $dp    = round($deal * $dpPercent / 100);
    $sisa  = $deal - $dp;
    $term2 = round($sisa / 2);
    $term3 = $sisa - $term2;

    $due = function (int $offset) use ($weddingDate): ?string {
        if (!$weddingDate) return null;
        return date('Y-m-d', strtotime($weddingDate . " $offset day"));
    };

    return [
        ['label' => "DP $dpPercent%",       'amount' => $dp,    'due_date' => date('Y-m-d', strtotime('+7 day')), 'sort_order' => 10],
        ['label' => 'Termin 2',             'amount' => $term2, 'due_date' => $due(-60), 'sort_order' => 20],
        ['label' => 'Pelunasan',            'amount' => $term3, 'due_date' => $due(-14), 'sort_order' => 30],
    ];
}

/**
 * Termin untuk satu nilai kontrak, dari tabel payment_templates.
 *
 * Inilah termin yang SAMA dengan yang tercantum di teks penawaran yang
 * dikirim ke klien (quoteTeksWA membaca tabel yang sama). Dulu deal memakai
 * paymentTemplate() di atas — DP 30% + dua termin H-60/H-14 yang ditulis
 * mati di kode — sementara penawaran menjanjikan Dealing 30% / H-60 20% /
 * H-30 30% / H-7 20%. Klien menerima satu jadwal, panel menagih jadwal lain.
 *
 * paymentTemplate() tetap dipakai sebagai cadangan kalau tabelnya kosong.
 */
function terminKlien(float $total, ?string $weddingDate): array
{
    $tpl = [];
    try { $tpl = all("SELECT * FROM payment_templates WHERE is_active = 1 ORDER BY urutan, id"); }
    catch (Throwable $e) { $tpl = []; }

    // Termin "saat tanda tangan" ditagih tiga hari lagi. Termin lain tidak
    // boleh jatuh tempo sebelum itu: kalau acaranya tinggal sebulan, termin
    // H-60 langsung tercatat "terlambat" begitu deal, padahal klien baru
    // saja setuju.
    $tenggatDp = max(1, (int) setting('dp_tenggat_hari', '3'));
    $paling_awal = date('Y-m-d', strtotime("+$tenggatDp day"));

    if (!$tpl) {
        $out = [];
        foreach (paymentTemplate($total, $weddingDate, (int) setting('dp_percent', '30')) as $t) {
            $out[] = $t + ['kode' => '', 'persen' => null, 'wajib' => 0];
        }
        return $out;
    }

    $jumlahPersen = array_sum(array_map(fn($t) => (float) $t['persen'], $tpl));
    $genap = abs($jumlahPersen - 100) < 0.01;
    $out = []; $terpakai = 0.0; $n = count($tpl);

    foreach ($tpl as $i => $t) {
        $amount = round($total * (float) $t['persen'] / 100);
        // Pembulatan per baris bisa membuat jumlahnya meleset beberapa rupiah
        // dari nilai kontrak. Baris terakhir menampung selisihnya — asal
        // persentasenya memang genap 100.
        if ($genap && $i === $n - 1) $amount = $total - $terpakai;
        $terpakai += $amount;

        if ($t['offset_hari'] === null) {
            $due = $paling_awal;
        } elseif ($weddingDate) {
            $due = date('Y-m-d', strtotime($weddingDate . ' -' . (int) $t['offset_hari'] . ' day'));
            if ($due < $paling_awal) $due = $paling_awal;
        } else {
            $due = null;
        }

        $out[] = [
            'kode'        => (string) $t['kode'],
            'label'       => $t['label'],
            'persen'      => (float) $t['persen'],
            'amount'      => $amount,
            'due_date'    => $due,
            'offset_hari' => $t['offset_hari'] === null ? null : (int) $t['offset_hari'],
            'wajib'       => (int) $t['wajib'],
            'sort_order'  => ($i + 1) * 10,
        ];
    }
    return $out;
}

/** Susun termin klien dari template. Hanya kalau belum ada satu pun. */
function terminSusun(int $clientId): int
{
    $c = one("SELECT deal_value, wedding_date FROM clients WHERE id = ?", [$clientId]);
    if (!$c || (float) $c['deal_value'] <= 0) return 0;
    if ((int) (one("SELECT COUNT(*) n FROM payments WHERE client_id = ?", [$clientId])['n'] ?? 0)) return 0;

    $n = 0;
    foreach (terminKlien((float) $c['deal_value'], $c['wedding_date']) as $t) {
        q("INSERT INTO payments (client_id, kode, label, amount, due_date, offset_hari, sort_order, persen, wajib)
           VALUES (?,?,?,?,?,?,?,?,?)",
          [$clientId, $t['kode'], $t['label'], $t['amount'], $t['due_date'], $t['offset_hari'] ?? null,
           $t['sort_order'], $t['persen'], $t['wajib']]);
        $n++;
    }
    return $n;
}

/**
 * Tanggal langkah checklist: dihitung mundur dari hari-H, tapi tidak pernah
 * lahir di masa lalu. Klien yang deal 60 hari sebelum acara tidak perlu
 * melihat "Survei lokasi — lewat 39 hari" sejak hari pertama; langkah itu
 * jatuh tempo hari ini saja (sama seperti termin yang dijepit ke +3 hari).
 */
function tugasTempo(string $weddingDate, int $offset): string
{
    $due = date('Y-m-d', strtotime($weddingDate . ' ' . $offset . ' day'));
    return $offset <= 0 ? max($due, min(date('Y-m-d'), $weddingDate)) : $due;
}

/** Susun checklist persiapan. Hanya kalau belum ada satu pun. */
function checklistSusun(int $clientId): int
{
    if ((int) (one("SELECT COUNT(*) n FROM client_tasks WHERE client_id = ?", [$clientId])['n'] ?? 0)) return 0;
    $tgl = one("SELECT wedding_date FROM clients WHERE id = ?", [$clientId])['wedding_date'] ?? null;
    // DP yang sudah lunas tidak perlu jadi langkah "lewat tempo" — klien baru
    // sampai di sini SETELAH DP masuk. "Kontrak ditandatangani" tetap manual:
    // contract_signed_at berarti DP masuk, bukan kertas yang sudah diteken.
    $dp = terminDp($clientId);
    $n = 0;
    foreach (TASK_TEMPLATE as $i => [$off, $judul, $detail]) {
        $due = $tgl ? tugasTempo($tgl, (int) $off) : null;
        $selesai = $judul === 'DP diterima dan dicatat' && $dp && $dp['paid_at'] ? $dp['paid_at'] . ' 00:00:00' : null;
        q("INSERT INTO client_tasks (client_id, title, detail, offset_day, due_date, done_at, sort_order)
           VALUES (?, ?, ?, ?, ?, ?, ?)", [$clientId, $judul, $detail, $off, $due, $selesai, $i * 10]);
        $n++;
    }
    return $n;
}

/**
 * Termin DP — baris pembayaran pertama yang ditagih saat dealing.
 * Dikenali dari kode 'dealing', lalu baris wajib, lalu urutan pertama.
 */
function terminDp(int $clientId): ?array
{
    return one("SELECT * FROM payments WHERE client_id = ?
                ORDER BY (kode = 'dealing') DESC, wajib DESC, sort_order, id LIMIT 1", [$clientId]);
}

/**
 * DP diterima → klien deal dan diserahkan ke admin office.
 *
 * Satu-satunya pintu serah terima: uangnya dicatat lewat bayarCatat() (jadi
 * DP bisa dibayar sebagian dan punya kwitansi), dan klien baru berpindah ke
 * admin office kalau DP-nya LUNAS PENUH. DP sebagian: klien tetap Menunggu DP
 * dengan tindakan "tagih kekurangan DP".
 *
 * $opt: jumlah (bawaan sisa DP), pengirim, bukti, catatan
 */
function dpDiterima(int $clientId, ?int $userId = null, string $tanggal = '', string $metode = '', array $opt = []): array
{
    require_once __DIR__ . '/bayar.php';
    $c = one("SELECT stage FROM clients WHERE id = ?", [$clientId]);
    if (!$c) throw new RuntimeException('Klien tidak ditemukan.');
    $tanggal = $tanggal && strtotime($tanggal) ? date('Y-m-d', strtotime($tanggal)) : date('Y-m-d');
    if ($tanggal > date('Y-m-d')) $tanggal = date('Y-m-d');

    $dp = terminDp($clientId);
    if (!$dp) {
        if (!terminSusun($clientId)) {
            throw new RuntimeException('Termin belum ada dan nilai deal kosong. Isi nilai deal dulu.');
        }
        $dp = terminDp($clientId);
    }
    $catat = null;
    if (!$dp['paid_at'] && bayarSisa($dp) > 0) {
        $jumlah = isset($opt['jumlah']) && (float) $opt['jumlah'] > 0 ? (float) $opt['jumlah'] : bayarSisa($dp);
        $catat = bayarCatat($clientId, $jumlah, $tanggal, $metode ?: 'Transfer bank', [
            'payment_id' => (int) $dp['id'], 'user_id' => $userId,
            'pengirim' => $opt['pengirim'] ?? '', 'bukti' => $opt['bukti'] ?? null, 'catatan' => $opt['catatan'] ?? '',
        ]);
        $dp = terminDp($clientId);
    }

    $info = [];
    $lunas = (bool) $dp['paid_at'];
    if ($lunas && !stageSudahDeal($c['stage'])) {
        $r = clientSetStage($clientId, 'deal', $userId, $dp['label'] . ' diterima ' . tanggalID($tanggal) . '.');
        $info = $r['info'];
    } elseif (!$lunas) {
        $kurang = bayarSisa($dp);
        q("UPDATE clients SET next_action = ?, next_action_at = ? WHERE id = ?",
          ['Tagih kekurangan ' . $dp['label'] . ' · ' . rupiah($kurang),
           $dp['due_date'] && $dp['due_date'] > date('Y-m-d') ? $dp['due_date'] : date('Y-m-d', strtotime('+2 day')), $clientId]);
        $info[] = 'DP baru diterima sebagian — kurang ' . rupiah($kurang) . '. Klien tetap di Menunggu DP sampai lunas.';
    }
    return ['dp' => $dp, 'info' => $info, 'lunas' => $lunas, 'kwitansi' => $catat];
}

/**
 * Nilai kontrak berubah setelah termin tersusun (tambahan paket, potongan,
 * nilai deal diubah): bagi ulang selisihnya ke termin yang BELUM lunas.
 *
 *   - termin lunas dan termin bernominal tetap (persen kosong — dibuat atau
 *     diubah tangan) tidak disentuh;
 *   - sisanya dibagi ke termin berpersen yang belum lunas sesuai persennya,
 *     tidak pernah di bawah yang sudah dibayar;
 *   - kalau tidak ada yang bisa disesuaikan dan nilainya naik, ditambah satu
 *     baris "Tambahan kontrak".
 *
 * Dulu baris manual diberi bobot 1 dan selalu paling bawah, sehingga baris
 * itu menerima "sisa − terpakai" = Rp 0 setiap kali ada tambahan disetujui.
 *
 * @return int jumlah termin yang berubah
 */
function terminSesuaikan(int $clientId): int
{
    $c = one("SELECT deal_value, wedding_date FROM clients WHERE id = ?", [$clientId]);
    $target = round((float) ($c['deal_value'] ?? 0), 2);
    if ($target <= 0) return 0;
    $rows = all("SELECT * FROM payments WHERE client_id = ? ORDER BY sort_order, id", [$clientId]);
    if (!$rows) return 0;

    $tetap = 0.0; $ubah = [];
    foreach ($rows as $p) {
        if ($p['paid_at'] || $p['persen'] === null || (float) $p['persen'] <= 0) $tetap += (float) $p['amount'];
        else $ubah[] = $p;
    }
    $sisa = round($target - $tetap, 2);
    $berubah = 0;

    if (!$ubah) {
        if ($sisa > 0.5) {
            $hariH = $c['wedding_date'] ?: null;
            $due = date('Y-m-d', strtotime('+7 day'));
            if ($hariH && date('Y-m-d', strtotime($hariH . ' -7 day')) < $due) $due = date('Y-m-d', strtotime($hariH . ' -7 day'));
            if ($due < date('Y-m-d', strtotime('+3 day'))) $due = date('Y-m-d', strtotime('+3 day'));
            $max = (int) (one("SELECT COALESCE(MAX(sort_order),0) m FROM payments WHERE client_id = ?", [$clientId])['m'] ?? 0);
            q("INSERT INTO payments (client_id, kode, label, amount, due_date, sort_order, persen, wajib)
               VALUES (?, 'tambahan', 'Tambahan kontrak', ?, ?, ?, NULL, 0)", [$clientId, $sisa, $due, $max + 10]);
            clientLog($clientId, 'bayar', 'Termin ditambah: Tambahan kontrak ' . rupiah($sisa), '', null);
            return 1;
        }
        return 0;
    }

    require_once __DIR__ . '/bayar.php';
    $baru = []; $terpakai = 0.0; $n = count($ubah);
    // Termin yang jatahnya di bawah yang sudah dibayar dijepit di angka itu,
    // lalu sisanya dibagi ulang ke termin lain — diulang sampai stabil.
    $bebas = $ubah; $sisaBagi = $sisa;
    for ($putaran = 0; $putaran < $n && $bebas; $putaran++) {
        $bobotBebas = array_sum(array_map(fn($p) => (float) $p['persen'], $bebas)) ?: 1;
        $dijepit = [];
        foreach ($bebas as $k => $p) {
            if (round($sisaBagi * (float) $p['persen'] / $bobotBebas) < (float) $p['terbayar']) $dijepit[] = $k;
        }
        if (!$dijepit) break;
        foreach ($dijepit as $k) {
            $baru[$bebas[$k]['id']] = (float) $bebas[$k]['terbayar'];
            $sisaBagi -= (float) $bebas[$k]['terbayar'];
            unset($bebas[$k]);
        }
    }
    $bebas = array_values($bebas);
    $bobotBebas = array_sum(array_map(fn($p) => (float) $p['persen'], $bebas)) ?: 1;
    foreach ($bebas as $i => $p) {
        $baru[$p['id']] = $i === count($bebas) - 1
            ? round($sisaBagi - $terpakai, 2)
            : round($sisaBagi * (float) $p['persen'] / $bobotBebas);
        $terpakai += $baru[$p['id']];
    }
    $catatan = [];
    foreach ($ubah as $p) {
        $nilai = max((float) $p['terbayar'], (float) ($baru[$p['id']] ?? $p['amount']));
        if (abs($nilai - (float) $p['amount']) >= 0.5) {
            q("UPDATE payments SET amount = ? WHERE id = ?", [$nilai, $p['id']]);
            bayarHitungUlang((int) $p['id']);
            $catatan[] = $p['label'] . ': ' . rupiah((float) $p['amount']) . ' → ' . rupiah($nilai);
            $berubah++;
        }
    }
    if ($catatan) clientLog($clientId, 'bayar', 'Termin disesuaikan ke nilai kontrak ' . rupiah($target), implode('; ', $catatan), null);
    return $berubah;
}

/** Catat aktivitas ke garis waktu klien. */
function clientLog(int $clientId, string $type, string $title, string $detail = '', ?int $userId = null): void
{
    q("INSERT INTO client_activities (client_id, user_id, type, title, detail) VALUES (?, ?, ?, ?, ?)",
      [$clientId, $userId, $type, mb_substr($title, 0, 190), $detail]);
}

/**
 * Pindahkan klien ke tahap lain, sekaligus jalankan efek sampingnya.
 *
 * Efek yang otomatis terjadi:
 *   → dp          : nilai deal = price list/penawaran yang disetujui, termin
 *                   disusun dari template pembayaran, DP 30% ditagih
 *   → deal        : event dibuat, termin disusun (kalau belum), klien pindah
 *                   ke admin office
 *   → persiapan / hari-H : checklist H-90 sampai H+3 (kalau belum ada)
 *   → batal       : alasan WAJIB, titik gugur dicatat untuk Analisa
 *   ← mundur ke sebelum deal : pegangan kembali ke admin early
 */
function clientSetStage(int $id, string $stage, ?int $userId = null, string $note = ''): array
{
    if (!isset(PIPE_STAGES[$stage])) throw new InvalidArgumentException('Tahap tidak dikenal.');
    $c = one("SELECT * FROM clients WHERE id = ?", [$id]);
    if (!$c) throw new RuntimeException('Klien tidak ditemukan.');
    if ($c['stage'] === $stage) return ['changed' => false, 'info' => []];

    // Tanpa alasan, menu Analisa tidak punya bahan. Antarmuka sudah memintanya
    // tapi tidak pernah memaksanya — dan yang tidak dipaksa selalu dilewati.
    if ($stage === 'batal' && trim($note) === '') {
        throw new RuntimeException('Alasan wajib diisi saat menandai klien tidak jadi.');
    }

    $info = [];
    $lama = stageLabel($c['stage']);

    q("UPDATE clients SET stage = ?, stage_changed_at = NOW() WHERE id = ?", [$stage, $id]);

    // Tindakan berikutnya diisikan otomatis supaya tidak ada klien tanpa langkah lanjutan.
    $sla = stageSla($stage);
    if (stageNext($stage) !== '') {
        q("UPDATE clients SET next_action = ?, next_action_at = ? WHERE id = ?",
          [stageNext($stage), date('Y-m-d', strtotime("+$sla day")), $id]);
    } else {
        q("UPDATE clients SET next_action = '', next_action_at = NULL WHERE id = ?", [$id]);
    }

    // Diaktifkan lagi dari arsip "tidak jadi".
    if ($c['stage'] === 'batal') {
        q("UPDATE clients SET lost_at = NULL, lost_reason = '', stage_batal = '' WHERE id = ?", [$id]);
    }

    // Mundur ke tahap sebelum deal (koreksi salah klik, atau deal batal di
    // tengah jalan): pegangan kembali ke admin early. Kalau tidak, klien
    // muncul di papan admin office padahal belum ada kontrak.
    if ($stage !== 'batal' && !stageSudahDeal($stage) && ($c['pic_role'] ?? '') === 'admin_office') {
        q("UPDATE clients SET pic_role = 'admin_biasa' WHERE id = ?", [$id]);
        $info[] = 'Pegangan kembali ke admin early.';
    }

    if ($stage === 'spesifikasi') {
        q("UPDATE clients SET spesifikasi_at = COALESCE(spesifikasi_at, NOW()) WHERE id = ?", [$id]);
    }

    // Nilai deal = total price list / penawaran yang disetujui. Dulu kolom
    // ini harus diketik ulang manual di Data klien; kalau lupa, termin tidak
    // pernah tersusun dan tidak ada yang memberi tahu.
    $nilai = (float) ($c['deal_value'] ?? 0);
    if (($stage === 'dp' || $stage === 'deal') && $nilai <= 0) {
        $qc = one("SELECT total FROM quotes WHERE client_id = ? AND status = 'cocok'
                   ORDER BY decided_at DESC, id DESC LIMIT 1", [$id]);
        if ($qc && (float) $qc['total'] > 0) {
            $nilai = (float) $qc['total'];
            q("UPDATE clients SET deal_value = ? WHERE id = ?", [$nilai, $id]);
        }
    }

    if ($stage === 'dp') {
        // Termin disusun SEKARANG, bukan saat deal: tagihan DP-nya harus sudah
        // ada untuk dikirim ke klien, lengkap dengan nominal dan tenggatnya.
        if ($nilai > 0) {
            $n = terminSusun($id);
            if ($n) $info[] = "Termin pembayaran disusun dari template ($n termin).";
            $dp = terminDp($id);
            if ($dp) {
                q("UPDATE clients SET next_action = ?, next_action_at = ? WHERE id = ?",
                  ['Tagih ' . $dp['label'] . ' · ' . rupiah((float) $dp['amount']),
                   $dp['due_date'] ?: date('Y-m-d', strtotime('+3 day')), $id]);
                $info[] = 'Tagih ' . $dp['label'] . ' ' . rupiah((float) $dp['amount'])
                        . '. Begitu ditandai lunas, klien otomatis diserahkan ke admin office.';
            }
        } else {
            $info[] = 'Nilai deal belum ada — isi harga price list atau nilai deal dulu supaya DP bisa ditagih.';
        }
    }

    if ($stage === 'deal') {

        if (eventPastikan($id)) {
            $info[] = 'Event dibuat di menu Event (masih tersembunyi — terbitkan bila ingin tampil di beranda).';
        }

        if ($nilai > 0) {
            $n = terminSusun($id);
            if ($n) $info[] = "Termin pembayaran disusun dari template ($n termin).";
        } elseif (!(int) (one("SELECT COUNT(*) n FROM payments WHERE client_id = ?", [$id])['n'] ?? 0)) {
            $info[] = 'Nilai deal belum ada, jadi termin belum disusun. Isi nilai deal di tab Biodata, '
                    . 'lalu tekan "Susun termin" di tab Pembayaran.';
        }

        q("UPDATE clients SET contract_signed_at = COALESCE(contract_signed_at, NOW()),
                              pic_role = 'admin_office',
                              handover_at = COALESCE(handover_at, NOW())
           WHERE id = ?", [$id]);
        $info[] = 'Klien diserahkan ke admin office. Catat juga kenapa klien ini jadi, di menu Analisa.';
    }

    // Checklist dibuat saat masuk persiapan — atau saat langsung loncat ke
    // hari-H (cron H-7 memindahkan klien deal yang belum sempat dipersiapkan).
    if ($stage === 'persiapan' || $stage === 'harih') {
        $n = checklistSusun($id);
        if ($n) $info[] = "Checklist persiapan dibuat ($n langkah, dihitung mundur dari hari-H).";
    }

    if ($stage === 'batal') {
        q("UPDATE clients SET lost_at = NOW(), lost_reason = ?, stage_batal = ? WHERE id = ?",
          [mb_substr($note, 0, 255), momenGugur($c['stage']), $id]);
        // Dashboard pengantin ikut mati. Diaktifkan lagi = butuh tautan baru.
        try {
            if (!empty($c['portal_token'])) {
                q("UPDATE clients SET portal_token = NULL WHERE id = ?", [$id]);
                $info[] = 'Tautan dashboard pengantin dimatikan.';
            }
        } catch (Throwable $e) { /* kolom belum ada */ }
        // Alasan bebas yang diketik di sini berguna untuk dibaca, tapi tidak
        // bisa dihitung. Analisa terstruktur diminta terpisah — dan diminta
        // SEKARANG, karena ingatan soal kenapa klien mundur luruh cepat.
        $info[] = 'Catat sebabnya di menu Analisa selagi masih segar.';
    }

    clientLog($id, 'tahap', "Tahap: $lama → " . stageLabel($stage), $note, $userId);
    return ['changed' => true, 'info' => $info];
}

/**
 * Buat event untuk klien yang sudah deal, kalau belum ada dan tanggalnya
 * sudah diketahui. Dipanggil saat deal, dan lagi saat admin office mengisi
 * tanggal belakangan (prospek sering belum pasti tanggal ketika DP).
 */
function eventPastikan(int $clientId): bool
{
    $c = one("SELECT * FROM clients WHERE id = ?", [$clientId]);
    if (!$c || $c['event_id'] || !$c['wedding_date'] || !stageSudahDeal($c['stage'])) return false;
    $judul = trim(($c['name'] . ($c['partner_name'] ? ' & ' . $c['partner_name'] : '')));
    $slug  = uniqueSlug('events', slugify($judul . '-' . date('Y', strtotime($c['wedding_date']))));
    $waktu = $c['wedding_date'] . ' ' . ($c['wedding_time'] ?: '08:00:00');
    q("INSERT INTO events (title, slug, couple, event_date, venue, city, guest_count, is_published)
       VALUES (?, ?, ?, ?, ?, ?, ?, 0)",
      ['Pernikahan ' . $judul, $slug, $judul, $waktu, $c['venue'], $c['city'], $c['guest_estimate']]);
    q("UPDATE clients SET event_id = ? WHERE id = ?", [insertId(), $clientId]);
    return true;
}

/**
 * Tanggal pernikahan bergeser → jatuh tempo termin yang belum lunas ikut
 * digeser dengan aturan yang dibekukan per klien (payments.offset_hari:
 * hari-H dikurangi n hari, paling cepat 3 hari lagi). Termin DP, termin
 * manual, dan tanggal yang diubah tangan (offset kosong) tidak disentuh.
 *
 * @return int jumlah termin yang bergeser
 */
function terminGeser(int $clientId, string $weddingDate): int
{
    $rows = all("SELECT id, label, due_date, offset_hari FROM payments
                 WHERE client_id = ? AND paid_at IS NULL AND offset_hari IS NOT NULL", [$clientId]);
    $paling_awal = date('Y-m-d', strtotime('+3 day'));
    $n = 0; $catatan = [];
    foreach ($rows as $r) {
        $due = max(date('Y-m-d', strtotime($weddingDate . ' -' . (int) $r['offset_hari'] . ' day')), $paling_awal);
        if ($due === $r['due_date']) continue;
        q("UPDATE payments SET due_date = ? WHERE id = ?", [$due, $r['id']]);
        $catatan[] = $r['label'] . ': ' . ($r['due_date'] ? tanggalID($r['due_date']) : '—') . ' → ' . tanggalID($due);
        $n++;
    }
    if ($catatan) clientLog($clientId, 'bayar', 'Jatuh tempo termin digeser mengikuti hari-H', implode('; ', $catatan), null);
    return $n;
}

/** Perbarui tanggal jatuh tempo checklist bila tanggal pernikahan bergeser. */
function retimeTasks(int $clientId, string $weddingDate): int
{
    $rows = all("SELECT id, offset_day FROM client_tasks WHERE client_id = ? AND done_at IS NULL", [$clientId]);
    foreach ($rows as $r) {
        q("UPDATE client_tasks SET due_date = ? WHERE id = ?",
          [tugasTempo($weddingDate, (int) $r['offset_day']), $r['id']]);
    }
    return count($rows);
}

/** Ringkasan uang satu klien. */
function clientMoney(int $clientId): array
{
    $r = one("SELECT COALESCE(SUM(amount),0) total, COALESCE(SUM(terbayar),0) lunas
              FROM payments WHERE client_id = ?", [$clientId]);
    $total = (float) ($r['total'] ?? 0);
    $lunas = (float) ($r['lunas'] ?? 0);
    return [
        'total'   => $total,
        'lunas'   => $lunas,
        'sisa'    => max(0, $total - $lunas),
        'persen'  => $total > 0 ? (int) min(100, round($lunas / $total * 100)) : 0,
    ];
}

/** Klien yang perlu ditindaklanjuti hari ini atau sudah lewat tenggat. */
function clientsDue(int $limit = 20): array
{
    return all("SELECT * FROM clients
                WHERE stage NOT IN ('selesai','batal')
                  AND next_action_at IS NOT NULL
                  AND next_action_at <= CURDATE()
                ORDER BY next_action_at ASC LIMIT $limit");
}

/**
 * Pemeliharaan otomatis, dipanggil dari cron harian:
 *  - klien persiapan yang acaranya 7 hari lagi → tahap hari-H
 *  - klien hari-H yang acaranya sudah lewat    → tahap selesai
 */
function pipelineAutoAdvance(): array
{
    $out = [];

    // 'deal' ikut dihitung: klien yang deal mepet tanggal sering tidak sempat
    // dimajukan manual ke persiapan, lalu tertahan di deal sampai acaranya lewat.
    foreach (all("SELECT id, name FROM clients WHERE stage IN ('deal','persiapan')
                  AND wedding_date IS NOT NULL AND wedding_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)") as $c) {
        clientSetStage((int) $c['id'], 'harih');
        $out[] = $c['name'] . ' → Hari-H';
    }
    foreach (all("SELECT id, name FROM clients WHERE stage = 'harih'
                  AND wedding_date IS NOT NULL AND wedding_date < CURDATE()") as $c) {
        clientSetStage((int) $c['id'], 'selesai');
        $out[] = $c['name'] . ' → Selesai';
    }
    return $out;
}

/* ============================================================
   SUSUNAN HARI
   Daftar ini disalin persis dari penyusun brief di situs publik
   (MOMENTS & SERVICES di index.php). Klien yang memakai penyusun
   itu lalu deal lewat WhatsApp membawa pilihan yang sama, jadi
   panel harus bisa mencatatnya tanpa penerjemahan.
   ============================================================ */
/**
 * Daftar acara & layanan dibaca dari database (tabel site_moments /
 * site_services), sumber yang SAMA dengan penyusun brief di situs publik.
 * Kalau tabelnya belum ada — instalasi lama yang belum dimigrasi — nilai
 * cadangan di bawah dipakai supaya panel tetap jalan.
 */
function momenSitus(bool $semua = false): array
{
    static $c = null;
    if ($c !== null && !$semua) return $c;
    try {
        $rows = all("SELECT mkey, label, day_offset, start_h, end_h, is_active FROM site_moments"
                    . ($semua ? '' : ' WHERE is_active = 1') . " ORDER BY sort_order, id");
    } catch (Throwable $e) {
        $rows = [
            ['mkey'=>'siraman','label'=>'Siraman','day_offset'=>-1,'start_h'=>15,'end_h'=>17,'is_active'=>1],
            ['mkey'=>'midoda','label'=>'Midodareni','day_offset'=>-1,'start_h'=>19,'end_h'=>21,'is_active'=>1],
            ['mkey'=>'akad','label'=>'Akad / Pemberkatan','day_offset'=>0,'start_h'=>8,'end_h'=>9.5,'is_active'=>1],
            ['mkey'=>'ramah','label'=>'Ramah tamah','day_offset'=>0,'start_h'=>9.5,'end_h'=>12,'is_active'=>1],
            ['mkey'=>'panggih','label'=>'Panggih adat','day_offset'=>0,'start_h'=>10,'end_h'=>11,'is_active'=>1],
            ['mkey'=>'ressiang','label'=>'Resepsi siang','day_offset'=>0,'start_h'=>12,'end_h'=>15,'is_active'=>1],
            ['mkey'=>'resmalam','label'=>'Resepsi malam','day_offset'=>0,'start_h'=>18.5,'end_h'=>22,'is_active'=>1],
            ['mkey'=>'after','label'=>'After-party','day_offset'=>0,'start_h'=>22,'end_h'=>24,'is_active'=>1],
        ];
    }
    if (!$semua) $c = $rows;
    return $rows;
}

function layananSitus(bool $semua = false): array
{
    static $c = null;
    if ($c !== null && !$semua) return $c;
    try {
        $rows = all("SELECT skey, label, note, is_active FROM site_services"
                    . ($semua ? '' : ' WHERE is_active = 1') . " ORDER BY sort_order, id");
    } catch (Throwable $e) {
        $rows = [
            ['skey'=>'plan','label'=>'Perencanaan penuh','note'=>'9–12 bulan','is_active'=>1],
            ['skey'=>'dok','label'=>'Dokumentasi foto+video','note'=>'2 kamera + drone','is_active'=>1],
            ['skey'=>'mc','label'=>'MC & hiburan','note'=>'MC + akustik','is_active'=>1],
            ['skey'=>'kater','label'=>'Manajemen katering','note'=>'koordinasi vendor','is_active'=>1],
        ];
    }
    if (!$semua) $c = $rows;
    return $rows;
}

function presetSitus(): array
{
    try { return all("SELECT * FROM site_presets WHERE is_active = 1 ORDER BY sort_order, id"); }
    catch (Throwable $e) { return []; }
}

/** Jam desimal -> "09.30" */
function jamDesimal(float $h): string
{
    $H = (int) floor($h); $M = (int) round(($h - $H) * 60);
    if ($M >= 60) { $H++; $M = 0; }
    return sprintf('%02d:%02d', $H % 24, $M);
}

/** Bentuk lama [key, offset, label, "HH:MM", "HH:MM"] agar kode yang sudah ada tetap jalan. */
function momenBawaan(): array
{
    $out = [];
    foreach (momenSitus() as $m) {
        $out[] = [$m['mkey'], (int) $m['day_offset'], $m['label'],
                  jamDesimal((float) $m['start_h']), jamDesimal((float) $m['end_h'])];
    }
    return $out;
}

/** [key => [label, keterangan]] */
function layananBawaan(): array
{
    $out = [];
    foreach (layananSitus() as $l) $out[$l['skey']] = [$l['label'], $l['note']];
    return $out;
}

/** 1 kru lapangan per sekian tamu — angka yang sama dipakai situs publik. */
function tamuPerKru(): int
{
    return max(10, (int) setting('crew_per', '100'));
}

function kruDisarankan(?int $tamu): int
{
    return $tamu ? max(2, (int) ceil($tamu / tamuPerKru())) : 0;
}

function segmenKlien(int $clientId): array
{
    return all("SELECT * FROM client_segments WHERE client_id = ?
                ORDER BY day_offset, sort_order, start_time", [$clientId]);
}

/** Rentang jam hari-H dan total durasi, dihitung dari segmen yang dipilih. */
function rentangHari(array $segmen): array
{
    $hd = array_filter($segmen, fn($s) => (int) $s['day_offset'] === 0 && $s['start_time']);
    if (!$hd) return ['mulai' => null, 'selesai' => null, 'durasi' => 0];
    $mulai   = min(array_map(fn($s) => $s['start_time'], $hd));
    $selesai = max(array_map(fn($s) => $s['end_time'] ?: $s['start_time'], $hd));
    $jam = (strtotime("1970-01-01 $selesai") - strtotime("1970-01-01 $mulai")) / 3600;
    return ['mulai' => $mulai, 'selesai' => $selesai, 'durasi' => max(0, round($jam, 1))];
}

/** Ringkasan satu baris untuk ditempel ke WhatsApp atau catatan. */
function ringkasanSusunan(array $c, array $segmen): string
{
    $r = rentangHari($segmen);
    $baris = [];
    foreach ($segmen as $s) {
        $baris[] = ($s['day_offset'] == -1 ? 'H-1 ' : '') . $s['label']
                 . ($s['start_time'] ? ' ' . substr($s['start_time'], 0, 5)
                    . ($s['end_time'] ? '–' . substr($s['end_time'], 0, 5) : '') : '');
    }
    $lay = [];
    $daftarLayanan = layananBawaan();
    foreach (array_filter(explode(',', $c['services'] ?? '')) as $k) {
        if (isset($daftarLayanan[$k])) $lay[] = $daftarLayanan[$k][0];
    }
    $out = [];
    if ($baris) $out[] = 'Rangkaian: ' . implode(' · ', $baris);
    if ($r['mulai']) $out[] = 'Rentang hari-H: ' . substr($r['mulai'],0,5) . '–' . substr($r['selesai'],0,5) . ' (' . $r['durasi'] . ' jam)';
    if (!empty($c['guest_estimate'])) $out[] = 'Tamu: ' . number_format((int) $c['guest_estimate'], 0, ',', '.');
    if (!empty($c['crew_count']))     $out[] = 'Kru lapangan: ' . (int) $c['crew_count'];
    if ($lay) $out[] = 'Layanan: ' . implode(' · ', $lay);
    return implode("\n", $out);
}

/**
 * Data kalender satu bulan: tanggal mana yang sudah terisi, mana yang kosong.
 *
 * Yang dianggap "terisi" ada tiga lapis, karena bebannya berbeda:
 *  - acara  : hari-H pernikahan klien (paling berat, praktis mengunci tanggal)
 *  - event  : acara yang tercatat di menu Event
 *  - temu   : jadwal pertemuan klien (ringan, masih bisa ditumpuk)
 *
 * Dipakai untuk menjawab pertanyaan yang paling sering muncul saat klien
 * menelepon: "tanggal sekian masih kosong tidak?"
 */
function kalenderBulan(int $tahun, int $bulan): array
{
    $awal  = sprintf('%04d-%02d-01', $tahun, $bulan);
    $akhir = date('Y-m-t', strtotime($awal));

    $isi = [];
    $tandai = function (string $tgl, string $jenis, array $data) use (&$isi) {
        $isi[$tgl][$jenis][] = $data;
    };

    foreach (all("SELECT id, name, partner_name, wedding_date, stage, venue, guest_estimate
                  FROM clients
                  WHERE wedding_date BETWEEN ? AND ? AND stage NOT IN ('batal')", [$awal, $akhir]) as $c) {
        $tandai($c['wedding_date'], 'acara', [
            'id'    => (int) $c['id'],
            'label' => trim($c['name'] . ($c['partner_name'] ? ' & ' . $c['partner_name'] : '')),
            'ket'   => $c['venue'] ?: '',
            'tahap' => $c['stage'],
        ]);
    }

    foreach (all("SELECT id, title, couple, event_date, venue FROM events
                  WHERE DATE(event_date) BETWEEN ? AND ?", [$awal, $akhir]) as $e) {
        $tandai(date('Y-m-d', strtotime($e['event_date'])), 'event', [
            'id'    => (int) $e['id'],
            'label' => $e['couple'] ?: $e['title'],
            'ket'   => $e['venue'] ?: '',
        ]);
    }

    foreach (all("SELECT id, title, client_name, client_id, start_at FROM meetings
                  WHERE status = 'scheduled' AND DATE(start_at) BETWEEN ? AND ?", [$awal, $akhir]) as $m) {
        $tandai(date('Y-m-d', strtotime($m['start_at'])), 'temu', [
            'id'    => (int) $m['id'],
            'label' => $m['client_name'],
            'ket'   => date('H.i', strtotime($m['start_at'])),
            'klien' => (int) ($m['client_id'] ?? 0),
        ]);
    }

    // Susun kisi: Senin sebagai kolom pertama (kebiasaan kalender Indonesia).
    $hariPertama = (int) date('N', strtotime($awal));      // 1 = Senin
    $jumlahHari  = (int) date('t', strtotime($awal));
    $sel = array_fill(0, $hariPertama - 1, null);
    for ($d = 1; $d <= $jumlahHari; $d++) {
        $tgl = sprintf('%04d-%02d-%02d', $tahun, $bulan, $d);
        $sel[] = ['tanggal' => $tgl, 'hari' => $d, 'isi' => $isi[$tgl] ?? []];
    }
    while (count($sel) % 7 !== 0) $sel[] = null;

    return [
        'tahun' => $tahun, 'bulan' => $bulan,
        'nama'  => namaBulan($bulan) . ' ' . $tahun,
        'sel'   => $sel,
        'jumlahAcara' => count(array_filter($isi, fn($x) => isset($x['acara']))),
    ];
}

function namaBulan(int $b): string
{
    return [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli',
            'Agustus','September','Oktober','November','Desember'][$b] ?? '';
}
