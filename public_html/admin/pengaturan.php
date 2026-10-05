<?php
require_once __DIR__ . '/_layout.php';
$user = requireLogin();

$keys = ['site_name','site_description','contact_email','wa_number','ig_url','fb_url','tiktok_url','youtube_url',
         'address_street','address_city','address_region','address_zip','area_served','price_range',
         'default_og_image','meeting_duration','ga_measurement_id','gsc_verification'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'site') {
            foreach ($keys as $k) if (isset($_POST[$k])) settingSet($k, trim($_POST[$k]));
            flash('Pengaturan situs disimpan.');
        }
        elseif ($act === 'password') {
            $cur = $_POST['current'] ?? ''; $new = $_POST['new'] ?? ''; $rep = $_POST['repeat'] ?? '';
            $row = one("SELECT password_hash FROM users WHERE id = ?", [$user['id']]);
            if (!password_verify($cur, $row['password_hash'])) throw new RuntimeException('Kata sandi saat ini salah.');
            if (strlen($new) < 12)  throw new RuntimeException('Kata sandi baru minimal 12 karakter.');
            if ($new !== $rep)      throw new RuntimeException('Konfirmasi kata sandi tidak sama.');
            q("UPDATE users SET password_hash = ? WHERE id = ?", [password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), $user['id']]);
            flash('Kata sandi diperbarui.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('admin/pengaturan.php');
}

$s = fn(string $k, string $d = '') => e(setting($k, $d) ?? '');
adminHead('Pengaturan', 'pengaturan');
pageHead('Pengaturan', 'Identitas bisnis di sini dipakai untuk data terstruktur LocalBusiness — inilah yang dibaca Google untuk hasil pencarian lokal.');
?>
<form method="post">
  <?= csrfField() ?><input type="hidden" name="act" value="site">
  <div class="grid g2" style="align-items:start">
    <div class="card">
      <h2>Identitas</h2>
      <div class="field"><label for="sn">Nama bisnis</label><input type="text" id="sn" name="site_name" value="<?= $s('site_name') ?>"></div>
      <div class="field"><label for="sd">Deskripsi situs</label><textarea id="sd" name="site_description" rows="3" maxlength="255"><?= $s('site_description') ?></textarea>
        <p class="hint">Dipakai sebagai meta description beranda. Ideal 120–158 karakter.</p></div>
      <div class="row c2">
        <div class="field"><label for="ce">Email kontak</label><input type="email" id="ce" name="contact_email" value="<?= $s('contact_email') ?>"></div>
        <div class="field"><label for="wa">Nomor WhatsApp</label><input type="text" id="wa" name="wa_number" value="<?= $s('wa_number') ?>" placeholder="6281234567890">
          <p class="hint">Format internasional tanpa tanda +.</p></div>
      </div>
      <div class="field"><label for="og">Gambar OG bawaan</label><input type="url" id="og" name="default_og_image" value="<?= $s('default_og_image') ?>" placeholder="<?= e(url('foto/dream-come-true.jpg')) ?>">
        <p class="hint">Muncul saat tautan situs dibagikan di WhatsApp atau media sosial. Ukuran ideal 1200×630.</p></div>
      <div class="field"><label for="dur">Durasi pertemuan bawaan (menit)</label><input type="number" id="dur" name="meeting_duration" value="<?= $s('meeting_duration', '60') ?>" min="15" step="15"></div>
    </div>

    <div>
      <div class="card">
        <h2>Alamat &amp; jangkauan</h2>
        <div class="field"><label for="as">Jalan</label><input type="text" id="as" name="address_street" value="<?= $s('address_street') ?>"></div>
        <div class="row c2">
          <div class="field"><label for="ac">Kota</label><input type="text" id="ac" name="address_city" value="<?= $s('address_city') ?>"></div>
          <div class="field"><label for="az">Kode pos</label><input type="text" id="az" name="address_zip" value="<?= $s('address_zip') ?>"></div>
        </div>
        <div class="field"><label for="ar">Provinsi</label><input type="text" id="ar" name="address_region" value="<?= $s('address_region') ?>"></div>
        <div class="field"><label for="av">Kota yang dilayani</label><input type="text" id="av" name="area_served" value="<?= $s('area_served') ?>">
          <p class="hint">Pisahkan dengan koma. Masuk ke data terstruktur sebagai areaServed.</p></div>
        <div class="field"><label for="pr">Rentang harga</label><input type="text" id="pr" name="price_range" value="<?= $s('price_range', 'Rp') ?>" placeholder="Rp"></div>
      </div>

      <div class="card">
        <h2>Media sosial</h2>
        <div class="field"><label for="ig">Instagram</label><input type="url" id="ig" name="ig_url" value="<?= $s('ig_url') ?>"></div>
        <div class="field"><label for="fb">Facebook</label><input type="url" id="fb" name="fb_url" value="<?= $s('fb_url') ?>"></div>
        <div class="field"><label for="tk">TikTok</label><input type="url" id="tk" name="tiktok_url" value="<?= $s('tiktok_url') ?>"></div>
        <div class="field"><label for="yt">YouTube</label><input type="url" id="yt" name="youtube_url" value="<?= $s('youtube_url') ?>"></div>
        <p class="hint">Semua terisi ke properti sameAs — membantu Google menghubungkan situs dengan akun resmi kalian.</p>
      </div>

      <div class="card">
        <h2>Pelacakan</h2>
        <div class="field"><label for="ga">Google Analytics 4</label><input type="text" id="ga" name="ga_measurement_id" value="<?= $s('ga_measurement_id') ?>" placeholder="G-XXXXXXXXXX"></div>
        <div class="field"><label for="gv">Verifikasi Search Console</label><input type="text" id="gv" name="gsc_verification" value="<?= $s('gsc_verification') ?>" placeholder="isi nilai content= dari meta tag">
          <p class="hint">Metode "HTML tag" di Search Console. Cukup nilai <code>content</code>-nya saja.</p></div>
      </div>
    </div>
  </div>
  <div class="sticky-actions"><button class="btn solid" type="submit">Simpan pengaturan</button></div>
</form>

<div class="card" style="margin-top:26px">
  <h2>Ganti kata sandi</h2>
  <form method="post">
    <?= csrfField() ?><input type="hidden" name="act" value="password">
    <div class="row c3">
      <div class="field"><label for="pc">Kata sandi saat ini</label><?= pwField('pc','current',['required'=>1,'autocomplete'=>'current-password']) ?></div>
      <div class="field"><label for="pn">Kata sandi baru</label><?= pwField('pn', 'new', ['required'=>1,'minlength'=>12,'autocomplete'=>'new-password', 'strength' => true]) ?></div>
      <div class="field"><label for="pr2">Ulangi</label><?= pwField('pr2','repeat',['required'=>1,'autocomplete'=>'new-password']) ?></div>
    </div>
    <button class="btn" type="submit">Perbarui kata sandi</button>
  </form>
</div>

<?= pwScript() ?>
<?php adminFoot();
