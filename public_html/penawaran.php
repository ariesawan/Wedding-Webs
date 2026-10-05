<?php
/**
 * PENAWARAN — tautan publik untuk klien
 *
 * Teks WhatsApp penawaran selalu memuat "Rincian lengkap: …/penawaran.php?t=…".
 * Berkas di alamat itu dulu adalah salinan nyasar admin/penawaran.php yang
 * mencoba memuat _layout.php dari luar public_html — jadi setiap klien yang
 * mengetuk tautannya mendapat galat 500.
 *
 * Halaman ini hanya-baca, dibuka dengan token acak 32 karakter (bukan id
 * berurutan), tidak diindeks mesin pencari, dan mencatat kapan klien pertama
 * kali membukanya ke quotes.seen_at — tanda yang selama ini tidak pernah terisi.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/pipeline.php';

header('X-Robots-Tag: noindex, nofollow');

$token = (string) ($_GET['t'] ?? '');
$qq = (strlen($token) === 32 && ctype_xdigit($token))
    ? one("SELECT q.*, c.name, c.partner_name, c.wedding_date, c.venue, c.guest_estimate, c.city
           FROM quotes q JOIN clients c ON c.id = q.client_id WHERE q.token = ?", [$token])
    : null;

if (!$qq || $qq['status'] === 'draf') {
    http_response_code(404);
    ?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex"><title>Penawaran tidak ditemukan</title>
    <style>body{font:16px/1.6 Georgia,serif;background:#F6F1E7;color:#1a1a1a;display:grid;place-items:center;min-height:100vh;margin:0;padding:24px;text-align:center}</style>
    </head><body><div><h1 style="font-weight:normal;font-style:italic">Penawaran tidak ditemukan</h1>
    <p>Tautannya mungkin terpotong saat disalin. Hubungi kami lewat WhatsApp, kami kirim ulang.</p></div></body></html><?php
    exit;
}

// Hanya kunjungan klien yang dihitung, bukan admin yang sedang mengecek.
if (empty($qq['seen_at']) && empty($_SESSION['uid'])) {
    q("UPDATE quotes SET seen_at = NOW() WHERE id = ? AND seen_at IS NULL", [$qq['id']]);
    clientLog((int) $qq['client_id'], 'sistem', 'Klien membuka ' . ($qq['jenis'] === 'pricelist' ? 'price list' : 'penawaran') . ' ' . $qq['nomor']);
}

$items = all("SELECT * FROM quote_items WHERE quote_id = ? ORDER BY sort_order, id", [$qq['id']]);
$wajib = array_filter($items, fn($i) => !$i['opsional']);
$opsi  = array_filter($items, fn($i) => $i['opsional']);
$brand = setting('site_name', 'Callalily Party');
$ang   = fn($n) => number_format((float) $n, 0, ',', '.');
$rpc   = fn($n) => setting('currency_prefix', 'Rp') . ' ' . number_format((float) $n, 0, ',', '.');
$nama  = $qq['name'] . ($qq['partner_name'] ? ' & ' . $qq['partner_name'] : '');
$termin = $qq['jenis'] === 'penawaran' && (float) $qq['total'] > 0
        ? terminKlien((float) $qq['total'], $qq['wedding_date']) : [];
$kadaluarsa = $qq['valid_until'] && $qq['valid_until'] < date('Y-m-d')
            && !in_array($qq['status'], ['cocok'], true);
$waNo = preg_replace('/\D/', '', (string) setting('wa_number'));
if (str_starts_with($waNo, '0')) $waNo = '62' . substr($waNo, 1);
?><!doctype html>
<html lang="id"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(($qq['jenis'] === 'pricelist' ? 'Price list' : 'Penawaran') . ' ' . $qq['nomor'] . ' — ' . $brand) ?></title>
<style>
  @page { size: A4; margin: 16mm 14mm; }
  * { box-sizing: border-box }
  body { font: 15px/1.6 Georgia,'Times New Roman',serif; color: #1a1a1a; background: #F6F1E7; margin: 0 }
  .kertas { max-width: 820px; margin: 28px auto; background: #fff; padding: 40px 44px;
            border-radius: 14px; box-shadow: 0 10px 40px rgba(60,40,10,.08) }
  @media (max-width: 640px) { .kertas { margin: 0; border-radius: 0; padding: 26px 18px } }
  @media print { body { background: #fff } .kertas { box-shadow: none; margin: 0; padding: 0 } .no-print { display: none } }
  h1 { font-size: 26px; margin: 0 0 2px; font-weight: normal; font-style: italic }
  .sub { color: #777; font-size: 13px; margin: 0 }
  .head { display: flex; justify-content: space-between; gap: 18px; flex-wrap: wrap;
          border-bottom: 2px solid #1a1a1a; padding-bottom: 14px; margin-bottom: 20px }
  .meta { text-align: right; font-size: 13px; color: #444 }
  @media (max-width: 640px) { .meta { text-align: left } }
  .info { display: flex; gap: 28px; flex-wrap: wrap; margin-bottom: 22px; font-size: 14px }
  .info b { display: block; font-size: 10.5px; text-transform: uppercase; letter-spacing: 1.2px;
            color: #999; font-weight: normal; margin-bottom: 2px }
  table { width: 100%; border-collapse: collapse; margin-bottom: 8px }
  th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: 1.2px;
       color: #999; font-weight: normal; border-bottom: 1px solid #ccc; padding: 8px 6px }
  td { padding: 10px 6px; border-bottom: 1px solid #eee; vertical-align: top }
  td.r, th.r { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums }
  .det { font-size: 12.5px; color: #777; display: block; margin-top: 2px }
  .tot { width: 100%; max-width: 360px; margin-left: auto }
  .tot td { border: none; padding: 5px 6px }
  .tot .grand td { border-top: 2px solid #1a1a1a; font-size: 18px; font-weight: bold; padding-top: 10px }
  h3 { font-size: 11px; text-transform: uppercase; letter-spacing: 1.2px; color: #999; font-weight: normal; margin: 28px 0 6px }
  .note { margin-top: 24px; padding-top: 14px; border-top: 1px solid #ddd; font-size: 13.5px; color: #444; white-space: pre-wrap }
  .status { display: inline-block; font: 600 11px/1 system-ui, sans-serif; letter-spacing: .6px; text-transform: uppercase;
            padding: 6px 10px; border-radius: 99px; background: #F3E6D3; color: #87500F; margin-top: 8px }
  .status.ok { background: #E3EEDD; color: #3F6B2F }
  .status.bad { background: #F6E0DC; color: #9C3A2B }
  .aksi { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; margin: 28px 0 0 }
  .aksi a, .aksi button { font: 600 14px/1 system-ui, sans-serif; padding: 13px 20px; border-radius: 99px; cursor: pointer;
            border: 1px solid #1a1a1a; background: #fff; color: #1a1a1a; text-decoration: none }
  .aksi .wa { background: #1E8E4E; border-color: #1E8E4E; color: #fff }
  .foot { margin-top: 30px; font-size: 12px; color: #999; text-align: center }
</style></head><body>
<div class="kertas">
  <div class="head">
    <div>
      <h1><?= e($brand) ?></h1>
      <p class="sub"><?= e(setting('site_tagline', 'Wedding Organizer · Yogyakarta')) ?></p>
    </div>
    <div class="meta">
      <b><?= $qq['jenis'] === 'pricelist' ? 'PRICE LIST' : 'PENAWARAN' ?></b><br>
      <?= e($qq['nomor']) ?><?= $qq['revisi'] > 1 ? ' · Revisi ' . (int) $qq['revisi'] : '' ?><br>
      <?= tanggalID(substr($qq['created_at'], 0, 10)) ?>
      <?php if ($qq['valid_until']): ?><br>Berlaku sampai <?= tanggalID($qq['valid_until']) ?><?php endif; ?>
      <br>
      <?php if ($qq['status'] === 'cocok'): ?><span class="status ok">Disetujui</span>
      <?php elseif ($qq['status'] === 'tidak_cocok'): ?><span class="status bad">Tidak berlaku</span>
      <?php elseif ($qq['status'] === 'revisi'): ?><span class="status">Sudah ada revisi terbaru</span>
      <?php elseif ($kadaluarsa): ?><span class="status bad">Masa berlaku habis</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="info">
    <div><b>Untuk</b><?= e($nama) ?></div>
    <?php if ($qq['wedding_date']): ?><div><b>Tanggal acara</b><?= tanggalID($qq['wedding_date']) ?></div><?php endif; ?>
    <?php if ($qq['venue']): ?><div><b>Venue</b><?= e($qq['venue']) ?></div><?php endif; ?>
    <?php if ($qq['guest_estimate']): ?><div><b>Tamu</b><?= number_format((int) $qq['guest_estimate'], 0, ',', '.') ?> undangan</div><?php endif; ?>
  </div>

  <?php if ($qq['tipe'] === 'budgeting' && (float) $qq['plafon'] > 0): ?>
    <p class="sub" style="margin-bottom:14px">Disusun menyesuaikan anggaran <?= $rpc($qq['plafon']) ?>.</p>
  <?php endif; ?>

  <table>
    <thead><tr><th style="width:55%">Uraian</th><th class="r">Qty</th><th class="r">Harga (Rp)</th><th class="r">Jumlah (Rp)</th></tr></thead>
    <tbody>
      <?php foreach ($wajib as $it): ?>
        <tr>
          <td><?= e($it['label']) ?><?php if ($it['detail']): ?><span class="det"><?= e($it['detail']) ?></span><?php endif; ?></td>
          <td class="r"><?= rtrim(rtrim(number_format((float) $it['qty'], 2, ',', '.'), '0'), ',') ?> <?= e($it['satuan']) ?></td>
          <td class="r"><?= $ang($it['harga']) ?></td>
          <td class="r"><?= $ang($it['jumlah']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <table class="tot">
    <tr><td>Subtotal</td><td class="r"><?= $rpc($qq['subtotal']) ?></td></tr>
    <?php if ((float) $qq['diskon'] > 0): ?><tr><td>Potongan</td><td class="r">− <?= $rpc($qq['diskon']) ?></td></tr><?php endif; ?>
    <tr class="grand"><td>Total</td><td class="r"><?= $rpc($qq['total']) ?></td></tr>
  </table>

  <?php if ($opsi): ?>
    <h3>Bisa ditambahkan — di luar total (Rp)</h3>
    <table><tbody>
      <?php foreach ($opsi as $it): ?>
        <tr><td><?= e($it['label']) ?><?php if ($it['detail']): ?><span class="det"><?= e($it['detail']) ?></span><?php endif; ?></td>
            <td class="r"><?= $ang($it['jumlah']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>

  <?php if ($termin): ?>
    <h3>Termin pembayaran</h3>
    <table><tbody>
      <?php foreach ($termin as $t): ?>
        <tr>
          <td><?= e($t['label']) ?><?php if ($t['persen'] !== null): ?> <span class="det" style="display:inline">(<?= rtrim(rtrim(number_format((float) $t['persen'], 2, ',', '.'), '0'), ',') ?>%)</span><?php endif; ?></td>
          <td class="r"><?= $t['due_date'] ? 'paling lambat ' . tanggalID($t['due_date']) : '' ?></td>
          <td class="r"><?= $rpc($t['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>

  <?php if (trim((string) $qq['catatan']) !== ''): ?>
    <div class="note"><?= e($qq['catatan']) ?></div>
  <?php endif; ?>

  <div class="aksi no-print">
    <?php if ($waNo): ?>
      <a class="wa" target="_blank" rel="noopener"
         href="https://wa.me/<?= e($waNo) ?>?text=<?= rawurlencode('Halo ' . $brand . ', saya ' . $nama . ' — soal ' . ($qq['jenis'] === 'pricelist' ? 'price list' : 'penawaran') . ' ' . $qq['nomor'] . '.') ?>">Tanya lewat WhatsApp</a>
    <?php endif; ?>
    <button type="button" onclick="window.print()">Simpan sebagai PDF</button>
  </div>

  <div class="foot"><?= e($brand) ?><?= $waNo ? ' · WhatsApp ' . e(setting('wa_number')) : '' ?></div>
</div>
</body></html>
