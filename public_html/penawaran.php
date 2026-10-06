<?php
/**
 * PRICE LIST / PENAWARAN — tautan publik untuk klien
 *
 * Dibuka dengan token acak 32 karakter (bukan id berurutan), hanya-baca,
 * tidak diindeks, dan mencatat kapan klien pertama kali membukanya
 * (quotes.seen_at) — terlihat di panel sebagai "sudah dibuka klien".
 *
 *   ?t=TOKEN         halaman yang enak dibaca di HP
 *   ?t=TOKEN&pdf=1   PDF yang sama dengan lampiran WhatsApp
 *
 * Alamat PDF inilah yang diberikan ke gateway WhatsApp untuk dilampirkan,
 * jadi harus bisa diunduh tanpa login.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/pipeline.php';
require_once __DIR__ . '/inc/penawaran.php';

header('X-Robots-Tag: noindex, nofollow');

$token = (string) ($_GET['t'] ?? '');
$qq = (strlen($token) === 32 && ctype_xdigit($token))
    ? one("SELECT id, status, seen_at, client_id, jenis, nomor FROM quotes WHERE token = ?", [$token])
    : null;

// Draf tetap bisa dibuka: tautannya baru keluar saat admin mengirim, dan
// gateway WhatsApp mengunduh PDF-nya SEBELUM statusnya berubah jadi
// terkirim. Menolak draf membuat lampiran PDF selalu gagal.
if (!$qq) {
    http_response_code(404);
    ?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex"><title>Dokumen tidak ditemukan</title>
    <style>body{font:16px/1.6 Georgia,serif;background:#F6F1E7;color:#1a1a1a;display:grid;place-items:center;min-height:100vh;margin:0;padding:24px;text-align:center}</style>
    </head><body><div><h1 style="font-weight:normal;font-style:italic">Dokumen tidak ditemukan</h1>
    <p>Tautannya mungkin terpotong saat disalin. Hubungi kami lewat WhatsApp, kami kirim ulang.</p></div></body></html><?php
    exit;
}

// Hanya kunjungan klien yang dihitung — bukan admin yang sedang mengecek,
// dan bukan server gateway WhatsApp yang mengunduh PDF untuk dilampirkan.
$ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
$bot = $ua === '' || preg_match('/bot|crawl|spider|curl|wget|python|go-http|guzzle|okhttp|fonnte|whatsapp|facebookexternalhit/', $ua);
if (empty($qq['seen_at']) && empty($_SESSION['uid']) && !$bot) {
    q("UPDATE quotes SET seen_at = NOW() WHERE id = ? AND seen_at IS NULL", [$qq['id']]);
    clientLog((int) $qq['client_id'], 'sistem', 'Klien membuka ' . ($qq['jenis'] === 'pricelist' ? 'price list' : 'penawaran') . ' ' . $qq['nomor']);
}

if (isset($_GET['pdf'])) {
    require_once __DIR__ . '/inc/pdf-penawaran.php';
    quotePdfKirim((int) $qq['id'], isset($_GET['unduh']));
}

$d  = quoteData((int) $qq['id']);
$q  = $d['q'];
$brand = setting('site_name', 'Callalily Party');
$kadaluarsa = $q['valid_until'] && $q['valid_until'] < date('Y-m-d') && $q['status'] !== 'cocok';
$waNo = $d['wa'];
?><!doctype html>
<html lang="id"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($d['jenisLbl'] . ' ' . $q['nomor'] . ' — ' . $brand) ?></title>
<style>
  * { box-sizing: border-box }
  body { font: 15px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; color: #1c1810; background: #F6F1E7; margin: 0 }
  .kertas { max-width: 760px; margin: 24px auto; background: #fff; padding: 34px 38px; border-radius: 16px; box-shadow: 0 10px 40px rgba(60,40,10,.08) }
  @media (max-width: 640px) { .kertas { margin: 0; border-radius: 0; padding: 24px 18px } }
  h1 { font: italic 600 28px/1.1 Georgia, serif; margin: 0 }
  .kop { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; border-bottom: 2px solid #1c1810; padding-bottom: 14px; margin-bottom: 18px }
  .kop .jenis { text-align: right; font-size: 12.5px; color: #6e685e }
  .kop .jenis b { display: block; color: #a9651b; font-size: 15px; letter-spacing: .08em }
  .sub { color: #6e685e; font-size: 13px; margin: 2px 0 0 }
  .info { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 18px }
  .info small { display: block; font-size: 10.5px; letter-spacing: .1em; text-transform: uppercase; color: #a09a90 }
  .paket { background: #F7F3EA; border: 1px solid #ded6c8; border-radius: 12px; padding: 16px 18px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap }
  .paket small { display: block; font-size: 10.5px; letter-spacing: .14em; color: #a9651b }
  .paket .nm { font: italic 600 24px/1.1 Georgia, serif }
  .paket .hg { font-size: 22px; font-weight: 700 }
  h3 { font-size: 11px; letter-spacing: .14em; text-transform: uppercase; color: #a9651b; margin: 24px 0 8px; padding-bottom: 6px; border-bottom: 1px solid #ded6c8 }
  .kel { font-weight: 700; margin: 12px 0 4px; font-size: 14px }
  ul { margin: 0; padding-left: 20px }
  li { margin: 2px 0 }
  li span { color: #6e685e; font-size: 13.5px }
  .baris { display: flex; justify-content: space-between; gap: 12px; padding: 6px 0; border-bottom: 1px solid #f0ebe1 }
  .baris span { color: #6e685e; font-size: 13px; display: block }
  .tot { margin: 16px 0 0 auto; max-width: 340px }
  .tot .baris { border: 0; padding: 3px 0 }
  .tot .grand { border-top: 2px solid #1c1810; margin-top: 6px; padding-top: 10px; font-size: 19px; font-weight: 700 }
  .num { white-space: nowrap; font-variant-numeric: tabular-nums }
  table { width: 100%; border-collapse: collapse; font-size: 14px }
  td, th { padding: 7px 4px; border-bottom: 1px solid #f0ebe1; text-align: left }
  th { font-size: 10.5px; letter-spacing: .1em; color: #a09a90; font-weight: 400; text-transform: uppercase }
  td.r, th.r { text-align: right }
  .note { white-space: pre-wrap; color: #4a453d; font-size: 13.5px }
  .status { display: inline-block; font: 600 11px/1 system-ui; letter-spacing: .06em; text-transform: uppercase; padding: 6px 10px; border-radius: 99px; background: #F3E6D3; color: #87500F; margin-top: 6px }
  .status.ok { background: #E3EEDD; color: #3F6B2F } .status.bad { background: #F6E0DC; color: #9C3A2B }
  .aksi { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; margin-top: 28px }
  .aksi a { font: 600 14px/1 system-ui; padding: 13px 20px; border-radius: 99px; border: 1px solid #1c1810; color: #1c1810; text-decoration: none }
  .aksi .wa { background: #1E8E4E; border-color: #1E8E4E; color: #fff }
</style></head><body>
<div class="kertas">
  <div class="kop">
    <div><h1><?= e($brand) ?></h1><p class="sub"><?= e(setting('site_tagline', 'Wedding Organizer · Yogyakarta')) ?></p></div>
    <div class="jenis"><b><?= e(mb_strtoupper($d['jenisLbl'])) ?></b>
      <?= e($q['nomor']) ?><?= $q['revisi'] > 1 ? ' · rev ' . (int) $q['revisi'] : '' ?><br>
      <?php if ($q['valid_until']): ?>Berlaku s/d <?= tanggalID($q['valid_until']) ?><br><?php endif; ?>
      <?php if ($q['status'] === 'cocok'): ?><span class="status ok">Disetujui</span>
      <?php elseif ($q['status'] === 'tidak_cocok'): ?><span class="status bad">Tidak berlaku</span>
      <?php elseif ($q['status'] === 'revisi'): ?><span class="status">Ada versi terbaru</span>
      <?php elseif ($kadaluarsa): ?><span class="status bad">Masa berlaku habis</span><?php endif; ?>
    </div>
  </div>

  <div class="info">
    <div><small>Untuk</small><?= e($d['nama']) ?></div>
    <?php if ($q['wedding_date']): ?><div><small>Tanggal acara</small><?= tanggalID($q['wedding_date']) ?></div><?php endif; ?>
    <?php if ($q['venue'] || $q['city']): ?><div><small>Lokasi</small><?= e($q['venue'] ?: $q['city']) ?></div><?php endif; ?>
    <?php if ($q['guest_estimate']): ?><div><small>Perkiraan tamu</small>±<?= number_format((int) $q['guest_estimate'], 0, ',', '.') ?></div><?php endif; ?>
  </div>

  <?php if ($d['paket']): ?>
    <div class="paket">
      <div><small>PAKET</small><span class="nm"><?= e($q['paket_nama']) ?></span></div>
      <span class="hg num"><?= (float) $q['paket_harga'] > 0 ? e(rupiah((float) $q['paket_harga'])) : 'Harga dikonfirmasi admin' ?></span>
    </div>
  <?php endif; ?>

  <?php if ($d['isi']): ?>
    <h3><?= $d['paket'] ? 'Termasuk dalam paket' : 'Rincian' ?></h3>
    <?php foreach ($d['isi'] as $kel => $baris): ?>
      <div class="kel"><?= e($kel) ?></div>
      <ul><?php foreach ($baris as $it): $x = array_filter([$it['detail'], qtyTeks($it)]); ?>
        <li><?= e($it['label']) ?><?php if ($x): ?> <span>— <?= e(implode(' · ', $x)) ?></span><?php endif; ?></li>
      <?php endforeach; ?></ul>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($d['tambahan']): ?>
    <h3><?= $d['paket'] ? 'Tambahan' : 'Rincian berbiaya' ?></h3>
    <?php foreach ($d['tambahan'] as $it): ?>
      <div class="baris"><div><?= e($it['label']) ?><?php if ($it['detail']): ?><span><?= e($it['detail']) ?></span><?php endif; ?></div>
        <div class="num"><?= e(rupiah((float) $it['jumlah'])) ?></div></div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="tot">
    <?php if ($d['paket'] && (float) $q['paket_harga'] > 0 && ($d['tambahan'] || (float) $q['diskon'] > 0)): ?>
      <div class="baris"><div>Harga paket</div><div class="num"><?= e(rupiah((float) $q['paket_harga'])) ?></div></div>
      <?php if ($d['tambahan']): ?><div class="baris"><div>Tambahan</div><div class="num"><?= e(rupiah(array_sum(array_map(fn($i) => (float) $i['jumlah'], $d['tambahan'])))) ?></div></div><?php endif; ?>
    <?php endif; ?>
    <?php if ((float) $q['diskon'] > 0): ?><div class="baris"><div>Potongan</div><div class="num">− <?= e(rupiah((float) $q['diskon'])) ?></div></div><?php endif; ?>
    <div class="baris grand"><div>Total</div><div class="num"><?= (float) $q['total'] > 0 ? e(rupiah((float) $q['total'])) : 'Dikonfirmasi admin' ?></div></div>
  </div>

  <?php if ($d['opsi']): ?>
    <h3>Bisa ditambahkan — di luar total</h3>
    <?php foreach ($d['opsi'] as $it): ?>
      <div class="baris"><div><?= e($it['label']) ?><?php if ($it['detail']): ?><span><?= e($it['detail']) ?></span><?php endif; ?></div>
        <div class="num"><?= (float) $it['jumlah'] > 0 ? e(rupiah((float) $it['jumlah'])) : 'tanya admin' ?></div></div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($d['termin']): ?>
    <h3>Pembayaran</h3>
    <table><thead><tr><th>Termin</th><th class="r">Nominal</th><th class="r">Paling lambat</th></tr></thead><tbody>
      <?php foreach ($d['termin'] as $t): ?>
        <tr><td><?= e($t['label']) ?><?= $t['persen'] !== null ? ' <span style="color:#a09a90">(' . e(persenTeks($t['persen'])) . '%)</span>' : '' ?></td>
            <td class="r num"><?= e(rupiah($t['amount'])) ?></td><td class="r"><?= $t['due_date'] ? tanggalID($t['due_date']) : '—' ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php if ($d['rekening']): ?><p style="margin:10px 0 0"><b>Transfer ke:</b> <?= e(implode(' ', $d['rekening'])) ?></p><?php endif; ?>
  <?php endif; ?>

  <?php if (trim((string) $q['catatan']) !== ''): ?>
    <h3>Catatan & ketentuan</h3>
    <div class="note"><?= e($q['catatan']) ?></div>
  <?php endif; ?>

  <div class="aksi">
    <a class="wa" target="_blank" rel="noopener"
       href="https://wa.me/<?= e($waNo) ?>?text=<?= rawurlencode('Halo ' . $brand . ', saya ' . $d['nama'] . ' — soal ' . strtolower($d['jenisLbl']) . ' ' . $q['nomor'] . '.') ?>">Balas lewat WhatsApp</a>
    <a href="?t=<?= e($token) ?>&pdf=1&unduh=1">Unduh PDF</a>
  </div>
</div>
</body></html>
