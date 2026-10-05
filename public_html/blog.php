<?php
/** Daftar artikel. URL bersih: /blog, /blog/kategori/<slug>, /blog?hal=2 */
require_once __DIR__ . '/inc/bootstrap.php';

$page    = intval_between($_GET['hal'] ?? 1, 1, 500, 1);
$perPage = 9;
$catSlug = trim($_GET['kategori'] ?? '');
$search  = trim($_GET['q'] ?? '');

$cat = $catSlug ? one("SELECT * FROM categories WHERE slug = ?", [$catSlug]) : null;
if ($catSlug && !$cat) { http_response_code(404); }

$where = ["p.status = 'published'", "p.published_at <= NOW()"];
$par   = [];
if ($cat)      { $where[] = "p.category_id = ?"; $par[] = $cat['id']; }
if ($search)   { $where[] = "MATCH(p.title, p.excerpt, p.content) AGAINST (? IN NATURAL LANGUAGE MODE)"; $par[] = $search; }
$w = 'WHERE ' . implode(' AND ', $where);

$total = (int) (one("SELECT COUNT(*) c FROM posts p $w", $par)['c'] ?? 0);
$pages = max(1, (int) ceil($total / $perPage));
$page  = min($page, $pages);
$off   = ($page - 1) * $perPage;

$posts = all("SELECT p.*, c.name cat_name, c.slug cat_slug
              FROM posts p LEFT JOIN categories c ON c.id = p.category_id
              $w ORDER BY p.published_at DESC LIMIT $perPage OFFSET $off", $par);
$cats  = all("SELECT c.*, COUNT(p.id) n FROM categories c
              LEFT JOIN posts p ON p.category_id = c.id AND p.status = 'published'
              GROUP BY c.id HAVING n > 0 ORDER BY c.name");

$title = $cat ? $cat['name'] . ' — Jurnal ' . setting('site_name') : 'Jurnal — ' . setting('site_name') . ' Wedding Organizer Yogyakarta';
$desc  = $cat ? ($cat['description'] ?: 'Artikel ' . $cat['name'] . ' dari ' . setting('site_name') . '.')
              : 'Catatan kerja, panduan persiapan, dan penjelasan adat dari ' . setting('site_name') . ', wedding organizer Yogyakarta.';
$base  = $cat ? url('blog/kategori/' . $cat['slug']) : url('blog');

$crumbs = [['Beranda', url()], ['Jurnal', $cat ? url('blog') : null]];
if ($cat) $crumbs[] = [$cat['name'], null];

$seo = [
  'title' => $title, 'desc' => $desc,
  'canonical' => $page > 1 ? $base . '?hal=' . $page : $base,
  'robots' => $search ? 'noindex, follow' : 'index, follow, max-image-preview:large',
  'prev' => $page > 1 ? ($page - 1 === 1 ? $base : $base . '?hal=' . ($page - 1)) : null,
  'next' => $page < $pages ? $base . '?hal=' . ($page + 1) : null,
  'jsonld' => [ldOrganization(), ldBreadcrumb(array_map(fn($c) => [$c[0], $c[1] ?: $base], $crumbs))],
];

$extraCss = <<<'CSS'
<style>
.jhead{padding:52px 0 40px;border-bottom:1px solid var(--ivory-12)}
.jhead h1{font-family:var(--serif);font-style:italic;font-weight:400;font-size:clamp(2.1rem,5vw,3.4rem);line-height:1.1;margin:16px 0 12px}
.jhead p{color:var(--ivory-60);max-width:56ch;font-size:clamp(15px,1.3vw,17px);font-weight:300}
.jfilter{display:flex;gap:8px;flex-wrap:wrap;padding:22px 0 0;align-items:center}
.jfilter a{font-family:var(--mono);font-size:10px;letter-spacing:.16em;text-transform:uppercase;
  padding:7px 14px;border:1px solid var(--ivory-12);border-radius:999px;text-decoration:none;color:var(--ivory-60);transition:.18s}
.jfilter a:hover{border-color:var(--ember);color:var(--ember)}
.jfilter a.on{background:var(--ember);border-color:var(--ember);color:var(--ink);font-weight:600}
.jgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:30px;padding:44px 0 0}
.jcard{display:flex;flex-direction:column;text-decoration:none;border-bottom:1px solid var(--ivory-12);padding-bottom:22px;transition:.22s}
.jcard:hover{border-bottom-color:var(--ember)}
.jcard .im{aspect-ratio:16/10;overflow:hidden;border-radius:11px;background:var(--ink-2);margin-bottom:17px}
.jcard .im img{width:100%;height:100%;object-fit:cover;transition:transform .55s ease}
.jcard:hover .im img{transform:scale(1.045)}
.jcard .k{font-family:var(--mono);font-size:9.5px;letter-spacing:.18em;text-transform:uppercase;color:var(--ember)}
.jcard h2{font-family:var(--serif);font-style:italic;font-weight:400;font-size:1.42rem;line-height:1.28;margin:9px 0 8px}
.jcard p{font-size:14px;color:var(--ivory-60);font-weight:300;line-height:1.65}
.jcard .m{font-family:var(--mono);font-size:9.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--ivory-38);margin-top:13px}
.pager{display:flex;gap:9px;justify-content:center;padding:56px 0 0;flex-wrap:wrap}
.pager a,.pager span{font-family:var(--mono);font-size:12.5px;padding:9px 15px;border:1px solid var(--ivory-12);
  border-radius:8px;text-decoration:none;color:var(--ivory-60)}
.pager a:hover{border-color:var(--ember);color:var(--ember)}
.pager .cur{background:var(--ember);border-color:var(--ember);color:var(--ink);font-weight:600}
.jempty{padding:80px 0;text-align:center}
.jempty p{font-family:var(--serif);font-style:italic;font-size:1.5rem;color:var(--ivory-60);margin-bottom:10px}
.jempty span{color:var(--ivory-38);font-size:14px;display:block;max-width:44ch;margin:0 auto 24px}
</style>
CSS;

require __DIR__ . '/partials/public-head.php';
?>
<header class="jhead">
  <span class="tstamp">JURNAL · <?= $total ?> TULISAN</span>
  <h1><?= $cat ? e($cat['name']) : 'Yang kami catat <em style="color:var(--ember)">di sela hari besar.</em>' ?></h1>
  <p><?= e($desc) ?></p>

  <div class="jfilter">
    <a href="<?= url('blog') ?>" class="<?= $cat ? '' : 'on' ?>">Semua</a>
    <?php foreach ($cats as $c): ?>
      <a href="<?= url('blog/kategori/' . $c['slug']) ?>" class="<?= $cat && $cat['id'] == $c['id'] ? 'on' : '' ?>">
        <?= e($c['name']) ?> · <?= (int) $c['n'] ?></a>
    <?php endforeach; ?>
  </div>
</header>

<?php if (!$posts): ?>
  <div class="jempty">
    <p>Belum ada tulisan di sini</p>
    <span>Jurnal ini akan diisi catatan kerja dan panduan persiapan. Sementara itu, susunan hari bisa dilihat di beranda.</span>
    <a class="btn solid" href="<?= url() ?>#susun">Susun harimu</a>
  </div>
<?php else: ?>
  <div class="jgrid">
    <?php foreach ($posts as $p): ?>
      <a class="jcard" href="<?= url('blog/' . $p['slug']) ?>">
        <?php if ($p['cover']): ?>
          <span class="im">
            <picture>
              <source srcset="<?= url('uploads/blog/' . $p['cover'] . '-thumb.webp') ?>" type="image/webp">
              <img src="<?= url('uploads/blog/' . $p['cover'] . '-thumb.jpg') ?>"
                   alt="<?= e($p['cover_alt'] ?: $p['title']) ?>" loading="lazy" decoding="async" width="600" height="375">
            </picture>
          </span>
        <?php endif; ?>
        <?php if ($p['cat_name']): ?><span class="k"><?= e($p['cat_name']) ?></span><?php endif; ?>
        <h2><?= e($p['title']) ?></h2>
        <p><?= e(excerptFrom($p['excerpt'] ?: $p['content'], 130)) ?></p>
        <span class="m"><?= tanggalID($p['published_at']) ?> · <?= readingTime($p['content']) ?> menit baca</span>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($pages > 1): ?>
    <nav class="pager" aria-label="Navigasi halaman">
      <?php for ($i = 1; $i <= $pages; $i++):
        $u = $i === 1 ? $base : $base . '?hal=' . $i; ?>
        <?php if ($i === $page): ?><span class="cur"><?= $i ?></span>
        <?php else: ?><a href="<?= e($u) ?>"><?= $i ?></a><?php endif; ?>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/public-foot.php';
