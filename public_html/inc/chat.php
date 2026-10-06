<?php
require_once __DIR__ . '/wa.php';
require_once __DIR__ . '/vendor.php';   // waNomor() dipakai di hampir setiap fungsi room

/**
 * ============================================================
 * CHAT WHATSAPP TERPADU
 * ============================================================
 *
 * Kenapa modul ini ada:
 *
 * Struktur lama (vendor_messages) mengunci setiap pesan ke pasangan
 * (client_id, vendor_id). Konsekuensinya: nomor yang belum terdaftar
 * sebagai vendor TIDAK punya tempat menampung pesan, jadi jatuh ke
 * wa_inbox — daftar datar tanpa balasan. Itulah kenapa room-nya terasa
 * tidak bisa dipakai: separuh percakapan tidak pernah sampai ke room.
 *
 * Di sini dibalik. Yang jadi kunci adalah NOMOR, persis seperti WhatsApp
 * sungguhan. Setiap nomor otomatis dapat room begitu ada pesan pertama.
 * Kaitan ke klien/vendor/pesta jadi label opsional, bukan syarat masuk.
 *
 * ------------------------------------------------------------
 * BATAS YANG PERLU DIKETAHUI SEBELUM BERHARAP TERLALU BANYAK
 * ------------------------------------------------------------
 * Panel ini BUKAN WhatsApp Web dan tidak bisa jadi WhatsApp Web.
 * web.whatsapp.com mengirim header frame-ancestors, jadi tidak akan pernah
 * mau dibuka di dalam iframe — itu bukan soal kepintaran kode, memang
 * ditutup dari sananya. Yang dibangun di sini adalah tampilan dan rasa
 * yang sama, dengan Fonnte sebagai jembatannya.
 *
 * Yang IKUT tersinkron:
 *   - pesan masuk ke nomor yang terhubung ke Fonnte (lewat webhook)
 *   - pesan keluar yang dikirim DARI panel ini
 *   - status terkirim / sampai / dibaca (webhook update status)
 *
 * Yang TIDAK ikut, dan tidak ada jalan memutarnya:
 *   - riwayat sebelum webhook dipasang. Fonnte tidak punya API tarik
 *     riwayat, jadi room mulai kosong dan terisi maju sejak dipasang.
 *   - pesan yang diketik owner langsung dari HP-nya. Itu tidak lewat API,
 *     jadi tidak masuk webhook. Kalau ingin semuanya tercatat, semua
 *     balasan harus lewat panel.
 *   - pesan grup. Sengaja disaring (lihat field 'member' di webhook),
 *     karena pencocokan nomor jadi kacau di grup.
 *
 * Lampiran (kirim + terima) butuh paket Fonnte yang mendukungnya —
 * di paket dasar field url/filename tidak dikirim.
 */

/* ============================================================
   ROOM
   ============================================================ */

/**
 * Cari room berdasarkan nomor; buat kalau belum ada.
 *
 * Pencocokan ke klien/vendor dilakukan sekali saat room dibuat, lalu
 * disimpan. Jadi tidak ada LIKE/RIGHT() di jalur panas — itu penting,
 * karena fungsi lama membandingkan 9 digit terakhir dengan RIGHT() pada
 * setiap pesan masuk, dan itu tidak bisa memakai index sama sekali.
 */
function chatRoom(string $nomor, string $namaKontak = ''): int
{
    // Tiga bentuk pengenal yang mungkin datang:
    //   6281234567890        nomor biasa
    //   1203…@g.us           ID grup
    //   1234567890…@lid      pengenal baru WhatsApp
    //
    // @lid adalah identitas anonim yang WhatsApp perkenalkan menggantikan
    // nomor telepon di jalur tertentu. Kalau dilewatkan waNomor(), akhirannya
    // terpotong dan menyisakan deretan angka yang MIRIP nomor telepon padahal
    // bukan — itu akan membuat room palsu yang tidak bisa dibalas dan tidak
    // bisa dicocokkan ke klien mana pun. Disimpan utuh supaya tetap satu room
    // per lawan bicara, dan bisa dipetakan ke nomor asli kalau nanti ketahuan.
    $n = (str_contains($nomor, '@g.us') || str_contains($nomor, '@lid'))
       ? trim($nomor)
       : waNomor($nomor);
    if ($n === '') return 0;

    $r = one("SELECT id, nama FROM wa_chats WHERE wa_number = ?", [$n]);
    if ($r) {
        // Nama profil WhatsApp baru diketahui belakangan — isi kalau masih kosong.
        if ($namaKontak !== '' && $r['nama'] === '') {
            q("UPDATE wa_chats SET nama = ? WHERE id = ?", [mb_substr($namaKontak, 0, 120), $r['id']]);
        }
        return (int) $r['id'];
    }

    $cocok = chatCocokkan($n);
    q("INSERT INTO wa_chats (wa_number, nama, jenis, client_id, vendor_id, last_at)
       VALUES (?,?,?,?,?,NOW())",
      [$n,
       mb_substr($cocok['nama'] ?: $namaKontak, 0, 120),
       $cocok['jenis'],
       $cocok['jenis'] === 'klien'  ? $cocok['id'] : null,
       $cocok['jenis'] === 'vendor' ? $cocok['id'] : null]);

    return insertId();
}

/**
 * Cocokkan nomor ke vendor atau klien.
 *
 * Dibandingkan 9 digit terakhir supaya tahan terhadap 0812…, +62812…,
 * dan 62812…. Query ini hanya jalan saat room PERTAMA kali dibuat, jadi
 * biaya full-scan-nya dibayar sekali per nomor, bukan tiap pesan.
 */
function chatCocokkan(string $nomor): array
{
    // @lid tidak memuat nomor telepon sama sekali, jadi tidak ada yang bisa
    // dicocokkan. Dibiarkan sebagai 'lainnya' — lebih baik room tanpa nama
    // daripada salah menautkannya ke klien yang keliru.
    if (str_contains($nomor, '@lid')) return ['jenis' => 'lainnya', 'id' => 0, 'nama' => ''];

    $ekor = substr($nomor, -9);
    if ($ekor === '') return ['jenis' => 'lainnya', 'id' => 0, 'nama' => ''];

    $v = one("SELECT id, name FROM vendors
              WHERE phone <> '' AND RIGHT(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),9) = ?
              LIMIT 1", [$ekor]);
    if ($v) return ['jenis' => 'vendor', 'id' => (int) $v['id'], 'nama' => $v['name']];

    $c = one("SELECT id, name, partner_name FROM clients
              WHERE phone <> '' AND RIGHT(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),9) = ?
              LIMIT 1", [$ekor]);
    if ($c) return ['jenis' => 'klien', 'id' => (int) $c['id'],
                    'nama' => trim($c['name'] . ($c['partner_name'] ? ' & ' . $c['partner_name'] : ''))];

    return ['jenis' => 'lainnya', 'id' => 0, 'nama' => ''];
}

/**
 * Pastikan seorang klien punya room chat, dan room itu tertaut ke datanya.
 *
 * Dipanggil setiap kali klien disimpan. Tanpa ini, room hanya lahir saat ada
 * pesan pertama — jadi klien yang baru dicatat tidak muncul di daftar kontak
 * sampai seseorang menghubunginya lebih dulu. Padahal urutan yang wajar
 * justru sebaliknya: dicatat dulu, baru dihubungi.
 *
 * Sekaligus memperbaiki nama yang basi: nama disimpan di baris room saat
 * dibuat, jadi klien yang belakangan berganti nama akan tetap tampil dengan
 * nama lamanya kalau room-nya tidak pernah tertaut ke client_id.
 */
function chatSinkronKlien(int $clientId): int
{
    $c = one("SELECT id, name, partner_name, phone FROM clients WHERE id = ?", [$clientId]);
    if (!$c || trim((string) $c['phone']) === '') return 0;

    $no = waNomor($c['phone']);
    if ($no === '') return 0;

    $nama = trim($c['name'] . ($c['partner_name'] ? ' & ' . $c['partner_name'] : ''));

    $ada = one("SELECT id, client_id FROM wa_chats WHERE wa_number = ?", [$no]);
    if ($ada) {
        q("UPDATE wa_chats SET client_id = ?, jenis = 'klien', nama = ? WHERE id = ?",
          [$clientId, mb_substr($nama, 0, 120), $ada['id']]);
        return (int) $ada['id'];
    }

    // Nomor klien bisa berpindah. Room lama yang masih menunjuk klien ini
    // dilepas kaitannya, bukan dihapus — riwayat percakapannya tetap berharga.
    q("UPDATE wa_chats SET client_id = NULL, jenis = 'lainnya'
       WHERE client_id = ? AND wa_number <> ?", [$clientId, $no]);

    q("INSERT INTO wa_chats (wa_number, nama, jenis, client_id, last_at)
       VALUES (?,?,'klien',?,NULL)", [$no, mb_substr($nama, 0, 120), $clientId]);
    return insertId();
}

/** Sama untuk vendor. */
function chatSinkronVendor(int $vendorId): int
{
    $v = one("SELECT id, name, phone FROM vendors WHERE id = ?", [$vendorId]);
    if (!$v || trim((string) $v['phone']) === '') return 0;

    $no = waNomor($v['phone']);
    if ($no === '') return 0;

    $ada = one("SELECT id FROM wa_chats WHERE wa_number = ?", [$no]);
    if ($ada) {
        q("UPDATE wa_chats SET vendor_id = ?, jenis = IF(client_id IS NULL,'vendor',jenis), nama = ?
           WHERE id = ?", [$vendorId, mb_substr($v['name'], 0, 120), $ada['id']]);
        return (int) $ada['id'];
    }
    q("INSERT INTO wa_chats (wa_number, nama, jenis, vendor_id, last_at)
       VALUES (?,?,'vendor',?,NULL)", [$no, mb_substr($v['name'], 0, 120), $vendorId]);
    return insertId();
}

/**
 * Tautkan ulang room yang belum punya pemilik.
 *
 * Murah karena hanya menyentuh baris yang client_id DAN vendor_id-nya kosong —
 * setelah sekali beres, pemanggilan berikutnya tidak menemukan apa-apa.
 */
function chatTautUlang(): int
{
    $n = 0;

    // ---- 1. Buatkan room untuk SEMUA klien & vendor yang belum punya ----
    // Versi sebelumnya hanya menautkan room yang sudah ada. Akibatnya klien
    // dan vendor yang dicatat SEBELUM fitur ini dipasang tidak pernah muncul
    // sama sekali — dan dari layar, tidak ada yang berubah.
    $klien = all("SELECT c.id FROM clients c
                  WHERE c.phone <> ''
                    AND NOT EXISTS (SELECT 1 FROM wa_chats w WHERE w.client_id = c.id)
                  LIMIT 300");
    foreach ($klien as $k) if (chatSinkronKlien((int) $k['id'])) $n++;

    $vendor = all("SELECT v.id FROM vendors v
                   WHERE v.phone <> '' AND v.is_active = 1
                     AND NOT EXISTS (SELECT 1 FROM wa_chats w WHERE w.vendor_id = v.id)
                   LIMIT 300");
    foreach ($vendor as $v) if (chatSinkronVendor((int) $v['id'])) $n++;

    // ---- 2. Lepas kaitan ke klien/vendor yang sudah dihapus ----
    // Room yang menunjuk baris hilang akan menampilkan nama basi selamanya,
    // karena JOIN-nya kosong dan tampilan jatuh ke salinan nama lama.
    q("UPDATE wa_chats w LEFT JOIN clients c ON c.id = w.client_id
       SET w.client_id = NULL WHERE w.client_id IS NOT NULL AND c.id IS NULL");
    q("UPDATE wa_chats w LEFT JOIN vendors v ON v.id = w.vendor_id
       SET w.vendor_id = NULL WHERE w.vendor_id IS NOT NULL AND v.id IS NULL");

    // ---- 3. Tautkan room yatim berdasarkan nomor ----
    $yatim = all("SELECT id, wa_number FROM wa_chats
                  WHERE client_id IS NULL AND vendor_id IS NULL AND is_group = 0 LIMIT 200");
    foreach ($yatim as $r) {
        $cocok = chatCocokkan($r['wa_number']);
        if ($cocok['jenis'] === 'lainnya') continue;
        q("UPDATE wa_chats SET jenis = ?, client_id = ?, vendor_id = ?, nama = ? WHERE id = ?",
          [$cocok['jenis'],
           $cocok['jenis'] === 'klien'  ? $cocok['id'] : null,
           $cocok['jenis'] === 'vendor' ? $cocok['id'] : null,
           mb_substr($cocok['nama'], 0, 120), $r['id']]);
        $n++;
    }

    // ---- 4. Segarkan nama room yang tertaut tapi namanya sudah berubah ----
    q("UPDATE wa_chats w JOIN clients c ON c.id = w.client_id
       SET w.nama = TRIM(CONCAT(c.name, IF(c.partner_name <> '', CONCAT(' & ', c.partner_name), ''))),
           w.jenis = 'klien'
       WHERE w.nama <> TRIM(CONCAT(c.name, IF(c.partner_name <> '', CONCAT(' & ', c.partner_name), '')))");

    q("UPDATE wa_chats w JOIN vendors v ON v.id = w.vendor_id
       SET w.nama = v.name, w.jenis = IF(w.client_id IS NULL, 'vendor', w.jenis)
       WHERE w.nama <> v.name");

    return $n;
}

/** Pesta mana yang sedang dibicarakan dengan vendor ini. */
function chatPestaAktif(int $chatId): ?int
{
    $ch = one("SELECT client_id, vendor_id, room_client_id FROM wa_chats WHERE id = ?", [$chatId]);
    if (!$ch) return null;
    if ($ch['room_client_id']) return (int) $ch['room_client_id'];
    if ($ch['client_id'])      return (int) $ch['client_id'];
    if (!$ch['vendor_id'])     return null;

    // Vendor bisa terlibat beberapa pesta. Ambil pesta aktif terakhir —
    // tebakan terbaik tanpa bertanya, dan owner tetap bisa memindahkannya.
    $r = one("SELECT cv.client_id FROM client_vendors cv
              JOIN clients c ON c.id = cv.client_id
              WHERE cv.vendor_id = ? AND c.stage NOT IN ('selesai','batal')
              ORDER BY cv.created_at DESC LIMIT 1", [$ch['vendor_id']]);
    return $r ? (int) $r['client_id'] : null;
}


/* ============================================================
   PESAN
   ============================================================ */

/** Simpan satu pesan dan segarkan ringkasan room. */
function chatSimpan(int $chatId, array $d): int
{
    $dir = $d['direction'] ?? 'keluar';

    q("INSERT INTO wa_messages
        (chat_id, client_id, vendor_id, direction, channel, body,
         media_url, media_name, media_type, wa_id, reply_to,
         wa_status, wa_from, user_id, sent_at, sender_name)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [$chatId,
       $d['client_id'] ?? null,
       $d['vendor_id'] ?? null,
       $dir,
       $d['channel']    ?? 'wa',
       $d['body']       ?? '',
       $d['media_url']  ?? null,
       $d['media_name'] ?? null,
       $d['media_type'] ?? null,
       $d['wa_id']      ?? null,
       $d['reply_to']   ?? null,
       $d['wa_status']  ?? 'lokal',
       $d['wa_from']    ?? '',
       $d['user_id']    ?? null,
       $d['sent_at']    ?? null,
       $d['sender_name'] ?? '']);

    $id = insertId();

    // Ringkasan disimpan di baris room supaya daftar percakapan tidak perlu
    // menghitung MAX(id) per room — itu yang bikin daftar chat lambat begitu
    // pesannya menumpuk.
    $cuplik = mb_substr(trim(preg_replace('/\s+/', ' ', $d['body'] ?? '')), 0, 220);
    q("UPDATE wa_chats
       SET last_at = NOW(), last_body = ?, last_dir = ?,
           unread = " . ($dir === 'masuk' ? 'unread + 1' : 'unread') . "
       WHERE id = ?", [$cuplik, $dir, $chatId]);

    return $id;
}

/**
 * Kirim pesan lewat penyedia lalu catat hasilnya.
 *
 * Pesan SELALU disimpan lebih dulu dengan status 'antre', baru dikirim.
 * Urutan ini disengaja: kalau gateway lambat atau mati, pesan tetap
 * terlihat di room dengan tanda gagal — bukan hilang tanpa jejak.
 */
function chatKirim(int $chatId, string $teks, ?int $userId = null, array $opt = []): array
{
    $ch = one("SELECT * FROM wa_chats WHERE id = ?", [$chatId]);
    if (!$ch) return ['ok' => false, 'error' => 'Room tidak ditemukan.'];

    $teks = trim($teks);
    if ($teks === '') return ['ok' => false, 'error' => 'Isi pesan kosong.'];

    $pesta = $opt['client_id'] ?? chatPestaAktif($chatId);

    $berkas = $opt['berkas'] ?? null;
    $mid = chatSimpan($chatId, [
        'direction'  => 'keluar',
        'channel'    => 'wa',
        'body'       => $teks,
        'client_id'  => $pesta,
        'vendor_id'  => $ch['vendor_id'],
        'wa_from'    => $ch['wa_number'],
        'user_id'    => $userId,
        'wa_status'  => waSiap() ? 'antre' : 'lokal',
        'reply_to'   => $opt['reply_to'] ?? null,
        'media_url'  => $berkas['url'] ?? null,
        'media_name' => $berkas['nama'] ?? null,
        'media_type' => $berkas ? 'application/pdf' : null,
    ]);

    if (!waSiap()) {
        return ['ok' => true, 'id' => $mid, 'terkirim' => false,
                'error' => null,
                'wa_url' => 'https://wa.me/' . $ch['wa_number'] . '?text=' . rawurlencode($teks)];
    }

    $r = waKirim($ch['wa_number'], $teks, $berkas);
    if ($r['ok']) {
        q("UPDATE wa_messages SET wa_status='terkirim', wa_id=?, sent_at=NOW() WHERE id=?",
          [$r['id'], $mid]);
    } else {
        q("UPDATE wa_messages SET wa_status='gagal', wa_error=? WHERE id=?",
          [mb_substr((string) $r['error'], 0, 400), $mid]);
    }

    return ['ok' => $r['ok'], 'id' => $mid, 'terkirim' => $r['ok'], 'error' => $r['error']];
}

/**
 * Terima pesan masuk dari webhook.
 * Mengembalikan chat_id supaya webhook bisa mencatat ke log.
 */
function chatTerima(string $dari, string $teks, array $meta = []): int
{
    $chatId = chatRoom($dari, $meta['nama'] ?? '');
    if (!$chatId) return 0;

    // Anti-duplikat: Fonnte mengirim ulang webhook sampai 15x kalau balasan
    // kita bukan 200. Tanpa penjagaan ini, satu pesan bisa muncul berkali-kali.
    if (!empty($meta['wa_id'])) {
        $ada = one("SELECT id FROM wa_messages WHERE wa_id = ? LIMIT 1", [$meta['wa_id']]);
        if ($ada) return $chatId;
    }

    $ch = one("SELECT client_id, vendor_id, is_group FROM wa_chats WHERE id = ?", [$chatId]);

    // Di grup, 'sender' adalah ID grup dan pengirim sebenarnya ada di 'member'.
    if (!empty($ch['is_group']) && !empty($meta['member'])) {
        grupCatatAnggota($chatId, $meta['member'], $meta['nama'] ?? '');
    }

    chatSimpan($chatId, [
        'direction'  => 'masuk',
        'channel'    => 'wa',
        'body'       => $teks,
        'client_id'  => chatPestaAktif($chatId),
        'vendor_id'  => $ch['vendor_id'] ?? null,
        'wa_id'      => $meta['wa_id'] ?? null,
        'wa_from'    => waNomor($dari),
        'wa_status'  => 'sampai',
        'media_url'  => $meta['media_url']  ?? null,
        'media_name' => $meta['media_name'] ?? null,
        'media_type' => $meta['media_type'] ?? null,
        'sender_name'=> $meta['member'] ? ($meta['nama'] ?? $meta['member']) : '',
    ]);

    return $chatId;
}

/** Perbarui status pesan keluar berdasarkan laporan penyedia. */
function chatStatus(string $waId, string $status): void
{
    $peta = ['sent' => 'terkirim', 'delivered' => 'sampai', 'read' => 'dibaca',
             'failed' => 'gagal', 'pending' => 'antre'];
    $s = $peta[strtolower($status)] ?? null;
    if (!$s || $waId === '') return;
    q("UPDATE wa_messages SET wa_status = ? WHERE wa_id = ? AND direction = 'keluar'", [$s, $waId]);
}


/* ============================================================
   BACAAN UNTUK TAMPILAN
   ============================================================ */

/** Daftar room untuk panel kiri. */
function chatDaftar(string $cari = '', string $filter = 'semua', int $limit = 120): array
{
    $w = ["ch.archived = 0"];
    $p = [];

    if ($filter === 'belum')  $w[] = "ch.unread > 0";
    if ($filter === 'klien')  $w[] = "ch.jenis = 'klien'";
    if ($filter === 'vendor') $w[] = "ch.jenis = 'vendor'";
    if ($filter === 'grup')   $w[] = "ch.is_group = 1";
    if ($filter === 'lain')   $w[] = "ch.jenis = 'lainnya'";
    // Kontak yang sudah punya room tapi belum pernah ada pesannya.
    if ($filter === 'kontak') $w[] = "ch.last_at IS NULL";
    if ($filter === 'aktif')  $w[] = "ch.last_at IS NOT NULL";

    if ($cari !== '') {
        $w[] = "(ch.nama LIKE ? OR ch.wa_number LIKE ? OR ch.group_subject LIKE ?)";
        $p[] = '%' . $cari . '%';
        $p[] = '%' . preg_replace('/\D/', '', $cari) . '%';
        $p[] = '%' . $cari . '%';
    }

    // Room yang belum pernah ada pesannya diletakkan di bawah, bukan dibuang.
    // Kontak vendor tetap harus terlihat walau belum pernah dichat — itu
    // kebiasaan yang dibawa orang dari WhatsApp, dan melanggarnya bikin
    // panel terasa kehilangan data.
    return all("SELECT ch.*,
                       c.name  AS klien_nama, c.partner_name, c.stage,
                       v.name  AS vendor_nama, v.category
                FROM wa_chats ch
                LEFT JOIN clients c ON c.id = ch.client_id
                LEFT JOIN vendors v ON v.id = ch.vendor_id
                WHERE " . implode(' AND ', $w) . "
                ORDER BY ch.pinned DESC, ch.last_at IS NULL, ch.last_at DESC, ch.nama
                LIMIT $limit", $p);
}


/* ============================================================
   GRUP WHATSAPP
   ============================================================

   Fonnte tidak menyediakan cara membuat grup lewat API — yang ada hanya
   menyegarkan daftar (fetch-group) dan membacanya (get-whatsapp-group).
   Jadi alurnya sama persis dengan catatan owner: admin office membuat grup
   di HP dan memasukkan vendornya sendiri, lalu panel menariknya ke sini.

   fetch-group SENGAJA tidak dipanggil otomatis. Dokumentasi Fonnte
   memperingatkan pemanggilan berlebihan bisa membuat nomor kena banned,
   jadi ini harus jadi tombol yang ditekan sadar, bukan cron.
*/

/**
 * Nomor perangkat yang sedang terhubung ke gateway.
 *
 * Perlu diketahui karena satu kesalahan pemasangan sangat sulit disadari:
 * mengirim ke nomor perangkat itu sendiri. Pengirimannya BERHASIL — WhatsApp
 * punya fitur "Message Yourself" — jadi dari panel semuanya tampak normal.
 * Tapi balasan yang diketik di perangkat itu adalah pesan KELUAR dari sudut
 * pandang gateway, dan webhook hanya menyala untuk pesan MASUK. Hasilnya:
 * kirim bisa, terima tidak pernah, tanpa satu pun galat.
 *
 * Disimpan di settings supaya tidak memanggil API pada setiap muat halaman.
 */
function waNomorPerangkat(bool $paksa = false): string
{
    $simpan = (string) setting('wa_device_number');
    if ($simpan !== '' && !$paksa) return $simpan;
    if (waPenyedia() !== 'gateway' || !setting('wa_gateway_token')) return '';

    try {
        $r = httpJson('POST', 'https://api.fonnte.com/device',
                      ['Authorization: ' . setting('wa_gateway_token')], []);
        $no = (string) ($r['json']['device'] ?? '');
        if ($no !== '') { settingSet('wa_device_number', waNomor($no)); return waNomor($no); }
    } catch (Throwable $e) { /* gagal ambil bukan alasan halaman tidak terbuka */ }

    return $simpan;
}

/** Apakah room ini sebenarnya nomor perangkat gateway sendiri? */
function chatKeDiriSendiri(string $nomor): bool
{
    $dev = waNomorPerangkat();
    return $dev !== '' && waNomor($nomor) === $dev;
}

/** Segarkan daftar grup di sisi Fonnte. Tekan hanya setelah membuat grup baru. */
function waGrupSegarkan(): array
{
    if (waPenyedia() !== 'gateway') return ['ok' => false, 'error' => 'Hanya tersedia untuk gateway.'];
    $r = httpJson('POST', 'https://api.fonnte.com/fetch-group',
                  ['Authorization: ' . setting('wa_gateway_token')], []);
    waCatatLog('keluar', $r['raw'], $r['code']);
    $j = $r['json'] ?? [];
    return ['ok' => ($j['status'] ?? false) === true,
            'error' => $j['detail'] ?? ($r['code'] >= 400 ? 'HTTP ' . $r['code'] : null)];
}

/** Ambil daftar grup lalu simpan sebagai room. */
function waGrupTarik(): array
{
    if (waPenyedia() !== 'gateway') return ['ok' => false, 'error' => 'Hanya tersedia untuk gateway.', 'jumlah' => 0];

    $r = httpJson('POST', 'https://api.fonnte.com/get-whatsapp-group',
                  ['Authorization: ' . setting('wa_gateway_token')], []);
    waCatatLog('keluar', $r['raw'], $r['code']);

    $j = $r['json'] ?? [];
    if (($j['status'] ?? false) !== true) {
        return ['ok' => false, 'jumlah' => 0,
                'error' => $j['detail'] ?? 'Daftar grup belum pernah disegarkan. Tekan "Segarkan dari WhatsApp" dulu.'];
    }

    $n = 0;
    foreach ($j['data'] ?? [] as $g) {
        $gid  = trim((string) ($g['id'] ?? ''));
        $nama = trim((string) ($g['name'] ?? ''));
        if ($gid === '') continue;

        $ada = one("SELECT id FROM wa_chats WHERE wa_number = ?", [$gid]);
        if ($ada) {
            q("UPDATE wa_chats SET nama = ?, group_subject = ?, synced_at = NOW() WHERE id = ?",
              [$nama, $nama, $ada['id']]);
        } else {
            q("INSERT INTO wa_chats (wa_number, nama, jenis, is_group, group_subject, synced_at)
               VALUES (?,?,'grup',1,?,NOW())", [$gid, $nama, $nama]);
            $n++;
        }
    }
    settingSet('wa_group_synced_at', date('Y-m-d H:i:s'));
    return ['ok' => true, 'jumlah' => $n, 'total' => count($j['data'] ?? []), 'error' => null];
}

/** Catat siapa yang bicara di dalam grup — dipakai untuk menebak vendornya. */
function grupCatatAnggota(int $chatId, string $nomor, string $nama = ''): void
{
    $n = waNomor($nomor);
    if ($n === '') return;

    $v = one("SELECT id FROM vendors
              WHERE phone <> '' AND RIGHT(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),9) = ?
              LIMIT 1", [substr($n, -9)]);

    q("INSERT INTO wa_group_members (chat_id, wa_number, nama, vendor_id, terakhir)
       VALUES (?,?,?,?,NOW())
       ON DUPLICATE KEY UPDATE terakhir = NOW(),
                               nama = IF(VALUES(nama) <> '', VALUES(nama), nama),
                               vendor_id = COALESCE(vendor_id, VALUES(vendor_id))",
      [$chatId, $n, mb_substr($nama, 0, 120), $v['id'] ?? null]);
}

function grupAnggota(int $chatId): array
{
    return all("SELECT m.*, v.name vendor_nama, v.category
                FROM wa_group_members m
                LEFT JOIN vendors v ON v.id = m.vendor_id
                WHERE m.chat_id = ? ORDER BY m.terakhir DESC", [$chatId]);
}

/**
 * Pesan dalam satu room.
 *
 * $afterId dipakai untuk polling: klien hanya meminta yang lebih baru dari
 * id terakhir yang sudah dipegang. Itu sebabnya urutannya pakai id, bukan
 * created_at — dua pesan bisa punya detik yang sama.
 */
function chatPesan(int $chatId, int $afterId = 0, int $limit = 60): array
{
    if ($afterId > 0) {
        return all("SELECT * FROM wa_messages WHERE chat_id = ? AND id > ?
                    ORDER BY id ASC LIMIT $limit", [$chatId, $afterId]);
    }
    // Ambil N terakhir, lalu balik urutannya supaya tampil kronologis.
    $rows = all("SELECT * FROM wa_messages WHERE chat_id = ?
                 ORDER BY id DESC LIMIT $limit", [$chatId]);
    return array_reverse($rows);
}

function chatTandaiBaca(int $chatId): void
{
    q("UPDATE wa_chats SET unread = 0 WHERE id = ?", [$chatId]);
}

function chatBelumDibaca(): int
{
    try { return (int) (one("SELECT COALESCE(SUM(unread),0) c FROM wa_chats WHERE archived = 0")['c'] ?? 0); }
    catch (Throwable $e) { return 0; }
}

/** Nama tampil sebuah room. */
function chatNama(array $ch): string
{
    if (!empty($ch['is_group'])) return $ch['group_subject'] ?: ($ch['nama'] ?: 'Grup tanpa nama');
    if (str_contains((string) ($ch['wa_number'] ?? ''), '@lid')) {
        return $ch['nama'] ?: 'Kontak tanpa nomor (@lid)';
    }
    if (!empty($ch['klien_nama'])) {
        return trim($ch['klien_nama'] . (!empty($ch['partner_name']) ? ' & ' . $ch['partner_name'] : ''));
    }
    if (!empty($ch['vendor_nama'])) return $ch['vendor_nama'];
    if (!empty($ch['nama']))        return $ch['nama'];
    return '+' . ($ch['wa_number'] ?? '');
}

/** Jam relatif ala WhatsApp: hari ini jam saja, kemarin "Kemarin", sisanya tanggal. */
function chatWaktu(?string $ts): string
{
    if (!$ts) return '';
    $t = strtotime($ts);
    if (date('Y-m-d', $t) === date('Y-m-d'))                     return date('H.i', $t);
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'Kemarin';
    if (date('Y', $t) === date('Y'))                              return date('d M', $t);
    return date('d/m/y', $t);
}

/** Tanda centang status, dibaca sekali lihat. */
function chatCentang(string $status): array
{
    return match ($status) {
        'antre'    => ['🕗', 'Menunggu antrean',       'muted'],
        'terkirim' => ['✓',  'Terkirim ke WhatsApp',   'muted'],
        'sampai'   => ['✓✓', 'Sampai di HP penerima',  'muted'],
        'dibaca'   => ['✓✓', 'Sudah dibaca',           'read'],
        'gagal'    => ['⚠',  'Gagal dikirim',          'bad'],
        default    => ['',   'Dicatat di panel saja',  'muted'],
    };
}

/**
 * Ubah teks bergaya WhatsApp menjadi HTML aman.
 * e() dijalankan LEBIH DULU, jadi tag apa pun dari lawan bicara sudah mati
 * sebelum penanda tebal/miring diproses.
 */
function chatFormat(string $teks): string
{
    $h = e($teks);
    $h = preg_replace('/(?<!\S)\*(\S(?:[^*\n]*\S)?)\*(?!\S)/u', '<b>$1</b>', $h);
    $h = preg_replace('/(?<!\S)_(\S(?:[^_\n]*\S)?)_(?!\S)/u',   '<i>$1</i>', $h);
    $h = preg_replace('/(?<!\S)~(\S(?:[^~\n]*\S)?)~(?!\S)/u',   '<s>$1</s>', $h);
    $h = preg_replace('/```(.+?)```/us', '<code>$1</code>', $h);
    $h = preg_replace(
        '~\bhttps?://[^\s<]+~i',
        '<a href="$0" target="_blank" rel="noopener nofollow">$0</a>',
        $h
    );
    return nl2br($h, false);
}
