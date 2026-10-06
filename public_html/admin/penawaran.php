<?php
/**
 * ============================================================
 * PRICE LIST & PENAWARAN
 * ============================================================
 *
 * Alur yang dipegang admin early, sesuai owner:
 *
 *   Buat    → dari paket (paket + rincian isi) atau kosong
 *   Sunting → harga paket, isi per kelompok, tambahan, opsional, diskon
 *   Kirim   → PDF terlampir di WhatsApp (otomatis bila penyedia WA aktif,
 *             atau unduh PDF + buka WhatsApp manual); tahap → Price list
 *   Tanggapan:
 *     cocok        → Menunggu DP (termin disusun, DP 30% ditagih)
 *     menawar      → catat angka yang diminta, buat revisi
 *     tidak cocok  → klien ke arsip "tidak jadi", lanjut ke Analisa
 *
 * Admin office boleh membuka tapi tidak menyunting: dia perlu membaca apa
 * yang sudah dijanjikan, tapi angkanya bukan lagi urusannya.
 */
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/penawaran.php';
require_once __DIR__ . '/../inc/pipeline.php';
require_once __DIR__ . '/../inc/paket.php';
$user = requireLogin();

$peranSaya    = $user['role'] ?? '';
$bolehSunting = in_array($peranSaya, ['owner', 'admin_early'], true);

/** Penjaga tunggal untuk semua aksi yang mengubah. */
function pastikanBolehSunting(bool $boleh): void
{
    if (!$boleh) throw new RuntimeException(
        'Price list disusun admin early. Admin office bisa membaca riwayatnya, tapi tidak mengubah angkanya.');
}

/** Kalimat penutup flash: tahap klien sekarang. */
function tahapInfo(int $cid): string
{
    $st = one("SELECT stage FROM clients WHERE id = ?", [$cid])['stage'] ?? '';
    return $st ? ' Tahap klien: ' . stageLabel($st) . '.' : '';
}

/** Label status yang dibaca manusia — enum di database bukan bahasa sehari-hari. */
function statusPenawaran(string $s): array
{
    return [
        'draf'        => ['Draf', 'draft'],
        'terkirim'    => ['Terkirim', 'warn'],
        'cocok'       => ['Disetujui', 'live'],
        'revisi'      => ['Tidak berlaku', 'draft'],
        'tidak_cocok' => ['Ditolak', 'bad'],
    ][$s] ?? [$s, 'draft'];
}

$uangPost = fn($v) => (float) preg_replace('/[^\d]/', '', (string) $v);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';
    $qid = 0;
    try {
        pastikanBolehSunting($bolehSunting);

        if ($act === 'buat') {
            $cid   = (int) ($_POST['client_id'] ?? 0);
            $jenis = ($_POST['jenis'] ?? 'pricelist') === 'penawaran' ? 'penawaran' : 'pricelist';
            if (!one("SELECT id FROM clients WHERE id = ?", [$cid])) throw new RuntimeException('Klien tidak ditemukan.');

            $tplId = (int) ($_POST['template_id'] ?? 0);
            if ($tplId) {
                $qid = quoteDariPaket($cid, $tplId, $jenis, (int) $user['id']);
                $qq  = one("SELECT paket_nama, paket_harga FROM quotes WHERE id = ?", [$qid]);
                clientLog($cid, 'sistem', ucfirst($jenis === 'pricelist' ? 'Price list' : 'Penawaran') . ' dibuat dari paket',
                          $qq['paket_nama'], $user['id']);
                flash('Dibuat dari paket "' . $qq['paket_nama'] . '".'
                    . ((float) $qq['paket_harga'] > 0 ? ' Periksa isinya, lalu kirim.' : ' Harga paketnya masih kosong — isi dulu sebelum dikirim.'));
            } else {
                $qid = ($_POST['sumber'] ?? '') === 'kebutuhan'
                     ? quoteBuat($cid, $jenis, (int) $user['id'])
                     : quoteDariKosong($cid, $jenis, (int) $user['id']);
                clientLog($cid, 'sistem', ($jenis === 'pricelist' ? 'Price list' : 'Penawaran') . ' dibuat', '', $user['id']);
                flash('Dokumen dibuat. Isi paket atau barisnya di sini.');
            }
            redirect('admin/penawaran.php?id=' . $qid);
        }

        $qid = (int) ($_POST['id'] ?? 0);
        $qq  = $qid ? one("SELECT * FROM quotes WHERE id = ?", [$qid]) : null;
        if (!$qq) throw new RuntimeException('Penawaran tidak ditemukan.');
        $cid = (int) $qq['client_id'];
        $terkunci = in_array($qq['status'], ['cocok', 'tidak_cocok'], true);
        if ($terkunci && in_array($act, ['simpan', 'baris', 'hapus_baris'], true)) {
            throw new RuntimeException('Dokumen ini sudah diputus dan terkunci. Buat revisi untuk mengubah isinya.');
        }

        if ($act === 'simpan') {
            // Seluruh dokumen dikirim sekaligus — menyusun price list itu
            // pekerjaan sekali duduk, dan satu tombol Simpan lebih jujur soal
            // apa yang sudah masuk dan apa yang belum.
            foreach ((array) ($_POST['item'] ?? []) as $iid => $row) {
                $harga = $uangPost($row['harga'] ?? 0);
                $qty   = max(0.01, (float) str_replace(',', '.', (string) ($row['qty'] ?? 1)));
                q("UPDATE quote_items
                      SET kelompok = ?, label = ?, detail = ?, qty = ?, satuan = ?, harga = ?, jumlah = ?,
                          opsional = ?, sort_order = ?
                    WHERE id = ? AND quote_id = ?",
                  [mb_substr(trim($row['kelompok'] ?? ''), 0, 80),
                   mb_substr(trim($row['label'] ?? ''), 0, 190),
                   mb_substr(trim($row['detail'] ?? ''), 0, 400),
                   $qty, mb_substr(trim($row['satuan'] ?? 'paket'), 0, 30) ?: 'paket',
                   $harga, $harga * $qty,
                   isset($row['opsional']) ? 1 : 0,
                   (int) ($row['sort'] ?? 0), (int) $iid, $qid]);
            }
            q("DELETE FROM quote_items WHERE quote_id = ? AND TRIM(label) = ''", [$qid]);
            $ph = $uangPost($_POST['paket_harga'] ?? '');
            q("UPDATE quotes SET paket_nama = ?, paket_harga = ?, diskon = ?, catatan = ?, valid_until = ?, jenis = ? WHERE id = ?",
              [mb_substr(trim($_POST['paket_nama'] ?? ''), 0, 120), $ph > 0 ? $ph : null,
               max(0, $uangPost($_POST['diskon'] ?? 0)), mb_substr(trim($_POST['catatan'] ?? ''), 0, 4000),
               ($_POST['valid_until'] ?? '') ?: null,
               ($_POST['jenis'] ?? $qq['jenis']) === 'penawaran' ? 'penawaran' : 'pricelist', $qid]);
            quoteHitung($qid);
            flash('Tersimpan.');
        }

        elseif ($act === 'baris') {
            $opsi = ($_POST['jenis_baris'] ?? '') === 'opsi' ? 1 : 0;
            $hrg  = ($_POST['jenis_baris'] ?? '') === 'tambahan';
            $urut = (int) (one("SELECT COALESCE(MAX(sort_order),0)+10 s FROM quote_items WHERE quote_id = ?", [$qid])['s'] ?? 10);
            q("INSERT INTO quote_items (quote_id, kelompok, label, qty, satuan, harga, jumlah, opsional, sort_order)
               VALUES (?, ?, ?, 1, 'paket', 0, 0, ?, ?)",
              [$qid, $opsi || $hrg ? '' : mb_substr(trim($_POST['kelompok'] ?? ''), 0, 80),
               $opsi ? 'Tambahan opsional' : ($hrg ? 'Tambahan' : 'Isi baru'), $opsi, $urut]);
            quoteHitung($qid);
            redirect('admin/penawaran.php?id=' . $qid . '#rincian');
        }

        elseif ($act === 'hapus_baris') {
            q("DELETE FROM quote_items WHERE id = ? AND quote_id = ?", [(int) $_POST['item_id'], $qid]);
            quoteHitung($qid);
            flash('Baris dihapus.');
        }

        elseif ($act === 'kirim') {
            $jenisLbl = $qq['jenis'] === 'pricelist' ? 'Price list' : 'Penawaran';
            if (!empty($_POST['via_wa'])) {
                $r = quoteKirimWA($qid, (int) $user['id']);
                if (empty($r['ok'])) {
                    // Gagal kirim = BELUM terkirim. Klien yang tidak pernah
                    // menerima apa pun tidak boleh ikut ditunggu jawabannya.
                    throw new RuntimeException('WhatsApp gagal: ' . ($r['error'] ?? 'tidak diketahui')
                        . '. Belum ditandai terkirim — kirim manual lalu tekan "Sudah saya kirim".');
                }
                flash(empty($r['lampiran_gagal'])
                    ? $jenisLbl . ' terkirim lewat WhatsApp beserta PDF-nya.' . tahapInfo($cid)
                    : $jenisLbl . ' terkirim lewat WhatsApp, tapi PDF gagal dilampirkan (' . $r['lampiran_gagal']
                      . '). Tautan PDF sudah ada di teksnya; bila perlu unduh dan kirim manual.' . tahapInfo($cid),
                      empty($r['lampiran_gagal']) ? 'ok' : 'warn');
            } else {
                quoteTandaiTerkirim($qid, (int) $user['id']);
                clientLog($cid, 'sistem', $jenisLbl . ' ' . $qq['nomor'] . ' dikirim manual',
                          'Total ' . rupiah((float) $qq['total']), $user['id']);
                flash($jenisLbl . ' ditandai terkirim.' . tahapInfo($cid));
            }
        }

        elseif ($act === 'nego') {
            // Angka yang DIMINTA klien, bukan yang kita ajukan.
            $nilai  = $uangPost($_POST['nego_nilai'] ?? 0);
            $alasan = mb_substr(trim($_POST['nego_catatan'] ?? ''), 0, 400);
            if ($nilai <= 0) throw new RuntimeException('Angka yang diminta klien belum diisi.');
            q("UPDATE quotes SET nego_nilai = ?, nego_catatan = ?, nego_at = NOW() WHERE id = ?", [$nilai, $alasan, $qid]);
            clientLog($cid, 'catatan', 'Klien menawar ' . rupiah($nilai),
                      'Dari ' . rupiah((float) $qq['total']) . ' — selisih ' . rupiah((float) $qq['total'] - $nilai)
                    . ($alasan ? '. ' . $alasan : ''), $user['id']);
            flash('Tawaran klien dicatat. Buat revisi untuk menyesuaikan angkanya.');
        }

        elseif ($act === 'revisi') {
            // Revisi = dokumen baru yang MENYALIN isi lama, bukan menimpa —
            // yang lama sudah dipegang klien.
            $c = one("SELECT * FROM clients WHERE id = ?", [$cid]);
            $baru = quoteBaru($c, $qq['jenis'], (int) $user['id']);
            q("INSERT INTO quote_items (quote_id, category_id, kelompok, label, detail, qty, satuan, harga, jumlah, opsional, sort_order)
               SELECT ?, category_id, kelompok, label, detail, qty, satuan, harga, jumlah, opsional, sort_order
                 FROM quote_items WHERE quote_id = ?", [$baru, $qid]);
            q("UPDATE quotes SET revisi_dari = ?, diskon = ?, catatan = ?, template_id = ?, paket_nama = ?, paket_harga = ? WHERE id = ?",
              [$qid, $qq['diskon'], $qq['catatan'], $qq['template_id'], $qq['paket_nama'], $qq['paket_harga'], $baru]);
            quoteHitung($baru);
            if (in_array($qq['status'], ['draf', 'terkirim'], true)) q("UPDATE quotes SET status = 'revisi' WHERE id = ?", [$qid]);
            clientLog($cid, 'sistem', 'Revisi dibuat', 'Dari ' . $qq['nomor'], $user['id']);
            flash('Revisi dibuat menyalin isi sebelumnya. Sesuaikan angkanya di sini.');
            redirect('admin/penawaran.php?id=' . $baru);
        }

        elseif ($act === 'cocok') {
            $r = quoteCocok($qid, (int) $user['id']);
            flash(($r['tahap'] === 'dp'
                    ? 'Klien cocok. Tahap: Menunggu DP — tagih DP 30%, lalu tandai lunas begitu masuk; klien otomatis diserahkan ke admin office.'
                    : 'Disetujui.')
                . ($r['info'] ? "\n" . implode("\n", $r['info']) : ''));
            redirect(kembaliRingkasan() ?? 'admin/klien.php?id=' . $cid);
        }

        elseif ($act === 'tidak_cocok') {
            $alasan = trim($_POST['alasan'] ?? '');
            if ($alasan === '') throw new RuntimeException('Alasan wajib diisi — ini yang dibaca saat menganalisa penyebab.');
            $r = quoteTidakCocok($qid, $alasan, (int) $user['id']);
            if ($r['batal']) {
                flash('Dicatat tidak cocok. Klien dipindah ke arsip "Tidak jadi" — catat sebabnya di bawah.');
                redirect('admin/analisa.php?klien=' . $cid);
            }
            flash('Dicatat tidak cocok.');
        }

        redirect('admin/penawaran.php?id=' . $qid);
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
        if ($k = kembaliRingkasan()) redirect($k);
        redirect('admin/penawaran.php' . ($qid ? '?id=' . $qid : (!empty($_POST['client_id']) ? '?client=' . (int) $_POST['client_id'] : '')));
    }
}

/** Dokumen kosong — tanpa paket, tanpa baris. */
function quoteDariKosong(int $cid, string $jenis, int $uid): int
{
    $c = one("SELECT * FROM clients WHERE id = ?", [$cid]);
    $qid = quoteBaru($c, $jenis, $uid);
    quoteHitung($qid);
    return $qid;
}

$qid    = (int) ($_GET['id'] ?? 0);
$cidGet = (int) ($_GET['client'] ?? 0);

/* ============================================================
   PDF — yang dikirim ke klien. ?cetak=1 (tautan lama) ikut ke sini.
   ============================================================ */
if ($qid && (isset($_GET['pdf']) || isset($_GET['cetak']))) {
    require_once __DIR__ . '/../inc/pdf-penawaran.php';
    quotePdfKirim($qid, isset($_GET['unduh']));
}

/* ============================================================
   DAFTAR SATU KLIEN + BUAT BARU
   ============================================================ */
if ($cidGet && !$qid) {
    $c = one("SELECT * FROM clients WHERE id = ?", [$cidGet]);
    if (!$c) { http_response_code(404); die('Klien tidak ditemukan.'); }
    $riwayat = all("SELECT * FROM quotes WHERE client_id = ? ORDER BY id DESC", [$cidGet]);
    $paket   = paketDaftar(false);

    adminHead('Price list', 'klien');
    pageHead('Price list — ' . $c['name'] . ($c['partner_name'] ? ' & ' . $c['partner_name'] : ''),
             'Pilih paket, sesuaikan isinya, lalu kirim PDF-nya lewat WhatsApp.',
             ($bolehSunting ? '<a class="btn ghost" href="paket.php">Kelola paket</a> ' : '')
           . '<a class="btn ghost" href="klien.php?id=' . (int) $c['id'] . '">← Halaman klien</a>');

    if ($bolehSunting): ?>
      <div class="card">
        <h2>Buat price list dari paket</h2>
        <p class="sub">Isi paket disalin utuh lalu bisa diubah untuk klien ini — paketnya sendiri tidak ikut berubah.
          <?php if (!empty($c['paket_minat'])): ?>Paket pilihan klien di formulir ditandai <b>★</b>.<?php endif; ?></p>
        <?php if (!$paket): ?>
          <p class="sub">Belum ada paket. <a href="paket.php" style="color:var(--ember)">Buat paket →</a></p>
        <?php endif; ?>
        <div class="pilih-paket">
          <?php foreach ($paket as $t): $minat = (int) ($c['paket_minat'] ?? 0) === (int) $t['id']; ?>
            <form method="post" class="pp<?= $minat ? ' minat' : '' ?>">
              <?= csrfField() ?><input type="hidden" name="act" value="buat">
              <input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>">
              <input type="hidden" name="template_id" value="<?= (int) $t['id'] ?>">
              <input type="hidden" name="jenis" value="pricelist">
              <b><?= $minat ? '★ ' : '' ?><?= e($t['nama']) ?></b>
              <span class="pp-h"><?= e(paketHargaLabel($t, true) ?: 'harga belum diisi') ?></span>
              <span class="pp-k"><?= (int) $t['n_isi'] ?> isi<?= !empty($t['tamu']) ? ' · ±' . (int) $t['tamu'] . ' tamu' : '' ?>
                · <?= !empty($t['tampil_web']) ? 'di situs' : 'internal' ?></span>
              <button class="btn sm <?= $minat ? 'solid' : '' ?>" type="submit">Pakai paket ini</button>
            </form>
          <?php endforeach; ?>
        </div>
        <details style="margin-top:14px">
          <summary class="hint" style="cursor:pointer">Lainnya: dokumen kosong, atau dari kebutuhan vendor klien</summary>
          <div class="aksi" style="margin-top:10px">
            <form method="post" style="display:inline"><?= csrfField() ?>
              <input type="hidden" name="act" value="buat"><input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>">
              <input type="hidden" name="jenis" value="pricelist"><button class="btn sm ghost" type="submit">+ Price list kosong</button></form>
            <form method="post" style="display:inline"><?= csrfField() ?>
              <input type="hidden" name="act" value="buat"><input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>">
              <input type="hidden" name="jenis" value="penawaran"><input type="hidden" name="sumber" value="kebutuhan">
              <button class="btn sm ghost" type="submit">+ Penawaran dari kebutuhan vendor</button></form>
          </div>
        </details>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2>Riwayat <span class="lab" style="margin-left:8px"><?= count($riwayat) ?></span></h2>
      <?php if (!$riwayat): ?>
        <p class="sub">Belum ada price list untuk klien ini.</p>
      <?php else: ?>
        <table class="tbl">
          <thead><tr><th>Nomor</th><th>Paket</th><th>Status</th><th class="num">Total</th><th>Dibuka klien</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($riwayat as $r): [$stL, $stC] = statusPenawaran($r['status']); ?>
            <tr>
              <td data-l="Nomor"><b><?= e($r['nomor']) ?></b><?= $r['revisi'] > 1 ? ' <span class="muted">rev ' . (int) $r['revisi'] . '</span>' : '' ?>
                <br><span class="muted mono" style="font-size:10.5px"><?= $r['jenis'] === 'pricelist' ? 'price list' : 'penawaran' ?></span></td>
              <td data-l="Paket"><?= e((string) ($r['paket_nama'] ?? '') ?: '—') ?></td>
              <td data-l="Status"><span class="pill <?= $stC ?>"><?= e($stL) ?></span></td>
              <td class="num" data-l="Total"><?= (float) $r['total'] > 0 ? rupiah((float) $r['total']) : 'Rp 0' ?></td>
              <td class="num" data-l="Dibuka klien"><?= $r['seen_at'] ? tanggalID(substr($r['seen_at'], 0, 10)) : '—' ?></td>
              <td class="actions"><a class="btn sm ghost" target="_blank" href="?id=<?= (int) $r['id'] ?>&pdf=1">PDF</a>
                <a class="btn sm" href="?id=<?= (int) $r['id'] ?>">Buka</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <style>
    .pilih-paket{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px}
    .pp{border:1px solid var(--ivory-12);border-radius:12px;padding:13px 14px;display:flex;flex-direction:column;gap:4px;align-items:flex-start}
    .pp.minat{border-color:var(--ember-line);background:var(--ember-soft)}
    .pp b{font-size:14.5px;color:var(--ivory)}
    .pp-h{font-family:var(--serif);font-style:italic;font-size:17px;color:var(--ember)}
    .pp-k{font-size:12px;color:var(--ivory-38);margin-bottom:6px}
    </style>
    <?php adminFoot(); exit;
}

/* ============================================================
   SATU DOKUMEN
   ============================================================ */
if (!$qid) { redirect('admin/klien.php'); }

$d = quoteData($qid);
if (!$d) { http_response_code(404); die('Penawaran tidak ditemukan.'); }
$qq     = $d['q'];
$cid    = (int) $qq['client_id'];
$items  = all("SELECT * FROM quote_items WHERE quote_id = ? ORDER BY opsional, sort_order, id", [$qid]);
$hitung = quoteHitung($qid);
$lain   = all("SELECT * FROM quotes WHERE client_id = ? ORDER BY id DESC", [$cid]);
$asal   = !empty($qq['revisi_dari']) ? one("SELECT nomor FROM quotes WHERE id = ?", [$qq['revisi_dari']]) : null;
$terkunci = in_array($qq['status'], ['cocok', 'tidak_cocok'], true);
$sunting  = $bolehSunting && !$terkunci;
$isPL     = $qq['jenis'] === 'pricelist';
$rp       = fn($n) => (float) $n > 0 ? rupiah((float) $n) : 'Rp 0';
$kelompokSaran = array_values(array_unique(array_merge(
    array_filter(array_map(fn($r) => trim((string) ($r['kelompok'] ?? '')), $items)),
    ['Wedding Organizer', 'Rias & busana', 'Dekorasi', 'Dokumentasi', 'Acara & hiburan', 'Rangkaian adat', 'Venue', 'Konsumsi', 'Undangan & souvenir'])));

adminHead($d['jenisLbl'], 'klien');
// pageHead() meng-escape sendiri — jangan panggil e() di sini.
pageHead($d['jenisLbl'] . ' ' . $qq['nomor'] . ($qq['revisi'] > 1 ? ' · rev ' . (int) $qq['revisi'] : ''),
         $d['nama'] . ' · ' . stageLabel((string) $qq['stage']) . ($asal ? ' · revisi dari ' . $asal['nomor'] : ''),
         '<a class="btn ghost" target="_blank" href="?id=' . $qid . '&pdf=1">Lihat PDF ↗</a> '
       . '<a class="btn ghost" href="penawaran.php?client=' . $cid . '">Riwayat</a> '
       . '<a class="btn ghost" href="klien.php?id=' . $cid . '">← Klien</a>');
[$stL, $stC] = statusPenawaran($qq['status']);
?>

<div class="grid g4">
  <div class="stat"><span class="n" style="font-size:22px"><?= $rp($hitung['paket']) ?></span><span class="d">Harga paket</span></div>
  <div class="stat"><span class="n" style="font-size:22px"><?= $rp($hitung['tambahan']) ?></span><span class="d">Tambahan</span></div>
  <div class="stat accent"><span class="n" style="font-size:22px"><?= $rp($hitung['total']) ?></span><span class="d">Total</span></div>
  <div class="stat"><span class="n" style="font-size:22px"><?= $hitung['total'] > 0 ? rupiah(round($hitung['total'] * 0.3)) : '—' ?></span><span class="d">DP 30%</span></div>
</div>

<?php if (!empty($qq['nego_nilai'])): $selisih = (float) $qq['total'] - (float) $qq['nego_nilai']; ?>
  <div class="card" style="margin-top:18px;border-color:var(--ember-line)">
    <h2>Tawaran klien</h2>
    <p class="sub" style="margin:0">Kita ajukan <b><?= rupiah((float) $qq['total']) ?></b>, klien minta
      <b style="color:var(--ember)"><?= rupiah((float) $qq['nego_nilai']) ?></b> — selisih <b><?= rupiah(abs($selisih)) ?></b>.
      <?= trim((string) $qq['nego_catatan']) !== '' ? e($qq['nego_catatan']) : '' ?></p>
  </div>
<?php endif; ?>

<form method="post" id="fDok" class="card" style="margin-top:18px">
  <?= csrfField() ?><input type="hidden" name="act" value="simpan"><input type="hidden" name="id" value="<?= $qid ?>">
  <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:baseline">
    <h2 style="margin:0">Isi dokumen <span class="pill <?= $stC ?>" style="margin-left:8px"><?= e($stL) ?></span></h2>
    <?php if (!empty($qq['template_id'])): $tplAsal = one("SELECT nama FROM quote_templates WHERE id = ?", [$qq['template_id']]); ?>
      <span class="lab">dari paket: <?= e($tplAsal['nama'] ?? '(terhapus)') ?></span>
    <?php endif; ?>
  </div>
  <?php if ($terkunci): ?>
    <p class="sub" style="color:var(--ember)">Sudah diputus, jadi terkunci. Untuk mengubah isinya, buat revisi.</p>
  <?php elseif (!$bolehSunting): ?>
    <p class="sub" style="color:var(--ember)">Kamu bisa membaca isinya; angkanya disusun admin early.</p>
  <?php endif; ?>

  <fieldset <?= $sunting ? '' : 'disabled' ?> style="border:0;padding:0;margin:14px 0 0">
    <div class="row c3">
      <div class="field"><label>Jenis</label>
        <select name="jenis"><option value="pricelist" <?= $isPL ? 'selected' : '' ?>>Price list</option>
          <option value="penawaran" <?= !$isPL ? 'selected' : '' ?>>Penawaran</option></select></div>
      <div class="field"><label>Nama paket</label><input type="text" name="paket_nama" value="<?= e((string) $qq['paket_nama']) ?>" placeholder="kosongkan kalau bukan paket"></div>
      <div class="field"><label>Harga paket (Rp)</label><input type="text" class="uang" inputmode="numeric" name="paket_harga"
             value="<?= (float) $qq['paket_harga'] > 0 ? number_format((float) $qq['paket_harga'], 0, ',', '.') : '' ?>"></div>
    </div>

    <div id="rincian" style="overflow-x:auto;margin-top:6px">
      <datalist id="kelList"><?php foreach ($kelompokSaran as $k): ?><option value="<?= e($k) ?>"><?php endforeach; ?></datalist>
      <table class="tbl" style="min-width:880px">
        <thead><tr><th style="width:15%">Kelompok</th><th style="width:22%">Isi / uraian</th><th style="width:22%">Keterangan</th>
          <th style="width:7%">Qty</th><th style="width:9%">Satuan</th><th style="width:13%" class="num">Harga</th><th style="width:6%">Opsi</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $i => $it): $k = (int) $it['id']; ?>
          <tr class="<?= $it['opsional'] ? 'baris-opsi' : ((float) $it['harga'] > 0 ? 'baris-tambah' : '') ?>">
            <td><input type="text" name="item[<?= $k ?>][kelompok]" value="<?= e((string) $it['kelompok']) ?>" list="kelList">
                <input type="hidden" name="item[<?= $k ?>][sort]" value="<?= ($i + 1) * 10 ?>"></td>
            <td><input type="text" name="item[<?= $k ?>][label]" value="<?= e($it['label']) ?>"></td>
            <td><input type="text" name="item[<?= $k ?>][detail]" value="<?= e($it['detail']) ?>"></td>
            <td><input type="text" inputmode="decimal" name="item[<?= $k ?>][qty]" value="<?= rtrim(rtrim(number_format((float) $it['qty'], 2, '.', ''), '0'), '.') ?>"></td>
            <td><input type="text" name="item[<?= $k ?>][satuan]" value="<?= e($it['satuan']) ?>"></td>
            <td><input type="text" class="uang" inputmode="numeric" name="item[<?= $k ?>][harga]"
                       value="<?= (float) $it['harga'] > 0 ? number_format((float) $it['harga'], 0, ',', '.') : '' ?>" placeholder="termasuk"></td>
            <td style="text-align:center"><input type="checkbox" name="item[<?= $k ?>][opsional]" value="1" <?= $it['opsional'] ? 'checked' : '' ?> title="Opsional: di luar total"></td>
            <td class="actions"><?php if ($sunting): ?><button class="btn sm ghost danger" type="submit" form="h<?= $k ?>">×</button><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?><tr><td colspan="8" class="sub" style="padding:16px">Belum ada baris.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <p class="hint">Harga <b>kosong</b> = termasuk paket (tampil tanpa harga). Harga <b>diisi</b> = tambahan yang masuk total.
      Centang <b>Opsi</b> = tawaran tambahan di luar total. Judul dikosongkan = baris dihapus saat simpan.</p>

    <div class="row c3" style="margin-top:12px">
      <div class="field"><label>Potongan (Rp)</label><input type="text" class="uang" inputmode="numeric" name="diskon" value="<?= (float) $qq['diskon'] > 0 ? number_format((float) $qq['diskon'], 0, ',', '.') : '' ?>"></div>
      <div class="field"><label>Berlaku sampai</label><input type="date" name="valid_until" value="<?= e((string) $qq['valid_until']) ?>"></div>
      <div></div>
    </div>
    <div class="field"><label>Catatan & ketentuan (satu baris = satu butir di PDF)</label>
      <textarea name="catatan" rows="5"><?= e((string) $qq['catatan']) ?></textarea></div>
  </fieldset>

  <?php if ($sunting): ?>
  <div class="aksi" style="margin-top:8px">
    <button class="btn solid" type="submit">Simpan</button>
    <button class="btn ghost" type="submit" form="bIsi">+ Isi paket</button>
    <button class="btn ghost" type="submit" form="bTambah">+ Tambahan berbayar</button>
    <button class="btn ghost" type="submit" form="bOpsi">+ Opsional</button>
  </div>
  <?php endif; ?>
</form>
<?php if ($sunting): ?>
  <form method="post" id="bIsi"><?= csrfField() ?><input type="hidden" name="act" value="baris"><input type="hidden" name="id" value="<?= $qid ?>"><input type="hidden" name="jenis_baris" value="isi"></form>
  <form method="post" id="bTambah"><?= csrfField() ?><input type="hidden" name="act" value="baris"><input type="hidden" name="id" value="<?= $qid ?>"><input type="hidden" name="jenis_baris" value="tambahan"></form>
  <form method="post" id="bOpsi"><?= csrfField() ?><input type="hidden" name="act" value="baris"><input type="hidden" name="id" value="<?= $qid ?>"><input type="hidden" name="jenis_baris" value="opsi"></form>
  <?php foreach ($items as $it): ?>
    <form method="post" id="h<?= (int) $it['id'] ?>"><?= csrfField() ?><input type="hidden" name="act" value="hapus_baris">
      <input type="hidden" name="id" value="<?= $qid ?>"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>"></form>
  <?php endforeach; ?>
<?php endif; ?>

<?php if ($bolehSunting && !$terkunci):
  $sudahKirim = !empty($qq['sent_at']);
  $teks = quoteTeksWA($qid);
  $noKlien = waNomor((string) $qq['phone']); ?>
<div class="langkah" style="margin-top:18px">
  <div class="langkah-no <?= $sudahKirim ? 'ok' : 'now' ?>">1</div>
  <div class="card" style="margin:0">
    <h2>Kirim ke klien</h2>
    <p class="sub">PDF <?= $isPL ? 'price list' : 'penawaran' ?> + ringkasan di chat.
      <?= $sudahKirim ? 'Terkirim ' . e(tanggalID(substr($qq['sent_at'], 0, 10))) . ($qq['seen_at'] ? ' · <span style="color:var(--sage)">sudah dibuka klien</span>' : ' · belum dibuka klien') . '.'
                      : 'Begitu terkirim, tahap klien maju ke <b>Price list terkirim</b>.' ?></p>
    <?php if ($hitung['total'] <= 0): ?>
      <p class="hint" style="color:var(--ember);margin:0 0 10px">Totalnya masih Rp 0 — klien akan menerima "harga dikonfirmasi admin".</p>
    <?php endif; ?>
    <div class="aksi">
      <?php if (waSiap()): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Kirim lewat WhatsApp ke <?= e(waTampil($noKlien)) ?> beserta PDF-nya?')">
          <?= csrfField() ?><input type="hidden" name="act" value="kirim"><input type="hidden" name="id" value="<?= $qid ?>">
          <input type="hidden" name="via_wa" value="1">
          <button class="btn solid" type="submit" <?= $noKlien ? '' : 'disabled title="Nomor WA klien kosong"' ?>>Kirim PDF lewat WhatsApp</button></form>
      <?php endif; ?>
      <a class="btn <?= waSiap() ? 'ghost' : 'solid' ?>" href="?id=<?= $qid ?>&pdf=1&unduh=1">Unduh PDF</a>
      <?php if ($noKlien): ?>
        <a class="btn ghost" target="_blank" rel="noopener" href="https://wa.me/<?= e($noKlien) ?>?text=<?= rawurlencode($teks) ?>">Buka WhatsApp klien ↗</a>
      <?php endif; ?>
      <form method="post" style="display:inline"><?= csrfField() ?>
        <input type="hidden" name="act" value="kirim"><input type="hidden" name="id" value="<?= $qid ?>">
        <button class="btn ghost" type="submit"><?= $sudahKirim ? 'Tandai terkirim lagi' : 'Sudah saya kirim manual' ?></button></form>
    </div>
    <details style="margin-top:12px">
      <summary class="hint" style="cursor:pointer">Lihat / salin teks pesan</summary>
      <textarea rows="10" readonly id="teksWa" style="margin-top:8px;font-family:var(--mono);font-size:12px"><?= e($teks) ?></textarea>
      <button type="button" class="btn sm ghost" style="margin-top:6px" id="salinWa">Salin teks</button>
    </details>
    <?php if (!waSiap()): ?>
      <p class="hint" style="margin-top:10px">Kirim manual: unduh PDF, buka WhatsApp klien, lampirkan PDF-nya, lalu tekan "Sudah saya kirim manual".
        Pengiriman otomatis aktif setelah WhatsApp diatur di <a href="integrasi.php#wa" style="color:var(--ember)">Integrasi</a>.</p>
    <?php endif; ?>
  </div>
</div>

<div class="langkah">
  <div class="langkah-no <?= $sudahKirim ? 'now' : '' ?>">2</div>
  <div class="card" style="margin:0">
    <h2>Tanggapan klien</h2>
    <div class="pilih3">
      <form method="post" class="pilih" onsubmit="return confirm('Klien cocok? Tahap jadi Menunggu DP dan DP 30% (<?= e(rupiah(round($hitung['total'] * 0.3))) ?>) ditagih.')">
        <?= csrfField() ?><input type="hidden" name="act" value="cocok"><input type="hidden" name="id" value="<?= $qid ?>">
        <b>Cocok</b>
        <span>Lanjut tagih DP 30%<?= $hitung['total'] > 0 ? ' — ' . e(rupiah(round($hitung['total'] * 0.3))) : '' ?>. Setelah DP masuk, klien diserahkan ke admin office.</span>
        <button class="btn solid sm" type="submit">Klien cocok → tagih DP</button>
      </form>
      <div class="pilih">
        <b>Menawar / minta ubah</b>
        <span>Catat angka yang diminta, lalu buat revisi.</span>
        <details>
          <summary class="btn sm" style="list-style:none">Catat tawaran</summary>
          <form method="post" style="margin-top:10px">
            <?= csrfField() ?><input type="hidden" name="act" value="nego"><input type="hidden" name="id" value="<?= $qid ?>">
            <div class="field"><label>Angka yang diminta (Rp)</label>
              <input type="text" inputmode="numeric" class="uang" name="nego_nilai" value="<?= !empty($qq['nego_nilai']) ? number_format((float) $qq['nego_nilai'], 0, ',', '.') : '' ?>"></div>
            <div class="field"><label>Alasannya</label><input type="text" name="nego_catatan" value="<?= e((string) ($qq['nego_catatan'] ?? '')) ?>"></div>
            <button class="btn sm" type="submit">Simpan tawaran</button>
          </form>
        </details>
        <form method="post" style="margin-top:8px"><?= csrfField() ?>
          <input type="hidden" name="act" value="revisi"><input type="hidden" name="id" value="<?= $qid ?>">
          <button class="btn sm ghost" type="submit">Buat revisi →</button></form>
      </div>
      <div class="pilih">
        <b>Tidak cocok — mundur</b>
        <span>Klien tidak lanjut. Pindah ke arsip, sebabnya dicatat di Analisa.</span>
        <details>
          <summary class="btn sm ghost danger" style="list-style:none">Catat tidak cocok</summary>
          <form method="post" style="margin-top:10px"><?= csrfField() ?>
            <input type="hidden" name="act" value="tidak_cocok"><input type="hidden" name="id" value="<?= $qid ?>">
            <div class="field"><label>Alasan (wajib)</label><input type="text" name="alasan" required placeholder="Ambil WO lain, budget tidak ketemu…"></div>
            <button class="btn sm danger" type="submit">Catat &amp; tutup klien</button>
          </form>
        </details>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h2>Riwayat dokumen klien ini</h2>
  <table class="tbl">
    <thead><tr><th>Nomor</th><th>Paket</th><th>Status</th><th class="num">Total</th><th class="num">Klien minta</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lain as $r): [$sl, $sc] = statusPenawaran($r['status']); ?>
      <tr style="<?= (int) $r['id'] === $qid ? 'background:var(--ivory-07)' : '' ?>">
        <td data-l="Nomor"><b><?= e($r['nomor']) ?></b><?= $r['revisi'] > 1 ? ' <span class="muted">rev ' . (int) $r['revisi'] . '</span>' : '' ?>
          <br><span class="muted mono" style="font-size:10.5px"><?= $r['jenis'] === 'pricelist' ? 'price list' : 'penawaran' ?></span></td>
        <td data-l="Paket"><?= e((string) ($r['paket_nama'] ?? '') ?: '—') ?></td>
        <td data-l="Status"><span class="pill <?= $sc ?>"><?= e($sl) ?></span></td>
        <td class="num" data-l="Total"><?= (float) $r['total'] > 0 ? rupiah((float) $r['total']) : 'Rp 0' ?></td>
        <td class="num" data-l="Klien minta"><?= !empty($r['nego_nilai']) ? rupiah((float) $r['nego_nilai']) : '—' ?></td>
        <td class="actions"><a class="btn sm ghost" target="_blank" href="?id=<?= (int) $r['id'] ?>&pdf=1">PDF</a>
          <?php if ((int) $r['id'] !== $qid): ?><a class="btn sm" href="?id=<?= (int) $r['id'] ?>">Buka</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<style>
input.uang{text-align:right;font-family:var(--mono);font-variant-numeric:tabular-nums;letter-spacing:.3px}
fieldset:disabled input, fieldset:disabled select, fieldset:disabled textarea{opacity:.65}
tr.baris-opsi td{background:var(--ivory-07)}
tr.baris-tambah td:nth-child(6) input{border-color:var(--ember-line)}
</style>
<script>
(() => {
  const fmt = v => { const a = String(v).replace(/\D/g, '').replace(/^0+(?=\d)/, ''); return a ? a.replace(/\B(?=(\d{3})+(?!\d))/g, '.') : ''; };
  document.querySelectorAll('input.uang').forEach(el => el.addEventListener('input', () => { el.value = fmt(el.value); }));
  document.getElementById('salinWa')?.addEventListener('click', async e => {
    try { await navigator.clipboard.writeText(document.getElementById('teksWa').value);
          e.target.textContent = 'Tersalin'; setTimeout(() => e.target.textContent = 'Salin teks', 1600); } catch (_) {}
  });
  // Menambah baris lewat tombol terpisah membuang ketikan yang belum disimpan.
  const f = document.getElementById('fDok'); let ubah = false;
  f?.addEventListener('input', () => { ubah = true; }); f?.addEventListener('submit', () => { ubah = false; });
  ['bIsi', 'bTambah', 'bOpsi'].forEach(id => document.getElementById(id)?.addEventListener('submit', e => {
    if (ubah && !confirm('Ada perubahan yang belum disimpan. Lanjut tanpa menyimpan?')) e.preventDefault();
  }));
})();
</script>
<?php adminFoot();
