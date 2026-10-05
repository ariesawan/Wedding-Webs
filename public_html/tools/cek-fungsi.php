<?php
/**
 * Pemeriksa fungsi tak terdefinisi.
 *
 * Dibuat setelah beranda mati total karena satu fungsi yang tidak pernah
 * dimuat. `php -l` meloloskannya — lint hanya memeriksa sintaks, dan
 * memanggil fungsi yang tidak ada secara sintaksis sah sempurna.
 * Kesalahannya baru muncul saat baris itu dieksekusi, yaitu di produksi.
 *
 * Skrip ini membaca seluruh proyek lewat tokenizer PHP, mengumpulkan
 * semua fungsi yang DIDEFINISIKAN, lalu membandingkannya dengan semua
 * fungsi yang DIPANGGIL. Selisihnya adalah kandidat fatal error.
 *
 * Jalankan sebelum unggah:
 *   php tools/cek-fungsi.php
 */

$akar = dirname(__DIR__);

$lewati = ['/vendor/', '/node_modules/', '/.git/', '/tools/'];

$berkas = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($akar));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') continue;
    $jalur = str_replace('\\', '/', $f->getPathname());
    foreach ($lewati as $l) if (str_contains($jalur, $l)) continue 2;
    $berkas[] = $jalur;
}
sort($berkas);

$didefinisikan = [];
$dipanggil     = [];   // nama => [berkas => baris pertama]

foreach ($berkas as $b) {
    $tok = token_get_all(file_get_contents($b));
    $n   = count($tok);

    for ($i = 0; $i < $n; $i++) {
        $t = $tok[$i];
        if (!is_array($t)) continue;

        // ---- definisi: T_FUNCTION diikuti nama ----
        if ($t[0] === T_FUNCTION) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_array($tok[$j]) && $tok[$j][0] === T_WHITESPACE) continue;
                // 'function &namaFungsi()' — kembalian by-reference. Tanda &
                // muncul sebagai token karakter biasa di antara keduanya.
                // Sejak PHP 8.1 tanda & bisa datang sebagai token array
                // (T_AMPERSAND_*), bukan lagi karakter tunggal — dua-duanya
                // harus dilewati atau nama fungsinya tidak pernah terbaca.
                if ($tok[$j] === '&') continue;
                if (is_array($tok[$j]) && ($tok[$j][1] ?? '') === '&') continue;
                if (is_array($tok[$j]) && $tok[$j][0] === T_STRING) {
                    $didefinisikan[strtolower($tok[$j][1])] = $b;
                }
                break;   // '(' berarti closure — tidak punya nama
            }
            continue;
        }

        // ---- pemanggilan: T_STRING diikuti '(' ----
        if ($t[0] !== T_STRING) continue;

        // Lewati kalau didahului -> :: function new atau kata kunci lain,
        // karena itu metode/kelas, bukan fungsi global.
        $sblm = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tok[$j]) && $tok[$j][0] === T_WHITESPACE) continue;
            $sblm = $tok[$j];
            break;
        }
        if (is_array($sblm) && in_array($sblm[0], [
            T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW,
            T_CLASS, T_INTERFACE, T_TRAIT, T_CONST, T_USE,
            T_NULLSAFE_OBJECT_OPERATOR,
        ], true)) continue;

        // Berikutnya harus '('
        $ssdh = null;
        for ($j = $i + 1; $j < $n; $j++) {
            if (is_array($tok[$j]) && $tok[$j][0] === T_WHITESPACE) continue;
            $ssdh = $tok[$j];
            break;
        }
        if ($ssdh !== '(') continue;

        $nama = strtolower($t[1]);
        if (!isset($dipanggil[$nama])) $dipanggil[$nama] = [];
        if (!isset($dipanggil[$nama][$b])) $dipanggil[$nama][$b] = $t[2];
    }
}

// Fungsi bawaan PHP + kata kunci yang menyerupai pemanggilan.
$bawaan = array_flip(array_map('strtolower', get_defined_functions()['internal']));
$katakunci = array_flip([
    'array','isset','unset','empty','list','echo','print','exit','die',
    'include','include_once','require','require_once','eval','match','fn',
    'static','parent','self','int','float','string','bool','void','if',
    'elseif','while','for','foreach','switch','catch','return','yield','new',
]);

$hilang = [];
foreach ($dipanggil as $nama => $tempat) {
    if (isset($didefinisikan[$nama]) || isset($bawaan[$nama]) || isset($katakunci[$nama])) continue;
    $hilang[$nama] = $tempat;
}

/* =====================================================================
   PASS 2 — keterjangkauan per titik masuk
   =====================================================================
   Pass pertama hanya memastikan fungsi ADA di suatu tempat dalam proyek.
   Itu tidak cukup: fungsi yang didefinisikan di admin/ akan lolos walau
   dipanggil dari index.php, dan halaman tetap fatal saat dibuka.

   Pass ini menelusuri rantai require tiap halaman, lalu memeriksa apakah
   setiap fungsi yang dipanggil benar-benar TERJANGKAU dari halaman itu.
   Inilah bentuk kesalahan yang membuat beranda mati: t() ada di inc/i18n.php,
   tapi index.php tidak pernah memuatnya.
   ===================================================================== */

/** Kumpulkan berkas yang di-require dari sebuah berkas, secara transitif. */
function rantaiRequire(string $berkas, string $akar, array &$sudah = []): array
{
    $berkas = realpath($berkas) ?: $berkas;
    if (isset($sudah[$berkas]) || !is_file($berkas)) return $sudah;
    $sudah[$berkas] = true;

    $isi = file_get_contents($berkas);
    // Cocokkan: require_once __DIR__ . '/inc/x.php'  (dan varian require/include)
    if (preg_match_all(
        '~(?:require|include)(?:_once)?\s*(?:\(\s*)?__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]~',
        $isi, $m
    )) {
        foreach ($m[1] as $rel) {
            rantaiRequire(dirname($berkas) . '/' . ltrim($rel, '/'), $akar, $sudah);
        }
    }
    return $sudah;
}

/** Fungsi yang didefinisikan dalam satu berkas. */
function fungsiDi(string $b): array
{
    $out = [];
    $tok = @token_get_all(@file_get_contents($b) ?: '');
    $n   = count($tok);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tok[$i]) || $tok[$i][0] !== T_FUNCTION) continue;
        for ($j = $i + 1; $j < $n; $j++) {
            if (is_array($tok[$j]) && $tok[$j][0] === T_WHITESPACE) continue;
            if ($tok[$j] === '&') continue;
            if (is_array($tok[$j]) && ($tok[$j][1] ?? '') === '&') continue;
            if (is_array($tok[$j]) && $tok[$j][0] === T_STRING) $out[strtolower($tok[$j][1])] = true;
            break;
        }
    }
    return $out;
}

// Titik masuk: berkas yang bisa dibuka langsung lewat peramban.
$masuk = array_values(array_filter($berkas, function ($b) use ($akar) {
    $rel = str_replace($akar . '/', '', $b);
    if (str_starts_with($rel, 'inc/'))      return false;
    if (str_starts_with($rel, 'partials/')) return false;
    if (str_starts_with($rel, 'cron/'))     return false;
    if (str_contains($rel, '_layout'))      return false;
    return true;
}));

$masalah = [];
foreach ($masuk as $hal) {
    $sudah    = [];
    $terjangkau = rantaiRequire($hal, $akar, $sudah);

    $adaFungsi = [];
    foreach (array_keys($terjangkau) as $b) $adaFungsi += fungsiDi($b);

    // Panggilan diperiksa di SELURUH berkas yang ikut termuat, bukan hanya
    // di berkas titik masuknya. Versi sebelumnya hanya melihat titik masuk —
    // dan meloloskan kasus paling berbahaya: fungsi yang dipanggil dari
    // dalam berkas inc/ yang di-require, di mana bergantungannya tidak
    // terlihat sama sekali dari halaman pemanggilnya.
    foreach ($dipanggil as $nama => $tempat) {
        if (isset($bawaan[$nama]) || isset($katakunci[$nama])) continue;
        if (isset($adaFungsi[$nama])) continue;

        foreach ($tempat as $berkasPemanggil => $baris) {
            if (!isset($terjangkau[$berkasPemanggil])) continue;   // bukan bagian halaman ini
            $rel = str_replace($akar . '/', '', $berkasPemanggil);
            $masalah[str_replace($akar . '/', '', $hal)][] =
                $nama . '()  di ' . $rel . ':' . $baris;
            break;
        }
    }
}

echo "Berkas diperiksa : " . count($berkas) . "\n";
echo "Titik masuk      : " . count($masuk) . "\n";
echo "Fungsi terdefinisi: " . count($didefinisikan) . "\n";
echo "Fungsi dipanggil  : " . count($dipanggil) . "\n\n";

if ($masalah) {
    echo "✗ Fungsi TIDAK TERJANGKAU dari titik masuknya (halaman akan fatal):\n\n";
    foreach ($masalah as $hal => $daftar) {
        echo "  $hal\n";
        foreach (array_unique($daftar) as $d) echo "      $d\n";
    }
    echo "\n  Perbaikan: tambahkan require_once berkas yang mendefinisikannya,\n";
    echo "  atau muat dari inc/bootstrap.php kalau dipakai banyak halaman.\n\n";
}

if (!$hilang && !$masalah) {
    echo "✓ Semua fungsi terdefinisi dan terjangkau dari titik masuknya.\n";
    exit(0);
}
if (!$hilang) exit(1);

echo "✗ " . count($hilang) . " fungsi dipanggil tapi tidak ditemukan definisinya:\n\n";
foreach ($hilang as $nama => $tempat) {
    echo "  $nama()\n";
    foreach ($tempat as $b => $baris) {
        echo "      " . str_replace($akar . '/', '', $b) . ":$baris\n";
    }
}
echo "\nCatatan: fungsi dari ekstensi yang tidak aktif di mesin ini juga\n";
echo "akan muncul di sini. Periksa satu per satu sebelum menyimpulkan.\n";
exit(1);
