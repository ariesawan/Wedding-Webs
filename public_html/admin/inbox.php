<?php
/**
 * Pesan WhatsApp masuk yang belum bisa dicocokkan otomatis — nomor baru,
 * vendor yang nomornya belum terdaftar, atau klien yang menghubungi dari
 * nomor lain. Tanpa halaman ini, balasan seperti itu hilang tanpa jejak.
 */
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/vendor.php';
require_once __DIR__ . '/../inc/wa.php';
$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    try {
        if (($_POST['act'] ?? '') === 'salurkan') {
            $mid = (int) $_POST['id'];
            $m   = one("SELECT * FROM wa_inbox WHERE id = ?", [$mid]);
            if (!$m) throw new RuntimeException('Pesan tidak ditemukan.');
            $cid = (int) ($_POST['client_id'] ?? 0);
            $vid = (int) ($_POST['vendor_id'] ?? 0);
            if (!$cid || !$vid) throw new RuntimeException('Pilih pesta dan vendornya.');

            q("INSERT INTO vendor_messages (client_id, vendor_id, direction, channel, body, wa_id, wa_from, wa_status, created_at)
               VALUES (?,?,'masuk','wa',?,?,?,'sampai',?)",
              [$cid, $vid, $m['body'], $m['wa_id'], $m['wa_from'], $m['created_at']]);
            q("UPDATE wa_inbox SET handled_at = NOW() WHERE id = ?", [$mid]);

            // Simpan nomornya ke vendor kalau memang belum ada, supaya
            // balasan berikutnya masuk sendiri tanpa perlu disalurkan lagi.
            if (!empty($_POST['simpan_nomor'])) {
                $v = one("SELECT phone FROM vendors WHERE id = ?", [$vid]);
                if ($v && trim($v['phone']) === '') q("UPDATE vendors SET phone = ? WHERE id = ?", [$m['wa_from'], $vid]);
            }
            flash('Pesan dipindahkan ke room.');
        } elseif (($_POST['act'] ?? '') === 'abaikan') {
            q("UPDATE wa_inbox SET handled_at = NOW() WHERE id = ?", [(int) $_POST['id']]);
            flash('Pesan ditandai selesai.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('admin/inbox.php');
}

$tampil = ($_GET['s'] ?? '') === 'semua';
$rows   = all("SELECT * FROM wa_inbox " . ($tampil ? '' : 'WHERE handled_at IS NULL ') . "ORDER BY created_at DESC LIMIT 200");
$klien  = all("SELECT id, name, partner_name, wedding_date FROM clients WHERE stage NOT IN ('batal') ORDER BY COALESCE(wedding_date,'2099-12-31')");
$vendor = all("SELECT id, name, category, phone FROM vendors WHERE is_active = 1 ORDER BY category, name");

adminHead('Kotak masuk', 'inbox');
pageHead('Kotak masuk WhatsApp',
    'Pesan dari nomor yang belum dikenali sistem. Salurkan ke room yang tepat agar riwayatnya lengkap.',
    '<a class="btn ghost" href="?s=' . ($tampil ? '' : 'semua') . '">' . ($tampil ? 'Hanya yang belum diproses' : 'Tampilkan semua') . '</a>');

if (!waSiap()): ?>
  <div class="flash warn"><span>Penyedia WhatsApp belum aktif, jadi belum ada pesan yang bisa masuk otomatis. <a href="integrasi.php#wa" style="text-decoration:underline">Atur di Integrasi →</a></span></div>
<?php endif; ?>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty">
      <p>Tidak ada pesan menunggu</p>
      <span>Balasan dari nomor yang sudah terdaftar langsung masuk ke room masing-masing. Yang muncul di sini hanya nomor yang belum dikenali.</span>
    </div>
  <?php else: foreach ($rows as $m): ?>
    <div style="border:1px solid var(--ivory-12);border-radius:11px;padding:15px;margin-bottom:12px<?= $m['handled_at'] ? ';opacity:.55' : '' ?>">
      <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:baseline">
        <b class="mono" style="font-size:13.5px">+<?= e($m['wa_from']) ?><?= $m['nama'] ? ' · ' . e($m['nama']) : '' ?></b>
        <span class="mono muted" style="font-size:11px"><?= tanggalID($m['created_at'], true) ?></span>
      </div>
      <div style="margin-top:9px;font-size:14px;line-height:1.65;white-space:pre-line"><?= e($m['body']) ?></div>

      <?php if (!$m['handled_at']): ?>
        <form method="post" style="display:flex;gap:9px;flex-wrap:wrap;align-items:flex-end;margin-top:13px;padding-top:12px;border-top:1px solid var(--ivory-07)">
          <?= csrfField() ?><input type="hidden" name="act" value="salurkan"><input type="hidden" name="id" value="<?= $m['id'] ?>">
          <div class="field" style="margin:0;flex:1;min-width:186px"><label>Pesta</label>
            <select name="client_id">
              <option value="">— pilih —</option>
              <?php foreach ($klien as $k): ?>
                <option value="<?= $k['id'] ?>"><?= e($k['name'] . ($k['partner_name'] ? ' & ' . $k['partner_name'] : '')) ?><?= $k['wedding_date'] ? ' · ' . date('M Y', strtotime($k['wedding_date'])) : '' ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field" style="margin:0;flex:1;min-width:186px"><label>Vendor</label>
            <select name="vendor_id">
              <option value="">— pilih —</option>
              <?php foreach ($vendor as $v): ?>
                <option value="<?= $v['id'] ?>"><?= e($v['name']) ?> · <?= e(katVendor($v['category'])) ?><?= trim($v['phone']) === '' ? ' (belum ada nomor)' : '' ?></option>
              <?php endforeach; ?>
            </select></div>
          <label class="check" style="flex:0 0 auto;padding:0 0 9px"><input type="checkbox" name="simpan_nomor" checked> Simpan nomor ke vendor</label>
          <button class="btn solid" type="submit">Salurkan</button>
          <button class="btn ghost" type="submit" name="act" value="abaikan" formnovalidate>Abaikan</button>
        </form>
      <?php else: ?>
        <p class="hint" style="margin-top:9px">Sudah diproses <?= tanggalID($m['handled_at'], true) ?>.</p>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
</div>

<?php adminFoot();
