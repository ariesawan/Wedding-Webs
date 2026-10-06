<?php
/**
 * DOKUMEN BERTANDA TANGAN — kwitansi untuk klien (dan orang tuanya)
 *
 *   dokumen.php?j=kw&id=ID&s=TANDA
 *
 * Tandanya HMAC dari APP_KEY untuk SATU dokumen, jadi tautan ini aman
 * diteruskan ke orang tua atau diunduh gateway WhatsApp sebagai lampiran:
 * pemegangnya hanya bisa membuka kwitansi itu, bukan dashboard pengantin.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/pdf-bayar.php';

$jenis = (string) ($_GET['j'] ?? '');
$id    = (int) ($_GET['id'] ?? 0);
$sig   = (string) ($_GET['s'] ?? '');

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

$tidakAda = function (): never {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    $wa = waNomorPublik();
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex"><title>Dokumen tidak ditemukan</title>'
       . '<style>body{font:16px/1.6 system-ui,sans-serif;background:#F7F3EA;color:#1C1810;display:grid;place-items:center;min-height:100vh;margin:0;padding:20px}'
       . 'div{max-width:420px;text-align:center}a{display:inline-block;margin-top:14px;background:#A9651B;color:#fff;padding:10px 18px;border-radius:99px;text-decoration:none}</style></head>'
       . '<body><div><h1 style="font:italic 400 28px Georgia,serif">Dokumen tidak ditemukan</h1>'
       . '<p>Tautannya mungkin salah salin atau dokumennya sudah diganti. Hubungi kami lewat WhatsApp.</p>'
       . '<a href="https://wa.me/' . e($wa) . '">Chat WhatsApp</a></div></body></html>';
    exit;
};

if ($jenis !== 'kw' || !dokSah($jenis, $id, $sig)) $tidakAda();

try {
    $isi = kwitansiPdf($id);
} catch (Throwable $e) {
    $tidakAda();
}
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($isi));
header('Content-Disposition: ' . (!empty($_GET['unduh']) ? 'attachment' : 'inline') . '; filename="' . kwitansiNamaBerkas($id) . '"');
echo $isi;
