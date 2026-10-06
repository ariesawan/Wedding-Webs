<?php
/**
 * Tampilkan bukti transfer — hanya untuk panel.
 *
 * Berkasnya disimpan di luar akses publik (inc/bayar.php buktiDir()), jadi
 * satu-satunya jalan melihatnya lewat sini: login, peran yang mengurus
 * pembayaran, dan path yang terbukti berada di dalam folder bukti.
 */
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/bayar.php';
$user = requireLogin();

// Peran lama 'editor' disamakan dengan admin office oleh bolehAkses(), tapi
// bukti transfer (nama & nomor rekening pihak ketiga) bukan urusannya.
if (!in_array($user['role'] ?? '', ['owner', 'admin_early', 'admin_office'], true)) {
    http_response_code(403);
    exit('Tidak diizinkan.');
}

$r = one("SELECT bukti FROM payment_receipts WHERE id = ?", [(int) ($_GET['id'] ?? 0)]);
$path = buktiPath($r['bukti'] ?? null);
if (!$path) {
    http_response_code(404);
    exit('Bukti tidak ditemukan.');
}

$pdf = str_ends_with($path, '.pdf');
header('Content-Type: ' . ($pdf ? 'application/pdf' : 'image/jpeg'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($pdf ? 'attachment' : 'inline') . '; filename="bukti-' . (int) $_GET['id'] . ($pdf ? '.pdf' : '.jpg') . '"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
header('Cache-Control: private, no-store');
readfile($path);
