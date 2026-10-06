<?php
/**
 * Sitemap XML dinamis. Didaftarkan ke Google Search Console sekali,
 * setelah itu artikel/event baru otomatis ikut terindeks tanpa tindakan manual.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/pipeline.php';

header('Content-Type: application/xml; charset=UTF-8');
header('X-Robots-Tag: noindex');

$urls = [];
$add = function (string $loc, ?string $mod = null, string $freq = 'monthly', string $pri = '0.6',
                 array $img = []) use (&$urls) {
    $urls[] = ['loc' => $loc, 'mod' => $mod, 'freq' => $freq, 'pri' => $pri, 'img' => $img];

    // Versi Inggris didaftarkan sebagai URL tersendiri. Tanpa ini Google
    // tidak punya alasan merayapinya — hreflang di <head> hanya menjelaskan
    // hubungan antar halaman, bukan mengundang perayapan.
    $urls[] = ['loc' => $loc . (str_contains($loc, '?') ? '&' : '?') . 'lang=en',
               'mod' => $mod, 'freq' => $freq,
               'pri' => number_format(max(0.1, (float) $pri - 0.1), 1),
               'img' => []];
};

// Galeri adalah aset terkuat sebuah WO — foto acara nyata, bukan stok.
// Tanpa didaftarkan di sitemap gambar, Google Images praktis tidak melihatnya,
// padahal pencarian visual sering jadi pintu masuk calon klien.
$gambarGaleri = [];
try {
    foreach (all("SELECT title, image, alt_text FROM gallery
                  WHERE is_published = 1 AND image <> '' ORDER BY sort_order LIMIT 200") as $g) {
        $gambarGaleri[] = [
            'loc'   => str_starts_with($g['image'], 'http') ? $g['image'] : url(ltrim($g['image'], '/')),
            'title' => $g['title'] ?: ($g['alt_text'] ?? ''),
        ];
    }
} catch (Throwable $e) { /* kolom berbeda pada instalasi lama — sitemap tetap terbit */ }

// Halaman utama
$add(url(),          null,                        'weekly',  '1.0');
$add(url('galeri'),  null,                        'weekly',  '0.9', $gambarGaleri);
$add(url('vendor'),  null,                        'monthly', '0.8');
$add(url('pricelist'), null,                      'monthly', '0.9');

// Halaman kategori adalah pintu masuk pencarian terbesar untuk jasa
// pernikahan — orang mencari "dekorasi pernikahan jogja" jauh lebih sering
// daripada nama penyelenggaranya. Prioritasnya disamakan dengan galeri.
try {
    foreach (all("SELECT slug FROM vendor_categories WHERE is_public = 1 ORDER BY urutan") as $vk) {
        $add(url('vendor/' . $vk['slug']), null, 'monthly', '0.8');
    }
} catch (Throwable $e) { /* tabel belum dimigrasi — sitemap tetap terbit */ }
$add(url('blog'),    one("SELECT MAX(published_at) m FROM posts WHERE status='published'")['m'] ?? null, 'weekly', '0.8');

// Kategori yang punya isi
foreach (all("SELECT c.slug, MAX(p.published_at) m FROM categories c
              JOIN posts p ON p.category_id = c.id AND p.status='published'
              GROUP BY c.id") as $c) {
    $add(url('blog/kategori/' . $c['slug']), $c['m'], 'weekly', '0.6');
}

// Artikel
foreach (all("SELECT slug, published_at, updated_at FROM posts
              WHERE status='published' AND published_at <= NOW() AND noindex = 0
              ORDER BY published_at DESC") as $p) {
    $add(url('blog/' . $p['slug']), $p['updated_at'] ?: $p['published_at'], 'monthly', '0.7');
}

// Event — yang mendatang lebih sering berubah, prioritasnya dinaikkan
foreach (all("SELECT slug, event_date, updated_at FROM events WHERE is_published = 1
              ORDER BY event_date DESC") as $ev) {
    $future = strtotime($ev['event_date']) >= time();
    $add(url('event/' . $ev['slug']), $ev['updated_at'], $future ? 'weekly' : 'yearly', $future ? '0.8' : '0.5');
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
<?php foreach ($urls as $u): ?>
  <url>
    <loc><?= e($u['loc']) ?></loc>
<?php if ($u['mod']): ?>    <lastmod><?= date('Y-m-d', strtotime($u['mod'])) ?></lastmod>
<?php endif; ?>    <changefreq><?= $u['freq'] ?></changefreq>
    <priority><?= $u['pri'] ?></priority>
<?php foreach ($u['img'] ?? [] as $im): ?>    <image:image>
      <image:loc><?= e($im['loc']) ?></image:loc>
<?php if (!empty($im['title'])): ?>      <image:title><?= e($im['title']) ?></image:title>
<?php endif; ?>    </image:image>
<?php endforeach; ?>  </url>
<?php endforeach; ?>
</urlset>
