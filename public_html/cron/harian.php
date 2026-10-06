<?php
/**
 * Pekerjaan harian. Satu cron untuk semuanya:
 *   0 8 * * * /usr/local/bin/php /home/USER/public_html/cron/harian.php >> /home/USER/logs/calla.log 2>&1
 *
 *  1. Pengingat H-1 pertemuan  (dulu reminder.php — masih bisa dipanggil sendiri)
 *  2. Naikkan tahap klien otomatis: persiapan → hari-H → selesai
 *  3. Pengingat pembayaran lewat WhatsApp (bila diaktifkan owner)
 *  4. Sinkronkan Google Spreadsheet bila diaktifkan
 */

if (PHP_SAPI !== 'cli') {
    require_once dirname(__DIR__) . '/inc/bootstrap.php';
    $token = setting('cron_token');
    if (!$token || !hash_equals($token, $_GET['token'] ?? '')) { http_response_code(403); exit("Akses ditolak.\n"); }
} else {
    require_once dirname(__DIR__) . '/inc/bootstrap.php';
}
require_once dirname(__DIR__) . '/inc/pipeline.php';
require_once dirname(__DIR__) . '/inc/sheets.php';
require_once dirname(__DIR__) . '/inc/reminder.php';

$log = fn(string $m) => print('[' . date('Y-m-d H:i:s') . '] ' . $m . PHP_EOL);
$log('=== Mulai pekerjaan harian ===');

// ---------- 1. Pengingat pertemuan ----------
try {
    $r = sendMeetingReminders();
    foreach ($r['pesan'] as $p) $log($p);
} catch (Throwable $e) {
    $log('GAGAL mengirim pengingat: ' . $e->getMessage());
}

// ---------- 2. Tahap otomatis ----------
try {
    $pindah = pipelineAutoAdvance();
    $log($pindah ? 'Tahap klien dipindahkan: ' . implode(', ', $pindah) : 'Tidak ada tahap klien yang perlu dipindahkan.');
} catch (Throwable $e) {
    $log('GAGAL memindahkan tahap: ' . $e->getMessage());
}

// ---------- 3. Pengingat pembayaran ----------
// Bawaan MATI (Pengaturan → Pengingat pembayaran). Paling banyak dua pesan per
// termin — H-3 dan sekali setelah lewat tempo — lalu diserahkan ke manusia.
if (setting('bayar_ingat_aktif', '0') === '1') {
    try {
        require_once dirname(__DIR__) . '/inc/bayar.php';
        if ((int) (one("SELECT GET_LOCK('calla_ingat_bayar', 0) g")['g'] ?? 0) === 1) {
            $hasil = bayarPengingatHarian(true);
            foreach ($hasil as $h) $log('Pengingat ' . $h['jenis'] . ' → ' . $h['nama'] . ': ' . $h['status']);
            $log(count($hasil) . ' klien diproses untuk pengingat pembayaran.');
            q("SELECT RELEASE_LOCK('calla_ingat_bayar')");
        } else {
            $log('Pengingat pembayaran sedang berjalan di proses lain — dilewati.');
        }
    } catch (Throwable $e) {
        $log('GAGAL mengirim pengingat pembayaran: ' . $e->getMessage());
    }
} else {
    $log('Pengingat pembayaran otomatis tidak aktif.');
}
setting('cron_terakhir') !== date('Y-m-d') && settingSet('cron_terakhir', date('Y-m-d'));

// ---------- 4. Sinkron spreadsheet ----------
if (setting('sheet_autosync') === '1' && setting('sheet_enabled') === '1') {
    try {
        $hasil = sheetsSyncAll();
        $ket = [];
        foreach ($hasil as $tab => $n) $ket[] = "$tab=$n";
        $log('Spreadsheet tersinkron: ' . implode(' ', $ket));
    } catch (Throwable $e) {
        settingSet('sheet_last_error', $e->getMessage());
        $log('GAGAL sinkron spreadsheet: ' . $e->getMessage());
    }
} else {
    $log('Sinkron spreadsheet dilewati (tidak diaktifkan).');
}

$log('=== Selesai ===');
