<?php
/**
 * Endpoint JSON untuk room chat.
 *
 * Semua jalur di sini pendek dan tidak merender HTML — dipanggil berkali-kali
 * per menit oleh polling, jadi setiap query yang tidak perlu terasa langsung.
 *
 * Aksi:
 *   GET  ?aksi=poll&chat=ID&after=ID   pesan baru + ringkasan daftar room
 *   POST aksi=kirim                    kirim pesan
 *   POST aksi=baca                     tandai room sudah dibaca
 *   POST aksi=pesta                    pindahkan room ke pesta lain
 *   POST aksi=arsip                    arsipkan / kembalikan room
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/vendor.php';
require_once __DIR__ . '/../inc/chat.php';

$user = requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function keluar(array $d, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$aksi = $_POST['aksi'] ?? $_GET['aksi'] ?? '';

/* ---------------- POLLING ---------------- */
if ($aksi === 'poll') {
    $chatId = (int) ($_GET['chat']  ?? 0);
    $after  = (int) ($_GET['after'] ?? 0);

    $out = ['ok' => true, 'pesan' => [], 'belum' => chatBelumDibaca()];

    if ($chatId > 0) {
        foreach (chatPesan($chatId, $after, 80) as $m) {
            [$ikon, $judul, $kelas] = chatCentang($m['wa_status']);
            $out['pesan'][] = [
                'id'     => (int) $m['id'],
                'dir'    => $m['direction'],
                'html'   => chatFormat($m['body']),
                'jam'    => date('H.i', strtotime($m['created_at'])),
                'tgl'    => date('Y-m-d', strtotime($m['created_at'])),
                'status' => $m['wa_status'],
                'ikon'   => $ikon,
                'judul'  => $judul . ($m['wa_error'] ? ' — ' . $m['wa_error'] : ''),
                'kelas'  => $kelas,
                'media'  => $m['media_url'] ? ['url' => $m['media_url'], 'nama' => $m['media_name']] : null,
            ];
        }
        // Hanya ditandai terbaca kalau memang sedang dibuka di layar.
        if (($_GET['fokus'] ?? '') === '1') chatTandaiBaca($chatId);
    }

    // Ringkasan daftar room supaya panel kiri ikut hidup tanpa reload.
    $out['room'] = [];
    foreach (chatDaftar($_GET['cari'] ?? '', $_GET['filter'] ?? 'semua') as $ch) {
        $out['room'][] = [
            'id'     => (int) $ch['id'],
            'nama'   => chatNama($ch),
            'cuplik' => ($ch['last_dir'] === 'keluar' ? 'Anda: ' : '') . mb_strimwidth($ch['last_body'], 0, 46, '…'),
            'waktu'  => chatWaktu($ch['last_at']),
            'belum'  => (int) $ch['unread'],
            'jenis'  => $ch['jenis'],
        ];
    }
    keluar($out);
}

/* ---------------- SEMUA AKSI TULIS ---------------- */
// Pemeriksaan CSRF ditulis manual di sini, TIDAK memakai csrfCheck().
// csrfCheck() bawaan mengembalikan void dan langsung die() dengan teks biasa
// kalau gagal — dua-duanya salah untuk endpoint JSON: nilai baliknya tidak
// bisa diuji, dan halaman error HTML akan memecahkan parser di sisi peramban.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') keluar(['ok' => false, 'error' => 'Metode salah.'], 405);

$tokenKirim = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($tokenKirim) || !hash_equals($_SESSION['csrf'] ?? '', $tokenKirim)) {
    keluar(['ok' => false, 'error' => 'Sesi kedaluwarsa. Muat ulang halaman.'], 419);
}

$chatId = (int) ($_POST['chat'] ?? 0);
if ($chatId <= 0) keluar(['ok' => false, 'error' => 'Room tidak disebut.'], 400);

if ($aksi === 'kirim') {
    $teks = (string) ($_POST['teks'] ?? '');
    if (mb_strlen($teks) > 4000) keluar(['ok' => false, 'error' => 'Pesan terlalu panjang (maks 4000 karakter).'], 400);

    $r = chatKirim($chatId, $teks, (int) $user['id'], [
        'reply_to' => $_POST['balas'] ?? null,
    ]);
    keluar([
        'ok'       => $r['ok'],
        'id'       => $r['id']       ?? null,
        'terkirim' => $r['terkirim'] ?? false,
        'wa_url'   => $r['wa_url']   ?? null,
        'error'    => $r['error'],
    ]);
}

if ($aksi === 'baca') {
    chatTandaiBaca($chatId);
    keluar(['ok' => true, 'belum' => chatBelumDibaca()]);
}

if ($aksi === 'pesta') {
    $cid = (int) ($_POST['client'] ?? 0);
    q("UPDATE wa_chats SET room_client_id = ? WHERE id = ?", [$cid ?: null, $chatId]);
    keluar(['ok' => true]);
}

if ($aksi === 'arsip') {
    q("UPDATE wa_chats SET archived = IF(archived = 1, 0, 1) WHERE id = ?", [$chatId]);
    keluar(['ok' => true]);
}

if ($aksi === 'sematkan') {
    q("UPDATE wa_chats SET pinned = IF(pinned = 1, 0, 1) WHERE id = ?", [$chatId]);
    keluar(['ok' => true]);
}

keluar(['ok' => false, 'error' => 'Aksi tidak dikenal.'], 400);
