<?php
require_once __DIR__ . '/inc/bootstrap.php';
if (http_response_code() !== 404) http_response_code(404);

$seo = [
    'title'  => 'Halaman tidak ditemukan — ' . setting('site_name', 'Callalily Party'),
    'desc'   => 'Alamat yang dituju tidak ada di situs ini.',
    'robots' => 'noindex, follow',
];
$recent = all("SELECT title, slug FROM posts WHERE status='published' ORDER BY published_at DESC LIMIT 4");

$extraCss = <<<'CSS'
<style>
.nf{padding:100px 0 60px;max-width:60ch}
.nf h1{font-family:var(--serif);font-style:italic;font-weight:400;font-size:clamp(2.4rem,6vw,4rem);line-height:1.08;margin:18px 0 18px}
.nf p{color:var(--ivory-60);font-weight:300;font-size:16.5px;line-height:1.75;margin-bottom:30px}
.nf .lk{display:flex;gap:11px;flex-wrap:wrap;margin-bottom:56px}
.nf ul{list-style:none;border-top:1px solid var(--ivory-12)}
.nf li{border-bottom:1px solid var(--ivory-12)}
.nf li a{display:block;padding:15px 2px;text-decoration:none;font-family:var(--serif);font-style:italic;font-size:1.15rem;transition:padding .2s,color .2s}
.nf li a:hover{padding-left:12px;color:var(--ember)}
</style>
CSS;

require __DIR__ . '/partials/public-head.php';
?>
<div class="nf">
  <span class="tstamp">404 · TIDAK ADA DI RUNDOWN</span>
  <h1>Halaman ini tidak ada di susunan hari.</h1>
  <p>Mungkin alamatnya salah ketik, atau tulisannya sudah dipindahkan. Beberapa jalan yang pasti ada:</p>
  <div class="lk">
    <a class="btn solid" href="<?= url() ?>">Kembali ke beranda</a>
    <a class="btn" href="<?= url('galeri') ?>">Galeri pesta</a>
    <a class="btn" href="<?= url('blog') ?>">Jurnal</a>
  </div>
  <?php if ($recent): ?>
    <span class="tstamp">TULISAN TERBARU</span>
    <ul style="margin-top:20px">
      <?php foreach ($recent as $r): ?>
        <li><a href="<?= url('blog/' . $r['slug']) ?>"><?= e($r['title']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/public-foot.php';
