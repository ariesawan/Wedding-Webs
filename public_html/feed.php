<?php
/** RSS 2.0. Dirujuk dari <link rel="alternate"> di seoHead() dan footer. */
require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/rss+xml; charset=UTF-8');
header('X-Robots-Tag: noindex');

$posts = all("SELECT p.*, c.name cat_name FROM posts p
              LEFT JOIN categories c ON c.id = p.category_id
              WHERE p.status='published' AND p.published_at <= NOW()
              ORDER BY p.published_at DESC LIMIT 25");
$build = $posts ? strtotime($posts[0]['published_at']) : time();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">
<channel>
  <title><?= e('Jurnal ' . setting('site_name', 'Callalily Party')) ?></title>
  <link><?= e(url('blog')) ?></link>
  <description><?= e(setting('site_description', '')) ?></description>
  <language>id-ID</language>
  <lastBuildDate><?= date(DATE_RSS, $build) ?></lastBuildDate>
  <atom:link href="<?= e(url('feed.php')) ?>" rel="self" type="application/rss+xml"/>
<?php foreach ($posts as $p): ?>
  <item>
    <title><?= e($p['title']) ?></title>
    <link><?= e(url('blog/' . $p['slug'])) ?></link>
    <guid isPermaLink="true"><?= e(url('blog/' . $p['slug'])) ?></guid>
    <pubDate><?= date(DATE_RSS, strtotime($p['published_at'])) ?></pubDate>
<?php if ($p['cat_name']): ?>    <category><?= e($p['cat_name']) ?></category>
<?php endif; ?>    <description><?= e($p['excerpt'] ?: excerptFrom($p['content'], 200)) ?></description>
<?php if ($p['cover']): ?>    <enclosure url="<?= e(url('uploads/blog/' . $p['cover'] . '.jpg')) ?>" type="image/jpeg" length="0"/>
<?php endif; ?>  </item>
<?php endforeach; ?>
</channel>
</rss>
