<?php
/**
 * Beranda. Bagian statis dipertahankan apa adanya; yang berubah hanya:
 *  - blok <head> SEO (dari tabel settings + data terstruktur)
 *  - panel 2 & 3 (event) yang ditarik dari database
 *  - tautan galeri/blog
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/pipeline.php';
require_once __DIR__ . '/partials/ikon-vendor.php';

/* Penyusun brief: isinya dari tabel site_moments / site_services / site_presets,
   bisa diubah lewat Admin -> Penyusun tanpa menyentuh berkas ini. */
$jsMoments = array_map(fn($m) => [
    'id'    => $m['mkey'],
    'strip' => ((int) $m['day_offset'] === -1) ? 'h1' : 'hd',
    'label' => $m['label'],
    's'     => (float) $m['start_h'],
    'e'     => (float) $m['end_h'],
], momenSitus());

/**
 * Chip "Layanan sepanjang hari" kini bersumber dari KATEGORI VENDOR, bukan
 * daftar layanan terpisah.
 *
 * Sebelumnya keduanya dua daftar yang hidup sendiri-sendiri: penyusun punya
 * layananSitus(), formulir punya vendor_categories. Isinya tumpang tindih
 * tapi id-nya berbeda, jadi apa pun yang dipilih di beranda tidak bisa
 * mencentang apa pun di formulir — orang harus memilih dua kali, hal yang
 * sama, dengan nama yang sedikit berbeda.
 *
 * Sekarang satu sumber. id chip = id kategori, jadi pilihannya bisa dibawa
 * ke formulir apa adanya.
 */
$jsServices = [];
try {
    foreach (all("SELECT id, nama FROM vendor_categories
                  WHERE parent_id IS NULL AND is_active = 1 ORDER BY urutan, nama") as $vc) {
        $jsServices[] = ['id' => 'v' . $vc['id'], 'label' => $vc['nama'], 'note' => '', 'cat' => (int) $vc['id']];
    }
} catch (Throwable $e) { $jsServices = []; }

// Cadangan bila tabel kategori belum dimigrasi — penyusun tetap berfungsi.
if (!$jsServices) {
    $jsServices = array_map(fn($l) => [
        'id' => $l['skey'], 'label' => $l['label'], 'note' => $l['note'], 'cat' => 0,
    ], layananSitus());
}

$jsPresets = [];
foreach (presetSitus() as $pr) {
    $jsPresets[$pr['pkey']] = [
        'm' => array_values(array_filter(explode(',', $pr['moments']))),
        's' => array_values(array_filter(explode(',', $pr['services']))),
        'g' => (int) $pr['guests'],
    ];
}
$gMin  = (int) setting('guest_min', '50');
$gMax  = (int) setting('guest_max', '1500');
$gStep = (int) setting('guest_step', '25');
$gDef  = (int) setting('guest_default', '300');

$faq = [
  ['Berapa biaya jasa Callalily?', 'Biaya jasa mengikuti susunan hari: jumlah acara yang dipegang, jumlah tamu, jarak venue, dan tanggalnya. Kirim tanggal, perkiraan jumlah tamu, dan rangkaian acara, penawaran dikirim dalam 2x24 jam.'],
  ['Melayani acara di luar Yogyakarta?', 'Ya. Jawa Tengah dan Jawa Timur rutin dijalani, dan beberapa kali ke luar pulau. Untuk luar kota ada komponen perjalanan dan penginapan kru yang dirinci terbuka di penawaran.'],
  ['Sebaiknya menghubungi berapa lama sebelum hari-H?', 'Untuk rangkaian penuh, enam sampai dua belas bulan memberi ruang paling lega. Akad kecil masih bisa dipegang dalam dua sampai tiga bulan, tergantung tanggal.'],
  ['Konsultasi pertama bayar?', 'Tidak. Pertemuan pertama gratis, di studio Yogyakarta atau lewat panggilan video, tanpa kewajiban melanjutkan.'],
];
?>
<!DOCTYPE html>
<html lang="<?= bahasaAktif() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= seoHead([
  'title'  => setting('site_name', 'Callalily Party') . ' — Wedding Organizer Yogyakarta',
  'desc'   => setting('site_description'),
  'canonical' => url(),
  'jsonld' => [ldOrganization(), ldService(), ldFaq($faq)],
]) ?>
<?php if ($gsc = setting('gsc_verification')): ?><meta name="google-site-verification" content="<?= e($gsc) ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..700;1,6..96,400..700&family=Hanken+Grotesk:ital,wght@0,300..700;1,300..700&family=IBM+Plex+Mono:wght@400;500;600&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..700;1,6..96,400..700&family=Hanken+Grotesk:ital,wght@0,300..700;1,300..700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" media="print" onload="this.media='all';this.onload=null">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..700;1,6..96,400..700&family=Hanken+Grotesk:ital,wght@0,300..700;1,300..700&family=IBM+Plex+Mono:wght@400;500;600&display=swap"></noscript>
<style>
:root{
  --ivory:#F1EAD9;
  --ivory-60:rgba(241,234,217,.6);
  --ivory-30:rgba(241,234,217,.3);
  --ivory-12:rgba(241,234,217,.12);
  --ink:#17181A;
  --ink-2:#1F2023;
  --ember:#E9A85C;      /* emas lentera */
  --ember-deep:#C97F3B;
  --serif:"Bodoni Moda", "Didot", serif;
  --sans:"Hanken Grotesk", system-ui, sans-serif;
  --mono:"IBM Plex Mono", ui-monospace, monospace;
  --pad:clamp(20px,5vw,80px);
}

/* ---------- pemilih bahasa + tombol WA mengambang ---------- */
.langsw{display:inline-flex;gap:2px;border:1px solid var(--ivory-12);border-radius:99px;padding:2px;
  background:rgba(23,24,26,.5);backdrop-filter:blur(6px)}
.langsw a{font-family:var(--mono);font-size:10.5px;letter-spacing:.1em;padding:4px 9px;
  border-radius:99px;color:var(--ivory-60);text-decoration:none;line-height:1;transition:.16s}
.langsw a:hover{color:var(--ivory)}
.langsw a.on{background:var(--ember);color:#17181A;font-weight:600}
/* Sempat position:fixed di pojok kanan atas — dan langsung menimpa tombol
   "Susun harimu" di navigasi. Tempatnya memang di dalam nav: pemilih bahasa
   itu bagian dari navigasi, bukan pelampung yang berdiri sendiri. */
.langsw{flex:0 0 auto;margin-left:4px}

.wafab{
  position:fixed;right:18px;bottom:18px;z-index:60;display:inline-flex;align-items:center;gap:9px;
  padding:12px 18px 12px 14px;border-radius:99px;text-decoration:none;
  background:#1F8A50;color:#fff;font-family:var(--sans);font-size:14px;font-weight:600;
  box-shadow:0 8px 26px rgba(0,0,0,.42);transition:transform .18s,box-shadow .18s;
}
.wafab:hover{transform:translateY(-2px);box-shadow:0 12px 32px rgba(0,0,0,.5)}
.wafab svg{flex:0 0 26px}
/* Di ponsel label disembunyikan supaya tombol tidak menutupi isi halaman —
   ikonnya sudah cukup dikenali tanpa penjelasan. */
@media(max-width:620px){
  .wafab{padding:14px;border-radius:50%}
  .wafab span{display:none}
  .langsw a{padding:4px 7px;font-size:10px}
}
@media(prefers-reduced-motion:reduce){.wafab{transition:none}.wafab:hover{transform:none}}

/* ---------- ringkasan layanan: satu layar, tanpa membaca ---------- */
.svc{padding:clamp(56px,9vw,110px) clamp(20px,5vw,64px);max-width:1180px;margin:0 auto}
.svc-h{text-align:center;margin-bottom:clamp(30px,5vw,54px)}
.svc-h .tstamp{display:inline-block;margin-bottom:14px}
.svc-h h2{font-family:var(--serif);font-weight:400;line-height:1.12;
  font-size:clamp(28px,4.4vw,46px);color:var(--ivory)}
.svc-h h2 em{font-style:italic;color:var(--gold)}
.svc-h p{margin:14px auto 0;max-width:52ch;color:var(--ivory-60);font-size:clamp(14px,1.5vw,16px);line-height:1.65}

.svc-g{display:grid;gap:clamp(12px,1.6vw,18px);
  grid-template-columns:repeat(auto-fit,minmax(215px,1fr))}
.svc-c{
  padding:clamp(20px,2.4vw,28px);border-radius:16px;
  border:1px solid var(--ivory-12);background:rgba(241,234,217,.025);
  display:flex;flex-direction:column;gap:9px;
  transition:border-color .3s,transform .3s,background .3s;
}
@media(hover:hover){.svc-c:hover{border-color:var(--gold-line);transform:translateY(-3px);
  background:rgba(241,234,217,.045)}}
.svc-c .jam{font-family:var(--mono);font-size:10.5px;letter-spacing:.16em;color:var(--gold)}
.svc-c h3{font-family:var(--serif);font-weight:400;font-size:clamp(17px,2vw,21px);color:var(--ivory);line-height:1.25}
/* Satu baris saja per kartu. Begitu jadi dua baris, kartunya berhenti
   bisa dipindai sekilas dan kembali jadi paragraf — persis yang dihindari. */
.svc-c p{font-size:13.4px;line-height:1.6;color:var(--ivory-60);margin:0}
@media(prefers-reduced-motion:reduce){.svc-c{transition:none}}

/* ---------- kisi jenis vendor: ikon bulat, ala direktori ---------- */
.vgrid{display:grid;gap:clamp(16px,2.4vw,26px) clamp(8px,1.4vw,16px);
  grid-template-columns:repeat(auto-fill,minmax(104px,1fr));max-width:940px;margin:0 auto}
.vgrid-i{display:flex;flex-direction:column;align-items:center;gap:10px;
  text-decoration:none;text-align:center;padding:6px 4px}
.vgrid-b{display:grid;place-items:center;width:58px;height:58px;border-radius:50%;
  border:1px solid var(--ivory-12);background:rgba(241,234,217,.04);
  color:var(--ivory-60);transition:all .28s}
.vgrid-i span:last-child{font-size:12.6px;color:var(--ivory-60);line-height:1.35}
@media(hover:hover){
  .vgrid-i:hover .vgrid-b{border-color:var(--gold-line);background:rgba(233,168,92,.1);
    color:var(--gold);transform:translateY(-3px)}
  .vgrid-i:hover span:last-child{color:var(--ivory)}
}
@media(prefers-reduced-motion:reduce){.vgrid-b{transition:none}.vgrid-i:hover .vgrid-b{transform:none}}
*{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth;overflow-x:clip}
::selection{background:var(--ember);color:var(--ink)}
::-webkit-scrollbar{width:10px}
::-webkit-scrollbar-track{background:#05070F}
::-webkit-scrollbar-thumb{background:#2A2F45;border-radius:99px}

body{
  font-family:var(--sans);
  color:var(--ivory);
  background:var(--ink);
  line-height:1.65;
  overflow-x:hidden;
  -webkit-font-smoothing:antialiased;
}
a{color:inherit}
button{font:inherit;cursor:pointer;color:inherit}
:focus-visible{outline:2px solid var(--ember);outline-offset:3px;border-radius:4px}

/* ================= LANGIT (CSS, selalu jalan) ================= */
.sky{position:fixed;inset:0;z-index:0;background:#05070F;overflow:hidden}
.sky .layer{position:absolute;inset:0;opacity:0;will-change:opacity}
.orb{
  position:fixed;left:0;top:0;z-index:1;pointer-events:none;
  width:140px;height:140px;margin:-70px 0 0 -70px;
  border-radius:50%;
  background:radial-gradient(circle, var(--orbCore,#FFE9C4) 0%, var(--orbGlow,rgba(255,180,90,.55)) 32%, transparent 70%);
  filter:blur(2px);
  opacity:0;
  transform:translate3d(-300px,-300px,0);
  will-change:transform,opacity;
}
#stage{position:fixed;inset:0;z-index:2;pointer-events:none}
.vignette{
  position:fixed;inset:0;z-index:3;pointer-events:none;
  background:radial-gradient(ellipse at center, transparent 55%, rgba(3,5,12,.55) 100%);
}
.content{position:relative;z-index:5}

/* ================= NAV minimal ================= */
nav{
  position:fixed;inset:0 0 auto 0;z-index:40;
  display:flex;justify-content:space-between;align-items:center;
  padding:20px var(--pad);
  mix-blend-mode:normal;
}
.brand{
  font-family:var(--serif);font-style:italic;font-weight:500;
  font-size:24px;letter-spacing:.04em;text-decoration:none;
  text-shadow:0 2px 24px rgba(0,0,0,.5);
}
.brand sup{font-family:var(--mono);font-size:8.5px;font-style:normal;letter-spacing:.16em;color:var(--ember);margin-left:7px;vertical-align:super;white-space:nowrap}
@media(max-width:560px){.brand sup{display:none}}
.btn{
  display:inline-flex;align-items:center;gap:10px;
  border:1px solid var(--ivory-30);border-radius:999px;
  background:rgba(7,10,22,.55);
  padding:11px 22px;font-size:13.5px;font-weight:600;letter-spacing:.03em;
  text-decoration:none;transition:all .25s ease;
}
.btn:hover{border-color:var(--ember);color:var(--ember);transform:translateY(-1px)}
.btn.solid{background:var(--ember);border-color:var(--ember);color:var(--ink)}
.btn.solid:hover{background:var(--ember-deep);border-color:var(--ember-deep);color:var(--ink)}

/* ================= HUD JAM ================= */
.hud{
  position:fixed;left:var(--pad);bottom:26px;z-index:40;
  pointer-events:none;text-shadow:0 2px 20px rgba(0,0,0,.6);
  transition:opacity .45s ease;
}
.hud.hide{opacity:0}
.hud .t{
  font-family:var(--mono);font-weight:600;
  font-size:clamp(26px,3.4vw,40px);letter-spacing:.04em;line-height:1;
}
.hud .l{
  font-family:var(--mono);font-size:10.5px;font-weight:500;
  letter-spacing:.34em;text-transform:uppercase;
  color:var(--ember);margin-top:7px;
}
/* rel waktu di kanan */
.rail{
  position:fixed;right:26px;top:50%;transform:translateY(-50%);
  z-index:40;display:flex;flex-direction:column;gap:16px;
}
.rail a{
  position:relative;display:block;width:26px;height:2px;
  background:var(--ivory-30);border-radius:2px;
  transition:all .25s ease;
}
.rail a:hover, .rail a.on{background:var(--ember);width:38px}
.rail a span{
  position:absolute;right:46px;top:50%;transform:translateY(-50%);
  font-family:var(--mono);font-size:10.5px;letter-spacing:.14em;
  white-space:nowrap;color:var(--ivory-60);
  opacity:0;transition:opacity .2s ease;pointer-events:none;
  text-shadow:0 2px 12px rgba(0,0,0,.8);
}
.rail a:hover span, .rail a.on span{opacity:1}
@media(max-width:820px){
  .rail{display:none}
  .hud{
    left:50%;top:84px;bottom:auto;transform:translateX(-50%);
    display:flex;align-items:baseline;gap:10px;white-space:nowrap;
    background:rgba(7,10,22,.6);border:1px solid var(--ivory-12);
    border-radius:999px;padding:7px 16px 8px;
    text-shadow:none;
  }
  .hud .t{font-size:14px}
  .hud .l{font-size:8.5px;margin-top:0;letter-spacing:.2em}
}

/* ================= CHAPTER ================= */
.ch{
  min-height:100vh;min-height:100svh;
  display:flex;align-items:center;
  padding:120px var(--pad);
  position:relative;
}
.ch-in{max-width:1200px;margin:0 auto;width:100%;position:relative}
.ch-in::before{ /* scrim halus supaya teks aman di langit terang */
  content:"";position:absolute;inset:-60px 0;
  background:radial-gradient(ellipse at 30% 50%, rgba(4,6,14,.5), transparent 70%);
  z-index:-1;pointer-events:none;
}
.tstamp{
  font-family:var(--mono);font-size:12px;font-weight:500;
  letter-spacing:.3em;color:var(--ember);
  display:flex;align-items:center;gap:14px;
}
.tstamp::after{content:"";height:1px;width:64px;background:var(--ember);opacity:.5}
.ch h2, .ch h1{
  font-family:var(--serif);font-weight:400;
  font-size:clamp(2.2rem,5.6vw,4.6rem);
  line-height:1.08;letter-spacing:.005em;
  margin:22px 0 24px;max-width:17ch;
}
.ch h1 em, .ch h2 em{font-style:italic;color:var(--ember)}
.ch p.body{max-width:52ch;color:var(--ivory-60);font-size:clamp(15px,1.35vw,17.5px);font-weight:300}
.ch p.body b{color:var(--ivory);font-weight:600}
.right .ch-in{display:flex;flex-direction:column;align-items:flex-end;text-align:right}
.right .tstamp{flex-direction:row-reverse}
.right .ch-in::before{background:radial-gradient(ellipse at 70% 50%, rgba(4,6,14,.5), transparent 70%)}
@media(max-width:700px){
  .right .ch-in{align-items:flex-start;text-align:left}
  .right .tstamp{flex-direction:row}
  .right .ch-in::before{background:radial-gradient(ellipse at 30% 50%, rgba(4,6,14,.5), transparent 70%)}
  .right .mstat div{border-right:none;border-left:1px solid var(--ivory-12);padding:0 0 0 18px}
}

/* reveal */
.rv{opacity:0;transform:translateY(24px);
  transition:opacity .9s cubic-bezier(.22,.61,.36,1), transform .9s cubic-bezier(.22,.61,.36,1)}
.rv.in{opacity:1;transform:none}

/* ================= HERO ================= */
.hero .ch-in{text-align:center;display:flex;flex-direction:column;align-items:center}
.hero .ch-in::before{background:radial-gradient(ellipse at 50% 45%, rgba(4,6,14,.55), transparent 72%)}
.dict{
  font-family:var(--mono);font-size:12.5px;letter-spacing:.06em;
  color:var(--ivory-60);border:1px solid var(--ivory-12);
  border-radius:999px;padding:9px 20px;margin-bottom:34px;
  background:rgba(7,10,22,.45);
}
.dict b{color:var(--ivory);font-weight:500}
.dict i{color:var(--ember)}
.hero h1{max-width:15ch;margin-inline:auto}
.hero .body{margin-inline:auto;text-align:center}
.hero-cta{display:flex;gap:14px;margin-top:40px;flex-wrap:wrap;justify-content:center}
.scroll-hint{
  position:absolute;bottom:34px;left:50%;transform:translateX(-50%);
  font-family:var(--mono);font-size:10px;letter-spacing:.4em;
  color:var(--ivory-60);text-transform:uppercase;
  display:flex;flex-direction:column;align-items:center;gap:12px;
}
.scroll-hint i{
  width:1px;height:52px;background:linear-gradient(var(--ember), transparent);
  display:block;animation:drip 2.2s ease-in-out infinite;
}
@keyframes drip{0%{transform:scaleY(0);transform-origin:top}55%{transform:scaleY(1);transform-origin:top}56%{transform-origin:bottom}100%{transform:scaleY(0);transform-origin:bottom}}

/* ================= PANGGIH steps ================= */
.steps{
  display:flex;flex-wrap:wrap;gap:10px 0;margin-top:34px;max-width:760px;
  font-family:var(--mono);font-size:12.5px;letter-spacing:.04em;
}
.steps span{display:flex;align-items:center;color:var(--ivory-60)}
.steps b{color:var(--ivory);font-weight:500}
.steps span:not(:last-child)::after{content:"→";margin:0 14px;color:var(--ember)}

/* stat kecil */
.mstat{
  display:flex;gap:clamp(24px,4vw,64px);margin-top:38px;flex-wrap:wrap;
}
.mstat div{border-left:1px solid var(--ivory-12);padding-left:18px}
.right .mstat div{border-left:none;border-right:1px solid var(--ivory-12);padding:0 18px 0 0}
.mstat .n{font-family:var(--serif);font-style:italic;font-size:clamp(1.6rem,2.6vw,2.3rem);line-height:1}
.mstat .d{font-family:var(--mono);font-size:10.5px;letter-spacing:.22em;text-transform:uppercase;color:var(--ivory-60);margin-top:8px}

/* ================= PRESET (susunan hari) ================= */
.presets{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:52px;width:100%}
@media(max-width:980px){.presets{grid-template-columns:1fr;max-width:600px}}
.pre{
  border:1px solid var(--ivory-12);border-radius:18px;
  background:rgba(7,10,22,.6);backdrop-filter:blur(6px);
  padding:26px 24px;text-align:left;
  transition:border-color .25s ease, transform .25s ease;
}
.pre:hover{border-color:var(--ember);transform:translateY(-4px)}
.pre h3{font-family:var(--serif);font-style:italic;font-weight:400;font-size:1.8rem}
.pre .sub{font-size:12.5px;color:var(--ivory-60);margin-top:2px}
.minibar-strip{
  position:relative;height:34px;margin:16px 0 20px;
  border-block:1px solid var(--ivory-12);
}
.minibar-strip .mb{
  position:absolute;top:7px;height:18px;border-radius:5px;
  background:linear-gradient(90deg, var(--ember), var(--ember-deep));
  opacity:.9;
}
.minibar-strip .mb.h1{background:rgba(241,234,217,.28)}
.pre ul{list-style:none;font-size:13px;color:var(--ivory-60);margin-bottom:22px}
.pre li{padding:3px 0;display:flex;gap:10px}
.pre li::before{content:"◦";color:var(--ember)}
.pre .btn{width:100%;justify-content:center}

/* ================= COMPOSER ================= */
.composer .ch-in{max-width:1280px}
.comp-grid{display:grid;grid-template-columns:1.55fr 1fr;gap:clamp(24px,3.5vw,56px);margin-top:48px;align-items:start;width:100%}
/* Tanpa ini, grid item menolak menyusut di bawah lebar isinya
   (.strip-shell min-width:620px) sehingga seluruh halaman melebar
   di layar ponsel. min-width:0 mengembalikan penggulungan ke .stripwrap. */
.comp-grid > *{min-width:0}
@media(max-width:1020px){.comp-grid{grid-template-columns:1fr}}
.panel{
  border:1px solid var(--ivory-12);border-radius:20px;
  background:rgba(7,10,22,.66);backdrop-filter:blur(6px);
  padding:clamp(20px,2.6vw,32px);
}
.plabel{
  font-family:var(--mono);font-size:10.5px;font-weight:600;
  letter-spacing:.3em;text-transform:uppercase;color:var(--ember);
  display:block;margin-bottom:18px;
}
.stripwrap{overflow-x:auto;padding-bottom:6px}
.stripwrap::-webkit-scrollbar{height:6px}
.strip-shell{min-width:620px}
.strip-name{
  font-family:var(--mono);font-size:10px;letter-spacing:.24em;
  color:var(--ivory-60);text-transform:uppercase;margin:20px 0 8px;
}
.strip{
  position:relative;height:64px;border-radius:10px;
  background:rgba(241,234,217,.04);
  border:1px solid var(--ivory-12);
}
.tick{
  position:absolute;top:0;bottom:0;width:1px;background:var(--ivory-12);
}
.tick span{
  position:absolute;top:calc(100% + 6px);left:0;transform:translateX(-50%);
  font-family:var(--mono);font-size:9.5px;color:var(--ivory-30);letter-spacing:.05em;
}
.blk{
  position:absolute;top:9px;height:46px;border-radius:8px;
  background:linear-gradient(120deg, var(--ember), var(--ember-deep));
  color:var(--ink);border:none;
  display:flex;flex-direction:column;justify-content:center;
  padding:0 12px;overflow:hidden;text-align:left;
  box-shadow:0 8px 24px -10px rgba(233,168,92,.5);
  animation:pop .35s cubic-bezier(.34,1.56,.64,1) backwards;
}
@keyframes pop{from{transform:scaleX(.4);opacity:0}to{transform:none;opacity:1}}
.blk .bl{font-weight:700;font-size:12px;line-height:1.15;white-space:nowrap}
.blk .bt{font-family:var(--mono);font-size:9.5px;opacity:.75;white-space:nowrap}
.blk:hover{filter:brightness(1.08)}
.strip .empty{
  position:absolute;inset:0;display:grid;place-items:center;
  font-family:var(--mono);font-size:11px;letter-spacing:.16em;
  color:var(--ivory-30);pointer-events:none;
}

.chips{display:flex;flex-wrap:wrap;gap:10px;margin-top:26px}
.chip{
  display:flex;align-items:baseline;gap:10px;
  border:1px solid var(--ivory-30);border-radius:999px;
  background:transparent;padding:10px 18px;
  font-size:13px;font-weight:600;transition:all .2s ease;
}
.chip small{font-family:var(--mono);font-size:10.5px;color:var(--ivory-60);font-weight:400}
.chip:hover{border-color:var(--ember)}
.chip.on{background:var(--ember);border-color:var(--ember);color:var(--ink)}
.chip.on small{color:rgba(7,10,22,.65)}

.lane{margin-top:30px;border-top:1px dashed var(--ivory-12);padding-top:24px}
.guest-row{display:flex;align-items:baseline;justify-content:space-between;gap:16px;margin-bottom:12px}
.guest-row .gv{font-family:var(--mono);font-size:19px;font-weight:600}
.guest-row .gv small{font-size:11px;color:var(--ivory-60);font-weight:400}
input[type=range]{
  width:100%;appearance:none;height:3px;border-radius:99px;
  background:linear-gradient(to right, var(--ember) var(--fill,20%), var(--ivory-12) var(--fill,20%));
}
input[type=range]::-webkit-slider-thumb{
  appearance:none;width:20px;height:20px;border-radius:50%;
  background:var(--ink);border:2.5px solid var(--ember);cursor:grab;
}
input[type=range]::-moz-range-thumb{
  width:15px;height:15px;border-radius:50%;
  background:var(--ink);border:2.5px solid var(--ember);cursor:grab;
}

/* call sheet */
.callsheet{position:sticky;top:90px}
.cs-head{
  display:flex;justify-content:space-between;align-items:center;
  border-bottom:1px dashed var(--ivory-12);padding-bottom:16px;margin-bottom:6px;
}
.cs-head .plabel{margin:0}
.cs-head .dotlive{width:8px;height:8px;border-radius:50%;background:var(--ember);animation:pulse 1.8s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.2}}
.cs-items{list-style:none;font-family:var(--mono);font-size:12.5px;padding:12px 0;max-height:300px;overflow:auto}
.cs-items li{display:flex;justify-content:space-between;gap:12px;padding:6px 0;align-items:baseline}
.cs-items .cn{color:var(--ivory-60);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cs-items .cn b{color:var(--ivory);font-weight:500}
.cs-items .cp{white-space:nowrap}
.callsheet .btn{width:100%;justify-content:center;margin-top:20px}
.cs-note{font-size:11px;color:var(--ivory-30);text-align:center;margin-top:14px;line-height:1.6}

/* minibar mobile */
.mbar{
  position:fixed;left:12px;right:12px;bottom:12px;z-index:45;
  background:rgba(10,13,26,.92);backdrop-filter:blur(8px);
  border:1px solid var(--ivory-12);border-radius:16px;
  padding:12px 14px 12px 18px;
  display:none;align-items:center;justify-content:space-between;gap:12px;
  transform:translateY(130%);transition:transform .3s ease;
}
.mbar.on{transform:none}
.mbar .mt{font-family:var(--mono);font-weight:600;font-size:15px;color:var(--ember)}
.mbar .ml{font-family:var(--mono);font-size:9px;letter-spacing:.2em;text-transform:uppercase;color:var(--ivory-60);display:block}
@media(max-width:1020px){.mbar{display:flex}.callsheet{position:static}}

/* ================= KONTAK & FOOTER ================= */
.kontak .ch-in{text-align:center;display:flex;flex-direction:column;align-items:center}
.kontak .ch-in::before{background:radial-gradient(ellipse at 50% 50%, rgba(4,6,14,.55), transparent 72%)}
.kontak-cta{display:flex;gap:14px;margin-top:38px;flex-wrap:wrap;justify-content:center}
.kontak .meta{
  margin-top:64px;display:flex;gap:30px;flex-wrap:wrap;justify-content:center;
  font-family:var(--mono);font-size:11.5px;letter-spacing:.12em;color:var(--ivory-30);
}
footer{
  position:relative;z-index:5;
  padding:26px var(--pad);
  display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;
  font-family:var(--mono);font-size:10.5px;letter-spacing:.1em;
  color:var(--ivory-30);border-top:1px solid var(--ivory-12);
  background:rgba(4,6,14,.6);
}


/* ================= NAV kanan ================= */
.navr{display:flex;align-items:center;gap:clamp(12px,2vw,22px)}
.navlink{
  font-family:var(--mono);font-size:10.5px;font-weight:500;
  letter-spacing:.22em;text-transform:uppercase;
  color:var(--ivory-60);text-decoration:none;transition:color .2s ease;
  text-shadow:0 2px 14px rgba(0,0,0,.7);
}
.navlink:hover{color:var(--ember)}
@media(max-width:700px){.navlink{display:none}}

/* ================= PINTU GALERI ================= */
/* Kartu lebar berisi tiga foto asli — begitu dilihat, langsung jelas
   ini pintu menuju galeri, bukan sekadar tombol. */
.gate{
  display:inline-flex;align-items:center;gap:clamp(18px,2.4vw,30px);
  margin-top:42px;padding:18px 30px 18px 22px;
  border:1px solid var(--ivory-12);border-radius:22px;
  background:rgba(7,10,22,.62);backdrop-filter:blur(8px);
  text-decoration:none;text-align:left;
  transition:border-color .3s ease, transform .3s ease, background .3s ease,
             box-shadow .3s ease;
}
.right .ch-in .gate{flex-direction:row-reverse;text-align:right}
.gate:hover{
  border-color:var(--ember);transform:translateY(-4px);
  background:rgba(7,10,22,.78);
  box-shadow:0 26px 60px -30px rgba(233,168,92,.55);
}
/* tumpukan foto */
.gate-stack{position:relative;flex:0 0 auto;width:132px;height:96px}
.gate-stack img{
  position:absolute;top:50%;width:70px;height:88px;margin-top:-44px;
  object-fit:cover;border-radius:10px;
  border:1px solid rgba(241,234,217,.22);
  box-shadow:0 12px 28px -12px rgba(0,0,0,.9);
  transition:transform .38s cubic-bezier(.22,.61,.36,1);
  background:linear-gradient(158deg,#2a1838,#0c0b17);
}
.gate-stack img:nth-child(1){left:0;  transform:rotate(-9deg) translateY(4px);z-index:1}
.gate-stack img:nth-child(2){left:31px;transform:rotate(0deg);z-index:3}
.gate-stack img:nth-child(3){left:62px;transform:rotate(9deg) translateY(4px);z-index:2}
.gate:hover .gate-stack img:nth-child(1){transform:rotate(-15deg) translate(-7px,-2px)}
.gate:hover .gate-stack img:nth-child(2){transform:rotate(0deg) translateY(-8px)}
.gate:hover .gate-stack img:nth-child(3){transform:rotate(15deg) translate(7px,-2px)}
/* lencana video kecil di keping ketiga */
.gate-stack .vd{
  position:absolute;z-index:4;left:96px;top:calc(50% - 30px);
  width:20px;height:20px;border-radius:50%;
  background:rgba(7,10,22,.72);border:1px solid rgba(241,234,217,.5);
}
.gate-stack .vd::after{content:"";position:absolute;top:50%;left:55%;
  transform:translate(-50%,-50%);border-style:solid;border-width:3.5px 0 3.5px 6px;
  border-color:transparent transparent transparent var(--ivory)}

.gate-txt{display:flex;flex-direction:column;gap:6px}
.gate .gl{font-family:var(--serif);font-style:italic;font-size:clamp(1.5rem,2.6vw,1.9rem);line-height:1}
.gate .gd{font-family:var(--mono);font-size:10.5px;letter-spacing:.22em;
  text-transform:uppercase;color:var(--ivory-60)}
.gate .go{
  display:inline-flex;align-items:center;gap:9px;margin-top:4px;
  font-size:13px;font-weight:600;color:var(--ember);
}
.gate .go::after{content:"→";transition:transform .3s ease}
.gate:hover .go::after{transform:translateX(5px)}
@media(max-width:560px){
  .gate{padding:16px 20px;gap:16px;width:100%}
  .gate-stack{width:104px;height:80px}
  .gate-stack img{width:56px;height:72px;margin-top:-36px}
  .gate-stack img:nth-child(2){left:24px}
  .gate-stack img:nth-child(3){left:48px}
  .gate-stack .vd{left:74px;top:calc(50% - 24px)}
}

/* ================= PESAN SETELAH ACARA ================= */
.notes{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:46px;width:100%}
@media(max-width:900px){.notes{grid-template-columns:1fr;max-width:540px}}
.note{
  border:1px solid var(--ivory-12);border-radius:16px 16px 16px 5px;
  background:rgba(7,10,22,.66);backdrop-filter:blur(6px);
  padding:22px 22px 15px;
}
.note p{font-size:14.5px;line-height:1.62;color:var(--ivory)}
.note .who{
  margin-top:18px;font-family:var(--mono);font-size:9.5px;
  letter-spacing:.2em;text-transform:uppercase;color:var(--ember);
}
.note .stamp{
  display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin-top:8px;
  font-family:var(--mono);font-size:9px;letter-spacing:.14em;color:var(--ivory-30);
}
.note .stamp .rd{color:var(--ember)}

/* ================= FAQ ================= */
.faq{width:100%;max-width:880px;margin-top:44px;border-top:1px solid var(--ivory-12)}
.faq details{border-bottom:1px solid var(--ivory-12)}
.faq summary{
  list-style:none;cursor:pointer;display:flex;gap:24px;
  justify-content:space-between;align-items:baseline;padding:19px 0;
  font-size:clamp(15px,1.7vw,18px);font-weight:500;
}
.faq summary::-webkit-details-marker{display:none}
.faq summary::after{content:"+";font-family:var(--mono);font-size:19px;color:var(--ember);line-height:1}
.faq details[open] summary::after{content:"–"}
.faq details p{
  color:var(--ivory-60);font-size:14.5px;line-height:1.72;
  max-width:64ch;padding:0 0 22px;
}
.faq details p b{color:var(--ivory);font-weight:600}

/* ================= PRESET tanpa harga ================= */
.pre .fit{
  font-family:var(--mono);font-size:9.5px;letter-spacing:.22em;
  text-transform:uppercase;color:var(--ivory-30);margin:20px 0 0;
}
.pre .fit b{
  display:block;margin-top:7px;font-family:var(--sans);font-size:13.5px;
  letter-spacing:0;text-transform:none;color:var(--ivory-60);font-weight:400;line-height:1.55;
}
.pre .dur{
  font-family:var(--mono);font-size:10.5px;letter-spacing:.18em;
  text-transform:uppercase;color:var(--ember);margin-top:12px;
}
.ask-note{
  margin-top:36px;border-left:2px solid var(--ember);padding-left:18px;
  font-size:13.5px;line-height:1.75;color:var(--ivory-60);max-width:62ch;
}
.ask-note b{color:var(--ivory);font-weight:600}

/* ================= RINGKASAN CALL SHEET (pengganti total) ================= */
.cs-sum{
  border-top:1px dashed var(--ivory-12);margin-top:6px;padding-top:18px;
  display:grid;grid-template-columns:repeat(3,1fr);gap:12px;
}
.cs-sum div{display:flex;flex-direction:column;gap:7px}
.cs-sum .lbl{
  font-family:var(--mono);font-size:8.5px;letter-spacing:.2em;
  text-transform:uppercase;color:var(--ivory-60);
}
.cs-sum .val{
  font-family:var(--mono);font-weight:600;font-size:clamp(13px,1.4vw,15.5px);
  color:var(--ember);line-height:1.2;
}
.cs-date{margin-top:22px;border-top:1px dashed var(--ivory-12);padding-top:20px}
.cs-date .plabel{margin-bottom:11px}
input[type=date]{
  width:100%;background:rgba(241,234,217,.05);
  border:1px solid var(--ivory-30);border-radius:10px;
  padding:11px 13px;color:var(--ivory);
  font-family:var(--mono);font-size:13px;
}
input[type=date]:focus{border-color:var(--ember);outline:none}
input[type=date]::-webkit-calendar-picker-indicator{
  filter:invert(80%) sepia(38%) saturate(700%) hue-rotate(-12deg);cursor:pointer;
}
.dayout{
  font-family:var(--mono);font-size:10.5px;line-height:1.6;
  color:var(--ivory-30);margin-top:10px;min-height:1.3em;
}
.dayout.hi{color:var(--ember)}
.cs-actions{display:grid;gap:10px;margin-top:20px}
.cs-actions .btn{width:100%;justify-content:center;margin-top:0}

@media (prefers-reduced-motion: reduce){
  html{scroll-behavior:auto}
  .gate-stack img{transition:none}
  .rv{opacity:1;transform:none;transition:none}
  .scroll-hint i,.cs-head .dotlive{animation:none}
  .blk{animation:none}
}

/* ================= PANEL EVENT (agenda & rekam jejak) ================= */
.ev-sheet{list-style:none;margin:38px 0 0;max-width:820px;border-top:1px solid var(--ivory-12)}
.ev-row{border-bottom:1px solid var(--ivory-12)}
.ev-row a{
  display:grid;grid-template-columns:74px 1fr auto;gap:22px;align-items:center;
  padding:18px 6px;text-decoration:none;transition:background .2s,padding .2s;
}
.ev-row a:hover{background:var(--ivory-12);padding-left:16px;padding-right:16px}
.ev-date{font-family:var(--mono);line-height:1.15;text-align:center}
.ev-date b{display:block;font-family:var(--serif);font-style:italic;font-weight:400;font-size:30px;color:var(--ember)}
.ev-date i{display:block;font-style:normal;font-size:10px;letter-spacing:.18em;color:var(--ivory-60);margin-top:2px}
.ev-date u{display:block;text-decoration:none;font-size:9.5px;letter-spacing:.14em;color:var(--ivory-30)}
.ev-body{min-width:0}
.ev-title{display:block;font-family:var(--serif);font-style:italic;font-size:clamp(1.15rem,2vw,1.5rem);line-height:1.25}
.ev-meta{display:block;font-family:var(--mono);font-size:10.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--ivory-60);margin-top:6px}
.ev-count{text-align:right;font-family:var(--mono);white-space:nowrap}
.ev-count b{display:block;font-family:var(--serif);font-style:italic;font-weight:400;font-size:26px;color:var(--ivory)}
.ev-count i{display:block;font-style:normal;font-size:9.5px;letter-spacing:.16em;text-transform:uppercase;color:var(--ivory-30);margin-top:3px}
.ev-note{font-size:13px;color:var(--ivory-60);margin-top:24px}
.ev-note a{color:var(--ember);text-decoration:none;border-bottom:1px solid transparent;transition:border-color .2s}
.ev-note a:hover{border-bottom-color:var(--ember)}

.ev-strip{
  display:flex;gap:16px;overflow-x:auto;padding:32px 0 14px;margin-right:calc(var(--pad) * -1);
  scroll-snap-type:x mandatory;scrollbar-width:thin;
}
.ev-strip::-webkit-scrollbar{height:5px}
.ev-strip::-webkit-scrollbar-thumb{background:var(--ivory-12);border-radius:99px}
.ev-card{
  flex:0 0 232px;scroll-snap-align:start;text-decoration:none;
  border:1px solid var(--ivory-12);border-radius:12px;overflow:hidden;
  background:rgba(7,10,22,.55);transition:border-color .25s,transform .25s;
}
.ev-card:hover{border-color:var(--ember);transform:translateY(-4px)}
.ev-card img,.ev-noimg{width:100%;aspect-ratio:4/3;object-fit:cover;display:block;background:#1F2023}
.ev-noimg{background:linear-gradient(135deg,#1F2023,#292B2F)}
.ev-card-in{display:block;padding:13px 15px 16px}
.ev-card-date{display:block;font-family:var(--mono);font-size:9.5px;letter-spacing:.16em;text-transform:uppercase;color:var(--ember)}
.ev-card-title{display:block;font-family:var(--serif);font-style:italic;font-size:1.05rem;margin-top:5px;line-height:1.3}
.ev-card-meta{display:block;font-size:11.5px;color:var(--ivory-60);margin-top:4px}

@media(max-width:640px){
  .ev-row a{grid-template-columns:58px 1fr;gap:15px;padding:15px 4px}
  .ev-date b{font-size:24px}
  .ev-count{grid-column:2;text-align:left;margin-top:-4px}
  .ev-count b{font-size:16px;display:inline}
  .ev-count i{display:inline;margin-left:5px}
  .ev-card{flex-basis:180px}
}
</style>
<?= hreflangTags() ?></head>
<body>

<!-- lapisan atmosfer -->
<div class="sky" aria-hidden="true"></div>
<div class="orb" aria-hidden="true"></div>
<canvas id="stage" aria-hidden="true"></canvas>
<div class="vignette" aria-hidden="true"></div>

<nav>
  <a class="brand" href="#subuh">Callalily<sup>PARTY · WEDDING ORGANIZER</sup></a>
  <div class="navr">
    <a class="navlink" href="<?= url('galeri') ?>"><?= t('Galeri pesta') ?></a>
    <a class="navlink" href="<?= url('vendor') ?>"><?= t('Jenis vendor') ?></a>
    <a class="navlink" href="<?= url('blog') ?>"><?= t('Jurnal') ?></a>
    <a class="navlink" href="#tanya"><?= t('Tanya jawab') ?></a>
    <a class="btn solid" href="#susun"><?= t('Susun harimu') ?></a>
    <?= pemilihBahasa() ?>
  </div>
</nav>

<!-- HUD jam -->
<div class="hud" aria-hidden="true">
  <div class="t" id="hudTime">04.50</div>
  <div class="l" id="hudLabel">SEBELUM SUBUH</div>
</div>
<div class="rail" id="rail" aria-label="Navigasi waktu"></div>

<main class="content">

<!-- ======== 04.50 HERO ======== -->
<section class="ch hero" id="subuh" data-time="04.50" data-label="Sebelum subuh">
  <div class="ch-in">
    <p class="rv" style="font-family:var(--mono);font-size:11.5px;letter-spacing:.18em;
       text-transform:uppercase;color:var(--ivory-60);margin:0 0 14px">
      <?= t('Wedding Organizer Yogyakarta &amp; Jawa Tengah') ?></p>
    <div class="dict rv"><b>cal·la lily</b> <i>/n./</i> — lili putih: mekar sekali, dikenang selamanya.</div>
    <h1 class="rv"><?= t('Semua hari besar dimulai ketika langit <em>masih gelap.</em>') ?></h1>
    <p class="body rv"><?= t('Gulir pelan-pelan. Ini satu hari yang kami pegang, dari subuh sampai lewat tengah malam.') ?></p>
    <div class="hero-cta rv">
      <a class="btn solid" href="#susun"><?= t('Susun harimu sendiri') ?></a>
      <a class="btn" href="#pagi"><?= t('Ikuti harinya ↓') ?></a>
    </div>
  </div>
  <div class="scroll-hint"><span><?= t('Gulir&nbsp;=&nbsp;waktu') ?></span><i></i></div>
</section>

<!-- ======== 05.00 PANEL 2 · AGENDA (dinamis dari sistem) ======== -->
<?php require __DIR__ . '/partials/events-upcoming.php'; ?>

<!-- ======== 05.10 PANEL 3 · REKAM JEJAK (dinamis dari sistem) ======== -->
<?php require __DIR__ . '/partials/events-past.php'; ?>


<!-- ======== 05.20 RINGKASAN LAYANAN · dipindai, bukan dibaca ======== -->
<section class="svc" id="layanan">
  <div class="svc-h rv">
    <span class="tstamp"><?= t('05.20 · SATU HARI, DELAPAN PERHENTIAN') ?></span>
    <h2><?= t('Yang kami pegang, <em>dari gelap ke gelap.</em>') ?></h2>
    <p><?= t('Kalau tidak sempat menggulir sampai bawah, ini ringkasannya.') ?></p>
  </div>

  <div class="svc-g">
    <?php foreach ([
      ['04.50', 'Sebelum subuh',   'Cue sheet dibagikan, genset diuji, jalur rias disterilkan.', '#pagi'],
      ['08.30', 'Akad',            'Kru membeku di posisi. Tidak ada langkah kaki, tidak ada radio.', '#subuh'],
      ['10.00', 'Prosesi adat',    'Pendamping hafal urutan panggih, dan tahu kapan menuntun.',      '#subuh'],
      ['16.30', 'Jeda emas',       'Satu slot yang tidak boleh diganggu siapa pun. Termasuk kami.',  '#subuh'],
      ['19.00', 'Resepsi',         'Satu show director, satu timekeeper, satu kru tiap 100 tamu.',   '#subuh'],
      ['21.00', 'Puncak pesta',    'Lantai dansa, kembang api, suara serak sampai kunang-kunang.',   '#arsip'],
      ['22.00', 'Larut',           'Loading-out berjalan senyap di belakang punggung tamu.',         '#pesan'],
      ['23.30', 'Susun harimu',    'Bukan paket. Garis waktu yang kamu atur sendiri.',               '#susun'],
    ] as [$jam, $judul, $ket, $anchor]): ?>
      <a class="svc-c rv" href="<?= $anchor ?>">
        <span class="jam"><?= e($jam) ?></span>
        <h3><?= t($judul) ?></h3>
        <p><?= t($ket) ?></p>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<!-- ======== 05.30 PERSIAPAN ======== -->
<section class="ch" id="pagi" data-time="05.30" data-label="Crew call">
  <div class="ch-in">
    <span class="tstamp rv"><?= t('05.30 · RUMAH MEMPELAI') ?></span>
    <h2 class="rv"><?= t('Rumah mulai harum melati. Kami sudah datang <em>lebih dulu.</em>') ?></h2>
    <p class="body rv"><?= t('Cue sheet 38 halaman, jalur rias steril, genset cadangan diuji. <b>Ketenangan pagimu pekerjaan kami semalaman.</b>') ?></p>
    <div class="mstat rv">
      <div><span class="n">38 hal.</span><span class="d">rundown per acara</span></div>
      <div><span class="n">H-30</span><span class="d">gladi &amp; technical meeting</span></div>
      <div><span class="n">04.30</span><span class="d">kru pertama tiba</span></div>
    </div>
  </div>
</section>

<!-- ======== 08.30 AKAD ======== -->
<section class="ch right" data-time="08.30" data-label="Ijab qabul">
  <div class="ch-in">
    <span class="tstamp rv"><?= t('08.30 · RUANG AKAD') ?></span>
    <h2 class="rv"><?= t('Enam puluh detik paling hening <em>seumur hidupmu.</em>') ?></h2>
    <p class="body rv"><?= t('Semua kru membeku di posisinya. Menit ini tepat waktu karena buffer 20 menit tadi pagi — <b>tanpa kamu tahu ada yang menjaganya.</b>') ?></p>
  </div>
</section>

<!-- ======== 10.00 PANGGIH ======== -->
<section class="ch" data-time="10.00" data-label="Panggih">
  <div class="ch-in">
    <span class="tstamp rv"><?= t('10.00 · GAPURA JANUR') ?></span>
    <h2 class="rv"><?= t('Tujuh langkah tua, dijalankan <em>tanpa keraguan.</em>') ?></h2>
    <p class="body rv"><?= t('Pendamping adat kami tahu kapan menuntun eyang yang lupa giliran — tanpa satu tamu pun menyadari.') ?></p>
    <div class="steps rv">
      <span><b>Balangan gantal</b></span>
      <span><b>Wijikan</b></span>
      <span><b>Midak antiga</b></span>
      <span><b>Sindur binayang</b></span>
      <span><b>Bobot timbang</b></span>
      <span><b>Kacar-kucur</b></span>
      <span><b>Dhahar klimah</b></span>
    </div>
  </div>
</section>

<!-- ======== 16.30 JEDA EMAS ======== -->
<section class="ch right" data-time="16.30" data-label="Jeda emas">
  <div class="ch-in">
    <span class="tstamp rv"><?= t('16.30 · TAMAN BELAKANG') ?></span>
    <h2 class="rv"><?= t('Satu jam ketika kalian berdua <em>menghilang dari dunia.</em>') ?></h2>
    <p class="body rv"><?= t('Golden hour. Satu slot yang tidak boleh diganggu siapa pun — <b>bahkan kami menunggu di luar cahaya.</b>') ?></p>
  </div>
</section>

<!-- ======== 19.00 RESEPSI ======== -->
<section class="ch" data-time="19.00" data-label="Kirab pengantin">
  <div class="ch-in">
    <span class="tstamp rv"><?= t('19.00 · BALLROOM') ?></span>
    <h2 class="rv"><?= t('Lampu turun. Gending naik. <em>Ini adegan pembukamu.</em>') ?></h2>
    <p class="body rv"><?= t('Satu show director, satu timekeeper, satu kru tiap 100 tamu. Termasuk untuk om yang minta lagu.') ?></p>
    <div class="mstat rv">
      <div><span class="n">1 : 100</span><span class="d">kru per tamu</span></div>
      <div><span class="n">±12 mnt</span><span class="d">durasi sambutan, disepakati</span></div>
      <div><span class="n">0</span><span class="d">masalah yang sampai ke kalian</span></div>
    </div>
  </div>
</section>

<!-- ======== 21.00 PINTU GALERI PESTA ======== -->
<section class="ch right" id="arsip" data-time="21.00" data-label="Puncak pesta">
  <div class="ch-in">
    <span class="tstamp rv"><?= t('21.00 · PUNCAK PESTA') ?></span>
    <h2 class="rv"><?= t('Yang paling diingat tamu, <em>bukan akadnya.</em>') ?></h2>
    <p class="body rv"><?= t('Lampu turun, lantai dansa penuh, suara serak sampai kunang-kunang keluar. Kami simpan di satu bola cahaya.') ?></p>
    <a class="gate rv" href="<?= url('galeri') ?>" aria-label="Buka galeri pesta: 12 momen foto dan video">
      <span class="gate-stack" aria-hidden="true">
        <img src="foto/winda-zakki-01-thumb.jpg" alt="" loading="lazy">
        <img src="foto/ichsan-dewa-02-thumb.jpg" alt="" loading="lazy">
        <img src="foto/adin-erik-thumb.jpg" alt="" loading="lazy">
        <span class="vd"></span>
      </span>
      <span class="gate-txt">
        <span class="gl">Galeri pesta</span>
        <span class="gd">12 momen · foto &amp; video</span>
        <span class="go">Buka galerinya</span>
      </span>
    </a>
  </div>
</section>

<!-- ======== 22.00 LARUT ======== -->
<section class="ch right" data-time="22.00" data-label="Larut">
  <div class="ch-in">
    <span class="tstamp rv"><?= t('22.00 · HALAMAN VENUE') ?></span>
    <h2 class="rv"><?= t('Tamu terakhir pulang. Kalian <em>masih berdansa.</em>') ?></h2>
    <p class="body rv"><?= t('Hari yang kami hitung dalam menit, kalian kenang dalam <b>puluhan tahun.</b>') ?></p>
  </div>
</section>

<!-- ======== 22.30 PESAN SETELAHNYA ======== -->
<section class="ch" id="pesan" data-time="22.30" data-label="Pesan yang datang">
  <div class="ch-in">
    <span class="tstamp rv"><?= t('22.30 · SEHARI SESUDAHNYA') ?></span>
    <h2 class="rv"><?= t('Yang masuk ke ponsel kami <em>keesokan paginya.</em>') ?></h2>
    <p class="body rv"><?= t('Kami tidak memajang piala. Yang kami simpan pesan seperti ini.') ?></p>
    <div class="notes rv">
      <article class="note">
        <p>Ibu saya cuma bilang satu kalimat: “kok bisa ya semuanya jalan sendiri.” Padahal timnya yang jalan — kami memang tidak pernah lihat.</p>
        <div class="who">Dita &amp; Bagas</div>
        <div class="stamp"><span>Resepsi ballroom · Sleman</span><span class="rd">08.12 ✓✓</span></div>
      </article>
      <article class="note">
        <p>Hujan turun jam empat sore dan saya sempat panik. Lima menit kemudian tendanya sudah pindah. Saya bahkan belum sempat bertanya.</p>
        <div class="who">Rani &amp; Yoga</div>
        <div class="stamp"><span>Garden party · Kalasan</span><span class="rd">09.40 ✓✓</span></div>
      </article>
      <article class="note">
        <p>Yang paling saya ingat justru satu jam waktu kami cuma berdua di taman. Baru tahu belakangan kalau jam itu memang sengaja dikosongkan.</p>
        <div class="who">Nabila &amp; Farhan</div>
        <div class="stamp"><span>Akad &amp; panggih · Kotagede</span><span class="rd">07.05 ✓✓</span></div>
      </article>
    </div>
  </div>
</section>

<!-- ======== 23.00 PRESETS ======== -->
<section class="ch" id="paket" data-time="23.00" data-label="Tiga susunan hari">
  <div class="ch-in">
    <span class="tstamp rv"><?= t('23.00 · TIGA SUSUNAN HARI') ?></span>
    <h2 class="rv"><?= t('Bukan paket. <em>Susunan hari.</em>') ?></h2>
    <p class="body rv"><?= t('Tiga arsitektur hari yang paling sering kami pentaskan. Muat satu, lalu ubah jadi harimu.') ?></p>

    <div class="presets rv">
      <article class="pre">
        <h3><?= t('Prasaja') ?></h3>
        <p class="sub">Satu acara. Hening, hangat, rapi.</p>
        <p class="fit">Cocok bila<b>Satu acara saja, tamu terbatas, waktu yang ringkas.</b></p>
        <p class="dur">Durasi acara ±<span id="durPrasaja">—</span></p>
        <div class="minibar-strip" data-pre="prasaja"></div>
        <ul>
          <li>Akad / pemberkatan + ramah tamah</li>
          <li>±150 tamu · 2 kru lapangan + tim inti</li>
          <li>Manajemen katering</li>
        </ul>
        <button class="btn" data-load="prasaja"><?= t('Muat susunan ini') ?></button>
      </article>

      <article class="pre">
        <h3><?= t('Semanak') ?></h3>
        <p class="sub">Akad pagi, pesta malam. Hari penuh.</p>
        <p class="fit">Cocok bila<b>Akad pagi dan resepsi malam jatuh di hari yang sama.</b></p>
        <p class="dur">Durasi acara ±<span id="durSemanak">—</span></p>
        <div class="minibar-strip" data-pre="semanak"></div>
        <ul>
          <li>Akad + resepsi malam + kirab</li>
          <li>±400 tamu · MC, dokumentasi, katering</li>
          <li>Perencanaan &amp; kurasi vendor 9 bulan</li>
        </ul>
        <button class="btn" data-load="semanak"><?= t('Muat susunan ini') ?></button>
      </article>

      <article class="pre">
        <h3><?= t('Sidomukti') ?></h3>
        <p class="sub">Rangkaian adat penuh, H-1 sampai larut.</p>
        <p class="fit">Cocok bila<b>Rangkaian adat lengkap, dimulai sejak H−1.</b></p>
        <p class="dur">Durasi acara ±<span id="durSidomukti">—</span></p>
        <div class="minibar-strip" data-pre="sidomukti"></div>
        <ul>
          <li>Siraman &amp; midodareni di H-1</li>
          <li>Akad, panggih, resepsi, after-party</li>
          <li>±800 tamu · seluruh layanan aktif</li>
        </ul>
        <button class="btn" data-load="sidomukti"><?= t('Muat susunan ini') ?></button>
      </article>
    </div>

    <p class="ask-note rv"><b>Kenapa tidak ada harga di sini?</b> Karena tidak ada dua hari yang sama. Jumlah acara, tamu, jarak venue, dan tanggalnya menggeser angkanya cukup jauh — menempel satu nominal di halaman ini hanya akan salah untuk hampir semua orang. Susun harimu di bawah, kirim drafnya, penawarannya kami hitung khusus untuk susunan itu.</p>
  </div>
</section>

<!-- ======== 23.30 COMPOSER ======== -->
<section class="ch composer" id="susun" data-time="23.30" data-label="Susun harimu">
  <div class="ch-in">
    <span class="tstamp rv"><?= t('23.30 · MEJA KERJA KAMI, SEKARANG MEJAMU') ?></span>
    <h2 class="rv"><?= t('Susun harimu. Kami baca <em>persis seperti itu.</em>') ?></h2>
    <p class="body rv"><?= t('Bukan daftar belanja — garis waktu. Nyalakan momenmu, call sheet menyusun rundown-nya sendiri.') ?></p>

    <div class="comp-grid rv">
      <div class="panel">
        <span class="plabel">Garis waktu</span>
        <div class="stripwrap">
          <div class="strip-shell">
            <p class="strip-name">H−1 · sehari sebelumnya</p>
            <div class="strip" id="stripH1"><span class="empty" id="emptyH1">— belum ada momen H−1 —</span></div>
            <p class="strip-name" style="margin-top:34px">Hari-H</p>
            <div class="strip" id="stripHD"><span class="empty" id="emptyHD">— pilih momen di bawah —</span></div>
          </div>
        </div>

        <div class="chips" id="chipRow"></div>

        <div class="lane">
          <span class="plabel">Tamu &amp; kru</span>
          <div class="guest-row">
            <span style="font-size:13px;color:var(--ivory-60)">Perkiraan undangan</span>
            <span class="gv"><span id="gNum">300</span> <small>tamu · <span id="gCrew">3</span> kru lapangan</small></span>
          </div>
          <input type="range" id="gRange" min="<?= $gMin ?>" max="<?= $gMax ?>" step="<?= $gStep ?>" value="<?= $gDef ?>" aria-label="Perkiraan jumlah tamu">
        </div>

        <div class="lane">
          <span class="plabel">Layanan sepanjang hari</span>
          <div class="chips" id="svcRow"></div>
        </div>
      </div>

      <aside class="panel callsheet" aria-live="polite">
        <div class="cs-head">
          <span class="plabel">Call sheet · draf</span>
          <span class="dotlive"></span>
        </div>
        <ul class="cs-items" id="csItems"></ul>
        <div class="cs-sum">
          <div><span class="lbl">Rentang hari</span><span class="val" id="csSpan">—</span></div>
          <div><span class="lbl">Durasi acara</span><span class="val" id="csDur">—</span></div>
          <div><span class="lbl">Kru lapangan</span><span class="val" id="csCrew">—</span></div>
        </div>
        <div class="cs-date">
          <label class="plabel" for="wDate">Tanggal yang diincar</label>
          <input type="date" id="wDate">
          <p class="dayout" id="dayOut">Belum dipilih — boleh dikosongkan dulu.</p>
        </div>
        <div class="cs-actions">
          <a class="btn solid" id="waBtn" href="#"><?= t('Lanjut isi data →') ?></a>
        </div>
        <p class="cs-note">Penawaran disusun per pasangan, mengikuti susunan hari di atas.<br>Kami balas dengan rincian biaya, ketersediaan tanggal, dan rundown resmi dalam 2×24 jam.</p>
      </aside>
    </div>
  </div>
</section>

<!-- ======== 23.40 JENIS VENDOR ======== -->
<?php
// Diambil dari tabel, bukan didaftar di kode. Owner bisa menerbitkan atau
// menyembunyikan kategori dari panel tanpa menyentuh berkas ini.
$katPublik = [];
try {
    $katPublik = all("SELECT nama, slug, ikon FROM vendor_categories
                      WHERE is_public = 1 ORDER BY urutan, nama LIMIT 16");
} catch (Throwable $e) { $katPublik = []; }
?>
<?php if ($katPublik): ?>
<section class="svc" id="jenis-vendor">
  <div class="svc-h rv">
    <span class="tstamp"><?= t('23.40 · YANG PERLU DIPESAN') ?></span>
    <h2><?= t('Jenis vendor, <em>satu per satu.</em>') ?></h2>
    <p><?= t('Tiap jenis punya jebakannya sendiri. Kami tulis yang perlu ditanyakan sebelum memesan, beserta kisaran biayanya di Yogyakarta.') ?></p>
  </div>

  <div class="vgrid">
    <?php foreach ($katPublik as $k): ?>
      <a class="vgrid-i rv" href="<?= url('vendor/' . $k['slug']) ?>">
        <span class="vgrid-b"><?= ikonVendor($k['ikon'], 24) ?></span>
        <span><?= e($k['nama']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <p class="rv" style="text-align:center;margin-top:26px">
    <a class="btn" href="<?= url('vendor') ?>"><?= t('Lihat semuanya') ?></a>
  </p>
</section>
<?php endif; ?>

<!-- ======== 23.50 TANYA JAWAB ======== -->
<section class="ch" id="tanya" data-time="23.50" data-label="Sebelum menghubungi">
  <div class="ch-in">
    <span class="tstamp rv"><?= t('23.50 · SEBELUM MENGHUBUNGI') ?></span>
    <h2 class="rv"><?= t('Enam hal yang <em>paling sering ditanyakan.</em>') ?></h2>
    <div class="faq rv">
      <details open>
        <summary><?= t('Berapa biaya jasa Callalily?') ?></summary>
        <p><?= t('Angkanya kami kirim lewat pesan, bukan lewat halaman ini. Alasannya sederhana: biaya jasa mengikuti susunan hari — berapa acara yang dipegang, berapa tamunya, berapa jauh venue-nya, dan tanggal berapa. <b>Kirim tiga hal saja: tanggal, perkiraan jumlah tamu, dan rangkaian acaranya.</b> Itu sudah cukup buat kami menghitung, dan balasannya kami kirim dalam 2×24 jam.') ?></p>
      </details>
      <details>
        <summary><?= t('Bisa minta susunan di luar tiga pilihan tadi?') ?></summary>
        <p><?= t('Justru itu yang paling sering terjadi. Tiga susunan di atas hanya titik berangkat — tambah siraman, buang kirab, geser resepsi ke siang, semuanya boleh. Pakai penyusun di atas untuk membentuknya, lalu kirim drafnya apa adanya.') ?></p>
      </details>
      <details>
        <summary><?= t('Kami sudah punya vendor sendiri, masih perlu WO?') ?></summary>
        <p><?= t('Masih, dan pekerjaannya justru jadi lebih jelas: kami yang menyatukan jadwal mereka, memegang rundown bersama, dan berdiri di antara kalian dan semua pertanyaan teknis di hari-H. Vendor yang sudah kalian pilih tetap dipakai — kami tidak memaksa mengganti.') ?></p>
      </details>
      <details>
        <summary><?= t('Melayani acara di luar Yogyakarta?') ?></summary>
        <p><?= t('Ya. Jawa Tengah dan Jawa Timur rutin kami jalani, dan beberapa kali ke luar pulau. Untuk luar kota ada komponen perjalanan dan penginapan kru yang kami rinci terbuka di penawaran, tanpa biaya tersembunyi.') ?></p>
      </details>
      <details>
        <summary><?= t('Sebaiknya menghubungi berapa lama sebelum hari-H?') ?></summary>
        <p><?= t('Untuk rangkaian penuh, enam sampai dua belas bulan memberi ruang paling lega. Akad kecil masih bisa kami pegang di dua sampai tiga bulan — tergantung tanggal. Sabtu dan Minggu di musim ramai biasanya terisi paling awal.') ?></p>
      </details>
      <details>
        <summary><?= t('Konsultasi pertama bayar?') ?></summary>
        <p><?= t('Tidak. Pertemuan pertama gratis, di studio kami di Yogyakarta atau lewat panggilan video, dan tidak ada kewajiban melanjutkan. Bawa tanggal, perkiraan tamu, dan gambaran acaranya — sisanya kami yang susun.') ?></p>
      </details>
    </div>
  </div>
</section>

<!-- ======== 00.00 KONTAK ======== -->
<section class="ch kontak" data-time="00.00" data-label="Hari baru">
  <div class="ch-in">
    <span class="tstamp rv" style="justify-content:center">00.00 · HARI BARU</span>
    <h2 class="rv"><?= t('Hari ini selesai. <em>Harimu</em> baru mau dimulai.') ?></h2>
    <p class="body rv"><?= t('Konsultasi pertama gratis. Sebutkan <b>tanggal</b> dan <b>perkiraan tamu</b> — dua itu sudah cukup.') ?></p>
    <div class="kontak-cta rv">
      <a class="btn solid" id="waHello" href="#" target="_blank" rel="noopener"><?= t('Chat WhatsApp') ?></a>
      <a class="btn" href="<?= e('mailto:' . setting('contact_email', 'callalily.party@yahoo.com')) ?>"><?= e(setting('contact_email', 'callalily.party@yahoo.com')) ?></a>
    </div>
    <div class="meta rv">
      <span>JL. MAGELANG KM 9, DENGGUNG · SLEMAN</span><span>SEN–SAB · 09.00–17.00</span><span>IG @callalilyparty</span>
    </div>
  </div>
</section>

</main>

<footer>
  <span>CALLALILY PARTY WEDDING ORGANIZER · Yogyakarta</span>
  <span>© 2026 · penawaran disusun per pasangan</span>
</footer>

<!-- minibar mobile -->
<div class="mbar" id="mbar">
  <div><span class="ml">Draf harimu</span><span class="mt" id="mTotal">— momen</span></div>
  <a class="btn solid" id="waBtnM" href="#" style="padding:9px 16px;font-size:12.5px"><?= t('Lanjut isi data') ?></a>
</div>

<script>
/* Three.js ditarik BELAKANGAN dan hanya bila partikelnya benar-benar akan
   digambar. Sebelumnya berkas ~600 KB ini diminta secara sinkron di tengah
   dokumen, jadi parser HTML berhenti menunggunya sebelum isi halaman sempat
   tampil — padahal pada mode hemat gerak partikelnya tidak pernah dipakai. */
window.muatThree = () => new Promise((res, rej) => {
  if (window.THREE) return res();
  const s = document.createElement("script");
  s.src = "https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js";
  s.onload = res; s.onerror = rej;
  document.head.appendChild(s);
});
</script>
<script>
/* =====================================================
   KONFIGURASI — ganti sebelum production
===================================================== */
const FORM_URL  = <?= json_encode(setting('form_url', url('form'))) ?>;
const WA_NUMBER = "6281329787728";  // WhatsApp Callalily (format 62xxx)
const CREW_PER  = <?= tamuPerKru() ?>;              // 1 kru lapangan per ±100 tamu
const SHOW_PARTICLES = true;        // false = matikan semua partikel 3D (bintang, kelopak, kunang-kunang)

const reduced = matchMedia("(prefers-reduced-motion: reduce)").matches;
const fmtID = new Intl.NumberFormat("id-ID");
const jam = h => (Math.round(h*10)/10).toString().replace(".",",")+" jam";
const lerp = (a,b,p)=>a+(b-a)*p;

/* =====================================================
   1. WAKTU ⇄ SCROLL  (HUD + langit)
===================================================== */
const SKY = [ /* t, top RGB, bottom RGB, orb{x%,y%,size,opacity,core,glow} */
  [0.00,[5,7,15],   [16,26,56],   [50,108,130,0.0,"#FFE9C4","rgba(255,180,90,.5)"]],
  [0.10,[16,18,42], [74,46,74],   [22,96, 150,0.15,"#FFD9A8","rgba(255,150,80,.45)"]],
  [0.20,[38,40,84], [226,124,84], [30,78, 240,0.85,"#FFE3B8","rgba(255,150,70,.55)"]],
  [0.34,[52,80,122],[238,190,120],[42,42, 300,0.9,"#FFF3D6","rgba(255,200,120,.5)"]],
  [0.50,[62,93,116],[201,169,110],[55,26, 340,0.85,"#FFF6E0","rgba(255,215,140,.45)"]],
  [0.64,[74,60,99], [232,138,90], [72,52, 280,0.9,"#FFE0B0","rgba(255,150,80,.6)"]],
  [0.76,[36,30,68], [162,74,94],  [82,82, 200,0.6,"#FFC98F","rgba(240,120,90,.5)"]],
  [0.86,[11,15,42], [42,36,80],   [76,20, 90,0.5,"#EAE6FF","rgba(180,190,255,.35)"]],
  [1.00,[5,7,15],   [20,26,60],   [70,16, 80,0.55,"#EDE9FF","rgba(180,190,255,.4)"]]
];
const skyEl=document.querySelector(".sky"), orbEl=document.querySelector(".orb");
let T=0;              // target progres dari scroll (mentah)
let S=0;              // progres yang sudah dihaluskan — dipakai SEMUA layer
let renderStage=null; // diisi modul Three.js bila aktif

/* Tiap keyframe langit di-pra-render sebagai layer statis.
   Per frame kita hanya mengubah OPACITY dua layer yang berdekatan —
   dikomposit di GPU, tidak ada repaint gradient fullscreen lagi. */
const skyLayers=SKY.map(k=>{
  const d=document.createElement("div");
  d.className="layer";
  d.style.background=`linear-gradient(to bottom, rgb(${k[1].join(",")}), rgb(${k[2].join(",")}))`;
  skyEl.appendChild(d);
  return d;
});
let orbCoreCache="",orbGlowCache="";
function applySky(t){
  let i=SKY.length-2;
  for(let j=0;j<SKY.length-1;j++) if(t>=SKY[j][0]&&t<=SKY[j+1][0]){i=j;break;}
  const a=SKY[i],b=SKY[i+1],p=(t-a[0])/Math.max(1e-6,b[0]-a[0]);
  for(let j=0;j<skyLayers.length;j++)
    skyLayers[j].style.opacity = j===i?1 : j===i+1?p.toFixed(3) : 0;
  /* orb: posisi & ukuran via transform3d (compositor-only), warna hanya saat berganti */
  const A=a[3],B=b[3];
  const x=lerp(A[0],B[0],p)/100*innerWidth, y=lerp(A[1],B[1],p)/100*innerHeight;
  const s=lerp(A[2],B[2],p)/140*(innerWidth<600?0.7:1);
  orbEl.style.transform=`translate3d(${x.toFixed(1)}px,${y.toFixed(1)}px,0) scale(${s.toFixed(3)})`;
  orbEl.style.opacity=lerp(A[3],B[3],p).toFixed(3);
  const core=p<.5?A[4]:B[4], glow=p<.5?A[5]:B[5];
  if(core!==orbCoreCache||glow!==orbGlowCache){
    orbCoreCache=core;orbGlowCache=glow;
    orbEl.style.setProperty("--orbCore",core);
    orbEl.style.setProperty("--orbGlow",glow);
  }
}
applySky(0);

/* HUD waktu: interpolasi antar chapter */
const chapters=[...document.querySelectorAll(".ch")];
const hudT=document.getElementById("hudTime"), hudL=document.getElementById("hudLabel");
const toMin=s=>{const[m,h]=[parseInt(s.split(".")[1]),parseInt(s.split(".")[0])];return h*60+m;};
let anchors=[]; // {y, min, label, el}
function measure(){
  anchors=chapters.map(el=>({
    y: el.offsetTop + el.offsetHeight*0.35,
    min: el.dataset.time==="00.00"?1440:toMin(el.dataset.time),
    label: el.dataset.label.toUpperCase(), el
  }));
}
function hud(y){
  let a=anchors[0],b=anchors[anchors.length-1];
  for(let i=0;i<anchors.length-1;i++) if(y>=anchors[i].y&&y<=anchors[i+1].y){a=anchors[i];b=anchors[i+1];break;}
  if(y<anchors[0].y){a=b=anchors[0];} if(y>anchors[anchors.length-1].y){a=b=anchors[anchors.length-1];}
  const p=a===b?0:(y-a.y)/(b.y-a.y);
  const m=Math.round(lerp(a.min,b.min,p))%1440;
  hudT.textContent=String(Math.floor(m/60)).padStart(2,"0")+"."+String(m%60).padStart(2,"0");
  hudL.textContent=(p<.5?a:b).label;
}

/* rail kanan */
const rail=document.getElementById("rail");
chapters.forEach((c,i)=>{
  const a=document.createElement("a");
  a.href="#"+(c.id||("ch"+i)); if(!c.id)c.id="ch"+i;
  a.innerHTML=`<span>${c.dataset.time} · ${c.dataset.label}</span>`;
  rail.appendChild(a);
});
const railLinks=[...rail.children];
const railIO=new IntersectionObserver(es=>{
  es.forEach(e=>{ if(e.isIntersecting){
    const i=chapters.indexOf(e.target);
    railLinks.forEach((l,j)=>l.classList.toggle("on",j===i));
  }});
},{threshold:.45});
chapters.forEach(c=>railIO.observe(c));

/* ---------- MASTER LOOP: satu rAF untuk semuanya ----------
   Kunci kehalusan: scroll TIDAK dipakai mentah. Nilai S mengejar T
   dengan easing tiap frame, jadi loncatan diskrit mouse-wheel /
   trackpad melebur jadi gerakan sinematik. */
function masterLoop(){
  const max=Math.max(1,document.documentElement.scrollHeight-innerHeight);
  T=Math.min(1,Math.max(0,scrollY/max));
  S+=(T-S)*(reduced?1:0.085);
  if(Math.abs(T-S)<0.0005)S=T;
  applySky(S);
  hud(S*max+innerHeight*0.4);
  if(renderStage)renderStage(S);
  requestAnimationFrame(masterLoop);
}
addEventListener("load",()=>{
  measure();
  const max=Math.max(1,document.documentElement.scrollHeight-innerHeight);
  S=T=Math.min(1,Math.max(0,scrollY/max));
  requestAnimationFrame(masterLoop);
  setTimeout(measure,900);
});
addEventListener("resize",measure);

/* =====================================================
   2. LAPISAN HIDUP — Three.js (opsional, punya fallback)
   bintang · kelopak melati · kunang-kunang
===================================================== */
(function(){
  if(reduced || !SHOW_PARTICLES) return;

  // Tunggu peramban senggang, baru tarik Three.js lalu gambar partikelnya.
  const mulai = () => window.muatThree().then(jalan).catch(() => {});
  (window.requestIdleCallback || (f => setTimeout(f, 1200)))(mulai);

  function jalan(){
  if(!window.THREE) return;
  try{
    const cv=document.getElementById("stage");
    const isMobile=innerWidth<820||matchMedia("(pointer:coarse)").matches;
    const rd=new THREE.WebGLRenderer({canvas:cv,alpha:true,antialias:devicePixelRatio<2,powerPreference:"high-performance"});
    rd.setPixelRatio(Math.min(devicePixelRatio,isMobile?1.5:1.75));
    const sc=new THREE.Scene();
    const cam=new THREE.PerspectiveCamera(55,innerWidth/innerHeight,.1,200);
    cam.position.set(0,2.2,14);

    /* tekstur glow radial — PointsMaterial default merender titik KOTAK;
       dengan map ini bintang & kunang-kunang jadi bulatan cahaya lembut */
    const tc=document.createElement("canvas");tc.width=tc.height=64;
    const tg=tc.getContext("2d"),grd=tg.createRadialGradient(32,32,0,32,32,32);
    grd.addColorStop(0,"rgba(255,255,255,1)");
    grd.addColorStop(.35,"rgba(255,255,255,.55)");
    grd.addColorStop(1,"rgba(255,255,255,0)");
    tg.fillStyle=grd;tg.fillRect(0,0,64,64);
    const softTex=new THREE.CanvasTexture(tc);

    /* bintang */
    const sg=new THREE.BufferGeometry(), sN=isMobile?90:140, sp=new Float32Array(sN*3);
    for(let i=0;i<sN;i++){
      const th=Math.random()*Math.PI*2, ph=Math.random()*Math.PI*.5, r=60;
      sp[i*3]=r*Math.sin(ph)*Math.cos(th); sp[i*3+1]=r*Math.cos(ph)*0.9+6; sp[i*3+2]=-r*Math.sin(ph)*Math.sin(th)*.6-20;
    }
    sg.setAttribute("position",new THREE.BufferAttribute(sp,3));
    const stars=new THREE.Points(sg,new THREE.PointsMaterial({color:0xEDE9FF,size:.6,map:softTex,transparent:true,opacity:1,depthWrite:false}));
    sc.add(stars);

    /* kelopak melati — bentuk tetes */
    const shp=new THREE.Shape();
    shp.moveTo(0,0.5); shp.bezierCurveTo(0.32,0.28,0.3,-0.25,0,-0.5);
    shp.bezierCurveTo(-0.3,-0.25,-0.32,0.28,0,0.5);
    const pg=new THREE.ShapeGeometry(shp);
    const pm=new THREE.MeshBasicMaterial({color:0xF6EFDF,transparent:true,opacity:.35,side:THREE.DoubleSide,depthWrite:false});
    const PN=isMobile?10:16, petals=new THREE.InstancedMesh(pg,pm,PN);
    const pd=[]; const dummy=new THREE.Object3D();
    for(let i=0;i<PN;i++){
      pd.push({x:(Math.random()-.5)*46, y:Math.random()*26-4, z:-4-Math.random()*15,
        s:.09+Math.random()*.14, vy:.16+Math.random()*.3, ph:Math.random()*Math.PI*2, vr:(Math.random()-.5)*1.1});
    }
    sc.add(petals);

    /* kunang-kunang */
    const fN=isMobile?6:10, fg=new THREE.BufferGeometry(), fp=new Float32Array(fN*3), fb=[];
    for(let i=0;i<fN;i++){
      const b={x:(Math.random()-.5)*36,y:Math.random()*6-2.5,z:(Math.random()-.5)*16-2,ph:Math.random()*6.28,sp:.3+Math.random()*.7};
      fb.push(b); fp[i*3]=b.x; fp[i*3+1]=b.y; fp[i*3+2]=b.z;
    }
    fg.setAttribute("position",new THREE.BufferAttribute(fp,3));
    const fmat=new THREE.PointsMaterial({color:0xFFD97A,size:.55,map:softTex,transparent:true,opacity:0,blending:THREE.AdditiveBlending,depthWrite:false});
    const flies=new THREE.Points(fg,fmat); sc.add(flies);

    let mx=0,my=0;
    if(!isMobile)addEventListener("pointermove",e=>{mx=(e.clientX/innerWidth-.5);my=(e.clientY/innerHeight-.5);},{passive:true});
    function size(){cam.aspect=innerWidth/innerHeight;cam.updateProjectionMatrix();rd.setSize(innerWidth,innerHeight);}
    size(); addEventListener("resize",size);

    const clock=new THREE.Clock();
    /* tidak punya rAF sendiri — dirender oleh masterLoop dengan progres yang SAMA
       dengan langit, jadi partikel & atmosfer bergerak satu napas */
    renderStage=function(prog){
      const dt=Math.min(clock.getDelta(),.05), t=clock.elapsedTime;
      stars.material.opacity = (prog<.14 ? 1-(prog/.14)*.9 : (prog>.8 ? (prog-.8)/.2 : .06))*.75;
      const warm=Math.max(0,1-Math.abs(prog-.64)*5);
      pm.color.setRGB(lerp(.965,.98,warm), lerp(.937,.86,warm), lerp(.874,.68,warm));
      for(let i=0;i<PN;i++){
        const p=pd[i];
        p.y-=p.vy*dt; p.x+=Math.sin(t*.7+p.ph)*dt*.6;
        if(p.y<-9){p.y=17+Math.random()*6;p.x=(Math.random()-.5)*46;}
        dummy.position.set(p.x,p.y,p.z);
        dummy.rotation.set(t*p.vr+p.ph, t*.6+p.ph, Math.sin(t+p.ph)*.6);
        dummy.scale.setScalar(p.s);
        dummy.updateMatrix(); petals.setMatrixAt(i,dummy.matrix);
      }
      petals.instanceMatrix.needsUpdate=true;
      fmat.opacity=Math.max(0,(prog-.78)/.22)*.4;
      const pos=fg.attributes.position;
      for(let i=0;i<fN;i++){
        const b=fb[i];
        pos.setX(i,b.x+Math.sin(t*b.sp+b.ph)*2.2);
        pos.setY(i,b.y+Math.sin(t*b.sp*1.7+b.ph)*1.1);
      }
      pos.needsUpdate=true;
      fmat.size=.5+Math.sin(t*2.2)*.07;
      cam.position.x=lerp(cam.position.x,mx*1.6,.05);
      cam.position.y=lerp(cam.position.y,2.2-my*1.1,.05);
      cam.lookAt(0,2.5,0);
      rd.render(sc,cam);
    };
  }catch(e){/* tanpa WebGL: langit CSS tetap hidup */}
  }
})();

/* =====================================================
   3. COMPOSER — susun hari = susun paket
===================================================== */
const STRIPS={ h1:{el:null,min:14,max:23}, hd:{el:null,min:6,max:24} };
STRIPS.h1.el=document.getElementById("stripH1");
STRIPS.hd.el=document.getElementById("stripHD");

const MOMENTS=<?= ejs($jsMoments) ?>;
/* note = keterangan cakupan, bukan harga */
const SERVICES=<?= ejs($jsServices) ?>;
const PRESETS=<?= ejs((object) $jsPresets) ?>;

const state={m:new Set(MOMENTS.filter(x=>["akad","resmalam"].includes(x.id)).map(x=>x.id)), s:new Set(SERVICES.filter(x=>/^(mc|kater|catering|dekorasi|venue)$/i.test(x.label.toLowerCase().replace(/\s.*/,""))).slice(0,2).map(x=>x.id)), g:<?= $gDef ?>};
const hhmm=h=>{const H=Math.floor(h),M=Math.round((h-H)*60);return String(H).padStart(2,"0")+"."+String(M).padStart(2,"0");};

/* ticks pada strip */
function buildTicks(){
  for(const k in STRIPS){
    const st=STRIPS[k];
    for(let h=st.min;h<=st.max;h+=2){
      const x=(h-st.min)/(st.max-st.min)*100;
      const tk=document.createElement("i");
      tk.className="tick"; tk.style.left=x+"%";
      tk.innerHTML=`<span>${String(h%24).padStart(2,"0")}</span>`;
      st.el.appendChild(tk);
    }
  }
}
buildTicks();

/* chips momen & layanan */
const chipRow=document.getElementById("chipRow"), svcRow=document.getElementById("svcRow");
MOMENTS.forEach(m=>{
  const b=document.createElement("button");
  b.className="chip"; b.dataset.id=m.id;
  b.innerHTML=`${m.label} <small>${m.strip==="h1"?"H−1 ":""}${hhmm(m.s)}–${hhmm(m.e)}</small>`;
  b.onclick=()=>{state.m.has(m.id)?state.m.delete(m.id):state.m.add(m.id);render();};
  chipRow.appendChild(b);
});
SERVICES.forEach(s=>{
  const b=document.createElement("button");
  b.className="chip"; b.dataset.id=s.id;
  b.innerHTML=`${s.label} <small>${s.note}</small>`;
  b.onclick=()=>{state.s.has(s.id)?state.s.delete(s.id):state.s.add(s.id);render();};
  svcRow.appendChild(b);
});

const gRange=document.getElementById("gRange");
gRange.addEventListener("input",()=>{state.g=+gRange.value;render();});

/* render blok pada strip + call sheet */
const $=id=>document.getElementById(id);
const csItems=$("csItems"), csSpan=$("csSpan"), csDur=$("csDur"), csCrew=$("csCrew"),
      mTotal=$("mTotal"), waBtn=$("waBtn"), waBtnM=$("waBtnM"),
      wDate=$("wDate"), dayOut=$("dayOut");
let briefMsg="";

/* tanggal incaran — murni isi pesan WA, tidak menghitung apa pun */
const today=new Date(); today.setHours(0,0,0,0);
wDate.min=new Date(today.getTime()-today.getTimezoneOffset()*6e4).toISOString().slice(0,10);
wDate.addEventListener("change",render);
function dateLabel(){
  if(!wDate.value)return "";
  const d=new Date(wDate.value+"T00:00:00");
  return isNaN(d)?"":new Intl.DateTimeFormat("id-ID",
    {weekday:"long",day:"numeric",month:"long",year:"numeric"}).format(d);
}

/* yang dulu menghitung rupiah, sekarang menghitung jam */
let shown=0;
function count(to){
  if(reduced){shown=to;csDur.textContent=jam(to);return;}
  const from=shown,t0=performance.now();
  (function st(t){const p=Math.min(1,(t-t0)/420),e=1-Math.pow(1-p,3);
    csDur.textContent=jam(from+(to-from)*e);
    if(p<1)requestAnimationFrame(st);else{shown=to;csDur.textContent=jam(to);}})(t0);
}
function render(){
  /* strip blocks */
  for(const k in STRIPS) STRIPS[k].el.querySelectorAll(".blk").forEach(n=>n.remove());
  let hasH1=false,hasHD=false;
  MOMENTS.forEach(m=>{
    const on=state.m.has(m.id);
    chipRow.querySelector(`[data-id="${m.id}"]`).classList.toggle("on",on);
    if(!on)return;
    if(m.strip==="h1")hasH1=true;else hasHD=true;
    const st=STRIPS[m.strip], span=st.max-st.min;
    const blk=document.createElement("button");
    blk.className="blk";
    blk.style.left=((m.s-st.min)/span*100)+"%";
    blk.style.width=((m.e-m.s)/span*100)+"%";
    blk.innerHTML=`<span class="bl">${m.label}</span><span class="bt">${hhmm(m.s)}–${hhmm(m.e)}</span>`;
    blk.title="Klik untuk melepas";
    blk.onclick=()=>{state.m.delete(m.id);render();};
    st.el.appendChild(blk);
  });
  document.getElementById("emptyH1").style.display=hasH1?"none":"grid";
  document.getElementById("emptyHD").style.display=hasHD?"none":"grid";
  SERVICES.forEach(s=>svcRow.querySelector(`[data-id="${s.id}"]`).classList.toggle("on",state.s.has(s.id)));

  /* slider */
  gRange.value=state.g;
  gRange.style.setProperty("--fill",((state.g-50)/(1500-50)*100)+"%");
  document.getElementById("gNum").textContent=fmtID.format(state.g);
  const crew=Math.max(1,Math.ceil(state.g/CREW_PER));
  document.getElementById("gCrew").textContent=crew;

  /* call sheet = rundown, bukan nota */
  const li=(n,t)=>`<li><span class="cn">${n}</span><span class="cp">${t}</span></li>`;
  const picked=MOMENTS.filter(m=>state.m.has(m.id)).sort((a,b)=>(a.strip===b.strip? a.s-b.s : a.strip==="h1"?-1:1));
  let html=li("<b>Show direction & tim inti</b>","sepanjang hari");
  picked.forEach(m=>html+=li(`${m.strip==="h1"?"H−1 ":""}${hhmm(m.s)} <b>${m.label}</b>`, jam(m.e-m.s)));
  html+=li("Kru lapangan", crew+" orang");
  SERVICES.filter(s=>state.s.has(s.id)).forEach(s=>html+=li(s.label, s.note));
  csItems.innerHTML=html;

  /* ringkasan hari — angka yang berguna, bukan angka yang menakuti */
  const hd=picked.filter(m=>m.strip==="hd"), h1=picked.filter(m=>m.strip==="h1");
  let span="—";
  if(hd.length){
    span=hhmm(Math.min(...hd.map(m=>m.s)))+"–"+hhmm(Math.max(...hd.map(m=>m.e)));
    if(h1.length)span="H−1 "+hhmm(Math.min(...h1.map(m=>m.s)))+" → "+span;
  }else if(h1.length){
    span="H−1 "+hhmm(Math.min(...h1.map(m=>m.s)))+"–"+hhmm(Math.max(...h1.map(m=>m.e)));
  }
  csSpan.textContent=span;
  csCrew.textContent=crew+" orang";
  const durasi=picked.reduce((a,m)=>a+(m.e-m.s),0);
  count(durasi);
  mTotal.textContent=picked.length?picked.length+" momen · "+jam(durasi):"— momen";

  /* tanggal incaran */
  const dl=dateLabel();
  if(dl){
    const d=new Date(wDate.value+"T00:00:00"), wd=d.getDay(), mo=d.getMonth();
    const ramai=(wd===0||wd===6)||[5,6,7,10,11].includes(mo);
    dayOut.textContent=dl+(ramai?" · tanggal favorit, biasanya terisi lebih dulu":"");
    dayOut.classList.add("hi");
  }else{
    dayOut.textContent="Belum dipilih — boleh dikosongkan dulu.";
    dayOut.classList.remove("hi");
  }

  /* pesan WA = draf rundown, tanpa satu pun angka rupiah */
  const lines=picked.map(m=>`  ${m.strip==="h1"?"H-1":"H  "} ${hhmm(m.s)}–${hhmm(m.e)}  ${m.label}`);
  const svc=SERVICES.filter(s=>state.s.has(s.id)).map(s=>s.label).join(", ")||"—";
  briefMsg="Halo Callalily! Ini draf hari yang saya susun di situs kalian:\n\n"+
    "Tanggal : "+(dl||"belum ditentukan")+"\n"+
    "Tamu    : ±"+fmtID.format(state.g)+" (±"+crew+" kru lapangan)\n"+
    "Layanan : "+svc+"\n\n"+
    "Rundown:\n"+(lines.length?lines.join("\n"):"  (belum ada momen dipilih)")+"\n\n"+
    "Boleh minta info ketersediaan tanggal dan penawarannya?";
  // Brief dibawa ke formulir lewat parameter, bukan langsung ke WhatsApp.
  // Alasannya bukan teknis: pesan WhatsApp panjang sering terkirim setengah
  // jadi, dan datanya berakhir sebagai teks yang harus diketik ulang
  // seseorang. Lewat formulir, susunan yang sama masuk langsung ke panel
  // sebagai klien — sudah bisa ditindaklanjuti tanpa disalin.
  // Kategori yang dipilih ikut dibawa sebagai daftar id. Formulir
  // mencentangnya sendiri di sana — tetap bisa ditambah atau dikurangi,
  // karena yang dikirim adalah keadaan awal, bukan kunci.
  const katTerpilih = SERVICES
    .filter(x => state.s.has(x.id) && x.cat)
    .map(x => x.cat)
    .join(",");

  const tautForm = FORM_URL + (FORM_URL.includes("?") ? "&" : "?")
                 + "brief=" + encodeURIComponent(briefMsg)
                 + (katTerpilih ? "&vendor=" + encodeURIComponent(katTerpilih) : "");
  waBtn.href = waBtnM.href = tautForm;
}
render();

/* presets → durasi, minibar & tombol muat */
const presetDur=p=>p.m.reduce((a,id)=>{const m=MOMENTS.find(x=>x.id===id);return a+(m.e-m.s);},0);
$("durPrasaja").textContent=jam(presetDur(PRESETS.prasaja));
$("durSemanak").textContent=jam(presetDur(PRESETS.semanak));
$("durSidomukti").textContent=jam(presetDur(PRESETS.sidomukti));
document.querySelectorAll(".minibar-strip").forEach(el=>{
  const p=PRESETS[el.dataset.pre];
  p.m.forEach(id=>{
    const m=MOMENTS.find(x=>x.id===id);
    const st=STRIPS[m.strip],span=st.max-st.min;
    const d=document.createElement("i");
    d.className="mb"+(m.strip==="h1"?" h1":"");
    d.style.left=((m.s-st.min)/span*100)+"%";
    d.style.width=((m.e-m.s)/span*100)+"%";
    el.appendChild(d);
  });
});
document.querySelectorAll("[data-load]").forEach(b=>{
  b.onclick=()=>{
    const p=PRESETS[b.dataset.load];
    state.m=new Set(p.m); state.s=new Set(p.s); state.g=p.g;
    render();
    document.getElementById("susun").scrollIntoView({behavior:reduced?"auto":"smooth"});
  };
});

/* =====================================================
   4. UTILITAS COMPOSER
   (galeri kini punya halaman sendiri: galeri.html)
===================================================== */
/* salin brief — jalan keluar buat yang belum mau buka WhatsApp */

/* WA sapa */
document.getElementById("waHello").href="https://wa.me/"+WA_NUMBER+"?text="+
  encodeURIComponent("Halo Callalily! Saya ingin konsultasi rencana pernikahan.");

/* minibar mobile muncul saat composer terlihat */
new IntersectionObserver(es=>{
  es.forEach(e=>document.getElementById("mbar").classList.toggle("on",e.isIntersecting));
},{threshold:.06}).observe(document.getElementById("susun"));

/* jam HUD memudar saat footer terlihat supaya tidak menimpa teks */
const hudEl=document.querySelector(".hud");
new IntersectionObserver(es=>{
  es.forEach(e=>hudEl.classList.toggle("hide",e.isIntersecting));
},{threshold:0}).observe(document.querySelector("footer"));

/* reveal */
if(!reduced){
  const io=new IntersectionObserver(es=>{es.forEach(e=>{
    if(e.isIntersecting){e.target.classList.add("in");io.unobserve(e.target);}
  });},{threshold:.18});
  document.querySelectorAll(".rv").forEach(el=>io.observe(el));
}else document.querySelectorAll(".rv").forEach(el=>el.classList.add("in"));
</script>

<?php if ($ga = setting('ga_measurement_id')): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= e($ga) ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','<?= e($ga) ?>');</script>
<?php endif; ?>
<?= tombolWaMengambang() ?>
</body>
</html>