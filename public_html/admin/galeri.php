<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/image.php';
$user = requireLogin();

// ---------------- Aksi ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';

    try {
        if ($act === 'upload') {
            $who     = trim($_POST['who'] ?? '');
            $caption = trim($_POST['caption'] ?? '');
            $alt     = trim($_POST['alt_text'] ?? '');
            $eventId = ($_POST['event_id'] ?? '') !== '' ? (int) $_POST['event_id'] : null;
            if ($who === '') throw new RuntimeException('Nama pasangan wajib diisi.');

            $files = $_FILES['photos'] ?? null;
            if (!$files || !is_array($files['name'])) throw new RuntimeException('Tidak ada berkas yang dipilih.');

            $maxOrder = (int) (one("SELECT COALESCE(MAX(sort_order),0) m FROM gallery")['m'] ?? 0);
            $ok = 0; $fails = [];

            foreach ($files['name'] as $i => $origName) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                $single = [
                    'name' => $origName, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i], 'size' => $files['size'][$i],
                ];
                try {
                    $base = slugify($who . '-' . pathinfo($origName, PATHINFO_FILENAME), 90);
                    $slug = $base; $n = 2;
                    while (one("SELECT id FROM gallery WHERE slug = ?", [$slug])) $slug = $base . '-' . $n++;

                    $meta = saveGalleryImage($single, DIR_FOTO, $slug);
                    $maxOrder += 10;
                    q("INSERT INTO gallery (slug, media_type, who, caption, alt_text, event_id, width, height, has_webp, sort_order)
                       VALUES (?, 'photo', ?, ?, ?, ?, ?, ?, ?, ?)",
                       [$slug, $who, $caption, $alt ?: ($who . ' — ' . $caption), $eventId,
                        $meta['width'], $meta['height'], $meta['webp'], $maxOrder]);
                    $ok++;
                } catch (Throwable $e) {
                    $fails[] = $origName . ': ' . $e->getMessage();
                }
            }
            flash("$ok foto berhasil diunggah." . ($fails ? "\nGagal: " . implode("\n", $fails) : ''), $fails ? 'warn' : 'ok');
        }

        elseif ($act === 'upload_video') {
            $who     = trim($_POST['who'] ?? '');
            $caption = trim($_POST['caption'] ?? '');
            if ($who === '') throw new RuntimeException('Nama pasangan wajib diisi.');
            if (empty($_FILES['poster']['name'])) throw new RuntimeException('Poster (gambar sampul video) wajib diunggah.');
            if (empty($_FILES['video']['name']))  throw new RuntimeException('Berkas video wajib diunggah.');

            $base = slugify($who . '-' . pathinfo($_FILES['video']['name'], PATHINFO_FILENAME), 90);
            $slug = $base; $n = 2;
            while (one("SELECT id FROM gallery WHERE slug = ?", [$slug])) $slug = $base . '-' . $n++;

            $meta      = saveGalleryImage($_FILES['poster'], DIR_FOTO, $slug);
            $videoFile = saveVideo($_FILES['video'], DIR_VIDEO, $slug);
            $maxOrder  = (int) (one("SELECT COALESCE(MAX(sort_order),0) m FROM gallery")['m'] ?? 0) + 10;

            q("INSERT INTO gallery (slug, media_type, video_file, who, caption, alt_text, width, height, has_webp, sort_order)
               VALUES (?, 'video', ?, ?, ?, ?, ?, ?, ?, ?)",
               [$slug, $videoFile, $who, $caption, trim($_POST['alt_text'] ?? '') ?: "$who — $caption",
                $meta['width'], $meta['height'], $meta['webp'], $maxOrder]);
            flash('Video berhasil diunggah.');
        }

        elseif ($act === 'update') {
            $id = (int) $_POST['id'];
            q("UPDATE gallery SET who = ?, caption = ?, alt_text = ?, sort_order = ?, is_published = ? WHERE id = ?", [
                trim($_POST['who'] ?? ''), trim($_POST['caption'] ?? ''), trim($_POST['alt_text'] ?? ''),
                (int) ($_POST['sort_order'] ?? 0), isset($_POST['is_published']) ? 1 : 0, $id,
            ]);
            flash('Perubahan disimpan.');
        }

        elseif ($act === 'toggle') {
            q("UPDATE gallery SET is_published = 1 - is_published WHERE id = ?", [(int) $_POST['id']]);
            flash('Status tampil diperbarui.');
        }

        elseif ($act === 'delete') {
            $id  = (int) $_POST['id'];
            $row = one("SELECT * FROM gallery WHERE id = ?", [$id]);
            if ($row) {
                deleteImageSet(DIR_FOTO, $row['slug']);
                if ($row['video_file'] && is_file(DIR_VIDEO . '/' . $row['video_file'])) @unlink(DIR_VIDEO . '/' . $row['video_file']);
                q("DELETE FROM gallery WHERE id = ?", [$id]);
                flash('Item galeri dihapus beserta berkasnya.');
            }
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    redirect('admin/galeri.php');
}

// ---------------- Data ----------------
$fTipe   = in_array($_GET['tipe'] ?? '', ['photo','video'], true) ? $_GET['tipe'] : '';
$fStatus = in_array($_GET['st'] ?? '', ['on','off'], true) ? $_GET['st'] : '';
$fCari   = trim($_GET['q'] ?? '');

$w = []; $par = [];
if ($fTipe)   { $w[] = "media_type = ?";  $par[] = $fTipe; }
if ($fStatus) { $w[] = "is_published = ?"; $par[] = $fStatus === 'on' ? 1 : 0; }
if ($fCari)   { $w[] = "(who LIKE ? OR caption LIKE ? OR alt_text LIKE ?)";
                array_push($par, "%$fCari%", "%$fCari%", "%$fCari%"); }
$where  = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$items  = all("SELECT * FROM gallery $where ORDER BY sort_order ASC, id ASC", $par);
$total  = (int) (one("SELECT COUNT(*) c FROM gallery")['c'] ?? 0);
$events = all("SELECT id, title, event_date FROM events ORDER BY event_date DESC LIMIT 100");
$edit   = isset($_GET['edit']) ? one("SELECT * FROM gallery WHERE id = ?", [(int) $_GET['edit']]) : null;

adminHead('Galeri', 'galeri');
pageHead('Galeri pesta', count($items) . ' item · foto & video yang tampil di bola galeri.');
?>

<div class="grid g2" style="align-items:start">
  <div class="card">
    <h2>Unggah foto</h2>
    <p class="sub">Bisa pilih banyak berkas sekaligus. Thumbnail, versi web, dan WebP dibuat otomatis — tidak perlu resize manual.</p>
    <form method="post" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="act" value="upload">
      <div class="row c2">
        <div class="field">
          <label for="who">Nama pasangan</label>
          <input type="text" id="who" name="who" required placeholder="Winda &amp; Zakki">
        </div>
        <div class="field">
          <label for="ev">Kaitkan ke event</label>
          <select id="ev" name="event_id">
            <option value="">— tidak dikaitkan —</option>
            <?php foreach ($events as $ev): ?>
              <option value="<?= $ev['id'] ?>"><?= e($ev['title']) ?> · <?= date('M Y', strtotime($ev['event_date'])) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field">
        <label for="cap">Keterangan</label>
        <input type="text" id="cap" name="caption" placeholder="Resepsi · dekor @royal_kinan">
      </div>
      <div class="field">
        <label for="alt">Teks alternatif (alt)</label>
        <input type="text" id="alt" name="alt_text" placeholder="Resepsi pernikahan Winda dan Zakki di Sleman">
        <p class="hint">Dibaca mesin pencari dan pembaca layar. Kosongkan untuk memakai gabungan nama + keterangan.</p>
      </div>
      <div class="field">
        <label for="ph">Berkas foto</label>
        <input type="file" id="ph" name="photos[]" accept="image/*" multiple required>
        <p class="hint">JPG, PNG, atau WebP. Maksimal <?= round(MAX_IMAGE_SIZE / 1048576) ?> MB per berkas.</p>
      </div>
      <button class="btn solid" type="submit">Unggah foto</button>
    </form>
  </div>

  <div class="card">
    <h2>Unggah video</h2>
    <p class="sub">Video butuh gambar poster — itu yang jadi keping di bola galeri sebelum diklik.</p>
    <form method="post" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="act" value="upload_video">
      <div class="field"><label for="vwho">Judul / pasangan</label><input type="text" id="vwho" name="who" required placeholder="Adin &amp; Erik"></div>
      <div class="field"><label for="vcap">Keterangan</label><input type="text" id="vcap" name="caption" placeholder="Lapangan Lumbini, Borobudur"></div>
      <div class="field"><label for="valt">Teks alternatif</label><input type="text" id="valt" name="alt_text"></div>
      <div class="row c2">
        <div class="field"><label for="vpost">Poster</label><input type="file" id="vpost" name="poster" accept="image/*" required></div>
        <div class="field"><label for="vfile">Video</label><input type="file" id="vfile" name="video" accept="video/mp4,video/webm" required></div>
      </div>
      <p class="hint" style="margin:-8px 0 15px">MP4 (H.264) paling aman untuk semua peramban. Maksimal <?= round(MAX_VIDEO_SIZE / 1048576) ?> MB — kompres dulu bila lebih besar.</p>
      <button class="btn solid" type="submit">Unggah video</button>
    </form>
  </div>
</div>

<?php if ($edit): ?>
<div class="card" style="border-color:var(--ember)">
  <h2>Ubah: <?= e($edit['who']) ?></h2>
  <form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="act" value="update">
    <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
    <div class="row c2">
      <div class="field"><label>Nama pasangan</label><input type="text" name="who" value="<?= e($edit['who']) ?>" required></div>
      <div class="field"><label>Keterangan</label><input type="text" name="caption" value="<?= e($edit['caption']) ?>"></div>
    </div>
    <div class="field"><label>Teks alternatif</label><input type="text" name="alt_text" value="<?= e($edit['alt_text']) ?>"></div>
    <div class="row c2">
      <div class="field"><label>Urutan</label><input type="number" name="sort_order" value="<?= (int) $edit['sort_order'] ?>" step="10">
        <p class="hint">Angka kecil tampil lebih dulu.</p></div>
      <div class="field"><label>Status</label>
        <label class="check"><input type="checkbox" name="is_published" <?= $edit['is_published'] ? 'checked' : '' ?>> Tampilkan di galeri publik</label>
      </div>
    </div>
    <button class="btn solid" type="submit">Simpan perubahan</button>
    <a class="btn ghost" href="galeri.php">Batal</a>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>Isi galeri</h2>
  <p class="sub">Urutan di sini menentukan sebaran keping di bola galeri.</p>

  <form method="get" class="filterbar">
    <input type="text" name="q" value="<?= e($fCari) ?>" placeholder="Cari nama pasangan atau keterangan">
    <select name="tipe">
      <option value="">Semua tipe</option>
      <option value="photo" <?= $fTipe === 'photo' ? 'selected' : '' ?>>Foto</option>
      <option value="video" <?= $fTipe === 'video' ? 'selected' : '' ?>>Video</option>
    </select>
    <select name="st">
      <option value="">Semua status</option>
      <option value="on"  <?= $fStatus === 'on'  ? 'selected' : '' ?>>Tampil</option>
      <option value="off" <?= $fStatus === 'off' ? 'selected' : '' ?>>Tersembunyi</option>
    </select>
    <button class="btn sm" type="submit">Saring</button>
    <?php if ($fCari || $fTipe || $fStatus): ?><a class="btn sm ghost" href="galeri.php">Bersihkan</a><?php endif; ?>
    <span class="hit"><?= count($items) ?> dari <?= $total ?></span>
  </form>

  <?php if (!$items): ?>
    <div class="empty">
      <p>Galeri masih kosong</p>
      <span>Unggah foto pertama lewat formulir di atas. Bola galeri menyesuaikan jumlahnya sendiri.</span>
    </div>
  <?php else: ?>
    <div class="tiles">
      <?php foreach ($items as $it): ?>
        <div class="tile<?= $it['is_published'] ? '' : ' off' ?>">
          <?php if ($it['media_type'] === 'video'): ?><span class="flagv">VIDEO</span><?php endif; ?>
          <span class="ord"><?= (int) $it['sort_order'] ?></span>
          <img src="<?= url('foto/' . $it['slug'] . '-thumb.jpg') ?>" alt="<?= e($it['alt_text']) ?>" loading="lazy">
          <div class="meta">
            <div class="who"><?= e($it['who']) ?></div>
            <div class="cap"><?= e($it['caption'] ?: '—') ?></div>
          </div>
          <div class="bar">
            <a class="btn sm ghost" href="?edit=<?= (int) $it['id'] ?>">Ubah</a>
            <form method="post" style="display:inline">
              <?= csrfField() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
              <button class="btn sm ghost" type="submit"><?= $it['is_published'] ? 'Sembunyikan' : 'Tampilkan' ?></button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Hapus <?= e(addslashes($it['who'])) ?>? Berkas fotonya ikut terhapus dan tidak bisa dikembalikan.')">
              <?= csrfField() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
              <button class="btn sm danger" type="submit">Hapus</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php adminFoot();
