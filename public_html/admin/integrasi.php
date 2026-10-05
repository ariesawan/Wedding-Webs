<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/google.php';
require_once __DIR__ . '/../inc/zoom.php';
require_once __DIR__ . '/../inc/mailer.php';
require_once __DIR__ . '/../inc/sheets.php';
require_once __DIR__ . '/../inc/diagnostics.php';
require_once __DIR__ . '/../inc/vendor.php';
require_once __DIR__ . '/../inc/wa.php';
$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'google') {
            settingSet('google_client_id',     trim($_POST['google_client_id'] ?? ''));
            settingSet('google_client_secret', trim($_POST['google_client_secret'] ?? ''), true);
            settingSet('google_calendar_id',   trim($_POST['google_calendar_id'] ?? 'primary'));
            flash('Kredensial Google disimpan. Klik "Hubungkan" untuk memberi izin.');
        }
        elseif ($act === 'google_disconnect') {
            foreach (['google_refresh_token','google_access_token','google_token_expires'] as $k) settingSet($k, null, true);
            flash('Koneksi Google diputus. Event yang sudah dibuat tetap ada di kalender.', 'warn');
        }
        elseif ($act === 'zoom') {
            settingSet('zoom_account_id',    trim($_POST['zoom_account_id'] ?? ''), true);
            settingSet('zoom_client_id',     trim($_POST['zoom_client_id'] ?? ''), true);
            settingSet('zoom_client_secret', trim($_POST['zoom_client_secret'] ?? ''), true);
            settingSet('zoom_host_email',    trim($_POST['zoom_host_email'] ?? 'me'));
            settingSet('zoom_access_token', null, true);
            settingSet('zoom_token_expires', '0');
            flash('Kredensial Zoom disimpan.');
        }
        elseif ($act === 'zoom_test') {
            $tok = zoomAccessToken();
            $me  = zoomCall('GET', '/users/me');
            flash('Zoom terhubung sebagai ' . ($me['email'] ?? '—') . ' (' . ($me['type'] == 1 ? 'Basic' : 'Berbayar') . ').');
        }
        elseif ($act === 'sheet') {
            $aktif = isset($_POST['sheet_enabled']) ? '1' : '0';
            $sebelum = setting('sheet_enabled');
            settingSet('sheet_enabled', $aktif);
            settingSet('sheet_autosync', isset($_POST['sheet_autosync']) ? '1' : '0');
            $sid = trim($_POST['sheet_id'] ?? '');
            // Terima tempelan URL penuh, ambil ID-nya saja.
            if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $sid, $m)) $sid = $m[1];
            settingSet('sheet_id', $sid);
            if ($sid) settingSet('sheet_url', 'https://docs.google.com/spreadsheets/d/' . $sid);

            if ($aktif === '1' && $sebelum !== '1' && googleConnected() && !googleHasScope(G_SCOPE_SHEET)) {
                flash('Fitur spreadsheet dinyalakan. Tekan "Hubungkan ulang" di bagian Google agar izin Spreadsheet diminta — tanpa itu sinkronisasi akan ditolak.', 'warn');
            } else {
                flash('Pengaturan spreadsheet disimpan.');
            }
        }
        elseif ($act === 'sheet_sync') {
            $hasil = sheetsSyncAll();
            $rincian = [];
            foreach ($hasil as $tab => $n) $rincian[] = "$tab: $n baris";
            flash('Spreadsheet diperbarui. ' . implode(', ', $rincian) . '.');
        }
        elseif ($act === 'sheet_new') {
            settingSet('sheet_id', null);
            settingSet('sheet_url', null);
            $id = sheetsCreate();
            sheetsSyncAll();
            flash('Spreadsheet baru dibuat dan diisi.');
        }
        elseif ($act === 'wa') {
            settingSet('wa_provider', in_array($_POST['wa_provider'] ?? '', ['none','cloud','gateway'], true) ? $_POST['wa_provider'] : 'none');
            settingSet('wa_phone_id', trim($_POST['wa_phone_id'] ?? ''));
            if (!empty($_POST['wa_token']))        settingSet('wa_token', trim($_POST['wa_token']), true);
            if (!empty($_POST['wa_verify_token'])) settingSet('wa_verify_token', trim($_POST['wa_verify_token']), true);
            if (!empty($_POST['wa_app_secret']))   settingSet('wa_app_secret', trim($_POST['wa_app_secret']), true);
            settingSet('wa_gateway_url', trim($_POST['wa_gateway_url'] ?? ''));
            if (!empty($_POST['wa_gateway_token'])) settingSet('wa_gateway_token', trim($_POST['wa_gateway_token']), true);
            settingSet('wa_gateway_ftarget', trim($_POST['wa_gateway_ftarget'] ?? 'target'));
            settingSet('wa_gateway_fbody',   trim($_POST['wa_gateway_fbody'] ?? 'message'));
            settingSet('wa_gateway_auth',    in_array($_POST['wa_gateway_auth'] ?? '', ['header','bearer','body'], true) ? $_POST['wa_gateway_auth'] : 'header');
            flash('Pengaturan WhatsApp disimpan.');
        }
        elseif ($act === 'wa_test') {
            $no = trim($_POST['wa_test_no'] ?? '');
            if ($no === '') throw new RuntimeException('Isi nomor tujuan uji.');
            $r = waKirim($no, 'Uji kirim dari panel ' . setting('site_name', 'Callalily') . ' — ' . date('d/m/Y H:i') . '. Abaikan pesan ini.');
            flash($r['ok'] ? 'Pesan uji terkirim. Cek WhatsApp nomor tersebut.' : 'Gagal: ' . $r['error'], $r['ok'] ? 'ok' : 'err');
        }
        elseif ($act === 'mail') {
            settingSet('mail_method',     $_POST['mail_method'] ?? 'mail');
            settingSet('mail_from_name',  trim($_POST['mail_from_name'] ?? ''));
            settingSet('mail_from_email', trim($_POST['mail_from_email'] ?? ''));
            settingSet('smtp_host',       trim($_POST['smtp_host'] ?? ''));
            settingSet('smtp_port',       trim($_POST['smtp_port'] ?? '587'));
            settingSet('smtp_secure',     $_POST['smtp_secure'] ?? 'tls');
            settingSet('smtp_user',       trim($_POST['smtp_user'] ?? ''));
            if (!empty($_POST['smtp_pass'])) settingSet('smtp_pass', $_POST['smtp_pass'], true);
            flash('Pengaturan email disimpan.');
        }
        elseif ($act === 'sheet') {
            $aktif = isset($_POST['sheet_enabled']) ? '1' : '0';
            $sebelum = setting('sheet_enabled');
            settingSet('sheet_enabled', $aktif);
            settingSet('sheet_id', trim($_POST['sheet_id'] ?? ''));
            settingSet('sheet_autosync', isset($_POST['sheet_autosync']) ? '1' : '0');
            if ($aktif === '1' && $sebelum !== '1' && googleConnected()) {
                flash('Spreadsheet diaktifkan. Google perlu izin tambahan — tekan "Hubungkan ulang Google Calendar" di atas, lalu setujui akses Spreadsheet.', 'warn');
            } else {
                flash('Pengaturan spreadsheet disimpan.');
            }
        }
        elseif ($act === 'sheet_create') {
            $id = sheetsCreate();
            flash('Spreadsheet baru dibuat. Semua tab sudah disiapkan — tekan "Sinkronkan sekarang" untuk mengisinya.');
        }
        elseif ($act === 'sheet_sync') {
            $hasil = sheetsSyncAll();
            $ket = [];
            foreach ($hasil as $tab => $n) $ket[] = "$tab: $n baris";
            flash('Sinkronisasi selesai. ' . implode(' · ', $ket));
        }
        elseif ($act === 'mail_test') {
            $to = trim($_POST['test_email'] ?? '') ?: $user['email'];
            $ok = sendMail($to, $user['name'], 'Uji kirim dari panel Callalily',
                  '<p style="font-family:sans-serif">Kalau email ini sampai, pengaturan pengiriman sudah benar.</p>');
            flash($ok ? "Email uji dikirim ke $to. Cek juga folder spam." : 'Pengiriman gagal. Periksa host, port, dan kredensial SMTP.',
                  $ok ? 'ok' : 'err');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    redirect('admin/integrasi.php');
}

adminHead('Integrasi', 'integrasi');
pageHead('Integrasi', 'Google Calendar untuk undangan klien, Zoom untuk pertemuan daring, Spreadsheet untuk rekap, SMTP supaya email tidak masuk spam.');


$gConn = googleConnected();
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

$diag = runDiagnostics();
$dc   = diagnosticsCount($diag);
$adaMasalah = $dc['bad'] > 0;
?>

<div class="card" style="<?= $adaMasalah ? 'border-color:rgba(217,123,123,.45)' : '' ?>">
  <h2>Pemeriksaan lingkungan
    <?php if ($dc['bad']): ?><span class="pill bad" style="margin-left:8px"><?= $dc['bad'] ?> masalah</span>
    <?php elseif ($dc['warn']): ?><span class="pill warn" style="margin-left:8px"><?= $dc['warn'] ?> peringatan</span>
    <?php else: ?><span class="pill live dot" style="margin-left:8px">Semua siap</span><?php endif; ?>
  </h2>
  <p class="sub">Kalau integrasi tidak jalan, jawabannya hampir selalu ada di daftar ini.</p>

  <ul class="diag">
    <?php foreach ($diag as $x):
      $ic = ['ok' => '✓', 'warn' => '!', 'bad' => '✕'][$x['status']];
      $cl = ['ok' => 'ok', 'warn' => 'wr', 'bad' => 'no'][$x['status']]; ?>
      <li>
        <span class="st <?= $cl ?>"><?= $ic ?></span>
        <span class="tx"><b><?= e($x['title']) ?></b><span><?= $x['detail'] ?></span></span>
      </li>
    <?php endforeach; ?>
  </ul>

  <div style="margin-top:18px">
    <span class="lab">Alamat callback yang harus didaftarkan di Google Cloud Console</span>
    <div class="copyrow">
      <code id="ruri"><?= e(GOOGLE_REDIRECT_URI) ?></code>
      <button class="btn sm ghost" type="button" onclick="navigator.clipboard.writeText(document.getElementById('ruri').textContent).then(()=>{this.textContent='Tersalin';setTimeout(()=>this.textContent='Salin',1600)})">Salin</button>
    </div>
    <p class="hint">Harus sama <b>persis</b> — beda satu karakter, garis miring di akhir, atau http vs https, Google menolak dengan <code>redirect_uri_mismatch</code>.</p>
  </div>
</div>

<div class="card">
  <h2>Google Calendar <?= $gConn ? '<span class="pill live dot" style="margin-left:8px">Terhubung</span>' : '<span class="pill draft" style="margin-left:8px">Belum terhubung</span>' ?></h2>
  <p class="sub">Dipakai untuk membuat event, mengundang email klien, dan membuat tautan Google Meet otomatis.</p>

  <details style="margin-bottom:20px">
    <summary style="cursor:pointer;color:var(--ember);font-size:14px;padding:8px 0">Cara mendapatkan Client ID &amp; Secret</summary>
    <ol style="font-size:13.5px;color:var(--ivory-60);line-height:1.85;margin:12px 0 0 20px">
      <li>Buka <code>console.cloud.google.com</code> → buat project baru.</li>
      <li>APIs &amp; Services → Library → aktifkan <b>Google Calendar API</b>.</li>
      <li>OAuth consent screen → tipe <b>External</b> → isi nama aplikasi &amp; email dukungan.
          Selama masih “Testing”, tambahkan email owner di <b>Test users</b>, atau tekan <b>Publish app</b> agar refresh token tidak kedaluwarsa 7 hari.</li>
      <li>Credentials → Create Credentials → <b>OAuth client ID</b> → Application type <b>Web application</b>.</li>
      <li>Authorized redirect URI, isi persis: <code><?= e(GOOGLE_REDIRECT_URI) ?></code></li>
      <li>Salin Client ID dan Client Secret ke formulir di bawah.</li>
    </ol>
  </details>

  <form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="act" value="google">
    <div class="row c2">
      <div class="field"><label for="gci">Client ID</label>
        <input type="text" id="gci" name="google_client_id" value="<?= e(setting('google_client_id', '')) ?>" placeholder="xxxx.apps.googleusercontent.com"></div>
      <div class="field"><label for="gcs">Client Secret</label>
        <input type="password" id="gcs" name="google_client_secret" value="<?= e(setting('google_client_secret', '')) ?>" autocomplete="off"></div>
    </div>
    <div class="field"><label for="gcal">Calendar ID</label>
      <input type="text" id="gcal" name="google_calendar_id" value="<?= e(setting('google_calendar_id', 'primary')) ?>">
      <p class="hint"><code>primary</code> = kalender utama akun yang memberi izin. Bisa juga diisi ID kalender bersama, misalnya <code>xxxx@group.calendar.google.com</code>.</p></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn" type="submit">Simpan kredensial</button>
      <?php if (googleConfigured()): ?>
        <a class="btn solid" href="<?= e(googleAuthUrl($state)) ?>"><?= $gConn ? 'Hubungkan ulang' : 'Hubungkan Google Calendar' ?></a>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($gConn): ?>
    <form method="post" style="margin-top:14px">
      <?= csrfField() ?><input type="hidden" name="act" value="google_disconnect">
      <button class="btn ghost danger sm" type="submit">Putuskan koneksi</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Zoom <?= zoomConfigured() ? '<span class="pill live dot" style="margin-left:8px">Terkonfigurasi</span>' : '<span class="pill draft" style="margin-left:8px">Belum diisi</span>' ?></h2>
  <p class="sub">Server-to-Server OAuth. Aplikasi JWT sudah dimatikan Zoom, jadi tipe inilah yang dipakai sekarang.</p>

  <details style="margin-bottom:20px">
    <summary style="cursor:pointer;color:var(--ember);font-size:14px;padding:8px 0">Cara membuat aplikasi Server-to-Server OAuth</summary>
    <ol style="font-size:13.5px;color:var(--ivory-60);line-height:1.85;margin:12px 0 0 20px">
      <li>Buka <code>marketplace.zoom.us</code> → Develop → Build App.</li>
      <li>Pilih <b>Server-to-Server OAuth</b>, beri nama, lalu Create.</li>
      <li>Di tab Scopes, tambahkan <code>meeting:write:admin</code> (pada akun dengan granular scope: <code>meeting:write:meeting:admin</code> dan <code>meeting:delete:meeting:admin</code>).</li>
      <li>Aktifkan aplikasinya di tab Activation.</li>
      <li>Salin Account ID, Client ID, dan Client Secret dari tab App Credentials.</li>
    </ol>
  </details>

  <form method="post">
    <?= csrfField() ?><input type="hidden" name="act" value="zoom">
    <div class="row c3">
      <div class="field"><label for="za">Account ID</label><input type="password" id="za" name="zoom_account_id" value="<?= e(setting('zoom_account_id', '')) ?>" autocomplete="off"></div>
      <div class="field"><label for="zc">Client ID</label><input type="password" id="zc" name="zoom_client_id" value="<?= e(setting('zoom_client_id', '')) ?>" autocomplete="off"></div>
      <div class="field"><label for="zs">Client Secret</label><input type="password" id="zs" name="zoom_client_secret" value="<?= e(setting('zoom_client_secret', '')) ?>" autocomplete="off"></div>
    </div>
    <div class="field"><label for="zh">Email host</label>
      <input type="text" id="zh" name="zoom_host_email" value="<?= e(setting('zoom_host_email', 'me')) ?>">
      <p class="hint"><code>me</code> = pemilik aplikasi. Isi email lain bila rapat dijadwalkan atas nama akun Zoom lain di organisasi yang sama.</p></div>
    <button class="btn" type="submit">Simpan kredensial</button>
  </form>

  <?php if (zoomConfigured()): ?>
    <form method="post" style="margin-top:14px">
      <?= csrfField() ?><input type="hidden" name="act" value="zoom_test">
      <button class="btn ghost sm" type="submit">Uji koneksi Zoom</button>
    </form>
  <?php endif; ?>
</div>


<div class="card">
  <h2>Google Spreadsheet
    <?php if (setting('sheet_enabled') === '1'): ?>
      <span class="pill live dot" style="margin-left:8px">Aktif</span>
    <?php else: ?><span class="pill draft" style="margin-left:8px">Nonaktif</span><?php endif; ?>
  </h2>
  <p class="sub">Menyalin pipeline klien, jadwal, dan pembayaran ke satu spreadsheet. Berguna untuk berbagi rekap ke bendahara atau kru tanpa memberi mereka akses panel.</p>

  <div class="flash warn" style="margin-bottom:18px"><span>
    Arahnya satu jalur: panel → spreadsheet. Mengetik di spreadsheet tidak akan mengubah data di panel, dan isinya akan tertimpa saat sinkronisasi berikutnya. Perlakukan sebagai laporan, bukan tempat kerja.
  </span></div>

  <form method="post">
    <?= csrfField() ?><input type="hidden" name="act" value="sheet">
    <label class="check"><input type="checkbox" name="sheet_enabled" <?= setting('sheet_enabled') === '1' ? 'checked' : '' ?>>
      Nyalakan integrasi spreadsheet</label>
    <label class="check"><input type="checkbox" name="sheet_autosync" <?= setting('sheet_autosync') === '1' ? 'checked' : '' ?>>
      Perbarui otomatis setiap ada perubahan data klien atau pembayaran</label>
    <p class="hint" style="margin:2px 0 16px">Kalau dimatikan, spreadsheet hanya diperbarui saat tombol di bawah ditekan atau lewat cron harian. Untuk data yang sering berubah, sinkron otomatis membuat setiap penyimpanan sedikit lebih lambat.</p>

    <div class="field">
      <label for="sid">ID spreadsheet</label>
      <input type="text" id="sid" name="sheet_id" value="<?= e(setting('sheet_id', '')) ?>" placeholder="kosongkan untuk dibuatkan otomatis">
      <p class="hint">Boleh ditempel URL penuhnya, ID-nya diambil sendiri. Kosongkan lalu tekan "Buat spreadsheet baru" agar dibuatkan dengan susunan tab yang benar.</p>
    </div>
    <button class="btn" type="submit">Simpan pengaturan</button>
  </form>

  <?php if (setting('sheet_enabled') === '1'): ?>
    <hr class="hr">
    <?php if (!googleConnected()): ?>
      <div class="flash err"><span>Google belum terhubung. Hubungkan dulu di bagian Google Calendar di atas.</span></div>
    <?php elseif (!googleHasScope(G_SCOPE_SHEET)): ?>
      <div class="flash warn"><span>Izin Spreadsheet belum diberikan. Tekan <b>Hubungkan ulang</b> di bagian Google Calendar — Google akan meminta izin tambahan untuk Spreadsheet dan Drive.</span></div>
    <?php else: ?>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <form method="post" style="display:inline"><?= csrfField() ?>
          <input type="hidden" name="act" value="sheet_sync">
          <button class="btn solid" type="submit">Perbarui sekarang</button></form>
        <form method="post" style="display:inline" data-confirm="Buat spreadsheet BARU? Yang lama tidak dihapus, tapi tidak lagi diperbarui."><?= csrfField() ?>
          <input type="hidden" name="act" value="sheet_new">
          <button class="btn ghost" type="submit">Buat spreadsheet baru</button></form>
        <?php if ($su = sheetsUrl()): ?>
          <a class="btn ghost" href="<?= e($su) ?>" target="_blank" rel="noopener">Buka spreadsheet ↗</a>
        <?php endif; ?>
      </div>
      <?php if ($ls = setting('sheet_last_sync')): ?>
        <p class="hint" style="margin-top:12px">Terakhir diperbarui <?= tanggalID($ls, true) ?> WIB.</p>
      <?php endif; ?>
      <?php if ($le = setting('sheet_last_error')): ?>
        <div class="flash err" style="margin-top:12px"><span>Sinkronisasi terakhir gagal: <?= e($le) ?></span></div>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>

  <details style="margin-top:18px">
    <summary style="cursor:pointer;color:var(--ember);font-size:14px;padding:8px 0">Isi tab yang dibuat</summary>
    <ul style="font-size:13px;color:var(--ivory-60);line-height:1.9;margin:12px 0 0 20px">
      <li><b>Klien</b> — seluruh pipeline: tahap, tanggal nikah, nilai deal, sudah dibayar, sisa, tindakan berikutnya.</li>
      <li><b>Jadwal</b> — pertemuan beserta hasilnya dan tautan rapat.</li>
      <li><b>Pembayaran</b> — semua termin, mana yang lunas dan mana yang lewat tempo.</li>
      <li><b>Rekap</b> — angka ringkas: nilai terkunci, piutang, acara 90 hari ke depan.</li>
    </ul>
    <p class="hint" style="margin-top:12px">Izin yang diminta ke Google adalah <code>drive.file</code> — aplikasi hanya bisa menyentuh berkas yang ia buat sendiri, bukan seluruh isi Drive Anda.</p>
  </details>
</div>

<div class="card">
  <h2>Google Spreadsheet
    <?php if (setting('sheet_enabled') === '1' && sheetsEnabled()): ?>
      <span class="pill live dot" style="margin-left:8px">Aktif</span>
    <?php elseif (setting('sheet_enabled') === '1'): ?>
      <span class="pill warn" style="margin-left:8px">Perlu izin tambahan</span>
    <?php else: ?>
      <span class="pill draft" style="margin-left:8px">Nonaktif</span>
    <?php endif; ?>
  </h2>
  <p class="sub">Menyalin data klien, jadwal, pembayaran, dan checklist ke spreadsheet. Arahnya satu jalur: sistem menulis ke sheet, sheet tidak pernah mengubah database — supaya tidak ada bentrok penyuntingan yang sulit dilacak.</p>

  <?php if (setting('sheet_enabled') === '1' && googleConnected() && !googleHasScope(G_SCOPE_SHEET)): ?>
    <div class="flash warn"><span>Fitur sudah dinyalakan, tetapi Google belum memberi izin Spreadsheet. Gulir ke kartu Google Calendar di atas lalu tekan <b>Hubungkan ulang</b> dan setujui akses Spreadsheet.</span></div>
  <?php endif; ?>

  <form method="post">
    <?= csrfField() ?><input type="hidden" name="act" value="sheet">
    <label class="check"><input type="checkbox" name="sheet_enabled" <?= setting('sheet_enabled') === '1' ? 'checked' : '' ?>>
      Aktifkan ekspor ke Google Spreadsheet</label>
    <label class="check"><input type="checkbox" name="sheet_autosync" <?= setting('sheet_autosync') === '1' ? 'checked' : '' ?>>
      Sinkronkan otomatis setiap hari lewat cron</label>
    <div class="field" style="margin-top:14px">
      <label for="sid">ID spreadsheet</label>
      <input type="text" id="sid" name="sheet_id" value="<?= e(setting('sheet_id', '')) ?>" placeholder="kosongkan lalu tekan Buat spreadsheet baru">
      <p class="hint">Ambil dari URL: <code>docs.google.com/spreadsheets/d/<b>ID-INI</b>/edit</code>. Kalau memakai sheet yang sudah ada, pastikan dibuat oleh akun Google yang sama dengan yang terhubung di atas.</p>
    </div>
    <button class="btn" type="submit">Simpan pengaturan</button>
  </form>

  <?php if (setting('sheet_enabled') === '1' && googleConnected()): ?>
    <hr class="hr">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <?php if (!setting('sheet_id')): ?>
        <form method="post" style="display:inline"><?= csrfField() ?>
          <input type="hidden" name="act" value="sheet_create">
          <button class="btn solid" type="submit">Buat spreadsheet baru</button></form>
      <?php else: ?>
        <form method="post" style="display:inline"><?= csrfField() ?>
          <input type="hidden" name="act" value="sheet_sync">
          <button class="btn solid" type="submit">Sinkronkan sekarang</button></form>
        <a class="btn ghost" href="<?= e(sheetsUrl()) ?>" target="_blank" rel="noopener">Buka spreadsheet ↗</a>
      <?php endif; ?>
      <?php if ($ls = setting('sheet_last_sync')): ?>
        <span class="mono muted">Terakhir disinkronkan <?= tanggalID($ls, true) ?></span>
      <?php endif; ?>
    </div>
    <p class="hint" style="margin-top:12px">Tab yang dibuat: <b>Klien</b>, <b>Jadwal</b>, <b>Pembayaran</b>, <b>Checklist</b>, <b>Event</b>, <b>Ringkasan</b>. Setiap sinkronisasi menulis ulang seluruh isi tab, jadi data yang dihapus di panel ikut hilang dan tidak pernah ada baris ganda.</p>
  <?php endif; ?>
</div>

<div class="card" id="wa">
  <h2>WhatsApp dua arah
    <?php if (waSiap()): ?><span class="pill live dot" style="margin-left:8px">Aktif</span>
    <?php else: ?><span class="pill draft" style="margin-left:8px">Nonaktif</span><?php endif; ?>
  </h2>
  <p class="sub">Kalau aktif, pesan diketik di panel dan langsung sampai ke WhatsApp vendor. Balasan mereka masuk sendiri ke room pesta yang bersangkutan.</p>

  <div class="flash warn"><span>
    <b>Baca dulu sebelum memilih.</b> Jalur <b>Cloud API</b> resmi dari Meta — aman dari pemblokiran, tapi butuh
    verifikasi bisnis, nomor khusus yang tidak boleh dipakai di aplikasi WhatsApp biasa, templat pesan yang disetujui,
    dan berbiaya per percakapan. Ada aturan jendela 24 jam: di luar itu hanya templat yang boleh dikirim.
    Jalur <b>Gateway</b> (Fonnte, Wablas, sejenisnya) memindai QR dari nomor biasa — murah dan cepat, tapi
    <b>tidak resmi dan melanggar ketentuan WhatsApp</b>. Nomor bisa diblokir sewaktu-waktu tanpa banding.
    Kalau memilih ini, jangan pakai nomor utama bisnis.
  </span></div>

  <form method="post">
    <?= csrfField() ?><input type="hidden" name="act" value="wa">
    <div class="field">
      <label for="wp">Penyedia</label>
      <select id="wp" name="wa_provider" onchange="document.querySelectorAll('.wa-blok').forEach(x=>x.hidden=x.dataset.p!==this.value)">
        <option value="none"    <?= waPenyedia()==='none'    ? 'selected' : '' ?>>Nonaktif — kirim manual lewat wa.me</option>
        <option value="cloud"   <?= waPenyedia()==='cloud'   ? 'selected' : '' ?>>Meta WhatsApp Cloud API (resmi)</option>
        <option value="gateway" <?= waPenyedia()==='gateway' ? 'selected' : '' ?>>Gateway pihak ketiga (Fonnte / Wablas / sejenis)</option>
      </select>
    </div>

    <div class="wa-blok" data-p="cloud" <?= waPenyedia()==='cloud' ? '' : 'hidden' ?>>
      <div class="row c2">
        <div class="field"><label for="wpi">Phone Number ID</label><input type="text" id="wpi" name="wa_phone_id" value="<?= e(setting('wa_phone_id','')) ?>" placeholder="dari Meta -> WhatsApp -> API Setup"></div>
        <div class="field"><label for="wtk">Access Token</label><?= pwField('wtk','wa_token',['placeholder'=>setting('wa_token')?'•••••••• (tersimpan)':'']) ?></div>
      </div>
      <div class="row c2">
        <div class="field"><label for="wvt">Verify Token</label><?= pwField('wvt','wa_verify_token',['placeholder'=>setting('wa_verify_token')?'•••••••• (tersimpan)':'karangan bebas, disamakan di Meta']) ?></div>
        <div class="field"><label for="was">App Secret</label><?= pwField('was','wa_app_secret',['placeholder'=>setting('wa_app_secret')?'•••••••• (tersimpan)':'untuk memeriksa tanda tangan']) ?></div>
      </div>
      <ol style="font-size:13px;color:var(--ivory-60);line-height:1.85;margin:6px 0 0 20px">
        <li>developers.facebook.com -> buat app tipe <b>Business</b> -> tambah produk <b>WhatsApp</b>.</li>
        <li>Salin <b>Phone Number ID</b> dan token dari tab API Setup.</li>
        <li>Configuration -> Webhook -> Callback URL: <code><?= e(url('wa-webhook.php')) ?></code>, isi Verify Token yang sama dengan di atas.</li>
        <li>Langganan field <b>messages</b>.</li>
      </ol>
    </div>

    <div class="wa-blok" data-p="gateway" <?= waPenyedia()==='gateway' ? '' : 'hidden' ?>>
      <p class="hint" style="margin:0 0 12px">Isi cepat:
        <button class="btn sm ghost" type="button" onclick="isiGw('https://api.fonnte.com/send','target','message','header')">Fonnte</button>
        <button class="btn sm ghost" type="button" onclick="isiGw('https://console.wablas.com/api/send-message','phone','message','header')">Wablas</button>
      </p>
      <div class="row c2">
        <div class="field"><label for="wgu">URL kirim</label><input type="url" id="wgu" name="wa_gateway_url" value="<?= e(setting('wa_gateway_url','')) ?>" placeholder="https://api.fonnte.com/send"></div>
        <div class="field"><label for="wgt">Token</label><?= pwField('wgt','wa_gateway_token',['placeholder'=>setting('wa_gateway_token')?'•••••••• (tersimpan)':'']) ?></div>
      </div>
      <div class="row c3">
        <div class="field"><label for="wft">Nama field tujuan</label><input type="text" id="wft" name="wa_gateway_ftarget" value="<?= e(setting('wa_gateway_ftarget','target')) ?>"><p class="hint">Fonnte: <code>target</code> · Wablas: <code>phone</code></p></div>
        <div class="field"><label for="wfb">Nama field isi</label><input type="text" id="wfb" name="wa_gateway_fbody" value="<?= e(setting('wa_gateway_fbody','message')) ?>"></div>
        <div class="field"><label for="wga">Cara kirim token</label>
          <select id="wga" name="wa_gateway_auth">
            <option value="header" <?= setting('wa_gateway_auth','header')==='header' ? 'selected' : '' ?>>Header Authorization</option>
            <option value="bearer" <?= setting('wa_gateway_auth')==='bearer' ? 'selected' : '' ?>>Header Bearer</option>
            <option value="body"   <?= setting('wa_gateway_auth')==='body'   ? 'selected' : '' ?>>Ikut di body</option>
          </select></div>
      </div>
      <p class="hint">Di panel gateway, isi webhook balasan ke:</p>
      <div class="copyrow"><code><?= e(url('wa-webhook.php?token=')) ?><span class="muted">TOKEN-ANDA</span></code></div>
    </div>

    <button class="btn" type="submit" style="margin-top:14px">Simpan pengaturan WhatsApp</button>
  </form>

  <?php if (waSiap()): ?>
    <hr class="hr">
    <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      <?= csrfField() ?><input type="hidden" name="act" value="wa_test">
      <div class="field" style="flex:1;min-width:210px;margin:0">
        <label for="wtn">Kirim pesan uji ke</label>
        <input type="text" id="wtn" name="wa_test_no" placeholder="08123456789">
      </div>
      <button class="btn ghost" type="submit">Kirim uji</button>
      <?php $bl = waBelumDibaca(); if ($bl): ?>
        <a class="btn ghost" href="inbox.php"><?= $bl ?> pesan belum dicocokkan →</a>
      <?php endif; ?>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Pengiriman email</h2>
  <p class="sub">SMTP hampir selalu lebih baik daripada fungsi mail() bawaan: ada autentikasi, Return-Path benar, sehingga SPF dan DKIM cocok dan email tidak dianggap spam.</p>
  <form method="post">
    <?= csrfField() ?><input type="hidden" name="act" value="mail">
    <div class="row c2">
      <div class="field"><label for="mm">Metode</label>
        <select id="mm" name="mail_method">
          <option value="mail" <?= setting('mail_method', 'mail') === 'mail' ? 'selected' : '' ?>>mail() bawaan PHP</option>
          <option value="smtp" <?= setting('mail_method') === 'smtp' ? 'selected' : '' ?>>SMTP autentikasi</option>
        </select></div>
      <div class="field"><label for="mfn">Nama pengirim</label><input type="text" id="mfn" name="mail_from_name" value="<?= e(setting('mail_from_name', 'Callalily Party')) ?>"></div>
    </div>
    <div class="field"><label for="mfe">Email pengirim</label>
      <input type="email" id="mfe" name="mail_from_email" value="<?= e(setting('mail_from_email', '')) ?>" placeholder="halo@callalilyparty.com">
      <p class="hint">Pakai email di domain sendiri, bukan Gmail/Yahoo — kalau tidak, SPF domain pengirim akan gagal.</p></div>
    <div class="row c3">
      <div class="field"><label for="sh">SMTP host</label><input type="text" id="sh" name="smtp_host" value="<?= e(setting('smtp_host', '')) ?>" placeholder="mail.callalilyparty.com"></div>
      <div class="field"><label for="sp">Port</label><input type="number" id="sp" name="smtp_port" value="<?= e(setting('smtp_port', '587')) ?>"></div>
      <div class="field"><label for="ss">Enkripsi</label>
        <select id="ss" name="smtp_secure">
          <option value="tls" <?= setting('smtp_secure', 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
          <option value="ssl" <?= setting('smtp_secure') === 'ssl' ? 'selected' : '' ?>>SSL/TLS (465)</option>
          <option value="none" <?= setting('smtp_secure') === 'none' ? 'selected' : '' ?>>Tanpa enkripsi</option>
        </select></div>
    </div>
    <div class="row c2">
      <div class="field"><label for="su">SMTP user</label><input type="text" id="su" name="smtp_user" value="<?= e(setting('smtp_user', '')) ?>" autocomplete="off"></div>
      <div class="field"><label for="spw">SMTP password</label><?= pwField('spw', 'smtp_pass', ['placeholder' => setting('smtp_pass') ? '•••••••• (tersimpan)' : '', 'autocomplete' => 'new-password']) ?>
        <p class="hint">Kosongkan bila tidak ingin mengubah.</p></div>
    </div>
    <button class="btn" type="submit">Simpan pengaturan email</button>
  </form>

  <hr class="hr">
  <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <?= csrfField() ?><input type="hidden" name="act" value="mail_test">
    <div class="field" style="flex:1;min-width:240px;margin:0">
      <label for="te">Kirim email uji ke</label>
      <input type="email" id="te" name="test_email" value="<?= e($user['email']) ?>">
    </div>
    <button class="btn ghost" type="submit">Kirim uji</button>
  </form>
</div>

<script>
function isiGw(url, ft, fb, auth) {
  document.getElementById('wgu').value = url;
  document.getElementById('wft').value = ft;
  document.getElementById('wfb').value = fb;
  document.getElementById('wga').value = auth;
  document.getElementById('wgu').focus();
}
</script>
<?= pwScript() ?>
<?php adminFoot();
