<?php
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/vendor.php';   // waNomor() dipakai saat menormalkan nomor tujuan

/**
 * ============================================================
 * WHATSAPP DUA ARAH
 * ============================================================
 *
 * Panel mengirim pesan ke penyedia, penyedia meneruskan ke WhatsApp vendor.
 * Balasan vendor dikembalikan penyedia lewat webhook ke wa-webhook.php,
 * lalu masuk ke room yang benar. Jadi Anda cukup mengetik di panel, vendor
 * tetap menerimanya di WhatsApp seperti biasa.
 *
 * Ada dua jalur, dan pilihannya bukan soal teknis semata:
 *
 * 1. 'cloud' — Meta WhatsApp Cloud API. Resmi.
 *    + Tidak akan diblokir karena memang jalur yang disediakan Meta.
 *    − Butuh Meta Business terverifikasi, nomor khusus yang TIDAK boleh
 *      dipakai di aplikasi WhatsApp biasa, dan templat pesan yang disetujui.
 *    − Ada aturan jendela 24 jam: di luar 24 jam sejak balasan terakhir
 *      vendor, hanya templat yang sudah disetujui yang boleh dikirim.
 *    − Berbayar per percakapan.
 *
 * 2. 'gateway' — layanan pihak ketiga (Fonnte, Wablas, dan sejenisnya)
 *    yang menyambung dengan cara memindai QR dari nomor WhatsApp biasa.
 *    + Murah, cepat dipasang, nomor lama tetap dipakai, tanpa aturan 24 jam.
 *    − Cara kerjanya TIDAK RESMI dan melanggar ketentuan layanan WhatsApp.
 *      Nomor bisa diblokir sewaktu-waktu, dan tidak ada jalur banding.
 *      Jangan memakai nomor utama bisnis untuk ini.
 *
 * 3. 'none' — tanpa penyedia. Panel tetap menyimpan riwayat, pengiriman
 *    dilakukan manual lewat tautan wa.me. Ini keadaan bawaan.
 */

function waPenyedia(): string
{
    $p = setting('wa_provider', 'none');
    return in_array($p, ['none', 'cloud', 'gateway', 'jembatan'], true) ? $p : 'none';
}

function waSiap(): bool
{
    return match (waPenyedia()) {
        'cloud'   => (bool) (setting('wa_phone_id') && setting('wa_token')),
        'gateway'  => (bool) (setting('wa_gateway_url') && setting('wa_gateway_token')),
        'jembatan' => (bool) (setting('wa_bridge_url') && setting('wa_bridge_token')),
        default    => false,
    };
}

function waCatatLog(string $arah, $payload, ?int $code = null): void
{
    $isi = is_string($payload) ? mb_substr($payload, 0, 60000) : json_encode($payload);
    try {
        try {
            q("INSERT INTO wa_log (arah, payload, http_code) VALUES (?,?,?)", [$arah, $isi, $code]);
        } catch (Throwable $e) {
            // Enum 'tolak' baru ada sejak migration-v11. Kalau belum dijalankan,
            // catatan tetap harus masuk — kehilangan jejak justru saat sedang
            // mendiagnosis adalah kegagalan yang paling merepotkan.
            if ($arah !== 'masuk' && $arah !== 'keluar') {
                q("INSERT INTO wa_log (arah, payload, http_code) VALUES ('masuk',?,?)",
                  ['[' . strtoupper($arah) . '] ' . $isi, $code]);
            } else {
                throw $e;
            }
        }
        // Buang catatan lebih dari 30 hari supaya tabel tidak menggelembung.
        if (random_int(1, 50) === 1) q("DELETE FROM wa_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    } catch (Throwable $e) { /* log gagal bukan alasan pengiriman gagal */ }
}

/**
 * Kirim pesan. Mengembalikan ['ok', 'id', 'error'].
 * Tidak pernah melempar — kegagalan kirim harus terlihat di room, bukan
 * membuat halaman error.
 */
function waKirim(string $nomor, string $teks): array
{
    $n = waNomor($nomor);
    if ($n === '')  return ['ok' => false, 'id' => null, 'error' => 'Nomor tujuan tidak valid.'];
    if ($teks === '') return ['ok' => false, 'id' => null, 'error' => 'Isi pesan kosong.'];
    if (!waSiap())  return ['ok' => false, 'id' => null, 'error' => 'Penyedia WhatsApp belum dikonfigurasi.'];

    try {
        return match (waPenyedia()) {
            'cloud'    => waKirimCloud($n, $teks),
            'jembatan' => waKirimJembatan($n, $teks),
            default    => waKirimGateway($n, $teks),
        };
    } catch (Throwable $e) {
        return ['ok' => false, 'id' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Jembatan Baileys milik sendiri.
 *
 * Bentuk permintaan dan balasannya sengaja dibuat MENIRU Fonnte — field
 * 'target' dan 'message' masuk, {status, id, detail} keluar. Akibatnya
 * wa-webhook.php, inc/chat.php, dan seluruh panel tidak perlu diubah sama
 * sekali saat berpindah penyedia. Yang berganti cuma alamat tujuannya.
 */
function waKirimJembatan(string $nomor, string $teks): array
{
    $url = rtrim(setting('wa_bridge_url'), '/') . '/send';
    $r = httpJson('POST', $url, ['Authorization: ' . setting('wa_bridge_token')], [
        'target'  => $nomor,
        'message' => $teks,
    ]);
    waCatatLog('keluar', $r['raw'], $r['code']);

    $j = $r['json'] ?? [];
    $id = $j['id'] ?? null;
    if (is_array($id)) $id = $id[0] ?? null;

    return [
        'ok'    => ($j['status'] ?? false) === true,
        'id'    => $id ? (string) $id : null,
        'error' => ($j['status'] ?? false) === true ? null
                 : ($j['detail'] ?? ($r['code'] === 0 ? 'Jembatan tidak bisa dihubungi.' : 'HTTP ' . $r['code'])),
    ];
}

/** Status sesi jembatan: terhubung, menunggu QR, atau mati. */
function waStatusJembatan(): array
{
    if (waPenyedia() !== 'jembatan' || !setting('wa_bridge_url')) {
        return ['ok' => false, 'state' => 'nonaktif'];
    }
    $r = httpJson('GET', rtrim(setting('wa_bridge_url'), '/') . '/status',
                  ['Authorization: ' . setting('wa_bridge_token')], null);
    if ($r['code'] === 0)   return ['ok' => false, 'state' => 'tak_terjangkau'];
    if ($r['code'] === 401) return ['ok' => false, 'state' => 'token_salah'];
    return ($r['json'] ?? []) + ['ok' => true];
}

/** Meta WhatsApp Cloud API. */
function waKirimCloud(string $nomor, string $teks): array
{
    $url = 'https://graph.facebook.com/v21.0/' . rawurlencode(setting('wa_phone_id')) . '/messages';
    $r = httpJson('POST', $url, ['Authorization: Bearer ' . setting('wa_token')], [
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $nomor,
        'type'              => 'text',
        'text'              => ['preview_url' => true, 'body' => $teks],
    ]);
    waCatatLog('keluar', $r['raw'], $r['code']);

    if ($r['code'] >= 400) {
        $m = $r['json']['error']['message'] ?? $r['raw'];
        // Pesan paling sering muncul di lapangan — dijelaskan supaya tidak bingung.
        if (str_contains($m, 're-engagement') || str_contains($m, '131047')) {
            $m .= ' — sudah lewat 24 jam sejak balasan terakhir. Di luar jendela itu Meta hanya mengizinkan templat yang sudah disetujui.';
        }
        return ['ok' => false, 'id' => null, 'error' => $m];
    }
    return ['ok' => true, 'id' => $r['json']['messages'][0]['id'] ?? null, 'error' => null];
}

/**
 * Gateway pihak ketiga. Nama field dibuat bisa diatur karena tiap layanan
 * memakai nama berbeda — Fonnte memakai target/message, Wablas memakai
 * phone/message, dan seterusnya.
 */
function waKirimGateway(string $nomor, string $teks): array
{
    $url    = setting('wa_gateway_url');
    $token  = setting('wa_gateway_token');
    $fT     = setting('wa_gateway_ftarget', 'target');
    $fB     = setting('wa_gateway_fbody', 'message');
    $mode   = setting('wa_gateway_auth', 'header');   // header | body | bearer

    $data = [$fT => $nomor, $fB => $teks];
    $head = [];
    if ($mode === 'header')      $head[] = 'Authorization: ' . $token;
    elseif ($mode === 'bearer')  $head[] = 'Authorization: Bearer ' . $token;
    else                         $data['token'] = $token;

    $r = httpJson('POST', $url, $head, $data);
    waCatatLog('keluar', $r['raw'], $r['code']);

    if ($r['code'] >= 400) {
        return ['ok' => false, 'id' => null, 'error' => 'Gateway ' . $r['code'] . ': ' . mb_substr($r['raw'], 0, 300)];
    }
    // Sebagian gateway membalas 200 tapi isinya status gagal.
    $j = $r['json'];
    $sukses = $j['status'] ?? $j['success'] ?? true;
    if ($sukses === false || $sukses === 'false') {
        return ['ok' => false, 'id' => null, 'error' => $j['reason'] ?? $j['message'] ?? 'Gateway menolak pesan.'];
    }
    $id = $j['id'] ?? $j['message_id'] ?? null;
    if (is_array($id)) $id = $id[0] ?? null;
    return ['ok' => true, 'id' => $id ? (string) $id : null, 'error' => null];
}

/**
 * Cocokkan nomor pengirim ke vendor atau klien.
 * Mengembalikan ['jenis' => 'vendor'|'klien'|null, 'id' => int, 'nama' => string].
 */
function waCocokkan(string $nomor): array
{
    $n = waNomor($nomor);
    if ($n === '') return ['jenis' => null, 'id' => 0, 'nama' => ''];

    // Bandingkan 9 digit terakhir — cukup unik, dan tahan terhadap
    // perbedaan penulisan 0812…, +62812…, 62812…
    $ekor = substr($n, -9);

    $v = one("SELECT id, name FROM vendors
              WHERE phone <> '' AND RIGHT(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''), 9) = ?
              LIMIT 1", [$ekor]);
    if ($v) return ['jenis' => 'vendor', 'id' => (int) $v['id'], 'nama' => $v['name']];

    $c = one("SELECT id, name, partner_name FROM clients
              WHERE phone <> '' AND RIGHT(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''), 9) = ?
              LIMIT 1", [$ekor]);
    if ($c) return ['jenis' => 'klien', 'id' => (int) $c['id'],
                    'nama' => trim($c['name'] . ($c['partner_name'] ? ' & ' . $c['partner_name'] : ''))];

    return ['jenis' => null, 'id' => 0, 'nama' => ''];
}

/**
 * Tentukan room mana yang menerima balasan vendor.
 *
 * Satu vendor bisa terlibat di beberapa pesta sekaligus, jadi balasan
 * dimasukkan ke room pesta yang PALING TERAKHIR dihubungi. Itu tebakan
 * terbaik yang bisa diambil tanpa bertanya, dan owner tetap bisa
 * memindahkannya kalau meleset.
 */
function waRoomVendor(int $vendorId): ?int
{
    $r = one("SELECT client_id FROM vendor_messages
              WHERE vendor_id = ? AND direction = 'keluar'
              ORDER BY created_at DESC LIMIT 1", [$vendorId]);
    if ($r) return (int) $r['client_id'];

    $r = one("SELECT cv.client_id FROM client_vendors cv
              JOIN clients c ON c.id = cv.client_id
              WHERE cv.vendor_id = ? AND c.stage NOT IN ('selesai','batal')
              ORDER BY cv.created_at DESC LIMIT 1", [$vendorId]);
    return $r ? (int) $r['client_id'] : null;
}

/** Simpan pesan masuk ke room yang tepat, atau ke kotak masuk kalau tidak dikenali. */
function waTerimaMasuk(string $dari, string $teks, ?string $waId = null, string $nama = ''): array
{
    $cocok = waCocokkan($dari);

    if ($cocok['jenis'] === 'vendor') {
        $clientId = waRoomVendor($cocok['id']);
        if ($clientId) {
            q("INSERT INTO vendor_messages (client_id, vendor_id, direction, channel, body, wa_id, wa_from, wa_status)
               VALUES (?,?,'masuk','wa',?,?,?,'sampai')",
              [$clientId, $cocok['id'], $teks, $waId, waNomor($dari)]);
            return ['ke' => 'room', 'client_id' => $clientId, 'vendor_id' => $cocok['id']];
        }
    }

    q("INSERT INTO wa_inbox (wa_from, nama, body, wa_id) VALUES (?,?,?,?)",
      [waNomor($dari), $nama ?: $cocok['nama'], $teks, $waId]);
    return ['ke' => 'inbox', 'jenis' => $cocok['jenis'], 'id' => $cocok['id']];
}

/** Perbarui status pesan keluar berdasarkan laporan penyedia. */
function waStatusPesan(string $waId, string $status): void
{
    $peta = ['sent' => 'terkirim', 'delivered' => 'sampai', 'read' => 'dibaca', 'failed' => 'gagal'];
    $s = $peta[$status] ?? null;
    if (!$s || $waId === '') return;
    q("UPDATE vendor_messages SET wa_status = ? WHERE wa_id = ? AND direction = 'keluar'", [$s, $waId]);
}

function waBelumDibaca(): int
{
    try { return (int) (one("SELECT COUNT(*) c FROM wa_inbox WHERE handled_at IS NULL")['c'] ?? 0); }
    catch (Throwable $e) { return 0; }
}
