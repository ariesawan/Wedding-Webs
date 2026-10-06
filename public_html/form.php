<?php
/**
 * FORMULIR KLIEN PUBLIK — form.callalily.party  (juga /form)
 *
 * Isinya sengaja hanya BIODATA AWAL + paket yang diminati: cukup bagi admin
 * early untuk mengirim price list. Biodata lengkap, keluarga, susunan acara,
 * dekor, dan venue diisi bersama admin office setelah DP — menanyakan
 * semuanya di depan hanya membuat orang berhenti di tengah formulir.
 *
 * Penjagaan dari kiriman otomatis:
 *   1. Honeypot    — kolom tak terlihat bernama netral (bukan "website":
 *                    pengisi otomatis peramban kadang mengisinya)
 *   2. Token HMAC  — membuktikan halaman dimuat dari situs ini, tanpa sesi
 *   3. Jeda 3 detik
 *   4. Throttle    — 10 kiriman tersimpan per IP per jam
 *
 * Dan yang paling penting: SEMUA kiriman — termasuk yang ditolak salah satu
 * penjagaan di atas — tercatat di Formulir masuk lengkap dengan isinya.
 * Lihat inc/formulir.php.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/formulir.php';

$waNo = waNomorPublik();

if (setting('form_aktif', '1') !== '1') {
    http_response_code(503);
    exit('Formulir sedang ditutup sementara. Hubungi kami lewat WhatsApp: https://wa.me/' . $waNo);
}

$galat  = [];
$sukses = false;
$isi    = formIsi($_GET);          // ?paket=semanak&brief=… dari price list / penyusun
$isi['wa'] = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';   // header proxy tidak dipercaya
    $isi = formIsi($_POST);
    $tok = formTokenCek((string) ($_POST['_t'] ?? ''));

    if (trim((string) ($_POST['kd_7'] ?? '')) !== '') {
        // Bot dibiarkan mengira berhasil. Isinya tetap tercatat (status bot)
        // supaya kalau ternyata manusia, datanya tidak hilang.
        formLog('bot', $isi, $ip, 'Kolom jebakan terisi');
        $sukses = true;
    } elseif ($tok === 'palsu') {
        formLog('ditolak', $isi, $ip, 'Token halaman tidak sah');
        $galat[] = 'Halaman perlu dimuat ulang. Isian kalian masih ada — tekan Kirim sekali lagi.';
    } elseif ($tok === 'cepat') {
        formLog('ditolak', $isi, $ip, 'Dikirim kurang dari 3 detik');
        $galat[] = 'Terlalu cepat. Tekan Kirim sekali lagi.';
    } elseif (!formBolehKirim($ip)) {
        formLog('ditolak', $isi, $ip, 'Batas 10 kiriman per jam');
        $galat[] = 'Sudah beberapa kali mengirim dari perangkat ini. Data kalian tetap kami catat — '
                 . 'atau hubungi kami langsung lewat WhatsApp.';
    } elseif ($v = formValidasi($isi)) {
        formLog('ditolak', $isi, $ip, 'Validasi: ' . implode(' ', $v));
        $galat = $v;
    } else {
        // Dicatat DULU sebagai galat, baru dinaikkan jadi tersimpan setelah
        // klien terbentuk. Kalau proses mati di tengah jalan, jejaknya tetap
        // ada dan muncul di dashboard sebagai "perlu dicek".
        $logId = formLog('galat', $isi, $ip, 'Sedang diproses');
        try {
            $r = formSimpan($isi, $ip);
            formLogSet($logId, $r['ulang'] ? 'ulang' : 'tersimpan', $r['id'], $r['info']);
            formCatatKirim($ip);
            formKabariAdmin($r['id'], $isi, $r['ulang']);
            $sukses = true;
        } catch (Throwable $e) {
            error_log('form.php: ' . $e->getMessage());
            formLogSet($logId, 'galat', null, mb_substr($e->getMessage(), 0, 380));
            $galat[] = 'Ada gangguan saat menyimpan, tapi isian kalian sudah kami catat dan admin akan '
                     . 'menghubungi. Kalau ingin lebih cepat, sapa kami di WhatsApp.';
        }
    }
}

$paketWeb = paketDaftar(true);
$pilih    = formPaket($isi['paket']);
$pilihId  = $pilih ? (int) $pilih['id'] : 0;

$seo = [
    'title'     => 'Isi Data Pernikahan — ' . setting('site_name', 'Callalily Party'),
    'desc'      => 'Kirim nama, tanggal, perkiraan tamu, dan paket yang diminati. '
                 . 'Kami balas dengan price list lengkap (PDF) lewat WhatsApp.',
    'canonical' => setting('form_url', url('form')),
    // Borang isian tidak perlu bersaing di pencarian.
    'robots'    => 'noindex, follow',
];
$extraCss = <<<'CSS'
<style>
.fm-pk{display:grid;gap:10px;margin-bottom:6px}
.fm-pk .fm-radio{display:flex;margin:0;align-items:flex-start}
.fm-pk .fm-radio span{flex:1;display:block}
.fm-pk .pk-h{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:baseline}
.fm-pk .pk-h b{font-family:var(--serif);font-style:italic;font-weight:400;font-size:20px}
.fm-pk .pk-r{font-family:var(--mono);font-size:11px;letter-spacing:.08em;color:var(--ember)}
.fm-pk .pk-d{display:block;font-size:13px;color:var(--ivory-60);margin-top:3px}
.fm-pk .pk-t{font-family:var(--mono);font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:var(--ivory-38)}
.fm-lihat{display:inline-block;font-size:13px;color:var(--ember);margin:4px 0 6px}
.fm-brief{font-size:12.5px;color:var(--ivory-38);border-left:2px solid var(--ivory-12);padding:6px 0 6px 12px;
  white-space:pre-wrap;margin:-4px 0 16px;max-height:9.5em;overflow:auto}
</style>
CSS;
require __DIR__ . '/partials/public-head.php';
?>

<div class="fm">
<?php if ($sukses): ?>

  <div class="fm-ok">
    <span class="fm-ok-i">✓</span>
    <h1><?= te('Terkirim. Terima kasih.') ?></h1>
    <p><?= te('Admin kami akan menyapa lewat WhatsApp dan mengirim price list lengkap dalam bentuk PDF — biasanya di hari yang sama.') ?></p>
    <p class="fm-ok-n"><?= te('Kalau ingin lebih cepat, boleh langsung sapa kami.') ?></p>
    <a class="btn solid" href="https://wa.me/<?= e($waNo) ?>?text=<?= rawurlencode('Halo Callalily, saya baru saja mengisi formulir atas nama ' . trim($isi['pria'] . ' & ' . $isi['wanita'], ' &') . '.') ?>"
       target="_blank" rel="noopener"><?= te('Buka WhatsApp') ?></a>
    <a class="btn" href="<?= e(url()) ?>"><?= te('Kembali ke situs') ?></a>
  </div>

<?php else: ?>

  <header class="fm-head">
    <h1><?= te('Ceritakan rencana kalian') ?></h1>
    <p><?= te('Nama, nomor WhatsApp, dan perkiraan tanggal sudah cukup untuk kami kirimi price list. Sisanya boleh menyusul.') ?></p>
  </header>

  <?php if ($galat): ?>
    <div class="fm-galat" role="alert">
      <?php foreach ($galat as $g): ?><p><?= e($g) ?></p><?php endforeach; ?>
      <p><a href="https://wa.me/<?= e($waNo) ?>" target="_blank" rel="noopener" style="color:inherit"><?= te('Atau hubungi kami di WhatsApp') ?> →</a></p>
    </div>
  <?php endif; ?>

  <form method="post" class="fm-form" novalidate>
    <input type="hidden" name="_t" value="<?= e(formToken()) ?>">
    <input type="hidden" name="brief" value="<?= e($isi['brief']) ?>">

    <!-- Kolom jebakan: disembunyikan lewat CSS, namanya sengaja netral. -->
    <div class="fm-hp" aria-hidden="true">
      <label>Kode<input type="text" name="kd_7" tabindex="-1" autocomplete="new-password"></label>
    </div>

    <fieldset>
      <legend><?= te('Mempelai') ?></legend>
      <div class="fm-r2">
        <label><?= te('Nama mempelai pria') ?>
          <input type="text" name="pria" value="<?= e($isi['pria']) ?>" autocomplete="off"></label>
        <label><?= te('Nama mempelai wanita') ?>
          <input type="text" name="wanita" value="<?= e($isi['wanita']) ?>" autocomplete="off"></label>
      </div>
      <div class="fm-r2">
        <label><?= te('WhatsApp') ?> <span class="fm-w">*</span>
          <input type="tel" name="wa" required inputmode="tel" autocomplete="tel"
                 value="<?= e($isi['wa']) ?>" placeholder="0812…"></label>
        <label><?= te('Email') ?>
          <input type="email" name="email" autocomplete="email" value="<?= e($isi['email']) ?>"></label>
      </div>
      <label><?= te('Instagram') ?>
        <input type="text" name="ig" value="<?= e($isi['ig']) ?>" placeholder="@…" autocomplete="off"></label>
    </fieldset>

    <fieldset>
      <legend><?= te('Rencana acara') ?></legend>
      <div class="fm-r2">
        <label><?= te('Tanggal rencana') ?>
          <input type="date" name="tanggal" value="<?= e($isi['tanggal']) ?>"></label>
        <label><?= te('Perkiraan tamu') ?>
          <input type="number" name="tamu" step="50" min="0" inputmode="numeric"
                 value="<?= $isi['tamu'] ? (int) $isi['tamu'] : '' ?>" placeholder="400"></label>
      </div>
      <div class="fm-r2">
        <label><?= te('Kota') ?>
          <input type="text" name="kota" value="<?= e($isi['kota'] !== '' ? $isi['kota'] : 'Yogyakarta') ?>"></label>
        <label><?= te('Venue, kalau sudah ada') ?>
          <input type="text" name="venue" value="<?= e($isi['venue']) ?>"></label>
      </div>
      <label><?= te('Perkiraan budget') ?>
        <input type="text" name="budget" inputmode="numeric"
               value="<?= $isi['budget'] ? e(number_format($isi['budget'], 0, ',', '.')) : '' ?>" placeholder="Rp"></label>
    </fieldset>

    <fieldset>
      <legend><?= te('Paket yang diminati') ?></legend>
      <div class="fm-pk">
        <?php foreach ($paketWeb as $t):
          $slug = $t['slug'] ?: (string) $t['id'];
          $hl = paketHargaLabel($t, true); ?>
          <label class="fm-radio">
            <input type="radio" name="paket" value="<?= e($slug) ?>" <?= $pilihId === (int) $t['id'] ? 'checked' : '' ?>>
            <span>
              <span class="pk-h"><b><?= e($t['nama']) ?></b>
                <span class="pk-r"><?= $hl ? e($hl) : te('harga via WhatsApp') ?></span></span>
              <?php if ($t['ringkas']): ?><span class="pk-d"><?= e($t['ringkas']) ?></span><?php endif; ?>
              <?php if (!empty($t['tamu'])): ?><span class="pk-t">±<?= number_format((int) $t['tamu'], 0, ',', '.') ?> <?= te('tamu') ?></span><?php endif; ?>
            </span>
          </label>
        <?php endforeach; ?>
        <label class="fm-radio">
          <input type="radio" name="paket" value="" <?= $pilihId === 0 ? 'checked' : '' ?>>
          <span><span class="pk-h"><b><?= te('Belum tahu') ?></b></span>
            <span class="pk-d"><?= te('Bantu kami memilih — admin akan menyarankan yang paling pas.') ?></span></span>
        </label>
      </div>
      <?php if ($paketWeb): ?>
        <a class="fm-lihat" href="<?= e(url('pricelist')) ?>" target="_blank" rel="noopener"><?= te('Lihat isi lengkap tiap paket') ?> ↗</a>
      <?php endif; ?>
    </fieldset>

    <fieldset>
      <legend><?= te('Cerita singkat') ?></legend>
      <?php if ($isi['brief'] !== ''): ?>
        <span class="fm-sub" style="margin-top:0"><?= te('Susunan hari dari situs ikut terkirim') ?></span>
        <div class="fm-brief"><?= e($isi['brief']) ?></div>
      <?php endif; ?>
      <label><?= te('Yang ingin kalian ceritakan') ?>
        <textarea name="catatan" rows="4"
          placeholder="<?= te('Konsep yang dibayangkan, prosesi adat, atau pertanyaan apa pun.') ?>"><?= e($isi['catatan']) ?></textarea></label>
      <label><?= te('Tahu Callalily dari mana') ?>
        <select name="sumber">
          <?php foreach (FORM_SUMBER as $k => $v): ?>
            <option value="<?= $k ?>" <?= $isi['sumber'] === $k ? 'selected' : '' ?>><?= te($v) ?></option>
          <?php endforeach; ?>
        </select></label>
    </fieldset>

    <button class="btn solid fm-kirim" type="submit"><?= te('Kirim') ?></button>
    <p class="fm-nb"><?= te('Data ini hanya dipakai untuk menyiapkan price list dan penawaran kalian. Tidak dibagikan ke pihak lain.') ?></p>
  </form>

<?php endif; ?>
</div>

<script>
// Tombol dikunci setelah ditekan: kiriman ganda dari jari yang menekan dua
// kali adalah sumber klien kembar paling umum.
document.querySelector('.fm-form')?.addEventListener('submit', e => {
  const b = e.target.querySelector('.fm-kirim');
  setTimeout(() => { b.disabled = true; b.textContent = 'Mengirim…'; }, 0);
});
</script>
<?php require __DIR__ . '/partials/public-foot.php';
