<?php
/**
 * Pekerjaan harian. Satu cron untuk semuanya:
 *   0 8 * * * /usr/local/bin/php /home/USER/public_html/cron/harian.php >> /home/USER/logs/calla.log 2>&1
 *
 *  1. Pengingat H-1 pertemuan  (dulu reminder.php — masih bisa dipanggil sendiri)
 *  2. Naikkan tahap klien otomatis: persiapan → hari-H → selesai
 *  3. Ingatkan termin pembayaran yang jatuh tempo
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

// ---------- 3. Termin jatuh tempo ----------
try {
    $jatuh = all("SELECT p.*, c.name, c.partner_name, c.email
                  FROM payments p JOIN clients c ON c.id = p.client_id
                  WHERE p.paid_at IS NULL AND p.due_date IS NOT NULL
                    AND p.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)");
    foreach ($jatuh as $p) {
        clientLog((int) $p['client_id'], 'sistem', 'Termin mendekati jatuh tempo',
                  $p['label'] . ' — ' . rupiah($p['amount']) . ', jatuh tempo ' . tanggalID($p['due_date']));
    }
    $log(count($jatuh) . ' termin pembayaran mendekati jatuh tempo (dicatat di riwayat klien).');
} catch (Throwable $e) {
    $log('GAGAL memeriksa pembayaran: ' . $e->getMessage());
}

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
