<?php
/** Halaman detail event. URL bersih: /event/<slug> */
require_once __DIR__ . '/inc/bootstrap.php';

$slug = trim($_GET['slug'] ?? '');
$ev = $slug ? one("SELECT * FROM events WHERE slug = ? AND is_published = 1", [$slug]) : null;
if (!$ev) { http_response_code(404); require __DIR__ . '/404.php'; exit; }

$past  = strtotime($ev['event_date']) < time();
$days  = (int) ceil((strtotime($ev['event_date']) - time()) / 86400);
$cover = $ev['cover'] ? url('uploads/event/' . $ev['cover'] . '.jpg') : null;

// Foto galeri yang dikaitkan ke event ini.
$shots = all("SELECT slug, who, caption, alt_text, media_type, video_file
              FROM gallery WHERE event_id = ? AND is_published = 1
              ORDER BY sort_order ASC LIMIT 12", [$ev['id']]);

// Navigasi antar event pada kelompok yang sama (mendatang / sudah lewat).
$cmp  = $past ? '<' : '>=';
$prev = one("SELECT title, slug FROM events WHERE is_published=1 AND event_date $cmp NOW()
             AND event_date < ? ORDER BY event_date DESC LIMIT 1", [$ev['event_date']]);
$next = one("SELECT title, slug FROM events WHERE is_published=1 AND event_date $cmp NOW()
             AND event_date > ? ORDER BY event_date ASC LIMIT 1", [$ev['event_date']]);

$crumbs = [['Beranda', url()], [$past ? 'Rekam jejak' : 'Agenda', url() . ($past ? '#rekam' : '#agenda')],
           [mb_strimwidth($ev['couple'] ?: $ev['title'], 0, 40, '…'), null]];

$seo = [
  'title'     => $ev['meta_title'] ?: $ev['title'] . ' — ' . setting('site_name'),
  'desc'      => $ev['meta_description'] ?: excerptFrom($ev['description'] ?: ($ev['title'] . ' di ' . ($ev['venue'] ?: $ev['city']) . ', ditangani ' . setting('site_name') . '.'), 155),
  'canonical' => url('event/' . $ev['slug']),
  'image'     => $cover ?: setting('default_og_image'),
  'image_alt' => $ev['cover_alt'] ?: $ev['title'],
  'type'      => 'article',
  'jsonld'    => [ldOrganization(), ldEvent($ev),
                  ldBreadcrumb(array_map(fn($c) => [$c[0], $c[1] ?: url('event/' . $ev['slug'])], $crumbs))],
];

$extraCss = <<<'CSS'
<style>
.ev-head{padding:44px 0 0;max-width:780px}
.ev-head h1{font-family:var(--serif);font-style:italic;font-weight:400;
  font-size:clamp(2.1rem,5vw,3.5rem);line-height:1.1;margin:18px 0 14px}
.ev-head .sub{color:var(--ivory-60);font-size:clamp(15.5px,1.4vw,18px);font-weight:300;line-height:1.7;max-width:58ch}
.ev-facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:2px;
  margin:38px 0 0;border-top:1px solid var(--ivory-12);border-bottom:1px solid var(--ivory-12)}
.ev-facts div{padding:20px 0}
.ev-facts .k{font-family:var(--mono);font-size:9.5px;letter-spacing:.18em;text-transform:uppercase;color:var(--ivory-38);display:block}
.ev-facts .v{font-family:var(--serif);font-style:italic;font-size:1.32rem;margin-top:7px;display:block;line-height:1.25}
.ev-facts .v small{font-family:var(--sans);font-style:normal;font-size:12.5px;color:var(--ivory-60);display:block;margin-top:4px}
.ev-hero{margin:44px 0 0;border-radius:14px;overflow:hidden;max-height:640px}
.ev-hero img{width:100%;height:100%;object-fit:cover;display:block}
.ev-desc{max-width:66ch;margin:48px 0 0;font-size:clamp(15.5px,1.4vw,17.2px);line-height:1.82;font-weight:300;color:var(--ivory-60)}
.ev-desc p + p{margin-top:1.2em}
.ev-shots{display:grid;grid-template-columns:repeat(auto-fill,minmax(214px,1fr));gap:14px;margin-top:56px}
.ev-shots a{display:block;border-radius:10px;overflow:hidden;aspect-ratio:4/3;background:var(--ink-2)}
.ev-shots img{width:100%;height:100%;object-fit:cover;transition:transform .5s}
.ev-shots a:hover img{transform:scale(1.06)}
.ev-cta{max-width:66ch;margin-top:64px;border:1px solid var(--ivory-12);border-radius:14px;padding:32px 30px;
  background:linear-gradient(160deg,rgba(233,168,92,.07),transparent 62%)}
.ev-cta h2{font-family:var(--serif);font-style:italic;font-weight:400;font-size:1.55rem;margin-bottom:10px;line-height:1.3}
.ev-cta p{color:var(--ivory-60);font-size:14.5px;font-weight:300;margin-bottom:22px}
.ev-nav{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;margin-top:80px;
  border-top:1px solid var(--ivory-12);padding-top:28px}
.ev-nav a{text-decoration:none;max-width:44%}
.ev-nav .k{font-family:var(--mono);font-size:9.5px;letter-spacing:.18em;text-transform:uppercase;color:var(--ivory-38);display:block}
.ev-nav .t{font-family:var(--serif);font-style:italic;font-size:1.16rem;margin-top:6px;display:block;line-height:1.3}
.ev-nav a:hover .t{color:var(--ember)}
.ev-nav .r{margin-left:auto;text-align:right}
@media(max-width:640px){.ev-nav a{max-width:100%}}
</style>
CSS;

require __DIR__ . '/partials/public-head.php';
?>
<article>
  <header class="ev-head">
    <span class="tstamp"><?= $past ? 'SUDAH TERSELENGGARA' : 'SEDANG DISIAPKAN' ?> · <?= e(strtoupper($ev['category'])) ?></span>
    <h1><?= e($ev['couple'] ?: $ev['title']) ?></h1>
    <?php if ($ev['couple'] && $ev['couple'] !== $ev['title']): ?>
      <p class="sub"><?= e($ev['title']) ?></p>
    <?php endif; ?>

    <div class="ev-facts">
      <div>
        <span class="k">Tanggal</span>
        <span class="v"><?= tanggalID($ev['event_date']) ?><small><?= hariID($ev['event_date']) ?>, <?= date('H.i', strtotime($ev['event_date'])) ?> WIB</small></span>
      </div>
      <div>
        <span class="k">Lokasi</span>
        <span class="v"><?= e($ev['venue'] ?: $ev['city']) ?><?= $ev['venue'] ? '<small>' . e($ev['city']) . '</small>' : '' ?></span>
      </div>
      <?php if ($ev['guest_count']): ?>
      <div>
        <span class="k">Tamu</span>
        <span class="v"><?= number_format((int) $ev['guest_count'], 0, ',', '.') ?><small>undangan</small></span>
      </div>
      <?php endif; ?>
      <div>
        <span class="k"><?= $past ? 'Status' : 'Hitung mundur' ?></span>
        <span class="v"><?= $past ? 'Selesai' : ($days <= 0 ? 'Hari ini' : $days) ?><small><?= $past ? 'sampai tamu terakhir pulang' : ($days > 0 ? 'hari lagi' : 'sedang berjalan') ?></small></span>
      </div>
    </div>
  </header>

  <?php if ($cover): ?>
    <figure class="ev-hero">
      <picture>
        <source srcset="<?= url('uploads/event/' . $ev['cover'] . '.webp') ?>" type="image/webp">
        <img src="<?= e($cover) ?>" alt="<?= e($ev['cover_alt'] ?: $ev['title']) ?>" width="1600" height="900" fetchpriority="high" decoding="async">
      </picture>
    </figure>
  <?php endif; ?>

  <?php if (trim((string) $ev['description'])): ?>
    <div class="ev-desc">
      <?php foreach (preg_split('/\n\s*\n/', trim($ev['description'])) as $par): ?>
        <p><?= nl2br(e(trim($par))) ?></p>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($shots): ?>
    <div class="ev-shots">
      <?php foreach ($shots as $s): ?>
        <a href="<?= url('foto/' . $s['slug'] . '.jpg') ?>" target="_blank" rel="noopener">
          <picture>
            <source srcset="<?= url('foto/' . $s['slug'] . '-thumb.webp') ?>" type="image/webp">
            <img src="<?= url('foto/' . $s['slug'] . '-thumb.jpg') ?>"
                 alt="<?= e($s['alt_text'] ?: $s['who'] . ' — ' . $s['caption']) ?>" loading="lazy" decoding="async">
          </picture>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <aside class="ev-cta">
    <h2><?= $past ? 'Ingin hari seperti ini?' : 'Tanggal kalian masih kosong?' ?></h2>
    <p>Kirim tanggal, perkiraan jumlah tamu, dan rangkaian acara. Penawaran dirinci per pos, dikirim dalam 2×24 jam.</p>
    <div style="display:flex;gap:11px;flex-wrap:wrap">
      <a class="btn solid" href="<?= url() ?>#susun">Susun harimu</a>
      <a class="btn" href="<?= url('galeri') ?>">Lihat galeri pesta</a>
    </div>
  </aside>

  <?php if ($prev || $next): ?>
  <nav class="ev-nav">
    <?php if ($prev): ?>
      <a href="<?= url('event/' . $prev['slug']) ?>"><span class="k">← Sebelumnya</span><span class="t"><?= e($prev['title']) ?></span></a>
    <?php endif; ?>
    <?php if ($next): ?>
      <a class="r" href="<?= url('event/' . $next['slug']) ?>"><span class="k">Berikutnya →</span><span class="t"><?= e($next['title']) ?></span></a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>
</article>

<?php require __DIR__ . '/partials/public-foot.php';
