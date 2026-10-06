<?php
/**
 * ============================================================
 * PAKET & PRICE LIST
 * ============================================================
 *
 * Satu tempat untuk dua hal yang dulu terpisah:
 *   - paket yang tampil di halaman price list situs (/pricelist)
 *   - template internal untuk menyusun price list / penawaran khusus
 *
 * Bentuknya "paket + rincian isi" sesuai pilihan owner: satu harga paket,
 * isi dikelompokkan tanpa harga per baris, dan tambahan opsional dengan
 * harganya sendiri. Admin early memakai ini untuk mengirim price list.
 */
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/pipeline.php';
require_once __DIR__ . '/../inc/paket.php';
$user = requireLogin();

if (!in_array($user['role'] ?? '', ['owner', 'admin_early'], true)) {
    http_response_code(403);
    die('Paket & price list disusun admin early. <a href="index.php" style="color:#E9A85C">Kembali</a>');
}

$uang = fn($v) => (float) preg_replace('/[^\d]/', '', (string) $v);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';
    $tid = (int) ($_POST['id'] ?? 0);
    try {
        if ($act === 'baru') {
            $nama = trim($_POST['nama'] ?? '');
            if ($nama === '') throw new RuntimeException('Nama paket wajib diisi.');
            $urut = (int) (one("SELECT COALESCE(MAX(urutan),0)+10 u FROM quote_templates")['u'] ?? 10);
            q("INSERT INTO quote_templates (nama, slug, deskripsi, tipe, urutan, created_by, tampil_web, harga_mulai)
               VALUES (?,?,?, 'semua', ?, ?, ?, 1)",
              [mb_substr($nama, 0, 120), slugPaket($nama), '', $urut, $user['id'], isset($_POST['tampil_web']) ? 1 : 0]);
            $baru = insertId();

            // Mulai dari kerangka: kelompok baku sudah ada, isinya tinggal diganti.
            $asal = (int) ($_POST['dari'] ?? 0);
            if ($asal) {
                q("INSERT INTO quote_template_items (template_id, category_id, kelompok, label, detail, qty, satuan, harga, opsional, sort_order)
                   SELECT ?, category_id, kelompok, label, detail, qty, satuan, harga, opsional, sort_order
                     FROM quote_template_items WHERE template_id = ?", [$baru, $asal]);
                $src = one("SELECT catatan_bawaan FROM quote_templates WHERE id = ?", [$asal]);
                q("UPDATE quote_templates SET catatan_bawaan = ? WHERE id = ?", [(string) ($src['catatan_bawaan'] ?? ''), $baru]);
            }
            flash('Paket dibuat. Lengkapi isi dan harganya di bawah.');
            redirect('admin/paket.php?id=' . $baru);
        }

        if (!$tid || !one("SELECT id FROM quote_templates WHERE id = ?", [$tid]))
            throw new RuntimeException('Paket tidak ditemukan.');

        if ($act === 'simpan') {
            $nama = trim($_POST['nama'] ?? '');
            if ($nama === '') throw new RuntimeException('Nama paket wajib diisi.');
            $harga = $uang($_POST['harga'] ?? '');
            $slug  = trim($_POST['slug'] ?? '') !== '' ? slugify($_POST['slug'], 120) : '';
            $slug  = $slug !== '' && !one("SELECT id FROM quote_templates WHERE slug = ? AND id <> ?", [$slug, $tid])
                   ? $slug : slugPaket($nama, $tid);
            q("UPDATE quote_templates SET nama = ?, slug = ?, ringkas = ?, deskripsi = ?, tipe = ?, catatan_bawaan = ?,
                                          harga = ?, harga_mulai = ?, tamu = ?, tampil_web = ?, unggulan = ?,
                                          urutan = ?, is_active = ?
               WHERE id = ?",
              [mb_substr($nama, 0, 120), $slug,
               mb_substr(trim($_POST['ringkas'] ?? ''), 0, 190),
               mb_substr(trim($_POST['deskripsi'] ?? ''), 0, 400),
               in_array($_POST['tipe'] ?? '', ['tematis', 'budgeting'], true) ? $_POST['tipe'] : 'semua',
               trim($_POST['catatan_bawaan'] ?? ''),
               $harga > 0 ? $harga : null,
               isset($_POST['harga_mulai']) ? 1 : 0,
               ($_POST['tamu'] ?? '') !== '' ? max(0, (int) $_POST['tamu']) : null,
               isset($_POST['tampil_web']) ? 1 : 0,
               isset($_POST['unggulan']) ? 1 : 0,
               (int) ($_POST['urutan'] ?? 0),
               isset($_POST['is_active']) ? 1 : 0, $tid]);

            foreach ((array) ($_POST['item'] ?? []) as $iid => $row) {
                $qty = max(0.01, (float) str_replace(',', '.', (string) ($row['qty'] ?? 1)));
                q("UPDATE quote_template_items
                      SET kelompok = ?, label = ?, detail = ?, qty = ?, satuan = ?, harga = ?, sort_order = ?
                    WHERE id = ? AND template_id = ?",
                  [mb_substr(trim($row['kelompok'] ?? ''), 0, 80),
                   mb_substr(trim($row['label'] ?? ''), 0, 190),
                   mb_substr(trim($row['detail'] ?? ''), 0, 400),
                   $qty, mb_substr(trim($row['satuan'] ?? 'paket'), 0, 30) ?: 'paket',
                   $uang($row['harga'] ?? 0), (int) ($row['sort'] ?? 0), (int) $iid, $tid]);
            }
            // Baris yang judulnya dikosongkan dihapus — cara paling cepat
            // membersihkan baris kerangka yang tidak dipakai paket ini.
            q("DELETE FROM quote_template_items WHERE template_id = ? AND TRIM(label) = ''", [$tid]);
            flash('Paket disimpan.' . (isset($_POST['tampil_web']) && $harga <= 0
                ? ' Harganya masih kosong — di situs tertulis "harga dikirim lewat WhatsApp".' : ''));
        }

        elseif ($act === 'baris') {
            $opsi = ($_POST['jenis'] ?? '') === 'opsi' ? 1 : 0;
            $urut = (int) (one("SELECT COALESCE(MAX(sort_order),0)+10 s FROM quote_template_items WHERE template_id = ?", [$tid])['s'] ?? 10);
            $kel  = $opsi ? '' : mb_substr(trim($_POST['kelompok'] ?? ''), 0, 80);
            q("INSERT INTO quote_template_items (template_id, kelompok, label, qty, satuan, harga, opsional, sort_order)
               VALUES (?, ?, ?, 1, 'paket', 0, ?, ?)", [$tid, $kel, $opsi ? 'Tambahan baru' : 'Isi baru', $opsi, $urut]);
            redirect('admin/paket.php?id=' . $tid . '#' . ($opsi ? 'opsi' : 'isi'));
        }

        elseif ($act === 'hapus_baris') {
            q("DELETE FROM quote_template_items WHERE id = ? AND template_id = ?", [(int) $_POST['item_id'], $tid]);
            flash('Baris dihapus.');
        }

        elseif ($act === 'web') {
            q("UPDATE quote_templates SET tampil_web = 1 - tampil_web WHERE id = ?", [$tid]);
            $t = one("SELECT nama, tampil_web FROM quote_templates WHERE id = ?", [$tid]);
            flash($t['tampil_web'] ? 'Paket ' . $t['nama'] . ' tampil di price list situs.' : 'Paket ' . $t['nama'] . ' disembunyikan dari situs.');
            redirect('admin/paket.php');
        }

        elseif ($act === 'duplikat') {
            $t = one("SELECT * FROM quote_templates WHERE id = ?", [$tid]);
            $nama = mb_substr($t['nama'] . ' (salinan)', 0, 120);
            q("INSERT INTO quote_templates (nama, slug, ringkas, deskripsi, tipe, catatan_bawaan, urutan, created_by,
                                            harga, harga_mulai, tamu, tampil_web, unggulan)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,0,0)",
              [$nama, slugPaket($nama), $t['ringkas'], $t['deskripsi'], $t['tipe'], $t['catatan_bawaan'],
               (int) $t['urutan'] + 5, $user['id'], $t['harga'], $t['harga_mulai'], $t['tamu']]);
            $baru = insertId();
            q("INSERT INTO quote_template_items (template_id, category_id, kelompok, label, detail, qty, satuan, harga, opsional, sort_order)
               SELECT ?, category_id, kelompok, label, detail, qty, satuan, harga, opsional, sort_order
                 FROM quote_template_items WHERE template_id = ?", [$baru, $tid]);
            flash('Paket diduplikat (belum tampil di situs). Ubah yang perlu diubah.');
            redirect('admin/paket.php?id=' . $baru);
        }

        elseif ($act === 'hapus') {
            // Price list yang pernah dibuat dari paket ini TIDAK ikut hilang —
            // isinya sudah disalin utuh, jadi berdiri sendiri.
            q("DELETE FROM quote_template_items WHERE template_id = ?", [$tid]);
            q("DELETE FROM quote_templates WHERE id = ?", [$tid]);
            flash('Paket dihapus. Price list yang sudah terkirim tidak terpengaruh.');
            redirect('admin/paket.php');
        }

        redirect('admin/paket.php?id=' . $tid);
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
        redirect('admin/paket.php' . ($tid ? '?id=' . $tid : ''));
    }
}

$tid    = (int) ($_GET['id'] ?? 0);
$daftar = paketDaftar(false);
// paketDaftar() hanya yang aktif; halaman ini juga perlu yang nonaktif.
try {
    $daftar = all("SELECT t.*,
                          (SELECT COUNT(*) FROM quote_template_items i WHERE i.template_id = t.id AND i.opsional = 0) n_isi,
                          (SELECT COUNT(*) FROM quote_template_items i WHERE i.template_id = t.id AND i.opsional = 1) n_opsi,
                          (SELECT COUNT(*) FROM quotes q WHERE q.template_id = t.id) n_pakai
                     FROM quote_templates t ORDER BY t.tampil_web DESC, t.urutan, t.id");
} catch (Throwable $e) { /* skema lama: pakai hasil paketDaftar */ }
$urlWeb = url('pricelist');

adminHead('Paket & price list', 'paket');

/* ============================================================
   DAFTAR PAKET
   ============================================================ */
if (!$tid):
    $web = array_filter($daftar, fn($t) => !empty($t['tampil_web']));
    $tanpaHarga = array_filter($web, fn($t) => (float) ($t['harga'] ?? 0) <= 0);
    pageHead('Paket & price list',
             'Paket yang tampil di halaman price list situs, sekaligus template untuk mengirim price list ke klien. '
           . 'Bentuknya paket + rincian isi.',
             '<a class="btn ghost" href="' . e($urlWeb) . '" target="_blank" rel="noopener">Lihat price list di situs ↗</a>');
?>
  <?php if ($tanpaHarga): ?>
    <div class="flash warn"><span><?= count($tanpaHarga) ?> paket tampil di situs tanpa harga
      (<?= e(implode(', ', array_map(fn($t) => $t['nama'], $tanpaHarga))) ?>). Di situs tertulis
      "harga dikirim lewat WhatsApp" — isi harganya kalau sudah pasti.</span></div>
  <?php endif; ?>

  <div class="paket-grid">
    <?php foreach ($daftar as $t):
      $hl = paketHargaLabel($t, true); ?>
      <div class="card paket-kartu<?= !empty($t['tampil_web']) ? ' web' : '' ?><?= empty($t['is_active']) ? ' mati' : '' ?>">
        <div class="paket-atas">
          <span class="pill <?= !empty($t['tampil_web']) ? 'live' : 'draft' ?>"><?= !empty($t['tampil_web']) ? 'Tampil di situs' : 'Internal' ?></span>
          <?php if (!empty($t['unggulan'])): ?><span class="pill warn">Unggulan</span><?php endif; ?>
          <?php if (empty($t['is_active'])): ?><span class="pill bad">Nonaktif</span><?php endif; ?>
        </div>
        <h2><?= e($t['nama']) ?></h2>
        <?php if (!empty($t['ringkas'])): ?><p class="sub" style="margin:0 0 10px"><?= e($t['ringkas']) ?></p><?php endif; ?>
        <div class="paket-harga"><?= $hl ? e($hl) : '<span class="muted">Harga belum diisi</span>' ?></div>
        <p class="hint" style="margin:6px 0 14px">
          <?= (int) ($t['n_isi'] ?? 0) ?> isi<?= !empty($t['n_opsi']) ? ' · ' . (int) $t['n_opsi'] . ' tambahan' : '' ?>
          <?= !empty($t['tamu']) ? ' · ±' . number_format((int) $t['tamu'], 0, ',', '.') . ' tamu' : '' ?>
          <?= !empty($t['n_pakai']) ? ' · dipakai ' . (int) $t['n_pakai'] . '×' : '' ?></p>
        <div class="aksi">
          <a class="btn sm solid" href="?id=<?= (int) $t['id'] ?>">Sunting</a>
          <form method="post" style="display:inline"><?= csrfField() ?>
            <input type="hidden" name="act" value="web"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button class="btn sm ghost" type="submit"><?= !empty($t['tampil_web']) ? 'Sembunyikan dari situs' : 'Tampilkan di situs' ?></button></form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card" style="margin-top:18px">
    <h2>Paket baru</h2>
    <form method="post" class="row c3" style="align-items:end">
      <?= csrfField() ?><input type="hidden" name="act" value="baru">
      <div class="field" style="margin:0"><label>Nama paket</label><input type="text" name="nama" required placeholder="Intimate 100 tamu"></div>
      <div class="field" style="margin:0"><label>Mulai dari</label>
        <select name="dari">
          <option value="0">Kosong</option>
          <?php foreach ($daftar as $t): ?><option value="<?= (int) $t['id'] ?>" <?= ($t['slug'] ?? '') === 'template-kosong' ? 'selected' : '' ?>>Salin isi: <?= e($t['nama']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field" style="margin:0;display:flex;gap:12px;align-items:center">
        <label class="inline"><input type="checkbox" name="tampil_web" value="1"> Tampil di situs</label>
        <button class="btn solid" type="submit">Buat</button>
      </div>
    </form>
  </div>

<?php adminFoot(); exit; endif;

/* ============================================================
   SUNTING SATU PAKET
   ============================================================ */
$t = one("SELECT * FROM quote_templates WHERE id = ?", [$tid]);
if (!$t) { flash('Paket tidak ditemukan.', 'err'); redirect('admin/paket.php'); }
$baris = all("SELECT * FROM quote_template_items WHERE template_id = ? ORDER BY sort_order, id", [$tid]);
$isi   = array_values(array_filter($baris, fn($r) => !$r['opsional']));
$opsi  = array_values(array_filter($baris, fn($r) => $r['opsional']));
$kelompokAda = array_values(array_unique(array_filter(array_map(fn($r) => trim((string) $r['kelompok']), $isi))));
$kelompokSaran = array_values(array_unique(array_merge($kelompokAda,
    ['Wedding Organizer', 'Rias & busana', 'Dekorasi', 'Dokumentasi', 'Acara & hiburan', 'Rangkaian adat', 'Venue', 'Konsumsi', 'Undangan & souvenir'])));

pageHead($t['nama'],
         (!empty($t['tampil_web']) ? 'Tampil di price list situs' : 'Internal — tidak tampil di situs')
       . (!empty($t['slug']) && !empty($t['tampil_web']) ? ' · /pricelist#' . $t['slug'] : ''),
         '<a class="btn ghost" href="paket.php">← Semua paket</a>'
       . (!empty($t['tampil_web']) ? ' <a class="btn ghost" target="_blank" rel="noopener" href="' . e($urlWeb . '#' . $t['slug']) . '">Lihat di situs ↗</a>' : ''));
?>

<form method="post" id="fPaket">
  <?= csrfField() ?><input type="hidden" name="act" value="simpan"><input type="hidden" name="id" value="<?= $tid ?>">

  <div class="card">
    <h2>Paket</h2>
    <div class="row c3">
      <div class="field"><label>Nama paket</label><input type="text" name="nama" required value="<?= e($t['nama']) ?>"></div>
      <div class="field"><label>Harga paket (Rp)</label>
        <input type="text" class="uang" inputmode="numeric" name="harga" value="<?= (float) $t['harga'] > 0 ? number_format((float) $t['harga'], 0, ',', '.') : '' ?>" placeholder="kosongkan kalau belum pasti">
        <label class="inline" style="margin-top:7px"><input type="checkbox" name="harga_mulai" value="1" <?= !empty($t['harga_mulai']) ? 'checked' : '' ?>> Tulis "mulai dari"</label></div>
      <div class="field"><label>Perkiraan tamu</label><input type="number" name="tamu" min="0" step="25" value="<?= e((string) ($t['tamu'] ?? '')) ?>"></div>
    </div>
    <div class="field"><label>Kalimat singkat di bawah nama</label>
      <input type="text" name="ringkas" maxlength="190" value="<?= e((string) $t['ringkas']) ?>" placeholder="Akad pagi, resepsi malam. Hari penuh di tanggal yang sama."></div>
    <div class="row c3">
      <div class="field"><label class="inline"><input type="checkbox" name="tampil_web" value="1" <?= !empty($t['tampil_web']) ? 'checked' : '' ?>> Tampil di price list situs</label>
        <label class="inline"><input type="checkbox" name="unggulan" value="1" <?= !empty($t['unggulan']) ? 'checked' : '' ?>> Tandai "paling dipilih"</label>
        <label class="inline"><input type="checkbox" name="is_active" value="1" <?= !empty($t['is_active']) ? 'checked' : '' ?>> Aktif (bisa dipilih admin)</label></div>
      <div class="field"><label>Urutan</label><input type="number" name="urutan" value="<?= (int) $t['urutan'] ?>">
        <p class="hint">Kecil tampil lebih dulu.</p></div>
      <div class="field"><label>Alamat di situs</label><input type="text" name="slug" value="<?= e((string) $t['slug']) ?>">
        <p class="hint">Dipakai tautan "pilih paket ini".</p></div>
    </div>
    <div class="field"><label>Catatan untuk admin (tidak tampil ke klien)</label>
      <input type="text" name="deskripsi" value="<?= e((string) $t['deskripsi']) ?>" placeholder="Kapan paket ini dipakai"></div>
    <input type="hidden" name="tipe" value="<?= e((string) $t['tipe']) ?>">
  </div>

  <div class="card" id="isi">
    <h2>Rincian isi <span class="lab" style="margin-left:8px"><?= count($isi) ?></span></h2>
    <p class="sub">Yang termasuk harga paket — tampil tanpa harga per baris. Kosongkan judul baris untuk menghapusnya.</p>
    <datalist id="kelompokList"><?php foreach ($kelompokSaran as $k): ?><option value="<?= e($k) ?>"><?php endforeach; ?></datalist>
    <div style="overflow-x:auto">
      <table class="tbl isi-tbl" style="min-width:760px">
        <thead><tr><th style="width:20%">Kelompok</th><th style="width:30%">Isi</th><th style="width:28%">Keterangan</th>
                   <th style="width:9%">Qty</th><th style="width:10%">Satuan</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($isi as $i => $r): $k = (int) $r['id']; ?>
          <tr>
            <td><input type="text" name="item[<?= $k ?>][kelompok]" value="<?= e((string) $r['kelompok']) ?>" list="kelompokList">
                <input type="hidden" name="item[<?= $k ?>][sort]" value="<?= ($i + 1) * 10 ?>"></td>
            <td><input type="text" name="item[<?= $k ?>][label]" value="<?= e($r['label']) ?>"></td>
            <td><input type="text" name="item[<?= $k ?>][detail]" value="<?= e($r['detail']) ?>"></td>
            <td><input type="text" inputmode="decimal" name="item[<?= $k ?>][qty]" value="<?= rtrim(rtrim(number_format((float) $r['qty'], 2, '.', ''), '0'), '.') ?>"></td>
            <td><input type="text" name="item[<?= $k ?>][satuan]" value="<?= e($r['satuan']) ?>"></td>
            <td class="actions"><button class="btn sm ghost danger" type="submit" form="h<?= $k ?>" title="Hapus">×</button>
              <input type="hidden" name="item[<?= $k ?>][harga]" value="0"></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$isi): ?><tr><td colspan="6" class="sub" style="padding:16px">Belum ada isi.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <p class="hint">Satuan <b>porsi</b>, <b>pax</b>, atau <b>kursi</b> otomatis mengikuti jumlah tamu klien saat price list dibuat.</p>
  </div>

  <div class="card" id="opsi">
    <h2>Tambahan opsional <span class="lab" style="margin-left:8px"><?= count($opsi) ?></span></h2>
    <p class="sub">Di luar harga paket, dengan harganya sendiri. Klien bisa memilih menambahkannya.</p>
    <div style="overflow-x:auto">
      <table class="tbl" style="min-width:640px">
        <thead><tr><th style="width:34%">Tambahan</th><th style="width:36%">Keterangan</th><th style="width:22%" class="num">Harga (Rp)</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($opsi as $i => $r): $k = (int) $r['id']; ?>
          <tr>
            <td><input type="text" name="item[<?= $k ?>][label]" value="<?= e($r['label']) ?>">
                <input type="hidden" name="item[<?= $k ?>][sort]" value="<?= 1000 + $i * 10 ?>">
                <input type="hidden" name="item[<?= $k ?>][kelompok]" value=""><input type="hidden" name="item[<?= $k ?>][qty]" value="1">
                <input type="hidden" name="item[<?= $k ?>][satuan]" value="<?= e($r['satuan']) ?>"></td>
            <td><input type="text" name="item[<?= $k ?>][detail]" value="<?= e($r['detail']) ?>"></td>
            <td><input type="text" class="uang" inputmode="numeric" name="item[<?= $k ?>][harga]" value="<?= number_format((float) $r['harga'], 0, ',', '.') ?>"></td>
            <td class="actions"><button class="btn sm ghost danger" type="submit" form="h<?= $k ?>" title="Hapus">×</button></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$opsi): ?><tr><td colspan="4" class="sub" style="padding:16px">Belum ada tambahan.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2>Catatan & ketentuan</h2>
    <p class="sub">Tercetak di PDF price list. Satu baris = satu butir.</p>
    <textarea name="catatan_bawaan" rows="6"><?= e((string) $t['catatan_bawaan']) ?></textarea>
  </div>

  <div class="simpan-bar">
    <button class="btn solid" type="submit">Simpan paket</button>
    <button class="btn ghost" type="submit" form="fIsi">+ Isi</button>
    <button class="btn ghost" type="submit" form="fOpsi">+ Tambahan</button>
    <span style="flex:1"></span>
    <button class="btn ghost" type="submit" form="fDup">Duplikat</button>
    <button class="btn ghost danger" type="submit" form="fDel" onclick="return confirm('Hapus paket ini? Price list yang sudah terkirim tidak terpengaruh.')">Hapus</button>
  </div>
</form>

<form method="post" id="fIsi"><?= csrfField() ?><input type="hidden" name="act" value="baris"><input type="hidden" name="jenis" value="isi">
  <input type="hidden" name="id" value="<?= $tid ?>"><input type="hidden" name="kelompok" value="<?= e(end($kelompokAda) ?: '') ?>"></form>
<form method="post" id="fOpsi"><?= csrfField() ?><input type="hidden" name="act" value="baris"><input type="hidden" name="jenis" value="opsi"><input type="hidden" name="id" value="<?= $tid ?>"></form>
<form method="post" id="fDup"><?= csrfField() ?><input type="hidden" name="act" value="duplikat"><input type="hidden" name="id" value="<?= $tid ?>"></form>
<form method="post" id="fDel"><?= csrfField() ?><input type="hidden" name="act" value="hapus"><input type="hidden" name="id" value="<?= $tid ?>"></form>
<?php foreach ($baris as $r): ?>
  <form method="post" id="h<?= (int) $r['id'] ?>"><?= csrfField() ?><input type="hidden" name="act" value="hapus_baris">
    <input type="hidden" name="id" value="<?= $tid ?>"><input type="hidden" name="item_id" value="<?= (int) $r['id'] ?>"></form>
<?php endforeach; ?>

<script>
/* Menambah baris memakai tombol terpisah yang langsung menyimpan baris
   kosong. Isian yang sedang diketik di formulir utama ikut hilang kalau
   tidak disimpan dulu — jadi tanyakan sebelum pergi. */
(() => {
  const f = document.getElementById('fPaket');
  let ubah = false;
  f.addEventListener('input', () => { ubah = true; });
  f.addEventListener('submit', () => { ubah = false; });
  ['fIsi', 'fOpsi', 'fDup'].forEach(id => document.getElementById(id).addEventListener('submit', e => {
    if (ubah && !confirm('Ada perubahan yang belum disimpan. Lanjut tanpa menyimpan?')) e.preventDefault();
  }));
  const fmt = v => { const a = String(v).replace(/\D/g, '').replace(/^0+(?=\d)/, ''); return a ? a.replace(/\B(?=(\d{3})+(?!\d))/g, '.') : ''; };
  document.querySelectorAll('input.uang').forEach(el => el.addEventListener('input', () => { el.value = fmt(el.value); }));
})();
</script>
<?php adminFoot();
