<?php
/**
 * PANEL 3 BERANDA — Event yang sudah terselenggara.
 * Ditampilkan sebagai deretan yang bisa digeser mendatar: setiap kartu satu hari
 * yang sudah selesai. Fungsinya bukti kerja, jadi angka totalnya ditonjolkan.
 */
$past = all("SELECT * FROM events
             WHERE is_published = 1 AND event_date < NOW()
             ORDER BY event_date DESC LIMIT 12");
$total = (int) (one("SELECT COUNT(*) c FROM events WHERE is_published = 1 AND event_date < NOW()")['c'] ?? 0);
$guests = (int) (one("SELECT COALESCE(SUM(guest_count),0) s FROM events WHERE is_published = 1 AND event_date < NOW()")['s'] ?? 0);
if (!$past) return;
?>
<section class="ch right" id="rekam" data-time="05.10" data-label="Sudah kami pegang">
  <div class="ch-in">
    <span class="tstamp rv">05.10 · REKAM JEJAK</span>
    <h2 class="rv">Hari-hari yang sudah <em>selesai kami jaga.</em></h2>
    <p class="body rv">Bukan portofolio foto — ini daftar tanggal yang benar-benar kami jalani sampai tamu terakhir pulang.</p>

    <div class="mstat rv">
      <div><span class="n"><?= $total ?></span><span class="d">acara terselenggara</span></div>
      <?php if ($guests > 0): ?>
        <div><span class="n"><?= number_format($guests, 0, ',', '.') ?></span><span class="d">tamu dilayani</span></div>
      <?php endif; ?>
      <div><span class="n"><?= date('Y', strtotime(end($past)['event_date'])) ?></span><span class="d">sejak</span></div>
    </div>

    <div class="ev-strip rv" role="list">
      <?php foreach ($past as $ev): ?>
        <a class="ev-card" role="listitem" href="<?= e(url('event/' . $ev['slug'])) ?>">
          <?php if ($ev['cover']): ?>
            <picture>
              <source srcset="<?= url('uploads/event/' . $ev['cover'] . '-thumb.webp') ?>" type="image/webp">
              <img src="<?= url('uploads/event/' . $ev['cover'] . '-thumb.jpg') ?>"
                   alt="<?= e($ev['cover_alt'] ?: $ev['title']) ?>" loading="lazy" decoding="async">
            </picture>
          <?php else: ?>
            <span class="ev-noimg" aria-hidden="true"></span>
          <?php endif; ?>
          <span class="ev-card-in">
            <span class="ev-card-date"><?= tanggalID($ev['event_date']) ?></span>
            <span class="ev-card-title"><?= e($ev['couple'] ?: $ev['title']) ?></span>
            <span class="ev-card-meta"><?= e($ev['venue'] ?: $ev['city']) ?></span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
    <p class="ev-note rv">Sisi pestanya kami kumpulkan terpisah. <a href="<?= url('galeri') ?>">Buka galeri pesta →</a></p>
  </div>
</section>
