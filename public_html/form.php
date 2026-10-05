<?php
/**
 * FORMULIR KLIEN PUBLIK — form.callalily.party
 *
 * Satu-satunya halaman di situs ini yang boleh menulis ke tabel clients
 * tanpa login. Karena itu penjagaannya berlapis, dan tiap lapisnya menutup
 * celah yang berbeda:
 *
 *   1. Honeypot        — menangkap bot yang mengisi semua kolom
 *   2. Jeda minimum    — menangkap bot yang mengirim dalam sekejap
 *   3. Throttle per IP — menahan banjir kiriman dari satu sumber
 *   4. Token sesi      — menahan kiriman dari luar halaman ini
 *   5. Validasi ketat  — hanya kolom yang dikenal yang masuk database
 *
 * CAPTCHA sengaja tidak dipakai. Untuk formulir yang mungkin diisi lima
 * kali sehari, biayanya lebih besar daripada manfaatnya: satu permintaan
 * ke server pihak ketiga, dan satu rintangan lagi buat calon klien yang
 * sedang berniat menghubungi. Empat lapis di atas sudah menghentikan
 * hampir semua kiriman otomatis.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/pipeline.php';
require_once __DIR__ . '/partials/blok-top5.php';

/**
 * Aset (CSS, font, gambar) SELALU ditarik dari domain induk, bukan dari
 * subdomain. url() memakai BASE_URL yang menunjuk ke callalily.party, jadi
 * ini terjadi sendirinya — dan memang yang diinginkan: satu salinan CSS,
 * satu cache peramban, dan tidak perlu sertifikat aset terpisah.
 *
 * Yang perlu dijaga adalah tautan BALIK ke situs induk supaya tidak
 * menunjuk ke subdomain. Karena semuanya memakai url(), itu pun otomatis.
 */
if (setting('form_aktif', '1') !== '1') {
    http_response_code(503);
    exit('Formulir sedang ditutup sementara.');
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['form_mulai'])) $_SESSION['form_mulai'] = time();
if (empty($_SESSION['form_nonce'])) $_SESSION['form_nonce'] = bin2hex(random_bytes(16));

$galat = [];
$sukses = false;
$isi = [];

/* ============================================================
   PENJAGAAN
   ============================================================ */
function ipPengirim(): string
{
    // Header proxy TIDAK dipercaya. Siapa pun bisa memalsukan
    // X-Forwarded-For, dan throttle yang bisa dilewati dengan satu header
    // sama saja dengan tidak ada throttle.
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function bolehKirim(string $ip): bool
{
    try {
        q("DELETE FROM form_throttle WHERE terakhir < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $r = one("SELECT jumlah FROM form_throttle
                  WHERE ip = ? AND terakhir > DATE_SUB(NOW(), INTERVAL 1 HOUR)", [$ip]);
        return !$r || (int) $r['jumlah'] < 5;
    } catch (Throwable $e) {
        return true;   // tabel belum ada — jangan halangi calon klien
    }
}

function catatKirim(string $ip): void
{
    try {
        q("INSERT INTO form_throttle (ip, jumlah) VALUES (?, 1)
           ON DUPLICATE KEY UPDATE
             jumlah = IF(terakhir < DATE_SUB(NOW(), INTERVAL 1 HOUR), 1, jumlah + 1)", [$ip]);
    } catch (Throwable $e) { /* pencatatan gagal bukan alasan menolak */ }
}

/* ============================================================
   PROSES
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = ipPengirim();

    // Honeypot: kolom tersembunyi yang manusia tidak pernah lihat.
    // Diberi nama yang menggoda bot ('website'), bukan 'honeypot'.
    if (trim($_POST['website'] ?? '') !== '') {
        $sukses = true;   // bot dibiarkan mengira berhasil — tidak diberi umpan balik
    } elseif (!hash_equals($_SESSION['form_nonce'] ?? '', $_POST['_n'] ?? '')) {
        $galat[] = 'Halaman kedaluwarsa. Muat ulang lalu isi lagi.';
    } elseif (time() - (int) ($_SESSION['form_mulai'] ?? 0) < 4) {
        // Manusia tidak bisa mengisi tujuh kolom dalam empat detik.
        $galat[] = 'Terlalu cepat. Coba kirim sekali lagi.';
    } elseif (!bolehKirim($ip)) {
        $galat[] = 'Sudah beberapa kali mengirim dari perangkat ini. Coba lagi satu jam lagi, '
                 . 'atau hubungi kami langsung lewat WhatsApp.';
    } else {
        $isi = [
            'pria'    => mb_substr(trim($_POST['pria'] ?? ''), 0, 120),
            'wanita'  => mb_substr(trim($_POST['wanita'] ?? ''), 0, 120),
            'wa'      => preg_replace('/\D/', '', $_POST['wa'] ?? ''),
            'email'   => mb_substr(trim($_POST['email'] ?? ''), 0, 160),
            'tanggal' => trim($_POST['tanggal'] ?? ''),
            'tamu'    => (int) preg_replace('/\D/', '', $_POST['tamu'] ?? '0'),
            'tamu_akad' => (int) preg_replace('/\D/', '', $_POST['tamu_akad'] ?? '0'),
            'tamu_res'  => (int) preg_replace('/\D/', '', $_POST['tamu_resepsi'] ?? '0'),
            'kota'    => mb_substr(trim($_POST['kota'] ?? ''), 0, 90),
            'venue'   => mb_substr(trim($_POST['venue'] ?? ''), 0, 190),
            'budget'  => (int) preg_replace('/\D/', '', $_POST['budget'] ?? '0'),
            'sumber'  => trim($_POST['sumber'] ?? ''),
            'catatan' => mb_substr(trim($_POST['catatan'] ?? ''), 0, 2000),
            'brief'   => mb_substr(trim($_POST['brief'] ?? ''), 0, 4000),
            // Base information
            'akad_t'  => trim($_POST['akad_tanggal'] ?? ''),
            'akad_j'  => trim($_POST['akad_jam'] ?? ''),
            'akad_l'  => mb_substr(trim($_POST['akad_lokasi'] ?? ''), 0, 190),
            'res_t'   => trim($_POST['resepsi_tanggal'] ?? ''),
            'res_j'   => trim($_POST['resepsi_jam'] ?? ''),
            'res_l'   => mb_substr(trim($_POST['resepsi_lokasi'] ?? ''), 0, 190),
            'adat'    => mb_substr(trim($_POST['prosesi_adat'] ?? ''), 0, 40),
            'adat_l'  => mb_substr(trim($_POST['prosesi_adat_lainnya'] ?? ''), 0, 120),
            'venue_t' => (array) ($_POST['venue_tipe'] ?? []),
            'jenis_a' => trim($_POST['jenis_acara'] ?? ''),
            'sitting' => trim($_POST['sitting_mode'] ?? ''),
            'tipe'    => trim($_POST['tipe_klien'] ?? ''),
            'needs'   => array_map('intval', (array) ($_POST['vendor_need'] ?? [])),
            'top5'    => (array) ($_POST['top_vendor'] ?? []),
        ];

        // Hari-H diturunkan dari resepsi, lalu akad. Sama persis dengan
        // aturan di panel — kalau berbeda, klien yang masuk lewat formulir
        // akan punya tanggal yang tidak cocok dengan yang dicatat manual.
        if ($isi['tanggal'] === '') {
            $isi['tanggal'] = $isi['res_t'] ?: $isi['akad_t'];
        }
        if ($isi['venue'] === '') {
            $isi['venue'] = $isi['res_l'] ?: $isi['akad_l'];
        }
        // Jamnya juga. Sebelumnya tidak pernah diturunkan sama sekali, jadi
        // klien yang mengisi jam resepsi tetap muncul tanpa jam di panel —
        // dan kartu Hari-H terdekat serta undangan kalender ikut kosong.
        $isi['jam'] = $isi['res_j'] ?: $isi['akad_j'];

        // guest_estimate tetap jadi angka utama untuk penawaran, daftar klien,
        // dan analisa. Diturunkan dari tamu resepsi lalu tamu akad — aturan
        // yang sama dengan tanggal dan venue, supaya klien dari formulir tidak
        // punya angka yang berbeda dengan yang dicatat manual di panel.
        if ($isi['tamu'] === 0) {
            $isi['tamu'] = $isi['tamu_res'] ?: $isi['tamu_akad'];
        }

        if ($isi['pria'] === '' && $isi['wanita'] === '') $galat[] = 'Nama mempelai belum diisi.';
        if (strlen($isi['wa']) < 9)                       $galat[] = 'Nomor WhatsApp belum benar.';
        if ($isi['email'] !== '' && !filter_var($isi['email'], FILTER_VALIDATE_EMAIL)) {
            $galat[] = 'Alamat email tidak valid.';
        }
        if ($isi['tanggal'] !== '' && !strtotime($isi['tanggal'])) $galat[] = 'Tanggal tidak terbaca.';

        if (!$galat) {
            try {
                $wa = $isi['wa'];
                if (str_starts_with($wa, '0'))  $wa = '62' . substr($wa, 1);
                if (str_starts_with($wa, '8'))  $wa = '62' . $wa;

                // Nomor yang sama tidak dibuat dua kali. Calon klien sering
                // mengirim ulang karena ragu kirimannya masuk — dan dua baris
                // klien untuk satu pasangan lebih merepotkan daripada satu
                // kiriman yang hilang.
                $ada = one("SELECT id FROM clients WHERE phone = ? LIMIT 1", [$wa]);

                $sumberSah = in_array($isi['sumber'],
                    ['instagram','whatsapp','web','referral','vendor','walkin','lainnya'], true)
                    ? $isi['sumber'] : 'web';

                $catatan = $isi['catatan'];
                if ($isi['brief'] !== '') {
                    $catatan = trim($catatan . "\n\n--- Susunan hari dari penyusun ---\n" . $isi['brief']);
                }

                if ($ada) {
                    q("UPDATE clients SET
                         name = COALESCE(NULLIF(?,''), name),
                         partner_name = COALESCE(NULLIF(?,''), partner_name),
                         email = COALESCE(NULLIF(?,''), email),
                         wedding_date = COALESCE(?, wedding_date),
                         -- Sama aturannya dengan wedding_date di atas: nilai baru
                         -- menang kalau dikirim, nilai lama bertahan kalau kosong.
                         -- COALESCE(wedding_time, ?) akan SALAH di sini — itu
                         -- hanya mengisi saat masih kosong, jadi resepsi yang
                         -- dipindah jamnya tidak pernah ikut terbarui.
                         wedding_time = COALESCE(?, wedding_time),
                         guest_estimate = IF(? > 0, ?, guest_estimate),
                         city = COALESCE(NULLIF(?,''), city),
                         venue = COALESCE(NULLIF(?,''), venue),
                         budget_estimate = IF(? > 0, ?, budget_estimate),
                         notes = CONCAT(COALESCE(notes,''), '\n\n[kiriman ulang formulir] ', ?),
                         form_brief = ?, updated_at = NOW()
                       WHERE id = ?",
                      [$isi['pria'], $isi['wanita'], $isi['email'],
                       $isi['tanggal'] ?: null, $isi['jam'] ?: null,
                       $isi['tamu'], $isi['tamu'],
                       $isi['kota'], $isi['venue'],
                       $isi['budget'], $isi['budget'],
                       $catatan, $isi['brief'], $ada['id']]);
                    $id = (int) $ada['id'];
                } else {
                    q("INSERT INTO clients
                        (name, partner_name, phone, email, wedding_date, wedding_time,
                         guest_estimate, city, venue, budget_estimate, source, notes,
                         stage, dari_form, form_ip, form_brief, created_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'baru', 1, ?, ?, NOW())",
                      [$isi['pria'] ?: $isi['wanita'], $isi['wanita'], $wa, $isi['email'],
                       $isi['tanggal'] ?: null, $isi['jam'] ?: null,
                       $isi['tamu'] ?: null,
                       $isi['kota'], $isi['venue'], $isi['budget'] ?: null,
                       $sumberSah, $catatan, $ip, $isi['brief']]);
                    $id = insertId();
                }

                // ---- Base information ----
                $venueSet = array_values(array_intersect(
                    array_keys($isi['venue_t']), ['indoor', 'outdoor']));
                $jenisA = in_array($isi['jenis_a'], ['standing','sitting'], true) ? $isi['jenis_a'] : '';
                $sitMode = $jenisA === 'sitting'
                    && in_array($isi['sitting'], ['per_seat','per_block'], true) ? $isi['sitting'] : '';

                q("INSERT INTO client_wedding_info
                    (client_id, akad_tanggal, akad_jam, akad_lokasi,
                     resepsi_tanggal, resepsi_jam, resepsi_lokasi,
                     prosesi_adat, prosesi_adat_lainnya, venue_tipe, jenis_acara, sitting_mode,
                     tamu_akad, tamu_resepsi)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE
                     -- Kolom yang dikirim KOSONG tidak menimpa nilai lama.
                     --
                     -- Sebelumnya semuanya VALUES(...) polos, jadi kiriman ulang
                     -- yang seadanya menghapus akad, resepsi, adat, dan jenis
                     -- acara yang sudah tersimpan. Dan mengirim ulang itu
                     -- perilaku paling wajar: calon klien ragu kirimannya masuk,
                     -- lalu mengisi cepat-cepat seperlunya. Panel lalu
                     -- memperlihatkan data yang lebih miskin daripada yang
                     -- sebenarnya pernah dikirim — tanpa jejak apa pun.
                     --
                     -- Tanggal dan jam bertipe NULL kalau kosong, teks bertipe
                     -- string kosong, jadi penjagaannya beda: COALESCE untuk
                     -- yang pertama, NULLIF untuk yang kedua.
                     akad_tanggal    = COALESCE(VALUES(akad_tanggal), akad_tanggal),
                     akad_jam        = COALESCE(VALUES(akad_jam), akad_jam),
                     akad_lokasi     = COALESCE(NULLIF(VALUES(akad_lokasi),''), akad_lokasi),
                     resepsi_tanggal = COALESCE(VALUES(resepsi_tanggal), resepsi_tanggal),
                     resepsi_jam     = COALESCE(VALUES(resepsi_jam), resepsi_jam),
                     resepsi_lokasi  = COALESCE(NULLIF(VALUES(resepsi_lokasi),''), resepsi_lokasi),
                     prosesi_adat    = COALESCE(NULLIF(VALUES(prosesi_adat),''), prosesi_adat),
                     prosesi_adat_lainnya = COALESCE(NULLIF(VALUES(prosesi_adat_lainnya),''), prosesi_adat_lainnya),
                     venue_tipe      = COALESCE(NULLIF(VALUES(venue_tipe),''), venue_tipe),
                     jenis_acara     = COALESCE(NULLIF(VALUES(jenis_acara),''), jenis_acara),
                     sitting_mode    = COALESCE(NULLIF(VALUES(sitting_mode),''), sitting_mode),
                     tamu_akad       = COALESCE(VALUES(tamu_akad), tamu_akad),
                     tamu_resepsi    = COALESCE(VALUES(tamu_resepsi), tamu_resepsi)",
                  [$id,
                   $isi['akad_t'] ?: null, $isi['akad_j'] ?: null, $isi['akad_l'],
                   $isi['res_t']  ?: null, $isi['res_j']  ?: null, $isi['res_l'],
                   $isi['adat'], $isi['adat_l'],
                   $venueSet ? implode(',', $venueSet) : null, $jenisA, $sitMode,
                   $isi['tamu_akad'] ?: null, $isi['tamu_res'] ?: null]);

                if (in_array($isi['tipe'], ['tematis','budgeting'], true)) {
                    q("UPDATE clients SET tipe_klien = ? WHERE id = ?", [$isi['tipe'], $id]);
                }

                // ---- Kebutuhan vendor ----
                if ($isi['needs']) {
                    q("DELETE FROM client_vendor_needs WHERE client_id = ?", [$id]);
                    $urut = 0;
                    foreach ($isi['needs'] as $catId) {
                        if ($catId <= 0) continue;
                        q("INSERT IGNORE INTO client_vendor_needs (client_id, category_id, sort_order)
                           VALUES (?,?,?)", [$id, $catId, $urut++ * 10]);
                    }
                }

                // ---- Top 5 prioritas ----
                if ($isi['top5']) {
                    q("DELETE FROM client_top_vendors WHERE client_id = ?", [$id]);
                    $sudah = [];
                    foreach ($isi['top5'] as $urutan => $catId) {
                        $urutan = (int) $urutan; $catId = (int) $catId;
                        if ($urutan < 1 || $urutan > 5 || $catId <= 0) continue;
                        if (in_array($catId, $sudah, true)) continue;
                        $sudah[] = $catId;
                        q("INSERT INTO client_top_vendors (client_id, category_id, urutan, nama)
                           VALUES (?,?,?,'')", [$id, $catId, $urutan]);
                    }
                }

                // Tindak lanjut langsung terjadwal. Prospek yang masuk sendiri
                // lewat formulir adalah yang paling panas — menunggunya sampai
                // ada yang sempat membuka panel adalah cara tercepat kehilangannya.
                q("UPDATE clients SET next_action = ?, next_action_at = CURDATE()
                   WHERE id = ? AND (next_action IS NULL OR next_action = '')",
                  ['Balas kiriman formulir dan kirim price list', $id]);

                if (function_exists('clientLog')) {
                    clientLog($id, 'catatan', 'Masuk lewat formulir publik',
                              'Diisi sendiri dari ' . e($_SERVER['HTTP_HOST'] ?? 'form'), null);
                }
                try { require_once __DIR__ . '/inc/chat.php'; chatSinkronKlien($id); }
                catch (Throwable $e) { /* room chat menyusul */ }

                catatKirim($ip);
                $_SESSION['form_nonce'] = bin2hex(random_bytes(16));
                $sukses = true;

            } catch (Throwable $e) {
                error_log('form.php: ' . $e->getMessage());
                $galat[] = 'Terjadi gangguan saat menyimpan. Coba lagi, atau hubungi kami lewat WhatsApp.';
            }
        }
    }
}

$waNo = preg_replace('/\D/', '', (string) setting('wa_number'));
if (str_starts_with($waNo, '0')) $waNo = '62' . substr($waNo, 1);

seoHead([
    'title'       => 'Isi Data Pernikahan — Callalily Party',
    'description' => 'Isi tanggal, perkiraan tamu, dan rangkaian acara. Kami balas dengan rincian biaya '
                   . 'dan ketersediaan tanggal dalam 2×24 jam.',
    'canonical'   => setting('form_url', url('form')),
    // Halaman formulir tidak perlu bersaing di pencarian — yang harus
    // terindeks adalah beranda dan halaman kategori, bukan borang isian.
    'robots'      => 'noindex, follow',
]);
require __DIR__ . '/partials/public-head.php';
?>

<div class="fm">
<?php if ($sukses): ?>

  <div class="fm-ok">
    <span class="fm-ok-i">✓</span>
    <h1><?= te('Terkirim. Terima kasih.') ?></h1>
    <p><?= te('Kami baca dulu susunannya, lalu balas dengan rincian biaya dan ketersediaan tanggal — biasanya dalam 2×24 jam.') ?></p>
    <p class="fm-ok-n"><?= te('Kalau butuh lebih cepat, boleh langsung sapa kami di WhatsApp.') ?></p>
    <?php if ($waNo): ?>
      <a class="btn solid" href="https://wa.me/<?= e($waNo) ?>" target="_blank" rel="noopener">
        <?= te('Buka WhatsApp') ?></a>
    <?php endif; ?>
    <a class="btn" href="<?= e(url()) ?>"><?= te('Kembali ke situs') ?></a>
  </div>

<?php else: ?>

  <header class="fm-head">
    <a class="fm-brand" href="<?= e(url()) ?>">Callalily<sup>PARTY</sup></a>
    <h1><?= te('Ceritakan harimu') ?></h1>
    <p><?= te('Tanggal dan perkiraan jumlah tamu sudah cukup untuk kami mulai. Sisanya boleh menyusul.') ?></p>
  </header>

  <?php if ($galat): ?>
    <div class="fm-galat">
      <?php foreach ($galat as $g): ?><p><?= e($g) ?></p><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" class="fm-form" novalidate>
    <input type="hidden" name="_n" value="<?= e($_SESSION['form_nonce']) ?>">
    <input type="hidden" name="brief" value="<?= e($_POST['brief'] ?? $_GET['brief'] ?? '') ?>">
    <?php if (!empty($_GET['dari'])): ?>
      <input type="hidden" name="dari" value="<?= e($_GET['dari']) ?>">
    <?php endif; ?>

    <!-- Honeypot. Disembunyikan lewat CSS, bukan type=hidden — sebagian bot
         melewati input hidden tapi tetap mengisi input teks biasa. -->
    <div class="fm-hp" aria-hidden="true">
      <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    </div>

    <fieldset>
      <legend><?= te('Siapa') ?></legend>
      <div class="fm-r2">
        <label><?= te('Mempelai pria') ?>
          <input type="text" name="pria" value="<?= e($isi['pria'] ?? '') ?>" autocomplete="off"></label>
        <label><?= te('Mempelai wanita') ?>
          <input type="text" name="wanita" value="<?= e($isi['wanita'] ?? '') ?>" autocomplete="off"></label>
      </div>
      <div class="fm-r2">
        <label><?= te('WhatsApp') ?> <span class="fm-w">*</span>
          <input type="tel" name="wa" required inputmode="numeric"
                 value="<?= e($isi['wa'] ?? '') ?>" placeholder="0812…"></label>
        <label><?= te('Email') ?>
          <input type="email" name="email" value="<?= e($isi['email'] ?? '') ?>"></label>
      </div>
    </fieldset>

    <fieldset>
      <legend><?= te('Acaranya') ?></legend>
      <div class="fm-r2">
        <label><?= te('Tanggal') ?>
          <input type="date" name="tanggal" value="<?= e($isi['tanggal'] ?? '') ?>"></label>
        <label><?= te('Perkiraan tamu resepsi') ?>
          <input type="number" name="tamu_resepsi" step="50" min="0"
                 value="<?= e((string) ($isi['tamu_res'] ?? ($isi['tamu'] ?? ''))) ?>" placeholder="500"></label>
      </div>
      <div class="fm-r2">
        <label><?= te('Perkiraan tamu akad / pemberkatan') ?>
          <input type="number" name="tamu_akad" step="25" min="0"
                 value="<?= e((string) ($isi['tamu_akad'] ?? '')) ?>" placeholder="100">
          <span class="fm-ket" style="margin-top:5px;display:block"><?= te('Biasanya jauh lebih sedikit daripada resepsi. Boleh dikosongi kalau acaranya menyatu.') ?></span></label>
        <div></div>
      </div>
      <div class="fm-r2">
        <label><?= te('Kota') ?>
          <input type="text" name="kota" value="<?= e($isi['kota'] ?? 'Yogyakarta') ?>"></label>
        <label><?= te('Venue, kalau sudah ada') ?>
          <input type="text" name="venue" value="<?= e($isi['venue'] ?? '') ?>"></label>
      </div>
      <div class="fm-r2">
        <label><?= te('Perkiraan budget') ?>
          <input type="text" name="budget" inputmode="numeric"
                 value="<?= e((string) ($isi['budget'] ?? '')) ?>" placeholder="Rp"></label>
        <label><?= te('Tahu Callalily dari mana') ?>
          <select name="sumber">
            <?php foreach (['instagram'=>'Instagram','referral'=>'Rekomendasi teman',
                            'vendor'=>'Vendor lain','web'=>'Google / situs',
                            'walkin'=>'Datang langsung','lainnya'=>'Lainnya'] as $k => $v): ?>
              <option value="<?= $k ?>" <?= ($isi['sumber'] ?? '') === $k ? 'selected' : '' ?>>
                <?= te($v) ?></option>
            <?php endforeach; ?>
          </select></label>
      </div>
      <label><?= te('Yang ingin kalian ceritakan') ?>
        <textarea name="catatan" rows="4"
          placeholder="<?= te('Konsep yang dibayangkan, prosesi adat, kendala, atau apa pun yang perlu kami tahu.') ?>"><?= e($isi['catatan'] ?? '') ?></textarea></label>
    </fieldset>

    <?php
    $katForm = [];
    try {
        $katForm = all("SELECT id, nama FROM vendor_categories
                        WHERE parent_id IS NULL AND is_active = 1 ORDER BY urutan, nama");
    } catch (Throwable $e) { $katForm = []; }
    /**
     * Centang awal berasal dari tiga sumber, berurutan kekuatannya:
     *   1. kiriman POST sebelumnya  — kalau formulir gagal validasi dan
     *      ditampilkan ulang, pilihan orang tidak boleh hilang
     *   2. ?vendor= dari penyusun   — apa yang sudah dipilih di beranda
     *   3. kosong
     *
     * Nomor 2 hanya keadaan AWAL, bukan kunci. Semua centang tetap bisa
     * ditambah atau dilepas di sini — yang dibawa dari beranda sering baru
     * gambaran kasar, dan memaksanya jadi keputusan akhir akan membuat orang
     * mengisi ulang dari nol.
     */
    $needTerpilih = array_map('intval', (array) ($_POST['vendor_need'] ?? []));
    if (!$needTerpilih && !empty($_GET['vendor'])) {
        $needTerpilih = array_values(array_filter(
            array_map('intval', explode(',', (string) $_GET['vendor'])),
            fn($v) => $v > 0
        ));
    }

    // Wedding Organizer selalu tercentang, dan hanya dia.
    //
    // Sebelumnya centang bawaan mengikuti apa pun yang dipilih di penyusun
    // beranda — sering belasan kategori sekaligus. Itu mengubah arti
    // kolomnya: yang mestinya "apa yang kalian butuhkan" jadi "apa yang
    // tadi kamu klik-klik", dan admin menerima daftar kebutuhan yang tidak
    // pernah benar-benar diputuskan siapa pun.
    //
    // WO beda: klien yang mengisi formulir wedding organizer memang sudah
    // pasti membutuhkan wedding organizer. Sisanya biar dia yang memilih.
    $idWO = (int) (one("SELECT id FROM vendor_categories WHERE slug = 'wedding-organizer' LIMIT 1")['id'] ?? 0);
    if ($idWO && !in_array($idWO, $needTerpilih, true)) $needTerpilih[] = $idWO;
    ?>

    <fieldset>
      <legend><?= te('Susunan acara') ?></legend>
      <p class="fm-ket"><?= te('Boleh dilewati. Kalau sudah ada gambarannya, ini yang membuat penawaran kami jauh lebih tepat sejak awal.') ?></p>

      <div class="fm-r2">
        <label><?= te('Akad / pemberkatan — tanggal') ?>
          <input type="date" name="akad_tanggal" value="<?= e($isi['akad_t'] ?? '') ?>"></label>
        <label><?= te('Jam') ?>
          <input type="time" name="akad_jam" value="<?= e($isi['akad_j'] ?? '') ?>"></label>
      </div>
      <label><?= te('Lokasi akad') ?>
        <input type="text" name="akad_lokasi" value="<?= e($isi['akad_l'] ?? '') ?>"
               placeholder="<?= te('Masjid, gereja, atau rumah') ?>"></label>

      <div class="fm-r2">
        <label><?= te('Resepsi — tanggal') ?>
          <input type="date" name="resepsi_tanggal" value="<?= e($isi['res_t'] ?? '') ?>"></label>
        <label><?= te('Jam') ?>
          <input type="time" name="resepsi_jam" value="<?= e($isi['res_j'] ?? '') ?>"></label>
      </div>
      <label><?= te('Lokasi resepsi') ?>
        <input type="text" name="resepsi_lokasi" value="<?= e($isi['res_l'] ?? '') ?>"></label>

      <div class="fm-r2">
        <label><?= te('Prosesi adat') ?>
          <select name="prosesi_adat">
            <option value=""><?= te('— tidak ada / belum ditentukan —') ?></option>
            <?php foreach (['jawa'=>'Jawa','chinese'=>'Chinese','batak'=>'Batak','lainnya'=>'Suku lainnya'] as $k=>$v): ?>
              <option value="<?= $k ?>" <?= ($isi['adat'] ?? '') === $k ? 'selected' : '' ?>><?= te($v) ?></option>
            <?php endforeach; ?>
          </select></label>
        <label><?= te('Kalau suku lain, sebutkan') ?>
          <input type="text" name="prosesi_adat_lainnya" value="<?= e($isi['adat_l'] ?? '') ?>"></label>
      </div>

      <div class="fm-r2">
        <label><?= te('Venue') ?>
          <span class="fm-cek">
            <label><input type="checkbox" name="venue_tipe[indoor]" value="1"
              <?= !empty($isi['venue_t']['indoor']) ? 'checked' : '' ?>> <?= te('Indoor') ?></label>
            <label><input type="checkbox" name="venue_tipe[outdoor]" value="1"
              <?= !empty($isi['venue_t']['outdoor']) ? 'checked' : '' ?>> <?= te('Outdoor') ?></label>
          </span></label>
        <label><?= te('Jenis acara') ?>
          <select name="jenis_acara" id="fJenis">
            <option value=""><?= te('— belum ditentukan —') ?></option>
            <option value="standing" <?= ($isi['jenis_a'] ?? '') === 'standing' ? 'selected' : '' ?>><?= te('Standing party') ?></option>
            <option value="sitting"  <?= ($isi['jenis_a'] ?? '') === 'sitting'  ? 'selected' : '' ?>><?= te('Sitting arrangement') ?></option>
          </select></label>
      </div>

      <div id="fSit" <?= ($isi['jenis_a'] ?? '') === 'sitting' ? '' : 'hidden' ?>>
        <span class="fm-cek">
          <label><input type="radio" name="sitting_mode" value="per_seat"
            <?= ($isi['sitting'] ?? '') === 'per_seat' ? 'checked' : '' ?>> <?= te('Per seat — ada nama tiap kursi') ?></label>
          <label><input type="radio" name="sitting_mode" value="per_block"
            <?= ($isi['sitting'] ?? '') === 'per_block' ? 'checked' : '' ?>> <?= te('Per block — piring terbang') ?></label>
        </span>
      </div>
    </fieldset>

    <fieldset>
      <legend><?= te('Cara menyusun anggaran') ?></legend>
      <label class="fm-radio">
        <input type="radio" name="tipe_klien" value="tematis"
          <?= ($isi['tipe'] ?? 'tematis') !== 'budgeting' ? 'checked' : '' ?>>
        <span><b><?= te('Ikuti konsep') ?></b><br>
          <?= te('Kami susun sesuai yang kalian bayangkan. Budget jadi perkiraan, bukan batas.') ?></span>
      </label>
      <label class="fm-radio">
        <input type="radio" name="tipe_klien" value="budgeting"
          <?= ($isi['tipe'] ?? '') === 'budgeting' ? 'checked' : '' ?>>
        <span><b><?= te('Ikuti anggaran') ?></b><br>
          <?= te('Angka di atas jadi batas atas. Kami isi sampai plafon, lalu berhenti.') ?></span>
      </label>
    </fieldset>

    <?php if ($katForm): ?>
    <fieldset>
      <legend><?= te('Vendor yang dibutuhkan') ?></legend>
      <p class="fm-ket"><?= te('Wedding Organizer sudah tercentang. Sisanya centang sendiri sesuai yang kalian butuhkan — boleh dikoreksi lagi saat konsultasi.') ?></p>
      <div class="fm-grid">
        <?php foreach ($katForm as $k): ?>
          <label class="fm-c">
            <input type="checkbox" class="vn-cekf" name="vendor_need[]" value="<?= (int) $k['id'] ?>"
              <?= in_array((int) $k['id'], $needTerpilih, true) ? 'checked' : '' ?>>
            <?= e($k['nama']) ?>
          </label>
        <?php endforeach; ?>
      </div>

      <span class="fm-sub"><?= te('Top 5 prioritas') ?></span>
      <p class="fm-ket"><?= te('Dari yang dicentang, lima mana yang paling ingin kalian maksimalkan. Sisanya kami sesuaikan dengan anggaran yang tersisa.') ?></p>
      <?= blokTop5($katForm, array_map('intval', (array) ($_POST['top_vendor'] ?? [])), true) ?>
    </fieldset>
    <?php endif; ?>

    <button class="btn solid fm-kirim" type="submit"><?= te('Kirim') ?></button>
    <p class="fm-nb"><?= te('Data ini hanya dipakai untuk menyiapkan penawaran kalian. Tidak dibagikan ke pihak lain.') ?></p>
  </form>

<?php endif; ?>
</div>

<script>
// Pilihan sitting hanya relevan kalau jenis acaranya sitting. Menampilkannya
// terus-menerus membuat orang mengira wajib diisi.
(() => {
  const j = document.getElementById('fJenis'), b = document.getElementById('fSit');
  if (!j || !b) return;
  const sync = () => { b.hidden = j.value !== 'sitting'; };
  j.addEventListener('change', sync); sync();
})();
</script>
<?= blokTop5Skrip() ?>
<?php require __DIR__ . '/partials/public-foot.php';
