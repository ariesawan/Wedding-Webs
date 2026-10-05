<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/image.php';
$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'save') {
            $id      = (int) ($_POST['id'] ?? 0);
            $title   = trim($_POST['title'] ?? '');
            if ($title === '') throw new RuntimeException('Judul artikel wajib diisi.');

            $content = sanitizeArticleHtml($_POST['content'] ?? '');
            $slug    = uniqueSlug('posts', slugify(trim($_POST['slug'] ?? '') ?: $title), $id ?: null);
            $status  = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
            $excerpt = trim($_POST['excerpt'] ?? '') ?: excerptFrom($content, 220);
            $metaDsc = trim($_POST['meta_description'] ?? '') ?: excerptFrom($content, 155);

            $data = [
                'title'            => $title,
                'slug'             => $slug,
                'excerpt'          => mb_substr($excerpt, 0, 400),
                'content'          => $content,
                'cover_alt'        => trim($_POST['cover_alt'] ?? ''),
                'category_id'      => ($_POST['category_id'] ?? '') !== '' ? (int) $_POST['category_id'] : null,
                'tags'             => trim($_POST['tags'] ?? ''),
                'meta_title'       => mb_substr(trim($_POST['meta_title'] ?? ''), 0, 190),
                'meta_description' => mb_substr($metaDsc, 0, 255),
                'focus_keyword'    => trim($_POST['focus_keyword'] ?? ''),
                'noindex'          => isset($_POST['noindex']) ? 1 : 0,
                'status'           => $status,
                'author_id'        => $user['id'],
            ];

            // published_at diisi sekali saat pertama kali terbit, tidak ditimpa saat diedit ulang.
            $existing = $id ? one("SELECT published_at, cover FROM posts WHERE id = ?", [$id]) : null;
            $data['published_at'] = $status === 'published'
                ? ($existing['published_at'] ?? date('Y-m-d H:i:s'))
                : ($existing['published_at'] ?? null);

            $audit = seoAudit(array_merge($data, ['content' => $content, 'cover' => $existing['cover'] ?? null]));
            $data['seo_score'] = $audit['score'];

            if ($id) {
                $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
                q("UPDATE posts SET $set WHERE id = ?", [...array_values($data), $id]);
            } else {
                $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
                q("INSERT INTO posts ($cols) VALUES (" . implode(',', array_fill(0, count($data), '?')) . ")", array_values($data));
                $id = insertId();
            }

            if (!empty($_FILES['cover']['name'])) {
                $old = one("SELECT cover FROM posts WHERE id = ?", [$id])['cover'] ?? null;
                if ($old) deleteImageSet(DIR_UPLOAD . '/blog', $old);
                saveCoverImage($_FILES['cover'], DIR_UPLOAD . '/blog', $slug);
                q("UPDATE posts SET cover = ? WHERE id = ?", [$slug, $id]);
            }

            flash($status === 'published' ? 'Artikel diterbitkan.' : 'Draf disimpan.');
            redirect('admin/blog.php?edit=' . $id);
        }

        elseif ($act === 'delete') {
            $id  = (int) $_POST['id'];
            $row = one("SELECT cover FROM posts WHERE id = ?", [$id]);
            if ($row && $row['cover']) deleteImageSet(DIR_UPLOAD . '/blog', $row['cover']);
            q("DELETE FROM posts WHERE id = ?", [$id]);
            flash('Artikel dihapus.');
        }
        elseif ($act === 'toggle') {
            $id = (int) $_POST['id'];
            $p  = one("SELECT status, published_at FROM posts WHERE id = ?", [$id]);
            if ($p['status'] === 'published') {
                q("UPDATE posts SET status = 'draft' WHERE id = ?", [$id]);
                flash('Artikel dikembalikan ke draf.');
            } else {
                q("UPDATE posts SET status = 'published', published_at = COALESCE(published_at, NOW()) WHERE id = ?", [$id]);
                flash('Artikel diterbitkan.');
            }
        }
        elseif ($act === 'upload_inline') {
            // Upload gambar dari dalam editor -> kembalikan URL sebagai JSON
            header('Content-Type: application/json');
            try {
                $slug = 'inline-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
                saveCoverImage($_FILES['file'], DIR_UPLOAD . '/blog', $slug);
                echo json_encode(['ok' => true, 'url' => url('uploads/blog/' . $slug . '.jpg')]);
            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            exit;
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    if ($act !== 'save') redirect('admin/blog.php');
}

$cats = all("SELECT * FROM categories ORDER BY name");
$edit = isset($_GET['edit']) ? one("SELECT * FROM posts WHERE id = ?", [(int) $_GET['edit']]) : null;
$isNew = isset($_GET['new']);

adminHead('Artikel', 'blog');

if ($edit || $isNew):
    $v = fn(string $k, $d = '') => e($edit[$k] ?? $d);
    $audit = $edit ? seoAudit($edit) : ['score' => 0, 'checks' => []];
    $ringColor = $audit['score'] >= 80 ? 'var(--sage)' : ($audit['score'] >= 50 ? 'var(--ember)' : 'var(--rose)');
    pageHead($edit ? 'Ubah artikel' : 'Artikel baru', '', '<a class="btn ghost" href="blog.php">← Kembali</a>');
?>
<form method="post" enctype="multipart/form-data" id="postForm">
  <?= csrfField() ?>
  <input type="hidden" name="act" value="save">
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <input type="hidden" name="content" id="contentField">

  <div class="g-side">
    <div>
      <div class="card">
        <div class="field">
          <label for="title">Judul artikel</label>
          <input type="text" id="title" name="title" required value="<?= $v('title') ?>"
                 placeholder="7 Hal yang Wajib Ditanyakan ke Wedding Organizer Sebelum Deal">
        </div>
        <div class="field">
          <label for="slug">Slug URL</label>
          <input type="text" id="slug" name="slug" value="<?= $v('slug') ?>">
          <p class="hint"><code><?= e(BASE_URL) ?>/blog/<span id="slugPrev"><?= $v('slug', 'judul-artikel') ?></span></code></p>
        </div>

        <div class="tb" role="toolbar" aria-label="Alat penyunting">
          <button type="button" data-cmd="formatBlock" data-val="h2" title="Subjudul H2"><b>H2</b></button>
          <button type="button" data-cmd="formatBlock" data-val="h3" title="Subjudul H3"><b>H3</b></button>
          <button type="button" data-cmd="formatBlock" data-val="p" title="Paragraf">¶</button>
          <span class="sep"></span>
          <button type="button" data-cmd="bold" title="Tebal"><b>B</b></button>
          <button type="button" data-cmd="italic" title="Miring"><i>I</i></button>
          <button type="button" data-cmd="underline" title="Garis bawah"><u>U</u></button>
          <span class="sep"></span>
          <button type="button" data-cmd="insertUnorderedList" title="Daftar butir">• Daftar</button>
          <button type="button" data-cmd="insertOrderedList" title="Daftar bernomor">1. Nomor</button>
          <button type="button" data-cmd="formatBlock" data-val="blockquote" title="Kutipan">❝</button>
          <span class="sep"></span>
          <button type="button" id="btnLink" title="Sisipkan tautan">🔗 Tautan</button>
          <button type="button" id="btnImg" title="Sisipkan gambar">🖼 Gambar</button>
          <span class="sep"></span>
          <button type="button" data-cmd="removeFormat" title="Bersihkan format">⌫ Bersihkan</button>
        </div>
        <div class="ed" id="editor" contenteditable="true" spellcheck="true"
             data-ph="Tulis artikelnya di sini. Pakai H2 untuk memecah bagian — mesin pencari dan pembaca sama-sama terbantu."><?= $edit['content'] ?? '' ?></div>
        <input type="file" id="inlineFile" accept="image/*" hidden>
        <p class="hint" style="margin-top:9px"><span id="wc">0</span> kata · sekitar <span id="rt">1</span> menit baca</p>
      </div>

      <div class="card">
        <h2>Ringkasan &amp; SEO</h2>
        <div class="field">
          <label for="excerpt">Ringkasan <span class="counter" id="cEx"></span></label>
          <textarea id="excerpt" name="excerpt" rows="3" maxlength="400" placeholder="Kosongkan untuk dibuat otomatis dari paragraf pertama."><?= $v('excerpt') ?></textarea>
        </div>
        <div class="row c2">
          <div class="field">
            <label for="fk">Kata kunci fokus</label>
            <input type="text" id="fk" name="focus_keyword" value="<?= $v('focus_keyword') ?>" placeholder="wedding organizer jogja">
            <p class="hint">Satu frasa yang ingin diperingkatkan. Dipakai untuk menilai artikel di panel kanan.</p>
          </div>
          <div class="field">
            <label for="cat">Kategori</label>
            <select id="cat" name="category_id">
              <option value="">— tanpa kategori —</option>
              <?php foreach ($cats as $c): ?>
                <option value="<?= $c['id'] ?>" <?= (int) ($edit['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="field">
          <label for="mt">Judul SEO <span class="counter" id="cMt"></span></label>
          <input type="text" id="mt" name="meta_title" maxlength="190" value="<?= $v('meta_title') ?>">
          <p class="hint">Panjang ideal 45–60 karakter. Kosongkan untuk memakai judul artikel.</p>
        </div>
        <div class="field">
          <label for="md">Meta description <span class="counter" id="cMd"></span></label>
          <textarea id="md" name="meta_description" rows="3" maxlength="255"><?= $v('meta_description') ?></textarea>
          <p class="hint">Panjang ideal 120–158 karakter. Inilah kalimat yang dibaca orang di hasil pencarian.</p>
        </div>
        <div class="field">
          <label for="tg">Tag</label>
          <input type="text" id="tg" name="tags" value="<?= $v('tags') ?>" placeholder="wedding organizer, jogja, adat jawa">
          <p class="hint">Pisahkan dengan koma.</p>
        </div>

        <span class="lab">Pratinjau hasil pencarian Google</span>
        <div class="serp" style="margin-top:9px">
          <div class="u"><?= e(preg_replace('#^https?://#', '', BASE_URL)) ?> › blog › <span id="pvSlug"></span></div>
          <div class="t" id="pvTitle">Judul artikel</div>
          <div class="d" id="pvDesc">Meta description akan muncul di sini.</div>
        </div>
      </div>
    </div>

    <div>
      <div class="card">
        <h2>Penilaian SEO</h2>
        <div class="seo-meter">
          <div class="seo-ring" style="border:3px solid <?= $ringColor ?>;color:<?= $ringColor ?>"><?= $audit['score'] ?></div>
          <p class="sub" style="margin:0">Dinilai ulang setiap kali artikel disimpan.</p>
        </div>
        <?php if ($audit['checks']): ?>
          <ul class="seo-list">
            <?php foreach ($audit['checks'] as [$label, $pass, $meta]): ?>
              <li class="<?= $pass ? 'yes' : 'no' ?>"><b><?= e($label) ?></b><?php if ($meta): ?><span class="m"><?= e($meta) ?></span><?php endif; ?></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="sub" style="margin:0">Simpan draf sekali untuk melihat penilaiannya.</p>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2>Sampul</h2>
        <?php if (!empty($edit['cover'])): ?>
          <img src="<?= url('uploads/blog/' . $edit['cover'] . '-thumb.jpg') ?>" alt=""
               style="width:100%;border-radius:9px;margin-bottom:13px;aspect-ratio:16/9;object-fit:cover">
        <?php endif; ?>
        <div class="field"><input type="file" name="cover" accept="image/*"></div>
        <div class="field"><label for="ca">Teks alternatif</label><input type="text" id="ca" name="cover_alt" value="<?= $v('cover_alt') ?>"></div>
      </div>

      <div class="card">
        <h2>Publikasi</h2>
        <div class="field">
          <label for="st">Status</label>
          <select id="st" name="status">
            <option value="draft" <?= ($edit['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draf</option>
            <option value="published" <?= ($edit['status'] ?? '') === 'published' ? 'selected' : '' ?>>Terbit</option>
          </select>
        </div>
        <label class="check"><input type="checkbox" name="noindex" <?= !empty($edit['noindex']) ? 'checked' : '' ?>>
          Jangan indeks di mesin pencari</label>
        <?php if ($edit && $edit['published_at']): ?>
          <p class="hint">Terbit <?= tanggalID($edit['published_at'], true) ?> · <?= (int) $edit['views'] ?> kali dibaca</p>
        <?php endif; ?>
        <div class="sticky-actions">
          <button class="btn solid" type="submit">Simpan</button>
          <?php if ($edit && $edit['status'] === 'published'): ?>
            <a class="btn ghost" href="<?= url('blog/' . $edit['slug']) ?>" target="_blank" rel="noopener">Lihat ↗</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</form>

<script>
(() => {
  const ed = document.getElementById('editor');
  const form = document.getElementById('postForm');
  const $ = id => document.getElementById(id);

  // ---- toolbar ----
  document.querySelectorAll('.tb [data-cmd]').forEach(b => {
    b.addEventListener('click', () => {
      ed.focus();
      document.execCommand(b.dataset.cmd, false, b.dataset.val || null);
      sync();
    });
  });

  $('btnLink').addEventListener('click', () => {
    const u = prompt('Alamat tautan (https://...)');
    if (!u) return;
    ed.focus();
    document.execCommand('createLink', false, u);
    sync();
  });

  // ---- sisip gambar via upload ----
  $('btnImg').addEventListener('click', () => $('inlineFile').click());
  $('inlineFile').addEventListener('change', async e => {
    const f = e.target.files[0];
    if (!f) return;
    const alt = prompt('Teks alternatif gambar (untuk SEO & pembaca layar):', '') || '';
    const fd = new FormData();
    fd.append('act', 'upload_inline');
    fd.append('_csrf', form.querySelector('[name=_csrf]').value);
    fd.append('file', f);
    try {
      const r = await fetch('blog.php', { method: 'POST', body: fd });
      const j = await r.json();
      if (!j.ok) return alert('Gagal: ' + j.error);
      ed.focus();
      document.execCommand('insertHTML', false,
        `<figure><img src="${j.url}" alt="${alt.replace(/"/g, '&quot;')}" loading="lazy"></figure><p><br></p>`);
      sync();
    } catch (err) { alert('Gagal mengunggah gambar.'); }
    e.target.value = '';
  });

  // ---- slug otomatis dari judul ----
  const slugify = s => s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 90);

  const isNew = !form.querySelector('[name=id]').value || form.querySelector('[name=id]').value === '0';
  $('title').addEventListener('input', () => {
    if (isNew && !$('slug').dataset.touched) $('slug').value = slugify($('title').value);
    sync();
  });
  $('slug').addEventListener('input', () => { $('slug').dataset.touched = '1'; sync(); });

  // ---- penghitung karakter ----
  const counter = (input, el, min, max) => {
    const n = input.value.length;
    el.textContent = n + '/' + max;
    el.className = 'counter ' + (n === 0 ? '' : n < min ? 'warn' : n > max ? 'bad' : 'ok');
  };

  function sync() {
    const title = $('mt').value || $('title').value || 'Judul artikel';
    $('pvTitle').textContent = title.length > 60 ? title.slice(0, 59) + '…' : title;
    $('pvSlug').textContent  = $('slug').value || 'judul-artikel';
    $('slugPrev').textContent = $('slug').value || 'judul-artikel';
    const d = $('md').value || $('excerpt').value || 'Meta description akan muncul di sini.';
    $('pvDesc').textContent = d.length > 158 ? d.slice(0, 157) + '…' : d;

    counter($('mt'), $('cMt'), 45, 60);
    counter($('md'), $('cMd'), 120, 158);
    counter($('excerpt'), $('cEx'), 60, 400);

    const words = ed.innerText.trim().split(/\s+/).filter(Boolean).length;
    $('wc').textContent = words;
    $('rt').textContent = Math.max(1, Math.ceil(words / 200));
  }

  ['input', 'keyup', 'paste'].forEach(ev => {
    ed.addEventListener(ev, () => setTimeout(sync, 0));
    ['mt', 'md', 'excerpt'].forEach(id => $(id).addEventListener(ev, sync));
  });

  // Tempel sebagai teks polos supaya format Word/Docs tidak ikut masuk & merusak HTML.
  ed.addEventListener('paste', e => {
    e.preventDefault();
    const t = (e.clipboardData || window.clipboardData).getData('text/plain');
    document.execCommand('insertText', false, t);
  });

  form.addEventListener('submit', () => { $('contentField').value = ed.innerHTML; });
  sync();
})();
</script>

<?php else:
$fStatus = $_GET['status'] ?? '';
$where   = $fStatus === 'draft' ? "WHERE status='draft'" : ($fStatus === 'published' ? "WHERE status='published'" : '');
$fKat  = ($_GET['kat'] ?? '') !== '' ? (int) $_GET['kat'] : 0;
$fCari = trim($_GET['q'] ?? '');
$par = [];
$w = $where ? [substr($where, 6)] : [];
if ($fKat)  { $w[] = "p.category_id = ?"; $par[] = $fKat; }
if ($fCari) { $w[] = "(p.title LIKE ? OR p.excerpt LIKE ? OR p.tags LIKE ?)";
              array_push($par, "%$fCari%", "%$fCari%", "%$fCari%"); }
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$posts = all("SELECT p.*, c.name cat FROM posts p LEFT JOIN categories c ON c.id = p.category_id
              $where ORDER BY COALESCE(p.published_at, p.created_at) DESC", $par);
$totalPost = (int) (one("SELECT COUNT(*) c FROM posts")['c'] ?? 0);
pageHead('Artikel', 'Blog dengan meta tag, data terstruktur, dan sitemap yang dibuat otomatis.',
         '<a class="btn solid" href="?new=1">+ Tulis artikel</a>');
?>
<div class="tabs">
  <a href="blog.php" class="<?= $fStatus === '' ? 'on' : '' ?>">Semua</a>
  <a href="?status=published" class="<?= $fStatus === 'published' ? 'on' : '' ?>">Terbit</a>
  <a href="?status=draft" class="<?= $fStatus === 'draft' ? 'on' : '' ?>">Draf</a>
</div>

<form method="get" class="filterbar" style="margin-bottom:18px">
  <?php if ($fStatus): ?><input type="hidden" name="status" value="<?= e($fStatus) ?>"><?php endif; ?>
  <input type="text" name="q" value="<?= e($fCari) ?>" placeholder="Cari judul, ringkasan, atau tag">
  <select name="kat"><option value="">Semua kategori</option>
    <?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>" <?= $fKat === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
  <button class="btn sm" type="submit">Saring</button>
  <?php if ($fCari || $fKat): ?><a class="btn sm ghost" href="blog.php<?= $fStatus ? '?status=' . e($fStatus) : '' ?>">Bersihkan</a><?php endif; ?>
  <span class="hit"><?= count($posts) ?> dari <?= $totalPost ?></span>
</form>

<div class="card">
  <?php if (!$posts): ?>
    <div class="empty">
      <p>Belum ada artikel</p>
      <span>Blog adalah cara termurah muncul di pencarian Google untuk kata seperti “wedding organizer Jogja”. Mulai dari satu tulisan yang menjawab pertanyaan calon klien.</span>
      <a class="btn solid" href="?new=1">Tulis artikel pertama</a>
    </div>
  <?php else: ?>
    <table class="tbl">
      <thead><tr><th style="width:74px"></th><th>Judul</th><th>Kategori</th><th>SEO</th><th>Terbit</th><th>Dibaca</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($posts as $p):
        $sc = (int) $p['seo_score'];
        $cl = $sc >= 80 ? 'live' : ($sc >= 50 ? 'warn' : 'bad'); ?>
        <tr>
          <td><?php if ($p['cover']): ?><img class="thumb" src="<?= url('uploads/blog/' . $p['cover'] . '-thumb.jpg') ?>" alt="" loading="lazy"><?php else: ?><span class="muted mono">—</span><?php endif; ?></td>
          <td data-l="Judul"><b><?= e($p['title']) ?></b><br><span class="muted mono">/blog/<?= e($p['slug']) ?></span></td>
          <td data-l="Kategori"><?= e($p['cat'] ?? '—') ?></td>
          <td data-l="SEO"><span class="pill <?= $cl ?>"><?= $sc ?>/100</span></td>
          <td class="num" data-l="Terbit"><?= $p['published_at'] ? tanggalID($p['published_at']) : '<span class="pill draft">Draf</span>' ?></td>
          <td class="num" data-l="Dibaca"><?= (int) $p['views'] ?></td>
          <td class="actions">
            <a class="btn sm ghost" href="?edit=<?= (int) $p['id'] ?>">Ubah</a>
            <form method="post" style="display:inline"><?= csrfField() ?>
              <input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button class="btn sm ghost" type="submit"><?= $p['status'] === 'published' ? 'Jadikan draf' : 'Terbitkan' ?></button></form>
            <form method="post" style="display:inline" onsubmit="return confirm('Hapus artikel ini secara permanen?')"><?= csrfField() ?>
              <input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button class="btn sm danger" type="submit">Hapus</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php endif; adminFoot();
