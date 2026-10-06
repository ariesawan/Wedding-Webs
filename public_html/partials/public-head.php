<?php
/**
 * Kerangka halaman publik selain beranda & galeri (blog, artikel, event).
 * Sengaja tanpa Three.js: halaman baca harus ringan, dan Core Web Vitals
 * ikut jadi faktor peringkat.
 *
 * Variabel yang diharapkan: $seo (array untuk seoHead), $crumbs (array [nama, url]).
 */
// Bahasa ditentukan SEBELUM ada keluaran: ?lang= memasang cookie, dan cookie
// hanya bisa dikirim sebelum HTML pertama.
if (function_exists('bahasaAktif')) bahasaAktif();
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..700;1,6..96,400..700&family=Hanken+Grotesk:ital,wght@0,300..700;1,300..700&family=IBM+Plex+Mono:wght@400;500;600&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..700;1,6..96,400..700&family=Hanken+Grotesk:ital,wght@0,300..700;1,300..700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" media="print" onload="this.media='all';this.onload=null">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..700;1,6..96,400..700&family=Hanken+Grotesk:ital,wght@0,300..700;1,300..700&family=IBM+Plex+Mono:wght@400;500;600&display=swap"></noscript>
<?= seoHead($seo ?? []) ?>
<?php if ($gsc = setting('gsc_verification')): ?><meta name="google-site-verification" content="<?= e($gsc) ?>"><?php endif; ?>
<link rel="stylesheet" href="<?= url('assets/halaman.css') ?>?v=<?= assetVer('assets/halaman.css') ?>">
<style>
:root{
  --ivory:#F1EAD9;--ivory-60:rgba(241,234,217,.6);--ivory-38:rgba(241,234,217,.38);
  --ivory-12:rgba(241,234,217,.12);--ink:#17181A;--ink-2:#1F2023;
  --ember:#E9A85C;--ember-deep:#C97F3B;
  --serif:"Bodoni Moda",Didot,serif;--sans:"Hanken Grotesk",system-ui,sans-serif;--mono:"IBM Plex Mono",ui-monospace,monospace;
  --pad:clamp(20px,5vw,80px);
}
*{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{font-family:var(--sans);color:var(--ivory);background:var(--ink);line-height:1.7;-webkit-font-smoothing:antialiased}
a{color:inherit}
::selection{background:var(--ember);color:var(--ink)}
:focus-visible{outline:2px solid var(--ember);outline-offset:3px;border-radius:3px}
img{max-width:100%;height:auto}

/* garis kemajuan baca — melanjutkan gagasan "gulir = waktu" di beranda */
.prog{position:fixed;inset:0 auto auto 0;height:2px;background:var(--ember);width:0;z-index:60;transition:width .1s linear}

nav{position:sticky;top:0;z-index:50;display:flex;justify-content:space-between;align-items:center;
  padding:16px var(--pad);background:rgba(7,10,22,.82);backdrop-filter:blur(14px);border-bottom:1px solid var(--ivory-12)}
.brand{font-family:var(--serif);font-style:italic;font-size:22px;text-decoration:none;letter-spacing:.03em}
.brand sup{font-family:var(--mono);font-size:8px;font-style:normal;letter-spacing:.16em;color:var(--ember);margin-left:6px;vertical-align:super}
.navr{display:flex;gap:8px;align-items:center}
.navlink{font-size:13.5px;color:var(--ivory-60);text-decoration:none;padding:8px 12px;transition:color .2s}
.navlink:hover{color:var(--ivory)}
.btn{display:inline-flex;align-items:center;gap:9px;border:1px solid var(--ivory-38);border-radius:999px;
  padding:9px 19px;font-size:13.5px;text-decoration:none;transition:.18s;background:transparent;cursor:pointer}
.btn:hover{border-color:var(--ember);color:var(--ember)}
.btn.solid{background:var(--ember);border-color:var(--ember);color:var(--ink);font-weight:600}
.btn.solid:hover{background:var(--ember-deep);border-color:var(--ember-deep);color:var(--ink)}
@media(max-width:620px){.navlink{display:none}.brand sup{display:none}}

main{padding:0 var(--pad)}
.shell{max-width:1180px;margin:0 auto}
.crumb{font-family:var(--mono);font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:var(--ivory-38);padding:26px 0 0}
.crumb a{color:var(--ivory-60);text-decoration:none}
.crumb a:hover{color:var(--ember)}
.crumb span{margin:0 7px;color:var(--ivory-12)}

.tstamp{display:inline-flex;align-items:center;gap:13px;font-family:var(--mono);font-size:10.5px;
  letter-spacing:.22em;text-transform:uppercase;color:var(--ember)}
.tstamp::after{content:"";height:1px;width:56px;background:var(--ember);opacity:.5}

footer{border-top:1px solid var(--ivory-12);margin-top:96px;padding:30px var(--pad);
  display:flex;justify-content:space-between;gap:18px;flex-wrap:wrap;
  font-family:var(--mono);font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:var(--ivory-38)}
footer a{color:var(--ivory-60);text-decoration:none}
footer a:hover{color:var(--ember)}
@media(prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important;scroll-behavior:auto}}
</style>
<?= $extraCss ?? '' ?>
</head>
<body>
<div class="prog" id="prog" aria-hidden="true"></div>
<nav>
  <a class="brand" href="<?= url() ?>">Callalily<sup>PARTY · WEDDING ORGANIZER</sup></a>
  <div class="navr">
    <a class="navlink" href="<?= url('pricelist') ?>">Price list</a>
    <a class="navlink" href="<?= url('galeri') ?>">Galeri pesta</a>
    <a class="navlink" href="<?= url('blog') ?>">Jurnal</a>
    <a class="btn solid" href="<?= url() ?>#susun">Susun harimu</a>
  </div>
</nav>
<main>
<div class="shell">
<?php if (!empty($crumbs)): ?>
  <p class="crumb">
    <?php foreach ($crumbs as $i => [$nm, $u]): ?>
      <?= $i ? '<span>/</span>' : '' ?><?= $u ? '<a href="' . e($u) . '">' . e($nm) . '</a>' : e($nm) ?>
    <?php endforeach; ?>
  </p>
<?php endif; ?>
