<?php
/**
 * Pemeriksa pemasangan — jalankan saat panel menolak terbuka.
 *
 * Berkas ini SENGAJA tidak memakai bootstrap penuh, supaya tetap bisa
 * menampilkan pesan meski database bermasalah. Sebagian besar penyebab
 * error 500 tidak kelihatan di layar karena display_errors dimatikan di
 * produksi; di sinilah penyebabnya ditampilkan terang-terangan.
 *
 * HAPUS berkas ini setelah masalah beres:  rm cek.php
 */

header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');

$hasil = [];
$catat = function (string $status, string $judul, string $pesan, string $saran = '') use (&$hasil) {
    $hasil[] = compact('status', 'judul', 'pesan', 'saran');
};

// ---------- 1. Versi PHP ----------
if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
    $catat('ok', 'Versi PHP', PHP_VERSION);
} else {
    $catat('bad', 'Versi PHP terlalu lama', PHP_VERSION . ' — butuh 8.1 ke atas',
           'cPanel → Select PHP Version → pilih 8.1 atau lebih baru.');
}

// ---------- 2. Ekstensi ----------
$perlu = ['pdo_mysql' => 'koneksi database', 'gd' => 'thumbnail & WebP', 'curl' => 'API Google/Zoom',
          'openssl' => 'enkripsi kredensial', 'mbstring' => 'teks Indonesia', 'fileinfo' => 'validasi upload'];
$kurang = [];
foreach ($perlu as $e => $g) if (!extension_loaded($e)) $kurang[] = "$e ($g)";
$kurang
    ? $catat('bad', 'Ekstensi PHP belum aktif', implode(', ', $kurang),
             'cPanel → Select PHP Version → Extensions, centang yang kurang.')
    : $catat('ok', 'Ekstensi wajib', 'lengkap');
if (!extension_loaded('exif')) {
    $catat('warn', 'Ekstensi exif belum aktif', 'Foto dari HP bisa tersimpan miring.',
           'Aktifkan exif di Select PHP Version.');
}

// ---------- 3. config.php ----------
if (!is_file(__DIR__ . '/inc/config.php')) {
    $catat('bad', 'inc/config.php tidak ada', 'Berkas konfigurasi belum dibuat.',
           'Salin inc/config.sample.php menjadi inc/config.php lalu isi kredensialnya.');
    tampilkan($hasil); exit;
}
require_once __DIR__ . '/inc/config.php';
$catat('ok', 'inc/config.php terbaca', 'BASE_URL = ' . BASE_URL);

// ---------- 4. BASE_URL vs domain yang dibuka ----------
$hostNyata = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
$hostConf  = parse_url(BASE_URL, PHP_URL_HOST) ?? '';
if ($hostNyata && $hostConf && strcasecmp($hostNyata, $hostConf) !== 0) {
    $catat('bad', 'BASE_URL tidak cocok dengan domain',
           "Membuka <b>$hostNyata</b> tetapi config berisi <b>$hostConf</b>",
           "Perbaiki define('BASE_URL', 'https://$hostNyata'); di inc/config.php");
} else {
    $catat('ok', 'BASE_URL cocok dengan domain', $hostConf);
}

// ---------- 5. APP_KEY ----------
(defined('APP_KEY') && strlen(APP_KEY) >= 32 && !str_contains(APP_KEY, 'ganti-dengan'))
    ? $catat('ok', 'APP_KEY terpasang', strlen(APP_KEY) . ' karakter')
    : $catat('bad', 'APP_KEY belum diisi', 'Masih nilai contoh atau terlalu pendek.',
             'Buat dengan: openssl rand -hex 32');

// ---------- 6. Berkas inti ----------
$wajib = ['inc/db.php','inc/helpers.php','inc/bootstrap.php','inc/auth.php','inc/settings.php',
          'inc/seo.php','inc/image.php','inc/http.php','inc/google.php','inc/zoom.php','inc/ics.php',
          'inc/mailer.php','inc/meeting.php','inc/pipeline.php','inc/sheets.php','inc/reminder.php',
          'inc/diagnostics.php','admin/_layout.php','admin/klien.php','admin/assets/admin.css',
          'admin/assets/admin.js','partials/public-head.php'];
$hilang = array_values(array_filter($wajib, fn($f) => !is_file(__DIR__ . '/' . $f)));
$hilang
    ? $catat('bad', 'Berkas inti belum tersalin', implode(', ', $hilang),
             'Salin ulang seluruh isi paket, jangan sebagian. Berkas inc/config.php tidak ikut ditimpa.')
    : $catat('ok', 'Berkas inti lengkap', count($wajib) . ' berkas diperiksa');

// ---------- 7. Database ----------
try {
    $pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
                   DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $catat('ok', 'Koneksi database', DB_NAME . ' di ' . DB_HOST);

    $ada = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $v1 = ['users','login_attempts','settings','gallery','events','categories','posts','meetings','audit_log'];
    $v2 = ['clients','client_activities','client_tasks','payments','password_resets','sheet_links','client_segments','vendors','client_vendors','vendor_messages','site_moments','site_services','site_presets','wa_inbox','wa_log'];

    $kurangV1 = array_values(array_diff($v1, $ada));
    $kurangV2 = array_values(array_diff($v2, $ada));

    if ($kurangV1) {
        $catat('bad', 'Tabel dasar belum ada', implode(', ', $kurangV1),
               'Impor db/schema.sql lewat phpMyAdmin, atau: mysql -u USER -p NAMA_DB < db/schema.sql');
    } elseif ($kurangV2) {
        $catat('bad', 'MIGRASI BELUM DIJALANKAN — ini penyebab error 500 di panel',
               'Tabel yang belum ada: ' . implode(', ', $kurangV2) .
               '. Situs publik tetap jalan karena tidak memakai tabel ini, tetapi seluruh halaman admin memerlukannya.',
               'Impor db/migration-v2.sql lewat phpMyAdmin, atau: mysql -u USER -p NAMA_DB < db/migration-v2.sql');
    } else {
        $catat('ok', 'Struktur database lengkap', count($ada) . ' tabel');
    }

    // kolom tambahan di meetings
    if (in_array('meetings', $ada, true)) {
        $kol = $pdo->query("SHOW COLUMNS FROM meetings")->fetchAll(PDO::FETCH_COLUMN);
        $kurangKol = array_values(array_diff(['client_id','outcome','outcome_note','minutes','zoom_recording_url'], $kol));
        $kurangKol
            ? $catat('bad', 'Kolom baru di tabel meetings belum ada', implode(', ', $kurangKol),
                     'Jalankan db/migration-v2.sql, lalu v3 sampai v7 berurutan.')
            : $catat('ok', 'Kolom tabel meetings lengkap', 'client_id, outcome, outcome_note');
    }

    if (in_array('users', $ada, true)) {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $n > 0
            ? $catat('ok', 'Akun admin', "$n akun terdaftar")
            : $catat('warn', 'Belum ada akun admin', 'Buka /admin/setup.php untuk membuat akun owner.');
    }
} catch (PDOException $e) {
    $catat('bad', 'Database gagal diakses', $e->getMessage(),
           'Periksa DB_NAME, DB_USER, DB_PASS di inc/config.php. Di cPanel, nama database dan user berprefix nama akun.');
}

// ---------- 8. Direktori tulis ----------
$dirs = ['foto', 'video', 'uploads/blog', 'uploads/event'];
$gagal = [];
foreach ($dirs as $d) {
    $p = __DIR__ . '/' . $d;
    if (!is_dir($p)) @mkdir($p, 0755, true);
    if (!is_dir($p) || !is_writable($p)) $gagal[] = $d;
}
$gagal
    ? $catat('bad', 'Direktori tidak bisa ditulis', implode(', ', $gagal), 'chmod 755 pada folder tersebut.')
    : $catat('ok', 'Direktori upload', 'semua bisa ditulis');

// ---------- 9. Sisa berkas pemasangan ----------
if (is_file(__DIR__ . '/admin/setup.php') && isset($pdo)) {
    try {
        if ((int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0) {
            $catat('warn', 'admin/setup.php masih ada', 'Sudah tidak diperlukan.', 'rm admin/setup.php');
        }
    } catch (Throwable $e) {}
}

tampilkan($hasil);

function tampilkan(array $hasil): void
{
    $bad  = count(array_filter($hasil, fn($x) => $x['status'] === 'bad'));
    $warn = count(array_filter($hasil, fn($x) => $x['status'] === 'warn'));
    $esc  = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    ?><!DOCTYPE html><html lang="id"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Pemeriksa pemasangan</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#17181A;color:#F1EAD9;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  line-height:1.65;padding:32px 18px}
.w{max-width:760px;margin:0 auto}
h1{font-size:23px;font-weight:500;margin-bottom:6px}
.sum{font-size:13.5px;color:rgba(241,234,217,.6);margin-bottom:26px}
.it{background:#1F2023;border:1px solid rgba(241,234,217,.12);border-left-width:3px;
  border-radius:10px;padding:14px 16px;margin-bottom:9px}
.it.ok{border-left-color:#7FB69A}.it.warn{border-left-color:#E9A85C}.it.bad{border-left-color:#D97B7B}
.it b{display:block;font-weight:600;font-size:14.5px;margin-bottom:3px}
.it.bad b{color:#EFC0C0}.it.warn b{color:#F0CF9E}.it.ok b{color:#B7DDCB}
.it p{font-size:13px;color:rgba(241,234,217,.66);word-break:break-word}
.it .fix{margin-top:9px;padding:9px 11px;background:#17181A;border-radius:7px;
  font-family:ui-monospace,monospace;font-size:12.5px;color:#E9A85C;word-break:break-all}
.note{margin-top:26px;padding:15px 17px;border:1px solid rgba(233,168,92,.35);border-radius:10px;font-size:13px;
  color:rgba(241,234,217,.72)}
.note code{color:#E9A85C;font-family:ui-monospace,monospace}
</style></head><body><div class="w">
<h1>Pemeriksa pemasangan</h1>
<p class="sum"><?= $bad ?> masalah · <?= $warn ?> peringatan · <?= count($hasil) - $bad - $warn ?> beres</p>
<?php foreach ($hasil as $x): ?>
  <div class="it <?= $x['status'] ?>">
    <b><?= $esc($x['judul']) ?></b>
    <p><?= $x['pesan'] ?></p>
    <?php if ($x['saran']): ?><div class="fix"><?= $esc($x['saran']) ?></div><?php endif; ?>
  </div>
<?php endforeach; ?>
<div class="note">
  Masih 500 padahal semua di atas hijau? Buka <code>inc/config.php</code>, ubah sementara
  <code>APP_ENV</code> menjadi <code>'development'</code> — pesan error aslinya akan muncul di layar.
  Kembalikan ke <code>'production'</code> setelah selesai.<br><br>
  <b>Hapus berkas ini setelah beres:</b> <code>rm cek.php</code>
</div>
</div></body></html><?php
}
