<?php
/**
 * Permintaan tautan reset kata sandi.
 *
 * Dua hal penting yang dijaga di sini:
 *  1. Jawaban SELALU sama, terdaftar atau tidak. Kalau berbeda, halaman ini
 *     jadi alat memeriksa email mana yang punya akun (user enumeration).
 *  2. Token asli tidak pernah disimpan — yang masuk database hanya SHA-256-nya.
 *     Kalau database bocor, token di dalamnya tidak bisa dipakai.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/mailer.php';

if (currentUser()) redirect('admin/index.php');

$sent = false; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $email = trim($_POST['email'] ?? '');
    $ip    = clientIp();

    // Batasi 5 permintaan per IP per jam supaya tidak dipakai membanjiri inbox orang.
    $recent = (int) (one("SELECT COUNT(*) c FROM password_resets
                          WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)", [$ip])['c'] ?? 0);
    if ($recent >= 5) {
        $err = 'Terlalu banyak permintaan dari koneksi ini. Coba lagi satu jam lagi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Format email tidak valid.';
    } else {
        $u = one("SELECT id, name, email FROM users WHERE email = ? AND is_active = 1", [$email]);
        if ($u) {
            // Token lama yang belum dipakai dibatalkan, supaya hanya satu yang berlaku.
            q("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL", [$u['id']]);

            $token = bin2hex(random_bytes(32));
            q("INSERT INTO password_resets (user_id, token_hash, expires_at, ip)
               VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE), ?)",
              [$u['id'], hash('sha256', $token), $ip]);

            $link = url('admin/reset-password.php?token=' . $token);
            $html = '<!DOCTYPE html><html lang="id"<?= themeAttr() ?>><body style="margin:0;background:#17181A;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#F1EAD9">
<div style="max-width:520px;margin:0 auto;padding:38px 26px">
  <p style="font-family:Georgia,serif;font-style:italic;font-size:22px;margin:0 0 4px">' . e(setting('site_name', 'Callalily Party')) . '</p>
  <p style="font-size:10px;letter-spacing:.18em;text-transform:uppercase;color:#E9A85C;margin:0 0 30px">PANEL PRODUKSI</p>
  <h1 style="font-family:Georgia,serif;font-weight:400;font-size:24px;line-height:1.3;margin:0 0 14px">Atur ulang kata sandi</h1>
  <p style="color:#b9b3a4;line-height:1.7;margin:0 0 26px">Halo ' . e($u['name']) . ', ada permintaan mengatur ulang kata sandi panel. Tautan di bawah berlaku <b>60 menit</b> dan hanya bisa dipakai sekali.</p>
  <p style="margin:0 0 26px"><a href="' . e($link) . '" style="background:#E9A85C;color:#17181A;padding:14px 28px;border-radius:999px;text-decoration:none;font-weight:600;display:inline-block">Atur kata sandi baru</a></p>
  <p style="color:#8b8f9e;font-size:12.5px;line-height:1.7">Kalau tombol tidak bisa ditekan, salin alamat ini:<br>
    <span style="color:#E9A85C;word-break:break-all">' . e($link) . '</span></p>
  <p style="color:#8b8f9e;font-size:12.5px;line-height:1.7;margin-top:24px">Kalau bukan Anda yang meminta, abaikan saja email ini — kata sandi lama tetap berlaku. Permintaan ini berasal dari alamat IP ' . e($ip) . '.</p>
</div></body></html>';

            @sendMail($u['email'], $u['name'], 'Atur ulang kata sandi panel ' . setting('site_name', 'Callalily'), $html);
        }
        // Jawaban sama, ada atau tidak ada akunnya.
        $sent = true;
    }
}
?><!DOCTYPE html>
<html lang="id"<?= themeAttr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Lupa kata sandi · Panel Callalily</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..700;1,6..96,400..700&family=Hanken+Grotesk:wght@300..700&family=IBM+Plex+Mono:wght@400;500;600&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..700;1,6..96,400..700&family=Hanken+Grotesk:wght@300..700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" media="print" onload="this.media='all';this.onload=null">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..700;1,6..96,400..700&family=Hanken+Grotesk:wght@300..700&family=IBM+Plex+Mono:wght@400;500;600&display=swap"></noscript>
<link rel="stylesheet" href="assets/admin.css?v=<?= assetVer('admin/assets/admin.css') ?>">
</head>
<body>
<div class="auth">
  <div class="auth-box">
    <div class="brand">Callalily<small>PANEL PRODUKSI</small></div>

    <?php if ($sent): ?>
      <h1>Periksa kotak masuk</h1>
      <div class="flash ok"><span>Kalau email itu terdaftar, tautan pengaturan ulang sudah dikirim. Berlaku 60 menit. Cek juga folder spam.</span></div>
      <p style="font-size:13px;color:var(--ivory-60);line-height:1.7">
        Email tidak sampai? Kemungkinan besar SMTP belum diatur di panel. Pemilik server bisa mengatur ulang kata sandi lewat SSH:
      </p>
      <div class="copyrow"><code>php cron/reset-password.php email@anda.com</code></div>
      <a class="btn ghost" href="login.php" style="width:100%;margin-top:20px">Kembali ke halaman masuk</a>

    <?php else: ?>
      <h1>Kirim tautan pengaturan ulang</h1>
      <?php if ($err): ?><div class="flash err"><span><?= e($err) ?></span></div><?php endif; ?>
      <form method="post">
        <?= csrfField() ?>
        <div class="field">
          <label for="email">Email akun</label>
          <input type="email" id="email" name="email" required autofocus autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>">
        </div>
        <button class="btn solid" type="submit" style="width:100%">Kirim tautan</button>
      </form>
      <a class="btn ghost" href="login.php" style="width:100%;margin-top:11px">Kembali</a>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
