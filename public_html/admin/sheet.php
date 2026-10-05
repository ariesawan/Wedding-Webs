<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/sheets.php';
$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'tambah') {
            $id = sheetIdDari($_POST['sheet_id'] ?? '');
            if (strlen($id) < 20) throw new RuntimeException('URL atau ID spreadsheet tidak dikenali.');
            $label = trim($_POST['label'] ?? '') ?: 'Spreadsheet';
            $max = (int) (one("SELECT COALESCE(MAX(sort_order),0) m FROM sheet_links")['m'] ?? 0);
            q("INSERT INTO sheet_links (label, sheet_id, tab, note, sort_order) VALUES (?,?,?,?,?)",
              [$label, $id, trim($_POST['tab'] ?? ''), trim($_POST['note'] ?? ''), $max + 10]);
            flash('Pintasan ditambahkan.');
        } elseif ($act === 'hapus') {
            q("DELETE FROM sheet_links WHERE id = ?", [(int) $_POST['id']]);
            flash('Pintasan dihapus.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('admin/sheet.php');
}

$links = all("SELECT * FROM sheet_links ORDER BY sort_order, id");
$sid   = sheetIdDari($_GET['s'] ?? '');
$tab   = trim($_GET['t'] ?? '');
$cari  = trim($_GET['q'] ?? '');
$hdr   = ($_GET['h'] ?? '') === '1';   // baris pertama dipakai sebagai judul kolom

$meta = null; $data = null; $galat = '';
if ($sid) {
    try {
        if (!sheetsEnabled()) {
            throw new RuntimeException('Fitur Spreadsheet belum aktif atau izin Google-nya belum diberikan. Buka Integrasi, aktifkan Spreadsheet, lalu tekan "Hubungkan ulang" pada Google Calendar.');
        }
        $meta = sheetTabs($sid);
        if (!$tab && $meta['tabs']) $tab = $meta['tabs'][0]['nama'];
        if ($tab) $data = sheetBaca($sid, $tab, 500, $hdr);
    } catch (Throwable $e) { $galat = $e->getMessage(); }
}

adminHead('Spreadsheet', 'sheet');
pageHead('Spreadsheet',
    'Membuka isi Google Sheets langsung di sini — daftar vendor, harga, kontak — tanpa pindah tab.',
    $sid ? '<a class="btn ghost" href="sheet.php">← Semua pintasan</a>'
           . '<a class="btn" target="_blank" rel="noopener" href="https://docs.google.com/spreadsheets/d/' . e($sid) . '/edit">Buka di Google ↗</a>' : '');

if (!sheetsEnabled()): ?>
  <div class="flash warn"><span>
    Pembaca spreadsheet perlu izin Google Sheets. Buka <a href="integrasi.php" style="text-decoration:underline">Integrasi</a>,
    centang <b>Aktifkan ekspor ke Google Spreadsheet</b>, simpan, lalu tekan <b>Hubungkan ulang</b> pada kartu Google Calendar
    dan setujui akses Spreadsheet. Pintasan di bawah tetap bisa disimpan sekarang.
  </span></div>
<?php endif;

if ($galat): ?>
  <div class="flash err"><span><?= e($galat) ?></span></div>
<?php endif;

/* ---------------- Tampilan satu spreadsheet ---------------- */
if ($sid && $meta): ?>
  <div class="card">
    <h2><?= e($meta['judul']) ?></h2>
    <p class="sub"><?= count($meta['tabs']) ?> tab. Isi dibaca langsung dari Google — perubahan di sana langsung terlihat di sini.</p>

    <div class="tabs" style="margin-bottom:16px">
      <?php foreach ($meta['tabs'] as $t): ?>
        <a href="?s=<?= e($sid) ?>&t=<?= urlencode($t['nama']) ?>" class="<?= $t['nama'] === $tab ? 'on' : '' ?>"><?= e($t['nama']) ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($data && $data['judul']):
      $baris = $data['baris'];
      if ($cari !== '') {
          $k = mb_strtolower($cari);
          $baris = array_values(array_filter($baris, fn($b) => str_contains(mb_strtolower(implode(' ', $b)), $k)));
      } ?>
      <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px">
        <input type="hidden" name="s" value="<?= e($sid) ?>">
        <input type="hidden" name="t" value="<?= e($tab) ?>">
        <div class="field" style="margin:0;flex:1;min-width:220px">
          <label for="q">Cari di tab ini</label>
          <input type="text" id="q" name="q" value="<?= e($cari) ?>" placeholder="nama vendor, kota, apa saja">
        </div>
        <input type="hidden" name="h" value="<?= $hdr ? '1' : '' ?>">
        <button class="btn" type="submit">Cari</button>
        <?php if ($cari): ?><a class="btn ghost" href="?s=<?= e($sid) ?>&t=<?= urlencode($tab) ?><?= $hdr ? '&h=1' : '' ?>">Bersihkan</a><?php endif; ?>
        <a class="btn ghost" href="?s=<?= e($sid) ?>&t=<?= urlencode($tab) ?><?= $hdr ? '' : '&h=1' ?><?= $cari ? '&q=' . urlencode($cari) : '' ?>">
          <?= $hdr ? 'Pakai huruf kolom' : 'Baris 1 = judul kolom' ?>
        </a>
        <span class="mono muted" style="margin-left:auto"><?= count($baris) ?> baris · <?= (int) ($data['lebar'] ?? 0) ?> kolom</span>
      </form>

      <?php if (!$baris): ?>
        <div class="empty"><p>Tidak ada baris yang cocok</p><span>Coba kata kunci lain, atau bersihkan pencarian.</span></div>
      <?php else: ?>
        <div class="sheetwrap">
        <table class="tbl">
          <thead><tr><th style="width:52px">#</th><?php foreach ($data['judul'] as $h): ?><th><?= e($h ?: '—') ?></th><?php endforeach; ?></tr></thead>
          <tbody>
          <?php foreach ($baris as $i => $b): ?>
            <tr>
              <td class="num muted" data-l="#"><?= $i + 1 + ($hdr ? 1 : 0) ?></td>
              <?php foreach ($b as $sel):
                $isi = trim((string) $sel);
                $tautan = filter_var($isi, FILTER_VALIDATE_URL);
                $mail   = filter_var($isi, FILTER_VALIDATE_EMAIL);
                $wa     = preg_match('/^(\+?62|0)8[0-9]{7,12}$/', preg_replace('/[\s-]/', '', $isi)); ?>
                <td>
                  <?php if ($tautan): ?><a href="<?= e($isi) ?>" target="_blank" rel="noopener" style="color:var(--ember)"><?= e(mb_strimwidth($isi, 0, 44, '…')) ?> ↗</a>
                  <?php elseif ($mail): ?><a href="mailto:<?= e($isi) ?>" style="color:var(--ember)"><?= e($isi) ?></a>
                  <?php elseif ($wa): ?><a href="https://wa.me/<?= e(preg_replace('/\D/', '', preg_replace('/^0/', '62', preg_replace('/[\s-]/', '', $isi)))) ?>" target="_blank" rel="noopener" style="color:var(--ember)"><?= e($isi) ?></a>
                  <?php else: ?><?= e($isi) ?><?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php if ($data['terpotong']): ?>
          <p class="hint" style="margin-top:12px">Ditampilkan 500 baris pertama. Untuk data yang lebih panjang, pakai pencarian di atas atau buka langsung di Google Sheets.</p>
        <?php endif; ?>
      <?php endif; ?>
    <?php else: ?>
      <div class="empty"><p>Tab ini kosong</p><span>Belum ada baris data di tab yang dipilih.</span></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="grid g2" style="align-items:start">
  <div class="card">
    <h2>Pintasan tersimpan</h2>
    <p class="sub">Spreadsheet yang sering dibuka. Simpan sekali, setelah itu tinggal klik.</p>
    <?php if (!$links): ?>
      <div class="empty" style="padding:34px 16px">
        <p>Belum ada pintasan</p>
        <span>Tempel URL Google Sheets di formulir sebelah — misalnya daftar vendor, daftar harga paket, atau kontak venue.</span>
      </div>
    <?php else: ?>
      <table class="tbl">
        <?php foreach ($links as $l): ?>
          <tr>
            <td>
              <b><?= e($l['label']) ?></b>
              <?php if ($l['note']): ?><br><span class="muted" style="font-size:12px"><?= e($l['note']) ?></span><?php endif; ?>
              <?php if ($l['tab']): ?><br><span class="muted mono" style="font-size:11px">tab: <?= e($l['tab']) ?></span><?php endif; ?>
            </td>
            <td class="actions">
              <a class="btn sm solid" href="?s=<?= e($l['sheet_id']) ?><?= $l['tab'] ? '&t=' . urlencode($l['tab']) : '' ?>">Buka</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Hapus pintasan ini? Spreadsheet-nya sendiri tidak ikut terhapus.')">
                <?= csrfField() ?><input type="hidden" name="act" value="hapus"><input type="hidden" name="id" value="<?= $l['id'] ?>">
                <button class="btn sm danger" type="submit">Hapus</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Tambah pintasan</h2>
    <form method="post">
      <?= csrfField() ?><input type="hidden" name="act" value="tambah">
      <div class="field">
        <label for="sl">URL Google Sheets</label>
        <input type="text" id="sl" name="sheet_id" required placeholder="https://docs.google.com/spreadsheets/d/....../edit">
        <p class="hint">Tempel URL lengkapnya — ID-nya diambil otomatis.</p>
      </div>
      <div class="row c2">
        <div class="field"><label for="lb">Nama pintasan</label><input type="text" id="lb" name="label" required placeholder="Daftar vendor"></div>
        <div class="field"><label for="tb">Tab bawaan</label><input type="text" id="tb" name="tab" placeholder="kosongkan = tab pertama"></div>
      </div>
      <div class="field"><label for="nt">Keterangan</label><input type="text" id="nt" name="note" placeholder="Kontak dan harga vendor dekor, katering, dokumentasi"></div>
      <button class="btn solid" type="submit">Simpan pintasan</button>
    </form>

    <hr class="hr">
    <p class="hint">
      Spreadsheet harus bisa diakses oleh akun Google yang terhubung di menu Integrasi.
      Kalau dibuat oleh akun lain, bagikan dulu ke akun tersebut minimal sebagai <b>Viewer</b>.
      Panel ini hanya membaca — penyuntingan tetap di Google Sheets, supaya tidak ada dua versi data yang berbeda.
    </p>
  </div>
</div>

<?php adminFoot();
