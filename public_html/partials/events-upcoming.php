<?php
/**
 * PANEL 2 BERANDA — Event yang sedang disiapkan.
 * Bentuknya sengaja dibuat seperti baris call sheet, bukan kartu produk:
 * tanggal di kiri sebagai penanda waktu, hitung mundur sebagai kolom paling kanan.
 * Ini melanjutkan logika "gulir = waktu" yang jadi konsep halaman.
 */
$upcoming = all("SELECT * FROM events
                 WHERE is_published = 1 AND event_date >= NOW()
                 ORDER BY event_date ASC LIMIT 6");
if (!$upcoming) return;   // panel hilang sendiri kalau tidak ada isinya
?>
<section class="ch" id="agenda" data-time="05.00" data-label="Agenda terdekat">
  <div class="ch-in">
    <span class="tstamp rv">05.00 · KALENDER KAMI</span>
    <h2 class="rv">Hari yang sedang kami <em>hitung mundur.</em></h2>
    <p class="body rv">Setiap baris di bawah ini sudah punya rundown, sudah punya kru, dan sudah punya tanggal yang tidak bisa digeser. Begitu satu hari lewat, ia turun sendiri ke arsip di bawahnya.</p>

    <ol class="ev-sheet rv">
      <?php foreach ($upcoming as $ev):
        $days = (int) ceil((strtotime($ev['event_date']) - time()) / 86400);
        $href = url('event/' . $ev['slug']);
      ?>
      <li class="ev-row">
        <a href="<?= e($href) ?>">
          <span class="ev-date">
            <b><?= date('d', strtotime($ev['event_date'])) ?></b>
            <i><?= strtoupper(substr(tanggalID($ev['event_date']), strpos(tanggalID($ev['event_date']), ' ') + 1, 3)) ?></i>
            <u><?= date('Y', strtotime($ev['event_date'])) ?></u>
          </span>
          <span class="ev-body">
            <span class="ev-title"><?= e($ev['couple'] ?: $ev['title']) ?></span>
            <span class="ev-meta"><?= e($ev['category']) ?><?= $ev['venue'] ? ' · ' . e($ev['venue']) : '' ?> · <?= e($ev['city']) ?></span>
          </span>
          <span class="ev-count"><b><?= $days <= 0 ? 'Hari ini' : $days ?></b><?php if ($days > 0): ?><i>hari lagi</i><?php endif; ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ol>
    <p class="ev-note rv">Tanggal Sabtu dan Minggu di musim ramai biasanya terisi paling awal. <a href="#susun">Cek tanggal kalian →</a></p>
  </div>
</section>
