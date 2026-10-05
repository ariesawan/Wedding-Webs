<?php
/** Halaman artikel tunggal. URL bersih: /blog/<slug> */
require_once __DIR__ . '/inc/bootstrap.php';

$slug = trim($_GET['slug'] ?? '');
$p = $slug ? one("SELECT p.*, c.name cat_name, c.slug cat_slug
                  FROM posts p LEFT JOIN categories c ON c.id = p.category_id
                  WHERE p.slug = ? AND p.status = 'published' AND p.published_at <= NOW()", [$slug]) : null;

if (!$p) { http_response_code(404); require __DIR__ . '/404.php'; exit; }

// Hitung pembacaan. Sesi dipakai supaya refresh berulang tidak menggelembungkan angka.
if (empty($_SESSION['seen_post'][$p['id']])) {
    q("UPDATE posts SET views = views + 1 WHERE id = ?", [$p['id']]);
    $_SESSION['seen_post'][$p['id']] = true;
}

// Artikel terkait: sekategori dulu, sisanya diisi yang terbaru.
$related = all("SELECT title, slug, cover, cover_alt, published_at, excerpt, content
                FROM posts WHERE status='published' AND published_at <= NOW() AND id <> ?
                ORDER BY (category_id <=> ?) DESC, published_at DESC LIMIT 3",
               [$p['id'], $p['category_id']]);

$cover  = $p['cover'] ? url('uploads/blog/' . $p['cover'] . '.jpg') : null;
$crumbs = [['Beranda', url()], ['Jurnal', url('blog')]];
if ($p['cat_name']) $crumbs[] = [$p['cat_name'], url('blog/kategori/' . $p['cat_slug'])];
$crumbs[] = [mb_strimwidth($p['title'], 0, 46, '…'), null];

$seo = [
  'title'     => $p['meta_title'] ?: $p['title'] . ' — ' . setting('site_name'),
  'desc'      => $p['meta_description'] ?: $p['excerpt'],
  'canonical' => url('blog/' . $p['slug']),
  'image'     => $cover ?: setting('default_og_image'),
  'image_alt' => $p['cover_alt'] ?: $p['title'],
  'type'      => 'article',
  'robots'    => $p['noindex'] ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1',
  'published' => $p['published_at'],
  'modified'  => $p['updated_at'],
  'section'   => $p['cat_name'] ?: '',
  'tags'      => array_filter(array_map('trim', explode(',', $p['tags']))),
  'jsonld'    => [
      ldOrganization(),
      ldArticle($p),
      ldBreadcrumb(array_map(fn($c) => [$c[0], $c[1] ?: url('blog/' . $p['slug'])], $crumbs)),
  ],
];

$extraCss = <<<'CSS'
<style>
.art{max-width:none}
.art-head{max-width:760px;padding:44px 0 0}
.art-head h1{font-family:var(--serif);font-style:italic;font-weight:400;
  font-size:clamp(2rem,4.6vw,3.3rem);line-height:1.12;margin:18px 0 18px;letter-spacing:-.01em}
.art-head .lede{font-size:clamp(16px,1.5vw,18.5px);color:var(--ivory-60);font-weight:300;line-height:1.72;max-width:60ch}
.art-meta{display:flex;gap:20px;flex-wrap:wrap;align-items:center;font-family:var(--mono);
  font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:var(--ivory-38);
  padding:24px 0;margin-top:26px;border-top:1px solid var(--ivory-12);border-bottom:1px solid var(--ivory-12)}
.art-cover{margin:40px 0 0;border-radius:14px;overflow:hidden;max-height:620px}
.art-cover img{width:100%;height:100%;object-fit:cover;display:block}
.art-cover figcaption{font-family:var(--mono);font-size:10px;letter-spacing:.14em;text-transform:uppercase;
  color:var(--ivory-38);padding:11px 2px 0}

/* Kolom baca: lebar ±68 karakter — titik nyaman untuk teks panjang di layar. */
.art-body{max-width:68ch;margin:48px 0 0;font-size:clamp(16px,1.42vw,17.5px);line-height:1.82;font-weight:300}
.art-body > * + *{margin-top:1.25em}
.art-body h2{font-family:var(--serif);font-style:italic;font-weight:400;font-size:clamp(1.5rem,2.8vw,2.1rem);
  line-height:1.24;margin-top:2.2em;color:var(--ivory)}
.art-body h3{font-family:var(--serif);font-style:italic;font-weight:400;font-size:clamp(1.22rem,2.1vw,1.55rem);margin-top:1.9em}
.art-body a{color:var(--ember);text-decoration:none;border-bottom:1px solid rgba(233,168,92,.4);transition:border-color .2s}
.art-body a:hover{border-bottom-color:var(--ember)}
.art-body ul,.art-body ol{padding-left:1.35em}
.art-body li + li{margin-top:.5em}
.art-body li::marker{color:var(--ember)}
.art-body blockquote{border-left:2px solid var(--ember);padding:2px 0 2px 22px;font-family:var(--serif);
  font-style:italic;font-size:1.16em;color:var(--ivory);line-height:1.55}
.art-body figure{margin:2em 0}
.art-body img{border-radius:11px;display:block}
.art-body figcaption{font-family:var(--mono);font-size:10px;letter-spacing:.14em;text-transform:uppercase;
  color:var(--ivory-38);margin-top:10px}
.art-body hr{border:0;height:1px;background:var(--ivory-12);margin:2.4em 0}
.art-body code{font-family:var(--mono);font-size:.86em;background:var(--ink-2);padding:2px 6px;border-radius:4px}
.art-body table{width:100%;border-collapse:collapse;font-size:.92em}
.art-body th,.art-body td{padding:10px 12px;border-bottom:1px solid var(--ivory-12);text-align:left}
.art-body th{font-family:var(--mono);font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:var(--ivory-38)}

.tagrow{display:flex;gap:8px;flex-wrap:wrap;margin-top:52px;max-width:68ch}
.tagrow span{font-family:var(--mono);font-size:9.5px;letter-spacing:.16em;text-transform:uppercase;
  padding:6px 13px;border:1px solid var(--ivory-12);border-radius:999px;color:var(--ivory-38)}
.share{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:34px;padding-top:26px;
  border-top:1px solid var(--ivory-12);max-width:68ch}
.share .lab{font-family:var(--mono);font-size:9.5px;letter-spacing:.18em;text-transform:uppercase;color:var(--ivory-38);margin-right:4px}

.cta{max-width:68ch;margin-top:60px;border:1px solid var(--ivory-12);border-radius:14px;padding:32px 30px;
  background:linear-gradient(160deg,rgba(233,168,92,.07),transparent 62%)}
.cta h3{font-family:var(--serif);font-style:italic;font-weight:400;font-size:1.55rem;line-height:1.3;margin-bottom:10px}
.cta p{color:var(--ivory-60);font-size:14.5px;font-weight:300;margin-bottom:22px;max-width:48ch}

.rel{margin-top:88px;border-top:1px solid var(--ivory-12);padding-top:36px}
.rel h2{font-family:var(--mono);font-size:10px;letter-spacing:.2em;text-transform:uppercase;color:var(--ivory-38);font-weight:500}
.relgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(258px,1fr));gap:26px;margin-top:26px}
.relcard{text-decoration:none;display:block}
.relcard .im{aspect-ratio:16/10;border-radius:10px;overflow:hidden;background:var(--ink-2);margin-bottom:14px}
.relcard .im img{width:100%;height:100%;object-fit:cover;transition:transform .5s}
.relcard:hover .im img{transform:scale(1.05)}
.relcard h3{font-family:var(--serif);font-style:italic;font-weight:400;font-size:1.24rem;line-height:1.3}
.relcard span{font-family:var(--mono);font-size:9.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--ivory-38);display:block;margin-top:8px}
</style>
CSS;

require __DIR__ . '/partials/public-head.php';
?>
<article class="art">
  <header class="art-head">
    <span class="tstamp"><?= $p['cat_name'] ? e(strtoupper($p['cat_name'])) : 'JURNAL' ?></span>
    <h1><?= e($p['title']) ?></h1>
    <?php if ($p['excerpt']): ?><p class="lede"><?= e($p['excerpt']) ?></p><?php endif; ?>
    <div class="art-meta">
      <span><?= hariID($p['published_at']) ?>, <?= tanggalID($p['published_at']) ?></span>
      <span><?= readingTime($p['content']) ?> menit baca</span>
      <?php if ($p['updated_at'] && strtotime($p['updated_at']) > strtotime($p['published_at']) + 86400): ?>
        <span>Diperbarui <?= tanggalID($p['updated_at']) ?></span>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($cover): ?>
    <figure class="art-cover">
      <picture>
        <source srcset="<?= url('uploads/blog/' . $p['cover'] . '.webp') ?>" type="image/webp">
        <img src="<?= e($cover) ?>" alt="<?= e($p['cover_alt'] ?: $p['title']) ?>" width="1600" height="900" fetchpriority="high" decoding="async">
      </picture>
      <?php if ($p['cover_alt']): ?><figcaption><?= e($p['cover_alt']) ?></figcaption><?php endif; ?>
    </figure>
  <?php endif; ?>

  <div class="art-body"><?= $p['content'] ?></div>

  <?php $tags = array_filter(array_map('trim', explode(',', $p['tags']))); ?>
  <?php if ($tags): ?>
    <div class="tagrow"><?php foreach ($tags as $t): ?><span><?= e($t) ?></span><?php endforeach; ?></div>
  <?php endif; ?>

  <?php
    $shareUrl  = rawurlencode(url('blog/' . $p['slug']));
    $shareText = rawurlencode($p['title']);
  ?>
  <div class="share">
    <span class="lab">Bagikan</span>
    <a class="btn" href="https://wa.me/?text=<?= $shareText ?>%20<?= $shareUrl ?>" target="_blank" rel="noopener nofollow">WhatsApp</a>
    <a class="btn" href="https://www.facebook.com/sharer/sharer.php?u=<?= $shareUrl ?>" target="_blank" rel="noopener nofollow">Facebook</a>
    <a class="btn" href="https://t.me/share/url?url=<?= $shareUrl ?>&text=<?= $shareText ?>" target="_blank" rel="noopener nofollow">Telegram</a>
    <button class="btn" type="button" id="copyLink">Salin tautan</button>
  </div>

  <aside class="cta">
    <h3>Punya tanggal yang sedang dipertimbangkan?</h3>
    <p>Kirim tanggal, perkiraan jumlah tamu, dan rangkaian acaranya. Kami balas dengan penawaran yang sudah dirinci per pos, bukan angka gelondongan.</p>
    <a class="btn solid" href="<?= url() ?>#susun">Susun harimu</a>
  </aside>

  <?php if ($related): ?>
  <section class="rel">
    <h2>Bacaan lain</h2>
    <div class="relgrid">
      <?php foreach ($related as $r): ?>
        <a class="relcard" href="<?= url('blog/' . $r['slug']) ?>">
          <?php if ($r['cover']): ?>
            <span class="im"><img src="<?= url('uploads/blog/' . $r['cover'] . '-thumb.jpg') ?>"
                 alt="<?= e($r['cover_alt'] ?: $r['title']) ?>" loading="lazy" decoding="async"></span>
          <?php endif; ?>
          <h3><?= e($r['title']) ?></h3>
          <span><?= tanggalID($r['published_at']) ?> · <?= readingTime($r['content']) ?> menit</span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</article>

<script>
document.getElementById('copyLink')?.addEventListener('click', async e => {
  try {
    await navigator.clipboard.writeText(<?= ejs(url('blog/' . $p['slug'])) ?>);
    const b = e.target, t = b.textContent;
    b.textContent = 'Tersalin';
    setTimeout(() => b.textContent = t, 1800);
  } catch (_) {}
});
</script>

<?php require __DIR__ . '/partials/public-foot.php';
