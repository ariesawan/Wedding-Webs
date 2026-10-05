<?php
/**
 * PENYUNTING TERJEMAHAN.
 *
 * Terjemahan bawaan yang saya tulis pasti tidak akan cocok seluruhnya —
 * prosa pemasaran itu suara, bukan sekadar arti. Halaman ini ada supaya
 * suara itu bisa diperbaiki sendiri, tanpa menyentuh kode dan tanpa
 * menunggu unggah ulang.
 *
 * Kalimat sumbernya dipindai langsung dari kode, jadi begitu ada kalimat
 * baru di beranda, kalimat itu otomatis muncul di sini menunggu diisi.
 * Kalimat yang sudah tidak ada di kode TIDAK dihapus — hanya ditandai
 * tidak terpakai, supaya suntingan yang sudah dibuat tidak hilang kalau
 * teksnya cuma dipindah sementara.
 */
require_once __DIR__ . '/_layout.php';

$user = requireLogin();
$lang = 'en';

/* ---------------- SIMPAN ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();

    if (($_POST['act'] ?? '') === 'pindai') {
        $ditemukan = kumpulkanKalimat();
        $bibitFile = __DIR__ . '/../inc/lang/en.php';
        $bibit     = is_file($bibitFile) ? (require $bibitFile) : [];

        q("UPDATE translations SET dipakai = 0 WHERE lang = ?", [$lang]);

        $baru = 0;
        foreach (array_keys($ditemukan) as $src) {
            $h = sha1($src);
            $ada = one("SELECT id FROM translations WHERE lang = ? AND src_hash = ?", [$lang, $h]);
            if ($ada) {
                q("UPDATE translations SET dipakai = 1, src = ? WHERE id = ?", [$src, $ada['id']]);
            } else {
                // Terjemahan bawaan dipakai sebagai isian awal — bukan dikunci.
                // Owner tinggal menimpanya kalau tidak cocok.
                q("INSERT INTO translations (lang, src_hash, src, dst, dipakai)
                   VALUES (?,?,?,?,1)", [$lang, $h, $src, $bibit[$src] ?? null]);
                $baru++;
            }
        }
        flash($baru > 0
            ? "Pemindaian selesai. $baru kalimat baru masuk daftar."
            : 'Pemindaian selesai. Tidak ada kalimat baru.');
        redirect('admin/bahasa.php');
    }

    if (($_POST['act'] ?? '') === 'simpan') {
        $n = 0;
        foreach ((array) ($_POST['dst'] ?? []) as $id => $nilai) {
            $id = (int) $id;
            if ($id <= 0) continue;
            $nilai = trim((string) $nilai);
            q("UPDATE translations SET dst = ?, updated_by = ? WHERE id = ? AND lang = ?",
              [$nilai !== '' ? $nilai : null, $user['id'], $id, $lang]);
            $n++;
        }
        flash("$n baris disimpan.");
        redirect('admin/bahasa.php' . (!empty($_POST['q']) ? '?q=' . urlencode($_POST['q']) : '')
                 . (!empty($_POST['f']) ? (!empty($_POST['q']) ? '&' : '?') . 'f=' . urlencode($_POST['f']) : ''));
    }
}

/* ---------------- BACA ---------------- */
$cari   = trim($_GET['q'] ?? '');
$filter = $_GET['f'] ?? 'semua';

$w = ['lang = ?']; $p = [$lang];
if ($filter === 'kosong')  $w[] = "(dst IS NULL OR dst = '')";
if ($filter === 'terisi')  $w[] = "(dst IS NOT NULL AND dst <> '')";
if ($filter === 'nonaktif') $w[] = 'dipakai = 0';
else                        $w[] = 'dipakai = 1';
if ($cari !== '') { $w[] = '(src LIKE ? OR dst LIKE ?)'; $p[] = "%$cari%"; $p[] = "%$cari%"; }

$baris = [];
$stat  = ['total' => 0, 'terisi' => 0];
try {
    $baris = all("SELECT * FROM translations WHERE " . implode(' AND ', $w)
               . " ORDER BY CHAR_LENGTH(src), src LIMIT 400", $p);
    $stat = one("SELECT COUNT(*) total, SUM(dst IS NOT NULL AND dst <> '') terisi
                 FROM translations WHERE lang = ? AND dipakai = 1", [$lang]) ?: $stat;
} catch (Throwable $e) {
    flash('Tabel terjemahan belum ada. Jalankan migration-v12.sql dulu.', 'err');
}

$persen = $stat['total'] > 0 ? round($stat['terisi'] / $stat['total'] * 100) : 0;

adminHead('Bahasa', 'bahasa');
pageHead('Terjemahan Inggris',
    'Teks Indonesia dipindai langsung dari kode. Kosongkan sebuah kolom untuk kembali memakai terjemahan bawaan.',
    '<form method="post" style="display:inline">' . csrfField()
  . '<input type="hidden" name="act" value="pindai">'
  . '<button class="btn ghost">Pindai ulang kalimat</button></form>');
?>

<div class="card">
  <div style="display:flex;align-items:center;gap:14px;margin-bottom:16px">
    <div style="flex:1;height:10px;background:var(--ivory-07);border-radius:5px;overflow:hidden">
      <div style="height:100%;width:<?= $persen ?>%;border-radius:5px;
                  background:<?= $persen >= 90 ? 'var(--sage)' : 'var(--ember)' ?>"></div>
    </div>
    <span class="mono" style="font-size:13px;color:var(--ivory)">
      <?= (int) $stat['terisi'] ?>/<?= (int) $stat['total'] ?> diterjemahkan
    </span>
  </div>

  <form method="get" style="display:flex;gap:9px;flex-wrap:wrap;align-items:center">
    <input type="text" name="q" value="<?= e($cari) ?>" placeholder="Cari kalimat…"
           style="flex:1;min-width:200px;padding:8px 11px;border-radius:9px;
                  border:1px solid var(--ivory-12);background:var(--ink-3);color:var(--ivory)">
    <?php foreach (['semua'=>'Semua','kosong'=>'Belum diisi','terisi'=>'Sudah diisi','nonaktif'=>'Tak terpakai'] as $k=>$v): ?>
      <a class="btn sm <?= $filter === $k ? 'solid' : 'ghost' ?>"
         href="?f=<?= $k ?><?= $cari ? '&q=' . urlencode($cari) : '' ?>"><?= $v ?></a>
    <?php endforeach; ?>
  </form>
</div>

<?php if (!$baris): ?>
  <div class="card"><div class="empty" style="padding:34px 16px">
    <p>Belum ada kalimat</p>
    <span>Tekan <b>Pindai ulang kalimat</b> di atas untuk mengambilnya dari kode.</span>
  </div></div>
<?php else: ?>
<form method="post">
  <?= csrfField() ?>
  <input type="hidden" name="act" value="simpan">
  <input type="hidden" name="q" value="<?= e($cari) ?>">
  <input type="hidden" name="f" value="<?= e($filter) ?>">

  <div class="card">
    <?php foreach ($baris as $b):
      $panjang = mb_strlen($b['src']);
      $rows    = $panjang > 220 ? 4 : ($panjang > 90 ? 3 : 2); ?>
      <div style="padding:14px 0;border-bottom:1px solid var(--ivory-07)">
        <div style="font-size:13.4px;color:var(--ivory);line-height:1.55;margin-bottom:7px">
          <?= $b['src'] /* sengaja mentah: kalimatnya memang memuat <em>/<b> */ ?>
        </div>
        <textarea name="dst[<?= (int) $b['id'] ?>]" rows="<?= $rows ?>"
                  placeholder="Terjemahan Inggris — kosongkan untuk memakai bawaan"
                  style="width:100%;padding:9px 11px;border-radius:9px;font-size:13.4px;
                         border:1px solid <?= $b['dst'] ? 'var(--ivory-12)' : 'var(--ember-line)' ?>;
                         background:var(--ink-3);color:var(--ivory);font-family:inherit;
                         line-height:1.55;resize:vertical"><?= e((string) $b['dst']) ?></textarea>
      </div>
    <?php endforeach; ?>

    <div style="padding-top:16px;display:flex;gap:10px;align-items:center">
      <button class="btn solid" type="submit">Simpan terjemahan</button>
      <span class="muted" style="font-size:12.5px">
        Tag <span class="mono">&lt;em&gt;</span> dan <span class="mono">&lt;b&gt;</span> di teks sumber
        sebaiknya dipertahankan — itu yang mengatur penekanannya.
      </span>
    </div>
  </div>
</form>
<?php endif; ?>

<?php adminFoot();
