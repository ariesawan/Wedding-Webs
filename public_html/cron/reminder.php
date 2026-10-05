<?php
/**
 * Pengingat H-1 saja. Dipertahankan agar cron lama yang menunjuk ke berkas ini
 * tetap berfungsi. Untuk semua pekerjaan harian sekaligus, pakai harian.php.
 *
 *   0 8 * * * /usr/local/bin/php /home/USER/public_html/cron/reminder.php
 */
if (PHP_SAPI !== 'cli') {
    require_once dirname(__DIR__) . '/inc/bootstrap.php';
    $t = setting('cron_token');
    if (!$t || !hash_equals($t, $_GET['token'] ?? '')) { http_response_code(403); exit("Akses ditolak.\n"); }
} else {
    require_once dirname(__DIR__) . '/inc/bootstrap.php';
}
require_once dirname(__DIR__) . '/inc/reminder.php';

$r = sendMeetingReminders();
foreach ($r['pesan'] as $p) echo '[' . date('Y-m-d H:i:s') . "] $p" . PHP_EOL;
echo '[' . date('Y-m-d H:i:s') . "] Selesai. Terkirim: {$r['terkirim']}, gagal: {$r['gagal']}." . PHP_EOL;
exit($r['gagal'] > 0 ? 1 : 0);
