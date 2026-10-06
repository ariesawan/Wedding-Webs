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

/* ============================================================
   GRAFIK RINGKASAN OWNER (v24)
   ============================================================
   Aturan yang sama untuk ketiganya:
   - satu warna data (--grafik), deret kedua dibedakan bentuk (isi vs garis
     tepi / titik penuh vs cincin), bukan warna kedua — warna ember kedua
     gagal uji kontras di mode gelap;
   - viewBox tetap + preserveAspectRatio meet: teks tidak pernah melar;
   - setiap tanda punya <title> dan data-tip (dibaca skrip ke keterangan
     aria-live, karena layar sentuh tidak punya hover);
   - selalu ada padanan teks: kalimat ringkas + tabel di <details>. */

/** Batang dengan ujung atas membulat 4px, menapak di garis dasar. */
function grafikBatangPath(float $x, float $yAtas, float $w, float $yDasar, float $r = 4): string
{
    $h = $yDasar - $yAtas;
    if ($h <= 0) return '';
    $r = min($r, $h, $w / 2);
    return sprintf('M%.1f %.1fL%.1f %.1fQ%.1f %.1f %.1f %.1fL%.1f %.1fQ%.1f %.1f %.1f %.1fL%.1f %.1fZ',
        $x, $yDasar, $x, $yAtas + $r, $x, $yAtas, $x + $r, $yAtas,
        $x + $w - $r, $yAtas, $x + $w, $yAtas, $x + $w, $yAtas + $r, $x + $w, $yDasar);
}

/**
 * Arus kas 12 bulan: diterima (isi) di bulan lalu & berjalan, dijadwalkan
 * (garis tepi) di bulan berjalan & ke depan.
 * $slot: [['bln','label','tahun','masuk','jadwal','nMasuk','nJadwal','kini','depan'], …]
 */
function grafikKas(array $slot, string $judul = 'Arus kas'): string
{
    $maks = 0.0;
    foreach ($slot as $s) $maks = max($maks, $s['masuk'] + ($s['kini'] || $s['depan'] ? $s['jadwal'] : 0));
    if ($maks <= 0) return '';

    $W = 360; $H = 176; $kiri = 6; $kanan = 354; $atas = 34; $dasar = 146;
    $n = count($slot); $lebarSlot = ($kanan - $kiri) / $n; $wb = 16;
    $skala = fn(float $v) => $v / $maks * ($dasar - $atas);
    $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" width="100%" preserveAspectRatio="xMidYMid meet" role="img" aria-label="'
         . e($judul) . '" class="grafik-svg">';
    foreach ([0.5, 1.0] as $g) {
        $y = round($dasar - $g * ($dasar - $atas), 1);
        $svg .= '<line x1="' . $kiri . '" y1="' . $y . '" x2="' . $kanan . '" y2="' . $y . '" class="g-garis"/>'
              . '<text x="' . $kiri . '" y="' . ($y - 3) . '" class="g-sumbu">' . e(rupiah($maks * $g, true)) . '</text>';
    }
    $svg .= '<line x1="' . $kiri . '" y1="' . $dasar . '" x2="' . $kanan . '" y2="' . $dasar . '" class="g-dasar"/>';

    $labelNilai = [];
    $iMaks = null; $vMaks = -1;
    foreach ($slot as $i => $s) {
        $tot = $s['masuk'] + ($s['kini'] || $s['depan'] ? $s['jadwal'] : 0);
        if ($tot > $vMaks) { $vMaks = $tot; $iMaks = $i; }
    }
    foreach ($slot as $i => $s) {
        $cx = $kiri + $lebarSlot * $i + $lebarSlot / 2;
        $x = $cx - $wb / 2;
        $masuk = $s['masuk'];
        $jadwal = ($s['kini'] || $s['depan']) ? $s['jadwal'] : 0.0;
        $yMasuk = $dasar - $skala($masuk);
        if ($masuk > 0) {
            // Bulan berjalan dengan sisa jadwal di atasnya: ujung atas bagian
            // bawah tidak dibulatkan — yang membulat hanya ujung tumpukan.
            $r = $jadwal > 0 ? 0 : 4;
            $svg .= '<path d="' . grafikBatangPath($x, $yMasuk, $wb, $dasar, $r) . '" class="g-isi"/>';
        }
        if ($jadwal > 0) {
            $alas = $masuk > 0 ? $yMasuk - 2 : $dasar;
            $yJ = $alas - $skala($jadwal);
            if ($alas - $yJ < 2) $yJ = $alas - 2;
            $svg .= '<path d="' . grafikBatangPath($x + 0.75, $yJ, $wb - 1.5, $alas) . '" class="g-garis-tepi"/>';
        }
        $tot = $masuk + $jadwal;
        if ($tot <= 0) {
            $svg .= '<text x="' . round($cx, 1) . '" y="' . ($dasar - 4) . '" text-anchor="middle" class="g-kosong">—</text>';
        } elseif ($s['kini'] || $i === $iMaks) {
            $yTop = $dasar - $skala($tot) - ($masuk > 0 && $jadwal > 0 ? 2 : 0);
            $svg .= '<text x="' . round($cx, 1) . '" y="' . round(max(24, $yTop - 4), 1) . '" text-anchor="middle" class="g-nilai">'
                  . e(rupiah($tot, true)) . '</text>';
        }
        $svg .= '<text x="' . round($cx, 1) . '" y="' . ($dasar + 15) . '" text-anchor="middle" class="g-bulan' . ($s['kini'] ? ' kini' : '') . '">'
              . e($s['label'] . ($s['kini'] ? '*' : '')) . '</text>';
        if ($s['kini']) {
            $xg = round($kiri + $lebarSlot * ($i + 1), 1);
            // Label "ke depan →" di pita paling atas, di atas label nilai batang.
            $svg .= '<line x1="' . $xg . '" y1="2" x2="' . $xg . '" y2="' . ($dasar + 4) . '" class="g-batas"/>'
                  . '<text x="' . ($xg + 4) . '" y="10" class="g-sumbu">ke depan →</text>';
        }
        $tip = $s['label'] . ' ' . $s['tahun'] . ' · '
             . implode(' · ', array_filter([
                 $masuk > 0 ? 'diterima ' . rupiah($masuk, true) . ' (' . $s['nMasuk'] . ' klien)' : '',
                 $jadwal > 0 ? 'dijadwalkan ' . rupiah($jadwal, true) . ' (' . $s['nJadwal'] . ' termin)' : '',
             ]) ?: ['tidak ada']);
        $svg .= '<rect x="' . round($kiri + $lebarSlot * $i, 1) . '" y="0" width="' . round($lebarSlot, 1) . '" height="' . $H
              . '" class="g-hit" data-tip="' . e($tip) . '" tabindex="0"><title>' . e($tip) . '</title></rect>';
    }
    return $svg . '</svg>';
}

/**
 * Tanggal terisi 6 bulan: satu titik per acara, ditumpuk dari bawah.
 * Penuh = DP masuk; cincin = menunggu DP.
 */
function grafikTanggal(array $rows, string $judul = 'Tanggal terisi'): string
{
    $bulan = [];
    $awal = strtotime(date('Y-m-01'));
    for ($i = 0; $i < 6; $i++) {
        $t = strtotime('+' . $i . ' month', $awal);
        $bulan[date('Y-m', $t)] = ['label' => BULAN_PENDEK[(int) date('n', $t)] ?? date('M', $t), 'b' => (int) date('n', $t),
                                   'y' => (int) date('Y', $t), 'isi' => []];
    }
    foreach ($rows as $r) {
        $k = substr((string) $r['wedding_date'], 0, 7);
        if (isset($bulan[$k])) $bulan[$k]['isi'][] = $r;
    }
    $maks = max(3, ...array_map(fn($b) => count($b['isi']), array_values($bulan)));
    // Lebar sengaja kecil: grafik ini tinggal di kolom samping (~260px),
    // supaya titik 10px tetap 10px di layar, bukan mengecil jadi 7px.
    $W = 264; $jarak = 15; $atas = 10; $dasar = $atas + $maks * $jarak; $H = $dasar + 34;
    $lebar = $W / 6;
    $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" width="100%" preserveAspectRatio="xMidYMid meet" role="img" aria-label="'
         . e($judul) . '" class="grafik-svg">';
    $svg .= '<line x1="4" y1="' . ($dasar + 4) . '" x2="' . ($W - 4) . '" y2="' . ($dasar + 4) . '" class="g-dasar"/>';
    $i = 0;
    foreach ($bulan as $b) {
        $cx = $lebar * $i + $lebar / 2;
        foreach ($b['isi'] as $j => $r) {
            $cy = $dasar - 4 - $j * $jarak;
            $tunggu = $r['stage'] === 'dp';
            $tip = namaPasangan($r) . ' · ' . tglPendek($r['wedding_date']) . ($r['lokasi'] ? ' · ' . $r['lokasi'] : '')
                 . ($tunggu ? ' · menunggu DP' : ' · DP masuk');
            $svg .= '<a href="klien.php?id=' . (int) $r['id'] . '" class="g-titik-a">'
                  . '<rect x="' . round($cx - 14, 1) . '" y="' . round($cy - 7, 1) . '" width="28" height="14" class="g-hit" data-tip="' . e($tip) . '"/>'
                  . '<circle cx="' . round($cx, 1) . '" cy="' . round($cy, 1) . '" r="' . ($tunggu ? 4.25 : 5) . '" class="' . ($tunggu ? 'g-cincin' : 'g-titik') . '">'
                  . '<title>' . e($tip) . '</title></circle></a>';
        }
        $svg .= '<a href="klien.php?b=' . $b['b'] . '&amp;y=' . $b['y'] . '">'
              . '<text x="' . round($cx, 1) . '" y="' . ($dasar + 18) . '" text-anchor="middle" class="g-bulan">' . e($b['label']) . '</text></a>'
              . '<text x="' . round($cx, 1) . '" y="' . ($dasar + 30) . '" text-anchor="middle" class="g-sumbu">' . count($b['isi']) . '</text>';
        $i++;
    }
    return $svg . '</svg>';
}

/**
 * Baris batang HTML untuk corong. $baris: [['label','n','dasar','sub','href','catatan'], …]
 * Lebar batang = bagian dari baris pertama. Batang aria-hidden — angkanya teks.
 */
function grafikBaris(array $baris): string
{
    if (!$baris) return '';
    $dasar = max(1, (int) $baris[0]['n']);
    $out = '<ol class="corong-baris">';
    foreach ($baris as $b) {
        $w = round(min(100, (int) $b['n'] / $dasar * 100), 1);
        $out .= '<li><div class="cb-atas"><span class="cb-label">' . e($b['label']) . '</span>'
              . '<span class="cb-n">' . (int) $b['n'] . '</span>'
              . ($b['rasio'] !== '' ? '<span class="cb-rasio">' . e($b['rasio']) . '</span>' : '') . '</div>'
              . '<div class="cb-jalur" aria-hidden="true"><i style="width:' . $w . '%"></i></div>'
              . ($b['catatan'] !== '' ? '<div class="cb-catat">' . $b['catatan'] . '</div>' : '')
              . '</li>';
    }
    return $out . '</ol>';
}
