<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/wa.php';

function adminHead(string $title, string $active = ''): void
{
    $u = currentUser();

    // Lencana menu digabung jadi SATU query.
    // Sebelumnya ada lima query terpisah di sini — dua di antaranya SQL yang
    // persis sama ($followUp dan $overdue) — dan semuanya jalan di setiap
    // halaman panel, sebelum sebaris HTML pun keluar. Satu round-trip jauh
    // lebih murah daripada lima, terutama di shared hosting.
    // Lencana Klien mengikuti bagian masing-masing peran: admin office tidak
    // perlu melihat angka merah dari prospek yang bukan pegangannya.
    $lingkup = match ($u['role'] ?? '') {
        'admin_early'            => "stage IN ('baru','pricelist','spesifikasi','penawaran')",
        'admin_office', 'editor' => "stage IN ('deal','persiapan','harih')",
        default                  => "stage NOT IN ('selesai','batal')",
    };
    $b = one("SELECT
        (SELECT COUNT(*) FROM meetings WHERE status='scheduled' AND start_at >= NOW()) AS temu,
        (SELECT COUNT(*) FROM posts WHERE status='draft')                              AS draf,
        (SELECT COUNT(*) FROM clients
           WHERE $lingkup
             AND next_action_at IS NOT NULL AND next_action_at <= CURDATE())            AS telat,
        (SELECT COALESCE(SUM(unread),0) FROM wa_chats WHERE archived = 0)               AS chat,
        (SELECT COUNT(*) FROM clients c
           WHERE c.stage IN ('batal','selesai')
             AND NOT EXISTS (SELECT 1 FROM client_analisa a WHERE a.client_id = c.id)) AS analisa
    ") ?: [];

    $pendingMeetings = (int) ($b['temu']  ?? 0);
    $drafts          = (int) ($b['draf']  ?? 0);
    $overdue         = (int) ($b['telat'] ?? 0);
    // $b['chat'] masih dihitung karena wa_chats tetap dipakai halaman klien,
    // tapi tidak lagi jadi lencana rail: menu Chat dan Sesi WA dibuang.
    // wa-sesi.php sendiri tidak pernah ada berkasnya — menunya menunjuk 404
    // sejak awal.
    $belumAnalisa    = (int) ($b['analisa'] ?? 0);

    // Dikelompokkan menurut pekerjaan. Delapan belas menu dalam satu daftar
    // membuat yang dipakai setiap hari (Klien, Jadwal) tenggelam di antara
    // yang disentuh sebulan sekali (SEO, Bahasa, Integrasi).
    $menu = [
        // kunci, berkas, label, ikon, lencana, kelompok
        ['',           'index.php',      'Ringkasan',   '◈', 0,             ''],
        ['klien',      'klien.php',      'Klien',       '◐', $overdue,      ''],
        ['jadwal',     'jadwal.php',     'Jadwal',      '◷', $pendingMeetings, ''],
        ['inbox',      'inbox.php',      'Kotak masuk', '✉', 0,             ''],
        ['template-penawaran', 'template-penawaran.php', 'Template penawaran', '❏', 0, 'Penjualan'],
        ['analisa',    'analisa.php',    'Analisa',     '◭', $belumAnalisa, 'Penjualan'],
        ['vendor',     'vendor.php',     'Vendor',      '⌂', 0,             'Produksi'],
        ['vendor-kategori','vendor-kategori.php','Kategori vendor', '◇', 0, 'Produksi'],
        ['event',      'event.php',      'Event',       '❖', 0,             'Produksi'],
        ['penyusun',   'penyusun.php',   'Penyusun brief', '✎', 0,          'Situs'],
        ['galeri',     'galeri.php',     'Galeri',      '▣', 0,             'Situs'],
        ['blog',       'blog.php',       'Artikel',     '❋', $drafts,       'Situs'],
        ['seo',        'seo.php',        'SEO',         '◎', 0,             'Situs'],
        ['bahasa',     'bahasa.php',     'Bahasa',      '⇄', 0,             'Situs'],
        ['sheet',      'sheet.php',      'Spreadsheet', '▦', 0,             'Sistem'],
        ['integrasi',  'integrasi.php',  'Integrasi',   '⚯', 0,             'Sistem'],
        ['pengaturan', 'pengaturan.php', 'Pengaturan',  '⚙', 0,             'Sistem'],
        ['pengguna',   'pengguna.php',   'Pengguna',    '☖', 0,             'Sistem'],
    ];

    // Rail disaring menurut peran. Ini kosmetik saja — penjagaan yang
    // sesungguhnya ada di requireLogin(), yang berjalan sebelum penanganan
    // POST halaman mana pun. Menyembunyikan menu tanpa menjaga berkasnya
    // cuma menyembunyikan pintu, bukan menguncinya.
    $peran = $u['role'] ?? '';
    $menu  = array_values(array_filter($menu, fn($m) => bolehAkses($peran, basename($m[1], '.php'))));
    $skemaGalat = isOwner() ? (string) setting('skema_galat', '') : '';
    ?><!DOCTYPE html>
<html lang="id"<?= themeAttr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#17181A">
<title><?= e($title) ?> · Panel Callalily</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..700;1,6..96,400..700&family=Hanken+Grotesk:wght@300..700&family=IBM+Plex+Mono:wght@400;500;600&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..700;1,6..96,400..700&family=Hanken+Grotesk:wght@300..700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" media="print" onload="this.media='all';this.onload=null">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..700;1,6..96,400..700&family=Hanken+Grotesk:wght@300..700&family=IBM+Plex+Mono:wght@400;500;600&display=swap"></noscript>
<link rel="stylesheet" href="assets/admin.css?v=<?= assetVer('admin/assets/admin.css') ?>">
</head>
<body>
<button class="railtoggle" id="railToggle" aria-label="Buka menu" aria-expanded="false" aria-controls="rail">☰</button>
<div class="railscrim" id="railScrim" aria-hidden="true"></div>

<aside class="rail" id="rail">
  <a class="brand" href="index.php">Callalily<small>PANEL PRODUKSI</small></a>
  <nav>
    <?php $grupLalu = null; foreach ($menu as [$key, $href, $label, $ic, $badge, $grup]):
      if ($grup !== $grupLalu) { if ($grup !== '') echo '<span class="grup">' . e($grup) . '</span>'; $grupLalu = $grup; } ?>
      <a href="<?= $href ?>"<?= $active === $key ? ' class="on" aria-current="page"' : '' ?>>
        <span class="ic" aria-hidden="true"><?= $ic ?></span><?= e($label) ?>
        <?php if ($badge > 0): ?><span class="badge"><?= $badge ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="foot">
    <?= themeToggle() ?>
    <span class="lab">Masuk sebagai</span>
    <div style="color:var(--ivory);font-size:13.5px;margin:3px 0 2px"><?= e($u['name'] ?? '') ?></div>
    <div class="mono" style="font-size:11px;color:var(--ember);margin:0 0 8px"><?= e(roleLabel($u['role'] ?? '')) ?></div>
    <a href="<?= url() ?>" target="_blank" rel="noopener">Lihat situs ↗</a>
    <a href="#" id="alertBtn" data-on="0">Pengingat hari-H: <span id="alertSt">mati</span></a>
    <a href="logout.php">Keluar</a>
  </div>
</aside>
<script>
/* ============================================================
   PENGINGAT HARI-H (H-7 … H-1)
   ============================================================
   Izin notifikasi TIDAK diminta otomatis saat halaman dibuka. Peramban
   modern menghukum situs yang melakukannya — permintaan yang muncul tanpa
   diminta sering langsung diblokir permanen oleh Chrome, dan setelah itu
   tidak ada cara memintanya lagi selain lewat setelan situs. Jadi izinnya
   baru diminta setelah tombol di rail ditekan.
   ============================================================ */
(() => {
  const btn = document.getElementById('alertBtn');
  const st  = document.getElementById('alertSt');
  if (!btn || !('Notification' in window)) { if (btn) btn.style.display = 'none'; return; }

  const KUNCI = 'clp_alert_on';
  const SUDAH = 'clp_alert_sent';

  const aktif = () => localStorage.getItem(KUNCI) === '1' && Notification.permission === 'granted';
  const gambar = () => { st.textContent = aktif() ? 'aktif' : 'mati';
                         st.style.color = aktif() ? 'var(--sage-text)' : 'var(--ivory-38)'; };

  function terkirim() { try { return JSON.parse(localStorage.getItem(SUDAH) || '{}'); } catch { return {}; } }
  function tandai(k) {
    const d = terkirim();
    d[k] = Date.now();
    // Buang penanda lebih dari 3 hari supaya localStorage tidak menggelembung.
    const batas = Date.now() - 3 * 864e5;
    for (const x in d) if (d[x] < batas) delete d[x];
    localStorage.setItem(SUDAH, JSON.stringify(d));
  }

  btn.addEventListener('click', async ev => {
    ev.preventDefault();
    if (aktif()) { localStorage.setItem(KUNCI, '0'); gambar(); return; }
    const izin = Notification.permission === 'granted'
      ? 'granted' : await Notification.requestPermission();
    if (izin !== 'granted') {
      alert('Izin notifikasi ditolak peramban. Buka ikon gembok di bilah alamat → Notifications → Allow.');
      return;
    }
    localStorage.setItem(KUNCI, '1'); gambar(); periksa();
  });

  async function periksa() {
    if (!aktif()) return;
    try {
      const r = await fetch('alert-api.php', { headers: { 'X-Requested-With': 'fetch' } });
      if (!r.ok) return;
      const d = await r.json();
      const sudah = terkirim();
      for (const it of d.item || []) {
        if (sudah[it.kunci]) continue;          // satu klien satu kali per hari
        const n = new Notification(it.judul, {
          body: it.badan,
          tag: it.kunci,                        // peramban ikut menahan duplikat
          requireInteraction: it.genting,       // yang genting tidak hilang sendiri
        });
        n.onclick = () => { window.focus(); location.href = it.url; };
        tandai(it.kunci);
      }
    } catch (_) { /* jaringan putus bukan alasan mematikan pengingat */ }
  }

  gambar();
  periksa();
  setInterval(periksa, 15 * 60 * 1000);   // 15 menit: cukup untuk jendela H-7…H-1
})();
</script>
<main class="wrap">
<?php
    if ($skemaGalat !== '') {
        echo '<div class="flash warn"><span>Struktur database belum bisa diperbarui otomatis: ' . e($skemaGalat)
           . '. Jalankan <b>db/migration-v22.sql</b> lewat phpMyAdmin, lalu muat ulang.</span></div>';
    }
    if ($f = flash()) {
        $cls = $f['type'] === 'err' ? 'err' : ($f['type'] === 'warn' ? 'warn' : 'ok');
        echo '<div class="flash ' . $cls . '"><span>' . nl2br(e($f['msg'])) . '</span></div>';
    }
}

function adminFoot(): void
{
    echo '</main><script src="assets/admin.js?v=' . assetVer('admin/assets/admin.js') . '"></script></body></html>';
}

/** Header halaman dengan judul + tombol aksi opsional. */
function pageHead(string $h1, string $desc = '', string $actionsHtml = ''): void
{
    echo '<div class="head"><div><h1>' . e($h1) . '</h1>'
       . ($desc ? '<p>' . e($desc) . '</p>' : '')
       . '</div><div style="display:flex;gap:10px;flex-wrap:wrap">' . $actionsHtml . '</div></div>';
}

/** Kepala halaman untuk berkas admin yang berdiri sendiri (login, reset sandi). */
function authHead(string $title, string $sub = ''): void
{
    ?><!DOCTYPE html>
<html lang="id"<?= themeAttr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#17181A">
<title><?= e($title) ?> · Panel Callalily</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..700;1,6..96,400..700&family=Hanken+Grotesk:wght@300..700&family=IBM+Plex+Mono:wght@400;500;600&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..700;1,6..96,400..700&family=Hanken+Grotesk:wght@300..700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" media="print" onload="this.media='all';this.onload=null">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..700;1,6..96,400..700&family=Hanken+Grotesk:wght@300..700&family=IBM+Plex+Mono:wght@400;500;600&display=swap"></noscript>
<link rel="stylesheet" href="assets/admin.css?v=<?= assetVer('admin/assets/admin.css') ?>">
</head>
<body>
<div class="auth"><div class="auth-box">
  <div class="brand">Callalily<small><?= e($sub ?: 'PANEL PRODUKSI') ?></small></div>
<?php
}

function authFoot(): void
{
    echo '</div></div><script src="assets/admin.js?v=' . assetVer('admin/assets/admin.js') . '"></script></body></html>';
}
