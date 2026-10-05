<?php
/**
 * ============================================================
 * GRAFIK SVG
 * ============================================================
 *
 * Digambar di server sebagai SVG, bukan lewat pustaka JavaScript.
 *
 * Alasannya bukan kemurnian: Chart.js menambah ~200 KB yang harus diunduh
 * sebelum satu batang pun muncul, dan panel ini dibuka dari koneksi seluler
 * di lapangan. SVG server-side tampil bersamaan dengan HTML-nya, ikut
 * tercetak saat halaman di-print, dan tetap terbaca kalau JavaScript mati.
 *
 * Setiap fungsi mengembalikan string SVG utuh. Semua teks dilewatkan e()
 * karena isinya bisa berupa nama kota atau pekerjaan dari input pengguna.
 */

/** Palet berulang — cukup untuk enam irisan sebelum berputar. */
const GRAFIK_WARNA = ['var(--ember)', 'var(--sage)', '#8FA9D6', '#D69AB4', '#C9B27E', '#7FB5B5'];

/**
 * Diagram donat.
 * $data: [label => nilai]
 */
function grafikDonat(array $data, string $tengahAtas = '', string $tengahBawah = '', int $ukuran = 190): string
{
    $total = array_sum($data);
    if ($total <= 0) {
        return '<p class="muted" style="font-size:13px;padding:16px 0">Belum ada data.</p>';
    }

    $r = $ukuran / 2 - 18;
    $c = $ukuran / 2;
    $keliling = 2 * M_PI * $r;
    $tebal = 26;

    $svg = '<svg viewBox="0 0 ' . $ukuran . ' ' . $ukuran . '" width="' . $ukuran . '" height="' . $ukuran
         . '" role="img" style="max-width:100%;height:auto">';

    $mulai = 0;
    $i = 0;
    foreach ($data as $label => $nilai) {
        if ($nilai <= 0) { $i++; continue; }
        $porsi   = $nilai / $total;
        $panjang = $porsi * $keliling;
        $warna   = GRAFIK_WARNA[$i % count(GRAFIK_WARNA)];

        // stroke-dasharray memotong lingkaran jadi irisan; -90° supaya
        // irisan pertama mulai dari atas, bukan dari jam tiga.
        $svg .= '<circle cx="' . $c . '" cy="' . $c . '" r="' . $r . '"'
              . ' fill="none" stroke="' . $warna . '" stroke-width="' . $tebal . '"'
              . ' stroke-dasharray="' . round($panjang, 2) . ' ' . round($keliling - $panjang, 2) . '"'
              . ' stroke-dashoffset="' . round(-$mulai, 2) . '"'
              . ' transform="rotate(-90 ' . $c . ' ' . $c . ')">'
              . '<title>' . e((string) $label) . ': ' . (int) $nilai
              . ' (' . round($porsi * 100) . '%)</title></circle>';

        $mulai += $panjang;
        $i++;
    }

    if ($tengahAtas !== '') {
        $svg .= '<text x="' . $c . '" y="' . ($c - 2) . '" text-anchor="middle"'
              . ' style="font-family:var(--serif);font-size:26px;fill:var(--ivory)">'
              . e($tengahAtas) . '</text>';
    }
    if ($tengahBawah !== '') {
        $svg .= '<text x="' . $c . '" y="' . ($c + 16) . '" text-anchor="middle"'
              . ' style="font-family:var(--mono);font-size:9.5px;letter-spacing:.1em;fill:var(--ivory-38)">'
              . e($tengahBawah) . '</text>';
    }

    return $svg . '</svg>';
}

/** Keterangan warna untuk donat. */
function grafikLegenda(array $data): string
{
    $total = array_sum($data);
    $out = '<div style="display:grid;gap:6px;margin-top:4px">';
    $i = 0;
    foreach ($data as $label => $nilai) {
        $warna = GRAFIK_WARNA[$i % count(GRAFIK_WARNA)];
        $persen = $total > 0 ? round($nilai / $total * 100) : 0;
        $out .= '<div style="display:flex;align-items:center;gap:8px;font-size:12.6px">'
              . '<span style="width:10px;height:10px;border-radius:3px;background:' . $warna . ';flex:0 0 10px"></span>'
              . '<span style="flex:1;color:var(--ivory-60)">' . e((string) $label) . '</span>'
              . '<span class="mono" style="color:var(--ivory)">' . (int) $nilai . '</span>'
              . '<span class="mono" style="color:var(--ivory-38);width:34px;text-align:right">' . $persen . '%</span>'
              . '</div>';
        $i++;
    }
    return $out . '</div>';
}

/**
 * Diagram batang vertikal — untuk deret waktu seperti bulan acara.
 * $data: [label => nilai]. $sorot: label yang diberi warna berbeda.
 */
function grafikBatang(array $data, array $sorot = [], int $tinggi = 170): string
{
    if (!$data) return '<p class="muted" style="font-size:13px">Belum ada data.</p>';

    $maks = max(1, max($data));
    $n    = count($data);
    $lebarKolom = 44;
    $lebar = max(320, $n * $lebarKolom);
    $dasar = $tinggi - 26;

    $svg = '<svg viewBox="0 0 ' . $lebar . ' ' . $tinggi . '" width="100%" height="' . $tinggi
         . '" preserveAspectRatio="none" role="img" style="overflow:visible">';

    // Garis bantu di 50% dan 100% — tanpa ini tinggi batang sulit dibandingkan.
    foreach ([0.5, 1.0] as $g) {
        $y = $dasar - $g * ($dasar - 12);
        $svg .= '<line x1="0" y1="' . round($y, 1) . '" x2="' . $lebar . '" y2="' . round($y, 1) . '"'
              . ' stroke="var(--ivory-07)" stroke-width="1"/>';
    }

    $i = 0;
    foreach ($data as $label => $nilai) {
        $h = $nilai > 0 ? max(3, ($nilai / $maks) * ($dasar - 12)) : 0;
        $x = $i * $lebarKolom + 7;
        $w = $lebarKolom - 14;
        $y = $dasar - $h;
        $warna = in_array($label, $sorot, true) ? 'var(--ember)' : 'var(--ivory-12)';

        if ($h > 0) {
            $svg .= '<rect x="' . $x . '" y="' . round($y, 1) . '" width="' . $w . '" height="' . round($h, 1) . '"'
                  . ' rx="3" fill="' . $warna . '"><title>' . e((string) $label) . ': ' . (int) $nilai . '</title></rect>';
            $svg .= '<text x="' . ($x + $w / 2) . '" y="' . round($y - 5, 1) . '" text-anchor="middle"'
                  . ' style="font-family:var(--mono);font-size:10px;fill:var(--ivory-60)">' . (int) $nilai . '</text>';
        }
        $svg .= '<text x="' . ($x + $w / 2) . '" y="' . ($dasar + 14) . '" text-anchor="middle"'
              . ' style="font-family:var(--mono);font-size:9.5px;fill:var(--ivory-38)">'
              . e((string) $label) . '</text>';
        $i++;
    }

    return $svg . '</svg>';
}

/**
 * Batang mendatar bertumpuk dua warna — dipakai untuk "masuk vs deal".
 * $baris: [['label' => ..., 'total' => n, 'deal' => n], …]
 */
function grafikBatangGanda(array $baris): string
{
    if (!$baris) return '<p class="muted" style="font-size:13px">Belum ada data.</p>';

    $maks = 1;
    foreach ($baris as $b) $maks = max($maks, (int) $b['total']);

    $out = '<div style="display:grid;gap:11px">';
    foreach ($baris as $b) {
        $wt = round((int) $b['total'] / $maks * 100, 1);
        $wd = round((int) $b['deal']  / $maks * 100, 1);
        $kv = $b['total'] > 0 ? round($b['deal'] / $b['total'] * 100) : 0;

        $out .= '<div>'
              . '<div style="display:flex;justify-content:space-between;font-size:12.6px;margin-bottom:4px">'
              . '<span style="color:var(--ivory)">' . e((string) $b['label']) . '</span>'
              . '<span class="mono" style="color:var(--ivory-38)">'
              . (int) $b['deal'] . ' dari ' . (int) $b['total']
              . ' <span style="color:' . ($kv >= 30 ? 'var(--sage-text)' : 'var(--ivory-38)') . '">· ' . $kv . '%</span>'
              . '</span></div>'
              // Batang bawah = semua yang bertanya, batang atas = yang jadi.
              // Ditumpuk, bukan berdampingan, supaya rasio keduanya langsung terbaca.
              . '<div style="position:relative;height:14px;background:var(--ivory-07);border-radius:4px;overflow:hidden">'
              . '<div style="position:absolute;inset:0 auto 0 0;width:' . $wt . '%;background:var(--ivory-12)"></div>'
              . '<div style="position:absolute;inset:0 auto 0 0;width:' . $wd . '%;background:var(--sage)"></div>'
              . '</div></div>';
    }
    return $out . '</div>';
}

/** Kelompokkan usia jadi rentang yang dipakai Meta / TikTok Ads. */
function rentangUsia(?int $u): ?string
{
    if ($u === null || $u < 17) return null;
    return match (true) {
        $u <= 24 => '18–24',
        $u <= 29 => '25–29',
        $u <= 34 => '30–34',
        $u <= 44 => '35–44',
        default  => '45+',
    };
}
