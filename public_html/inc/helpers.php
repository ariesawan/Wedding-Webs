<?php
/** Escape HTML. Dipakai di SETIAP output ke halaman. */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escape untuk dipakai di dalam atribut JS / JSON inline. */
function ejs($v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/** Slug URL-friendly: "Winda & Zakki, Sleman" -> "winda-zakki-sleman" */
function slugify(string $text, int $max = 90): string
{
    $text = trim($text);
    if (function_exists('iconv')) {
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($conv !== false) $text = $conv;
    }
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');
    if ($text === '') $text = 'item-' . date('YmdHis');
    return substr($text, 0, $max);
}

/** Pastikan slug unik di tabel tertentu. */
function uniqueSlug(string $table, string $slug, ?int $ignoreId = null): string
{
    $allowed = ['posts', 'events'];
    if (!in_array($table, $allowed, true)) throw new InvalidArgumentException('Tabel tidak diizinkan.');

    $base = $slug; $i = 2;
    while (true) {
        $sql = "SELECT id FROM `$table` WHERE slug = ?" . ($ignoreId ? " AND id <> " . (int)$ignoreId : "") . " LIMIT 1";
        if (!one($sql, [$slug])) return $slug;
        $slug = $base . '-' . $i++;
    }
}

/** URL absolut dari path relatif. */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)));
    exit;
}

/** Token CSRF per-sesi. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
}

/** Panggil di awal SEMUA handler POST. hash_equals = perbandingan waktu-konstan. */
function csrfCheck(): void
{
    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        die('Sesi kedaluwarsa atau token tidak cocok. Muat ulang halaman lalu ulangi.');
    }
}

/** Flash message antar-redirect. */
function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) { $_SESSION['flash'] = ['msg' => $msg, 'type' => $type]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/** Tanggal Indonesia: 2026-08-17 -> 17 Agustus 2026 */
function tanggalID(?string $datetime, bool $withTime = false): string
{
    if (!$datetime) return '—';
    $ts = strtotime($datetime);
    if (!$ts) return '—';
    $bulan = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $out = date('j', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    return $withTime ? $out . ', ' . date('H.i', $ts) : $out;
}

function hariID(?string $datetime): string
{
    if (!$datetime) return '';
    $h = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
    return $h[date('l', strtotime($datetime))] ?? '';
}

/** "3 menit baca" untuk artikel blog. 200 kata/menit. */
function readingTime(string $html): int
{
    $words = str_word_count(strip_tags($html), 0, 'àáâãäåçèéêëìíîïñòóôõöùúûüýÿ0123456789');
    return max(1, (int) ceil($words / 200));
}

/** Potong teks rapi di batas kata — untuk excerpt & meta description. */
function excerptFrom(string $html, int $len = 160): string
{
    $t = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    if (mb_strlen($t) <= $len) return $t;
    $cut = mb_substr($t, 0, $len);
    $sp  = mb_strrpos($cut, ' ');
    return rtrim($sp ? mb_substr($cut, 0, $sp) : $cut, ' ,.;:-') . '…';
}

/** Whitelist HTML untuk konten artikel — cegah XSS tersimpan dari editor. */
function sanitizeArticleHtml(string $html): string
{
    // Buang blok berbahaya BESERTA ISINYA lebih dulu. strip_tags() saja hanya
    // menghapus tag-nya, sehingga "alert(1)" dari <script> akan tersisa jadi teks.
    $html = preg_replace('#<(script|style|iframe|object|embed|form|svg|math)\b[^>]*>.*?</\1\s*>#is', '', $html);
    $html = preg_replace('#<(script|style|iframe|object|embed|form|svg|math)\b[^>]*/?>#i', '', $html);

    $allowed = '<p><br><strong><b><em><i><u><s><h2><h3><h4><ul><ol><li><blockquote><a><img><figure><figcaption><hr><table><thead><tbody><tr><th><td><code><pre><span>';
    $html = strip_tags($html, $allowed);

    // Buang atribut berbahaya: on*, javascript:, srcdoc, style ekspresi
    $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2/i', '$1="#"', $html);
    $html = preg_replace('/\ssrcdoc\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html);

    // Semua link keluar -> rel aman
    $html = preg_replace('/<a\s+(?![^>]*rel=)/i', '<a rel="noopener" ', $html);
    return trim($html);
}

/** Bilangan aman untuk paging. */
function intval_between($v, int $min, int $max, int $default): int
{
    $n = filter_var($v, FILTER_VALIDATE_INT);
    if ($n === false) return $default;
    return max($min, min($max, $n));
}

/** IP klien (hormati proxy Cloudflare / LiteSpeed bila ada). */
function clientIp(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

/**
 * Versi aset untuk memaksa peramban mengambil berkas terbaru.
 *
 * Memakai waktu ubah berkas, bukan angka yang ditulis tangan. Nomor manual
 * gampang lupa dinaikkan — dan kalau lupa, peramban tetap memakai CSS lama
 * dari cache sementara HTML-nya sudah versi baru. Hasilnya tampilan rusak
 * yang membingungkan, padahal berkas di server sebenarnya sudah benar.
 */
function assetVer(string $rel): string
{
    $p = DIR_ROOT . '/' . ltrim($rel, '/');
    return (string) (is_file($p) ? filemtime($p) : time());
}

/**
 * Bidang kata sandi.
 *
 * Tombol lihat/sembunyikan dan indikator kekuatan TIDAK ditulis di sini —
 * keduanya disuntik oleh admin/assets/admin.js ke setiap input[type=password].
 * Satu mekanisme saja; kalau ditulis dua kali, tombolnya jadi dobel.
 *
 * $opt['strength'] = true  -> tampilkan indikator kekuatan (untuk sandi baru)
 */
function pwField(string $id, string $name, array $opt = []): string
{
    $attr = '';
    foreach (['required', 'autofocus'] as $k) {
        if (!empty($opt[$k])) $attr .= " $k";
    }
    foreach (['minlength', 'placeholder', 'autocomplete', 'value'] as $k) {
        if (isset($opt[$k]) && $opt[$k] !== '') $attr .= ' ' . $k . '="' . e((string) $opt[$k]) . '"';
    }
    if (!isset($opt['autocomplete'])) $attr .= ' autocomplete="off"';
    if (!empty($opt['strength']))     $attr .= ' data-strength';

    return '<input type="password" id="' . e($id) . '" name="' . e($name) . '"' . $attr . '>';
}

/**
 * Untuk halaman yang TIDAK memakai adminFoot()/authFoot() — memuat skrip
 * bersama supaya perilaku kata sandinya tetap sama.
 */
function pwScript(): string
{
    return '<script src="assets/admin.js?v=' . assetVer('admin/assets/admin.js') . '"></script>';
}

/** Format rupiah: 15000000 -> Rp 15.000.000 */
function rupiah($n, bool $short = false): string
{
    $n = (float) $n;
    if ($n <= 0) return '—';
    $p = setting('currency_prefix', 'Rp');
    if ($short) {
        if ($n >= 1_000_000_000) return $p . ' ' . rtrim(rtrim(number_format($n / 1_000_000_000, 1, ',', '.'), '0'), ',') . ' M';
        if ($n >= 1_000_000)     return $p . ' ' . rtrim(rtrim(number_format($n / 1_000_000, 1, ',', '.'), '0'), ',') . ' jt';
        if ($n >= 1_000)         return $p . ' ' . round($n / 1000) . ' rb';
    }
    return $p . ' ' . number_format($n, 0, ',', '.');
}

/** Selisih hari dari hari ini; negatif = sudah lewat. */
function hariKe(?string $date): ?int
{
    if (!$date) return null;
    $t = strtotime(date('Y-m-d', strtotime($date)));
    $n = strtotime(date('Y-m-d'));
    return $t === false ? null : (int) round(($t - $n) / 86400);
}

/** "H-45", "Hari ini", "3 hari lalu" */
function labelHari(?string $date): string
{
    $d = hariKe($date);
    if ($d === null) return '—';
    if ($d === 0)  return 'Hari ini';
    if ($d === 1)  return 'Besok';
    if ($d === -1) return 'Kemarin';
    return $d > 0 ? 'H-' . $d : abs($d) . ' hari lalu';
}


/**
 * Tema tampilan panel: 'terang' (bawaan) atau 'gelap'.
 *
 * Nilainya dibaca dari cookie lalu dicetak sebagai atribut data-theme pada
 * <html>. Dilakukan di sisi server, bukan JavaScript, supaya halaman tidak
 * berkedip putih sesaat sebelum tema gelap diterapkan.
 */
function themeAttr(): string
{
    $t = $_COOKIE['calla_theme'] ?? '';
    return $t === 'dark' ? ' data-theme="dark"' : ($t === 'light' ? ' data-theme="light"' : '');
}

/**
 * Tombol ganti tema untuk rail admin.
 *
 * Atribut width/height/fill/stroke sengaja ditulis langsung di SVG, tidak
 * hanya diserahkan ke CSS. Kalau stylesheet gagal dimuat atau masih versi
 * lama di cache, ikon tetap berukuran wajar — bukan gambar hitam raksasa
 * yang merusak seluruh tata letak.
 */
function themeToggle(): string
{
    $svg = 'width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';

    return '<button type="button" class="themebtn" id="themeBtn" aria-label="Ganti tema tampilan">'
      . '<svg class="ic-sun" ' . $svg . '><circle cx="12" cy="12" r="4.5"/>'
      . '<path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>'
      . '<svg class="ic-moon" ' . $svg . '><path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5Z"/></svg>'
      . '<span><span class="lbl-light">Mode gelap</span><span class="lbl-dark">Mode terang</span></span>'
      . '</button>';
}
