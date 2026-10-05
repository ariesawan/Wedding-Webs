<?php
/**
 * Penyunting "Susun harimu" — bagian situs publik yang selama ini terkunci
 * di JavaScript. Situs dan panel membaca tabel yang sama, jadi mengubah di
 * sini langsung terlihat di dua tempat sekaligus.
 */
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/pipeline.php';
$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'momen') {
            foreach ((array) ($_POST['m'] ?? []) as $id => $row) {
                $id = (int) $id;
                if (trim($row['label'] ?? '') === '') { q("DELETE FROM site_moments WHERE id = ?", [$id]); continue; }
                q("UPDATE site_moments SET label=?, day_offset=?, start_h=?, end_h=?, sort_order=?, is_active=? WHERE id=?", [
                    trim($row['label']), (int) ($row['day'] ?? 0),
                    jamKeDesimal($row['start'] ?? '08:00'), jamKeDesimal($row['end'] ?? '10:00'),
                    (int) ($row['ord'] ?? 0), isset($row['on']) ? 1 : 0, $id,
                ]);
            }
            $lbl = trim($_POST['new_label'] ?? '');
            if ($lbl !== '') {
                $key = slugify($lbl, 36);
                $n = 2; while (one("SELECT id FROM site_moments WHERE mkey = ?", [$key])) $key = slugify($lbl, 32) . '-' . $n++;
                $max = (int) (one("SELECT COALESCE(MAX(sort_order),0) m FROM site_moments")['m'] ?? 0);
                q("INSERT INTO site_moments (mkey,label,day_offset,start_h,end_h,sort_order) VALUES (?,?,?,?,?,?)", [
                    $key, $lbl, (int) ($_POST['new_day'] ?? 0),
                    jamKeDesimal($_POST['new_start'] ?? '08:00'), jamKeDesimal($_POST['new_end'] ?? '10:00'), $max + 10,
                ]);
            }
            flash('Daftar acara disimpan.');
        }
        elseif ($act === 'layanan') {
            foreach ((array) ($_POST['s'] ?? []) as $id => $row) {
                $id = (int) $id;
                if (trim($row['label'] ?? '') === '') { q("DELETE FROM site_services WHERE id = ?", [$id]); continue; }
                q("UPDATE site_services SET label=?, note=?, sort_order=?, is_active=? WHERE id=?", [
                    trim($row['label']), trim($row['note'] ?? ''), (int) ($row['ord'] ?? 0),
                    isset($row['on']) ? 1 : 0, $id,
                ]);
            }
            $lbl = trim($_POST['new_label'] ?? '');
            if ($lbl !== '') {
                $key = slugify($lbl, 36);
                $n = 2; while (one("SELECT id FROM site_services WHERE skey = ?", [$key])) $key = slugify($lbl, 32) . '-' . $n++;
                $max = (int) (one("SELECT COALESCE(MAX(sort_order),0) m FROM site_services")['m'] ?? 0);
                q("INSERT INTO site_services (skey,label,note,sort_order) VALUES (?,?,?,?)",
                  [$key, $lbl, trim($_POST['new_note'] ?? ''), $max + 10]);
            }
            flash('Daftar layanan disimpan.');
        }
        elseif ($act === 'preset') {
            foreach ((array) ($_POST['p'] ?? []) as $id => $row) {
                $id = (int) $id;
                if (trim($row['label'] ?? '') === '') { q("DELETE FROM site_presets WHERE id = ?", [$id]); continue; }
                q("UPDATE site_presets SET label=?, moments=?, services=?, guests=?, is_active=? WHERE id=?", [
                    trim($row['label']),
                    implode(',', (array) ($row['m'] ?? [])),
                    implode(',', (array) ($row['s'] ?? [])),
                    intval_between($row['g'] ?? 300, 10, 5000, 300),
                    isset($row['on']) ? 1 : 0, $id,
                ]);
            }
            flash('Paket contoh disimpan.');
        }
        elseif ($act === 'angka') {
            foreach (['crew_per','guest_min','guest_max','guest_step','guest_default'] as $k) {
                if (isset($_POST[$k])) settingSet($k, (string) max(1, (int) $_POST[$k]));
            }
            flash('Angka penyusun disimpan.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('admin/penyusun.php');
}

function jamKeDesimal(string $hhmm): float
{
    [$h, $m] = array_pad(explode(':', trim($hhmm)), 2, '0');
    return round(((int) $h) + ((int) $m) / 60, 2);
}

$momen   = all("SELECT * FROM site_moments  ORDER BY day_offset, sort_order, id");
$layanan = all("SELECT * FROM site_services ORDER BY sort_order, id");
$preset  = all("SELECT * FROM site_presets  ORDER BY sort_order, id");

adminHead('Penyusun brief', 'penyusun');
pageHead('Penyusun brief',
    'Isi bagian "Susun harimu" di situs. Perubahan di sini langsung berlaku untuk situs publik dan panel Susunan hari.',
    '<a class="btn ghost" target="_blank" rel="noopener" href="' . url() . '#susun">Lihat di situs ↗</a>');
?>

<div class="card">
  <h2>Rangkaian acara</h2>
  <p class="sub">Pilihan yang muncul sebagai tombol di penyusun. Jam di sini jadi nilai awal — calon klien masih bisa menggesernya.</p>
  <form method="post">
    <?= csrfField() ?><input type="hidden" name="act" value="momen">
    <?php foreach ([-1 => 'H-1 · sehari sebelumnya', 0 => 'Hari-H'] as $off => $judul):
      $grup = array_filter($momen, fn($m) => (int) $m['day_offset'] === $off); ?>
      <span class="lab" style="display:block;margin:15px 0 8px"><?= e($judul) ?></span>
      <?php foreach ($grup as $m): ?>
        <div class="editrow">
          <input type="checkbox" name="m[<?= $m['id'] ?>][on]" <?= $m['is_active'] ? 'checked' : '' ?> title="Tampilkan di situs">
          <input type="text" name="m[<?= $m['id'] ?>][label]" value="<?= e($m['label']) ?>" placeholder="Kosongkan untuk menghapus">
          <select name="m[<?= $m['id'] ?>][day]">
            <option value="0"  <?= (int) $m['day_offset'] === 0  ? 'selected' : '' ?>>Hari-H</option>
            <option value="-1" <?= (int) $m['day_offset'] === -1 ? 'selected' : '' ?>>H-1</option>
          </select>
          <input type="time" name="m[<?= $m['id'] ?>][start]" value="<?= jamDesimal((float) $m['start_h']) ?>">
          <input type="time" name="m[<?= $m['id'] ?>][end]"   value="<?= jamDesimal((float) $m['end_h']) ?>">
          <input type="number" name="m[<?= $m['id'] ?>][ord]" value="<?= (int) $m['sort_order'] ?>" step="10" title="Urutan" style="width:78px">
          <span class="mono muted" style="font-size:10px"><?= e($m['mkey']) ?></span>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="editrow baru">
      <span class="plus">+</span>
      <input type="text" name="new_label" placeholder="Tambah acara baru">
      <select name="new_day"><option value="0">Hari-H</option><option value="-1">H-1</option></select>
      <input type="time" name="new_start" value="08:00">
      <input type="time" name="new_end" value="10:00">
    </div>
    <button class="btn solid" type="submit" style="margin-top:14px">Simpan rangkaian</button>
  </form>
</div>

<div class="card">
  <h2>Layanan</h2>
  <p class="sub">Muncul sebagai pilihan "Layanan sepanjang hari". Keterangan adalah cakupan, bukan harga.</p>
  <form method="post">
    <?= csrfField() ?><input type="hidden" name="act" value="layanan">
    <?php foreach ($layanan as $l): ?>
      <div class="editrow">
        <input type="checkbox" name="s[<?= $l['id'] ?>][on]" <?= $l['is_active'] ? 'checked' : '' ?>>
        <input type="text" name="s[<?= $l['id'] ?>][label]" value="<?= e($l['label']) ?>" placeholder="Kosongkan untuk menghapus">
        <input type="text" name="s[<?= $l['id'] ?>][note]" value="<?= e($l['note']) ?>" placeholder="keterangan cakupan">
        <input type="number" name="s[<?= $l['id'] ?>][ord]" value="<?= (int) $l['sort_order'] ?>" step="10" style="width:78px">
        <span class="mono muted" style="font-size:10px"><?= e($l['skey']) ?></span>
      </div>
    <?php endforeach; ?>
    <div class="editrow baru">
      <span class="plus">+</span>
      <input type="text" name="new_label" placeholder="Tambah layanan baru">
      <input type="text" name="new_note" placeholder="keterangan cakupan">
    </div>
    <button class="btn solid" type="submit" style="margin-top:14px">Simpan layanan</button>
  </form>
</div>

<div class="card">
  <h2>Paket contoh</h2>
  <p class="sub">Tombol pintas yang mengisi penyusun sekaligus. Berguna untuk calon klien yang belum tahu mau apa saja.</p>
  <form method="post">
    <?= csrfField() ?><input type="hidden" name="act" value="preset">
    <?php foreach ($preset as $p):
      $pm = array_filter(explode(',', $p['moments']));
      $ps = array_filter(explode(',', $p['services'])); ?>
      <div style="border:1px solid var(--ivory-12);border-radius:10px;padding:14px;margin-bottom:11px">
        <div class="row c3" style="margin-bottom:10px">
          <div class="field" style="margin:0"><label>Nama paket</label>
            <input type="text" name="p[<?= $p['id'] ?>][label]" value="<?= e($p['label']) ?>"></div>
          <div class="field" style="margin:0"><label>Perkiraan tamu</label>
            <input type="number" name="p[<?= $p['id'] ?>][g]" value="<?= (int) $p['guests'] ?>" step="25"></div>
          <div class="field" style="margin:0"><label>Tampil</label>
            <label class="check" style="padding:9px 0"><input type="checkbox" name="p[<?= $p['id'] ?>][on]" <?= $p['is_active'] ? 'checked' : '' ?>> Aktif</label></div>
        </div>
        <span class="lab">Acara yang termasuk</span>
        <div class="pilihan">
          <?php foreach ($momen as $m): ?>
            <label><input type="checkbox" name="p[<?= $p['id'] ?>][m][]" value="<?= e($m['mkey']) ?>" <?= in_array($m['mkey'], $pm, true) ? 'checked' : '' ?>> <?= e($m['label']) ?></label>
          <?php endforeach; ?>
        </div>
        <span class="lab" style="display:block;margin-top:10px">Layanan yang termasuk</span>
        <div class="pilihan">
          <?php foreach ($layanan as $l): ?>
            <label><input type="checkbox" name="p[<?= $p['id'] ?>][s][]" value="<?= e($l['skey']) ?>" <?= in_array($l['skey'], $ps, true) ? 'checked' : '' ?>> <?= e($l['label']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <button class="btn solid" type="submit">Simpan paket</button>
  </form>
</div>

<div class="card">
  <h2>Angka</h2>
  <form method="post">
    <?= csrfField() ?><input type="hidden" name="act" value="angka">
    <div class="row c3">
      <div class="field"><label for="cp">1 kru lapangan per … tamu</label>
        <input type="number" id="cp" name="crew_per" value="<?= e(setting('crew_per', '100')) ?>" min="10"></div>
      <div class="field"><label for="gd">Tamu bawaan penggeser</label>
        <input type="number" id="gd" name="guest_default" value="<?= e(setting('guest_default', '300')) ?>" step="25"></div>
      <div class="field"><label for="gs">Kelipatan penggeser</label>
        <input type="number" id="gs" name="guest_step" value="<?= e(setting('guest_step', '25')) ?>" min="1"></div>
    </div>
    <div class="row c2">
      <div class="field"><label for="gmin">Tamu minimum</label>
        <input type="number" id="gmin" name="guest_min" value="<?= e(setting('guest_min', '50')) ?>" min="1"></div>
      <div class="field"><label for="gmax">Tamu maksimum</label>
        <input type="number" id="gmax" name="guest_max" value="<?= e(setting('guest_max', '1500')) ?>" min="1"></div>
    </div>
    <button class="btn solid" type="submit">Simpan angka</button>
  </form>
</div>

<?php adminFoot();
