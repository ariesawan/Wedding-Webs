<?php
/**
 * KATEGORI VENDOR — pengelola isi halaman publik.
 *
 * Halaman /vendor dan /vendor/<slug> seluruhnya bersumber dari sini. Tidak
 * ada teks kategori yang tertanam di kode, jadi menambah jenis vendor baru
 * atau memperbaiki penjelasan tidak perlu menyentuh berkas apa pun.
 *
 * Kolom "Terbit" sengaja mati secara bawaan. Menerbitkan dua puluh delapan
 * halaman sekaligus yang sebagian berisi dua kalimat justru menurunkan
 * kualitas situs di mata mesin pencari — lebih baik dua belas halaman yang
 * benar-benar menjawab pertanyaan daripada dua puluh delapan yang setengah.
 */
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/image.php';

$user = requireLogin();

/* ============================================================
   SIMPAN
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';
    $id  = (int) ($_POST['id'] ?? 0);

    try {
        if ($act === 'simpan' && $id > 0) {
            $kat = one("SELECT * FROM vendor_categories WHERE id = ?", [$id]);
            if (!$kat) throw new RuntimeException('Kategori tidak ditemukan.');

            $data = [
                'ringkas'   => mb_substr(trim($_POST['ringkas'] ?? ''), 0, 190),
                'kisaran'   => mb_substr(trim($_POST['kisaran'] ?? ''), 0, 90),
                'isi'       => trim($_POST['isi'] ?? ''),
                'dicek'     => trim($_POST['dicek'] ?? ''),
                'seo_title' => mb_substr(trim($_POST['seo_title'] ?? ''), 0, 160),
                'seo_desc'  => mb_substr(trim($_POST['seo_desc'] ?? ''), 0, 220),
                'gambar_alt'=> mb_substr(trim($_POST['gambar_alt'] ?? ''), 0, 190),
                'is_public' => !empty($_POST['is_public']) ? 1 : 0,
            ];

            // ---- Unggahan gambar ----
            if (!empty($_FILES['gambar']['name']) && $_FILES['gambar']['error'] === UPLOAD_ERR_OK) {
                // Slug berkas diberi awalan tetap supaya tidak bertabrakan
                // dengan foto galeri yang kebetulan bernama sama.
                $slugBerkas = 'kat-' . $kat['slug'];
                $meta = saveGalleryImage($_FILES['gambar'], DIR_FOTO, $slugBerkas, 1600, 620);

                $data['gambar']      = $slugBerkas;
                $data['gambar_w']    = $meta['width'];
                $data['gambar_h']    = $meta['height'];
                $data['gambar_webp'] = $meta['webp'];

                if ($data['gambar_alt'] === '') {
                    $data['gambar_alt'] = $kat['nama'] . ' pernikahan Yogyakarta';
                }
            }

            // ---- Hapus gambar ----
            if (!empty($_POST['hapus_gambar']) && $kat['gambar'] !== '') {
                deleteImageSet(DIR_FOTO, $kat['gambar']);
                $data['gambar'] = '';
                $data['gambar_w'] = null;
                $data['gambar_h'] = null;
                $data['gambar_webp'] = 0;
            }

            $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
            q("UPDATE vendor_categories SET $set WHERE id = ?", [...array_values($data), $id]);

            flash('Kategori "' . $kat['nama'] . '" disimpan.');
        }

        if ($act === 'terbit_toggle' && $id > 0) {
            q("UPDATE vendor_categories SET is_public = IF(is_public = 1, 0, 1) WHERE id = ?", [$id]);
            flash('Status terbit diubah.');
        }

    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    redirect('admin/vendor-kategori.php' . ($id ? '?edit=' . $id : ''));
}

/* ============================================================
   BACA
   ============================================================ */
$editId = (int) ($_GET['edit'] ?? 0);
$edit   = $editId ? one("SELECT * FROM vendor_categories WHERE id = ?", [$editId]) : null;

$daftar = all("SELECT vc.*,
                      (SELECT COUNT(*) FROM vendor_categories a WHERE a.parent_id = vc.id) sub
               FROM vendor_categories vc
               WHERE vc.parent_id IS NULL AND vc.is_active = 1
               ORDER BY vc.is_public DESC, vc.urutan, vc.nama");

$nTerbit = 0;
foreach ($daftar as $d) if ($d['is_public']) $nTerbit++;

adminHead('Kategori vendor', 'vendor-kategori');
pageHead('Kategori vendor',
    'Isi halaman /vendor di situs publik. ' . $nTerbit . ' dari ' . count($daftar) . ' kategori terbit.',
    '<a class="btn ghost" href="' . e(url('vendor')) . '" target="_blank" rel="noopener">Lihat di situs ↗</a>');
?>

<?php if ($edit): ?>
<form method="post" enctype="multipart/form-data" class="card">
  <?= csrfField() ?>
  <input type="hidden" name="act" value="simpan">
  <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">

  <h2><?= e($edit['nama']) ?>
    <span class="lab" style="margin-left:8px">/vendor/<?= e($edit['slug']) ?></span></h2>

  <div class="grid g2" style="align-items:start">
    <div>
      <div class="field"><label for="ri">Kalimat ringkas</label>
        <input type="text" id="ri" name="ringkas" maxlength="190" value="<?= e($edit['ringkas']) ?>"
               placeholder="Satu kalimat di bawah nama, pada kartu dan judul halaman">
        <p class="hint" style="margin:6px 0 0">Ini yang paling banyak dibaca — muncul di kartu grid
          maupun di bawah judul halaman.</p></div>

      <div class="field"><label for="ki">Kisaran harga di Yogyakarta</label>
        <input type="text" id="ki" name="kisaran" maxlength="90" value="<?= e($edit['kisaran']) ?>"
               placeholder="Rp 8 – 60 juta">
        <p class="hint" style="margin:6px 0 0">Kosongkan kalau terlalu bervariasi untuk disebut.
          Angka yang salah lebih merugikan daripada tidak ada angka.</p></div>

      <div class="field"><label for="di">Yang perlu ditanyakan sebelum memesan</label>
        <textarea id="di" name="dicek" rows="7"
          placeholder="Satu pertanyaan per baris.&#10;Sampai jam berapa venue boleh dipakai?&#10;Ada genset cadangan?"><?= e((string) $edit['dicek']) ?></textarea>
        <p class="hint" style="margin:6px 0 0">Satu per baris, dinomori otomatis. Urutan berarti —
          taruh yang paling sering bikin menyesal di atas.</p></div>
    </div>

    <div>
      <span class="lab" style="display:block;margin-bottom:8px">Gambar</span>
      <?php if ($edit['gambar']): ?>
        <div style="margin-bottom:12px">
          <img src="<?= e(url('foto/' . $edit['gambar'] . '.jpg')) ?>?v=<?= (int) filemtime(DIR_FOTO . '/' . $edit['gambar'] . '.jpg') ?>"
               alt="" style="width:100%;border-radius:12px;display:block;border:var(--card-border)">
          <p class="hint" style="margin:7px 0 0">
            <?= (int) $edit['gambar_w'] ?>×<?= (int) $edit['gambar_h'] ?> px
            <?= $edit['gambar_webp'] ? '· WebP tersedia' : '' ?>
          </p>
          <label style="display:flex;gap:8px;align-items:center;margin-top:9px;font-size:13px">
            <input type="checkbox" name="hapus_gambar" value="1"> Hapus gambar ini
          </label>
        </div>
      <?php else: ?>
        <div class="empty" style="padding:26px 14px;margin-bottom:12px">
          <p>Belum ada gambar</p>
          <span>Kartu dan halaman akan tampil tanpa gambar.</span>
        </div>
      <?php endif; ?>

      <div class="field"><label for="gb"><?= $edit['gambar'] ? 'Ganti gambar' : 'Unggah gambar' ?></label>
        <input type="file" id="gb" name="gambar" accept="image/jpeg,image/png,image/webp">
        <p class="hint" style="margin:6px 0 0">Mendatar, minimal 1200 px. Otomatis dikecilkan jadi
          1600 px dan dibuatkan thumbnail serta versi WebP.</p></div>

      <div class="field"><label for="ga">Teks alternatif gambar</label>
        <input type="text" id="ga" name="gambar_alt" maxlength="190" value="<?= e($edit['gambar_alt']) ?>"
               placeholder="Dekorasi pelaminan adat Jawa di pendopo">
        <p class="hint" style="margin:6px 0 0">Dibaca pembaca layar, dan dipakai Google Images untuk
          memahami isinya. Jelaskan apa yang terlihat, bukan ulangi nama kategori.</p></div>

      <label style="display:flex;gap:9px;align-items:flex-start;padding:11px 13px;margin-top:6px;
                    border:1px solid var(--ivory-12);border-radius:11px;cursor:pointer">
        <input type="checkbox" name="is_public" value="1" <?= $edit['is_public'] ? 'checked' : '' ?>
               style="margin-top:3px">
        <span><b style="color:var(--ivory)">Terbitkan di situs</b><br>
          <span style="font-size:12.3px;color:var(--ivory-38)">Muncul di /vendor dan punya halaman
          sendiri. Terbitkan setelah isinya benar-benar menjawab sesuatu.</span></span>
      </label>
    </div>
  </div>

  <div class="field"><label for="is">Penjelasan panjang</label>
    <textarea id="is" name="isi" rows="8"
      placeholder="Boleh HTML sederhana: &lt;p&gt;, &lt;h3&gt;, &lt;ul&gt;, &lt;li&gt;, &lt;b&gt;.&#10;Kosongkan untuk memakai teks bawaan."><?= e((string) $edit['isi']) ?></textarea>
    <p class="hint" style="margin:6px 0 0">Kalau dikosongkan, halaman memakai penjelasan bawaan
      tentang bagaimana Callalily menangani jenis vendor ini.</p></div>

  <div class="row c2">
    <div class="field"><label for="st">Judul SEO</label>
      <input type="text" id="st" name="seo_title" maxlength="160" value="<?= e($edit['seo_title']) ?>"
             placeholder="<?= e($edit['nama']) ?> Pernikahan Yogyakarta — Callalily Party">
      <p class="hint" style="margin:6px 0 0">Yang tampil di hasil Google. Sertakan nama kota.</p></div>
    <div class="field"><label for="sd">Deskripsi SEO</label>
      <input type="text" id="sd" name="seo_desc" maxlength="220" value="<?= e($edit['seo_desc']) ?>"
             placeholder="70–165 karakter">
      <p class="hint" style="margin:6px 0 0">Cuplikan di bawah judul pada hasil pencarian.</p></div>
  </div>

  <div style="display:flex;gap:10px;align-items:center;margin-top:6px">
    <button class="btn solid" type="submit">Simpan</button>
    <a class="btn ghost" href="vendor-kategori.php">Kembali ke daftar</a>
    <?php if ($edit['is_public']): ?>
      <a class="btn ghost" href="<?= e(url('vendor/' . $edit['slug'])) ?>" target="_blank" rel="noopener">
        Lihat halamannya ↗</a>
    <?php endif; ?>
  </div>
</form>
<?php endif; ?>

<div class="card">
  <h2>Semua kategori</h2>
  <p class="sub">Yang terbit muncul di situs publik. Yang belum tetap bisa dipakai di panel —
    hanya tidak punya halaman sendiri.</p>

  <table class="tbl">
    <thead><tr><th style="width:56px"></th><th>Kategori</th><th>Ringkas</th>
      <th class="num">Kisaran</th><th class="num">Terbit</th><th></th></tr></thead>
    <?php foreach ($daftar as $d): ?>
      <tr style="<?= $editId === (int) $d['id'] ? 'background:var(--ember-soft)' : '' ?>">
        <td data-l="Gambar">
          <?php if ($d['gambar']): ?>
            <img src="<?= e(url('foto/' . $d['gambar'] . '-thumb.jpg')) ?>" alt=""
                 style="width:48px;height:34px;object-fit:cover;border-radius:6px;display:block">
          <?php else: ?>
            <span style="display:grid;place-items:center;width:48px;height:34px;border-radius:6px;
                         border:1px dashed var(--ivory-12);color:var(--ivory-30);font-size:15px">—</span>
          <?php endif; ?>
        </td>
        <td data-l="Kategori">
          <b><?= e($d['nama']) ?></b>
          <?php if ($d['sub'] > 0): ?><span class="muted" style="font-size:12px"> · <?= (int) $d['sub'] ?> sub</span><?php endif; ?>
          <br><span class="muted mono" style="font-size:11.5px">/vendor/<?= e($d['slug']) ?></span>
        </td>
        <td data-l="Ringkas" class="muted" style="font-size:12.8px">
          <?= $d['ringkas'] ? e(mb_strimwidth($d['ringkas'], 0, 60, '…')) : '<i style="opacity:.5">belum diisi</i>' ?>
        </td>
        <td data-l="Kisaran" class="num mono" style="font-size:12.3px">
          <?= $d['kisaran'] ? e($d['kisaran']) : '—' ?></td>
        <td data-l="Terbit" class="num">
          <form method="post" style="display:inline">
            <?= csrfField() ?><input type="hidden" name="act" value="terbit_toggle">
            <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
            <button class="btn sm <?= $d['is_public'] ? 'solid' : 'ghost' ?>" type="submit">
              <?= $d['is_public'] ? 'Ya' : 'Tidak' ?></button>
          </form>
        </td>
        <td class="actions"><a class="btn sm ghost" href="?edit=<?= (int) $d['id'] ?>">Sunting</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php adminFoot();
