<?php
/**
 * config.php — satu-satunya file yang perlu diedit saat instalasi.
 * Salin file ini jadi inc/config.php lalu isi sesuai hosting Anda.
 */

// ---- Database (cPanel: nama user & db berprefix akun) ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'callalily_cms');
define('DB_USER', 'callalily_cms');
define('DB_PASS', 'ganti-password-kuat');
define('DB_CHARSET', 'utf8mb4');

// ---- Situs ----
// Isi domain yang dipakai. TANPA slash di akhir.
//
// Kalau situs diakses lewat lebih dari satu domain (misalnya subdomain uji
// sebelum pindah ke domain final), pakai bentuk daftar putih di bawah ini
// dan hapus baris define('BASE_URL', ...) yang tunggal.
// Daftar putih penting: mempercayai HTTP_HOST mentah membuka Host header
// injection — penyerang bisa membelokkan tautan di email undangan ke domainnya.
//
//   $callaHosts  = ['wo.contoh.com', 'callalily.party'];
//   $callaHost   = $_SERVER['HTTP_HOST'] ?? '';
//   if (!in_array($callaHost, $callaHosts, true)) $callaHost = $callaHosts[0];
//   $callaScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
//               || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
//   define('BASE_URL', $callaScheme . '://' . $callaHost);
//
// Catatan: setiap domain yang dipakai harus juga didaftarkan sebagai
// Authorized redirect URI di Google Cloud Console.
define('BASE_URL', 'https://callalily.party');
define('APP_TZ',   'Asia/Jakarta');
define('APP_ENV',  'production');                    // 'development' = error ditampilkan

// ---- Path upload ----
define('DIR_ROOT',   dirname(__DIR__));
define('DIR_FOTO',   DIR_ROOT . '/foto');
define('DIR_VIDEO',  DIR_ROOT . '/video');
define('DIR_UPLOAD', DIR_ROOT . '/uploads');

// ---- Batas upload (byte) ----
define('MAX_IMAGE_SIZE', 12 * 1024 * 1024);   // 12 MB
define('MAX_VIDEO_SIZE', 100 * 1024 * 1024);  // 100 MB

// ---- Keamanan ----
// Buat string acak: openssl rand -hex 32
define('APP_KEY', 'ganti-dengan-string-acak-64-karakter');
define('SESSION_NAME', 'calla_sid');
define('LOGIN_MAX_TRY', 5);
define('LOGIN_LOCK_MINUTES', 15);

// ---- OAuth redirect (harus identik dengan yang didaftarkan di Google Console) ----
define('GOOGLE_REDIRECT_URI', BASE_URL . '/admin/oauth-callback.php');
