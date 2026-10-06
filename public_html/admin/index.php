<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/pipeline.php';
require_once __DIR__ . '/../inc/google.php';
require_once __DIR__ . '/../inc/zoom.php';
require_once __DIR__ . '/../inc/formulir.php';
$user = requireLogin();

/**
 * RINGKASAN — layar pertama setelah masuk.
 *
 * Disusun menurut peran. Admin early tidak perlu melihat tagihan termin,
 * admin office tidak perlu melihat prospek yang belum tentu jadi, dan owner
 * melihat semuanya. Dulu ketiganya melihat layar yang sama — enam angka yang
 * patah jadi 5 + 1 baris, dan hitungan "Prospek aktif" masih memakai tahap
 * 'meeting' dan 'negosiasi' yang sudah dihapus sejak v8, sehingga klien di
 * tahap Price list dan Spesifikasi tidak pernah terhitung.
 */
$peran  = $user['role'] ?? '';
$early  = $peran === 'admin_early';
$office = in_array($peran, ['admin_office', 'editor'], true);
$owner  = !$early && !$office;

// Pra-deal = bagian admin early: prospek → price list → menunggu DP.
// DP 30% masuk = deal, dan klien pindah ke admin office.
$PRA_DEAL   = ['baru', 'pricelist', 'dp'];
$PASCA_DEAL = ['deal', 'persiapan', 'harih'];
$tahapKu    = $early ? $PRA_DEAL : ($office ? $PASCA_DEAL : PIPE_ACTIVE);
$inKu       = "'" . implode("','", $tahapKu) . "'";
$inPra      = "'" . implode("','", $PRA_DEAL) . "'";
$inPasca    = "'" . implode("','", $PASCA_DEAL) . "'";

$v = fn(string $sql) => (float) (one($sql)['v'] ?? 0);

// ---- Angka utama: empat, satu baris, berbeda per peran ----
$perlu = (int) $v("SELECT COUNT(*) v FROM clients WHERE stage IN ($inKu)
                    AND next_action_at IS NOT NULL AND next_action_at <= CURDATE()");
$stat = [['n' => $perlu, 'd' => 'Perlu ditindak hari ini', 'aksen' => true, 'href' => 'klien.php']];
if (!$office) {
    $stat[] = ['n' => (int) $v("SELECT COUNT(*) v FROM clients WHERE stage IN ($inPra)"), 'd' => 'Prospek aktif', 'href' => 'klien.php'];
}
if ($early) {
    $stat[] = ['n' => (int) $v("SELECT COUNT(*) v FROM clients WHERE stage = 'dp'"), 'd' => 'Menunggu DP', 'href' => 'klien.php?tahap=dp'];
    $stat[] = ['n' => (int) $v("SELECT COUNT(*) v FROM clients WHERE contract_signed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"), 'd' => 'Deal bulan ini (DP masuk)', 'href' => 'klien.php?tahap=deal'];
}
if ($office) {
    $stat[] = ['n' => (int) $v("SELECT COUNT(*) v FROM clients WHERE stage IN ($inPasca)"), 'd' => 'Acara berjalan', 'href' => 'klien.php'];
    $stat[] = ['n' => (int) $v("SELECT COUNT(*) v FROM clients WHERE stage IN ($inPasca) AND data_lengkap_at IS NULL"), 'd' => 'Data lengkap kosong', 'href' => 'klien.php?pic=dl_kosong'];
}
if (!$early) {
    if ($owner) $stat[] = ['n' => rupiah($v("SELECT COALESCE(SUM(deal_value),0) v FROM clients WHERE stage IN ($inPasca)"), true) ?: '—', 'd' => 'Nilai terkunci', 'kecil' => true, 'href' => 'klien.php'];
    $telat = $v("SELECT COALESCE(SUM(p.amount - p.terbayar),0) v FROM payments p JOIN clients c ON c.id = p.client_id
                 WHERE p.paid_at IS NULL AND p.due_date < CURDATE() AND c.stage <> 'batal'");
    $stat[] = ['n' => $telat > 0 ? rupiah($telat, true) : '—', 'd' => 'Tagihan lewat tempo', 'kecil' => true, 'merah' => $telat > 0];
}

// ---- Corong tahap ----
$corong = [];
foreach (all("SELECT stage, COUNT(*) n FROM clients WHERE stage IN ($inKu) GROUP BY stage") as $r) $corong[$r['stage']] = (int) $r['n'];

// ---- Daftar "hari ini" ----
$tindakan = all("SELECT id, name, partner_name, next_action, next_action_at, stage FROM clients
                 WHERE stage IN ($inKu) AND next_action_at IS NOT NULL AND next_action_at <= CURDATE()
                 ORDER BY next_action_at ASC LIMIT 8");
// DP yang sedang ditunggu: begitu masuk, klien diserahkan ke admin office.
$dpTunggu = $office ? [] : all("SELECT c.id, c.name, c.partner_name, p.label, (p.amount - p.terbayar) amount, p.due_date
                 FROM clients c JOIN payments p ON p.id = (
                     SELECT p2.id FROM payments p2 WHERE p2.client_id = c.id
                     ORDER BY (p2.kode = 'dealing') DESC, p2.wajib DESC, p2.sort_order, p2.id LIMIT 1)
                 WHERE c.stage = 'dp' AND p.paid_at IS NULL
                 ORDER BY p.due_date ASC LIMIT 6");
// Kiriman formulir yang gagal jadi klien — calon klien yang nyaris hilang.
$formCek = $office ? [] : formPerluCek(30);
$tanpaAksi = $office ? [] : all("SELECT id, name, partner_name, stage FROM clients
                  WHERE (next_action_at IS NULL OR next_action = '') AND stage IN ($inPra) LIMIT 6");
$catatHasil = $office ? [] : all("SELECT id, client_name, start_at FROM meetings
                   WHERE status='scheduled' AND start_at < NOW() AND outcome = '' ORDER BY start_at DESC LIMIT 5");
$tugasTelat = $early ? [] : all("SELECT t.id, t.title, t.due_date, c.id cid, c.name FROM client_tasks t
                   JOIN clients c ON c.id = t.client_id
                   WHERE t.done_at IS NULL AND t.due_date <= CURDATE() AND c.stage NOT IN ('selesai','batal')
                   ORDER BY t.due_date ASC LIMIT 8");
$tagihan = $early ? [] : all("SELECT p.id, p.label, (p.amount - p.terbayar) amount, p.due_date, c.id cid, c.name FROM payments p
                JOIN clients c ON c.id = p.client_id
                WHERE p.paid_at IS NULL AND p.due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) AND c.stage NOT IN ('batal','dp')
                ORDER BY p.due_date ASC LIMIT 6");
$nextMeet = all("SELECT * FROM meetings WHERE status='scheduled' AND start_at >= NOW() ORDER BY start_at ASC LIMIT 4");
$hariH    = $early ? [] : all("SELECT id, name, partner_name, wedding_date, venue FROM clients
                 WHERE stage IN ($inPasca) AND wedding_date >= CURDATE()
                 ORDER BY wedding_date ASC LIMIT 5");
$syncBad  = $owner ? all("SELECT id, client_name, sync_error FROM meetings WHERE sync_error IS NOT NULL AND status='scheduled' LIMIT 3") : [];
$lowSeo   = $owner ? all("SELECT id, title, seo_score FROM posts WHERE status='published' AND seo_score < 70 ORDER BY seo_score ASC LIMIT 3") : [];

$adaHariIni = $tindakan || $tanpaAksi || $tugasTelat || $tagihan || $catatHasil || $dpTunggu || $formCek;

adminHead('Ringkasan', '');
$aksiAtas = ($office ? '' : '<a class="btn solid" href="klien.php?new=1">+ Klien baru</a> ')
          . '<a class="btn ghost" href="jadwal.php?new=1">+ Jadwalkan</a>';
pageHead('Selamat datang, ' . explode(' ', $user['name'])[0],
         hariID('now') . ', ' . tanggalID('now') . ' · ' . roleLabel($peran)
         . ($early ? ' — prospek sampai DP masuk.' : ($office ? ' — klien setelah DP masuk.' : '.')),
         $aksiAtas);
?>

<div class="stat-baris">
  <?php foreach (array_slice($stat, 0, 4) as $s): ?>
    <a class="stat<?= !empty($s['aksen']) ? ' accent' : '' ?>" href="<?= e($s['href'] ?? '#') ?>">
      <span class="n" style="<?= !empty($s['kecil']) ? 'font-size:24px;' : '' ?><?= !empty($s['merah']) ? 'color:var(--rose)' : '' ?>"><?= e((string) $s['n']) ?></span>
      <span class="d"><?= e($s['d']) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="card corong-kartu">
  <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
    <h2 style="margin:0">Jalur klien</h2>
    <a class="mono" style="font-size:11px;color:var(--ember)" href="klien.php">Buka papan →</a>
  </div>
  <div class="corong">
    <?php foreach ($tahapKu as $st): $n = $corong[$st] ?? 0; ?>
      <a href="klien.php?tahap=<?= $st ?>" class="<?= $n ? 'isi' : '' ?>">
        <b><?= $n ?></b><span><?= e(stageLabel($st)) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid dash">
  <div class="card hari-ini">
    <h2>Hari ini</h2>
    <?php if (!$adaHariIni): ?>
      <div class="empty" style="padding:30px 12px 18px">
        <p>Tidak ada yang mendesak</p>
        <span>Semua tindak lanjut<?= $early ? '' : ', langkah persiapan, dan tagihan' ?> sedang pada jalurnya.</span>
      </div>
    <?php endif; ?>

    <?php if ($formCek): ?>
      <span class="lab" style="color:var(--rose)">Kiriman formulir belum jadi klien</span>
      <ul class="daftar">
        <?php foreach (array_slice($formCek, 0, 5) as $f): ?>
          <li>
            <a href="formulir.php"><b><?= e($f['nama'] ?: $f['wa']) ?></b><span><?= e($f['pesan'] ?: 'Perlu dicek') ?> · <?= e($f['wa']) ?></span></a>
            <span class="kapan telat"><?= e(labelHari(substr($f['created_at'], 0, 10))) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($tindakan): ?>
      <span class="lab">Tindak lanjut jatuh tempo</span>
      <ul class="daftar">
        <?php foreach ($tindakan as $t): $lewat = $t['next_action_at'] < date('Y-m-d'); ?>
          <li>
            <a href="klien.php?id=<?= $t['id'] ?>">
              <b><?= e($t['name'] . ($t['partner_name'] ? ' & ' . $t['partner_name'] : '')) ?></b>
              <span><?= e($t['next_action'] ?: stageNext($t['stage'])) ?></span>
            </a>
            <span class="pill draft"><?= e(stageLabel($t['stage'])) ?></span>
            <span class="kapan<?= $lewat ? ' telat' : '' ?>"><?= e(labelHari($t['next_action_at'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($dpTunggu): ?>
      <span class="lab">Menunggu DP — begitu masuk, serahkan ke admin office</span>
      <ul class="daftar">
        <?php foreach ($dpTunggu as $p): $lewat = $p['due_date'] && $p['due_date'] < date('Y-m-d'); ?>
          <li>
            <a href="klien.php?id=<?= (int) $p['id'] ?>"><b><?= e($p['name'] . ($p['partner_name'] ? ' & ' . $p['partner_name'] : '')) ?></b>
              <span><?= e($p['label']) ?> · <?= rupiah((float) $p['amount']) ?></span></a>
            <span class="kapan<?= $lewat ? ' telat' : '' ?>"><?= $p['due_date'] ? e(labelHari($p['due_date'])) : '—' ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($catatHasil): ?>
      <span class="lab">Pertemuan lewat, hasil belum dicatat</span>
      <ul class="daftar">
        <?php foreach ($catatHasil as $m): ?>
          <li>
            <a href="jadwal.php?edit=<?= $m['id'] ?>"><b><?= e($m['client_name']) ?></b><span>Catat hasilnya — menentukan langkah klien berikutnya</span></a>
            <span class="kapan telat"><?= tanggalID(substr($m['start_at'], 0, 10)) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($tugasTelat): ?>
      <span class="lab">Langkah persiapan lewat tempo</span>
      <ul class="daftar">
        <?php foreach ($tugasTelat as $t): ?>
          <li>
            <a href="klien.php?id=<?= $t['cid'] ?>#checklist"><b><?= e($t['title']) ?></b><span><?= e($t['name']) ?></span></a>
            <span class="kapan telat"><?= e(labelHari($t['due_date'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($tagihan): ?>
      <span class="lab">Tagihan jatuh tempo (3 hari ke depan)</span>
      <ul class="daftar">
        <?php foreach ($tagihan as $p): $lewat = $p['due_date'] < date('Y-m-d'); ?>
          <li>
            <a href="klien.php?id=<?= $p['cid'] ?>#uang"><b><?= e($p['name']) ?></b><span><?= e($p['label']) ?> · <?= rupiah((float) $p['amount']) ?></span></a>
            <span class="kapan<?= $lewat ? ' telat' : '' ?>"><?= e(labelHari($p['due_date'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($tanpaAksi): ?>
      <span class="lab">Prospek tanpa tindakan berikutnya</span>
      <div style="display:flex;gap:7px;flex-wrap:wrap;margin:9px 0 4px">
        <?php foreach ($tanpaAksi as $t): ?>
          <a class="btn sm ghost" href="klien.php?id=<?= $t['id'] ?>"><?= e($t['name']) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="dash-samping">
    <div class="card">
      <h2>Pertemuan terdekat</h2>
      <?php if (!$nextMeet): ?>
        <p class="sub" style="margin:0">Belum ada pertemuan terjadwal. <a href="jadwal.php?new=1" style="color:var(--ember)">Jadwalkan →</a></p>
      <?php else: ?>
        <ul class="daftar">
          <?php foreach ($nextMeet as $m): ?>
            <li>
              <a href="jadwal.php?edit=<?= (int) $m['id'] ?>"><b><?= e($m['client_name']) ?></b>
                <span><?= ['meet'=>'Google Meet','zoom'=>'Zoom','onsite'=>'Tatap muka','phone'=>'Telepon'][$m['mode']] ?? '' ?></span></a>
              <span class="kapan"><?= date('d/m', strtotime($m['start_at'])) ?> · <?= date('H.i', strtotime($m['start_at'])) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <?php if (!$early): ?>
    <div class="card">
      <h2>Hari-H terdekat</h2>
      <?php if (!$hariH): ?>
        <p class="sub" style="margin:0">Klien yang sudah deal dan punya tanggal nikah muncul di sini.</p>
      <?php else: ?>
        <ul class="daftar">
          <?php foreach ($hariH as $h): $d = hariKe($h['wedding_date']); ?>
            <li>
              <a href="klien.php?id=<?= $h['id'] ?>"><b><?= e($h['name'] . ($h['partner_name'] ? ' & ' . $h['partner_name'] : '')) ?></b>
                <span><?= e($h['venue'] ?: '—') ?></span></a>
              <span class="kapan"><?= $d === 0 ? 'hari ini' : 'H-' . $d ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($owner && (!googleConnected() || !zoomConfigured() || $syncBad || $lowSeo)): ?>
<details class="card perhatian">
  <summary><h2 style="display:inline">Perlu perhatian</h2> <span class="lab" style="margin-left:8px">pengaturan</span></summary>
  <ul style="list-style:none;display:grid;gap:10px;font-size:14px;color:var(--ivory-60);margin-top:12px">
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
</details>
<?php endif; ?>

<?php adminFoot();
