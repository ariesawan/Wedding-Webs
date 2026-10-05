<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/pipeline.php';
require_once __DIR__ . '/../inc/google.php';
require_once __DIR__ . '/../inc/zoom.php';
require_once __DIR__ . '/../inc/pipeline.php';
$user = requireLogin();

$c = fn(string $sql) => (float) (one($sql)['v'] ?? 0);

$stats = [
    'perlu'    => (int) $c("SELECT COUNT(*) v FROM clients WHERE next_action_at <= CURDATE() AND stage NOT IN ('selesai','batal')"),
    'prospek'  => (int) $c("SELECT COUNT(*) v FROM clients WHERE stage IN ('baru','meeting','penawaran','negosiasi')"),
    'deal'     => (int) $c("SELECT COUNT(*) v FROM clients WHERE stage IN ('deal','persiapan','harih')"),
    'meeting'  => (int) $c("SELECT COUNT(*) v FROM meetings WHERE status='scheduled' AND start_at >= NOW()"),
    'terkunci' => $c("SELECT COALESCE(SUM(deal_value),0) v FROM clients WHERE stage IN ('deal','persiapan','harih')"),
    'telat'    => $c("SELECT COALESCE(SUM(amount),0) v FROM payments WHERE paid_at IS NULL AND due_date < CURDATE()"),
];

// Yang harus dikerjakan hari ini — disatukan dari tiga sumber.
$tindakan = all("SELECT id, name, partner_name, next_action, next_action_at, stage FROM clients
                 WHERE next_action_at <= CURDATE() AND stage NOT IN ('selesai','batal')
                 ORDER BY next_action_at ASC LIMIT 8");
$tanpaAksi = all("SELECT id, name, partner_name, stage FROM clients
                  WHERE (next_action_at IS NULL OR next_action = '')
                    AND stage IN ('baru','meeting','penawaran','negosiasi') LIMIT 6");
$tugasTelat = all("SELECT t.id, t.title, t.due_date, c.id cid, c.name FROM client_tasks t
                   JOIN clients c ON c.id = t.client_id
                   WHERE t.done_at IS NULL AND t.due_date <= CURDATE() AND c.stage NOT IN ('selesai','batal')
                   ORDER BY t.due_date ASC LIMIT 8");
$tagihan = all("SELECT p.id, p.label, p.amount, p.due_date, c.id cid, c.name FROM payments p
                JOIN clients c ON c.id = p.client_id
                WHERE p.paid_at IS NULL AND p.due_date <= CURDATE() ORDER BY p.due_date ASC LIMIT 6");
$nextMeet = all("SELECT * FROM meetings WHERE status='scheduled' AND start_at >= NOW() ORDER BY start_at ASC LIMIT 4");
$hariH    = all("SELECT id, name, partner_name, wedding_date, venue FROM clients
                 WHERE stage IN ('deal','persiapan','harih') AND wedding_date >= CURDATE()
                 ORDER BY wedding_date ASC LIMIT 5");
$catatHasil = all("SELECT id, client_name, start_at FROM meetings
                   WHERE status='scheduled' AND start_at < NOW() AND outcome = '' ORDER BY start_at DESC LIMIT 5");
$syncBad  = all("SELECT id, client_name, sync_error FROM meetings WHERE sync_error IS NOT NULL AND status='scheduled' LIMIT 3");
$lowSeo   = all("SELECT id, title, seo_score FROM posts WHERE status='published' AND seo_score < 70 ORDER BY seo_score ASC LIMIT 3");

adminHead('Ringkasan', '');
pageHead('Selamat datang, ' . explode(' ', $user['name'])[0],
         hariID('now') . ', ' . tanggalID('now') . '. Yang paling atas adalah yang paling perlu disentuh hari ini.');
?>

<div class="grid g4">
  <div class="stat accent"><span class="n"><?= $stats['perlu'] ?></span><span class="d">Perlu ditindak</span></div>
  <div class="stat"><span class="n"><?= $stats['prospek'] ?></span><span class="d">Prospek aktif</span></div>
  <div class="stat"><span class="n"><?= $stats['deal'] ?></span><span class="d">Sudah deal</span></div>
  <div class="stat"><span class="n"><?= $stats['meeting'] ?></span><span class="d">Pertemuan mendatang</span></div>
  <div class="stat"><span class="n" style="font-size:23px"><?= rupiah($stats['terkunci'], true) ?></span><span class="d">Nilai terkunci</span></div>
  <div class="stat"><span class="n" style="font-size:23px;<?= $stats['telat'] > 0 ? 'color:var(--rose)' : '' ?>"><?= $stats['telat'] ? rupiah($stats['telat'], true) : '—' ?></span><span class="d">Tagihan lewat tempo</span></div>
</div>

<?php if ($tindakan || $tanpaAksi || $tugasTelat || $tagihan || $catatHasil): ?>
<div class="card" style="margin-top:18px;border-color:rgba(233,168,92,.4)">
  <h2>Hari ini</h2>
  <p class="sub">Daftar ini kosong kalau semuanya sudah tertangani.</p>

  <?php if ($tindakan): ?>
    <span class="lab">Tindak lanjut jatuh tempo</span>
    <table class="tbl" style="margin:9px 0 20px">
      <?php foreach ($tindakan as $t): ?>
        <tr>
          <td data-l="Klien" style="padding:10px 0"><b><?= e($t['name'] . ($t['partner_name'] ? ' & ' . $t['partner_name'] : '')) ?></b><br>
            <span class="muted mono"><?= e(stageLabel($t['stage'])) ?></span></td>
          <td data-l="Tindakan"><?= e($t['next_action'] ?: '—') ?><br>
            <span class="muted mono" style="color:var(--rose)"><?= tanggalID($t['next_action_at']) ?></span></td>
          <td class="actions"><a class="btn sm ghost" href="klien.php?id=<?= $t['id'] ?>">Buka</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php if ($catatHasil): ?>
    <span class="lab">Pertemuan sudah lewat, hasilnya belum dicatat</span>
    <table class="tbl" style="margin:9px 0 20px">
      <?php foreach ($catatHasil as $m): ?>
        <tr>
          <td data-l="Klien" style="padding:10px 0"><b><?= e($m['client_name']) ?></b><br>
            <span class="muted mono"><?= tanggalID($m['start_at']) ?></span></td>
          <td class="actions"><a class="btn sm solid" href="jadwal.php?edit=<?= $m['id'] ?>">Catat hasil</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php if ($tanpaAksi): ?>
    <span class="lab">Prospek tanpa tindakan berikutnya</span>
    <p style="font-size:12.5px;color:var(--ivory-38);margin:4px 0 9px">Ini penyebab prospek menguap — tidak ada yang tahu siapa harus dihubungi kapan.</p>
    <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:20px">
      <?php foreach ($tanpaAksi as $t): ?>
        <a class="btn sm ghost" href="klien.php?id=<?= $t['id'] ?>"><?= e($t['name']) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($tugasTelat): ?>
    <span class="lab">Langkah persiapan lewat tempo</span>
    <table class="tbl" style="margin:9px 0 20px">
      <?php foreach ($tugasTelat as $t): ?>
        <tr>
          <td data-l="Langkah" style="padding:10px 0"><b><?= e($t['title']) ?></b><br>
            <span class="muted mono"><?= e($t['name']) ?> · <?= tanggalID($t['due_date']) ?></span></td>
          <td class="actions"><a class="btn sm ghost" href="klien.php?id=<?= $t['cid'] ?>">Buka</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php if ($tagihan): ?>
    <span class="lab">Tagihan jatuh tempo</span>
    <table class="tbl" style="margin:9px 0 0">
      <?php foreach ($tagihan as $p): ?>
        <tr>
          <td data-l="Tagihan" style="padding:10px 0"><b><?= e($p['name']) ?></b> — <?= e($p['label']) ?><br>
            <span class="muted mono"><?= tanggalID($p['due_date']) ?></span></td>
          <td data-l="Nominal" class="num" style="color:var(--rose)"><?= rupiah((float) $p['amount']) ?></td>
          <td class="actions"><a class="btn sm ghost" href="klien.php?id=<?= $p['cid'] ?>">Buka</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="card" style="margin-top:18px">
  <div class="empty" style="padding:36px 16px">
    <p>Tidak ada yang mendesak hari ini</p>
    <span>Semua tindak lanjut, langkah persiapan, dan tagihan sedang pada jalurnya.</span>
  </div>
</div>
<?php endif; ?>

<div class="grid g2" style="margin-top:18px;align-items:start">
  <div class="card">
    <h2>Pertemuan terdekat</h2>
    <?php if (!$nextMeet): ?>
      <div class="empty" style="padding:30px 16px">
        <p>Belum ada pertemuan terjadwal</p>
        <span>Buat jadwal, dan undangan kalender langsung terkirim ke email klien.</span>
        <a class="btn solid" href="jadwal.php?new=1">Jadwalkan</a>
      </div>
    <?php else: ?>
      <table class="tbl">
        <?php foreach ($nextMeet as $m): ?>
          <tr>
            <td data-l="Waktu" class="num" style="width:120px"><b style="color:var(--ivory)"><?= date('d M', strtotime($m['start_at'])) ?></b><br><?= date('H.i', strtotime($m['start_at'])) ?> WIB</td>
            <td data-l="Klien"><b><?= e($m['client_name']) ?></b><br><span class="muted mono"><?= ['meet'=>'Google Meet','zoom'=>'Zoom','onsite'=>'Tatap muka','phone'=>'Telepon'][$m['mode']] ?></span></td>
            <td class="actions"><a class="btn sm ghost" href="jadwal.php?edit=<?= (int) $m['id'] ?>">Buka</a></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Hari-H terdekat</h2>
    <p class="sub">Acara yang sudah terikat kontrak.</p>
    <?php if (!$hariH): ?>
      <div class="empty" style="padding:30px 16px">
        <p>Belum ada acara terjadwal</p>
        <span>Klien yang masuk tahap deal dan punya tanggal nikah akan muncul di sini.</span>
      </div>
    <?php else: ?>
      <table class="tbl">
        <?php foreach ($hariH as $h): $d = (int) ceil((strtotime($h['wedding_date']) - strtotime('today')) / 86400); ?>
          <tr>
            <td data-l="Tanggal" class="num" style="width:120px"><b style="color:var(--ivory)"><?= date('d M Y', strtotime($h['wedding_date'])) ?></b><br><?= $d === 0 ? 'hari ini' : "H-$d" ?></td>
            <td data-l="Pasangan"><b><?= e($h['name'] . ($h['partner_name'] ? ' & ' . $h['partner_name'] : '')) ?></b><br><span class="muted mono"><?= e($h['venue'] ?: '—') ?></span></td>
            <td class="actions"><a class="btn sm ghost" href="klien.php?id=<?= $h['id'] ?>">Buka</a></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php if (!googleConnected() || $syncBad || $lowSeo): ?>
<div class="card">
  <h2>Perlu perhatian</h2>
  <ul style="list-style:none;display:grid;gap:10px;font-size:14px;color:var(--ivory-60)">
    <?php if (!googleConnected()): ?>
      <li>◦ Google Calendar belum terhubung — undangan ke klien tidak terkirim. <a href="integrasi.php" style="color:var(--ember)">Hubungkan →</a></li>
    <?php endif; ?>
    <?php if (!zoomConfigured()): ?>
      <li>◦ Zoom belum dikonfigurasi. Google Meet tetap bisa dipakai tanpa ini. <a href="integrasi.php" style="color:var(--ember)">Atur →</a></li>
    <?php endif; ?>
    <?php foreach ($syncBad as $s): ?>
      <li>◦ Jadwal <b><?= e($s['client_name']) ?></b> gagal tersinkron: <?= e(mb_substr($s['sync_error'], 0, 90)) ?>
          <a href="jadwal.php?edit=<?= (int) $s['id'] ?>" style="color:var(--ember)">Periksa →</a></li>
    <?php endforeach; ?>
    <?php foreach ($lowSeo as $p): ?>
      <li>◦ Artikel "<?= e(mb_strimwidth($p['title'], 0, 46, '…')) ?>" skor SEO <?= (int) $p['seo_score'] ?>/100.
          <a href="blog.php?edit=<?= (int) $p['id'] ?>" style="color:var(--ember)">Perbaiki →</a></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php adminFoot();
