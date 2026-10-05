<?php
/**
 * ============================================================
 * PENGGUNA PANEL
 * ============================================================
 *
 * Halaman yang selama ini tidak ada: owner tidak punya cara membuat akun
 * admin. Satu-satunya pembuatan akun adalah setup.php, dan berkas itu
 * menolak jalan begitu tabel users terisi — jadi setelah instalasi pertama,
 * jumlah akun terkunci selamanya di satu.
 *
 * Khusus owner. Penjagaannya berlapis: requireLogin() menolak lewat
 * AKSES_PERAN (pengguna.php tidak ada di daftar peran mana pun selain
 * owner), requireOwner() menolak sekali lagi di sini. Berlebihan memang,
 * tapi halaman yang bisa membuat akun owner baru pantas dijaga dua kali.
 */
require_once __DIR__ . '/_layout.php';
$user = requireOwner();

/** Jumlah owner aktif. Dipakai untuk mencegah owner terakhir dilucuti. */
function ownerAktif(?int $kecuali = null): int
{
    $sql = "SELECT COUNT(*) c FROM users WHERE role = 'owner' AND is_active = 1";
    $par = [];
    if ($kecuali) { $sql .= " AND id <> ?"; $par[] = $kecuali; }
    return (int) (one($sql, $par)['c'] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'tambah') {
            $nama  = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $peran = $_POST['role'] ?? '';
            $pass  = $_POST['password'] ?? '';

            if ($nama === '')                                   throw new RuntimeException('Nama wajib diisi.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL))     throw new RuntimeException('Email tidak valid.');
            if (!isset(ROLE_LABEL[$peran]) || $peran === 'editor') throw new RuntimeException('Peran tidak dikenal.');
            if (strlen($pass) < 12)                             throw new RuntimeException('Kata sandi minimal 12 karakter.');
            if (one("SELECT id FROM users WHERE email = ?", [$email]))
                throw new RuntimeException('Email itu sudah dipakai akun lain.');

            q("INSERT INTO users (name, email, password_hash, role) VALUES (?,?,?,?)",
              [$nama, $email, password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $peran]);
            logAudit((int) $user['id'], 'user_tambah', $email, 'Peran: ' . $peran);
            flash('Akun ' . $nama . ' dibuat sebagai ' . roleLabel($peran) . '.');
        }

        elseif ($act === 'peran') {
            $uid   = (int) ($_POST['uid'] ?? 0);
            $peran = $_POST['role'] ?? '';
            if (!isset(ROLE_LABEL[$peran])) throw new RuntimeException('Peran tidak dikenal.');

            $t = one("SELECT * FROM users WHERE id = ?", [$uid]);
            if (!$t) throw new RuntimeException('Akun tidak ditemukan.');

            // Owner terakhir tidak boleh menurunkan dirinya sendiri. Kalau
            // sampai nol owner, tidak ada lagi yang bisa membuka halaman ini,
            // dan setup.php sudah menolak jalan karena tabelnya tidak kosong.
            if ($t['role'] === 'owner' && $peran !== 'owner' && ownerAktif($uid) === 0)
                throw new RuntimeException('Ini owner aktif terakhir. Angkat owner lain dulu sebelum menurunkan yang ini.');

            q("UPDATE users SET role = ? WHERE id = ?", [$peran, $uid]);
            logAudit((int) $user['id'], 'user_peran', $t['email'], $t['role'] . ' → ' . $peran);
            flash('Peran ' . $t['name'] . ' diubah jadi ' . roleLabel($peran) . '.');
        }

        elseif ($act === 'aktif') {
            $uid = (int) ($_POST['uid'] ?? 0);
            $t   = one("SELECT * FROM users WHERE id = ?", [$uid]);
            if (!$t) throw new RuntimeException('Akun tidak ditemukan.');
            if ($uid === (int) $user['id']) throw new RuntimeException('Tidak bisa menonaktifkan akun sendiri.');
            if ($t['is_active'] && $t['role'] === 'owner' && ownerAktif($uid) === 0)
                throw new RuntimeException('Ini owner aktif terakhir.');

            $baru = $t['is_active'] ? 0 : 1;
            q("UPDATE users SET is_active = ? WHERE id = ?", [$baru, $uid]);
            logAudit((int) $user['id'], 'user_aktif', $t['email'], $baru ? 'diaktifkan' : 'dinonaktifkan');
            flash($t['name'] . ($baru ? ' diaktifkan.' : ' dinonaktifkan. Sesi yang sedang jalan ikut putus.'));
        }

        elseif ($act === 'sandi') {
            $uid  = (int) ($_POST['uid'] ?? 0);
            $pass = $_POST['password'] ?? '';
            if (strlen($pass) < 12) throw new RuntimeException('Kata sandi minimal 12 karakter.');
            $t = one("SELECT * FROM users WHERE id = ?", [$uid]);
            if (!$t) throw new RuntimeException('Akun tidak ditemukan.');

            q("UPDATE users SET password_hash = ? WHERE id = ?",
              [password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $uid]);
            // Kunci login per-IP dibersihkan: kalau sandinya di-reset karena
            // yang bersangkutan lupa, IP-nya hampir pasti sudah terkunci
            // gara-gara percobaan tadi — dan sandi baru pun akan ditolak.
            q("DELETE FROM login_attempts");
            logAudit((int) $user['id'], 'user_sandi', $t['email'], 'Direset owner');
            flash('Kata sandi ' . $t['name'] . ' diganti. Kunci login per-IP ikut dibersihkan.');
        }

        redirect('admin/pengguna.php');
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
        redirect('admin/pengguna.php');
    }
}

$daftar = all("SELECT * FROM users ORDER BY FIELD(role,'owner','admin_office','admin_early','editor'), name");

adminHead('Pengguna', 'pengguna');
pageHead('Pengguna panel',
         'Siapa boleh membuka apa. Berbeda dari pegangan klien — yang itu menandai '
       . 'klien sedang di tangan siapa, yang ini menentukan halaman mana yang terbuka.');
?>

<div class="card">
  <h2>Akun terdaftar <span class="lab" style="margin-left:8px"><?= count($daftar) ?></span></h2>

  <?php foreach ($daftar as $u): $sendiri = (int) $u['id'] === (int) $user['id']; ?>
    <div style="padding:15px 0;border-bottom:1px solid var(--ivory-07)">
      <div style="display:flex;gap:11px;align-items:baseline;flex-wrap:wrap">
        <b style="color:var(--ivory);font-size:14.5px"><?= e($u['name']) ?></b>
        <span class="pill" style="<?= $u['role'] === 'owner' ? 'border-color:var(--ember-line);color:var(--ember)' : 'border-color:var(--sage);color:var(--sage)' ?>">
          <?= e(roleLabel($u['role'])) ?></span>
        <?php if (!$u['is_active']): ?><span class="pill bad">Nonaktif</span><?php endif; ?>
        <?php if ($sendiri): ?><span class="hint" style="margin:0">— ini kamu</span><?php endif; ?>
      </div>
      <div class="mono" style="font-size:12px;color:var(--ivory-60);margin-top:4px">
        <?= e($u['email']) ?>
        <?php if ($u['last_login_at']): ?> · terakhir masuk <?= tanggalID(substr($u['last_login_at'], 0, 10)) ?><?php endif; ?>
      </div>

      <div style="display:flex;gap:9px;flex-wrap:wrap;align-items:flex-end;margin-top:11px">
        <form method="post" style="display:flex;gap:7px;align-items:flex-end">
          <?= csrfField() ?><input type="hidden" name="act" value="peran">
          <input type="hidden" name="uid" value="<?= (int) $u['id'] ?>">
          <div class="field" style="margin:0;min-width:165px"><label>Peran</label>
            <select name="role">
              <?php foreach (['owner','admin_early','admin_office'] as $r): ?>
                <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e(roleLabel($r)) ?></option>
              <?php endforeach; ?>
              <?php if ($u['role'] === 'editor'): ?><option value="editor" selected>Editor (lama)</option><?php endif; ?>
            </select></div>
          <button class="btn sm" type="submit">Ubah</button>
        </form>

        <details style="display:inline-block">
          <summary class="btn sm ghost" style="list-style:none">Ganti sandi</summary>
          <form method="post" style="display:flex;gap:7px;align-items:flex-end;margin-top:9px">
            <?= csrfField() ?><input type="hidden" name="act" value="sandi">
            <input type="hidden" name="uid" value="<?= (int) $u['id'] ?>">
            <div class="field" style="margin:0;min-width:220px"><label>Sandi baru</label>
              <input type="password" name="password" required minlength="12" autocomplete="new-password"></div>
            <button class="btn sm" type="submit">Simpan</button>
          </form>
        </details>

        <?php if (!$sendiri): ?>
          <form method="post" style="display:inline">
            <?= csrfField() ?><input type="hidden" name="act" value="aktif">
            <input type="hidden" name="uid" value="<?= (int) $u['id'] ?>">
            <button class="btn sm <?= $u['is_active'] ? 'danger ghost' : 'ghost' ?>" type="submit">
              <?= $u['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <h2>Tambah akun</h2>
  <p class="sub">Sandi minimal 12 karakter. Frasa panjang lebih kuat daripada kata pendek yang rumit.</p>
  <form method="post">
    <?= csrfField() ?><input type="hidden" name="act" value="tambah">
    <div class="row c2">
      <div class="field"><label for="n">Nama</label>
        <input type="text" id="n" name="name" required placeholder="Nama yang muncul di panel"></div>
      <div class="field"><label for="em">Email</label>
        <input type="email" id="em" name="email" required placeholder="early@callalily.party">
        <p class="hint" style="margin:6px 0 0">Dipakai untuk masuk dan memulihkan sandi.</p></div>
    </div>
    <div class="row c2">
      <div class="field"><label for="r">Peran</label>
        <select id="r" name="role">
          <option value="admin_early">Admin early</option>
          <option value="admin_office">Admin office</option>
          <option value="owner">Owner</option>
        </select></div>
      <div class="field"><label for="p">Kata sandi</label>
        <input type="password" id="p" name="password" required minlength="12" autocomplete="new-password"></div>
    </div>
    <button class="btn solid" type="submit">Buat akun</button>
  </form>
</div>

<div class="card">
  <h2>Wewenang tiap peran</h2>
  <p class="sub">Ditegakkan di <span class="mono">requireLogin()</span>, jadi ikut berlaku untuk kiriman POST —
    bukan sekadar menyembunyikan menu dari rail.</p>

  <?php
  $petaMenu = [
      'index' => 'Ringkasan', 'klien' => 'Klien', 'chat' => 'Chat', 'wa-sesi' => 'Sesi WA',
      'jadwal' => 'Jadwal', 'inbox' => 'Kotak masuk', 'penyusun' => 'Penyusun',
      'analisa' => 'Analisa', 'event' => 'Event', 'vendor' => 'Vendor',
      'vendor-kategori' => 'Kategori', 'galeri' => 'Galeri', 'blog' => 'Artikel',
      'seo' => 'SEO', 'bahasa' => 'Bahasa', 'sheet' => 'Spreadsheet',
      'integrasi' => 'Integrasi', 'pengaturan' => 'Pengaturan', 'pengguna' => 'Pengguna',
  ];
  ?>
  <div style="overflow-x:auto">
    <table class="tbl" style="min-width:420px">
      <thead><tr>
        <th>Halaman</th><th style="text-align:center">Early</th>
        <th style="text-align:center">Office</th><th style="text-align:center">Owner</th>
      </tr></thead>
      <tbody>
      <?php foreach ($petaMenu as $k => $label): ?>
        <tr>
          <td><?= e($label) ?></td>
          <?php foreach (['admin_early','admin_office','owner'] as $r): ?>
            <td style="text-align:center;color:<?= bolehAkses($r, $k) ? 'var(--sage)' : 'var(--ivory-38)' ?>">
              <?= bolehAkses($r, $k) ? '✓' : '·' ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="hint" style="margin-top:14px">Halaman <b>Klien</b> sengaja terbuka untuk dua-duanya.
    Early membutuhkannya untuk menyusun penawaran, office untuk melanjutkan data lengkap —
    yang membedakan bukan aksesnya, tapi panel mana yang relevan, dan itu sudah ditandai
    pill <b>Pegangan</b> di kartu perjalanan klien.</p>
</div>

<?php adminFoot();
