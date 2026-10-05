<?php
/** Titik masuk tunggal semua halaman: require file ini paling atas. */

if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    die('inc/config.php belum dibuat. Salin inc/config.sample.php menjadi inc/config.php lalu isi kredensialnya.');
}
require_once __DIR__ . '/config.php';

date_default_timezone_set(APP_TZ);
mb_internal_encoding('UTF-8');

if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

// ---- Session hardening ----
if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,      // cookie hanya lewat HTTPS
        'httponly' => true,        // tidak bisa dibaca JavaScript -> mitigasi XSS
        'samesite' => 'Lax',       // Lax, bukan Strict, agar redirect OAuth Google tetap bawa sesi
    ]);
    session_start();
}

// ---- Header keamanan dasar ----
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/seo.php';
// Dimuat di sini, bukan per halaman. t() dipakai di beranda, partial, dan
// nanti di galeri/blog/event — memuatnya satu per satu berarti satu halaman
// yang terlewat langsung fatal, dan itu persis yang terjadi sebelumnya.
require_once __DIR__ . '/i18n.php';
