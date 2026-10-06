<?php
require_once __DIR__ . '/_layout.php';
$user = requireLogin();

$keys = ['site_name','site_description','contact_email','wa_number','ig_url','fb_url','tiktok_url','youtube_url',
         'address_street','address_city','address_region','address_zip','area_served','price_range',
         'default_og_image','meeting_duration','ga_measurement_id','gsc_verification',
         'site_tagline','wa_admin_biasa','wa_admin_office','bank_nama','bank_norek','bank_atasnama'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'site') {
            foreach ($keys as $k) if (isset($_POST[$k])) settingSet($k, trim($_POST[$k]));
            flash('Pengaturan situs disimpan.');
        }
        elseif ($act === 'termin_tpl') {
            // Template termin untuk klien BARU. Klien lama tidak berubah:
            // jadwal mereka sudah dibekukan di payments.offset_hari.
            $baris = [];
            foreach ((array) ($_POST['t'] ?? []) as $id => $r) {
                $label = mb_substr(trim((string) ($r['label'] ?? '')), 0, 80);
                if ($label === '') continue;
                $kapan = trim((string) ($r['offset'] ?? ''));
                $baris[] = ['id' => (int) $id, 'label' => $label,
                            'persen' => round((float) str_replace(',', '.', (string) ($r['persen'] ?? 0)), 2),
                            'offset' => $kapan === '' ? null : (int) $kapan,
                            'urutan' => (int) ($r['urutan'] ?? 0), 'aktif' => !empty($r['aktif']) ? 1 : 0];
            }
            $nb = trim((string) ($_POST['baru_label'] ?? ''));
            if ($nb !== '') {
                $baris[] = ['id' => 0, 'label' => mb_substr($nb, 0, 80), 'persen' => round((float) str_replace(',', '.', (string) ($_POST['baru_persen'] ?? 0)), 2),
                            'offset' => ($_POST['baru_offset'] ?? '') === '' ? null : (int) $_POST['baru_offset'], 'urutan' => 99, 'aktif' => 1];
            }
            $aktif = array_filter($baris, fn($b) => $b['aktif']);
            $total = array_sum(array_map(fn($b) => $b['persen'], $aktif));
            if (abs($total - 100) > 0.01) throw new RuntimeException('Total persen termin aktif harus 100% (sekarang ' . rtrim(rtrim(number_format($total, 2, ',', ''), '0'), ',') . '%).');
            $dealing = one("SELECT id FROM payment_templates WHERE kode = 'dealing'");
            $offs = [];
            foreach ($aktif as $b) {
                if ($b['persen'] <= 0) throw new RuntimeException('Persen tiap termin aktif harus lebih dari 0.');
                $isDeal = $dealing && $b['id'] === (int) $dealing['id'];
                if ($isDeal && $b['offset'] !== null) throw new RuntimeException('Termin DP selalu "saat DP" — kosongkan kolom H-nya.');
                if (!$isDeal) {
                    if ($b['offset'] === null || $b['offset'] < 1 || $b['offset'] > 365)
                        throw new RuntimeException('Termin "' . $b['label'] . '" butuh H- antara 1 dan 365.');
                    if (isset($offs[$b['offset']])) throw new RuntimeException('Dua termin tidak boleh jatuh di H-' . $b['offset'] . ' yang sama.');
                    $offs[$b['offset']] = true;
                }
            }
            if (!$dealing || !array_filter($aktif, fn($b) => $b['id'] === (int) $dealing['id']))
                throw new RuntimeException('Termin DP (dealing) wajib aktif.');
            foreach ($baris as $b) {
                if ($b['id']) {
                    q("UPDATE payment_templates SET label = ?, persen = ?, offset_hari = ?, urutan = ?, is_active = ? WHERE id = ?",
                      [$b['label'], $b['persen'], $b['id'] === (int) $dealing['id'] ? null : $b['offset'], $b['urutan'], $b['aktif'], $b['id']]);
                } else {
                    $kode = 'termin_' . substr(bin2hex(random_bytes(3)), 0, 6);
                    q("INSERT INTO payment_templates (kode, label, persen, offset_hari, urutan, wajib, is_active) VALUES (?,?,?,?,?,0,1)",
                      [$kode, $b['label'], $b['persen'], $b['offset'], $b['urutan']]);
                }
            }
            // Urutan: DP pertama, lalu dari H- terbesar (paling awal) ke terkecil.
            q("UPDATE payment_templates SET urutan = CASE WHEN kode = 'dealing' THEN 1 ELSE 400 - COALESCE(offset_hari, 0) END");
            $dpPersen = (float) (one("SELECT persen FROM payment_templates WHERE kode = 'dealing'")['persen'] ?? 30);
            settingSet('dp_percent', rtrim(rtrim(number_format($dpPersen, 2, '.', ''), '0'), '.'));
            settingSet('dp_tenggat_hari', (string) max(1, min(30, (int) ($_POST['dp_tenggat_hari'] ?? 3))));
            flash('Susunan termin disimpan. Berlaku untuk klien yang termin-nya disusun setelah ini.');
        }
        elseif ($act === 'pengingat') {
            $nyala = !empty($_POST['bayar_ingat_aktif']);
            if ($nyala && setting('bayar_ingat_aktif', '0') !== '1') settingSet('bayar_ingat_mulai', date('Y-m-d'));
            settingSet('bayar_ingat_aktif', $nyala ? '1' : '0');
            settingSet('kwitansi_kota', mb_substr(trim((string) ($_POST['kwitansi_kota'] ?? '')), 0, 60));
            settingSet('kwitansi_penandatangan', mb_substr(trim((string) ($_POST['kwitansi_penandatangan'] ?? '')), 0, 80));
            flash($nyala ? 'Pengingat pembayaran otomatis aktif mulai besok pagi.' : 'Pengingat pembayaran otomatis mati.');
        }
        elseif ($act === 'portal') {
            settingSet('portal_aktif', !empty($_POST['portal_aktif']) ? '1' : '0');
            settingSet('portal_kunci_hari', (string) max(0, min(120, (int) ($_POST['portal_kunci_hari'] ?? 30))));
            settingSet('portal_salam', mb_substr(trim((string) ($_POST['portal_salam'] ?? '')), 0, 200));
            flash('Pengaturan dashboard pengantin disimpan.');
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
        <div class="field"><label for="wa">Nomor WhatsApp utama</label><input type="text" id="wa" name="wa_number" value="<?= $s('wa_number') === '6281234567890' ? '' : $s('wa_number') ?>" placeholder="62812…">
          <p class="hint">Dipakai tombol WhatsApp di formulir dan price list. Format 62…, tanpa tanda +.
            <?php if (setting('wa_number') === '6281234567890'): ?><b style="color:var(--rose)">Masih berisi nomor contoh — isi nomor yang benar.</b><?php endif; ?></p></div>
      </div>
      <div class="field"><label for="tg">Tagline (kop PDF)</label><input type="text" id="tg" name="site_tagline" value="<?= $s('site_tagline', 'Wedding Organizer · Yogyakarta') ?>"></div>
      <div class="field"><label for="og">Gambar OG bawaan</label><input type="url" id="og" name="default_og_image" value="<?= $s('default_og_image') ?>" placeholder="<?= e(url('foto/dream-come-true.jpg')) ?>">
        <p class="hint">Muncul saat tautan situs dibagikan di WhatsApp atau media sosial. Ukuran ideal 1200×630.</p></div>
      <div class="field"><label for="dur">Durasi pertemuan bawaan (menit)</label><input type="number" id="dur" name="meeting_duration" value="<?= $s('meeting_duration', '60') ?>" min="15" step="15"></div>
    </div>

    <div>
      <div class="card">
        <h2>Price list &amp; pembayaran</h2>
        <p class="sub">Tercetak di PDF price list dan teks WhatsApp yang dikirim ke klien.</p>
        <div class="row c2">
          <div class="field"><label for="wab">WA admin early</label><input type="text" id="wab" name="wa_admin_biasa" value="<?= $s('wa_admin_biasa') ?>" placeholder="62812…">
            <p class="hint">Kontak di price list sebelum DP.</p></div>
          <div class="field"><label for="wao">WA admin office</label><input type="text" id="wao" name="wa_admin_office" value="<?= $s('wa_admin_office') ?>" placeholder="62812…">
            <p class="hint">Kontak setelah DP.</p></div>
        </div>
        <div class="row c3">
          <div class="field"><label for="bn">Bank</label><input type="text" id="bn" name="bank_nama" value="<?= $s('bank_nama') ?>" placeholder="BCA"></div>
          <div class="field"><label for="br">Nomor rekening</label><input type="text" id="br" name="bank_norek" value="<?= $s('bank_norek') ?>"></div>
          <div class="field"><label for="ba">Atas nama</label><input type="text" id="ba" name="bank_atasnama" value="<?= $s('bank_atasnama') ?>"></div>
        </div>
      </div>

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

<?php
require_once __DIR__ . '/../inc/bayar.php';
require_once __DIR__ . '/../inc/penawaran.php';   // persenTeks()
$tplT = [];
try { $tplT = all("SELECT * FROM payment_templates ORDER BY is_active DESC, urutan, id"); } catch (Throwable $e) {}
$besok = date('Y-m-d', strtotime('+1 day'));
$pratinjau = [];
try { $pratinjau = bayarPengingatHarian(false, $besok); } catch (Throwable $e) {}
$cronTerakhir = (string) setting('cron_terakhir', '');
?>
<div class="grid g2" style="align-items:start;margin-top:26px">
  <div class="card" id="termin">
    <h2>Termin pembayaran</h2>
    <p class="sub">Susunan untuk klien yang termin-nya disusun setelah ini. Klien lama tidak ikut berubah.
      DP selalu "saat DP"; termin lain dihitung mundur dari hari-H.</p>
    <form method="post">
      <?= csrfField() ?><input type="hidden" name="act" value="termin_tpl">
      <div style="overflow-x:auto"><table class="tbl" style="min-width:520px">
        <thead><tr><th>Nama termin <span class="muted">(terlihat klien)</span></th><th class="num">%</th><th>Kapan</th><th>Aktif</th></tr></thead>
        <tbody>
        <?php foreach ($tplT as $t): $dl = $t['kode'] === 'dealing'; ?>
          <tr>
            <td><input type="text" name="t[<?= (int) $t['id'] ?>][label]" value="<?= e($t['label']) ?>" maxlength="80">
              <input type="hidden" name="t[<?= (int) $t['id'] ?>][urutan]" value="<?= (int) $t['urutan'] ?>"></td>
            <td style="width:84px"><input type="text" inputmode="decimal" class="tpl-persen" name="t[<?= (int) $t['id'] ?>][persen]" value="<?= e(persenTeks($t['persen'])) ?>"></td>
            <td style="width:120px"><?php if ($dl): ?><span class="muted">saat DP</span><input type="hidden" name="t[<?= (int) $t['id'] ?>][offset]" value="">
              <?php else: ?><div style="display:flex;align-items:center;gap:4px">H-<input type="number" min="1" max="365" name="t[<?= (int) $t['id'] ?>][offset]" value="<?= e((string) $t['offset_hari']) ?>" style="width:72px"></div><?php endif; ?></td>
            <td style="width:60px"><input type="checkbox" class="tpl-aktif" name="t[<?= (int) $t['id'] ?>][aktif]" value="1" <?= $t['is_active'] ? 'checked' : '' ?> <?= $dl ? 'disabled checked' : '' ?>>
              <?php if ($dl): ?><input type="hidden" name="t[<?= (int) $t['id'] ?>][aktif]" value="1"><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
          <tr>
            <td><input type="text" name="baru_label" placeholder="+ termin baru" maxlength="80"></td>
            <td><input type="text" inputmode="decimal" class="tpl-persen tpl-baru" name="baru_persen" placeholder="0"></td>
            <td><div style="display:flex;align-items:center;gap:4px">H-<input type="number" min="1" max="365" name="baru_offset" style="width:72px"></div></td>
            <td></td>
          </tr>
        </tbody>
      </table></div>
      <p class="hint" id="tplTotal">Total aktif: —</p>
      <div class="row c2" style="align-items:end">
        <div class="field"><label>Tenggat DP (hari setelah cocok)</label><input type="number" name="dp_tenggat_hari" min="1" max="30" value="<?= e(setting('dp_tenggat_hari', '3')) ?>"></div>
        <div class="field"><button class="btn solid" type="submit">Simpan susunan termin</button></div>
      </div>
    </form>
  </div>

  <div>
    <div class="card" id="pengingat">
      <h2>Pengingat pembayaran</h2>
      <p class="sub">WhatsApp otomatis lewat gateway, dikirim cron harian pagi: satu pengingat H-3 sebelum jatuh tempo, dan satu kali
        setelah lewat 2 hari. Sesudah itu berhenti — termin muncul di Ringkasan untuk dihubungi langsung. Klien sudah DP saja;
        minggu hari-H tidak ditagih otomatis.</p>
      <form method="post">
        <?= csrfField() ?><input type="hidden" name="act" value="pengingat">
        <label class="inline" style="margin-bottom:12px"><input type="checkbox" name="bayar_ingat_aktif" value="1" <?= setting('bayar_ingat_aktif', '0') === '1' ? 'checked' : '' ?>>
          Kirim pengingat otomatis</label>
        <div class="row c2">
          <div class="field"><label>Kota di kwitansi</label><input type="text" name="kwitansi_kota" value="<?= $s('kwitansi_kota') ?>" placeholder="<?= $s('address_city', 'Yogyakarta') ?>"></div>
          <div class="field"><label>Penanda tangan kwitansi</label><input type="text" name="kwitansi_penandatangan" value="<?= $s('kwitansi_penandatangan') ?>" placeholder="<?= $s('site_name', 'Callalily Party') ?>"></div>
        </div>
        <button class="btn" type="submit">Simpan</button>
      </form>
      <?php if (!waSiap()): ?><p class="hint" style="color:var(--rose);margin-top:10px">Gateway WhatsApp belum tersambung — pengingat tidak bisa terkirim.</p><?php endif; ?>
      <p class="hint" style="margin-top:10px">Cron harian terakhir berjalan: <b><?= $cronTerakhir ? e(labelHari($cronTerakhir)) : 'belum pernah tercatat' ?></b>
        <?= $cronTerakhir && $cronTerakhir < date('Y-m-d', strtotime('-1 day')) ? ' — periksa Cron Jobs di DirectAdmin.' : '' ?></p>
      <span class="lab" style="display:block;margin:14px 0 6px">Pratinjau besok pagi (<?= e(tanggalID($besok)) ?>)</span>
      <?php if (!$pratinjau): ?>
        <p class="sub" style="margin:0">Tidak ada pengingat yang akan terkirim.</p>
      <?php else: ?>
        <ul class="daftar">
          <?php foreach ($pratinjau as $pv): ?>
            <li><a href="klien.php?id=<?= (int) $pv['client_id'] ?>#uang"><b><?= e($pv['nama']) ?></b>
              <span><?= e(implode(' + ', array_map(fn($t) => $t['label'], $pv['termin']))) ?> · <?= rupiah(array_sum(array_map('bayarSisa', $pv['termin']))) ?></span></a>
              <span class="pill <?= $pv['status'] === 'ditahan' ? 'draft' : ($pv['jenis'] === 'telat' ? 'bad' : 'warn') ?>"><?= e($pv['status'] === 'ditahan' ? 'ditahan' : ($pv['jenis'] === 'telat' ? 'lewat tempo' : 'H-3')) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <p class="hint">"Ditahan" = klien baru mengirim gambar di WhatsApp dalam 48 jam (mungkin bukti transfer) — cek dulu.</p>
      <?php endif; ?>
    </div>

    <div class="card" id="portal">
      <h2>Dashboard pengantin</h2>
      <p class="sub">Halaman pribadi untuk klien yang sudah DP: hitung mundur, pembayaran & kwitansi, jadwal meeting, vendor,
        dan formulir data keluarga. Tautannya dikirim dari halaman klien.</p>
      <form method="post">
        <?= csrfField() ?><input type="hidden" name="act" value="portal">
        <label class="inline" style="margin-bottom:12px"><input type="checkbox" name="portal_aktif" value="1" <?= setting('portal_aktif', '1') !== '0' ? 'checked' : '' ?>>
          Dashboard pengantin aktif <span class="muted">(matikan semua tautan sekaligus bila ada insiden)</span></label>
        <div class="row c2">
          <div class="field"><label>Kunci formulir mulai H-</label><input type="number" name="portal_kunci_hari" min="0" max="120" value="<?= $s('portal_kunci_hari', '30') ?>">
            <p class="hint">Setelah itu data keluarga hanya bisa diubah lewat PIC (untuk cetak undangan &amp; naskah MC).</p></div>
          <div class="field"><label>Kalimat sambutan</label><input type="text" name="portal_salam" maxlength="200" value="<?= $s('portal_salam') ?>" placeholder="Terima kasih sudah mempercayakan hari kalian kepada kami."></div>
        </div>
        <button class="btn" type="submit">Simpan</button>
      </form>
    </div>
  </div>
</div>
<script>
(() => {
  const out = document.getElementById('tplTotal'); if (!out) return;
  const hitung = () => {
    let t = 0;
    document.querySelectorAll('.tpl-persen').forEach(el => {
      const row = el.closest('tr'); const cb = row.querySelector('.tpl-aktif');
      if (cb && !cb.checked) return;
      if (el.classList.contains('tpl-baru') && !row.querySelector('[name=baru_label]').value.trim()) return;
      t += parseFloat((el.value || '0').replace(',', '.')) || 0;
    });
    out.textContent = 'Total aktif: ' + (Math.round(t * 100) / 100) + '%' + (Math.abs(t - 100) < 0.01 ? ' ✓' : ' — harus 100%');
    out.style.color = Math.abs(t - 100) < 0.01 ? 'var(--sage-text)' : 'var(--rose)';
  };
  document.querySelectorAll('.tpl-persen,.tpl-aktif,[name=baru_label]').forEach(el => el.addEventListener('input', hitung));
  hitung();
})();
</script>

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
