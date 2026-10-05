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
 * Alur normal:
 *   baru → meeting → penawaran → negosiasi → deal → persiapan → hari-H → selesai
 * Alur gagal:
 *   dari tahap mana pun → batal (wajib isi alasan, supaya bisa dievaluasi)
 */

const PIPE_STAGES = [
    // Disamakan dengan enum di database sejak migration-v8. Sebelumnya
    // konstanta ini masih memuat 'meeting' dan 'negosiasi' sementara kolomnya
    // sudah berubah — akibatnya tahap 'pricelist' dan 'spesifikasi' tidak
    // pernah muncul di antarmuka dan tidak bisa dipilih sama sekali.
    'baru' => [
        'label' => 'Prospek baru',
        'desc'  => 'Masuk dari DM, WhatsApp, atau formulir. Belum ada pembicaraan serius.',
        'next'  => 'Kirim price list',
        'sla'   => 1,
    ],
    'pricelist' => [
        'label' => 'Price list',
        'desc'  => 'Price list sudah dikirim, menunggu jawaban cocok atau tidak.',
        'next'  => 'Tanyakan tanggapan price list',
        'sla'   => 3,
    ],
    'spesifikasi' => [
        'label' => 'Spesifikasi',
        'desc'  => 'Cocok dengan price list. Sedang menggali base information — konsultasi terjadi di tahap ini.',
        'next'  => 'Lengkapi base information, lalu susun penawaran',
        'sla'   => 4,
    ],
    'penawaran' => [
        'label' => 'Penawaran',
        'desc'  => 'Penawaran sudah dikirim, menunggu keputusan.',
        'next'  => 'Tanyakan tanggapan penawaran',
        'sla'   => 3,
    ],
    'deal' => [
        'label' => 'Deal',
        'desc'  => 'Kontrak ditandatangani. Peran berpindah ke admin office.',
        'next'  => 'Buat grup WA dan masukkan vendor',
        'sla'   => 2,
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
];

/** Tahap yang masih aktif (dipakai untuk papan pipeline). */
// Tahap yang dianggap masih berjalan. Ikut disamakan dengan PIPE_STAGES —
// sempat tertinggal memuat 'meeting' dan 'negosiasi', sehingga papan pipeline
// menampilkan kolom yang sudah tidak ada dan melewatkan dua tahap baru.
const PIPE_ACTIVE = ['baru', 'pricelist', 'spesifikasi', 'penawaran', 'deal', 'persiapan', 'harih'];

function stageLabel(string $s): string { return PIPE_STAGES[$s]['label'] ?? $s; }
function stageNext(string $s): string  { return PIPE_STAGES[$s]['next']  ?? ''; }
function stageSla(string $s): int      { return PIPE_STAGES[$s]['sla']   ?? 3; }

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
 *   → deal      : buat event di kalender situs + susun termin pembayaran
 *   → persiapan : susun checklist H-90 sampai H+3
 *   → batal     : catat alasan dan tanggal
 */
function clientSetStage(int $id, string $stage, ?int $userId = null, string $note = ''): array
{
    if (!isset(PIPE_STAGES[$stage])) throw new InvalidArgumentException('Tahap tidak dikenal.');
    $c = one("SELECT * FROM clients WHERE id = ?", [$id]);
    if (!$c) throw new RuntimeException('Klien tidak ditemukan.');
    if ($c['stage'] === $stage && $stage !== 'batal') return ['changed' => false, 'info' => []];

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

    if ($stage === 'deal') {
        if (!$c['event_id'] && $c['wedding_date']) {
            $judul = trim(($c['name'] . ($c['partner_name'] ? ' & ' . $c['partner_name'] : '')));
            $slug  = uniqueSlug('events', slugify($judul . '-' . date('Y', strtotime($c['wedding_date']))));
            $waktu = $c['wedding_date'] . ' ' . ($c['wedding_time'] ?: '08:00:00');
            q("INSERT INTO events (title, slug, couple, event_date, venue, city, guest_count, is_published)
               VALUES (?, ?, ?, ?, ?, ?, ?, 0)",
              ['Pernikahan ' . $judul, $slug, $judul, $waktu, $c['venue'], $c['city'], $c['guest_estimate']]);
            $eid = insertId();
            q("UPDATE clients SET event_id = ? WHERE id = ?", [$eid, $id]);
            $info[] = 'Event dibuat di menu Event (masih tersembunyi — terbitkan bila ingin tampil di beranda).';
        }
        $sudahAda = (int) (one("SELECT COUNT(*) c FROM payments WHERE client_id = ?", [$id])['c'] ?? 0);
        if (!$sudahAda && $c['deal_value'] > 0) {
            $dpP = (int) setting('dp_percent', '30');
            foreach (paymentTemplate((float) $c['deal_value'], $c['wedding_date'], $dpP) as $t) {
                q("INSERT INTO payments (client_id, label, amount, due_date, sort_order) VALUES (?, ?, ?, ?, ?)",
                  [$id, $t['label'], $t['amount'], $t['due_date'], $t['sort_order']]);
            }
            $info[] = 'Termin pembayaran disusun otomatis (DP ' . $dpP . '% + 2 termin).';
        }
    }

    if ($stage === 'persiapan') {
        $sudahAda = (int) (one("SELECT COUNT(*) c FROM client_tasks WHERE client_id = ?", [$id])['c'] ?? 0);
        if (!$sudahAda) {
            $n = 0;
            foreach (TASK_TEMPLATE as $i => [$off, $judul, $detail]) {
                $due = $c['wedding_date'] ? date('Y-m-d', strtotime($c['wedding_date'] . " $off day")) : null;
                q("INSERT INTO client_tasks (client_id, title, detail, offset_day, due_date, sort_order)
                   VALUES (?, ?, ?, ?, ?, ?)", [$id, $judul, $detail, $off, $due, $i * 10]);
                $n++;
            }
            $info[] = "Checklist persiapan dibuat ($n langkah, dihitung mundur dari hari-H).";
        }
    }

    if ($stage === 'batal') {
        q("UPDATE clients SET lost_at = NOW(), lost_reason = ? WHERE id = ?", [mb_substr($note, 0, 255), $id]);
        // Alasan bebas yang diketik di sini berguna untuk dibaca, tapi tidak
        // bisa dihitung. Analisa terstruktur diminta terpisah — dan diminta
        // SEKARANG, karena ingatan soal kenapa klien mundur luruh cepat.
        q("UPDATE clients SET stage_batal = ? WHERE id = ? AND stage_batal = ''",
          [in_array($c['stage'], ['pricelist','penawaran'], true) ? $c['stage'] : 'penawaran', $id]);
        $info[] = 'Catat sebabnya di menu Analisa selagi masih segar → analisa.php?klien=' . $id;
    }

    if ($stage === 'deal') {
        q("UPDATE clients SET contract_signed_at = COALESCE(contract_signed_at, NOW()),
                              pic_role = 'admin_office',
                              handover_at = COALESCE(handover_at, NOW())
           WHERE id = ?", [$id]);
        $info[] = 'Peran berpindah ke admin office. Catat juga kenapa klien ini jadi, di menu Analisa.';
    }

    clientLog($id, 'tahap', "Tahap: $lama → " . stageLabel($stage), $note, $userId);
    return ['changed' => true, 'info' => $info];
}

/** Perbarui tanggal jatuh tempo checklist bila tanggal pernikahan bergeser. */
function retimeTasks(int $clientId, string $weddingDate): int
{
    $rows = all("SELECT id, offset_day FROM client_tasks WHERE client_id = ? AND done_at IS NULL", [$clientId]);
    foreach ($rows as $r) {
        q("UPDATE client_tasks SET due_date = ? WHERE id = ?",
          [date('Y-m-d', strtotime($weddingDate . ' ' . $r['offset_day'] . ' day')), $r['id']]);
    }
    return count($rows);
}

/** Ringkasan uang satu klien. */
function clientMoney(int $clientId): array
{
    $r = one("SELECT COALESCE(SUM(amount),0) total,
                     COALESCE(SUM(CASE WHEN paid_at IS NOT NULL THEN amount ELSE 0 END),0) lunas
              FROM payments WHERE client_id = ?", [$clientId]);
    $total = (float) ($r['total'] ?? 0);
    $lunas = (float) ($r['lunas'] ?? 0);
    return [
        'total'   => $total,
        'lunas'   => $lunas,
        'sisa'    => $total - $lunas,
        'persen'  => $total > 0 ? (int) round($lunas / $total * 100) : 0,
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

    foreach (all("SELECT id, name FROM clients WHERE stage = 'persiapan'
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
