<?php
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/ics.php';

/**
 * Kirim pengingat untuk pertemuan yang mulai 12–36 jam ke depan.
 *
 * Google Calendar sudah punya pengingat sendiri, tapi itu hanya sampai ke klien
 * yang memakai Google. Fungsi ini memastikan semua klien dapat pengingat, dan
 * sekaligus jadi kesempatan mengirim ulang tautan rapat.
 *
 * Kolom reminder_sent_at mencegah pengiriman ganda kalau cron jalan dua kali.
 *
 * Dikembalikan: ['terkirim' => n, 'gagal' => n, 'pesan' => [...]]
 */
function sendMeetingReminders(): array
{
    $rows = all("SELECT * FROM meetings
                 WHERE status = 'scheduled'
                   AND reminder_sent_at IS NULL
                   AND start_at BETWEEN DATE_ADD(NOW(), INTERVAL 12 HOUR)
                                    AND DATE_ADD(NOW(), INTERVAL 36 HOUR)
                 ORDER BY start_at ASC");

    $out = ['terkirim' => 0, 'gagal' => 0, 'pesan' => []];
    if (!$rows) { $out['pesan'][] = 'Tidak ada pertemuan yang perlu diingatkan.'; return $out; }

    foreach ($rows as $m) {
        $join = $m['mode'] === 'zoom' ? $m['zoom_join_url'] : ($m['mode'] === 'meet' ? $m['meet_url'] : null);
        $btn  = $join
            ? '<p style="margin:26px 0"><a href="' . e($join) . '" style="background:#E9A85C;color:#17181A;padding:13px 26px;border-radius:999px;text-decoration:none;font-weight:600;display:inline-block">Gabung pertemuan</a></p>'
            : '';
        $format = ['meet' => 'Google Meet', 'zoom' => 'Zoom',
                   'onsite' => 'Tatap muka — ' . e($m['location_text']), 'phone' => 'Telepon'][$m['mode']] ?? '';

        $html = '<!DOCTYPE html><html lang="id"><body style="margin:0;background:#17181A;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#F1EAD9">
<div style="max-width:540px;margin:0 auto;padding:38px 26px">
  <p style="font-family:Georgia,serif;font-style:italic;font-size:22px;margin:0 0 4px">' . e(setting('site_name', 'Callalily Party')) . '</p>
  <p style="font-size:10px;letter-spacing:.18em;text-transform:uppercase;color:#E9A85C;margin:0 0 30px">PENGINGAT PERTEMUAN</p>
  <h1 style="font-family:Georgia,serif;font-weight:400;font-size:24px;line-height:1.3;margin:0 0 14px">Besok kita bertemu, ' . e($m['client_name']) . '.</h1>
  <p style="color:#b9b3a4;line-height:1.7;margin:0 0 22px">' . e($m['title']) . '</p>
  <table style="width:100%;border-top:1px solid rgba(241,234,217,.15);border-bottom:1px solid rgba(241,234,217,.15);font-size:14px">
    <tr><td style="padding:12px 0;color:#8b8f9e;width:100px">Hari</td><td style="padding:12px 0">' . hariID($m['start_at']) . ', ' . tanggalID($m['start_at']) . '</td></tr>
    <tr><td style="padding:6px 0;color:#8b8f9e">Waktu</td><td style="padding:6px 0"><b>' . date('H.i', strtotime($m['start_at'])) . ' – ' . date('H.i', strtotime($m['end_at'])) . ' WIB</b></td></tr>
    <tr><td style="padding:6px 0 12px;color:#8b8f9e">Format</td><td style="padding:6px 0 12px">' . $format . '</td></tr>
  </table>' . $btn . '
  <p style="color:#8b8f9e;font-size:12.5px;line-height:1.7;margin-top:30px">Perlu menggeser jadwal? Balas email ini atau hubungi kami di WhatsApp ' . e(setting('wa_number', '')) . '.</p>
</div></body></html>';

        try {
            $ok = sendMail($m['client_email'], $m['client_name'],
                    'Pengingat: ' . $m['title'] . ' — besok ' . date('H.i', strtotime($m['start_at'])) . ' WIB',
                    $html,
                    [['name' => 'jadwal-callalily.ics', 'mime' => 'text/calendar; charset=UTF-8', 'data' => buildIcs($m)]]);
            if ($ok) {
                q("UPDATE meetings SET reminder_sent_at = NOW() WHERE id = ?", [$m['id']]);
                $out['terkirim']++;
                $out['pesan'][] = "Terkirim ke {$m['client_email']} (jadwal #{$m['id']})";
            } else {
                $out['gagal']++;
                $out['pesan'][] = "GAGAL kirim ke {$m['client_email']} (jadwal #{$m['id']}) — periksa pengaturan SMTP";
            }
        } catch (Throwable $e) {
            $out['gagal']++;
            $out['pesan'][] = "ERROR jadwal #{$m['id']}: " . $e->getMessage();
        }
    }
    return $out;
}
