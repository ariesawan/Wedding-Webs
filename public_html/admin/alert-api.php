<?php
/**
 * Data pengingat untuk notifikasi desktop.
 *
 * Jendelanya sengaja sempit: H-7 sampai H-1 saja.
 *
 * Alasannya bukan teknis. Notifikasi yang muncul terlalu sering berhenti
 * dibaca — dan begitu satu diabaikan, yang berikutnya ikut diabaikan.
 * H-30 masih terlalu jauh untuk ditindaklanjuti hari itu juga; H-0 sudah
 * terlambat untuk apa pun selain datang. Yang tersisa adalah minggu terakhir,
 * dan di minggu itu memang setiap harinya berbeda bobot.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/pipeline.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$keluar = ['ok' => true, 'item' => []];

try {
    $rows = all("SELECT id, name, partner_name, wedding_date, venue, stage,
                        DATEDIFF(wedding_date, CURDATE()) sisa
                 FROM clients
                 WHERE wedding_date IS NOT NULL
                   AND stage IN ('deal','persiapan','harih')
                   AND DATEDIFF(wedding_date, CURDATE()) BETWEEN 1 AND 7
                 ORDER BY wedding_date");

    foreach ($rows as $r) {
        $sisa = (int) $r['sisa'];
        $nama = trim($r['name'] . ($r['partner_name'] ? ' & ' . $r['partner_name'] : ''));

        // Checklist yang belum kelar di minggu terakhir — ini yang sebenarnya
        // membuat pengingat berguna. "H-3" saja tidak menyuruh melakukan apa pun.
        $sisaTugas = (int) (one("SELECT COUNT(*) c FROM client_tasks
                                 WHERE client_id = ? AND done_at IS NULL", [$r['id']])['c'] ?? 0);

        $belumBayar = (int) (one("SELECT COUNT(*) c FROM payments
                                  WHERE client_id = ? AND paid_at IS NULL AND amount > terbayar
                                    AND due_date <= CURDATE()", [$r['id']])['c'] ?? 0);

        $badan = [];
        if ($sisaTugas > 0) $badan[] = $sisaTugas . ' langkah checklist belum selesai';
        if ($belumBayar > 0) $badan[] = $belumBayar . ' tagihan lewat tempo';
        if (!$badan) $badan[] = 'Checklist dan tagihan sudah bersih';
        if ($r['venue']) $badan[] = $r['venue'];

        $keluar['item'][] = [
            // Kunci penanda: satu klien maksimal satu notifikasi per hari.
            // Tanpa ini, polling tiap 15 menit akan memunculkan hal yang sama
            // berkali-kali dalam sehari.
            'kunci'  => 'hh-' . $r['id'] . '-' . date('Y-m-d'),
            'judul'  => 'H-' . $sisa . ' · ' . $nama,
            'badan'  => implode(' · ', $badan),
            'url'    => 'klien.php?id=' . (int) $r['id'],
            'sisa'   => $sisa,
            'genting' => $sisa <= 3 && ($sisaTugas > 0 || $belumBayar > 0),
        ];
    }
} catch (Throwable $e) {
    $keluar = ['ok' => false, 'error' => 'Gagal membaca pengingat.', 'item' => []];
}

echo json_encode($keluar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
