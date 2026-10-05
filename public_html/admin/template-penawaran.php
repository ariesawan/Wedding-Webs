<?php
/**
 * ============================================================
 * TEMPLATE PENAWARAN
 * ============================================================
 *
 * Sampai v19 penawaran hanya bisa lahir dari kebutuhan vendor yang dicentang
 * di halaman klien. Itu berguna setelah konsultasi, tapi bukan untuk kiriman
 * pertama: klien baru mengisi formulir, belum ada yang dicentang, dan
 * penawarannya lahir kosong. Admin lalu mengetik ulang susunan yang sama
 * untuk kesekian kalinya.
 *
 * Template menyimpan susunan yang sudah baku — dan penting: dipakai sebagai
 * TITIK AWAL, bukan kunci. Begitu disalin ke penawaran, salinannya berdiri
 * sendiri. Mengubah template tidak menyentuh penawaran yang sudah terbit,
 * karena penawaran itu sudah dipegang klien.
 */
require_once __DIR__ . '/_layout.php';
$user = requireLogin();

if (!in_array($user['role'] ?? '', ['owner', 'admin_early'], true)) {
    http_response_code(403);
    die('Template penawaran disusun admin early. <a href="index.php" style="color:#E9A85C">Kembali</a>');
}

$uang = fn($v) => (float) str_replace(['.', ','], ['', '.'], (string) $v);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';
    $tid = (int) ($_POST['id'] ?? 0);
    try {
        if ($act === 'tpl_baru') {
            $nama = trim($_POST['nama'] ?? '');
            if ($nama === '') throw new RuntimeException('Nama template wajib diisi.');
            $urut = (int) (one("SELECT COALESCE(MAX(urutan),0)+10 u FROM quote_templates")['u'] ?? 10);
            q("INSERT INTO quote_templates (nama, deskripsi, tipe, urutan, created_by) VALUES (?,?,?,?,?)",
              [mb_substr($nama, 0, 120), mb_substr(trim($_POST['deskripsi'] ?? ''), 0, 400),
               in_array($_POST['tipe'] ?? '', ['tematis','budgeting'], true) ? $_POST['tipe'] : 'semua',
               $urut, $user['id']]);
            $tid = (int) insertId();
            flash('Template dibuat. Tambahkan barisnya di bawah.');
            redirect('admin/template-penawaran.php?id=' . $tid);
        }

        if (!$tid || !one("SELECT id FROM quote_templates WHERE id = ?", [$tid]))
            throw new RuntimeException('Template tidak ditemukan.');

        if ($act === 'tpl_simpan') {
            q("UPDATE quote_templates SET nama = ?, deskripsi = ?, tipe = ?, catatan_bawaan = ?, is_active = ? WHERE id = ?",
              [mb_substr(trim($_POST['nama'] ?? ''), 0, 120),
               mb_substr(trim($_POST['deskripsi'] ?? ''), 0, 400),
               in_array($_POST['tipe'] ?? '', ['tematis','budgeting'], true) ? $_POST['tipe'] : 'semua',
               trim($_POST['catatan_bawaan'] ?? ''),
               isset($_POST['is_active']) ? 1 : 0, $tid]);

            foreach (($_POST['item'] ?? []) as $iid => $row) {
                $qty = max(0.01, (float) ($row['qty'] ?? 1));
                q("UPDATE quote_template_items
                      SET label = ?, detail = ?, qty = ?, satuan = ?, harga = ?, opsional = ?, sort_order = ?
                    WHERE id = ? AND template_id = ?",
                  [mb_substr(trim($row['label'] ?? ''), 0, 190),
                   mb_substr(trim($row['detail'] ?? ''), 0, 400),
                   $qty, mb_substr(trim($row['satuan'] ?? 'paket'), 0, 30),
                   $uang($row['harga'] ?? 0), isset($row['opsional']) ? 1 : 0,
                   (int) ($row['sort'] ?? 0), (int) $iid, $tid]);
            }
            flash('Template disimpan.');
        }

        elseif ($act === 'item_tambah') {
            $urut = (int) (one("SELECT COALESCE(MAX(sort_order),0)+10 s FROM quote_template_items WHERE template_id = ?", [$tid])['s'] ?? 10);
            q("INSERT INTO quote_template_items (template_id, label, sort_order) VALUES (?, 'Baris baru', ?)", [$tid, $urut]);
        }

        elseif ($act === 'item_hapus') {
            q("DELETE FROM quote_template_items WHERE id = ? AND template_id = ?", [(int) $_POST['item_id'], $tid]);
            flash('Baris dihapus.');
        }

        elseif ($act === 'duplikat') {
            $t = one("SELECT * FROM quote_templates WHERE id = ?", [$tid]);
            q("INSERT INTO quote_templates (nama, deskripsi, tipe, catatan_bawaan, urutan, created_by)
               VALUES (?,?,?,?,?,?)",
              [mb_substr($t['nama'] . ' (salinan)', 0, 120), $t['deskripsi'], $t['tipe'],
               $t['catatan_bawaan'], (int) $t['urutan'] + 5, $user['id']]);
            $baru = (int) insertId();
            q("INSERT INTO quote_template_items (template_id, category_id, label, detail, qty, satuan, harga, opsional, sort_order)
               SELECT ?, category_id, label, detail, qty, satuan, harga, opsional, sort_order
                 FROM quote_template_items WHERE template_id = ?", [$baru, $tid]);
            flash('Template diduplikat. Ubah yang perlu diubah.');
            redirect('admin/template-penawaran.php?id=' . $baru);
        }

        elseif ($act === 'hapus') {
            // Penawaran yang pernah dibuat dari template ini TIDAK ikut hilang —
            // isinya sudah disalin utuh saat dibuat, jadi berdiri sendiri.
            q("DELETE FROM quote_templates WHERE id = ?", [$tid]);
            flash('Template dihapus. Penawaran yang sudah terbit tidak terpengaruh.');
            redirect('admin/template-penawaran.php');
        }

        redirect('admin/template-penawaran.php?id=' . $tid);
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
        redirect('admin/template-penawaran.php' . ($tid ? '?id=' . $tid : ''));
    }
}

$tid    = (int) ($_GET['id'] ?? 0);
$daftar = all("SELECT t.*,
                      (SELECT COUNT(*) FROM quote_template_items i WHERE i.template_id = t.id) baris,
                      (SELECT COALESCE(SUM(IF(i.opsional,0,i.harga*i.qty)),0) FROM quote_template_items i WHERE i.template_id = t.id) nilai
               FROM quote_templates t ORDER BY t.urutan, t.id");

adminHead('Template penawaran', 'klien');
pageHead('Template penawaran',
         'Susunan baku yang dipakai sebagai titik awal penawaran. Disalin lalu disesuaikan — '
       . 'mengubah template tidak menyentuh penawaran yang sudah terbit.',
         '<a class="btn ghost" href="klien.php">← Klien</a>');

if (!$tid): ?>
  <div class="card">
    <h2>Template tersedia <span class="lab" style="margin-left:8px"><?= count($daftar) ?></span></h2>
    <?php if (!$daftar): ?>
      <p class="sub">Belum ada. Buat satu di bawah — contoh bawaan mestinya sudah terpasang lewat migration-v20.</p>
    <?php else: ?>
      <table class="tbl">
        <thead><tr><th>Nama</th><th>Untuk</th><th class="num">Baris</th><th class="num">Nilai wajib</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($daftar as $t): ?>
          <tr>
            <td data-l="Nama"><b><?= e($t['nama']) ?></b>
              <?php if ($t['deskripsi']): ?><span class="muted" style="display:block;font-size:12.5px"><?= e(mb_substr($t['deskripsi'], 0, 90)) ?><?= mb_strlen($t['deskripsi']) > 90 ? '…' : '' ?></span><?php endif; ?></td>
            <td data-l="Untuk"><?= $t['tipe'] === 'semua' ? 'Semua klien' : ucfirst($t['tipe']) ?></td>
            <td class="num" data-l="Baris"><?= (int) $t['baris'] ?></td>
            <td class="num" data-l="Nilai"><?= (float) $t['nilai'] > 0 ? rupiah((float) $t['nilai'], true) : 'Rp 0' ?></td>
            <td data-l="Status"><span class="pill <?= $t['is_active'] ? 'ok' : '' ?>"><?= $t['is_active'] ? 'aktif' : 'nonaktif' ?></span></td>
            <td class="actions"><a class="btn sm" href="?id=<?= (int) $t['id'] ?>">Buka</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Template baru</h2>
    <p class="sub">Kosong sejak awal. Kalau ada yang mirip, lebih cepat membuka template itu lalu menduplikatnya.</p>
    <form method="post">
      <?= csrfField() ?><input type="hidden" name="act" value="tpl_baru">
      <div class="row c2">
        <div class="field"><label for="n">Nama</label>
          <input type="text" id="n" name="nama" required placeholder="Paket Intimate — 150 Tamu"></div>
        <div class="field"><label for="t">Untuk tipe klien</label>
          <select id="t" name="tipe">
            <option value="semua">Semua klien</option>
            <option value="tematis">Hanya on tematis</option>
            <option value="budgeting">Hanya on budgeting</option>
          </select></div>
      </div>
      <div class="field"><label for="d">Kapan dipakai</label>
        <input type="text" id="d" name="deskripsi" placeholder="Dibaca sendiri saat memilih template — tulis yang membedakannya dari yang lain"></div>
      <button class="btn solid" type="submit">Buat template</button>
    </form>
  </div>
  <?php adminFoot(); exit;
endif;

/* ---------- satu template ---------- */
$t = one("SELECT * FROM quote_templates WHERE id = ?", [$tid]);
if (!$t) { http_response_code(404); die('Template tidak ditemukan.'); }
$items = all("SELECT ti.*, vc.nama kategori
              FROM quote_template_items ti
              LEFT JOIN vendor_categories vc ON vc.id = ti.category_id
              WHERE ti.template_id = ? ORDER BY ti.sort_order, ti.id", [$tid]);

$wajib = array_sum(array_map(fn($i) => $i['opsional'] ? 0 : (float) $i['harga'] * (float) $i['qty'], $items));
$opsi  = array_sum(array_map(fn($i) => $i['opsional'] ? (float) $i['harga'] * (float) $i['qty'] : 0, $items));
?>

<div class="grid g4">
  <div class="stat accent"><span class="n" style="font-size:22px"><?= $wajib > 0 ? rupiah($wajib, true) : 'Rp 0' ?></span><span class="d">Nilai wajib</span></div>
  <div class="stat"><span class="n" style="font-size:22px"><?= $opsi > 0 ? rupiah($opsi, true) : 'Rp 0' ?></span><span class="d">Opsional</span></div>
  <div class="stat"><span class="n" style="font-size:22px"><?= count($items) ?></span><span class="d">Baris</span></div>
  <div class="stat"><span class="n" style="font-size:22px"><?= $t['tipe'] === 'semua' ? 'Semua' : ucfirst($t['tipe']) ?></span><span class="d">Untuk tipe klien</span></div>
</div>

<form method="post">
  <?= csrfField() ?><input type="hidden" name="act" value="tpl_simpan">
  <input type="hidden" name="id" value="<?= $tid ?>">

  <div class="card">
    <h2>Keterangan</h2>
    <div class="row c2">
      <div class="field"><label>Nama</label>
        <input type="text" name="nama" value="<?= e($t['nama']) ?>" required></div>
      <div class="field"><label>Untuk tipe klien</label>
        <select name="tipe">
          <?php foreach (['semua' => 'Semua klien', 'tematis' => 'Hanya on tematis', 'budgeting' => 'Hanya on budgeting'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= $t['tipe'] === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="field"><label>Kapan dipakai</label>
      <input type="text" name="deskripsi" value="<?= e($t['deskripsi']) ?>"
             placeholder="Yang membedakannya dari template lain"></div>
    <div class="field"><label>Catatan bawaan penawaran</label>
      <textarea name="catatan_bawaan" rows="5" placeholder="Syarat, cakupan, yang tidak termasuk…"><?= e((string) $t['catatan_bawaan']) ?></textarea>
      <p class="hint" style="margin:6px 0 0">Ikut tersalin ke kolom Catatan pada penawaran, dan tercetak
        di PDF. Ini tempat menaruh hal yang selalu sama: masa berlaku, apa yang belum termasuk,
        ketentuan perubahan.</p></div>
    <label style="display:flex;gap:8px;align-items:center;font-size:13.5px">
      <input type="checkbox" name="is_active" value="1" <?= $t['is_active'] ? 'checked' : '' ?>>
      Aktif — muncul saat memilih template
    </label>
  </div>

  <div class="card">
    <h2>Baris</h2>
    <p class="sub">Harga di sini angka awal, bukan harga mati — begitu disalin ke penawaran,
      salinannya bisa diubah tanpa memengaruhi template.</p>

    <div style="overflow-x:auto">
      <table class="tbl" style="min-width:820px">
        <thead><tr>
          <th style="width:26%">Uraian</th><th style="width:28%">Detail</th>
          <th style="width:8%">Qty</th><th style="width:10%">Satuan</th>
          <th style="width:15%" class="num">Harga <span style="opacity:.5">(Rp)</span></th>
          <th style="width:7%">Opsi</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($items as $i => $it): ?>
          <tr>
            <td><input type="text" name="item[<?= (int) $it['id'] ?>][label]" value="<?= e($it['label']) ?>">
                <input type="hidden" name="item[<?= (int) $it['id'] ?>][sort]" value="<?= $i * 10 ?>">
                <?php if ($it['kategori']): ?><span class="muted" style="font-size:11.5px"><?= e($it['kategori']) ?></span><?php endif; ?></td>
            <td><input type="text" name="item[<?= (int) $it['id'] ?>][detail]" value="<?= e($it['detail']) ?>"></td>
            <td><input type="number" step="0.5" min="0.5" name="item[<?= (int) $it['id'] ?>][qty]"
                       value="<?= rtrim(rtrim(number_format((float) $it['qty'], 2, '.', ''), '0'), '.') ?>"></td>
            <td><input type="text" name="item[<?= (int) $it['id'] ?>][satuan]" value="<?= e($it['satuan']) ?>"></td>
            <td><input type="text" inputmode="numeric" class="uang" name="item[<?= (int) $it['id'] ?>][harga]"
                       value="<?= number_format((float) $it['harga'], 0, ',', '.') ?>"></td>
            <td style="text-align:center"><input type="checkbox" name="item[<?= (int) $it['id'] ?>][opsional]" value="1"
                       <?= $it['opsional'] ? 'checked' : '' ?> title="Opsional tidak masuk total"></td>
            <td class="actions"><button class="btn sm ghost danger" type="submit" form="h<?= (int) $it['id'] ?>">×</button></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?>
          <tr><td colspan="7" class="sub" style="padding:18px">Belum ada baris.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div style="display:flex;gap:9px;flex-wrap:wrap;margin-top:16px">
      <button class="btn solid" type="submit">Simpan template</button>
      <button class="btn ghost" type="submit" form="tambah">+ Tambah baris</button>
      <button class="btn ghost" type="submit" form="dup">Duplikat</button>
      <button class="btn ghost danger" type="submit" form="del"
              onclick="return confirm('Hapus template ini? Penawaran yang sudah terbit tidak terpengaruh.')">Hapus</button>
    </div>
  </div>
</form>

<?php foreach (['tambah' => 'item_tambah', 'dup' => 'duplikat', 'del' => 'hapus'] as $fid => $a): ?>
  <form method="post" id="<?= $fid ?>"><?= csrfField() ?>
    <input type="hidden" name="act" value="<?= $a ?>"><input type="hidden" name="id" value="<?= $tid ?>"></form>
<?php endforeach; ?>
<?php foreach ($items as $it): ?>
  <form method="post" id="h<?= (int) $it['id'] ?>"><?= csrfField() ?>
    <input type="hidden" name="act" value="item_hapus"><input type="hidden" name="id" value="<?= $tid ?>">
    <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>"></form>
<?php endforeach; ?>

<style>
input.uang { text-align: right; font-family: var(--mono); font-variant-numeric: tabular-nums; letter-spacing: .3px }
</style>
<script>
(function () {
  const fmt = v => {
    const a = String(v).replace(/\D/g, '').replace(/^0+(?=\d)/, '');
    return a ? a.replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '';
  };
  document.querySelectorAll('input.uang').forEach(el => {
    el.addEventListener('input', () => {
      const sebelum = el.value.slice(0, el.selectionStart).replace(/\D/g, '').length;
      el.value = fmt(el.value);
      let pos = 0, n = 0;
      while (pos < el.value.length && n < sebelum) { if (/\d/.test(el.value[pos])) n++; pos++; }
      el.setSelectionRange(pos, pos);
    });
    el.addEventListener('blur', () => { if (el.value.trim() === '') el.value = '0'; });
  });
})();
</script>

<?php adminFoot();
