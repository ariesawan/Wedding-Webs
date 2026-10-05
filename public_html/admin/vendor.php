<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/vendor.php';
require_once __DIR__ . '/../inc/chat.php';
$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'save') {
            $id   = (int) ($_POST['id'] ?? 0);
            $nama = trim($_POST['name'] ?? '');
            if ($nama === '') throw new RuntimeException('Nama vendor wajib diisi.');
            $data = [
                'name'       => $nama,
                'category'   => isset(VENDOR_KATEGORI[$_POST['category'] ?? '']) ? $_POST['category'] : 'lainnya',
                'phone'      => trim($_POST['phone'] ?? ''),
                'email'      => trim($_POST['email'] ?? ''),
                'instagram'  => ltrim(trim($_POST['instagram'] ?? ''), '@'),
                'city'       => trim($_POST['city'] ?? ''),
                'pic_name'   => trim($_POST['pic_name'] ?? ''),
                'price_note' => trim($_POST['price_note'] ?? ''),
                'rating'     => ($_POST['rating'] ?? '') !== '' ? intval_between($_POST['rating'], 1, 5, 3) : null,
                'note'       => trim($_POST['note'] ?? ''),
                'is_active'  => isset($_POST['is_active']) ? 1 : 0,
            ];
            if ($id) {
                $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
                q("UPDATE vendors SET $set WHERE id = ?", [...array_values($data), $id]);
                flash('Vendor diperbarui.');
            } else {
                $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
                q("INSERT INTO vendors ($cols) VALUES (" . implode(',', array_fill(0, count($data), '?')) . ")",
                  array_values($data));
                $id = insertId();
                flash('Vendor ditambahkan.');
            }
            // Room chat dibuat/ditautkan supaya vendor langsung terlihat di daftar kontak.
            try { chatSinkronVendor($id); } catch (Throwable $e) { /* bukan alasan simpan batal */ }
        } elseif ($act === 'delete') {
            $id = (int) $_POST['id'];
            $dipakai = (int) (one("SELECT COUNT(*) c FROM client_vendors WHERE vendor_id = ?", [$id])['c'] ?? 0);
            if ($dipakai) throw new RuntimeException("Vendor ini masih dipakai di $dipakai pesta. Nonaktifkan saja agar riwayatnya tidak hilang.");
            q("DELETE FROM vendors WHERE id = ?", [$id]);
            flash('Vendor dihapus.');
        } elseif ($act === 'toggle') {
            q("UPDATE vendors SET is_active = 1 - is_active WHERE id = ?", [(int) $_POST['id']]);
            flash('Status vendor diperbarui.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('admin/vendor.php');
}

$fKat  = isset(VENDOR_KATEGORI[$_GET['kat'] ?? '']) ? $_GET['kat'] : '';
$fKota = trim($_GET['kota'] ?? '');
$fSt   = in_array($_GET['st'] ?? '', ['on','off'], true) ? $_GET['st'] : '';
$fCari = trim($_GET['q'] ?? '');

$w = []; $par = [];
if ($fKat)  { $w[] = "category = ?"; $par[] = $fKat; }
if ($fKota) { $w[] = "city = ?";     $par[] = $fKota; }
if ($fSt)   { $w[] = "is_active = ?"; $par[] = $fSt === 'on' ? 1 : 0; }
if ($fCari) { $w[] = "(name LIKE ? OR pic_name LIKE ? OR note LIKE ? OR price_note LIKE ?)";
              array_push($par, "%$fCari%", "%$fCari%", "%$fCari%", "%$fCari%"); }
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

$rows  = all("SELECT v.*, (SELECT COUNT(*) FROM client_vendors cv WHERE cv.vendor_id = v.id) dipakai
              FROM vendors v $where ORDER BY v.category, v.name", $par);
$total = (int) (one("SELECT COUNT(*) c FROM vendors")['c'] ?? 0);
$kota  = array_column(all("SELECT DISTINCT city FROM vendors WHERE city <> '' ORDER BY city"), 'city');
$edit  = isset($_GET['edit']) ? one("SELECT * FROM vendors WHERE id = ?", [(int) $_GET['edit']]) : null;
$isNew = isset($_GET['new']);

adminHead('Vendor', 'vendor');

if ($edit || $isNew):
  $v = fn(string $k, $d = '') => e($edit[$k] ?? $d);
  pageHead($edit ? 'Ubah vendor' : 'Vendor baru',
           'Daftar induk vendor. Satu vendor bisa dipakai di banyak pesta, tapi percakapannya tetap terpisah per pesta.',
           '<a class="btn ghost" href="vendor.php">← Kembali</a>');
?>
<form method="post">
  <?= csrfField() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <div class="grid g2" style="align-items:start">
    <div class="card">
      <h2>Identitas</h2>
      <div class="row c2">
        <div class="field"><label for="n">Nama vendor</label><input type="text" id="n" name="name" required autofocus value="<?= $v('name') ?>" placeholder="Royal Kinan Decoration"></div>
        <div class="field"><label for="k">Kategori</label>
          <select id="k" name="category">
            <?php foreach (VENDOR_KATEGORI as $kk => $kv): ?>
              <option value="<?= $kk ?>" <?= ($edit['category'] ?? '') === $kk ? 'selected' : '' ?>><?= e($kv) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="row c2">
        <div class="field"><label for="pic">Nama PIC</label><input type="text" id="pic" name="pic_name" value="<?= $v('pic_name') ?>" placeholder="Mas Bagas"></div>
        <div class="field"><label for="ct">Kota</label><input type="text" id="ct" name="city" value="<?= $v('city', 'Yogyakarta') ?>"></div>
      </div>
      <div class="row c2">
        <div class="field"><label for="ph">WhatsApp</label><input type="text" id="ph" name="phone" value="<?= $v('phone') ?>" placeholder="08123456789">
          <p class="hint">Nomor ini yang dipakai tombol chat. Boleh 08… atau 62…</p></div>
        <div class="field"><label for="ig">Instagram</label><input type="text" id="ig" name="instagram" value="<?= $v('instagram') ?>" placeholder="royal_kinan"></div>
      </div>
      <div class="field"><label for="em">Email</label><input type="email" id="em" name="email" value="<?= $v('email') ?>"></div>
    </div>

    <div class="card">
      <h2>Catatan kerja</h2>
      <div class="field"><label for="pn">Kisaran harga</label><input type="text" id="pn" name="price_note" value="<?= $v('price_note') ?>" placeholder="Mulai 18 jt untuk pelaminan indoor"></div>
      <div class="field"><label for="rt">Penilaian</label>
        <select id="rt" name="rating">
          <option value="">— belum dinilai —</option>
          <?php for ($i = 5; $i >= 1; $i--): ?>
            <option value="<?= $i ?>" <?= (int) ($edit['rating'] ?? 0) === $i ? 'selected' : '' ?>><?= str_repeat('★', $i) . str_repeat('·', 5 - $i) ?></option>
          <?php endfor; ?>
        </select>
        <p class="hint">Penilaian internal dari pengalaman kerja sama, bukan rating publik.</p></div>
      <div class="field"><label for="nt">Catatan</label>
        <textarea id="nt" name="note" rows="6" placeholder="Kekuatan, kelemahan, cara kerja, hal yang perlu diingatkan."><?= $v('note') ?></textarea></div>
      <label class="check"><input type="checkbox" name="is_active" <?= (!$edit || $edit['is_active']) ? 'checked' : '' ?>> Aktif — muncul saat memilih vendor untuk pesta</label>
      <div class="sticky-actions"><button class="btn solid" type="submit">Simpan vendor</button></div>
    </div>
  </div>
</form>

<?php else:
pageHead('Vendor', 'Daftar induk vendor. Pilih vendor untuk sebuah pesta lewat halaman klien.',
         '<a class="btn solid" href="?new=1">+ Vendor baru</a>');
?>
<form method="get" class="filterbar">
  <input type="text" name="q" value="<?= e($fCari) ?>" placeholder="Cari nama, PIC, catatan, atau harga">
  <select name="kat"><option value="">Semua kategori</option>
    <?php foreach (VENDOR_KATEGORI as $kk => $kv): ?><option value="<?= $kk ?>" <?= $fKat === $kk ? 'selected' : '' ?>><?= e($kv) ?></option><?php endforeach; ?></select>
  <select name="kota"><option value="">Semua kota</option>
    <?php foreach ($kota as $kk): ?><option value="<?= e($kk) ?>" <?= $fKota === $kk ? 'selected' : '' ?>><?= e($kk) ?></option><?php endforeach; ?></select>
  <select name="st"><option value="">Semua status</option>
    <option value="on"  <?= $fSt === 'on'  ? 'selected' : '' ?>>Aktif</option>
    <option value="off" <?= $fSt === 'off' ? 'selected' : '' ?>>Nonaktif</option></select>
  <button class="btn sm" type="submit">Saring</button>
  <?php if ($fCari || $fKat || $fKota || $fSt): ?><a class="btn sm ghost" href="vendor.php">Bersihkan</a><?php endif; ?>
  <span class="hit"><?= count($rows) ?> dari <?= $total ?></span>
</form>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty">
      <p><?= $total ? 'Tidak ada yang cocok' : 'Belum ada vendor' ?></p>
      <span><?= $total ? 'Coba ubah penyaringnya.' : 'Isi vendor yang sudah biasa dipakai — dekorasi, katering, dokumentasi, rias. Setelah itu tinggal dipilih tiap kali ada pesta baru.' ?></span>
      <a class="btn solid" href="?new=1">Tambah vendor</a>
    </div>
  <?php else: ?>
    <table class="tbl">
      <thead><tr><th>Vendor</th><th>Kategori</th><th>Kontak</th><th>Kisaran harga</th><th>Nilai</th><th>Dipakai</th><th></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= $r['is_active'] ? '' : ' style="opacity:.5"' ?>>
          <td><b><?= e($r['name']) ?></b><?php if ($r['pic_name']): ?><br><span class="muted mono"><?= e($r['pic_name']) ?></span><?php endif; ?>
            <?php if ($r['city']): ?><br><span class="muted" style="font-size:12px"><?= e($r['city']) ?></span><?php endif; ?></td>
          <td><span class="pill draft"><?= e(katVendor($r['category'])) ?></span></td>
          <td>
            <?php if ($r['phone']): ?><a class="mono" style="color:var(--ember)" target="_blank" rel="noopener" href="<?= e(waTautan($r['phone'])) ?>"><?= e($r['phone']) ?> ↗</a><?php endif; ?>
            <?php if ($r['instagram']): ?><br><a class="mono muted" target="_blank" rel="noopener" href="https://instagram.com/<?= e($r['instagram']) ?>">@<?= e($r['instagram']) ?></a><?php endif; ?>
          </td>
          <td><?= e($r['price_note'] ?: '—') ?></td>
          <td class="num"><?= $r['rating'] ? str_repeat('★', (int) $r['rating']) : '—' ?></td>
          <td class="num"><?= (int) $r['dipakai'] ?> pesta</td>
          <td class="actions">
            <a class="btn sm ghost" href="?edit=<?= (int) $r['id'] ?>">Ubah</a>
            <form method="post" style="display:inline"><?= csrfField() ?>
              <input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn sm ghost" type="submit"><?= $r['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
          </td>
          <td class="actions danger-col">
            <form method="post" style="display:inline" onsubmit="return confirm('Hapus vendor ini?')"><?= csrfField() ?>
              <input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn sm danger" type="submit">Hapus</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php endif; adminFoot();
