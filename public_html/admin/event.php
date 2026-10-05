<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/image.php';
$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'save') {
            $id    = (int) ($_POST['id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $date  = trim($_POST['event_date'] ?? '');
            if ($title === '') throw new RuntimeException('Judul event wajib diisi.');
            if (!strtotime($date)) throw new RuntimeException('Tanggal event tidak valid.');

            $slug = trim($_POST['slug'] ?? '') ?: slugify($title . '-' . date('Y', strtotime($date)));
            $slug = uniqueSlug('events', slugify($slug), $id ?: null);

            $data = [
                'title'       => $title,
                'slug'        => $slug,
                'couple'      => trim($_POST['couple'] ?? ''),
                'event_date'  => date('Y-m-d H:i:s', strtotime($date)),
                'end_date'    => trim($_POST['end_date'] ?? '') ? date('Y-m-d H:i:s', strtotime($_POST['end_date'])) : null,
                'venue'       => trim($_POST['venue'] ?? ''),
                'city'        => trim($_POST['city'] ?? 'Yogyakarta'),
                'category'    => trim($_POST['category'] ?? 'Resepsi'),
                'guest_count' => ($_POST['guest_count'] ?? '') !== '' ? (int) $_POST['guest_count'] : null,
                'description' => trim($_POST['description'] ?? ''),
                'cover_alt'   => trim($_POST['cover_alt'] ?? ''),
                'is_featured' => isset($_POST['is_featured']) ? 1 : 0,
                'is_published'=> isset($_POST['is_published']) ? 1 : 0,
                'meta_title'  => trim($_POST['meta_title'] ?? ''),
                'meta_description' => trim($_POST['meta_description'] ?? ''),
            ];

            if ($id) {
                $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
                q("UPDATE events SET $set WHERE id = ?", [...array_values($data), $id]);
            } else {
                $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
                $ph   = implode(', ', array_fill(0, count($data), '?'));
                q("INSERT INTO events ($cols) VALUES ($ph)", array_values($data));
                $id = insertId();
            }

            // Sampul: nama berkas mengikuti slug, jadi ganti slug = ganti nama berkas juga.
            if (!empty($_FILES['cover']['name'])) {
                $old = one("SELECT cover FROM events WHERE id = ?", [$id])['cover'] ?? null;
                if ($old) deleteImageSet(DIR_UPLOAD . '/event', $old);
                saveCoverImage($_FILES['cover'], DIR_UPLOAD . '/event', $slug);
                q("UPDATE events SET cover = ? WHERE id = ?", [$slug, $id]);
            }
            flash('Event tersimpan.');
            redirect('admin/event.php?edit=' . $id);
        }

        elseif ($act === 'delete') {
            $id  = (int) $_POST['id'];
            $row = one("SELECT cover FROM events WHERE id = ?", [$id]);
            if ($row && $row['cover']) deleteImageSet(DIR_UPLOAD . '/event', $row['cover']);
            q("UPDATE gallery SET event_id = NULL WHERE event_id = ?", [$id]);
            q("DELETE FROM events WHERE id = ?", [$id]);
            flash('Event dihapus.');
        }
        elseif ($act === 'toggle') {
            q("UPDATE events SET is_published = 1 - is_published WHERE id = ?", [(int) $_POST['id']]);
            flash('Status tampil diperbarui.');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    if ($act !== 'save') redirect('admin/event.php');
}

$edit     = isset($_GET['edit']) ? one("SELECT * FROM events WHERE id = ?", [(int) $_GET['edit']]) : null;
$isNew    = isset($_GET['new']);
$fKat  = trim($_GET['kat'] ?? '');
$fKota = trim($_GET['kota'] ?? '');
$fSt   = in_array($_GET['st'] ?? '', ['on','off'], true) ? $_GET['st'] : '';
$fCari = trim($_GET['q'] ?? '');

$w = []; $par = [];
if ($fKat)  { $w[] = "category = ?"; $par[] = $fKat; }
if ($fKota) { $w[] = "city = ?";     $par[] = $fKota; }
if ($fSt)   { $w[] = "is_published = ?"; $par[] = $fSt === 'on' ? 1 : 0; }
if ($fCari) { $w[] = "(title LIKE ? OR couple LIKE ? OR venue LIKE ?)";
              array_push($par, "%$fCari%", "%$fCari%", "%$fCari%"); }
$extra = $w ? ' AND ' . implode(' AND ', $w) : '';

$upcoming = all("SELECT * FROM events WHERE event_date >= NOW() $extra ORDER BY event_date ASC", $par);
$past     = all("SELECT * FROM events WHERE event_date <  NOW() $extra ORDER BY event_date DESC LIMIT 60", $par);
$kataKat  = array_column(all("SELECT DISTINCT category FROM events WHERE category <> '' ORDER BY category"), 'category');
$kotaAda  = array_column(all("SELECT DISTINCT city FROM events WHERE city <> '' ORDER BY city"), 'city');

adminHead('Event', 'event');

if ($edit || $isNew):
    $v = fn(string $k, $d = '') => e($edit[$k] ?? $d);
    pageHead($edit ? 'Ubah event' : 'Event baru',
        'Event dengan tanggal ke depan otomatis muncul di panel "Yang sedang disiapkan". Lewat tanggalnya, ia pindah sendiri ke "Yang sudah kami pegang".',
        '<a class="btn ghost" href="event.php">← Kembali</a>');
?>
<form method="post" enctype="multipart/form-data">
  <?= csrfField() ?>
  <input type="hidden" name="act" value="save">
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

  <div class="grid g2" style="align-items:start">
    <div class="card">
      <h2>Isi event</h2>
      <div class="field"><label for="t">Judul</label>
        <input type="text" id="t" name="title" required value="<?= $v('title') ?>" placeholder="Resepsi Winda &amp; Zakki di Royal Ambarrukmo"></div>
      <div class="row c2">
        <div class="field"><label for="c">Pasangan</label><input type="text" id="c" name="couple" value="<?= $v('couple') ?>" placeholder="Winda &amp; Zakki"></div>
        <div class="field"><label for="cat">Kategori</label>
          <?php $cats = ['Resepsi','Akad','Akad & Resepsi','Engagement','Siraman','Prewedding','Adat Jawa','Intimate']; ?>
          <select id="cat" name="category">
            <?php foreach ($cats as $c): ?>
              <option value="<?= e($c) ?>" <?= ($edit['category'] ?? 'Resepsi') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="row c2">
        <div class="field"><label for="d1">Mulai</label>
          <input type="datetime-local" id="d1" name="event_date" required
                 value="<?= $edit ? date('Y-m-d\TH:i', strtotime($edit['event_date'])) : '' ?>"></div>
        <div class="field"><label for="d2">Selesai (opsional)</label>
          <input type="datetime-local" id="d2" name="end_date"
                 value="<?= !empty($edit['end_date']) ? date('Y-m-d\TH:i', strtotime($edit['end_date'])) : '' ?>"></div>
      </div>
      <div class="row c3">
        <div class="field"><label for="ven">Venue</label><input type="text" id="ven" name="venue" value="<?= $v('venue') ?>" placeholder="Royal Ambarrukmo"></div>
        <div class="field"><label for="ci">Kota</label><input type="text" id="ci" name="city" value="<?= $v('city', 'Yogyakarta') ?>"></div>
        <div class="field"><label for="gc">Jumlah tamu</label><input type="number" id="gc" name="guest_count" value="<?= $v('guest_count') ?>" min="0" step="25"></div>
      </div>
      <div class="field"><label for="desc">Deskripsi</label>
        <textarea id="desc" name="description" rows="6" placeholder="Satu-dua paragraf tentang acaranya: konsep, tantangan, apa yang membuatnya berkesan."><?= $v('description') ?></textarea></div>
    </div>

    <div>
      <div class="card">
        <h2>Sampul</h2>
        <?php if (!empty($edit['cover'])): ?>
          <img src="<?= url('uploads/event/' . $edit['cover'] . '-thumb.jpg') ?>" alt=""
               style="width:100%;border-radius:9px;margin-bottom:14px;aspect-ratio:16/9;object-fit:cover">
        <?php endif; ?>
        <div class="field"><label for="cov">Gambar sampul</label><input type="file" id="cov" name="cover" accept="image/*">
          <p class="hint">Rasio 16:9 paling rapi. Otomatis dikecilkan ke maks 1600px + WebP.</p></div>
        <div class="field"><label for="calt">Teks alternatif sampul</label><input type="text" id="calt" name="cover_alt" value="<?= $v('cover_alt') ?>"></div>
      </div>

      <div class="card">
        <h2>SEO</h2>
        <div class="field"><label for="mt">Judul SEO</label><input type="text" id="mt" name="meta_title" maxlength="190" value="<?= $v('meta_title') ?>">
          <p class="hint">Kosongkan untuk memakai judul event.</p></div>
        <div class="field"><label for="md">Meta description</label><textarea id="md" name="meta_description" rows="3" maxlength="255"><?= $v('meta_description') ?></textarea></div>
        <div class="field"><label for="sl">Slug URL</label><input type="text" id="sl" name="slug" value="<?= $v('slug') ?>">
          <p class="hint"><code><?= e(BASE_URL) ?>/event/<span id="slugPrev"><?= $v('slug', 'slug-event') ?></span></code></p></div>
      </div>

      <div class="card">
        <h2>Publikasi</h2>
        <label class="check"><input type="checkbox" name="is_published" <?= (!$edit || $edit['is_published']) ? 'checked' : '' ?>> Tampilkan di situs</label>
        <label class="check"><input type="checkbox" name="is_featured" <?= !empty($edit['is_featured']) ? 'checked' : '' ?>> Sorot di beranda</label>
        <div class="sticky-actions">
          <button class="btn solid" type="submit">Simpan event</button>
          <?php if ($edit): ?><a class="btn ghost" href="<?= url('event/' . $edit['slug']) ?>" target="_blank" rel="noopener">Lihat ↗</a><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</form>
<script>
document.getElementById('sl')?.addEventListener('input', e => {
  document.getElementById('slugPrev').textContent = e.target.value || 'slug-event';
});
</script>

<?php else:
pageHead('Event', 'Yang tampil di panel 2 dan 3 beranda. Perpindahan "akan datang" ke "sudah terselenggara" terjadi otomatis berdasarkan tanggal.',
         '<a class="btn solid" href="?new=1">+ Event baru</a>');

$render = function (array $rows, string $emptyTitle, string $emptyHint) { ?>
  <?php if (!$rows): ?>
    <div class="empty"><p><?= e($emptyTitle) ?></p><span><?= e($emptyHint) ?></span>
      <a class="btn solid" href="?new=1">Tambah event</a></div>
  <?php else: ?>
    <table class="tbl">
      <thead><tr><th style="width:74px"></th><th>Event</th><th>Tanggal</th><th>Venue</th><th>Tamu</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $ev): ?>
        <tr>
          <td><?php if ($ev['cover']): ?><img class="thumb" src="<?= url('uploads/event/' . $ev['cover'] . '-thumb.jpg') ?>" alt="" loading="lazy"><?php else: ?><span class="muted mono">—</span><?php endif; ?></td>
          <td data-l="Event"><b><?= e($ev['title']) ?></b><?php if ($ev['couple']): ?><br><span class="muted mono"><?= e($ev['couple']) ?></span><?php endif; ?></td>
          <td class="num" data-l="Tanggal"><?= tanggalID($ev['event_date']) ?><br><span class="muted"><?= hariID($ev['event_date']) ?>, <?= date('H.i', strtotime($ev['event_date'])) ?></span></td>
          <td data-l="Venue"><?= e($ev['venue'] ?: '—') ?><br><span class="muted mono"><?= e($ev['city']) ?></span></td>
          <td class="num" data-l="Tamu"><?= $ev['guest_count'] ? number_format((int) $ev['guest_count'], 0, ',', '.') : '—' ?></td>
          <td data-l="Status">
            <span class="pill dot <?= $ev['is_published'] ? 'live' : 'draft' ?>"><?= $ev['is_published'] ? 'Tampil' : 'Tersembunyi' ?></span>
            <?php if ($ev['is_featured']): ?><br><span class="pill warn" style="margin-top:5px">Disorot</span><?php endif; ?>
          </td>
          <td class="actions">
            <a class="btn sm ghost" href="?edit=<?= (int) $ev['id'] ?>">Ubah</a>
            <form method="post" style="display:inline"><?= csrfField() ?>
              <input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int) $ev['id'] ?>">
              <button class="btn sm ghost" type="submit"><?= $ev['is_published'] ? 'Sembunyikan' : 'Tampilkan' ?></button></form>
            <form method="post" style="display:inline" onsubmit="return confirm('Hapus event ini?')"><?= csrfField() ?>
              <input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int) $ev['id'] ?>">
              <button class="btn sm danger" type="submit">Hapus</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif;
};
?>
<form method="get" class="filterbar" style="margin-bottom:18px">
  <input type="text" name="q" value="<?= e($fCari) ?>" placeholder="Cari judul, pasangan, atau venue">
  <select name="kat"><option value="">Semua kategori</option>
    <?php foreach ($kataKat as $k): ?><option value="<?= e($k) ?>" <?= $fKat === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?></select>
  <select name="kota"><option value="">Semua kota</option>
    <?php foreach ($kotaAda as $k): ?><option value="<?= e($k) ?>" <?= $fKota === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?></select>
  <select name="st"><option value="">Semua status</option>
    <option value="on"  <?= $fSt === 'on'  ? 'selected' : '' ?>>Tampil</option>
    <option value="off" <?= $fSt === 'off' ? 'selected' : '' ?>>Tersembunyi</option></select>
  <button class="btn sm" type="submit">Saring</button>
  <?php if ($fCari || $fKat || $fKota || $fSt): ?><a class="btn sm ghost" href="event.php">Bersihkan</a><?php endif; ?>
  <span class="hit"><?= count($upcoming) + count($past) ?> event</span>
</form>

<div class="card">
  <h2>Yang sedang disiapkan <span class="lab" style="margin-left:8px"><?= count($upcoming) ?> EVENT</span></h2>
  <p class="sub">Tanggalnya masih di depan — tampil di panel 2 beranda.</p>
  <?php $render($upcoming, 'Belum ada event mendatang', 'Tambahkan acara yang sudah terkonfirmasi tanggalnya. Panel beranda ikut kosong selama daftar ini kosong.'); ?>
</div>

<div class="card">
  <h2>Yang sudah kami pegang <span class="lab" style="margin-left:8px"><?= count($past) ?> EVENT</span></h2>
  <p class="sub">Tanggalnya sudah lewat — tampil di panel 3 beranda sebagai rekam jejak.</p>
  <?php $render($past, 'Belum ada event yang tercatat', 'Isi acara-acara yang sudah pernah dipegang. Ini yang paling sering dibaca calon klien.'); ?>
</div>
<?php endif; adminFoot();
