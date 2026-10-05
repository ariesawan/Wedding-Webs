<?php
/**
 * Penerima balasan WhatsApp.
 *
 * Didaftarkan ke penyedia sebagai:
 *   https://callalily.party/wa-webhook.php?token=TOKEN_GATEWAY
 *
 * Berkas ini SENGAJA di root dan bisa diakses tanpa login — yang memanggil
 * adalah server penyedia, bukan peramban. Pengamanannya token, bukan sesi.
 *
 * Balasan SELALU 200 secepatnya. Fonnte mengulang kiriman sampai 15 kali
 * (satu kali per menit) kalau balasannya bukan 200, dan pengulangan itu
 * akan menggandakan pesan di room. Penjagaan kedua ada di chatTerima():
 * wa_id yang sudah pernah masuk dilewati.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/vendor.php';
require_once __DIR__ . '/inc/chat.php';

// ---------- Verifikasi pendaftaran webhook (Cloud API) ----------
if (($_GET['hub_mode'] ?? '') === 'subscribe') {
    $token = setting('wa_verify_token');
    if ($token && hash_equals($token, $_GET['hub_verify_token'] ?? '')) {
        header('Content-Type: text/plain');
        echo $_GET['hub_challenge'] ?? '';
    } else {
        http_response_code(403);
        echo 'token verifikasi tidak cocok';
    }
    exit;
}

$mentah = file_get_contents('php://input') ?: '';

// Pada kiriman multipart/form-data, php://input SELALU kosong di PHP —
// isinya sudah dipindahkan ke $_POST oleh interpreter. Tanpa cadangan ini,
// log cuma berisi baris kosong dan tidak ada yang bisa didiagnosis.
if ($mentah === '' && $_POST) $mentah = json_encode($_POST, JSON_UNESCAPED_UNICODE);

// Jejak pemanggil ikut dicatat. Tanpa ini, panggilan Fonnte dan uji dari
// peramban terlihat sama persis di log — padahal artinya jauh berbeda.
$ua   = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 80);
$ip   = $_SERVER['REMOTE_ADDR'] ?? '-';
$cara = $_SERVER['REQUEST_METHOD'] ?? '-';
waCatatLog('masuk', "[$cara $ip | $ua] " . ($mentah !== '' ? $mentah : '(badan kosong)'));

// ---------- Pemeriksaan keaslian ----------
$sah = false;
if (waPenyedia() === 'cloud') {
    $rahasia = setting('wa_app_secret') ?: setting('wa_verify_token');
    $tanda   = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if (!$rahasia) {
        $sah = true;                       // pemasangan awal, belum diisi
    } elseif ($tanda && str_starts_with($tanda, 'sha256=')) {
        $sah = hash_equals('sha256=' . hash_hmac('sha256', $mentah, $rahasia), $tanda);
    }
} else {
    $token = setting('wa_gateway_token');
    $kirim = $_GET['token'] ?? $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '';
    if (!$kirim) {
        $j = json_decode($mentah, true);
        $kirim = $j['token'] ?? $_POST['token'] ?? '';
    }
    $sah = $token && hash_equals($token, (string) $kirim);

    // Mode diagnosa berbatas waktu. Dipasang dari panel, mati sendiri setelah
    // 30 menit. Gunanya menyingkirkan token dari persamaan: kalau Fonnte
    // tersandung pada query string, panggilannya tidak akan pernah terlihat
    // selama token masih disyaratkan di URL.
    if (!$sah && (int) setting('wa_hook_open_until') > time()) {
        $sah = true;
        waCatatLog('tolak', 'DITERIMA TANPA TOKEN (mode diagnosa aktif) — '
                 . 'segera daftarkan ulang URL berikut token setelah selesai menguji.', 200);
    }
}

if (!$sah) {
    // Penolakan ikut dicatat, dengan sebabnya. Dulu webhook yang ditolak
    // hanya meninggalkan baris payload tanpa keterangan — tidak mungkin
    // membedakan "Fonnte tidak pernah memanggil" dari "dipanggil tapi ditolak",
    // padahal dua-duanya terlihat sama dari panel: balasan tidak masuk.
    $sebab = !setting('wa_gateway_token')
        ? 'Token gateway belum diisi di Pengaturan.'
        : 'Token tidak cocok atau tidak disertakan. URL webhook di Fonnte harus memuat ?token=...';
    waCatatLog('tolak', $sebab, 403);
    http_response_code(403);
    exit('ditolak');
}

// ---------- Baca isi ----------
$data = json_decode($mentah, true);
if (!is_array($data)) $data = $_POST;

$masuk = 0;
try {
    if (waPenyedia() === 'cloud') {
        // Bentuk Cloud API bersarang: entry[].changes[].value
        foreach ($data['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $ch) {
                $v = $ch['value'] ?? [];

                $namaKontak = [];
                foreach ($v['contacts'] ?? [] as $c) {
                    $namaKontak[$c['wa_id'] ?? ''] = $c['profile']['name'] ?? '';
                }

                foreach ($v['messages'] ?? [] as $m) {
                    $teks = $m['text']['body']
                         ?? $m['button']['text']
                         ?? $m['interactive']['list_reply']['title']
                         ?? $m['interactive']['button_reply']['title']
                         ?? ('[' . ($m['type'] ?? 'lampiran') . ' — buka di WhatsApp]');
                    chatTerima($m['from'] ?? '', $teks, [
                        'wa_id' => $m['id'] ?? null,
                        'nama'  => $namaKontak[$m['from'] ?? ''] ?? '',
                    ]);
                    $masuk++;
                }
                foreach ($v['statuses'] ?? [] as $st) {
                    chatStatus($st['id'] ?? '', $st['status'] ?? '');
                }
            }
        }
    } else {
        // ---------- Gateway (Fonnte) ----------
        // Nama field mengikuti dokumentasi Fonnte, dengan alternatif umum
        // sebagai cadangan supaya ganti gateway tidak perlu tulis ulang.
        $dari = $data['sender'] ?? $data['from'] ?? $data['phone'] ?? $data['pengirim'] ?? '';
        $teks = $data['message'] ?? $data['text'] ?? $data['pesan'] ?? $data['body'] ?? '';
        $nama = $data['name'] ?? $data['pushname'] ?? $data['sender_name'] ?? '';

        // Fonnte memakai 'inboxid' sebagai penanda pesan masuk, bukan 'id'.
        $wid = $data['inboxid'] ?? $data['id'] ?? $data['message_id'] ?? null;
        if (is_array($wid)) $wid = $wid[0] ?? null;

        // Pesan grup: field 'member' berisi anggota yang bicara, sedangkan
        // 'sender' berisi ID grup. Dulu semua pesan grup dibuang karena panel
        // belum punya room grup. Sekarang punya — tapi hanya grup yang SUDAH
        // ditarik ke panel yang diterima, supaya grup pribadi milik owner
        // tidak ikut membanjiri daftar.
        // Penentu grup HANYA akhiran @g.us pada sender. Sebelumnya field
        // 'member' yang tidak kosong ikut dianggap penanda grup — kalau Fonnte
        // mengirim field itu pada pesan personal, pesannya akan dibuang diam-diam
        // sebagai "grup tidak terdaftar". Terlalu berisiko untuk sebuah tebakan.
        $anggota  = (string) ($data['member'] ?? '');
        $dariGrup = str_contains((string) $dari, '@g.us');

        if ($dariGrup) {
            $gid = str_contains((string) $dari, '@g.us') ? (string) $dari : '';
            $room = $gid ? one("SELECT id FROM wa_chats WHERE wa_number = ? AND is_group = 1", [$gid]) : null;
            if (!$room) {
                waCatatLog('tolak', 'Pesan grup dari ' . $gid . ' — grup belum ditarik ke panel.', 200);
                // Grup belum terdaftar di panel — diabaikan, bukan error.
                http_response_code(200);
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'diterima' => 0, 'catatan' => 'grup tidak terdaftar']);
                exit;
            }
        }

        // Lampiran hanya dikirim pada paket Fonnte yang mendukungnya.
        $mediaUrl = $data['url'] ?? null;
        $mediaNm  = $data['filename'] ?? null;
        if ($teks === '' && $mediaNm) {
            $teks = '[lampiran: ' . $mediaNm . ']';
        } elseif ($teks === '' && !empty($data['location'])) {
            $teks = '[lokasi dibagikan — buka di WhatsApp]';
        } elseif ($teks === '' && !empty($data['pollname'])) {
            $teks = '[jajak pendapat: ' . $data['pollname'] . ']';
        }

        $status = $data['status'] ?? '';

        // Setiap jalan buntu dicatat sebabnya. Sebelumnya pesan yang tidak
        // dikenali dibuang tanpa jejak, jadi "Fonnte tidak memanggil" dan
        // "dipanggil tapi isinya tidak terbaca" terlihat sama dari panel.
        if (!$dari && !$status) {
            waCatatLog('tolak', 'Muatan tidak memuat sender. Field yang datang: '
                     . implode(', ', array_keys($data)), 200);
        } elseif ($dari && $teks === '' && !$status) {
            waCatatLog('tolak', 'Ada sender (' . $dari . ') tapi isi pesan kosong. Field: '
                     . implode(', ', array_keys($data)), 200);
        }

        if ($status && $wid && $teks === '') {
            // Kiriman status, bukan pesan baru.
            chatStatus((string) $wid, (string) $status);
        } elseif ($dari && $teks !== '') {
            chatTerima((string) $dari, (string) $teks, [
                'wa_id'      => $wid ? (string) $wid : null,
                'nama'       => (string) $nama,
                'member'     => (string) $anggota,
                'media_url'  => $mediaUrl,
                'media_name' => $mediaNm,
                'media_type' => $data['extension'] ?? null,
            ]);
            $masuk++;
        }
    }
} catch (Throwable $e) {
    error_log('wa-webhook: ' . $e->getMessage());
}

// Selalu 200 supaya penyedia tidak mengirim ulang.
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'diterima' => $masuk]);
