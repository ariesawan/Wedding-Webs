<?php
require_once __DIR__ . '/_layout.php';

if (currentUser()) redirect('admin/index.php');

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $ip = clientIp();
    if (isLocked($ip)) {
        $err = 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . lockRemaining($ip) . ' menit.';
    } else {
        $email = trim($_POST['email'] ?? '');
        if (attemptLogin($email, $_POST['password'] ?? '')) {
            logAudit(currentUser()['id'] ?? null, 'login', '', '');
            $to = $_SESSION['after_login'] ?? 'admin/index.php';
            unset($_SESSION['after_login']);
            redirect(str_starts_with($to, '/') ? ltrim($to, '/') : $to);
        }
        $err = 'Email atau kata sandi tidak cocok.';
    }
}

authHead('Masuk');
?>
<h1>Masuk untuk mengelola situs</h1>

<?php if ($err): ?><div class="flash err"><span><?= e($err) ?></span></div><?php endif; ?>
<?php if (isset($_GET['timeout'])): ?><div class="flash warn"><span>Sesi berakhir karena tidak ada aktivitas. Masuk lagi untuk melanjutkan.</span></div><?php endif; ?>
<?php if ($f = flash()): ?><div class="flash <?= $f['type'] === 'err' ? 'err' : 'ok' ?>"><span><?= e($f['msg']) ?></span></div><?php endif; ?>

<form method="post" autocomplete="on">
  <?= csrfField() ?>
  <div class="field">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" required autofocus autocomplete="username"
           value="<?= e($_POST['email'] ?? '') ?>">
  </div>
  <div class="field" style="margin-bottom:8px">
    <label for="password">Kata sandi</label>
    <?= pwField('password', 'password', ['required' => 1, 'autocomplete' => 'current-password']) ?>
  </div>
  <p style="text-align:right;margin-bottom:18px">
    <a href="lupa-password.php" style="font-size:12.5px;color:var(--ivory-60)">Lupa kata sandi?</a>
  </p>
  <button class="btn solid" type="submit" style="width:100%">Masuk</button>
</form>
<p style="text-align:center;margin-top:20px;font-size:12px;color:var(--ivory-38)">
  Setelah <?= LOGIN_MAX_TRY ?> percobaan gagal, alamat IP dikunci <?= LOGIN_LOCK_MINUTES ?> menit.
</p>
<?php authFoot();
