<?php
/**
 * PRICE LIST — paket yang tampil di situs (/pricelist)
 *
 * Diisi dari panel: Paket & price list. Paket yang harganya belum diisi tetap
 * tampil dengan "harga dikirim lewat WhatsApp" — angka harga hanya boleh
 * datang dari owner, tidak pernah dikarang.
 *
 * Tombol "Pilih paket ini" membawa slug paket ke formulir, jadi admin early
 * langsung tahu paket mana yang diminati dan price list-nya tinggal dikirim.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/pipeline.php';
require_once __DIR__ . '/inc/paket.php';

$paket   = paketDaftar(true);
$formUrl = setting('form_url', url('form'));
$sambung = str_contains($formUrl, '?') ? '&' : '?';
$waNo    = waNomorPublik();

$seo = [
    'title'     => 'Price List Paket Wedding Organizer Yogyakarta — ' . setting('site_name', 'Callalily Party'),
    'desc'      => 'Paket wedding organizer Callalily Party: isi paket, perkiraan tamu, dan harga. '
                 . 'Pilih paket, kirim data, kami balas dengan price list lengkap dalam bentuk PDF.',
    'canonical' => url('pricelist'),
];
$crumbs = [['Beranda', url()], ['Price list', '']];
$extraCss = <<<'CSS'
<style>
.pl-head{padding:34px 0 10px;max-width:720px}
.pl-head h1{font-family:var(--serif);font-style:italic;font-weight:400;font-size:clamp(34px,5vw,54px);line-height:1.05;margin:14px 0 12px}
.pl-head p{color:var(--ivory-60);font-size:16px}
.pl-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:18px;margin:34px 0 10px}
.pl{position:relative;border:1px solid var(--ivory-12);border-radius:18px;padding:26px 24px 22px;background:rgba(255,255,255,.02);
  display:flex;flex-direction:column;scroll-margin-top:90px}
.pl.unggul{border-color:var(--ember);background:linear-gradient(180deg,rgba(233,168,92,.08),transparent 60%)}
.pl .tanda{position:absolute;top:-11px;left:22px;background:var(--ember);color:var(--ink);font-family:var(--mono);
  font-size:9.5px;letter-spacing:.16em;text-transform:uppercase;padding:4px 10px;border-radius:99px;font-weight:600}
.pl h2{font-family:var(--serif);font-style:italic;font-weight:400;font-size:32px;line-height:1.1}
.pl .ring{color:var(--ivory-60);font-size:14px;margin:6px 0 16px;min-height:2.6em}
.pl .harga{font-family:var(--serif);font-size:30px;color:var(--ember);line-height:1.1}
.pl .harga small{display:block;font-family:var(--mono);font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:var(--ivory-38);margin-bottom:5px}
.pl .harga.kosong{font-size:17px;font-family:var(--sans);color:var(--ivory-60)}
.pl .tamu{font-family:var(--mono);font-size:11px;letter-spacing:.12em;color:var(--ivory-38);margin:10px 0 16px;text-transform:uppercase}
.pl .kel{font-family:var(--mono);font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:var(--ember);margin:12px 0 5px}
.pl ul{list-style:none;padding:0;margin:0}
.pl li{font-size:14px;padding:3px 0 3px 18px;position:relative;color:var(--ivory)}
.pl li::before{content:"";position:absolute;left:2px;top:12px;width:7px;height:1px;background:var(--ember)}
.pl li span{color:var(--ivory-38);font-size:12.5px}
.pl .opsi{margin-top:16px;padding-top:12px;border-top:1px dashed var(--ivory-12)}
.pl .opsi li::before{background:var(--ivory-38)}
.pl .cta{display:flex;flex-direction:column;gap:9px;margin-top:auto;padding-top:22px}
.pl .cta .btn{justify-content:center}
.pl-catatan{max-width:760px;color:var(--ivory-60);font-size:14px;margin:30px 0 0;padding:18px 20px;border-left:2px solid var(--ember);background:rgba(255,255,255,.02)}
.pl-kosong{padding:60px 0;color:var(--ivory-60)}
</style>
CSS;
require __DIR__ . '/partials/public-head.php';
?>

<header class="pl-head">
  <span class="tstamp"><?= te('PRICE LIST') ?></span>
  <h1><?= te('Paket pernikahan') ?></h1>
  <p><?= te('Titik awal yang paling sering kami pegang. Pilih yang paling dekat dengan bayangan kalian — isinya masih bisa disesuaikan, dan rincian finalnya kami kirim sebagai PDF lewat WhatsApp.') ?></p>
</header>

<?php if (!$paket): ?>
  <div class="pl-kosong">
    <p><?= te('Daftar paket sedang kami perbarui.') ?></p>
    <p style="margin-top:14px"><a class="btn solid" href="<?= e($formUrl) ?>"><?= te('Ceritakan rencanamu') ?></a></p>
  </div>
<?php else: ?>
  <div class="pl-grid">
    <?php foreach ($paket as $t):
      $isi = paketIsi((int) $t['id']);
      $hl  = paketHargaLabel($t);
      $slug = $t['slug'] ?: ('paket-' . (int) $t['id']); ?>
      <article class="pl<?= !empty($t['unggulan']) ? ' unggul' : '' ?>" id="<?= e($slug) ?>">
        <?php if (!empty($t['unggulan'])): ?><span class="tanda"><?= te('Paling dipilih') ?></span><?php endif; ?>
        <h2><?= e($t['nama']) ?></h2>
        <p class="ring"><?= e((string) $t['ringkas']) ?></p>
        <?php if ($hl): ?>
          <div class="harga"><small><?= !empty($t['harga_mulai']) ? te('Mulai dari') : te('Harga paket') ?></small><?= e(rupiah((float) $t['harga'])) ?></div>
        <?php else: ?>
          <div class="harga kosong"><?= te('Harga dikirim lewat WhatsApp') ?></div>
        <?php endif; ?>
        <?php if (!empty($t['tamu'])): ?><div class="tamu">±<?= number_format((int) $t['tamu'], 0, ',', '.') ?> <?= te('tamu') ?></div><?php else: ?><div class="tamu"></div><?php endif; ?>

        <?php foreach ($isi['isi'] as $kel => $baris): ?>
          <div class="kel"><?= e($kel) ?></div>
          <ul><?php foreach ($baris as $r): $x = array_filter([$r['detail'], qtyTeks($r)]); ?>
            <li><?= e($r['label']) ?><?php if ($x): ?> <span>· <?= e(implode(' · ', $x)) ?></span><?php endif; ?></li>
          <?php endforeach; ?></ul>
        <?php endforeach; ?>

        <?php if ($isi['opsi']): ?>
          <div class="opsi"><div class="kel" style="margin-top:0"><?= te('Bisa ditambahkan') ?></div>
            <ul><?php foreach ($isi['opsi'] as $r): ?>
              <li><?= e($r['label']) ?><?php if ((float) $r['harga'] > 0): ?> <span>· <?= e(rupiah((float) $r['harga'])) ?></span><?php endif; ?></li>
            <?php endforeach; ?></ul></div>
        <?php endif; ?>

        <div class="cta">
          <a class="btn solid" href="<?= e($formUrl . $sambung . 'paket=' . rawurlencode($slug)) ?>"><?= te('Pilih paket ini') ?></a>
          <a class="btn" target="_blank" rel="noopener"
             href="https://wa.me/<?= e($waNo) ?>?text=<?= rawurlencode('Halo Callalily, saya tertarik paket ' . $t['nama'] . '. Boleh minta price list lengkapnya?') ?>"><?= te('Tanya lewat WhatsApp') ?></a>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <p class="pl-catatan"><?= te('Harga paket dapat menyesuaikan tanggal, lokasi, dan jumlah tamu. DP 30% mengunci tanggal; setelah itu admin kami menemani penyusunan biodata, dekorasi, venue, vendor, dan jadwal meeting sampai hari-H.') ?></p>
<?php endif; ?>

<?php require __DIR__ . '/partials/public-foot.php';
