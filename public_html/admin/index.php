<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/ringkasan.php';
require_once __DIR__ . '/../inc/grafik.php';
require_once __DIR__ . '/../inc/google.php';
require_once __DIR__ . '/../inc/zoom.php';
$user = requireLogin();

/**
 * RINGKASAN — layar pertama setelah masuk, berbeda per peran.
 *
 *   admin early  : antrean "kerjakan dari atas" dari prospek sampai DP
 *   admin office : hari-H berikutnya + antrean setelah DP
 *   owner        : angka bulan ini, keputusan, antrean tim, kas, corong
 *
 * Owner bisa melihat persis antrean tim lewat ?lihat=early|office (tanpa
 * cookie; setiap aksi kembali ke pratinjau itu). Semua logika ada di
 * inc/ringkasan.php; berkas ini hanya merender.
 */
$peran = $user['role'] ?? '';
$lihat = $peran === 'owner' && in_array($_GET['lihat'] ?? '', ['early', 'office'], true) ? $_GET['lihat'] : '';
$mode  = match (true) {
    $peran === 'admin_early'                          => 'early',
    in_array($peran, ['admin_office', 'editor'], true) => 'office',
    default                                           => $lihat ?: 'owner',
};
$KEMBALI = $lihat ? 'ringkasan-' . $lihat : 'ringkasan';
$SARING  = preg_replace('/[^a-z]/', '', (string) ($_GET['saring'] ?? ''));
$SARING_URL = $lihat ? '?lihat=' . $lihat . '&saring=' : '?saring=';
$GATEWAY = waSiap();
$waUrl = (string) ($_SESSION['wa_url'] ?? '');
unset($_SESSION['wa_url']);

/* ============================================================
   PEMBANTU RENDER
   ============================================================ */

/** Formulir satu tombol: POST ke handler yang sudah ada, kembali ke Ringkasan. */
function tombolPost(array $a, string $jangkar, string $kelas = ''): string
{
    global $KEMBALI;
    $h = '<form method="post" action="' . e($a['action']) . '" class="aksi-form' . (!empty($a['utama']) ? ' utama' : '') . '" data-sekali'
       . (!empty($a['konfirmasi']) ? ' data-confirm="' . e($a['konfirmasi']) . '"' : '') . '>' . csrfField();
    foreach ($a['field'] as $k => $v) $h .= '<input type="hidden" name="' . e((string) $k) . '" value="' . e((string) $v) . '">';
    $h .= '<input type="hidden" name="kembali" value="' . e($KEMBALI) . '"><input type="hidden" name="jangkar" value="' . e($jangkar) . '">'
        . '<button type="submit" class="btn sm' . (!empty($a['utama']) ? ' solid' : '') . ($kelas ? ' ' . $kelas : '') . '">' . e($a['label']) . '</button></form>';
    return $h;
}

/** Popover "DP masuk ✓": jumlah, tanggal, cara bayar — dicatat lewat dp_masuk (kwitansi + penjaga ganda). */
function popoverDp(array $a, string $jangkar): string
{
    global $KEMBALI, $GATEWAY;
    $h = '<details class="pop-dp"><summary class="btn sm solid">' . e($a['label']) . '</summary><div class="pop-isi">'
       . '<form method="post" action="klien.php" data-sekali data-confirm="' . e('DP Rp {jumlah} dari ' . $a['nama'] . ' sudah benar-benar masuk? Kalau DP lunas, klien langsung diserahkan ke admin office.') . '">'
       . csrfField()
       . '<input type="hidden" name="act" value="dp_masuk"><input type="hidden" name="id" value="' . (int) $a['client_id'] . '">'
       . '<input type="hidden" name="kembali" value="' . e($KEMBALI) . '"><input type="hidden" name="jangkar" value="' . e($jangkar) . '">'
       . '<div class="f"><span>Jumlah diterima</span><input type="text" name="jumlah" data-rp required aria-label="Jumlah diterima" value="' . (int) round($a['sisa']) . '"></div>'
       . '<div class="dua tetap"><label class="f"><span>Tanggal</span><input type="date" name="tanggal" required max="' . date('Y-m-d') . '" value="' . date('Y-m-d') . '"></label>'
       . '<label class="f"><span>Cara bayar</span><select name="metode">';
    foreach (BAYAR_METODE as $m) $h .= '<option>' . e($m) . '</option>';
    $h .= '</select></label></div>'
        . '<label class="check"><input type="checkbox" name="kirim_kwitansi" value="1"' . ($GATEWAY ? ' checked' : '') . '> Kirim kwitansi ke WhatsApp klien'
        . ($GATEWAY ? '' : ' <small>(gateway belum tersambung)</small>') . '</label>'
        . '<button type="submit" class="btn sm solid">Catat DP masuk</button>'
        . '<p class="hint">Sebagian atau dengan bukti transfer? <a href="klien.php?id=' . (int) $a['client_id'] . '#uang">Buka tab Pembayaran →</a></p>'
        . '</form></div></details>';
    return $h;
}

function renderAksi(array $a, string $jangkar): string
{
    return match ($a['jenis']) {
        'post' => tombolPost($a, $jangkar),
        'dp'   => popoverDp($a, $jangkar),
        'wa'   => '<a class="btn sm" target="_blank" rel="noopener" href="' . e($a['href']) . '">' . e($a['label']) . '</a>',
        default => '<a class="btn sm' . (!empty($a['utama']) ? ' solid' : ' ghost') . '" href="' . e($a['href']) . '">' . e($a['label']) . '</a>',
    };
}

/** Menu "Lainnya ▾": aksi sekunder + tunda (tidak pernah menyembunyikan uang yang lewat tempo). */
function menuLainnya(array $lain, ?array $tunda, string $jangkar): string
{
    if (!$lain && !$tunda) return '';
    $h = '<details class="lainnya"><summary class="btn sm ghost" aria-label="Aksi lainnya"><span class="pj">Lainnya ▾</span><span class="pd" aria-hidden="true">•••</span></summary><div class="lainnya-isi">';
    foreach ($lain as $a) $h .= renderAksi($a, $jangkar);
    if ($tunda) {
        $senin = date('Y-m-d', strtotime('next monday'));
        $h .= '<span class="lab">Tunda</span>';
        foreach ([['Sudah dihubungi · cek lagi 2 hari', date('Y-m-d', strtotime('+2 day'))],
                  ['Besok', date('Y-m-d', strtotime('+1 day'))],
                  ['Senin ' . tglPendek($senin, false), $senin]] as [$lbl, $tgl]) {
            $h .= tombolPost(['label' => $lbl, 'action' => 'klien.php', 'utama' => false, 'konfirmasi' => null,
                              'field' => ['act' => 'nextaction', 'id' => $tunda['id'], 'next_action' => $tunda['teks'], 'next_action_at' => $tgl]],
                             $jangkar, 'ghost');
        }
    }
    return $h . '</div></details>';
}

function chipHtml($c): string
{
    if (is_array($c)) return '<span class="chip ' . e($c['nada'] ?? '') . '">' . e($c['teks']) . '</span>';
    return '<span class="chip">' . e((string) $c) . '</span>';
}

/** Strip enam titik kesiapan + teks "4/6 siap" (titik tidak pernah jadi satu-satunya sinyal). */
function titikSiap(array $siap): string
{
    $h = '<span class="siap-titik" aria-hidden="true">';
    foreach ($siap as $s) $h .= '<i class="' . ($s['ok'] ? 'ok' : '') . '" title="' . e(ucfirst($s['label']) . ($s['ok'] ? ': ada' : ': belum')) . '"></i>';
    return $h . '</span><span class="siap-teks">' . kesiapanJumlah($siap) . '/6 siap</span>';
}

function barisAntrean(array $it, string $jangkarBerikut, string $saringAktif): string
{
    $tersembunyi = $saringAktif !== '' && !in_array($saringAktif, $it['saring'], true);
    $h = '<li id="q-' . e($it['kunci']) . '" class="q t' . (int) $it['tier'] . '" data-saring="' . e(implode(' ', $it['saring'])) . '"'
       . ($tersembunyi ? ' hidden' : '') . '>'
       . '<div class="q-kepala"><a class="q-nama" href="' . e($it['href']) . '">' . e($it['nama']) . '</a>'
       . '<span class="q-kapan' . ($it['kapan']['telat'] ? ' telat' : '') . '">' . e($it['kapan']['teks']) . '</span></div>'
       . '<p class="q-alasan">' . e($it['alasan']) . '</p>';
    if ($it['chip'] || $it['titik']) {
        $h .= '<div class="q-chip">' . ($it['titik'] ? titikSiap($it['titik']) : '') . implode('', array_map('chipHtml', $it['chip'])) . '</div>';
    }
    $aksi = array_slice($it['aksi'], 0, 2);
    $lebih = array_merge(array_slice($it['aksi'], 2), $it['lain']);
    $h .= '<div class="q-aksi">';
    foreach ($aksi as $a) $h .= renderAksi($a, $jangkarBerikut);
    $h .= menuLainnya($lebih, $it['tunda'], $jangkarBerikut) . '</div></li>';
    return $h;
}

const TIER_LABEL = [1 => 'Mendesak', 2 => 'Hari ini', 3 => 'Segera'];

/** Daftar antrean bertingkat; lebih dari RINGKASAN_MAKS baris masuk <details>. */
function daftarAntrean(array $rows, string $saringAktif, bool $denganTier = true, string $jangkarTetap = ''): string
{
    $hitung = [1 => 0, 2 => 0, 3 => 0];
    foreach ($rows as $r) $hitung[$r['tier']]++;
    $out = ''; $tier = 0; $buka = '<ol class="antrean">';
    $out .= $buka;
    foreach ($rows as $i => $r) {
        if ($i === RINGKASAN_MAKS) {
            $out .= '</ol><details class="antrean-lebih"><summary>Tampilkan ' . (count($rows) - RINGKASAN_MAKS) . ' lainnya</summary>' . $buka;
        }
        if ($denganTier && $r['tier'] !== $tier) {
            $tier = $r['tier'];
            $out .= '<li class="tier-lab t' . $tier . '" data-tier="' . $tier . '">' . TIER_LABEL[$tier] . ' <b>' . $hitung[$tier] . '</b></li>';
        }
        $berikut = $jangkarTetap !== '' ? $jangkarTetap : 'q-' . ($rows[$i + 1]['kunci'] ?? $r['kunci']);
        $out .= barisAntrean($r, $berikut, $saringAktif);
    }
    $out .= '</ol>' . (count($rows) > RINGKASAN_MAKS ? '</details>' : '');
    return $out;
}

/** Chip saringan: tautan biasa tanpa JS (?saring=…), disaring di tempat dengan JS. */
function chipSaring(array $def, array $jumlah, int $semua, string $aktif): string
{
    global $lihat;
    $dasar = $lihat ? '?lihat=' . $lihat . '&saring=' : '?saring=';
    $h = '<nav class="saring" aria-label="Saring antrean">'
       . '<a href="' . e($lihat ? '?lihat=' . $lihat : 'index.php') . '#antrean" data-saring="" aria-pressed="' . ($aktif === '' ? 'true' : 'false') . '">Semua <b>' . $semua . '</b></a>';
    foreach ($def as $k => $lbl) {
        $n = (int) ($jumlah[$k] ?? 0);
        $h .= '<a href="' . e($dasar . $k) . '#antrean" data-saring="' . e($k) . '" aria-pressed="' . ($aktif === $k ? 'true' : 'false') . '"'
            . ($n ? '' : ' class="nol"') . '>' . e($lbl) . ' <b>' . $n . '</b></a>';
    }
    return $h . '</nav><p class="saring-kosong" hidden>Tidak ada di bagian ini.</p>';
}

/** Ubin angka: label mono, nilai serif, sub-baris, delta dengan glyph. */
function statTile(array $s): string
{
    $tag = !empty($s['href']) ? 'a' : 'div';
    $h = '<' . $tag . ' class="stat kpi' . (!empty($s['nada']) ? ' ' . $s['nada'] : '') . '"' . ($tag === 'a' ? ' href="' . e($s['href']) . '"' : '') . '>'
       . '<span class="d">' . e($s['label']) . '</span>'
       . '<span class="n">' . (!empty($s['glyph']) ? '<i aria-hidden="true">' . e($s['glyph']) . '</i> ' : '') . e((string) $s['nilai']) . '</span>';
    if (!empty($s['sub'])) $h .= '<span class="s">' . e($s['sub']) . '</span>';
    if (!empty($s['delta'])) $h .= '<span class="delta ' . e($s['delta']['kelas']) . '">' . e($s['delta']['teks']) . '</span>';
    return $h . '</' . $tag . '>';
}

/** Meter rasio: nilai selalu ikut tertulis; jalur = versi muda dari warna isinya. */
function meter(float $isi, float $total, string $nada, string $label, string $teks): string
{
    $p = $total > 0 ? max(0, min(100, round($isi / $total * 100))) : 0;
    return '<div class="meter-baris"><div class="meter ' . e($nada) . '" role="meter" aria-valuemin="0" aria-valuemax="' . (int) round($total)
         . '" aria-valuenow="' . (int) round($isi) . '" aria-label="' . e($label) . '"><i style="width:' . $p . '%"></i></div>'
         . '<span class="meter-teks">' . $teks . '</span></div>';
}

function kosong(string $judul, string $teks = '', string $aksi = ''): string
{
    return '<div class="kosong-r"><p>' . e($judul) . '</p>' . ($teks ? '<span>' . e($teks) . '</span>' : '') . ($aksi ? '<div class="aksi">' . $aksi . '</div>' : '') . '</div>';
}

function galatBagian(): string
{
    return '<p class="galat-bagian">Bagian ini gagal dimuat — muat ulang halaman. Bila berulang, buka menu Klien.</p>';
}

/** 'mulai 25 menit lagi' / 'sedang berlangsung' / 'selesai' — diperbarui skrip tiap menit. */
function relatifTemu(string $mulai, ?string $selesai): string
{
    $a = strtotime($mulai); $b = $selesai ? strtotime($selesai) : $a + 3600; $t = time();
    if ($t >= $b) return 'selesai';
    if ($t >= $a) return 'sedang berlangsung';
    $m = (int) ceil(($a - $t) / 60);
    if ($m <= 90) return 'mulai ' . $m . ' menit lagi';
    if (date('Y-m-d', $a) === date('Y-m-d')) return 'mulai ' . (int) round($m / 60) . ' jam lagi';
    return '';
}

function labelHariAgenda(string $tgl): string
{
    $d = hariKe($tgl);
    return ($d === 0 ? 'Hari ini · ' : ($d === 1 ? 'Besok · ' : '')) . tglPendek($tgl);
}

/** Satu entri agenda pertemuan (bentuk seragam untuk admin & owner). */
function agendaTemu(array $m, bool $tagTim = false): string
{
    $mulai = $m['waktu']; $selesai = $m['selesai'] ?? null;
    $tombol = [];
    $tautan = trim((string) ($m['tautan'] ?? ''));
    $lokasi = trim((string) ($m['lokasi'] ?? ''));
    $telp = waNomor($m['phone'] ?? '');
    switch ($m['ket']) {
        case 'zoom':   if ($tautan !== '') $tombol[] = '<a class="btn sm solid" target="_blank" rel="noopener" href="' . e($tautan) . '">Gabung Zoom ↗</a>'
                                                 . (!empty($m['passcode']) ? '<span class="sandi mono" title="Kode sandi">' . e($m['passcode']) . '</span>' : ''); break;
        case 'meet':   if ($tautan !== '') $tombol[] = '<a class="btn sm solid" target="_blank" rel="noopener" href="' . e($tautan) . '">Gabung Meet ↗</a>'; break;
        case 'onsite': if ($lokasi !== '') $tombol[] = '<a class="btn sm" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&amp;query=' . rawurlencode($lokasi) . '">Peta ↗</a>'; break;
        case 'phone':  if ($telp !== '') $tombol[] = '<a class="btn sm solid" href="tel:+' . e($telp) . '">Telepon</a>'; break;
    }
    if (!empty($m['teksIngat']) && $telp !== '' && strtotime($mulai) - time() < 86400 && strtotime($mulai) > time()) {
        $tombol[] = '<a class="btn sm ghost" target="_blank" rel="noopener" href="' . e(waTautan($telp, $m['teksIngat'])) . '">Ingatkan via WA ↗</a>';
    }
    $rel = relatifTemu($mulai, $selesai);
    return '<li class="ag' . (hariKe($mulai) === 0 ? ' kini' : '') . '">'
         . '<span class="ag-jam mono">' . e(jamTeks($mulai) . ($selesai ? '–' . jamTeks($selesai) : '')) . '</span>'
         . '<div class="ag-isi"><a class="ag-nama" href="jadwal.php?edit=' . (int) $m['ref_id'] . '">' . e(trim((string) $m['nama']) ?: 'Pertemuan') . '</a>'
         . '<span class="ag-ket">' . e(modeTemu((string) $m['ket']))
         . ($tagTim && !empty($m['tim']) ? ' · <b class="mono">' . e(mb_strtoupper(str_replace('admin_', '', (string) $m['tim']))) . '</b>' : '')
         . '<time data-mulai="' . e(date('c', strtotime($mulai))) . '" data-selesai="' . e(date('c', strtotime($selesai ?: $mulai) + ($selesai ? 0 : 3600))) . '">' . e($rel) . '</time></span>'
         . (!empty($m['sync_error']) ? '<a class="ag-galat" href="jadwal.php?edit=' . (int) $m['ref_id'] . '">Sinkron gagal →</a>' : '')
         . '</div>'
         . ($tombol ? '<div class="ag-aksi">' . implode('', $tombol) . '</div>' : '')
         . '</li>';
}

function agendaTermin(array $t): string
{
    return '<li class="ag termin"><span class="ag-jam mono">tagihan</span><div class="ag-isi">'
         . '<a class="ag-nama" href="klien.php?id=' . (int) $t['client_id'] . '#uang">' . e($t['nama']) . '</a>'
         . '<span class="ag-ket">' . ((int) $t['n'] > 1 ? (int) $t['n'] . ' termin · ' : '') . e(rupiah((float) $t['rp'], true)) . ' jatuh tempo</span></div></li>';
}

/** Agenda dikelompokkan per hari. $entri: ['tanggal' => Y-m-d, 'html' => …] */
function agendaHari(array $entri): string
{
    if (!$entri) return '';
    usort($entri, fn($a, $b) => strcmp($a['urut'], $b['urut']));
    $out = '<div class="agenda">'; $hari = '';
    foreach ($entri as $x) {
        if ($x['tanggal'] !== $hari) {
            if ($hari !== '') $out .= '</ul>';
            $hari = $x['tanggal'];
            $out .= '<h3 class="ag-hari">' . e(labelHariAgenda($hari)) . '</h3><ul class="ag-daftar">';
        }
        $out .= $x['html'];
    }
    return $out . '</ul></div>';
}

/** Ubah baris meetings (admin) ke bentuk agenda. */
function agendaDariTemu(array $m): array
{
    return ['ref_id' => $m['id'], 'client_id' => $m['client_id'], 'nama' => $m['client_name'] ?: $m['title'],
            'waktu' => $m['start_at'], 'selesai' => $m['end_at'], 'ket' => $m['mode'],
            'tautan' => $m['mode'] === 'zoom' ? $m['zoom_join_url'] : ($m['mode'] === 'meet' ? $m['meet_url'] : ''),
            'passcode' => $m['zoom_passcode'], 'lokasi' => $m['location_text'], 'phone' => $m['c_phone'] ?: $m['client_phone'],
            'sync_error' => $m['sync_error'], 'teksIngat' => teksPengingatTemu($m)];
}

/** Daftar teks "Tanggal terisi" per bulan (padanan teks grafik owner). */
function daftarTanggal(array $rows): string
{
    $per = [];
    foreach ($rows as $r) $per[substr($r['wedding_date'], 0, 7)][] = $r;
    $bentrok = tanggalBentrok($rows);
    $h = '<dl class="tgl-daftar">';
    foreach ($per as $bln => $isi) {
        $h .= '<dt>' . e(BULAN_PENDEK[(int) substr($bln, 5, 2)] . ' ' . substr($bln, 0, 4)) . '</dt><dd>';
        foreach ($isi as $r) {
            $h .= '<a href="klien.php?id=' . (int) $r['id'] . '"' . (isset($bentrok[$r['wedding_date']]) ? ' class="bentrok"' : '') . '>'
                . '<b class="mono">' . e(tglPendek($r['wedding_date'])) . '</b> ' . e(namaPasangan($r))
                . ($r['stage'] === 'dp' ? ' <span class="tunggu">○ menunggu DP</span>' : '') . '</a>';
        }
        $h .= '</dd>';
    }
    $h .= '</dl>';
    foreach ($bentrok as $tgl => $n) $h .= '<p class="bentrok-baris">! ' . $n . ' acara di ' . e(tglPendek($tgl)) . ' — cek kru</p>';
    return $h;
}

$aksiAtas = ($mode === 'office' ? '' : '<a class="btn solid" href="klien.php?new=1">+ Klien baru</a> ')
          . '<a class="btn ghost" href="jadwal.php?new=1">+ Jadwalkan</a>';

adminHead('Ringkasan', '');
pageHead('Selamat datang, ' . explode(' ', $user['name'])[0],
         hariID('now') . ', ' . tanggalID('now') . ' · ' . roleLabel($peran)
         . ($mode === 'early' && !$lihat ? ' — prospek sampai DP masuk.' : ($mode === 'office' && !$lihat ? ' — klien setelah DP masuk.' : '.')),
         $aksiAtas);

if ($lihat): ?>
  <div class="lihat-banner">Kamu melihat antrean <b>Admin <?= $lihat ?></b> sebagai owner — tombol di bawah bekerja dengan hakmu.
    <a href="index.php">← Kembali ke ringkasan owner</a></div>
<?php endif;
if ($waUrl !== ''): ?>
  <div class="lihat-banner"><span>Pesan belum terkirim otomatis.</span> <a class="btn sm solid" target="_blank" rel="noopener" href="<?= e($waUrl) ?>">Buka WhatsApp ↗</a></div>
<?php endif;

/* ============================================================
   ADMIN EARLY
   ============================================================ */
if ($mode === 'early'):
    $R = ringkasanEarly();
    $q = $R['antrean'];
    $temuDepan = $R['agenda'][0] ?? null;
    $kepala = [];
    if (!isset($R['galat']['antrean'])) {
        if ($q) {
            $kepala[] = count($q) . ' di antrean';
            if ($R['tier'][1]) $kepala[] = '<a class="mendesak" href="#antrean">' . $R['tier'][1] . ' mendesak</a>';
        }
        if ($temuDepan) {
            $d = hariKe($temuDepan['start_at']);
            $kepala[] = 'konsultasi berikutnya ' . e(($d === 0 ? 'hari ini' : ($d === 1 ? 'besok' : tglPendek($temuDepan['start_at']))) . ' ' . jamTeks($temuDepan['start_at']))
                      . ' (' . e($temuDepan['client_name'] ?: $temuDepan['title']) . ')';
        }
    }
    ?>
  <p class="kepala-r"><?= $kepala ? implode(' · ', $kepala) : 'Antrean bersih · belum ada konsultasi 7 hari ke depan.' ?></p>

  <div class="grid dash">
    <section class="card antrean-kartu" id="antrean">
      <h2>Antrean — kerjakan dari atas</h2>
      <?php if (isset($R['galat']['antrean'])): ?>
        <?= galatBagian() ?>
      <?php elseif (!$R['nKlien'] && !$q): ?>
        <?= kosong('Belum ada prospek aktif', 'Kiriman formulir situs, DM Instagram, dan WhatsApp yang dicatat akan muncul di sini — yang paling mendesak di atas.',
                   '<a class="btn sm solid" href="klien.php?new=1">+ Klien baru</a> <a class="btn sm ghost" href="formulir.php">Formulir masuk</a>') ?>
      <?php elseif (!$q): ?>
        <?= kosong('Antrean bersih', 'Semua prospek punya langkah dengan tanggal di depan.', '<a class="btn sm solid" href="klien.php?new=1">+ Klien baru</a> <a class="btn sm ghost" href="formulir.php">Formulir masuk</a>') ?>
        <?php if ($R['berikutnya']): $b = $R['berikutnya']; ?>
          <p class="berikutnya">Berikutnya: <a href="klien.php?id=<?= $b['id'] ?>"><?= e($b['nama']) ?></a> — <?= e($b['teks']) ?>, <?= e(tglPendek($b['tanggal'])) ?> (<?= e(labelTempo($b['tanggal'])) ?>)</p>
        <?php endif; ?>
      <?php else: ?>
        <?= chipSaring(['formulir' => 'Formulir', 'baru' => 'Prospek baru', 'pricelist' => 'Price list', 'dp' => 'Menunggu DP'], $R['saring'], count($q), $SARING) ?>
        <?= daftarAntrean($q, $SARING) ?>
      <?php endif; ?>
    </section>

    <div class="dash-samping">
      <section class="card">
        <h2>Agenda 7 hari</h2>
        <?php $ent = [];
        foreach ($R['agenda'] as $m) $ent[] = ['tanggal' => substr($m['start_at'], 0, 10), 'urut' => $m['start_at'], 'html' => agendaTemu(agendaDariTemu($m))];
        echo $ent ? agendaHari($ent) : kosong('Tidak ada konsultasi 7 hari ke depan.', '', '<a class="btn sm solid" href="jadwal.php?new=1">Jadwalkan konsultasi</a>'); ?>
      </section>

      <details class="card tgl-kartu" data-buka-lebar>
        <summary><h2>Tanggal terisi</h2> <span class="lab"><?= isset($R['galat']['tanggal']) ? '' : '6 bulan · ' . count($R['tanggal']) . ' acara' ?></span></summary>
        <?php if (isset($R['galat']['tanggal'])): echo galatBagian();
        elseif (!$R['tanggal']): ?>
          <p class="sub" style="margin:10px 0 0">Belum ada tanggal terisi 6 bulan ke depan — semua tanggal masih bisa ditawarkan.</p>
        <?php else: ?>
          <?= daftarTanggal($R['tanggal']) ?>
          <p class="hint">Yang tercantum hanya klien yang sudah DP atau sedang menunggu DP. <a href="klien.php?b=<?= date('n') ?>&amp;y=<?= date('Y') ?>">Kalender →</a></p>
        <?php endif; ?>
      </details>

      <section class="angka-r" aria-label="Angka">
        <?php if (isset($R['galat']['angka'])): echo galatBagian(); else: $A = $R['angka']; ?>
          <div class="stat-baris dua-dua">
            <?= statTile(['label' => 'Prospek aktif', 'nilai' => $A['aktif'], 'href' => 'klien.php',
                          'sub' => $A['n']['baru'] . ' baru · ' . $A['n']['pricelist'] . ' price list · ' . $A['n']['dp'] . ' DP']) ?>
            <?= statTile(['label' => 'Menunggu DP', 'nilai' => $A['dpSisa'] > 0 ? rupiah($A['dpSisa'], true) : '—', 'href' => $SARING_URL . 'dp#antrean',
                          'sub' => $A['dpKlien'] ? $A['dpKlien'] . ' klien' : 'belum ada tagihan DP']) ?>
            <?= statTile(['label' => 'DP masuk bulan ini', 'nilai' => $A['dpBln'], 'href' => 'klien.php?tahap=deal',
                          'delta' => deltaTeks($A['dpBln'], $A['dpLalu'])]) ?>
            <?= statTile(['label' => 'Price list ≤ 24 jam', 'href' => 'klien.php?tahap=baru',
                          'nilai' => $A['formN'] >= 3 ? $A['formCepat'] . ' dari ' . $A['formN'] : '—',
                          'sub' => $A['formN'] >= 3 ? 'prospek formulir, 90 hari' : 'belum cukup data (perlu ≥ 3 prospek formulir)']) ?>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </div>

<?php
/* ============================================================
   ADMIN OFFICE
   ============================================================ */
elseif ($mode === 'office'):
    $R = ringkasanOffice();
    $q = $R['antrean'];
    $hero = $R['hero'];
    $heroAtas = $hero && (int) $hero['hari_ke_h'] <= 14;
    $kepala = [];
    if ($R['nKlien']) $kepala[] = $R['nKlien'] . ' acara berjalan';
    if ($hero) $kepala[] = 'hari-H berikutnya ' . ((int) $hero['hari_ke_h'] === 0 ? 'hari ini' : 'H-' . (int) $hero['hari_ke_h']) . ' (' . e(namaPasangan($hero)) . ')';
    if ($q) $kepala[] = count($q) . ' di antrean' . ($R['tier'][1] ? ', <a class="mendesak" href="#antrean">' . $R['tier'][1] . ' mendesak</a>' : '');

    $kartuHero = function () use ($hero): string {
        if (!$hero) return '';
        $hk = (int) $hero['hari_ke_h'];
        $tot = (float) $hero['tagihan_total']; $msk = (float) $hero['tagihan_masuk'];
        $sisa = max(0, $tot - $msk);
        $lunasPct = $tot > 0 ? (int) floor($msk / $tot * 100) : 0;
        $merah = $hk <= 14 && $sisa > 0.5;
        $lok = trim((string) $hero['lokasi']);
        $h = '<section class="card hero-harih"><div class="hh-kepala"><span class="lab">Hari-H berikutnya</span>'
           . '<span class="hh-h">' . ($hk === 0 ? 'Hari ini' : 'H-' . $hk) . '</span></div>'
           . '<a class="hh-nama" href="klien.php?id=' . (int) $hero['id'] . '">' . e(namaPasangan($hero)) . '</a>'
           . '<p class="hh-ket">' . e(tglPendek($hero['wedding_date']) . ($hero['wedding_time'] ? ' · ' . jamTeks($hero['wedding_date'] . ' ' . $hero['wedding_time']) : '') . ($lok !== '' ? ' · ' . $lok : ' · venue belum diisi')) . '</p>';
        if ((int) $hero['n_tugas']) {
            $h .= meter((float) $hero['n_tugas_selesai'], (float) $hero['n_tugas'], 'sage', 'Checklist',
                        'Checklist ' . (int) $hero['n_tugas_selesai'] . '/' . (int) $hero['n_tugas'] . ((int) $hero['n_tugas_telat'] ? ' · <b class="merah">' . (int) $hero['n_tugas_telat'] . ' lewat</b>' : ''));
        } else {
            $h .= '<p class="hint">Checklist belum dibuat.</p>';
        }
        if ($tot > 0) {
            $h .= '<a class="meter-link" href="klien.php?id=' . (int) $hero['id'] . '#uang">' . meter($msk, $tot, $merah ? 'rose' : 'sage', 'Pembayaran',
                        'Pembayaran ' . $lunasPct . '%' . ($sisa > 0.5 ? ' · ' . ($merah ? '<b class="merah">' : '') . e(rupiah($sisa, true)) . ' belum lunas' . ($merah ? '</b>' : '') : ' · lunas')) . '</a>';
        }
        if ($hero['stage'] === 'deal') {
            $kurang = array_filter(kesiapanDeal($hero), fn($s) => !$s['ok']);
            if ($kurang) {
                $h .= '<div class="q-chip">';
                foreach ($kurang as $s) $h .= '<a class="chip rose" href="' . e($s['href']) . '">' . e(ucfirst($s['label'])) . ' kosong</a>';
                $h .= '</div>';
            }
        }
        $wa = waTautan((string) $hero['phone']);
        $h .= '<div class="hh-aksi"><a class="btn solid" href="klien.php?id=' . (int) $hero['id'] . '#checklist">Checklist →</a>'
            . ($lok !== '' ? '<a class="btn" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&amp;query=' . rawurlencode($lok . ($hero['city'] ? ', ' . $hero['city'] : '')) . '">Peta ↗</a>' : '')
            . ($wa !== '' ? '<a class="btn" target="_blank" rel="noopener" href="' . e($wa) . '">WA ↗</a>' : '') . '</div></section>';
        return $h;
    };
    ?>
  <p class="kepala-r"><?= $kepala ? implode(' · ', $kepala) : 'Belum ada acara berjalan.' ?></p>

  <div class="grid dash susun">
    <div class="kolom">
      <?php if ($heroAtas): ?><div class="u1"><?= $kartuHero() ?></div><?php endif; ?>
      <section class="card antrean-kartu u2" id="antrean">
        <h2>Antrean — kerjakan dari atas</h2>
        <?php if (isset($R['galat']['antrean'])): ?>
          <?= galatBagian() ?>
        <?php elseif (!$R['nKlien'] && !$q): ?>
          <?= kosong('Belum ada klien yang DP', 'Begitu admin early mengonfirmasi DP, klien muncul di sini lengkap dengan daftar penyusunannya.') ?>
        <?php elseif (!$q): ?>
          <?= kosong('Semua acara pada jalurnya') ?>
          <?php if ($R['berikutnya']): $b = $R['berikutnya']; ?>
            <p class="berikutnya">Berikutnya: <a href="klien.php?id=<?= $b['id'] ?>"><?= e($b['nama']) ?></a> — <?= e($b['teks']) ?>, <?= e(tglPendek($b['tanggal'])) ?></p>
          <?php endif; ?>
        <?php else: ?>
          <?= chipSaring(['tagihan' => 'Tagihan', 'checklist' => 'Checklist', 'deal' => 'Deal', 'harih' => 'Hari-H 30 hari'], $R['saring'], count($q), $SARING) ?>
          <?= daftarAntrean($q, $SARING) ?>
        <?php endif; ?>
      </section>
    </div>

    <div class="kolom dash-samping">
      <?php if ($hero && !$heroAtas): ?><div class="u1"><?= $kartuHero() ?></div><?php endif; ?>
      <section class="card u3">
        <h2>Agenda 7 hari</h2>
        <?php $ent = [];
        foreach ($R['agenda'] as $m) $ent[] = ['tanggal' => substr($m['start_at'], 0, 10), 'urut' => $m['start_at'], 'html' => agendaTemu(agendaDariTemu($m))];
        foreach ($R['terminAgenda'] as $t) $ent[] = ['tanggal' => $t['tanggal'], 'urut' => $t['tanggal'] . ' 23:59', 'html' => agendaTermin($t)];
        echo $ent ? agendaHari($ent) : kosong('Tidak ada meeting 7 hari ke depan.', '', '<a class="btn sm solid" href="jadwal.php?new=1">Jadwalkan</a>'); ?>
      </section>

      <?php if ($R['nKlien']): ?>
      <section class="card u4">
        <h2>Hari-H 30 hari</h2>
        <?php if (!$R['harih30']): ?>
          <p class="sub" style="margin:0">Tidak ada hari-H lain dalam 30 hari.<?php if ($R['selanjutnya']): $s = $R['selanjutnya']; ?>
            Berikutnya: <a href="klien.php?id=<?= (int) $s['id'] ?>"><?= e(namaPasangan($s)) ?></a>, <?= e(tglPendek($s['wedding_date'], false)) ?> (H-<?= (int) $s['hari_ke_h'] ?>)<?php endif; ?></p>
        <?php else: ?>
          <ul class="hh-daftar">
            <?php foreach ($R['harih30'] as $c): $telat = (int) $c['n_tugas_telat']; ?>
              <li>
                <span class="mono hh-hk<?= $telat ? ' merah' : '' ?>">H-<?= (int) $c['hari_ke_h'] ?><?= $telat ? ' · lewat' : '' ?></span>
                <a href="klien.php?id=<?= (int) $c['id'] ?>#checklist"><b><?= e(namaPasangan($c)) ?></b><span><?= e($c['lokasi'] ?: 'venue belum diisi') ?></span></a>
                <?= (int) $c['n_tugas'] ? meter((float) $c['n_tugas_selesai'], (float) $c['n_tugas'], 'sage', 'Checklist ' . namaPasangan($c),
                       (int) $c['n_tugas_selesai'] . '/' . (int) $c['n_tugas'] . ($telat ? ' · <b class="merah">lewat ' . $telat . '</b>' : '')) : '<span class="hint">checklist belum dibuat</span>' ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <section class="angka-r u6" aria-label="Angka">
        <?php if (isset($R['galat']['angka'])): echo galatBagian(); else: $A = $R['angka']; ?>
          <div class="stat-baris dua-dua">
            <?= statTile(['label' => 'Acara berjalan', 'nilai' => $A['acara'], 'href' => 'klien.php?pic=admin_office',
                          'sub' => $A['n']['deal'] . ' deal · ' . $A['n']['persiapan'] . ' persiapan · ' . $A['n']['harih'] . ' hari-H']) ?>
            <?= statTile(['label' => 'Tagihan 30 hari', 'nilai' => rupiah($A['rp30'], true), 'href' => $SARING_URL . 'tagihan#antrean',
                          'sub' => ($A['n30'] ? $A['n30'] . ' termin' : 'tidak ada') . ($A['tanpaTgl'] ? ' · ' . $A['tanpaTgl'] . ' belum bertanggal' : '')]) ?>
            <?= $A['rpLewat'] > 0
                ? statTile(['label' => 'Lewat tempo', 'nilai' => rupiah($A['rpLewat'], true), 'nada' => 'rose', 'glyph' => '!', 'href' => $SARING_URL . 'tagihan#antrean',
                            'sub' => $A['nLewat'] . ' termin · tertua ' . abs((int) hariKe($A['tertua'])) . ' hari'])
                : statTile(['label' => 'Lewat tempo', 'nilai' => '—', 'nada' => 'sage', 'sub' => '✓ semua tepat waktu']) ?>
            <?= statTile(['label' => 'Langkah checklist lewat', 'nilai' => $A['tugasLewat'], 'href' => $SARING_URL . 'checklist#antrean', 'nada' => $A['tugasLewat'] ? 'rose' : '',
                          'sub' => $A['tugasLewat'] ? 'di ' . $A['acaraLewat'] . ' acara' : 'tidak ada']) ?>
          </div>
        <?php endif; ?>
      </section>
    </div>

    <?php if ($R['acara']):
      $belum = 0;
      foreach ($R['acara'] as $c) if (kesiapanJumlah(kesiapanDeal($c)) < 6) $belum++; ?>
    <details class="card kesiapan u5">
      <summary><h2>Kesiapan <?= count($R['acara']) ?> acara</h2> <span class="lab"><?= $belum ? $belum . ' belum lengkap' : '✓ semua lengkap' ?></span></summary>
      <div class="tbl-wrap"><table class="tbl siap-tbl">
        <thead><tr><th>Acara</th><th>Tanggal</th><th>Biodata</th><th>Venue</th><th>Dekor</th><th>Vendor</th><th>Termin</th><th>Meeting</th><th>Checklist</th></tr></thead>
        <tbody>
        <?php foreach ($R['acara'] as $c):
            $siap = kesiapanDeal($c);
            $kurang = array_values(array_filter($siap, fn($s) => !$s['ok']));
            $hk = $c['hari_ke_h'] === null ? null : (int) $c['hari_ke_h'];
            $soroti = $kurang && $hk !== null && $hk <= 90; ?>
          <tr class="<?= $soroti ? 'soroti' : '' ?>">
            <td data-l="Acara"><a href="klien.php?id=<?= (int) $c['id'] ?>"><b><?= e(namaPasangan($c)) ?></b></a>
              <span class="mono"><?= $hk === null ? 'tanpa tanggal' : ($hk >= 0 ? 'H-' . $hk : 'lewat') ?> · <?= kesiapanJumlah($siap) ?>/6 siap</span>
              <?php if ($kurang): ?><span class="chip<?= $soroti ? ' rose' : '' ?>">kurang: <?= e(implode(', ', array_column($kurang, 'label'))) ?></span><?php endif; ?></td>
            <td data-l="Tanggal" class="mono"><?= e($c['wedding_date'] ? tglPendek($c['wedding_date'], false) : '—') ?></td>
            <?php foreach ($siap as $s): ?>
              <td data-l="<?= e(ucfirst($s['label'])) ?>" class="sel-siap"><?= $s['ok']
                  ? '<span class="ok" aria-label="' . e(ucfirst($s['label'])) . ': ada">✓</span>'
                  : '<a class="belum" href="' . e($s['href']) . '" aria-label="' . e(ucfirst($s['label'])) . ': belum diisi — ' . e($s['aksi']) . '">○</a>' ?></td>
            <?php endforeach; ?>
            <td data-l="Checklist"><?= (int) $c['n_tugas']
                ? meter((float) $c['n_tugas_selesai'], (float) $c['n_tugas'], 'sage', 'Checklist', (int) $c['n_tugas_selesai'] . '/' . (int) $c['n_tugas']
                        . ((int) $c['n_tugas_telat'] ? ' <b class="merah">lewat ' . (int) $c['n_tugas_telat'] . '</b>' : ''))
                : '<a class="hint" href="klien.php?id=' . (int) $c['id'] . '#checklist">belum dibuat</a>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </details>
    <?php endif; ?>
  </div>

<?php
/* ============================================================
   OWNER
   ============================================================ */
else:
    $R = ringkasanOwner();
    $B = $R['bulan'] ?? [];
    $per = labelPeriodeLalu();
    ?>
  <?php if (isset($R['galat']['bulan'])): echo galatBagian(); else:
    $lewatRp = (float) $B['lewat_rp']; ?>
  <div class="stat-baris dua-dua owner-kpi">
    <?= statTile(['label' => 'Prospek masuk', 'nilai' => (int) $B['masuk'], 'href' => 'klien.php?tahap=baru',
                  'sub' => (int) $B['masuk_form'] . ' lewat formulir', 'delta' => deltaTeks((int) $B['masuk'], (int) $B['masuk_lalu'])]) ?>
    <?= statTile(['label' => 'DP masuk', 'nilai' => (int) $B['dp_n'], 'href' => 'klien.php?tahap=deal',
                  'sub' => (float) $B['dp_nilai'] > 0 ? rupiah((float) $B['dp_nilai'], true) . ' nilai kontrak' : 'belum ada', 'delta' => deltaTeks((int) $B['dp_n'], (int) $B['dp_n_lalu'])]) ?>
    <?= statTile(['label' => 'Uang diterima', 'nilai' => rupiah((float) $B['kas'], true), 'href' => '#kas',
                  'sub' => (float) $B['kas'] > 0 ? 'dari ' . (int) $B['kas_klien'] . ' klien' : 'belum ada pembayaran tercatat bulan ini',
                  'delta' => deltaTeks((float) $B['kas'], (float) $B['kas_lalu'], true, true)]) ?>
    <?= $lewatRp > 0
        ? statTile(['label' => 'Lewat tempo', 'nilai' => rupiah($lewatRp, true), 'nada' => 'rose', 'glyph' => '!', 'href' => '#keputusan',
                    'sub' => (int) $B['lewat_n'] . ' termin · tertua ' . abs((int) hariKe($B['lewat_tertua'])) . ' hari'])
        : statTile(['label' => 'Lewat tempo', 'nilai' => '—', 'nada' => 'sage', 'sub' => '✓ semua termin tepat waktu']) ?>
  </div>
  <p class="kalimat-r"><?php
    if (!(int) $B['masuk'] && !(int) $B['dp_n'] && (float) $B['kas'] <= 0) echo 'Bulan ini belum ada prospek, DP, atau pembayaran tercatat.';
    else echo e('Bulan ini: ' . (int) $B['masuk'] . ' prospek (' . (int) $B['masuk_form'] . ' lewat formulir), ' . (int) $B['dp_n'] . ' DP masuk'
            . ((float) $B['dp_nilai'] > 0 ? ' senilai ' . rupiah((float) $B['dp_nilai'], true) : '') . ', uang diterima ' . ((float) $B['kas'] > 0 ? rupiah((float) $B['kas'], true) : 'belum ada')
            . ', ' . ($lewatRp > 0 ? (int) $B['lewat_n'] . ' termin lewat tempo (' . rupiah($lewatRp, true) . ').' : 'tidak ada termin lewat tempo.') . ' Pembanding: ' . $per . '.');
  ?></p>
  <?php endif; ?>

  <div class="grid dash susun owner">
    <div class="kolom">
      <section class="card u1" id="keputusan">
        <h2>Perlu keputusanmu</h2>
        <?php if (isset($R['galat']['keputusan'])): echo galatBagian();
        elseif (!$R['keputusan']): ?>
          <p class="semua-beres">✓ Tidak ada yang menunggu keputusanmu. Semua antrean tim masih dalam tenggat.</p>
        <?php else: $kp = $R['keputusan']; ?>
          <p class="sub">Hanya yang butuh pertimbanganmu atau sudah lolos dari antrean tim — satu baris per klien.</p>
          <?php
            $tampil = array_slice($kp, 0, 8);
            echo daftarAntrean($tampil, '', false, 'keputusan');
            if (count($kp) > 8) echo '<details class="antrean-lebih"><summary>Tampilkan ' . (count($kp) - 8) . ' lainnya</summary>' . daftarAntrean(array_slice($kp, 8), '', false, 'keputusan') . '</details>';
          ?>
        <?php endif; ?>
      </section>

      <section class="card u4" id="kas">
        <h2>Arus kas</h2>
        <?php if (isset($R['galat']['kas'])): echo galatBagian(); else:
          $K = $R['kas']; $f = $K['fakta'];
          $adaData = array_filter($K['slot'], fn($s) => $s['masuk'] > 0 || (($s['kini'] || $s['depan']) && $s['jadwal'] > 0));
          if (!$adaData && !$f['lewat'] && !$f['tanpa'] && !$f['nanti'] && !($f['dp']['n'] ?? 0)): ?>
            <p class="sub">Belum ada pembayaran tercatat atau terjadwal. Termin tersusun otomatis saat klien cocok — catat uang masuk di tab Pembayaran supaya grafik ini terisi.</p>
          <?php else:
            $kini = array_values(array_filter($K['slot'], fn($s) => $s['kini']))[0];
            $depan = array_sum(array_map(fn($s) => $s['depan'] ? $s['jadwal'] : 0, $K['slot']));
            $kalimat = 'Bulan ini diterima ' . ($kini['masuk'] > 0 ? rupiah($kini['masuk'], true) : 'belum ada')
                     . ($kini['jadwal'] > 0 ? ', masih dijadwalkan ' . rupiah($kini['jadwal'], true) : '')
                     . '; 6 bulan ke depan dijadwalkan ' . ($depan > 0 ? rupiah($depan, true) : 'belum ada') . '.'; ?>
            <figure class="grafik">
              <?php if ($adaData): ?><?= grafikKas($K['slot'], 'Arus kas: ' . $kalimat) ?><?php endif; ?>
              <figcaption aria-live="polite" data-keterangan><?= e($kalimat) ?></figcaption>
              <div class="legenda"><span><i class="lg-isi"></i> diterima</span><span><i class="lg-tepi"></i> dijadwalkan (termin klien yang sudah DP)</span><span class="mono">* sampai hari ini</span></div>
              <?php if (!array_filter($K['slot'], fn($s) => ($s['kini'] || $s['depan']) && $s['jadwal'] > 0)): ?><p class="hint">Belum ada termin terjadwal.</p><?php endif; ?>
              <ul class="fakta">
                <?php if ($f['lewat']): ?>
                  <li class="merah"><a href="#keputusan">! Lewat tempo <?= e(rupiah($f['lewat']['rp'], true)) ?> (<?= (int) $f['lewat']['n'] ?> termin, tertua <?= abs((int) hariKe($f['lewat']['tertua'])) ?> hari)</a></li>
                <?php else: ?>
                  <li class="hijau">✓ Tidak ada termin lewat tempo</li>
                <?php endif; ?>
                <?php if ($f['tanpa']): ?><li><a href="klien.php?tahap=deal">Belum bertanggal: <?= (int) $f['tanpa']['n'] ?> termin · <?= e(rupiah($f['tanpa']['rp'], true)) ?> — tanggal acara belum pasti</a></li><?php endif; ?>
                <?php if (($f['dp']['n'] ?? 0) > 0): ?><li><a href="klien.php?tahap=dp">DP ditunggu: <?= e(rupiah($f['dp']['rp'], true)) ?> dari <?= (int) $f['dp']['n'] ?> klien — belum masuk proyeksi</a></li><?php endif; ?>
                <?php if ($f['nanti']): ?><li>Setelah <?= e(end($K['slot'])['label'] . ' ' . end($K['slot'])['tahun']) ?>: <?= e(rupiah($f['nanti']['rp'], true)) ?></li><?php endif; ?>
              </ul>
              <details class="setara"><summary>Lihat sebagai tabel</summary>
                <table class="tbl"><thead><tr><th>Bulan</th><th>Diterima</th><th>Dijadwalkan</th></tr></thead><tbody>
                  <?php foreach ($K['slot'] as $s): ?>
                    <tr><td><?= e($s['label'] . ' ' . $s['tahun'] . ($s['kini'] ? '*' : '')) ?></td><td class="mono"><?= e(rupiah($s['masuk'])) ?></td>
                        <td class="mono"><?= e(($s['kini'] || $s['depan']) ? rupiah($s['jadwal']) : '—') ?></td></tr>
                  <?php endforeach; ?>
                </tbody></table>
              </details>
            </figure>
          <?php endif; ?>
        <?php endif; ?>
      </section>

      <section class="card u6" id="corong">
        <h2>Corong 12 bulan</h2>
        <?php if (isset($R['galat']['corong'])): echo galatBagian(); else:
          $C = $R['corong']; $np = (int) ($C['prospek'] ?? 0);
          if (!$np): ?>
            <p class="sub">Belum ada prospek dalam 12 bulan terakhir. Corong terisi otomatis begitu klien dicatat atau formulir masuk.</p>
          <?php else:
            $pct = fn(int $a, int $b, string $dari) => $np >= 10 && $b > 0 ? round($a / $b * 100) . '% dari ' . $dari : '';
            $lnk = fn(string $href, string $teks) => '<a href="' . e($href) . '">' . e($teks) . '</a>';
            $baris = [
              ['label' => 'Prospek masuk', 'n' => $np, 'rasio' => '',
               'catatan' => $lnk('klien.php?tahap=baru', (int) $C['aktif0'] . ' belum dikirimi price list (masih aktif)') . ' · ' . $lnk('klien.php?tahap=batal', (int) $C['gugur0'] . ' gugur')],
              ['label' => 'Price list terkirim', 'n' => (int) $C['pl'], 'rasio' => $pct((int) $C['pl'], $np, 'prospek'),
               'catatan' => $lnk('klien.php?tahap=pricelist', (int) $C['aktif1'] . ' masih menimbang') . ' · ' . $lnk('klien.php?tahap=batal', (int) $C['gugur1'] . ' gugur')],
              ['label' => 'Cocok', 'n' => (int) $C['cocok'], 'rasio' => $pct((int) $C['cocok'], (int) $C['pl'], 'price list'),
               'catatan' => $lnk('klien.php?tahap=dp', (int) $C['aktif2'] . ' masih menunggu DP') . ' · ' . $lnk('klien.php?tahap=batal', (int) $C['gugur2'] . ' gugur')],
              ['label' => 'DP masuk', 'n' => (int) $C['dp'], 'rasio' => $pct((int) $C['dp'], (int) $C['cocok'], 'yang cocok'),
               'catatan' => e((int) $C['selesai'] . ' selesai · ' . (int) $C['aktif3'] . ' acara belum tiba · ' . (int) $C['gugur3'] . ' batal setelah DP')],
              ['label' => 'Selesai', 'n' => (int) $C['selesai'], 'rasio' => '', 'catatan' => ''],
            ];
            echo grafikBaris($baris);
            if ($np < 10): ?><p class="hint">Baru <?= $np ?> prospek — baca sebagai hitungan, bukan persentase.</p><?php endif;
            $med = $C['median']; ?>
            <p class="kecepatan<?= $med !== null && $med > 1440 ? ' lewat' : '' ?>"><a href="klien.php?tahap=baru"><?php
              if ($med === null) echo 'Waktu respon price list: data belum cukup (perlu ≥ 3 prospek formulir dalam 90 hari).';
              else echo e('Price list terkirim median ' . durasiTeks((int) round($med)) . ' setelah formulir masuk (' . (int) $C['n_median'] . ' prospek, 90 hari)'
                      . ((int) $C['form_n24'] ? ' · ' . (int) $C['form_cepat24'] . ' dari ' . (int) $C['form_n24'] . ' dalam 24 jam' : '')
                      . ($med > 1440 ? ' — lewat target 24 jam' : '') . '.');
            ?></a></p>
            <p class="hint"><a href="analisa.php">Kenapa gugur &amp; channel mana yang jadi → Analisa</a></p>
          <?php endif; ?>
        <?php endif; ?>
      </section>
    </div>

    <div class="kolom dash-samping">
      <section class="card u2" id="tim">
        <h2>Antrean tim</h2>
        <?php if (isset($R['galat']['tim'])): echo galatBagian(); else: $T = $R['tim']; ?>
        <div class="tim-kartu">
          <?php foreach ([
              ['early', 'Admin early', (int) $T['e_aktif'], (int) $T['e_lewat'], (int) $T['e_hari_ini'], $T['e_tertua'],
               [['baru', 'Prospek baru', (int) $T['n_baru']], ['pricelist', 'Price list', (int) $T['n_pricelist']], ['dp', 'Menunggu DP', (int) $T['n_dp']]],
               ['<a href="formulir.php">formulir gagal ' . (int) $T['e_form'] . '</a>', 'DP lewat tempo ' . (int) $T['e_dp_lewat'], 'konsultasi belum dicatat ' . (int) $T['e_temu_terbuka']]],
              ['office', 'Admin office', (int) $T['o_aktif'], (int) $T['o_lewat'], (int) $T['o_hari_ini'], $T['o_tertua'],
               [['deal', 'Deal', (int) $T['n_deal']], ['persiapan', 'Persiapan', (int) $T['n_persiapan']], ['harih', 'Hari-H', (int) $T['n_harih']]],
               ['termin lewat ' . (int) $T['o_termin_lewat'], 'langkah checklist lewat ' . (int) $T['o_tugas_lewat'],
                '<a href="klien.php?pic=dl_kosong">deal >14 hari tanpa biodata ' . (int) $T['o_dl_basi'] . '</a>', 'meeting belum dicatat ' . (int) $T['o_temu_terbuka']]],
            ] as [$k, $judul, $aktif, $lewat, $hariIni, $tertua, $tahap, $ekstra]): ?>
            <div class="tim">
              <span class="lab"><?= e($judul) ?></span>
              <?php if (!$aktif): ?>
                <p class="sub" style="margin:6px 0">Belum ada klien di bagian ini.</p>
              <?php elseif ($lewat): ?>
                <p class="tim-n"><b class="merah"><?= $lewat ?></b> tindak lanjut lewat<?= $hariIni ? ' · ' . $hariIni . ' hari ini' : '' ?></p>
                <p class="hint">tertua: <?= e(tglPendek($tertua, false)) ?> (<?= abs((int) hariKe($tertua)) ?> hari lewat)</p>
              <?php else: ?>
                <p class="tim-n hijau">✓ Tidak ada yang lewat tenggat<?= $hariIni ? ' · ' . $hariIni . ' hari ini' : '' ?></p>
              <?php endif; ?>
              <p class="tim-tahap"><?php foreach ($tahap as $i => [$st, $lbl, $n]) echo ($i ? ' · ' : '') . '<a href="klien.php?tahap=' . $st . '">' . e($lbl) . ' <b>' . $n . '</b></a>'; ?></p>
              <p class="hint"><?= implode(' · ', $ekstra) ?></p>
              <a class="btn sm" href="index.php?lihat=<?= $k ?>">Lihat antrean <?= $k ?> →</a>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </section>

      <section class="card u3" id="minggu-ini">
        <h2>Minggu ini</h2>
        <?php if (isset($R['galat']['minggu'])): echo galatBagian(); else:
          $ent = []; $hh = [];
          foreach ($R['minggu'] as $w) {
              if ($w['jenis'] === 'hari_h') { $hh[] = $w; continue; }
              if ($w['jenis'] === 'temu') {
                  $ent[] = ['tanggal' => substr($w['waktu'], 0, 10), 'urut' => $w['waktu'], 'html' => agendaTemu($w + ['passcode' => ''], true)];
              } else {
                  if (substr($w['waktu'], 0, 10) > date('Y-m-d', strtotime('+7 day'))) continue;
                  $ent[] = ['tanggal' => substr($w['waktu'], 0, 10), 'urut' => substr($w['waktu'], 0, 10) . ' 23:59',
                            'html' => agendaTermin(['client_id' => $w['client_id'], 'nama' => $w['nama'], 'n' => $w['n'], 'rp' => $w['nilai']])];
              }
          }
          echo $ent ? agendaHari($ent) : ($hh ? '' : kosong('Minggu ini kosong', 'Tidak ada pertemuan, tagihan, atau hari-H.', '<a class="btn sm solid" href="jadwal.php?new=1">Jadwalkan</a>'));
          if ($hh): ?>
            <h3 class="ag-hari">Hari-H 30 hari</h3>
            <ul class="hh-daftar">
              <?php foreach ($hh as $w): $hk = (int) hariKe($w['waktu']); $lok = trim((string) $w['lokasi']); ?>
                <li><span class="mono hh-hk<?= (int) $w['nilai'] ? ' merah' : '' ?>"><?= $hk === 0 ? 'Hari ini' : 'H-' . $hk ?></span>
                  <a href="klien.php?id=<?= (int) $w['client_id'] ?>#checklist"><b><?= e($w['nama']) ?></b>
                    <span><?= e(($lok ?: 'venue belum diisi') . ((int) $w['nilai'] ? ' · ' . (int) $w['nilai'] . ' langkah lewat' : '')) ?></span></a>
                  <?php if ($lok !== ''): ?><a class="btn sm ghost" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&amp;query=<?= rawurlencode($lok) ?>">Peta ↗</a><?php endif; ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        <?php endif; ?>
      </section>

      <section class="card u5" id="tanggal">
        <h2>Tanggal terisi</h2>
        <?php if (isset($R['galat']['tanggal'])): echo galatBagian();
        elseif (!$R['tanggal']): ?>
          <p class="sub" style="margin:0">Belum ada tanggal terisi 6 bulan ke depan.</p>
        <?php else:
          $nDp = count(array_filter($R['tanggal'], fn($r) => $r['stage'] !== 'dp'));
          $kal = count($R['tanggal']) . ' acara dalam 6 bulan: ' . $nDp . ' sudah DP, ' . (count($R['tanggal']) - $nDp) . ' menunggu DP.'; ?>
          <figure class="grafik">
            <?= grafikTanggal($R['tanggal'], 'Tanggal terisi: ' . $kal) ?>
            <figcaption aria-live="polite" data-keterangan><?= e($kal) ?></figcaption>
            <div class="legenda"><span><i class="lg-titik"></i> DP masuk</span><span><i class="lg-cincin"></i> Menunggu DP</span></div>
            <?php foreach (tanggalBentrok($R['tanggal']) as $tgl => $n): ?><p class="bentrok-baris">! <?= $n ?> acara di <?= e(tglPendek($tgl)) ?> — cek kru</p><?php endforeach; ?>
            <details class="setara"><summary>Lihat daftar tanggal</summary><?= daftarTanggal($R['tanggal']) ?></details>
          </figure>
        <?php endif; ?>
      </section>
    </div>
  </div>

  <?php
  $P = $R['perhatian'] ?? null;
  $google = googleConnected(); $zoom = zoomConfigured();
  if ($P !== null):
    $formGalat = (int) ($P['form']['galat'] ?? 0); $formTolak = (int) ($P['form']['ditolak'] ?? 0);
    $formSimpan = (int) ($P['form']['tersimpan'] ?? 0) + (int) ($P['form']['ulang'] ?? 0);
    $ada = !$google || !$zoom || $P['sinkron'] || $P['seo'] || $P['paket'] || $formGalat + $formTolak > 0 || $P['analisa'] > 0;
    $merah = $formGalat > 0 || $P['sinkron'];
    if ($ada): ?>
  <details class="card perhatian"<?= $merah ? ' open' : '' ?>>
    <summary><h2 style="display:inline">Perlu perhatian</h2> <span class="lab" style="margin-left:8px">pengaturan &amp; kesehatan situs</span></summary>
    <ul class="perhatian-daftar">
      <?php if ($formGalat + $formTolak > 0): ?>
        <li<?= $formGalat ? ' class="merah"' : '' ?>><?= $formGalat ? '! ' : '◦ ' ?>Formulir 30 hari: <?= $formSimpan ?> tersimpan · <?= $formGalat ?> galat · <?= $formTolak ?> ditolak<?= $formGalat ? ' — galat berarti formulir situs sendiri gagal menyimpan' : '' ?>.
          <a href="formulir.php">Periksa →</a></li>
      <?php endif; ?>
      <?php if ($P['analisa'] > 0): ?><li>◦ <?= (int) $P['analisa'] ?> klien batal/selesai belum dianalisa. <a href="analisa.php">Analisa →</a></li><?php endif; ?>
      <?php foreach ($P['paket'] as $x): ?><li>◦ Paket <b><?= e($x['judul']) ?></b> tampil di situs tanpa harga. <a href="paket.php">Isi harga →</a></li><?php endforeach; ?>
      <?php if (!$google): ?><li>◦ Google Calendar belum terhubung — undangan ke klien tidak terkirim. <a href="integrasi.php">Hubungkan →</a></li><?php endif; ?>
      <?php if (!$zoom): ?><li>◦ Zoom belum dikonfigurasi. Google Meet tetap bisa dipakai tanpa ini. <a href="integrasi.php">Atur →</a></li><?php endif; ?>
      <?php foreach (array_slice($P['sinkron'], 0, 5) as $x): ?>
        <li class="merah">! Jadwal <b><?= e($x['judul']) ?></b> gagal tersinkron: <?= e(mb_substr((string) $x['ket'], 0, 90)) ?> <a href="jadwal.php?edit=<?= (int) $x['id'] ?>">Periksa →</a></li>
      <?php endforeach; ?>
      <?php foreach (array_slice($P['seo'], 0, 3) as $x): ?>
        <li>◦ Artikel "<?= e(mb_strimwidth((string) $x['judul'], 0, 46, '…')) ?>" skor SEO <?= e($x['ket']) ?>. <a href="blog.php?edit=<?= (int) $x['id'] ?>">Perbaiki →</a></li>
      <?php endforeach; ?>
    </ul>
  </details>
  <?php endif; endif; ?>
<?php endif; ?>

<?php adminFoot();
