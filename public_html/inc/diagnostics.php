<?php
/**
 * Pemeriksaan lingkungan untuk halaman Integrasi.
 *
 * Tujuannya menjawab pertanyaan "kenapa tidak jalan" sebelum owner harus
 * membaca log. Urutan pemeriksaan disusun dari penyebab yang paling sering
 * ke yang paling jarang. Penyebab nomor satu di lapangan: BASE_URL di
 * config.php tidak sama dengan domain yang sedang dibuka, sehingga redirect
 * OAuth dikirim ke alamat yang salah dan Google menolak dengan
 * redirect_uri_mismatch.
 *
 * Status: 'ok' | 'warn' | 'bad'
 */
function runDiagnostics(): array
{
    $d = [];
    $add = function (string $status, string $title, string $detail) use (&$d) {
        $d[] = ['status' => $status, 'title' => $title, 'detail' => $detail];
    };

    // ---------- 1. BASE_URL vs host sebenarnya ----------
    $hostNyata = $_SERVER['HTTP_HOST'] ?? '';
    $hostConf  = parse_url(BASE_URL, PHP_URL_HOST) ?? '';
    $portNyata = ($_SERVER['SERVER_PORT'] ?? '') ;
    if ($hostNyata && $hostConf && strcasecmp($hostNyata, $hostConf) !== 0
        && strcasecmp($hostNyata, $hostConf . ':' . $portNyata) !== 0
        && strcasecmp(explode(':', $hostNyata)[0], $hostConf) !== 0) {
        $add('bad', 'BASE_URL tidak cocok dengan domain yang dibuka',
             'Sekarang membuka <b>' . e($hostNyata) . '</b> tetapi inc/config.php berisi <b>' . e($hostConf) . '</b>. '
             . 'Semua tautan, redirect setelah simpan, dan alamat callback OAuth akan mengarah ke domain yang salah. '
             . 'Perbaiki baris <code>define(\'BASE_URL\', ...)</code> di inc/config.php.');
    } else {
        $add('ok', 'BASE_URL cocok dengan domain', e(BASE_URL));
    }

    // ---------- 2. HTTPS ----------
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $lokal = in_array(explode(':', $hostNyata)[0], ['localhost', '127.0.0.1'], true);
    if ($https) {
        $add('ok', 'Situs berjalan di HTTPS', 'Wajib untuk OAuth Google dan cookie sesi yang aman.');
    } elseif ($lokal) {
        $add('warn', 'Berjalan tanpa HTTPS (lokal)', 'Bisa untuk pengembangan. Di server produksi, Google menolak redirect URI non-HTTPS.');
    } else {
        $add('bad', 'Situs belum memakai HTTPS',
             'Google hanya menerima redirect URI HTTPS untuk domain publik. Aktifkan AutoSSL di cPanel → SSL/TLS Status.');
    }

    // ---------- 3. Ekstensi PHP ----------
    $wajib = [
        'curl'      => 'memanggil API Google & Zoom',
        'openssl'   => 'HTTPS dan enkripsi kredensial',
        'pdo_mysql' => 'koneksi database',
        'mbstring'  => 'teks Indonesia & pemenggalan aman',
        'gd'        => 'membuat thumbnail dan WebP',
        'fileinfo'  => 'memeriksa tipe berkas asli saat upload',
    ];
    $kurang = [];
    foreach ($wajib as $ext => $guna) if (!extension_loaded($ext)) $kurang[] = "$ext ($guna)";
    if ($kurang) {
        $add('bad', 'Ekstensi PHP belum aktif',
             'Belum ada: <b>' . e(implode(', ', $kurang)) . '</b>. Aktifkan di cPanel → Select PHP Version → Extensions.');
    } else {
        $add('ok', 'Semua ekstensi wajib aktif', 'curl, openssl, pdo_mysql, mbstring, gd, fileinfo — PHP ' . PHP_VERSION);
    }
    if (!extension_loaded('exif')) {
        $add('warn', 'Ekstensi exif belum aktif',
             'Foto dari HP bisa tersimpan miring karena orientasi EXIF tidak terbaca. Aktifkan di Select PHP Version.');
    }

    // ---------- 4. APP_KEY ----------
    if (!defined('APP_KEY') || strlen(APP_KEY) < 32 || str_contains(APP_KEY, 'ganti-dengan')) {
        $add('bad', 'APP_KEY belum diisi dengan benar',
             'Kunci ini mengenkripsi client secret dan refresh token di database. Buat dengan <code>openssl rand -hex 32</code> lalu isikan di inc/config.php.');
    } else {
        $add('ok', 'APP_KEY terpasang', strlen(APP_KEY) . ' karakter. Jangan diganti setelah integrasi tersambung — kredensial lama akan gagal dibaca.');
    }

    // ---------- 5. Jalur keluar ke internet ----------
    foreach ([
        'oauth2.googleapis.com'  => 'Google OAuth',
        'www.googleapis.com'     => 'Google Calendar',
        'sheets.googleapis.com'  => 'Google Sheets',
        'api.zoom.us'            => 'Zoom',
    ] as $host => $nama) {
        $ok = false; $ket = '';
        if (function_exists('curl_init')) {
            $ch = curl_init('https://' . $host);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true,
            ]);
            curl_exec($ch);
            $errno = curl_errno($ch); $err = curl_error($ch);
            curl_close($ch);
            $ok  = ($errno === 0);
            $ket = $ok ? 'Terhubung.' : 'Gagal: ' . $err;
        } else {
            $ket = 'cURL tidak tersedia.';
        }
        $add($ok ? 'ok' : 'bad', "Koneksi keluar ke $nama",
             e($host) . ' — ' . e($ket) . ($ok ? '' : ' Beberapa hosting memblokir koneksi keluar; minta dibuka ke penyedia.'));
    }

    // ---------- 6. Direktori tulis ----------
    $dirs = [DIR_FOTO => 'foto', DIR_VIDEO => 'video', DIR_UPLOAD . '/blog' => 'uploads/blog', DIR_UPLOAD . '/event' => 'uploads/event'];
    $gagal = [];
    foreach ($dirs as $path => $label) {
        if (!is_dir($path)) { @mkdir($path, 0755, true); }
        if (!is_dir($path) || !is_writable($path)) $gagal[] = $label;
    }
    if ($gagal) {
        $add('bad', 'Direktori tidak bisa ditulis',
             'Belum writable: <b>' . e(implode(', ', $gagal)) . '</b>. Jalankan <code>chmod 755</code> pada folder tersebut.');
    } else {
        $add('ok', 'Semua direktori upload bisa ditulis', 'foto, video, uploads/blog, uploads/event');
    }

    // ---------- 7. Batas upload PHP ----------
    $toB = function (string $v): int {
        $v = trim($v); $n = (int) $v; $s = strtolower(substr($v, -1));
        return match ($s) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    };
    $up = $toB(ini_get('upload_max_filesize'));
    $po = $toB(ini_get('post_max_size'));
    if ($up < MAX_IMAGE_SIZE || $po <= $up) {
        $add('warn', 'Batas upload server perlu dinaikkan',
             'upload_max_filesize = ' . e(ini_get('upload_max_filesize')) . ', post_max_size = ' . e(ini_get('post_max_size'))
             . '. Untuk video sampai ' . round(MAX_VIDEO_SIZE / 1048576) . ' MB, setel keduanya di cPanel → MultiPHP INI Editor (post_max_size harus lebih besar dari upload_max_filesize).');
    } else {
        $add('ok', 'Batas upload memadai',
             'upload_max_filesize ' . e(ini_get('upload_max_filesize')) . ', post_max_size ' . e(ini_get('post_max_size'))
             . ', memory_limit ' . e(ini_get('memory_limit')));
    }

    // ---------- 8. Berkas pemasangan yang harus dihapus ----------
    if (is_file(DIR_ROOT . '/admin/setup.php')) {
        $add('warn', 'admin/setup.php masih ada di server',
             'Berkas ini mengunci diri setelah akun pertama dibuat, tapi sebaiknya tetap dihapus: <code>rm admin/setup.php</code>');
    }

    return $d;
}

/** Ringkas jumlah masalah untuk lencana di menu. */
function diagnosticsCount(array $d): array
{
    return [
        'bad'  => count(array_filter($d, fn($x) => $x['status'] === 'bad')),
        'warn' => count(array_filter($d, fn($x) => $x['status'] === 'warn')),
    ];
}
