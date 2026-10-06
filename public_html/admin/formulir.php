<?php
/**
 * ============================================================
 * FORMULIR MASUK — jejak setiap kiriman formulir publik
 * ============================================================
 *
 * Kiriman yang lolos menjadi klien otomatis. Yang tidak (ditolak penjagaan,
 * gagal validasi, galat database) tetap tercatat di sini lengkap dengan
 * isinya — admin early tinggal menjadikannya klien atau menghubungi lewat
 * WhatsApp. Tujuannya satu: tidak ada calon klien yang hilang tanpa jejak.
 */
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/formulir.php';
$user = requireLogin();

if (!in_array($user['role'] ?? '', ['owner', 'admin_early'], true)) {
    http_response_code(403);
    die('Formulir masuk dipegang admin early. <a href="index.php" style="color:#E9A85C">Kembali</a>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $id  = (int) ($_POST['id'] ?? 0);
    $act = $_POST['act'] ?? '';
    $f   = one("SELECT * FROM form_masuk WHERE id = ?", [$id]);
    try {
        if (!$f) throw new RuntimeException('Kiriman tidak ditemukan.');
        if ($act === 'jadikan') {
            if ($f['client_id']) redirect('admin/klien.php?id=' . (int) $f['client_id']);
            $isi = formIsi((array) json_decode((string) $f['payload'], true));
            if ($v = formValidasi($isi)) {
                throw new RuntimeException('Belum bisa dijadikan klien otomatis: ' . implode(' ', $v)
                    . ' Tambahkan manual dari menu Klien.');
            }
            $r = formSimpan($isi, (string) $f['ip'], (int) $user['id']);
            q("UPDATE form_masuk SET client_id = ?, ditangani = 1,
                 pesan = CONCAT(pesan, ' → dijadikan klien oleh ', ?) WHERE id = ?",
              [$r['id'], $user['name'] ?? 'admin', $id]);
            flash('Kiriman dijadikan klien. ' . $r['info']);
            redirect('admin/klien.php?id=' . $r['id']);
        }
        if ($act === 'abaikan') {
            q("UPDATE form_masuk SET ditangani = 1 WHERE id = ?", [$id]);
            flash('Ditandai sudah ditangani.');
        }
        if ($act === 'buka') {
            q("UPDATE form_masuk SET ditangani = 0 WHERE id = ?", [$id]);
            flash('Dikembalikan ke daftar perlu dicek.');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    redirect(kembaliRingkasan() ?? 'admin/formulir.php' . (isset($_GET['f']) ? '?f=' . urlencode((string) $_GET['f']) : ''));
}

$filter = $_GET['f'] ?? 'cek';
$perlu  = formPerluCek(30);
$ringkas = one("SELECT
    SUM(created_at >= CURDATE())                                  AS hari,
    SUM(created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND status IN ('tersimpan','ulang')) AS jadi7,
    SUM(created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND status = 'bot') AS bot7
  FROM form_masuk") ?: [];

$baris = match ($filter) {
    // client_lanjut: kiriman yang ditolak lalu dikirim ulang dengan benar —
    // orangnya sudah jadi klien, tombol "Jadikan klien" tidak perlu lagi.
    'semua' => all("SELECT " . FORM_KOLOM_DAFTAR . ", (SELECT g.client_id FROM form_masuk g
                                 WHERE g.wa = f.wa AND g.client_id IS NOT NULL AND g.id > f.id
                                 ORDER BY g.id LIMIT 1) AS client_lanjut
                    FROM form_masuk f WHERE f.status <> 'bot' ORDER BY f.id DESC LIMIT 200"),
    'bot'   => all("SELECT " . FORM_KOLOM_DAFTAR . " FROM form_masuk f WHERE f.status = 'bot' ORDER BY f.id DESC LIMIT 100"),
    default => $perlu,
};
if (!in_array($filter, ['semua', 'bot'], true)) $filter = 'cek';

$labelStatus = [
    'tersimpan' => ['Jadi klien', 'live'],
    'ulang'     => ['Klien lama diperbarui', 'live'],
    'ditolak'   => ['Ditolak', 'warn'],
    'galat'     => ['Galat', 'bad'],
    'bot'       => ['Bot', 'draft'],
];

adminHead('Formulir masuk', 'formulir');
pageHead('Formulir masuk',
         'Setiap kiriman formulir publik tercatat di sini — juga yang gagal. Kiriman yang belum jadi klien '
       . 'muncul di "Perlu dicek" supaya bisa ditindaklanjuti manual.',
         '<a class="btn ghost" href="' . e(setting('form_url', url('form'))) . '" target="_blank" rel="noopener">Buka formulir ↗</a>');
?>
  <div class="grid g3">
    <div class="stat"><span class="n"><?= (int) ($ringkas['hari'] ?? 0) ?></span><span class="d">Kiriman hari ini</span></div>
    <div class="stat"><span class="n"><?= (int) ($ringkas['jadi7'] ?? 0) ?></span><span class="d">Jadi klien · 7 hari</span></div>
    <div class="stat<?= $perlu ? ' accent' : '' ?>"><span class="n"><?= count($perlu) ?></span><span class="d">Perlu dicek</span></div>
  </div>

  <nav class="tabs">
    <a href="?f=cek" class="<?= $filter === 'cek' ? 'on' : '' ?>">Perlu dicek <span class="n"><?= count($perlu) ?></span></a>
    <a href="?f=semua" class="<?= $filter === 'semua' ? 'on' : '' ?>">Semua kiriman</a>
    <a href="?f=bot" class="<?= $filter === 'bot' ? 'on' : '' ?>">Tersaring bot <span class="n"><?= (int) ($ringkas['bot7'] ?? 0) ?></span></a>
  </nav>

  <?php if (!$baris): ?>
    <div class="card"><p class="muted" style="margin:0">
      <?= $filter === 'cek' ? 'Tidak ada kiriman yang tertinggal. Semua kiriman 30 hari terakhir sudah jadi klien atau sudah ditangani.' : 'Belum ada kiriman.' ?></p></div>
  <?php else: ?>
    <div class="card">
      <div style="overflow-x:auto"><table class="tbl" style="min-width:860px">
        <thead><tr><th>Waktu</th><th>Nama</th><th>WhatsApp</th><th>Paket</th><th>Status</th><th>Keterangan</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($baris as $f):
          [$lbl, $cls] = $labelStatus[$f['status']] ?? [$f['status'], 'draft'];
          $isi = (array) json_decode((string) $f['payload'], true); ?>
          <tr>
            <td style="white-space:nowrap"><?= e(date('d/m H:i', strtotime($f['created_at']))) ?></td>
            <td><b><?= e($f['nama'] ?: '—') ?></b>
              <details class="hint"><summary>isi</summary>
                <?php foreach (['tanggal' => 'Tanggal', 'tamu' => 'Tamu', 'kota' => 'Kota', 'venue' => 'Venue',
                                'budget' => 'Budget', 'email' => 'Email', 'ig' => 'Instagram', 'catatan' => 'Catatan'] as $k => $n):
                  if (empty($isi[$k])) continue; ?>
                  <div><?= $n ?>: <?= e($k === 'budget' ? rupiah((float) $isi[$k]) : (string) $isi[$k]) ?></div>
                <?php endforeach; ?>
              </details></td>
            <td style="white-space:nowrap"><?php if ($f['wa']): ?><a href="https://wa.me/<?= e(preg_replace('/\D/', '', $f['wa'])) ?>" target="_blank" rel="noopener"><?= e($f['wa']) ?></a><?php else: ?>—<?php endif; ?></td>
            <td><?= e($f['paket'] ?: '—') ?></td>
            <td><span class="pill <?= $cls ?>"><?= e($lbl) ?></span><?= $f['ditangani'] ? ' <span class="hint">· ditangani</span>' : '' ?></td>
            <td class="hint" style="max-width:280px"><?= e($f['pesan']) ?></td>
            <td style="white-space:nowrap">
              <?php if ($f['client_id'] || !empty($f['client_lanjut'])): ?>
                <a class="btn sm ghost" href="klien.php?id=<?= (int) ($f['client_id'] ?: $f['client_lanjut']) ?>">Buka klien</a>
                <?php if (!$f['client_id']): ?><div class="hint" style="margin-top:4px">dikirim ulang &amp; sudah jadi klien</div><?php endif; ?>
              <?php else: ?>
                <form method="post" style="display:inline"><?= csrfField() ?>
                  <input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><input type="hidden" name="act" value="jadikan">
                  <button class="btn sm solid" type="submit">Jadikan klien</button></form>
                <form method="post" style="display:inline"><?= csrfField() ?>
                  <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                  <input type="hidden" name="act" value="<?= $f['ditangani'] ? 'buka' : 'abaikan' ?>">
                  <button class="btn sm ghost" type="submit"><?= $f['ditangani'] ? 'Buka lagi' : 'Sudah ditangani' ?></button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  <?php endif; ?>
<?php adminFoot();
