<?php
/**
 * Halaman kategori vendor.
 *
 * Dua tampilan dalam satu berkas:
 *   /vendor          daftar semua kategori
 *   /vendor/venue    penjelasan satu kategori
 *
 * Kenapa ini dibuat: pencarian jasa pernikahan hampir selalu dimulai per
 * jenis, bukan per penyelenggara. Orang mengetik "dekorasi pernikahan jogja"
 * jauh lebih sering daripada "wedding organizer jogja". Halaman kategori
 * menangkap pencarian itu, lalu mengantarnya ke formulir — bukan sebaliknya
 * berharap orang menemukan beranda lebih dulu.
 *
 * Isi tiap halaman diambil dari tabel, jadi bisa disunting owner dari panel
 * tanpa menyentuh kode.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/partials/ikon-vendor.php';

$slug = trim($_GET['k'] ?? '');

/* ============================================================
   SATU KATEGORI
   ============================================================ */
if ($slug !== '') {
    try {
        $kat = one("SELECT * FROM vendor_categories WHERE slug = ? AND is_public = 1", [$slug]);
    } catch (Throwable $e) {
        // Kolom is_public baru ada sejak migration-v14. Tanpa penjaga ini,
        // halaman mati dengan 500 polos dan tidak ada petunjuk apa pun.
        http_response_code(503);
        exit('Halaman kategori belum siap — jalankan migration-v14.sql lebih dulu.');
    }
    if (!$kat) { http_response_code(404); require __DIR__ . '/404.php'; exit; }

    $anak = all("SELECT nama, slug FROM vendor_categories
                 WHERE parent_id = ? AND is_active = 1 ORDER BY urutan", [$kat['id']]);

    $lain = all("SELECT nama, slug, ikon, ringkas, gambar FROM vendor_categories
                 WHERE is_public = 1 AND id <> ? ORDER BY RAND() LIMIT 4", [$kat['id']]);

    $judul = $kat['seo_title'] ?: ($kat['nama'] . ' Pernikahan Yogyakarta — Callalily Party');
    $desk  = $kat['seo_desc']  ?: $kat['ringkas'];

    // public-head.php membaca variabel $seo, bukan hasil seoHead() yang
    // dipanggil sendiri — seoHead() MENGEMBALIKAN string, tidak mencetak.
    // Memanggilnya langsung di sini membuat seluruh tag kepala hilang, dan
    // kunci 'description' pun diabaikan karena yang dibaca adalah 'desc'.
    $seo = [
        'title'     => $judul,
        'desc'      => $desk,
        'canonical' => url('vendor/' . $kat['slug']),
        'jsonld'    => [
            // ldBreadcrumb() membongkar tiap baris sebagai [$nama, $url] —
            // bentuk POSISIONAL. Array asosiatif diterima tanpa galat tapi
            // menghasilkan nama dan tautan kosong di data terstruktur.
            ldBreadcrumb([
                ['Beranda',      url()],
                ['Jenis vendor', url('vendor')],
                [$kat['nama'],   url('vendor/' . $kat['slug'])],
            ]),
            ldService(),
        ],
    ];
    // Jejak halaman digambar kerangka publik lewat $crumbs — bukan ditulis
    // ulang di sini. Dua penggambar untuk hal yang sama akan berbeda gaya
    // begitu salah satunya disentuh.
    $crumbs = [
        ['Beranda',      url()],
        ['Jenis vendor', url('vendor')],
        [$kat['nama'],   null],
    ];
    require __DIR__ . '/partials/public-head.php';
    ?>
    <div class="vk">
      <?php if ($kat['gambar'] !== ''): ?>
        <?php
          // width/height ditulis eksplisit supaya peramban menyiapkan ruangnya
          // sebelum gambar turun. Tanpa itu halaman melompat saat gambar muncul,
          // dan Google menghitungnya sebagai Cumulative Layout Shift.
          $g = url('foto/' . $kat['gambar']);
        ?>
        <figure class="vk-hero">
          <?php if ($kat['gambar_webp']): ?>
            <picture>
              <source srcset="<?= e($g) ?>.webp" type="image/webp">
              <img src="<?= e($g) ?>.jpg" alt="<?= e($kat['gambar_alt'] ?: $kat['nama']) ?>"
                   width="<?= (int) $kat['gambar_w'] ?>" height="<?= (int) $kat['gambar_h'] ?>"
                   fetchpriority="high" decoding="async">
            </picture>
          <?php else: ?>
            <img src="<?= e($g) ?>.jpg" alt="<?= e($kat['gambar_alt'] ?: $kat['nama']) ?>"
                 width="<?= (int) $kat['gambar_w'] ?>" height="<?= (int) $kat['gambar_h'] ?>"
                 fetchpriority="high" decoding="async">
          <?php endif; ?>
        </figure>
      <?php endif; ?>

      <header class="vk-head">
        <span class="vk-ikon"><?= ikonVendor($kat['ikon'], 34) ?></span>
        <h1><?= e($kat['nama']) ?></h1>
        <?php if ($kat['ringkas']): ?><p class="vk-lead"><?= e($kat['ringkas']) ?></p><?php endif; ?>
        <?php if ($kat['kisaran']): ?>
          <p class="vk-harga"><span><?= te('Kisaran di Yogyakarta') ?></span> <b><?= e($kat['kisaran']) ?></b></p>
          <p class="vk-harga-nb"><?= te('Angka kasar dari acara yang pernah kami pegang. Bukan penawaran, dan sangat bergantung tanggal serta jumlah tamu.') ?></p>
        <?php endif; ?>
      </header>

      <?php if ($anak): ?>
        <section class="vk-blok">
          <h2><?= te('Yang termasuk di dalamnya') ?></h2>
          <ul class="vk-chip">
            <?php foreach ($anak as $a): ?><li><?= e($a['nama']) ?></li><?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php if (trim((string) $kat['isi']) !== ''): ?>
        <section class="vk-blok vk-isi"><?= $kat['isi'] /* HTML sederhana dari panel */ ?></section>
      <?php else: ?>
        <section class="vk-blok">
          <h2><?= te('Bagaimana kami menanganinya') ?></h2>
          <p><?= te('Kami tidak menjual jenis vendor ini sendiri. Yang kami lakukan: mencocokkannya dengan susunan hari kalian, menyatukan jadwalnya dengan vendor lain, dan berdiri di antara kalian dan semua pertanyaan teknis di hari-H.') ?></p>
          <p><?= te('Vendor yang sudah kalian pilih sendiri tetap dipakai. Kami tidak memaksa mengganti.') ?></p>
        </section>
      <?php endif; ?>

      <?php
      $cek = array_values(array_filter(array_map('trim', explode("\n", (string) $kat['dicek']))));
      if ($cek): ?>
        <section class="vk-blok">
          <h2><?= te('Yang perlu ditanyakan sebelum memesan') ?></h2>
          <ol class="vk-tanya">
            <?php foreach ($cek as $c): ?><li><?= e($c) ?></li><?php endforeach; ?>
          </ol>
        </section>
      <?php endif; ?>

      <section class="vk-cta">
        <h2><?= te('Belum tahu butuh yang mana?') ?></h2>
        <p><?= te('Isi susunan harimu. Kami baca dulu, baru kirim rincian biaya dan ketersediaan tanggal.') ?></p>
        <a class="btn solid" href="<?= e(setting('form_url', url('form.php'))) ?>?dari=<?= e($kat['slug']) ?>">
          <?= te('Isi data & minta penawaran') ?></a>
      </section>

      <?php if ($lain): ?>
        <section class="vk-blok">
          <h2><?= te('Jenis lainnya') ?></h2>
          <div class="vk-grid">
            <?php foreach ($lain as $l): ?>
              <a class="vk-card" href="<?= url('vendor/' . $l['slug']) ?>">
                <?php if (!empty($l['gambar'])): ?>
                  <span class="vk-card-g"><img src="<?= e(url('foto/' . $l['gambar'] . '-thumb.jpg')) ?>"
                    alt="" loading="lazy" decoding="async"></span>
                <?php endif; ?>
                <span class="vk-card-i"><?= ikonVendor($l['ikon']) ?></span>
                <h3><?= e($l['nama']) ?></h3>
                <p><?= e($l['ringkas']) ?></p>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    </div>
    <?php
    require __DIR__ . '/partials/public-foot.php';
    exit;
}

/* ============================================================
   DAFTAR SEMUA KATEGORI
   ============================================================ */
try {
    $semua = all("SELECT nama, slug, ikon, ringkas, kisaran, gambar FROM vendor_categories
                  WHERE is_public = 1 ORDER BY urutan, nama");
} catch (Throwable $e) {
    http_response_code(503);
    exit('Halaman kategori belum siap — jalankan migration-v14.sql lebih dulu.');
}

$seo = [
    'title'     => 'Jenis Vendor Pernikahan Yogyakarta — Callalily Party',
    'desc'      => 'Venue, dekorasi, catering, foto, video, rias, busana, musik, MC, sound, undangan, dan kue. '
                 . 'Penjelasan tiap jenis vendor pernikahan beserta kisaran biayanya di Yogyakarta.',
    'canonical' => url('vendor'),
    'jsonld'    => [ldService()],
];
require __DIR__ . '/partials/public-head.php';
?>
<div class="vk">
  <header class="vk-head vk-head-l">
    <h1><?= te('Jenis vendor pernikahan') ?></h1>
    <p class="vk-lead"><?= te('Tiap jenis punya jebakannya sendiri. Berikut yang perlu diketahui sebelum memesan — dan kisaran biayanya di Yogyakarta.') ?></p>
  </header>

  <div class="vk-grid vk-grid-l">
    <?php foreach ($semua as $k): ?>
      <a class="vk-card" href="<?= url('vendor/' . $k['slug']) ?>">
        <?php if (!empty($k['gambar'])): ?>
          <span class="vk-card-g"><img src="<?= e(url('foto/' . $k['gambar'] . '-thumb.jpg')) ?>"
            alt="" loading="lazy" decoding="async"></span>
        <?php endif; ?>
        <span class="vk-card-i"><?= ikonVendor($k['ikon']) ?></span>
        <h3><?= e($k['nama']) ?></h3>
        <p><?= e($k['ringkas']) ?></p>
        <?php if ($k['kisaran']): ?><span class="vk-card-h"><?= e($k['kisaran']) ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <section class="vk-cta">
    <h2><?= te('Tidak semua harus dipesan sendiri') ?></h2>
    <p><?= te('Susun harimu dulu — kami yang mencocokkan jenis vendor mana yang benar-benar dibutuhkan.') ?></p>
    <a class="btn solid" href="<?= e(setting('form_url', url('form.php'))) ?>"><?= te('Isi data & minta penawaran') ?></a>
  </section>
</div>
<?php require __DIR__ . '/partials/public-foot.php';
