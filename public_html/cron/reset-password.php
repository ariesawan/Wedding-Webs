<?php
/**
 * Reset kata sandi lewat SSH — jalur darurat ketika SMTP belum jalan
 * atau email owner tidak bisa diakses.
 *
 *   php cron/reset-password.php                    → daftar akun
 *   php cron/reset-password.php email@anda.com     → buat kata sandi acak
 *   php cron/reset-password.php email@anda.com 'FrasaSandiPanjang'
 *   php cron/reset-password.php --link email@anda.com  → cetak tautan reset
 *
 * Hanya bisa dijalankan dari baris perintah. Kalau dipanggil lewat browser,
 * langsung ditolak — ini penting, karena skrip ini bisa mengambil alih akun.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya bisa dijalankan lewat SSH / terminal.\n");
}

require_once dirname(__DIR__) . '/inc/bootstrap.php';

$args = array_slice($argv, 1);
$mode = 'set';
if (($k = array_search('--link', $args, true)) !== false) { $mode = 'link'; array_splice($args, $k, 1); }

$email = $args[0] ?? null;
$pass  = $args[1] ?? null;

// ---------- Tanpa argumen: tampilkan daftar akun ----------
if (!$email) {
    $rows = all("SELECT id, name, email, role, is_active, last_login_at FROM users ORDER BY id");
    if (!$rows) {
        echo "Belum ada akun sama sekali. Buka /admin/setup.php di peramban untuk membuat akun owner.\n";
        exit(0);
    }
    echo "\nAkun yang terdaftar:\n";
    echo str_repeat('-', 74) . "\n";
    printf("%-4s %-22s %-30s %-8s %s\n", 'ID', 'NAMA', 'EMAIL', 'PERAN', 'AKTIF');
    echo str_repeat('-', 74) . "\n";
    foreach ($rows as $r) {
        printf("%-4s %-22s %-30s %-8s %s\n", $r['id'],
               mb_strimwidth($r['name'], 0, 21, '…'), $r['email'], $r['role'],
               $r['is_active'] ? 'ya' : 'tidak');
    }
    echo str_repeat('-', 74) . "\n";
    echo "\nAtur ulang:  php cron/reset-password.php EMAIL [KATA_SANDI_BARU]\n";
    echo "Buat tautan: php cron/reset-password.php --link EMAIL\n\n";
    exit(0);
}

$u = one("SELECT id, name, email FROM users WHERE email = ?", [$email]);
if (!$u) {
    fwrite(STDERR, "Akun dengan email '$email' tidak ditemukan.\n");
    fwrite(STDERR, "Jalankan tanpa argumen untuk melihat daftar akun.\n");
    exit(1);
}

// ---------- Mode tautan ----------
if ($mode === 'link') {
    q("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL", [$u['id']]);
    $token = bin2hex(random_bytes(32));
    q("INSERT INTO password_resets (user_id, token_hash, expires_at, ip)
       VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE), 'cli')",
      [$u['id'], hash('sha256', $token)]);

    echo "\nTautan pengaturan ulang untuk {$u['email']} (berlaku 60 menit, sekali pakai):\n\n";
    echo '  ' . url('admin/reset-password.php?token=' . $token) . "\n\n";
    exit(0);
}

// ---------- Mode setel langsung ----------
$acak = false;
if ($pass === null) {
    // Frasa acak yang masih bisa diketik ulang tanpa salah baca.
    $kata = ['langit','embun','kirab','pelaminan','janur','melati','rembulan','sanggar',
             'gapura','tenda','rundown','gladi','seserahan','panggih','beskap','kebaya'];
    $pass = $kata[random_int(0, count($kata) - 1)] . '-'
          . $kata[random_int(0, count($kata) - 1)] . '-'
          . random_int(1000, 9999);
    $acak = true;
}

if (strlen($pass) < 12) {
    fwrite(STDERR, "Kata sandi minimal 12 karakter (yang diberikan: " . strlen($pass) . ").\n");
    exit(1);
}

q("UPDATE users SET password_hash = ?, is_active = 1 WHERE id = ?",
  [password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $u['id']]);
q("DELETE FROM login_attempts");   // buka kunci semua IP, siapa tahu owner terkunci
q("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL", [$u['id']]);
q("INSERT INTO audit_log (user_id, action, target, detail, ip)
   VALUES (?, 'reset_password', 'users', 'Diatur ulang lewat CLI', 'cli')", [$u['id']]);

echo "\n✓ Kata sandi untuk {$u['email']} berhasil diperbarui.\n";
if ($acak) {
    echo "\n  Kata sandi baru:  $pass\n";
    echo "\n  Segera ganti setelah masuk lewat Pengaturan → Ganti kata sandi.\n";
}
echo "\n  Masuk di: " . url('admin/login.php') . "\n\n";
echo "  Catatan: kunci login (throttle) untuk semua IP ikut dibersihkan.\n\n";
