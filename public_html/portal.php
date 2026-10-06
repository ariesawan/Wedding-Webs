<?php
/**
 * DASHBOARD PENGANTIN — /p/{token}
 *
 * Halaman pribadi pasangan setelah DP. Lihat inc/portal.php untuk model
 * aksesnya. Semua data dibaca lewat fungsi berdaftar-putih di sana; halaman
 * ini tidak pernah memakai SELECT *.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/portal.php';

$token = strtolower((string) ($_GET['t'] ?? ''));
$c     = portalKlien($token);
$st    = portalStatus($c);
$admin = !empty($_SESSION['uid']);

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
     . "form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$brand = setting('site_name', 'Callalily Party');
$waPub = waNomorPublik();

$halaman = function (string $judul, string $isi, int $kode = 200) use ($brand, $waPub): never {
    http_response_code($kode);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow"><title>Dashboard pengantin — ' . e($brand) . '</title>'
       . '<style>body{font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;background:#F6F1E7;color:#1c1810;display:grid;place-items:center;min-height:100vh;margin:0;padding:22px}'
       . 'div{max-width:440px;text-align:center}h1{font:italic 400 30px/1.15 Georgia,serif;margin:0 0 10px}'
       . 'a{display:inline-block;margin-top:16px;background:#A9651B;color:#fff;padding:12px 20px;border-radius:99px;text-decoration:none;font-weight:600}</style></head>'
       . '<body><div><p style="letter-spacing:.14em;text-transform:uppercase;font-size:11px;color:#A9651B">' . e($brand) . '</p>'
       . '<h1>' . e($judul) . '</h1><p>' . $isi . '</p>'
       . '<a href="https://wa.me/' . e($waPub) . '" rel="noopener noreferrer">Hubungi kami di WhatsApp</a></div></body></html>';
    exit;
};

if ($st === 'mati') $halaman('Tautan ini tidak aktif', 'Mungkin tautannya sudah diganti. Hubungi kami lewat WhatsApp, kami kirim yang baru.', 404);
if ($st === 'praDeal') $halaman('Sebentar lagi', 'Dashboard pengantin aktif setelah DP kalian kami konfirmasi.');

$cid   = (int) $c['id'];
$kunci = portalKunci($c);
$bisaIsi = $st === 'aktif' && !$kunci && !$admin;
$pesan = ['ok' => '', 'galat' => '', 'bagian' => ''];

// ---------- Simpan formulir ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bagian = (string) ($_POST['bagian'] ?? '');
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $baseOrigin = preg_replace('#^(https?://[^/]+).*$#', '$1', rtrim(BASE_URL, '/'));
    if ($admin) {
        $pesan = ['ok' => '', 'galat' => 'Pratinjau admin — formulir tidak disimpan. Sunting dari panel.', 'bagian' => $bagian];
    } elseif ($origin !== '' && strcasecmp($origin, $baseOrigin) !== 0) {
        $pesan = ['ok' => '', 'galat' => 'Permintaan ditolak. Muat ulang halaman ini lalu coba lagi.', 'bagian' => $bagian];
    } else {
        $galat = portalSimpan($c, $bagian, $_POST);
        if ($galat === '') {
            header('Location: ' . portalUrl($token) . '?ok=' . rawurlencode($bagian) . '#' . rawurlencode($bagian), true, 303);
            exit;
        }
        $pesan = ['ok' => '', 'galat' => $galat, 'bagian' => $bagian];
    }
}
if (isset($_GET['ok'])) $pesan = ['ok' => 'Tersimpan. Terima kasih!', 'galat' => '', 'bagian' => (string) $_GET['ok']];

// ---------- Kunjungan pertama ----------
if (!$admin && empty($c['portal_seen_at']) && !portalBot() && $_SERVER['REQUEST_METHOD'] === 'GET') {
    q("UPDATE clients SET portal_seen_at = NOW() WHERE id = ? AND portal_seen_at IS NULL", [$cid]);
    clientLog($cid, 'sistem', 'Klien membuka dashboard pengantin', '', null);
}

// ---------- Data ----------
$rk   = bayarRingkas($cid);
$wi   = portalInfo($cid);
$kel  = portalKeluarga($cid);
$meet = portalMeeting($cid);
$ven  = portalVendor($cid);
$dok  = portalDokumen($cid);
$langkah = portalLangkah($c, $rk, $kel, $wi, $meet, $kunci);
$nama = trim($c['name'] . ($c['partner_name'] ? ' & ' . $c['partner_name'] : ''));
$hK   = hariKe($c['wedding_date']);
$waPic = waNomorPic($c['stage']);
$waTxt = fn(string $t) => 'https://wa.me/' . $waPic . '?text=' . rawurlencode($t);
$rek  = rekeningBaris();
$norek = preg_replace('/\D/', '', (string) setting('bank_norek', ''));
$val  = fn(string $k, $lama = '') => $pesan['galat'] !== '' && isset($_POST[$k]) ? (string) $_POST[$k] : (string) $lama;
$lokasi = $wi['resepsi_lokasi'] ?: ($wi['akad_lokasi'] ?: $c['venue']);
$tahap = ['deal' => 1, 'persiapan' => 2, 'harih' => 3, 'selesai' => 4][$c['stage']] ?? 1;
require_once __DIR__ . '/inc/vendor.php';
?><!doctype html>
<html lang="id"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#F6F1E7">
<title>Dashboard pengantin — <?= e($brand) ?></title>
<style>
:root{--latar:#F6F1E7;--kartu:#fff;--tinta:#1c1810;--abu:#6e685e;--pudar:#a09a90;--garis:#e6dfd2;--aksen:#A9651B;--aksen-l:rgba(169,101,27,.1);
  --hijau:#2F7355;--hijau-l:rgba(47,115,85,.1);--merah:#B03A3A;--merah-l:rgba(176,58,58,.08);--kuning:#7A4E0C;--kuning-l:rgba(233,168,92,.16)}
@media (prefers-color-scheme:dark){:root{--latar:#17181A;--kartu:#1F2023;--tinta:#F1EAD9;--abu:rgba(241,234,217,.66);--pudar:rgba(241,234,217,.42);
  --garis:rgba(241,234,217,.12);--aksen:#E9A85C;--aksen-l:rgba(233,168,92,.1);--hijau:#7FB69A;--hijau-l:rgba(127,182,154,.1);--merah:#E08A8A;--merah-l:rgba(224,138,138,.1);--kuning:#E9C08A;--kuning-l:rgba(233,168,92,.12)}}
*{box-sizing:border-box}
body{margin:0;background:var(--latar);color:var(--tinta);font:15.5px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;padding-bottom:88px}
a{color:var(--aksen)}
.wrap{max-width:720px;margin:0 auto;padding:22px 16px}
.brand{font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:var(--aksen);margin:0 0 14px}
h1{font:italic 400 clamp(28px,7vw,40px)/1.1 Georgia,serif;margin:0}
h2{font:italic 400 24px/1.2 Georgia,serif;margin:0 0 4px}
.lab{font-size:10.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--pudar)}
.kartu{background:var(--kartu);border:1px solid var(--garis);border-radius:16px;padding:20px 18px;margin:14px 0;scroll-margin-top:12px}
.kepala{padding:26px 20px}
.hitung{font:italic 400 clamp(40px,12vw,60px)/1 Georgia,serif;color:var(--aksen);margin:14px 0 4px}
.sub{color:var(--abu);margin:4px 0 0}
.langkah3{display:flex;gap:6px;margin:18px 0 0;padding:0;list-style:none}
.langkah3 li{flex:1;font-size:12px;color:var(--pudar);border-top:3px solid var(--garis);padding-top:7px}
.langkah3 li.ok{color:var(--tinta);border-color:var(--aksen)}
.todo{list-style:none;margin:10px 0 0;padding:0}
.todo li{border-top:1px solid var(--garis)}
.todo a{display:flex;justify-content:space-between;gap:10px;padding:12px 0;text-decoration:none;color:var(--tinta);min-height:44px;align-items:center}
.todo a::after{content:"→";color:var(--aksen)}
.angka{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:12px 0 10px}
.angka b{display:block;font-size:clamp(15px,4.4vw,19px);margin-top:2px;white-space:nowrap}
@media (max-width:460px){.angka{grid-template-columns:1fr;gap:4px}.angka div{display:flex;justify-content:space-between;align-items:baseline}.angka b{margin:0}}
.dua.tetap{grid-template-columns:1fr 1fr}
details.wali{border-top:1px dashed var(--garis);margin-top:8px;padding-top:6px}
details.wali summary{cursor:pointer;color:var(--aksen);font-size:14px;padding:8px 0;min-height:44px;display:flex;align-items:center}
.bar{height:8px;background:var(--garis);border-radius:9px;overflow:hidden}.bar i{display:block;height:100%;background:var(--hijau);border-radius:9px}
.berikut{background:var(--aksen-l);border-radius:12px;padding:14px;margin:14px 0}
.berikut b.n{font-size:22px;display:block}
.rek{border:1px dashed var(--garis);border-radius:12px;padding:12px 14px;margin:12px 0}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:44px;padding:10px 16px;border-radius:99px;border:1px solid var(--garis);
  background:transparent;color:var(--tinta);font:inherit;font-size:14px;cursor:pointer;text-decoration:none}
.btn.isi{background:var(--aksen);border-color:var(--aksen);color:#fff;font-weight:600}
@media (prefers-color-scheme:dark){.btn.isi{color:#17181A}}
.btn.kecil{min-height:36px;padding:6px 12px;font-size:13px}
.termin{list-style:none;padding:0;margin:8px 0 0}
.termin li{display:flex;justify-content:space-between;gap:12px;padding:11px 0;border-top:1px solid var(--garis)}
.termin .st{display:block;font-size:12.5px;margin-top:2px}
.st.lunas{color:var(--hijau)}.st.lewat{color:var(--kuning)}.st.dekat,.st.hari_ini{color:var(--kuning)}.st.belum,.st.menyusul,.st.sebagian{color:var(--abu)}
.num{white-space:nowrap;text-align:right}
.meet{border-top:1px solid var(--garis);padding:12px 0}
.meet:first-of-type{border-top:0}
.aksi{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
form .f{margin:10px 0}
label{display:block;font-size:13px;color:var(--abu);margin-bottom:4px}
input[type=text],input[type=number],input[type=tel],select,textarea{width:100%;font:inherit;font-size:16px;padding:11px 12px;border-radius:10px;
  border:1px solid var(--garis);background:var(--latar);color:var(--tinta)}
textarea{min-height:120px;resize:vertical}
.dua{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media (max-width:520px){.dua{grid-template-columns:1fr}}
fieldset{border:1px solid var(--garis);border-radius:12px;padding:12px 12px 4px;margin:12px 0}
legend{font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--aksen);padding:0 6px}
.ortu{border-top:1px dashed var(--garis);padding-top:8px;margin-top:8px}
.ortu:first-of-type{border:0;margin:0;padding:0}
.ket{font-size:12.5px;color:var(--pudar);margin:4px 0 0}
.pesan{border-radius:10px;padding:10px 12px;margin:10px 0;font-size:14px}
.pesan.ok{background:var(--hijau-l);color:var(--hijau)}.pesan.galat{background:var(--merah-l);color:var(--merah)}
.kunci{background:var(--kuning-l);color:var(--kuning);border-radius:10px;padding:10px 12px;font-size:14px}
.ven{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;margin-top:10px}
.ven div{border:1px solid var(--garis);border-radius:10px;padding:9px 11px}
.ven small{display:block;color:var(--pudar);font-size:11px;letter-spacing:.08em;text-transform:uppercase}
.baca dt{font-size:12px;color:var(--pudar)}.baca dd{margin:0 0 8px}
.banner{background:#1c1810;color:#F6F1E7;text-align:center;font-size:13px;padding:8px}
.chat{position:fixed;left:0;right:0;bottom:0;background:var(--kartu);border-top:1px solid var(--garis);padding:10px 16px calc(10px + env(safe-area-inset-bottom));z-index:9}
.chat .btn{width:100%;max-width:720px;margin:0 auto;display:flex}
.kaki{font-size:12px;color:var(--pudar);text-align:center;margin:24px 0}
</style>
<script src="<?= e(url('assets/portal.js')) ?>?v=<?= e(assetVer('assets/portal.js')) ?>" defer></script>
</head>
<body>
<?php if ($admin): ?><div class="banner">Pratinjau admin — formulir nonaktif, kunjungan tidak tercatat. Sunting dari panel.</div><?php endif; ?>
<main class="wrap">
  <p class="brand"><?= e($brand) ?> · Dashboard pengantin</p>

  <!-- ---------- Kepala ---------- -->
  <section class="kartu kepala">
    <h1>Hai <?= e($nama) ?></h1>
    <?php if (setting('portal_salam', '') !== ''): ?><p class="sub"><?= e(setting('portal_salam')) ?></p><?php endif; ?>
    <div class="hitung"><?php
      if ($hK === null) echo 'Tanggal menyusul';
      elseif ($hK > 0) echo e(number_format($hK, 0, ',', '.')) . ' hari lagi';
      elseif ($hK === 0) echo 'Hari ini';
      else echo 'Terima kasih'; ?></div>
    <p class="sub"><?php if ($c['wedding_date']): ?><?= e(hariID($c['wedding_date']) . ', ' . tanggalID($c['wedding_date'])) ?><?= $c['wedding_time'] ? ' · ' . e(substr($c['wedding_time'], 0, 5)) . ' WIB' : '' ?><?php else: ?>Tanggal kita tentukan bersama PIC.<?php endif; ?>
      <?= $lokasi ? '<br>' . e($lokasi) : '' ?></p>
    <?php if ($wi['akad_tanggal'] && $wi['resepsi_tanggal']): ?>
      <p class="sub" style="font-size:13.5px">Akad <?= e(tanggalID($wi['akad_tanggal'])) ?><?= $wi['akad_jam'] ? ' ' . e(substr($wi['akad_jam'], 0, 5)) : '' ?><?= $wi['akad_lokasi'] ? ' · ' . e($wi['akad_lokasi']) : '' ?>
        <br>Resepsi <?= e(tanggalID($wi['resepsi_tanggal'])) ?><?= $wi['resepsi_jam'] ? ' ' . e(substr($wi['resepsi_jam'], 0, 5)) : '' ?><?= $wi['resepsi_lokasi'] ? ' · ' . e($wi['resepsi_lokasi']) : '' ?></p>
    <?php endif; ?>
    <ol class="langkah3" aria-label="Perjalanan kalian">
      <li class="<?= $tahap >= 1 ? 'ok' : '' ?>">DP diterima</li>
      <li class="<?= $tahap >= 2 ? 'ok' : '' ?>">Persiapan</li>
      <li class="<?= $tahap >= 3 ? 'ok' : '' ?>">Hari-H</li>
    </ol>
    <div class="aksi"><a class="btn kecil" rel="noopener noreferrer" href="<?= e($waTxt('Halo, kami ' . $nama . ($c['wedding_date'] ? ' (hari-H ' . tanggalID($c['wedding_date']) . ')' : '') . '. Mau mengajukan perubahan tanggal/lokasi.')) ?>">Ajukan perubahan ke PIC</a></div>
  </section>

  <!-- ---------- Yang perlu dilakukan ---------- -->
  <section class="kartu">
    <span class="lab">Yang perlu kalian lakukan</span>
    <?php if ($langkah): ?>
      <ul class="todo"><?php foreach ($langkah as [$ke, $teks]): ?><li><a href="<?= e($ke) ?>"><?= e($teks) ?></a></li><?php endforeach; ?></ul>
    <?php else: ?>
      <p class="sub">Semua beres untuk saat ini. Kami kabari lewat WhatsApp kalau ada yang perlu kalian kerjakan.</p>
    <?php endif; ?>
  </section>

  <!-- ---------- Pembayaran ---------- -->
  <section class="kartu" id="bayar">
    <h2>Pembayaran</h2>
    <?php if (!$rk['termin']): ?>
      <p class="sub">Jadwal pembayaran sedang disusun PIC kalian.</p>
    <?php else: ?>
      <div class="angka">
        <div><span class="lab">Total</span><b><?= e(rupiah($rk['kontrak'])) ?></b></div>
        <div><span class="lab">Sudah diterima</span><b style="color:var(--hijau)"><?= e(rupiah($rk['diterima'])) ?></b></div>
        <div><span class="lab">Sisa</span><b><?= e(rupiah($rk['sisa'])) ?></b></div>
      </div>
      <div class="bar" role="img" aria-label="<?= (int) $rk['persen'] ?>% sudah dibayar"><i style="width:<?= (int) $rk['persen'] ?>%"></i></div>
      <p class="ket"><?= (int) $rk['persen'] ?>% sudah dibayar</p>

      <?php if ($b = $rk['berikutnya']): ?>
        <div class="berikut">
          <span class="lab">Berikutnya</span>
          <b class="n"><?= e(rupiah($b['sisa'])) ?></b>
          <span><?= e($b['label']) ?><?= $b['due_date'] ? ' · paling lambat ' . e(tanggalID($b['due_date'])) : '' ?></span>
          <div class="aksi"><button class="btn kecil" type="button" data-salin="<?= (int) round($b['sisa']) ?>">Salin nominal</button></div>
        </div>
      <?php endif; ?>

      <?php if ($rek): ?>
        <div class="rek">
          <span class="lab">Transfer ke</span>
          <div style="margin-top:4px"><?= e(implode(' · ', $rek)) ?></div>
          <?php if ($norek): ?><div class="aksi"><button class="btn kecil" type="button" data-salin="<?= e($norek) ?>">Salin nomor rekening</button></div><?php endif; ?>
          <p class="ket">Kami tidak pernah meminta transfer ke rekening lain atau atas nama pribadi. Ragu? Tanyakan ke PIC kalian.</p>
        </div>
      <?php endif; ?>

      <ul class="termin">
        <?php foreach ($rk['termin'] as $t): ?>
          <li><div><?= e($t['label']) ?><span class="st <?= e($t['status']) ?>"><?= e($t['status_teks']) ?></span></div>
            <div class="num"><?= e(rupiah($t['amount'])) ?></div></li>
        <?php endforeach; ?>
      </ul>
      <?php $kwSah = array_filter($rk['kwitansi'], fn($k) => !$k['batal']); ?>
      <?php if ($kwSah): ?>
        <p class="lab" style="margin:16px 0 4px">Kwitansi</p>
        <ul class="termin">
          <?php foreach ($kwSah as $k): ?>
            <li><div><?= e($k['nomor']) ?><span class="st"><?= e(tanggalID($k['tanggal'])) ?></span></div>
              <div class="num"><?= e(rupiah($k['jumlah'])) ?><br><a href="<?= e($k['url']) ?>&amp;unduh=1" rel="noopener noreferrer">Unduh PDF</a></div></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($rk['berikutnya']): ?>
        <div class="aksi"><a class="btn isi" rel="noopener noreferrer"
          href="<?= e('https://wa.me/' . (waSiap() && setting('wa_device_number', '') !== '' ? waNomor((string) setting('wa_device_number')) : $waPic) . '?text='
                 . rawurlencode('Halo, kami ' . $nama . '. Sudah transfer ' . $rk['berikutnya']['label'] . ' ' . rupiah($rk['berikutnya']['sisa']) . '. Berikut bukti transfernya:')) ?>">Sudah transfer? Kirim bukti lewat WhatsApp</a></div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <!-- ---------- Meeting ---------- -->
  <section class="kartu" id="meeting">
    <h2>Jadwal meeting</h2>
    <?php if (!$meet): ?>
      <p class="sub">Belum ada meeting terjadwal. PIC kalian akan menghubungi untuk menjadwalkan.</p>
    <?php else: foreach ($meet as $m):
      $mulai = strtotime($m['start_at']); $selesai = strtotime($m['end_at']);
      $gabung = $m['mode'] === 'zoom' ? $m['zoom_join_url'] : ($m['mode'] === 'meet' ? $m['meet_url'] : '');
      $gcal = 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' . rawurlencode($m['title'])
            . '&dates=' . gmdate('Ymd\THis\Z', $mulai) . '/' . gmdate('Ymd\THis\Z', $selesai)
            . '&details=' . rawurlencode(($gabung ? "Gabung: $gabung\n" : '') . (string) $m['notes'])
            . '&location=' . rawurlencode($m['mode'] === 'onsite' ? (string) $m['location_text'] : (string) $gabung); ?>
      <div class="meet">
        <b><?= e(hariID(date('Y-m-d', $mulai)) . ', ' . tanggalID(date('Y-m-d', $mulai))) ?> · <?= date('H.i', $mulai) ?>–<?= date('H.i', $selesai) ?> WIB</b>
        <div class="sub"><?= e(['zoom' => 'Zoom', 'meet' => 'Google Meet', 'onsite' => 'Tatap muka', 'phone' => 'Telepon'][$m['mode']] ?? '') ?>
          <?= $m['mode'] === 'onsite' && $m['location_text'] ? ' · ' . e($m['location_text']) : '' ?>
          <?= $m['mode'] === 'phone' ? ' · kami yang menelepon' : '' ?>
          <?= $m['mode'] === 'zoom' && $m['zoom_passcode'] ? ' · passcode ' . e($m['zoom_passcode']) : '' ?></div>
        <?php if (trim((string) $m['notes']) !== ''): ?><p class="ket"><?= nl2br(e($m['notes'])) ?></p><?php endif; ?>
        <div class="aksi">
          <?php if ($gabung && str_starts_with($gabung, 'https://')): ?><a class="btn isi kecil" href="<?= e($gabung) ?>" rel="noopener noreferrer">Gabung <?= $m['mode'] === 'zoom' ? 'Zoom' : 'Google Meet' ?></a><?php endif; ?>
          <?php if ($m['mode'] === 'onsite' && $m['location_text']): ?><a class="btn kecil" href="https://www.google.com/maps/search/?api=1&amp;query=<?= rawurlencode($m['location_text']) ?>" rel="noopener noreferrer">Buka peta</a><?php endif; ?>
          <a class="btn kecil" href="<?= e($gcal) ?>" rel="noopener noreferrer">Tambah ke kalender</a>
          <a class="btn kecil" href="<?= e($waTxt('Halo, kami ' . $nama . '. Mau minta jadwal ulang meeting ' . tanggalID(date('Y-m-d', $mulai)) . ' pukul ' . date('H.i', $mulai) . '.')) ?>" rel="noopener noreferrer">Minta jadwal ulang</a>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </section>

  <!-- ---------- Data keluarga ---------- -->
  <section class="kartu" id="keluarga">
    <h2>Data keluarga</h2>
    <p class="sub">Untuk undangan, naskah MC, dan susunan acara. Orang tua boleh ikut mengisi lewat tautan ini.</p>
    <?php if ($pesan['bagian'] === 'keluarga' && $pesan['ok']): ?><div class="pesan ok"><?= e($pesan['ok']) ?></div><?php endif; ?>
    <?php if ($pesan['bagian'] === 'keluarga' && $pesan['galat']): ?><div class="pesan galat"><?= e($pesan['galat']) ?></div><?php endif; ?>
    <?php if (!$bisaIsi): ?>
      <?php if ($kunci): ?><p class="kunci">Data sudah dikunci untuk cetak undangan &amp; naskah MC. Ada perubahan? Hubungi PIC kalian.</p><?php endif; ?>
      <dl class="baca">
        <?php foreach (['pria' => 'Mempelai pria', 'wanita' => 'Mempelai wanita'] as $p => $jp): ?>
          <dt><?= $jp ?></dt><dd><?= e($wi[$p . '_nama'] ?: '—') ?></dd>
          <?php foreach (['ayah', 'ibu', 'wali'] as $r): if (!isset($kel["{$p}_{$r}"])) continue; $f = $kel["{$p}_{$r}"]; ?>
            <dt><?= ucfirst($r) ?> (pihak <?= $p ?>)</dt><dd><?= e($f['nama_undangan'] ?: $f['nama']) ?><?= $f['status'] === 'almarhum' ? ' (alm.)' : '' ?></dd>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </dl>
    <?php else: ?>
      <form method="post" data-jaga>
        <input type="hidden" name="bagian" value="keluarga"><input type="hidden" name="v" value="<?= e(portalVersi($cid, 'keluarga')) ?>">
        <?php foreach (['pria' => 'Pihak mempelai pria', 'wanita' => 'Pihak mempelai wanita'] as $p => $jp): ?>
          <fieldset>
            <legend><?= $jp ?></legend>
            <div class="f"><label>Nama lengkap mempelai (dengan gelar, untuk undangan)</label>
              <input type="text" name="<?= $p ?>_nama" maxlength="190" value="<?= e($val($p . '_nama', $wi[$p . '_nama'] ?? '')) ?>"></div>
            <div class="dua tetap">
              <div class="f"><label>Anak ke</label><input type="number" name="<?= $p ?>_anak_ke" min="1" max="20" value="<?= e($val($p . '_anak_ke', $wi[$p . '_anak_ke'] ?? '')) ?>"></div>
              <div class="f"><label>Dari … bersaudara</label><input type="number" name="<?= $p ?>_dari" min="1" max="20" value="<?= e($val($p . '_dari', $wi[$p . '_dari'] ?? '')) ?>"></div>
            </div>
            <div class="f"><label>Alamat (untuk undangan)</label>
              <input type="text" name="<?= $p ?>_alamat" maxlength="255" value="" placeholder="<?= ($wi[$p . '_alamat'] ?? '') !== '' ? 'Sudah tersimpan: ' . e(mb_strimwidth((string) $wi[$p . '_alamat'], 0, 14, '…')) . ' — biarkan kosong bila tidak berubah' : 'Sesuai yang dicetak di undangan' ?>"></div>
            <?php foreach (['ayah' => 'Ayah', 'ibu' => 'Ibu', 'wali' => 'Wali (bila ada)'] as $r => $jr): $f = $kel["{$p}_{$r}"] ?? null; $k = "{$p}_{$r}"; ?>
              <?php if ($r === 'wali'): ?><details class="wali" <?= $f || $val($k . '_nama') !== '' ? 'open' : '' ?>><summary>+ Wali (bila ada)</summary><?php endif; ?>
              <div class="ortu">
                <div class="dua">
                  <div class="f"><label><?= $jr ?> — nama</label><input type="text" name="<?= $k ?>_nama" maxlength="120" value="<?= e($val($k . '_nama', $f['nama'] ?? '')) ?>"></div>
                  <div class="f"><label>Nama di undangan</label><input type="text" name="<?= $k ?>_undangan" maxlength="190" placeholder="Bapak H. … , S.E." value="<?= e($val($k . '_undangan', $f['nama_undangan'] ?? '')) ?>"></div>
                </div>
                <div class="dua">
                  <div class="f"><label>Telepon</label><input type="tel" name="<?= $k ?>_telepon" maxlength="30" value="" placeholder="<?= !empty($f['telepon']) ? e(portalSamar($f['telepon'])) . ' — kosong bila tetap' : '08…' ?>"></div>
                  <div class="f"><label>Status</label><select name="<?= $k ?>_status">
                    <option value="hidup">Masih ada</option>
                    <option value="almarhum" <?= $val($k . '_status', $f['status'] ?? '') === 'almarhum' ? 'selected' : '' ?>>Almarhum / almarhumah</option></select></div>
                </div>
              </div>
              <?php if ($r === 'wali'): ?></details><?php endif; ?>
            <?php endforeach; ?>
          </fieldset>
        <?php endforeach; ?>
        <p class="ket">Mengosongkan nama berarti baris itu dihapus. Telepon &amp; alamat yang sudah tersimpan tidak ditampilkan utuh demi privasi.</p>
        <div class="aksi"><button class="btn isi" type="submit">Simpan data keluarga</button></div>
      </form>
    <?php endif; ?>
  </section>

  <!-- ---------- Prosesi adat ---------- -->
  <section class="kartu" id="prosesi">
    <h2>Prosesi adat</h2>
    <?php if ($pesan['bagian'] === 'prosesi' && $pesan['ok']): ?><div class="pesan ok"><?= e($pesan['ok']) ?></div><?php endif; ?>
    <?php if ($pesan['bagian'] === 'prosesi' && $pesan['galat']): ?><div class="pesan galat"><?= e($pesan['galat']) ?></div><?php endif; ?>
    <?php if (!$bisaIsi): ?>
      <dl class="baca"><dt>Adat</dt><dd><?= e(PORTAL_ADAT[$wi['prosesi_adat'] ?? ''] ?? '—') ?><?= ($wi['prosesi_adat_lainnya'] ?? '') !== '' ? ' — ' . e($wi['prosesi_adat_lainnya']) : '' ?></dd>
        <dt>Urutan prosesi</dt><dd><?= nl2br(e((string) ($wi['prosesi_adat_detail'] ?: '—'))) ?></dd></dl>
    <?php else: ?>
      <form method="post" data-jaga>
        <input type="hidden" name="bagian" value="prosesi"><input type="hidden" name="v" value="<?= e(portalVersi($cid, 'prosesi')) ?>">
        <div class="dua">
          <div class="f"><label>Adat</label><select name="prosesi_adat">
            <?php foreach (PORTAL_ADAT as $kA => $vA): ?><option value="<?= e($kA) ?>" <?= $val('prosesi_adat', $wi['prosesi_adat'] ?? '') === $kA ? 'selected' : '' ?>><?= e($vA) ?></option><?php endforeach; ?></select></div>
          <div class="f"><label>Kalau suku lain, sebutkan</label><input type="text" name="prosesi_adat_lainnya" maxlength="120" value="<?= e($val('prosesi_adat_lainnya', $wi['prosesi_adat_lainnya'] ?? '')) ?>"></div>
        </div>
        <div class="f"><label>Urutan prosesi (salin apa adanya dari keluarga)</label>
          <textarea name="prosesi_adat_detail" maxlength="4000"><?= e($val('prosesi_adat_detail', $wi['prosesi_adat_detail'] ?? '')) ?></textarea></div>
        <div class="aksi"><button class="btn isi" type="submit">Simpan prosesi</button></div>
      </form>
    <?php endif; ?>
  </section>

  <!-- ---------- Referensi dekor ---------- -->
  <section class="kartu" id="dekor">
    <h2>Referensi dekor</h2>
    <p class="sub">Warna, tema, suasana yang kalian bayangkan — tempel juga tautan Pinterest/Instagram.</p>
    <?php if ($pesan['bagian'] === 'dekor' && $pesan['ok']): ?><div class="pesan ok"><?= e($pesan['ok']) ?></div><?php endif; ?>
    <?php if ($pesan['bagian'] === 'dekor' && $pesan['galat']): ?><div class="pesan galat"><?= e($pesan['galat']) ?></div><?php endif; ?>
    <?php if (!$bisaIsi): ?>
      <p><?= nl2br(e((string) ($wi['dekor_klien'] ?: '—'))) ?></p>
    <?php else: ?>
      <form method="post" data-jaga>
        <input type="hidden" name="bagian" value="dekor"><input type="hidden" name="v" value="<?= e(portalVersi($cid, 'dekor')) ?>">
        <div class="f"><textarea name="dekor_klien" maxlength="4000" placeholder="Contoh: sage & ivory, bunga segar, pelaminan kayu. https://pin.it/…"><?= e($val('dekor_klien', $wi['dekor_klien'] ?? '')) ?></textarea></div>
        <div class="aksi"><button class="btn isi" type="submit">Kirim referensi</button></div>
      </form>
    <?php endif; ?>
  </section>

  <!-- ---------- Vendor & dokumen ---------- -->
  <section class="kartu">
    <h2>Vendor terpilih</h2>
    <?php if (!$ven): ?><p class="sub">Vendor sedang kami pilihkan bersama kalian.</p>
    <?php else: ?><div class="ven"><?php foreach ($ven as $v): ?><div><small><?= e(katVendor((string) $v['category'])) ?></small><?= e($v['name']) ?></div><?php endforeach; ?></div><?php endif; ?>
    <?php if ($dok): ?>
      <p class="lab" style="margin:18px 0 4px">Dokumen</p>
      <ul class="termin">
        <?php foreach ($dok as $i => $d): ?>
          <li><div><?= $i === 0 ? 'Price list ' . e($d['paket_nama'] ?: '') : 'Tambahan' ?><span class="st"><?= e($d['nomor']) ?></span></div>
            <div class="num"><a href="<?= e(url('penawaran.php?t=' . $d['token'])) ?>" rel="noopener noreferrer">Lihat</a> ·
              <a href="<?= e(url('penawaran.php?t=' . $d['token'] . '&pdf=1&unduh=1')) ?>" rel="noopener noreferrer">PDF</a></div></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <p class="kaki">Tautan ini pribadi untuk kalian dan keluarga — mohon jangan dibagikan di grup.</p>
</main>
<div class="chat"><a class="btn isi" rel="noopener noreferrer" href="<?= e($waTxt('Halo, kami ' . $nama . ($c['wedding_date'] ? ' (hari-H ' . tanggalID($c['wedding_date']) . ')' : '') . '. ')) ?>">Chat PIC kalian · <?= e(waTampil($waPic)) ?></a></div>
</body></html>
