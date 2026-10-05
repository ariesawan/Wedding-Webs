<?php
/** Menyetel kata sandi baru dari tautan reset. */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$err = ''; $done = false; $row = null;

if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $row = one("SELECT r.*, u.name, u.email FROM password_resets r
                JOIN users u ON u.id = r.user_id
                WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()",
               [hash('sha256', $token)]);
}

if (!$row) {
    $err = 'Tautan tidak berlaku atau sudah kedaluwarsa. Minta tautan baru.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $new = $_POST['password'] ?? '';
    $rep = $_POST['password2'] ?? '';
    if (strlen($new) < 12)  $err = 'Kata sandi minimal 12 karakter.';
    elseif ($new !== $rep)  $err = 'Konfirmasi kata sandi tidak sama.';
    else {
        q("UPDATE users SET password_hash = ? WHERE id = ?",
          [password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), $row['user_id']]);
        q("UPDATE password_resets SET used_at = NOW() WHERE id = ?", [$row['id']]);
        // Bersihkan kunci login yang mungkin masih aktif untuk IP ini.
        q("DELETE FROM login_attempts WHERE ip = ?", [clientIp()]);
        q("INSERT INTO audit_log (user_id, action, target, detail, ip)
           VALUES (?, 'reset_password', 'users', 'Kata sandi diatur ulang lewat tautan email', ?)",
          [$row['user_id'], clientIp()]);
        $done = true;
    }
}
?><!DOCTYPE html>
<html lang="id"<?= themeAttr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Atur kata sandi · Panel Callalily</title>
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

    <?php if ($done): ?>
      <h1>Kata sandi diperbarui</h1>
      <div class="flash ok"><span>Silakan masuk dengan kata sandi baru.</span></div>
      <a class="btn solid" href="login.php" style="width:100%">Masuk sekarang</a>

    <?php elseif (!$row): ?>
      <h1>Tautan tidak berlaku</h1>
      <div class="flash err"><span><?= e($err) ?></span></div>
      <a class="btn solid" href="lupa-password.php" style="width:100%">Minta tautan baru</a>

    <?php else: ?>
      <h1>Kata sandi baru untuk <?= e($row['email']) ?></h1>
      <?php if ($err): ?><div class="flash err"><span><?= e($err) ?></span></div><?php endif; ?>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field">
          <label for="p">Kata sandi baru</label>
          <?= pwField('p', 'password', ['required' => 1, 'minlength' => 12, 'autofocus' => 1, 'autocomplete' => 'new-password', 'strength' => true]) ?>
          <p class="hint">Minimal 12 karakter. Frasa panjang lebih kuat sekaligus lebih mudah diingat daripada kata pendek yang rumit.</p>
        </div>
        <div class="field">
          <label for="p2">Ulangi</label>
          <?= pwField('p2', 'password2', ['required' => 1, 'autocomplete' => 'new-password']) ?>
        </div>
        <button class="btn solid" type="submit" style="width:100%">Simpan kata sandi</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?= pwScript() ?>
</body>
</html>
