<?php
/**
 * Pembuatan akun owner pertama. Otomatis nonaktif setelah ada 1 user —
 * jadi tidak bisa dipakai orang lain untuk membuat akun admin baru.
 * Setelah instalasi selesai, hapus berkas ini.
 */
require_once __DIR__ . '/_layout.php';

$count = (int) (one("SELECT COUNT(*) c FROM users")['c'] ?? 0);
if ($count > 0) {
    http_response_code(403);
    die('Akun admin sudah ada. Hapus berkas admin/setup.php dari server sekarang juga.');
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Nama dan email wajib diisi dengan benar.';
    elseif (strlen($pass) < 12)   $err = 'Kata sandi minimal 12 karakter.';
    elseif ($pass !== $pass2)     $err = 'Konfirmasi kata sandi tidak sama.';
    else {
        q("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'owner')",
          [$name, $email, password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12])]);
        flash('Akun owner dibuat. Hapus berkas admin/setup.php dari server, lalu masuk.');
        redirect('admin/login.php');
    }
}

authHead('Pemasangan', 'PEMASANGAN');
?>
<h1>Buat akun owner pertama</h1>
<?php if ($err): ?><div class="flash err"><span><?= e($err) ?></span></div><?php endif; ?>
<form method="post">
  <?= csrfField() ?>
  <div class="field"><label for="n">Nama</label><input type="text" id="n" name="name" required value="<?= e($_POST['name'] ?? '') ?>"></div>
  <div class="field"><label for="e">Email</label><input type="email" id="e" name="email" required value="<?= e($_POST['email'] ?? '') ?>">
    <p class="hint">Dipakai untuk masuk dan untuk memulihkan kata sandi.</p></div>
  <div class="field"><label for="p">Kata sandi</label><input type="password" id="p" name="password" required minlength="12" data-strength>
    <p class="hint">Minimal 12 karakter. Frasa panjang lebih kuat daripada kata pendek yang rumit.</p></div>
  <div class="field"><label for="p2">Ulangi kata sandi</label><?= pwField('p2', 'password2', ['required'=>1,'autocomplete'=>'new-password']) ?></div>
  <button class="btn solid" type="submit" style="width:100%">Buat akun</button>
</form>
<?php authFoot();
