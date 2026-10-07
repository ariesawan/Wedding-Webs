<?php
/**
 * Tab Pembayaran di halaman klien (di-include dari admin/klien.php).
 *
 * Termin (jadwal) dan penerimaan (uang masuk) ditampilkan terpisah: satu
 * transfer bisa melunasi beberapa termin atau hanya sebagian satu termin,
 * dan tiap transfer punya kwitansi sendiri.
 *
 * Variabel dari halaman induk: $c, $user, $sudahDeal.
 */
$rkB     = bayarRingkas((int) $c['id']);
$termB   = all("SELECT * FROM payments WHERE client_id = ? ORDER BY sort_order, id", [$c['id']]);
$penB    = all("SELECT r.*, p.label, u.name pencatat FROM payment_receipts r
                JOIN payments p ON p.id = r.payment_id LEFT JOIN users u ON u.id = r.user_id
                WHERE r.client_id = ? AND r.status IN ('sah','batal')
                ORDER BY r.tanggal DESC, r.id DESC", [$c['id']]);
// Satu transfer = satu kwitansi; baris migrasi (tanpa nomor) berdiri sendiri.
$grupB = [];
foreach ($penB as $r) {
    $k = $r['kwitansi_no'] !== '' ? $r['kwitansi_no'] . '|' . $r['status'] : 'm' . $r['id'];
    $grupB[$k] ??= ['r' => $r, 'jumlah' => 0.0, 'untuk' => []];
    $grupB[$k]['jumlah'] += (float) $r['jumlah'];
    $grupB[$k]['untuk'][] = $r['label'];
}
$isDpB     = $c['stage'] === 'dp';
$bolehB    = bayarBoleh($user, $c['stage'], $isDpB) || bayarBoleh($user, $c['stage'], false);
$belumB    = array_values(array_filter($termB, fn($p) => !$p['paid_at'] && bayarSisa($p) > 0));
$selisihB  = round((float) $c['deal_value'] - array_sum(array_map(fn($p) => (float) $p['amount'], $termB)), 2);
$waSiapB   = waSiap();
$waUrlB    = $_SESSION['wa_url'] ?? ''; unset($_SESSION['wa_url']);
$pillB = ['lunas' => 'live', 'sebagian' => 'warn', 'lewat' => 'bad', 'hari_ini' => 'warn', 'dekat' => 'warn', 'belum' => 'draft', 'menyusul' => 'draft'];
?>
<div class="card" id="uang">
  <div class="bayar-kepala">
    <h2 style="margin:0">Pembayaran</h2>
    <?php if ($termB): ?>
      <form method="post" style="display:inline"><?= csrfField() ?>
        <input type="hidden" name="act" value="pay_pengingat"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <button class="btn sm ghost" type="submit" title="Pengingat WhatsApp otomatis H-3 dan sekali setelah lewat tempo">
          Pengingat otomatis: <?= (int) ($c['pengingat_bayar'] ?? 1) ? 'aktif' : 'mati' ?></button></form>
    <?php endif; ?>
  </div>

  <?php if ($waUrlB): ?>
    <div class="flash warn" style="margin:12px 0"><span>Pesan sudah disiapkan.</span>
      <a class="btn sm solid" href="<?= e($waUrlB) ?>" target="_blank" rel="noopener">Buka WhatsApp ↗</a></div>
  <?php endif; ?>

  <?php if ($termB): ?>
    <div class="bayar-angka">
      <div><span class="lab">Nilai kontrak</span><b><?= rupiah($rkB['kontrak']) ?></b></div>
      <div><span class="lab">Sudah diterima</span><b style="color:var(--sage-text)"><?= rupiah($rkB['diterima']) ?></b></div>
      <div><span class="lab">Sisa</span><b><?= rupiah($rkB['sisa']) ?></b></div>
    </div>
    <div class="bar-progress" role="img" aria-label="<?= $rkB['persen'] ?>% terbayar"><i style="width:<?= $rkB['persen'] ?>%"></i></div>
    <p class="hint"><?= $rkB['persen'] ?>% terbayar<?= $rkB['berikutnya'] ? ' · berikutnya ' . e($rkB['berikutnya']['label']) . ' ' . rupiah($rkB['berikutnya']['sisa'])
        . ($rkB['berikutnya']['due_date'] ? ' paling lambat ' . e(tanggalID($rkB['berikutnya']['due_date'])) : '') : '' ?></p>

    <?php if (abs($selisihB) >= 0.5 && (float) $c['deal_value'] > 0): ?>
      <div class="flash warn" style="margin:12px 0;flex-wrap:wrap">
        <span>Total termin <?= rupiah($rkB['kontrak']) ?> ≠ nilai kontrak <?= rupiah((float) $c['deal_value']) ?>
          (selisih <?= rupiah(abs($selisihB)) ?>).</span>
        <?php if ($bolehB): ?>
          <form method="post" style="display:inline"><?= csrfField() ?>
            <input type="hidden" name="act" value="pay_sesuaikan"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <button class="btn sm solid" type="submit">Sesuaikan termin yang belum lunas</button></form>
          <?php if (($user['role'] ?? '') === 'owner'): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Jadikan nilai kontrak = total termin?')"><?= csrfField() ?>
              <input type="hidden" name="act" value="pay_sesuaikan"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <input type="hidden" name="ke_kontrak" value="1">
              <button class="btn sm ghost" type="submit">Jadikan nilai kontrak = total termin</button></form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($belumB && $bolehB): ?>
      <!-- ---------- Catat pembayaran ---------- -->
      <details class="bayar-catat" <?= $isDpB || in_array($rkB['berikutnya']['status'] ?? '', ['lewat', 'hari_ini', 'dekat'], true) ? 'open' : '' ?>>
        <summary>Catat pembayaran masuk</summary>
        <form method="post" enctype="multipart/form-data" class="bayar-form" data-sekali>
          <?= csrfField() ?><input type="hidden" name="act" value="pay_catat"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <div class="row c3">
            <div class="field"><label>Jumlah diterima</label>
              <input type="text" name="jumlah" data-rp inputmode="numeric" required value="<?= (int) $belumB[0]['amount'] - (int) $belumB[0]['terbayar'] ?>"></div>
            <div class="field"><label>Untuk</label>
              <select name="payment_id">
                <option value="0">Otomatis — termin paling awal dulu</option>
                <?php foreach ($belumB as $p): ?>
                  <option value="<?= (int) $p['id'] ?>"><?= e($p['label']) ?> · sisa <?= rupiah(bayarSisa($p)) ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="field"><label>Tanggal diterima</label>
              <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
          </div>
          <div class="row c3">
            <div class="field"><label>Cara bayar</label>
              <select name="metode"><?php foreach (BAYAR_METODE as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Nama pengirim <span class="muted">(opsional)</span></label>
              <input type="text" name="pengirim" maxlength="120" placeholder="Kalau ditransfer orang tua"></div>
            <div class="field"><label>Bukti transfer <span class="muted">(opsional)</span></label>
              <input type="file" name="bukti" accept="image/jpeg,image/png,image/webp,application/pdf"></div>
          </div>
          <div class="field"><label>Catatan internal <span class="muted">(tidak terlihat klien)</span></label>
            <input type="text" name="catatan" maxlength="255"></div>
          <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
            <button class="btn solid" type="submit">Catat pembayaran</button>
            <label class="inline"><input type="checkbox" name="kirim_kwitansi" value="1" <?= $waSiapB && $c['phone'] ? 'checked' : '' ?>
              <?= $c['phone'] ? '' : 'disabled' ?>> Kirim kwitansi ke WhatsApp klien</label>
          </div>
          <p class="hint" style="margin:8px 0 0">Kelebihan otomatis masuk ke termin berikutnya; satu transfer = satu kwitansi.
            <?php if ($isDpB): ?> DP yang lunas penuh langsung menyerahkan klien ke admin office.<?php endif; ?></p>
        </form>
      </details>
    <?php endif; ?>

    <!-- ---------- Jadwal termin ---------- -->
    <ul class="bayar-termin">
      <?php foreach ($termB as $p):
        $st = bayarStatus($p);
        $aturan = $p['offset_hari'] !== null ? 'H-' . (int) $p['offset_hari'] : ($p['kode'] === 'dealing' ? 'saat DP' : 'tanggal tetap');
        [$ingatJenis] = array_pad(explode('@', (string) $p['ingat_kode'], 2), 2, '');
        $pillTeks = $p['paid_at'] ? 'Lunas ' . date('d/m', strtotime($p['paid_at']))
            : ((float) $p['terbayar'] > 0 ? 'Sebagian' : (['lewat' => 'Lewat tempo', 'hari_ini' => 'Hari ini', 'dekat' => 'Segera', 'menyusul' => 'Menyusul'][$st['kode']] ?? 'Belum')); ?>
        <li>
          <div class="kiri">
            <b><?= e($p['label']) ?></b>
            <span><?= e($aturan) ?><?= $p['persen'] !== null ? ' · ' . e(persenTeks($p['persen'])) . '%' : '' ?>
              · <?= $p['due_date'] ? 'tempo ' . e(tanggalID($p['due_date'])) : 'tempo belum ada' ?></span>
            <?php if ($p['ingat_at'] && !$p['paid_at']): ?><span class="hint">Diingatkan<?= $ingatJenis === 'telat' ? ' (lewat tempo)' : '' ?> <?= e(mb_strtolower(labelHari(substr($p['ingat_at'], 0, 10)))) ?></span><?php endif; ?>
          </div>
          <div class="nominal">
            <b><?= rupiah((float) $p['amount']) ?></b>
            <?php if ((float) $p['terbayar'] > 0 && !$p['paid_at']): ?><span>diterima <?= rupiah((float) $p['terbayar']) ?></span><?php endif; ?>
          </div>
          <div class="kanan">
            <span class="pill <?= $pillB[$st['kode']] ?? 'draft' ?>"><?= e($pillTeks) ?></span>
            <?php if (!$p['paid_at'] && $bolehB): ?>
              <form method="post" data-confirm="<?= e('Catat ' . rupiah(bayarSisa($p)) . ' untuk ' . $p['label'] . ' diterima hari ini?') ?>"><?= csrfField() ?>
                <input type="hidden" name="act" value="pay_paid"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                <button class="btn sm" type="submit">Tandai lunas</button></form>
              <?php if ($c['phone']): ?>
                <form method="post"><?= csrfField() ?>
                  <input type="hidden" name="act" value="pay_kirim_tagihan"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                  <button class="btn sm ghost" type="submit">Kirim tagihan</button></form>
              <?php endif; ?>
              <details class="bayar-ubah"><summary class="btn sm ghost">Ubah</summary>
                <form method="post" class="bayar-ubah-f"><?= csrfField() ?>
                  <input type="hidden" name="act" value="pay_ubah"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                  <div class="field"><label>Nama termin <span class="muted">(terlihat klien)</span></label><input type="text" name="label" value="<?= e($p['label']) ?>" maxlength="80"></div>
                  <div class="field"><label>Nominal</label><input type="text" name="amount" data-rp inputmode="numeric" value="<?= (int) $p['amount'] ?>"></div>
                  <div class="field"><label>Jatuh tempo</label><input type="date" name="due_date" value="<?= e((string) $p['due_date']) ?>"></div>
                  <?php if ($p['kode'] !== '' && $p['kode'] !== 'dealing' && $p['offset_hari'] === null): ?>
                    <label class="inline"><input type="checkbox" name="ikut_harih" value="1"> Ikuti hari-H lagi (aturan template)</label>
                  <?php endif; ?>
                  <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button class="btn sm solid" type="submit">Simpan</button>
                    <?php if ((float) $p['terbayar'] <= 0): ?>
                      <button class="btn sm danger" type="submit" form="hapusT<?= (int) $p['id'] ?>">Hapus termin</button>
                    <?php endif; ?>
                  </div>
                </form>
              </details>
              <form method="post" id="hapusT<?= (int) $p['id'] ?>" data-confirm="<?= e('Hapus termin ' . $p['label'] . '?') ?>" hidden><?= csrfField() ?>
                <input type="hidden" name="act" value="pay_del"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>"></form>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>

    <!-- ---------- Penerimaan & kwitansi ---------- -->
    <span class="lab" style="display:block;margin:20px 0 6px">Penerimaan &amp; kwitansi</span>
    <?php if (!$grupB): ?>
      <p class="sub" style="margin:0">Belum ada pembayaran yang dicatat.</p>
    <?php else: ?>
      <ul class="daftar bayar-terima">
        <?php foreach ($grupB as $g): $r = $g['r']; $batalB = $r['status'] === 'batal'; ?>
          <li class="<?= $batalB ? 'batal' : '' ?>">
            <div class="isi">
              <b><?= rupiah($g['jumlah']) ?></b>
              <span><?= e(tanggalID($r['tanggal'])) ?> · <?= e($r['kwitansi_no'] ?: 'data lama') ?> · <?= e(implode(', ', array_unique($g['untuk']))) ?>
                · <?= e($r['metode'] ?: '—') ?><?= $r['pengirim'] !== '' ? ' · a.n. ' . e($r['pengirim']) : '' ?></span>
              <span class="hint"><?= $batalB ? 'Dibatalkan: ' . e($r['batal_alasan']) : ($r['pencatat'] ? 'Dicatat ' . e($r['pencatat']) : 'Dari data lama')
                . ($r['catatan'] !== '' && !$batalB ? ' · ' . e($r['catatan']) : '') . ($r['kwitansi_wa_at'] ? ' · kwitansi terkirim' : '') ?></span>
            </div>
            <div class="aksi-kecil">
              <?php if ($r['bukti']): ?><a class="btn sm ghost" href="bukti.php?id=<?= (int) $r['id'] ?>" target="_blank" rel="noopener">Bukti</a><?php endif; ?>
              <?php if ($r['kwitansi_no'] !== ''): ?>
                <a class="btn sm ghost" href="<?= e(dokUrl('kw', (int) $r['id'])) ?>" target="_blank" rel="noopener">Kwitansi PDF</a>
                <?php if (!$batalB && $c['phone'] && $bolehB): ?>
                  <form method="post" style="display:inline"><?= csrfField() ?>
                    <input type="hidden" name="act" value="pay_kirim_kwitansi"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <input type="hidden" name="receipt_id" value="<?= (int) $r['id'] ?>">
                    <button class="btn sm ghost" type="submit"><?= $r['kwitansi_wa_at'] ? 'Kirim ulang' : 'Kirim ke WA' ?></button></form>
                <?php endif; ?>
              <?php endif; ?>
              <?php if (!$batalB && $bolehB): ?>
                <details class="bayar-ubah"><summary class="btn sm ghost danger">Batalkan</summary>
                  <form method="post" class="bayar-ubah-f"><?= csrfField() ?>
                    <input type="hidden" name="act" value="pay_batal"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <input type="hidden" name="receipt_id" value="<?= (int) $r['id'] ?>">
                    <div class="field"><label>Alasan (internal)</label><input type="text" name="alasan" required maxlength="255" placeholder="Salah nominal, transfer tidak masuk…"></div>
                    <button class="btn sm danger" type="submit">Batalkan penerimaan ini</button>
                  </form>
                </details>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

  <?php else: ?>
    <p class="sub">Belum ada termin. Termin disusun otomatis dari <b>template pembayaran</b> begitu klien cocok dan nilai kontraknya ada.</p>
    <?php if ((float) $c['deal_value'] > 0 && $bolehB): ?>
      <form method="post" style="margin-bottom:6px"><?= csrfField() ?>
        <input type="hidden" name="act" value="pay_generate"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <button class="btn solid sm" type="submit">Susun termin dari template (<?= rupiah((float) $c['deal_value'], true) ?>)</button>
      </form>
    <?php elseif ($sudahDeal): ?>
      <p class="hint">Nilai deal belum diisi. Isi di tab <a href="#data" style="color:var(--ember)">Biodata awal</a>, lalu susun terminnya di sini.</p>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($bolehB): ?>
    <details style="margin-top:16px">
      <summary style="cursor:pointer;color:var(--ember);font-size:13.5px;padding:6px 0">+ Tambah termin manual</summary>
      <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:11px">
        <?= csrfField() ?><input type="hidden" name="act" value="pay_save"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <div class="field" style="margin:0;min-width:160px"><label>Nama termin <span class="muted">(terlihat klien)</span></label><input type="text" name="label" required placeholder="Tambahan dekorasi"></div>
        <div class="field" style="margin:0;min-width:150px"><label>Nominal</label><input type="text" name="amount" data-rp inputmode="numeric" required></div>
        <div class="field" style="margin:0;min-width:150px"><label>Jatuh tempo</label><input type="date" name="due_date"></div>
        <button class="btn" type="submit">Tambah</button>
      </form>
    </details>
  <?php else: ?>
    <p class="hint" style="margin-top:14px"><?= $isDpB ? 'DP dicatat admin early atau owner.' : 'Pembayaran klien yang sudah deal dicatat admin office atau owner.' ?></p>
  <?php endif; ?>
</div>
<?php /* Kunci tombol form[data-sekali] kini ada di assets/admin.js (berlaku di semua halaman). */ ?>
