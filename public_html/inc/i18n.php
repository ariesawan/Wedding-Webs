<?php
/**
 * ============================================================
 * DWIBAHASA (Indonesia / English)
 * ============================================================
 *
 * Kunci terjemahan adalah kalimat Indonesianya sendiri, bukan kode
 * seperti 'hero.title'. Alasannya praktis: halaman ini isinya prosa,
 * bukan label antarmuka. Dengan kalimat sebagai kunci, teks Indonesia
 * tetap terbaca utuh di dalam kode, dan kalimat yang belum diterjemahkan
 * jatuh kembali ke aslinya — bukan jadi 'hero.title' telanjang di layar.
 *
 * Konsekuensinya: mengubah kalimat Indonesia memutus terjemahannya.
 * Itu justru disengaja — kalimat yang berubah artinya memang harus
 * ditinjau ulang terjemahannya, bukan diam-diam memakai versi lama.
 *
 * Urutan penentuan bahasa:
 *   1. ?lang=en di URL        — paling kuat, supaya tautan bisa dibagikan
 *   2. cookie clp_lang        — pilihan yang sudah pernah dibuat
 *   3. Accept-Language        — tebakan pertama untuk pengunjung baru
 *   4. Indonesia              — bawaan
 */

const BAHASA_TERSEDIA = ['id' => 'Indonesia', 'en' => 'English'];

function bahasaAktif(): string
{
    static $lang = null;
    if ($lang !== null) return $lang;

    $pilih = $_GET['lang'] ?? '';
    if (isset(BAHASA_TERSEDIA[$pilih])) {
        // Cookie dipasang di sini, bukan lewat JS, supaya pilihan sudah
        // berlaku pada permintaan yang sama — tanpa kedipan bahasa.
        setcookie('clp_lang', $pilih, [
            'expires'  => time() + 31536000,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        return $lang = $pilih;
    }

    $cookie = $_COOKIE['clp_lang'] ?? '';
    if (isset(BAHASA_TERSEDIA[$cookie])) return $lang = $cookie;

    // Hanya diperiksa untuk pengunjung yang belum pernah memilih.
    $accept = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    if ($accept !== '' && !str_contains($accept, 'id') && str_contains($accept, 'en')) {
        return $lang = 'en';
    }

    return $lang = 'id';
}

/**
 * Terjemahkan.
 *
 * Tiga lapis, dari yang paling kuat:
 *   1. tabel translations  — suntingan owner lewat panel
 *   2. inc/lang/en.php     — terjemahan bawaan (bibit)
 *   3. teks asli           — kalau dua-duanya kosong
 *
 * Seluruh tabel dibaca sekali per permintaan, bukan satu query per kalimat.
 * Beranda memanggil t() puluhan kali; satu query per panggilan berarti
 * puluhan round-trip untuk memuat satu halaman.
 */
function t(string $teks): string
{
    static $db = null, $bibit = null;

    if (bahasaAktif() === 'id') return $teks;

    if ($bibit === null) {
        $berkas = __DIR__ . '/lang/en.php';
        $bibit  = is_file($berkas) ? (require $berkas) : [];
    }

    if ($db === null) {
        $db = [];
        try {
            foreach (all("SELECT src_hash, dst FROM translations
                          WHERE lang = ? AND dst IS NOT NULL AND dst <> ''",
                         [bahasaAktif()]) as $r) {
                $db[$r['src_hash']] = $r['dst'];
            }
        } catch (Throwable $e) {
            // Tabel belum dimigrasi — situs tetap jalan dengan bibit saja.
            $db = [];
        }
    }

    $h = sha1($teks);
    if (isset($db[$h]))     return $db[$h];
    if (isset($bibit[$teks])) return $bibit[$teks];
    return $teks;
}

/**
 * Kumpulkan semua kalimat yang dibungkus t() di seluruh kode sumber.
 *
 * Dibaca dari berkas, bukan dicatat saat runtime. Alasannya: kalimat yang
 * hanya muncul di cabang tertentu — misalnya pesan galat — tidak akan pernah
 * tertangkap kalau menunggu halaman dijalankan, jadi tidak akan pernah bisa
 * diterjemahkan.
 */
function kumpulkanKalimat(): array
{
    $akar    = dirname(__DIR__);
    $periksa = ['index.php', 'galeri.php', 'blog.php', 'blog-detail.php',
                'event.php', 'event-detail.php', 'inc/i18n.php'];
    foreach (glob($akar . '/partials/*.php') ?: [] as $f) {
        $periksa[] = 'partials/' . basename($f);
    }

    $keluar = [];
    foreach ($periksa as $rel) {
        $jalur = $akar . '/' . $rel;
        if (!is_file($jalur)) continue;
        $isi = file_get_contents($jalur);
        if (preg_match_all("/\bt\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*\)/s", $isi, $m)) {
            foreach ($m[1] as $k) {
                $k = str_replace(["\\'", '\\\\'], ["'", '\\'], $k);
                if (trim($k) === '') continue;
                $keluar[$k] = $rel;
            }
        }
    }
    ksort($keluar);
    return $keluar;
}

/** Terjemahkan lalu langsung amankan untuk HTML. */
function te(string $teks): string
{
    return e(t($teks));
}

/**
 * URL halaman ini dalam bahasa lain.
 * Parameter lang lama dibuang dulu supaya tidak menumpuk saat ditekan
 * berkali-kali (…?lang=en&lang=id&lang=en).
 */
function urlBahasa(string $lang): string
{
    $q = $_GET;
    unset($q['lang']);
    $q['lang'] = $lang;
    return strtok($_SERVER['REQUEST_URI'] ?? '/', '?') . '?' . http_build_query($q);
}

/**
 * Tag hreflang.
 *
 * Ini yang memberi tahu Google bahwa dua URL adalah halaman yang sama
 * dalam bahasa berbeda — bukan dua halaman yang isinya mirip. Tanpa ini,
 * versi Inggris berisiko dianggap salinan dan justru menekan peringkat
 * versi Indonesianya.
 *
 * x-default menunjuk ke versi Indonesia karena pasar utamanya Yogyakarta.
 */
function hreflangTags(): string
{
    $dasar = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $q     = $_GET;
    unset($q['lang']);
    $sisa  = $q ? '&' . http_build_query($q) : '';
    $root  = rtrim(setting('site_url', ''), '/');

    $out = '';
    foreach (array_keys(BAHASA_TERSEDIA) as $l) {
        $out .= '<link rel="alternate" hreflang="' . $l . '" href="'
              . e($root . $dasar . '?lang=' . $l . $sisa) . '">' . "\n";
    }
    $out .= '<link rel="alternate" hreflang="x-default" href="'
          . e($root . $dasar) . '">' . "\n";
    return $out;
}

/** Pemilih bahasa. Ringkas — dua huruf, tanpa bendera. */
function pemilihBahasa(string $kelas = ''): string
{
    $kini = bahasaAktif();
    $out  = '<div class="langsw ' . e($kelas) . '" role="group" aria-label="' . te('Pilih bahasa') . '">';
    foreach (BAHASA_TERSEDIA as $kode => $nama) {
        // Bendera sengaja tidak dipakai: bahasa bukan negara, dan bendera
        // Inggris/Amerika sama-sama keliru untuk pembaca dari Singapura,
        // Australia, atau mana pun.
        $out .= '<a href="' . e(urlBahasa($kode)) . '"'
              . ' class="' . ($kode === $kini ? 'on' : '') . '"'
              . ' hreflang="' . $kode . '" lang="' . $kode . '"'
              . ' title="' . e($nama) . '"'
              . ($kode === $kini ? ' aria-current="true"' : '')
              . ' rel="nofollow">' . strtoupper($kode) . '</a>';
    }
    return $out . '</div>';
}

/**
 * Tombol WhatsApp mengambang.
 *
 * Nomor diambil dari pengaturan yang sama dengan yang dipakai skema
 * LocalBusiness — kalau berbeda, Google akan ragu situs dan profil bisnis
 * ini milik usaha yang sama, dan itu merugikan pencarian lokal.
 */
function tombolWaMengambang(): string
{
    $no = preg_replace('/\D/', '', (string) setting('wa_number'));
    if ($no === '') return '';
    if (str_starts_with($no, '0')) $no = '62' . substr($no, 1);

    $pesan = t('Halo Callalily, saya ingin bertanya soal wedding organizer.');

    return '<a class="wafab" href="https://wa.me/' . e($no) . '?text=' . rawurlencode($pesan) . '"'
         . ' target="_blank" rel="noopener" aria-label="' . te('Hubungi kami lewat WhatsApp') . '">'
         . '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" focusable="false">'
         . '<path fill="currentColor" d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15'
         . '-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.76-1.66-2.06'
         . '-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61'
         . '-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.22 3.08'
         . 'c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.69.63.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2.01-1.41'
         . '.25-.7.25-1.29.17-1.42-.07-.13-.27-.2-.57-.35zM12.04 21.5h-.01a9.4 9.4 0 0 1-4.79-1.31l-.34-.2'
         . '-3.56.93.95-3.47-.22-.36a9.36 9.36 0 0 1-1.44-5.01c0-5.18 4.23-9.4 9.42-9.4a9.36 9.36 0 0 1 6.65 2.76'
         . 'a9.32 9.32 0 0 1 2.76 6.65c0 5.18-4.23 9.4-9.42 9.4zM20.52 3.49A11.78 11.78 0 0 0 12.04 0'
         . 'C5.5 0 .18 5.32.18 11.86c0 2.09.55 4.13 1.59 5.93L.08 24l6.35-1.66a11.83 11.83 0 0 0 5.61 1.43h.01'
         . 'c6.53 0 11.85-5.32 11.85-11.86 0-3.17-1.23-6.15-3.38-8.42z"/></svg>'
         . '<span>' . te('Tanya lewat WhatsApp') . '</span></a>';
}
