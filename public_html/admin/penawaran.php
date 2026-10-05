<?php
/**
 * ============================================================
 * PENAWARAN
 * ============================================================
 *
 * Halaman yang selama ini tidak ada. inc/penawaran.php sudah lengkap sejak
 * lama — quoteBuat(), quoteHitung(), quoteTeksWA(), quoteKirimWA(),
 * quoteCocok() — tapi tidak satu pun dipanggil dari mana pun. Tahap
 * "penawaran" di pipeline cuma label yang dimajukan manual, tanpa dokumen
 * apa pun di baliknya.
 *
 * Alur yang ditangani di sini, semuanya milik admin early:
 *
 *   Buat  → item terisi otomatis dari jenis vendor + Top 5 yang dicentang
 *   Sunting → harga per baris, tandai opsional, diskon, catatan
 *   Kirim → teks WhatsApp + tautan publik; status jadi 'terkirim'
 *   Nego  → catat angka yang DIMINTA klien beserta alasannya
 *   Revisi → penawaran baru yang menyalin item lama untuk disesuaikan
 *   Putus → cocok (klien deal, pindah ke admin office) atau tidak cocok
 *
 * Admin office boleh membuka tapi tidak menyunting: dia perlu membaca
 * seluruh riwayat tawar-menawar untuk tahu apa yang sudah dijanjikan dan
 * apa yang dikorbankan, tapi angkanya sudah tidak lagi urusannya.
 */
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/penawaran.php';
require_once __DIR__ . '/../inc/pipeline.php';
$user = requireLogin();

$peranSaya   = $user['role'] ?? '';
$bolehSunting = in_array($peranSaya, ['owner', 'admin_early'], true);

/** Penjaga tunggal untuk semua aksi yang mengubah. */
function pastikanBolehSunting(bool $boleh): void
{
    if (!$boleh) throw new RuntimeException(
        'Penawaran disusun admin early. Admin office bisa membaca riwayatnya, tapi tidak mengubah angkanya.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';
    try {
        pastikanBolehSunting($bolehSunting);

        if ($act === 'buat') {
            $cid   = (int) ($_POST['client_id'] ?? 0);
            $jenis = ($_POST['jenis'] ?? '') === 'pricelist' ? 'pricelist' : 'penawaran';
            if (!one("SELECT id FROM clients WHERE id = ?", [$cid])) throw new RuntimeException('Klien tidak ditemukan.');

            $qid = quoteBuat($cid, $jenis, (int) $user['id']);

            // Template dipakai sebagai TITIK AWAL, lalu berdiri sendiri. Isinya
            // disalin utuh ke penawaran — bukan dirujuk. Mengubah template
            // besok tidak boleh mengubah dokumen yang hari ini sudah dipegang
            // klien; template_id disimpan hanya sebagai catatan asal.
            $tplId = (int) ($_POST['template_id'] ?? 0);
            if ($tplId) {
                $tpl = one("SELECT * FROM quote_templates WHERE id = ?", [$tplId]);
                if (!$tpl) throw new RuntimeException('Template tidak ditemukan.');

                q("DELETE FROM quote_items WHERE quote_id = ?", [$qid]);
                q("INSERT INTO quote_items (quote_id, category_id, label, detail, qty, satuan, harga, jumlah, opsional, sort_order)
                   SELECT ?, category_id, label, detail, qty, satuan, harga, harga * qty, opsional, sort_order
                     FROM quote_template_items WHERE template_id = ? ORDER BY sort_order, id",
                  [$qid, $tplId]);
                q("UPDATE quotes SET template_id = ?, catatan = ? WHERE id = ?",
                  [$tplId, (string) $tpl['catatan_bawaan'], $qid]);
                quoteHitung($qid);

                clientLog($cid, 'sistem', ucfirst($jenis) . ' dibuat dari template',
                          $tpl['nama'], $user['id']);
                flash('Dibuat dari template "' . $tpl['nama'] . '". Sesuaikan harga dan barisnya di sini.');
                redirect('admin/penawaran.php?id=' . $qid);
            }

            if (!one("SELECT id FROM quote_items WHERE quote_id = ? LIMIT 1", [$qid])) {
                flash('Penawaran dibuat, tapi belum ada barisnya — jenis vendor di halaman klien belum dicentang. '
                    . 'Pakai template, centang dulu kebutuhannya, atau tambahkan barisnya manual di bawah.', 'err');
            } else {
                flash('Penawaran dibuat dari kebutuhan vendor yang sudah dicentang.');
            }
            clientLog($cid, 'sistem', ucfirst($jenis) . ' dibuat', '', $user['id']);
            redirect('admin/penawaran.php?id=' . $qid);
        }

        $qid = (int) ($_POST['id'] ?? 0);
        $qq  = $qid ? one("SELECT * FROM quotes WHERE id = ?", [$qid]) : null;
        if (!$qq) throw new RuntimeException('Penawaran tidak ditemukan.');
        $cid = (int) $qq['client_id'];

        if ($act === 'item_simpan') {
            // Seluruh tabel dikirim sekaligus. Menyimpan per baris lewat AJAX
            // terdengar lebih modern, tapi menyusun penawaran itu pekerjaan
            // sekali duduk — dan satu tombol Simpan lebih jujur soal apa yang
            // sudah masuk dan apa yang belum.
            foreach (($_POST['item'] ?? []) as $iid => $row) {
                $iid = (int) $iid;
                $harga = (float) str_replace(['.', ','], ['', '.'], (string) ($row['harga'] ?? 0));
                $qty   = max(0.01, (float) ($row['qty'] ?? 1));
                q("UPDATE quote_items
                      SET label = ?, detail = ?, qty = ?, satuan = ?, harga = ?, jumlah = ?,
                          opsional = ?, sort_order = ?
                    WHERE id = ? AND quote_id = ?",
                  [mb_substr(trim($row['label'] ?? ''), 0, 190),
                   mb_substr(trim($row['detail'] ?? ''), 0, 400),
                   $qty, mb_substr(trim($row['satuan'] ?? 'paket'), 0, 30),
                   $harga, $harga * $qty,
                   isset($row['opsional']) ? 1 : 0,
                   (int) ($row['sort'] ?? 0), $iid, $qid]);
            }
            $diskon = (float) str_replace(['.', ','], ['', '.'], (string) ($_POST['diskon'] ?? 0));
            q("UPDATE quotes SET diskon = ?, catatan = ?, valid_until = ? WHERE id = ?",
              [max(0, $diskon), mb_substr(trim($_POST['catatan'] ?? ''), 0, 2000),
               $_POST['valid_until'] ?: null, $qid]);
            quoteHitung($qid);
            flash('Penawaran disimpan.');
        }

        elseif ($act === 'item_tambah') {
            $urut = (int) (one("SELECT COALESCE(MAX(sort_order),0)+10 s FROM quote_items WHERE quote_id = ?", [$qid])['s'] ?? 0);
            q("INSERT INTO quote_items (quote_id, label, qty, satuan, harga, jumlah, sort_order)
               VALUES (?, 'Item baru', 1, 'paket', 0, 0, ?)", [$qid, $urut]);
            quoteHitung($qid);
        }

        elseif ($act === 'item_hapus') {
            q("DELETE FROM quote_items WHERE id = ? AND quote_id = ?", [(int) $_POST['item_id'], $qid]);
            quoteHitung($qid);
            flash('Baris dihapus.');
        }

        elseif ($act === 'kirim') {
            if ($qq['status'] === 'draf') {
                q("UPDATE quotes SET status = 'terkirim', sent_at = NOW() WHERE id = ?", [$qid]);
            }
            clientLog($cid, 'sistem', 'Penawaran ' . $qq['nomor'] . ' ditandai terkirim',
                      'Total ' . rupiah((float) $qq['total']), $user['id']);
            if (!empty($_POST['via_wa'])) {
                try {
                    quoteKirimWA($qid, (int) $user['id']);
                    flash('Ditandai terkirim dan pesan WhatsApp dikirim.');
                } catch (Throwable $e) {
                    flash('Ditandai terkirim, tapi WhatsApp gagal: ' . $e->getMessage(), 'err');
                }
            } else {
                flash('Ditandai terkirim. Salin teksnya di bawah kalau mau kirim manual.');
            }
        }

        elseif ($act === 'nego') {
            // Angka yang DIMINTA klien, bukan yang kita ajukan. Selisihnya yang
            // menentukan revisi berikutnya, dan selama ini cuma hidup di chat.
            $nilai = (float) str_replace(['.', ','], ['', '.'], (string) ($_POST['nego_nilai'] ?? 0));
            $alasan = mb_substr(trim($_POST['nego_catatan'] ?? ''), 0, 400);
            if ($nilai <= 0) throw new RuntimeException('Angka penawaran klien belum diisi.');

            q("UPDATE quotes SET nego_nilai = ?, nego_catatan = ?, nego_at = NOW(),
                                 status = IF(status IN ('draf'), status, 'revisi')
               WHERE id = ?", [$nilai, $alasan, $qid]);

            $selisih = (float) $qq['total'] - $nilai;
            clientLog($cid, 'catatan', 'Klien menawar ' . rupiah($nilai),
                      'Dari ' . rupiah((float) $qq['total']) . ' — selisih ' . rupiah($selisih)
                    . ($alasan ? '. ' . $alasan : ''), $user['id']);
            flash('Tawaran klien dicatat. Buat revisi untuk menyesuaikan angkanya.');
        }

        elseif ($act === 'revisi') {
            // Revisi = penawaran baru yang MENYALIN item lama, bukan menimpa.
            // Yang lama harus tetap utuh: itu yang dipegang klien, dan kalau
            // ada selisih ingatan soal apa yang dijanjikan, dokumen itu
            // satu-satunya rujukan.
            $baru = quoteBuat($cid, $qq['jenis'], (int) $user['id']);
            q("DELETE FROM quote_items WHERE quote_id = ?", [$baru]);
            q("INSERT INTO quote_items (quote_id, category_id, label, detail, qty, satuan, harga, jumlah, opsional, sort_order)
               SELECT ?, category_id, label, detail, qty, satuan, harga, jumlah, opsional, sort_order
                 FROM quote_items WHERE quote_id = ?", [$baru, $qid]);
            q("UPDATE quotes SET revisi_dari = ?, diskon = ?, catatan = ? WHERE id = ?",
              [$qid, $qq['diskon'], $qq['catatan'], $baru]);
            quoteHitung($baru);

            if ($qq['status'] !== 'tidak_cocok') q("UPDATE quotes SET status = 'revisi' WHERE id = ?", [$qid]);
            clientLog($cid, 'sistem', 'Revisi penawaran dibuat', 'Dari ' . $qq['nomor'], $user['id']);
            flash('Revisi dibuat menyalin baris sebelumnya. Sesuaikan angkanya di sini.');
            redirect('admin/penawaran.php?id=' . $baru);
        }

        elseif ($act === 'cocok') {
            quoteCocok($qid, (int) $user['id']);
            flash('Deal. Klien berpindah ke admin office beserta seluruh riwayat penawarannya.');
            redirect('admin/klien.php?id=' . $cid);
        }

        elseif ($act === 'tidak_cocok') {
            $alasan = trim($_POST['alasan'] ?? '');
            if ($alasan === '') throw new RuntimeException('Alasan wajib diisi — ini yang dibaca saat menganalisa penyebab.');
            quoteTidakCocok($qid, $alasan, (int) $user['id']);
            flash('Dicatat tidak cocok.');
        }

        redirect('admin/penawaran.php?id=' . $qid);
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
        redirect('admin/penawaran.php' . (!empty($qid) ? '?id=' . $qid : ''));
    }
}

$qid    = (int) ($_GET['id'] ?? 0);
$cidGet = (int) ($_GET['client'] ?? 0);

/* ============================================================
   TAMPILAN CETAK — dipisah supaya bisa disimpan jadi PDF
   ============================================================
   Tidak memakai pustaka PDF sama sekali. Sistemmu tanpa Composer, jadi
   Dompdf/mPDF berarti menyeret puluhan berkas ke shared hosting demi satu
   halaman. Peramban sudah punya mesin PDF yang lebih bagus daripada
   keduanya: Ctrl+P → Save as PDF. Hasilnya teks yang bisa dicari dan
   diseleksi, bukan gambar.
*/
if ($qid && isset($_GET['cetak'])) {
    $qq = one("SELECT q.*, c.name, c.partner_name, c.phone, c.email, c.wedding_date,
                      c.venue, c.guest_estimate, c.city
               FROM quotes q JOIN clients c ON c.id = q.client_id WHERE q.id = ?", [$qid]);
    if (!$qq) { http_response_code(404); die('Penawaran tidak ditemukan.'); }
    $items = all("SELECT * FROM quote_items WHERE quote_id = ? ORDER BY sort_order, id", [$qid]);
    $opsi  = array_filter($items, fn($i) => $i['opsional']);
    $wajib = array_filter($items, fn($i) => !$i['opsional']);
    $brand = setting('site_name', 'Callalily Party');

    // rupiah() mengembalikan "—" untuk nol. Di dokumen yang dikirim ke klien
    // itu tidak bisa: baris berharga nol artinya "termasuk, tanpa biaya
    // tambahan", bukan "belum diisi". Tanda pisah membuatnya terbaca seperti
    // data yang hilang, dan itu pertanyaan pertama yang muncul dari klien.
    // Di dalam tabel, angka ditulis telanjang — satuannya sudah disebut sekali
    // di kepala kolom. Mengulang "Rp" di tiap baris membuat kolomnya ramai dan
    // justru mempersulit membandingkan besaran antar baris, yang merupakan
    // satu-satunya alasan klien membaca tabel ini.
    $ang = fn($n) => number_format((float) $n, 0, ',', '.');

    // Di baris total, satuannya ditulis lengkap. Angka itu yang disalin ke
    // percakapan dan transfer, jadi tidak boleh ambigu.
    $rpc = fn($n) => setting('currency_prefix', 'Rp') . ' ' . number_format((float) $n, 0, ',', '.');
    ?><!doctype html><html lang="id"><head><meta charset="utf-8">
    <title><?= e($qq['nomor']) ?> — <?= e($qq['name']) ?></title>
    <style>
      @page { size: A4; margin: 16mm 14mm; }
      * { box-sizing: border-box }
      body { font: 11pt/1.55 Georgia,'Times New Roman',serif; color: #1a1a1a; margin: 0 }
      .no-print { margin-bottom: 18px }
      @media print { .no-print { display: none } }
      h1 { font-size: 22pt; margin: 0 0 2px; letter-spacing: .5px }
      .sub { color: #666; font-size: 9.5pt; margin: 0 }
      .head { display: flex; justify-content: space-between; align-items: flex-start;
              border-bottom: 2px solid #1a1a1a; padding-bottom: 12px; margin-bottom: 18px }
      .meta { text-align: right; font-size: 9.5pt; color: #444 }
      .info { display: flex; gap: 34px; margin-bottom: 20px; font-size: 10pt }
      .info b { display: block; font-size: 8pt; text-transform: uppercase;
                letter-spacing: 1px; color: #888; font-weight: normal; margin-bottom: 2px }
      table { width: 100%; border-collapse: collapse; margin-bottom: 6px }
      th { text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: 1px;
           color: #888; font-weight: normal; border-bottom: 1px solid #ccc; padding: 7px 6px }
      td { padding: 9px 6px; border-bottom: 1px solid #eee; vertical-align: top }
      /* tabular-nums membuat lebar tiap digit sama, jadi kolom angka lurus
         ke bawah. Tanpa ini, "1" jauh lebih sempit daripada "8" dan deretan
         rupiah terlihat bergerigi di kertas. */
      td.r, th.r { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums }
      .tot { width: 46%; margin-left: auto }
      .tot td:first-child { color: #555 }
      .det { font-size: 9pt; color: #666; display: block; margin-top: 2px }
      .tot td { border: none; padding: 5px 6px; font-size: 11pt }
      .tot .grand td { border-top: 2px solid #1a1a1a; font-size: 13pt; font-weight: bold; padding-top: 10px }
      .opsi { margin-top: 22px }
      .opsi h3 { font-size: 10pt; text-transform: uppercase; letter-spacing: 1px; color: #888;
                 font-weight: normal; margin: 0 0 6px }
      .note { margin-top: 24px; padding-top: 14px; border-top: 1px solid #ddd;
              font-size: 9.5pt; color: #444; white-space: pre-wrap }
      .foot { margin-top: 30px; font-size: 9pt; color: #888; text-align: center }
      button { font: inherit; padding: 9px 20px; cursor: pointer; border: 1px solid #1a1a1a;
               background: #1a1a1a; color: #fff; border-radius: 6px }
      a.kembali { font-size: 10pt; margin-left: 12px; color: #666 }
    </style></head><body>

    <div class="no-print">
      <button onclick="window.print()">Cetak / Simpan sebagai PDF</button>
      <a class="kembali" href="penawaran.php?id=<?= $qid ?>">← kembali menyunting</a>
    </div>

    <div class="head">
      <div>
        <h1><?= e($brand) ?></h1>
        <p class="sub"><?= e(setting('site_tagline', 'Wedding Organizer · Yogyakarta')) ?></p>
      </div>
      <div class="meta">
        <b><?= $qq['jenis'] === 'pricelist' ? 'PRICE LIST' : 'PENAWARAN' ?></b><br>
        <?= e($qq['nomor']) ?><?= $qq['revisi'] > 1 ? ' · Revisi ' . (int) $qq['revisi'] : '' ?><br>
        <?= tanggalID(substr($qq['created_at'], 0, 10)) ?>
        <?php if ($qq['valid_until']): ?><br>Berlaku sampai <?= tanggalID($qq['valid_until']) ?><?php endif; ?>
      </div>
    </div>

    <div class="info">
      <div><b>Untuk</b><?= e($qq['name'] . ($qq['partner_name'] ? ' & ' . $qq['partner_name'] : '')) ?></div>
      <?php if ($qq['wedding_date']): ?><div><b>Tanggal acara</b><?= tanggalID($qq['wedding_date']) ?></div><?php endif; ?>
      <?php if ($qq['venue']): ?><div><b>Venue</b><?= e($qq['venue']) ?></div><?php endif; ?>
      <?php if ($qq['guest_estimate']): ?><div><b>Tamu</b><?= number_format((int) $qq['guest_estimate'], 0, ',', '.') ?> undangan</div><?php endif; ?>
    </div>

    <table>
      <thead><tr><th style="width:52%">Uraian</th><th class="r">Qty</th><th class="r">Harga (Rp)</th><th class="r">Jumlah (Rp)</th></tr></thead>
      <tbody>
        <?php foreach ($wajib as $it): ?>
          <tr>
            <td><?= e($it['label']) ?><?php if ($it['detail']): ?><span class="det"><?= e($it['detail']) ?></span><?php endif; ?></td>
            <td class="r"><?= rtrim(rtrim(number_format((float) $it['qty'], 2, ',', '.'), '0'), ',') ?> <?= e($it['satuan']) ?></td>
            <td class="r"><?= $ang($it['harga']) ?></td>
            <td class="r"><?= $ang($it['jumlah']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$wajib): ?><tr><td colspan="4" style="color:#999">Belum ada baris.</td></tr><?php endif; ?>
      </tbody>
    </table>

    <table class="tot">
      <tr><td>Subtotal</td><td class="r"><?= $rpc($qq['subtotal']) ?></td></tr>
      <?php if ((float) $qq['diskon'] > 0): ?>
        <tr><td>Diskon</td><td class="r">− <?= $rpc($qq['diskon']) ?></td></tr>
      <?php endif; ?>
      <tr class="grand"><td>Total</td><td class="r"><?= $rpc($qq['total']) ?></td></tr>
    </table>

    <?php if ($opsi): ?>
      <div class="opsi">
        <h3>Opsional — di luar total (Rp)</h3>
        <table>
          <tbody>
          <?php foreach ($opsi as $it): ?>
            <tr><td><?= e($it['label']) ?><?php if ($it['detail']): ?><span class="det"><?= e($it['detail']) ?></span><?php endif; ?></td>
                <td class="r"><?= $ang($it['jumlah']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if (trim((string) $qq['catatan']) !== ''): ?>
      <div class="note"><?= e($qq['catatan']) ?></div>
    <?php endif; ?>

    <div class="foot">
      <?= e($brand) ?><?php if (setting('wa_number', '')): ?> · WhatsApp <?= e(setting('wa_number')) ?><?php endif; ?>
      <?php if (setting('site_url', '')): ?> · <?= e(setting('site_url')) ?><?php endif; ?>
    </div>
    </body></html><?php
    exit;
}

/* ============================================================
   DAFTAR PENAWARAN SATU KLIEN
   ============================================================ */
if ($cidGet && !$qid) {
    $c = one("SELECT * FROM clients WHERE id = ?", [$cidGet]);
    if (!$c) { http_response_code(404); die('Klien tidak ditemukan.'); }
    $riwayat = all("SELECT * FROM quotes WHERE client_id = ? ORDER BY id DESC", [$cidGet]);

    adminHead('Penawaran', 'klien');
    pageHead('Penawaran — ' . $c['name'] . ($c['partner_name'] ? ' & ' . $c['partner_name'] : ''),
             'Seluruh riwayat pengajuan, tawar-menawar, dan revisi untuk klien ini.',
             ($bolehSunting ? '<a class="btn ghost" href="template-penawaran.php">Kelola template</a> ' : '')
           . '<a class="btn ghost" href="klien.php?id=' . (int) $c['id'] . '">← Halaman klien</a>');

    // Template yang cocok untuk tipe klien ini. Yang dibatasi ke tipe lain
    // sengaja tidak ditampilkan — daftar pilihan yang memuat template yang
    // tidak boleh dipakai cuma memperlambat dan mengundang salah pilih.
    $tpl = []; $tplGagal = false;
    try {
        $tpl = all("SELECT * FROM quote_templates
                     WHERE is_active = 1 AND tipe IN ('semua', ?)
                     ORDER BY urutan, id", [$c['tipe_klien'] ?: 'semua']);
    } catch (Throwable $e) {
        // Tabelnya belum ada berarti migration-v20 belum dijalankan. Itu beda
        // dengan "belum ada template", dan menyamakan keduanya membuat orang
        // mencari-cari tombol yang memang belum bisa muncul.
        $tplGagal = true;
    }

    if ($bolehSunting): ?>
      <div class="card">
        <h2>Buat dari template</h2>
        <?php if ($tpl): ?>
          <p class="sub">Titik awal untuk kiriman pertama, saat kebutuhan vendor belum dicentang.
            Isinya disalin utuh lalu bisa diubah — template tidak ikut berubah.</p>
          <?php foreach ($tpl as $t):
            $nilai = (float) (one("SELECT COALESCE(SUM(IF(opsional,0,harga*qty)),0) n
                                   FROM quote_template_items WHERE template_id = ?", [$t['id']])['n'] ?? 0); ?>
            <div style="display:flex;gap:13px;align-items:center;justify-content:space-between;
                        flex-wrap:wrap;padding:13px 0;border-bottom:1px solid var(--ivory-07)">
              <div style="flex:1;min-width:230px">
                <b style="color:var(--ivory)"><?= e($t['nama']) ?></b>
                <?php if ($t['tipe'] !== 'semua'): ?><span class="lab" style="margin-left:7px"><?= e($t['tipe']) ?></span><?php endif; ?>
                <?php if ($t['deskripsi']): ?><span class="muted" style="display:block;font-size:12.5px;margin-top:2px"><?= e($t['deskripsi']) ?></span><?php endif; ?>
              </div>
              <span class="mono" style="font-size:12.5px;color:var(--ivory-60)"><?= $nilai > 0 ? rupiah($nilai, true) : 'Rp 0' ?></span>
              <div style="display:flex;gap:7px">
                <?php foreach (['pricelist' => 'Price list', 'penawaran' => 'Penawaran'] as $j => $lbl): ?>
                  <form method="post" style="display:inline">
                    <?= csrfField() ?><input type="hidden" name="act" value="buat">
                    <input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>">
                    <input type="hidden" name="jenis" value="<?= $j ?>">
                    <input type="hidden" name="template_id" value="<?= (int) $t['id'] ?>">
                    <button class="btn sm <?= $j === 'penawaran' ? 'solid' : '' ?>" type="submit"><?= $lbl ?></button>
                  </form>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php elseif ($tplGagal): ?>
          <p class="sub" style="color:var(--ember)">Tabel template belum ada di database —
            <b>migration-v20.sql</b> belum dijalankan. Jalankan dulu lewat phpMyAdmin,
            lalu muat ulang halaman ini.</p>
        <?php else: ?>
          <p class="sub">Belum ada template aktif untuk tipe klien ini.
            <a href="template-penawaran.php" style="color:var(--ember)">Buat satu →</a></p>
        <?php endif; ?>
        <p class="hint" style="margin-top:13px">
          <a href="template-penawaran.php" style="color:var(--ember)">Kelola template →</a>
        </p>
      </div>

      <div class="card">
        <h2>Buat tanpa template</h2>
        <p class="sub">Barisnya terisi dari jenis vendor dan Top 5 yang sudah dicentang di halaman
          klien. Cocok setelah konsultasi, saat kebutuhannya sudah jelas. Kalau belum ada yang
          dicentang, penawarannya lahir kosong.</p>
        <div style="display:flex;gap:9px;flex-wrap:wrap">
          <?php foreach (['pricelist' => 'Price list (kiriman awal)', 'penawaran' => 'Penawaran (setelah spesifikasi)'] as $j => $lbl): ?>
            <form method="post" style="display:inline">
              <?= csrfField() ?><input type="hidden" name="act" value="buat">
              <input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>">
              <input type="hidden" name="jenis" value="<?= $j ?>">
              <button class="btn" type="submit">+ <?= $lbl ?></button>
            </form>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2>Riwayat <span class="lab" style="margin-left:8px"><?= count($riwayat) ?></span></h2>
      <?php if (!$riwayat): ?>
        <p class="sub">Belum ada penawaran untuk klien ini.</p>
      <?php else: ?>
        <table class="tbl">
          <thead><tr><th>Nomor</th><th>Jenis</th><th>Status</th><th class="num">Kita ajukan</th>
                     <th class="num">Klien minta</th><th>Tanggal</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($riwayat as $r): ?>
            <tr>
              <td data-l="Nomor"><b><?= e($r['nomor']) ?></b><?= $r['revisi'] > 1 ? ' <span class="muted">rev ' . (int) $r['revisi'] . '</span>' : '' ?></td>
              <td data-l="Jenis"><?= $r['jenis'] === 'pricelist' ? 'Price list' : 'Penawaran' ?></td>
              <td data-l="Status"><span class="pill <?= $r['status'] === 'cocok' ? 'ok' : ($r['status'] === 'tidak_cocok' ? 'bad' : 'draft') ?>"><?= e(str_replace('_', ' ', $r['status'])) ?></span></td>
              <td class="num" data-l="Kita ajukan"><?= rupiah((float) $r['total']) ?></td>
              <td class="num" data-l="Klien minta"><?= $r['nego_nilai'] ? rupiah((float) $r['nego_nilai']) : '—' ?></td>
              <td class="num" data-l="Tanggal"><?= tanggalID(substr($r['created_at'], 0, 10)) ?></td>
              <td class="actions"><a class="btn sm" href="?id=<?= (int) $r['id'] ?>">Buka</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <?php adminFoot(); exit;
}

/* ============================================================
   SATU PENAWARAN
   ============================================================ */
if (!$qid) { redirect('admin/klien.php'); }

$qq = one("SELECT q.*, c.name, c.partner_name, c.stage, c.wedding_date, c.venue
           FROM quotes q JOIN clients c ON c.id = q.client_id WHERE q.id = ?", [$qid]);
if (!$qq) { http_response_code(404); die('Penawaran tidak ditemukan.'); }

$cid    = (int) $qq['client_id'];
$items  = all("SELECT * FROM quote_items WHERE quote_id = ? ORDER BY sort_order, id", [$qid]);
$hitung = quoteHitung($qid);
$lain   = all("SELECT * FROM quotes WHERE client_id = ? ORDER BY id DESC", [$cid]);
$asal   = $qq['revisi_dari'] ? one("SELECT nomor FROM quotes WHERE id = ?", [$qq['revisi_dari']]) : null;
$terkunci = in_array($qq['status'], ['cocok', 'tidak_cocok'], true);

adminHead('Penawaran', 'klien');
// pageHead() meng-escape sendiri. Memanggil e() di sini membuat "&" jadi
// "&amp;" di layar — nama klien dengan "&" adalah kasus paling umum di sini.
pageHead($qq['nomor'] . ($qq['revisi'] > 1 ? ' · revisi ' . (int) $qq['revisi'] : ''),
         $qq['name'] . ($qq['partner_name'] ? ' & ' . $qq['partner_name'] : '')
       . ($asal ? ' · revisi dari ' . $asal['nomor'] : ''),
         ($bolehSunting ? '<a class="btn ghost" href="template-penawaran.php">Template</a> ' : '')
       . '<a class="btn ghost" href="penawaran.php?client=' . $cid . '">Riwayat</a> '
       . '<a class="btn ghost" href="klien.php?id=' . $cid . '">← Klien</a>');
?>

<?php
// rupiah() memakai "—" untuk nol. Itu masuk akal di daftar klien, tapi di
// editor angka nol harus terbaca sebagai nol — kalau tidak, penawaran yang
// barisnya belum diisi tampak seperti gagal memuat.
$rp = fn($n) => (float) $n > 0 ? rupiah((float) $n, true) : 'Rp 0';
?>
<div class="grid g4">
  <div class="stat"><span class="n" style="font-size:22px"><?= $rp($hitung['subtotal']) ?></span><span class="d">Subtotal</span></div>
  <div class="stat"><span class="n" style="font-size:22px"><?= $rp($hitung['opsional']) ?></span><span class="d">Opsional</span></div>
  <div class="stat accent"><span class="n" style="font-size:22px"><?= $rp($hitung['total']) ?></span><span class="d">Total diajukan</span></div>
  <div class="stat"><span class="n" style="font-size:22px"><?= $qq['nego_nilai'] ? rupiah((float) $qq['nego_nilai'], true) : '—' ?></span><span class="d">Diminta klien</span></div>
</div>

<?php if ($qq['nego_nilai']): $selisih = (float) $qq['total'] - (float) $qq['nego_nilai']; ?>
  <div class="card" style="margin-top:18px;border-color:rgba(233,168,92,.45)">
    <h2>Tawaran klien</h2>
    <p class="sub" style="margin-bottom:9px">
      Kita ajukan <b><?= rupiah((float) $qq['total']) ?></b>, klien minta
      <b style="color:var(--ember)"><?= rupiah((float) $qq['nego_nilai']) ?></b> —
      selisih <b><?= rupiah(abs($selisih)) ?></b>
      (<?= (float) $qq['total'] > 0 ? number_format($selisih / (float) $qq['total'] * 100, 1) : '0' ?>%).
      Dicatat <?= tanggalID(substr((string) $qq['nego_at'], 0, 10)) ?>.
    </p>
    <?php if (trim((string) $qq['nego_catatan']) !== ''): ?>
      <p style="margin:0;color:var(--ivory)"><?= e($qq['nego_catatan']) ?></p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
    <h2 style="margin:0">Rincian
      <span class="pill <?= $qq['status'] === 'cocok' ? 'ok' : ($qq['status'] === 'tidak_cocok' ? 'bad' : 'draft') ?>"
            style="margin-left:8px"><?= e(str_replace('_', ' ', $qq['status'])) ?></span>
      <?php if ($qq['tipe'] === 'budgeting' && $qq['plafon']): ?>
        <span class="lab" style="margin-left:6px">plafon <?= rupiah((float) $qq['plafon'], true) ?></span>
      <?php endif; ?>
    </h2>
    <div style="display:flex;gap:9px;align-items:center">
      <?php if (!empty($qq['template_id'])):
        $tplAsal = one("SELECT nama FROM quote_templates WHERE id = ?", [$qq['template_id']]); ?>
        <span class="lab" title="Isi sudah disalin — mengubah template tidak mengubah penawaran ini">
          dari template: <?= e($tplAsal['nama'] ?? '(terhapus)') ?></span>
      <?php endif; ?>
      <a class="btn sm" href="?id=<?= $qid ?>&cetak=1" target="_blank">Lihat / cetak PDF →</a>
    </div>
  </div>

  <?php if ($terkunci): ?>
    <p class="sub" style="color:var(--ember)">Penawaran ini sudah diputus, jadi terkunci.
      Untuk mengubah angkanya, buat revisi baru — dokumen yang sudah dipegang klien tidak boleh berubah isinya.</p>
  <?php elseif (!$bolehSunting): ?>
    <p class="sub" style="color:var(--ember)">Kamu bisa membaca riwayatnya, tapi angkanya disusun admin early.</p>
  <?php endif; ?>

  <form method="post">
    <?= csrfField() ?><input type="hidden" name="act" value="item_simpan">
    <input type="hidden" name="id" value="<?= $qid ?>">

    <div style="overflow-x:auto">
      <table class="tbl" style="min-width:760px">
        <thead><tr>
          <th style="width:34%">Uraian</th><th style="width:26%">Detail</th>
          <th style="width:9%">Qty</th><th style="width:9%">Satuan</th>
          <th style="width:15%" class="num">Harga <span style="opacity:.5">(Rp)</span></th><th style="width:7%">Opsi</th><th></th>
        </tr></thead>
        <tbody>
        <?php $sunting = $bolehSunting && !$terkunci; foreach ($items as $i => $it): ?>
          <tr>
            <td><input type="text" name="item[<?= (int) $it['id'] ?>][label]" value="<?= e($it['label']) ?>" <?= $sunting ? '' : 'disabled' ?>>
                <input type="hidden" name="item[<?= (int) $it['id'] ?>][sort]" value="<?= $i * 10 ?>"></td>
            <td><input type="text" name="item[<?= (int) $it['id'] ?>][detail]" value="<?= e($it['detail']) ?>" <?= $sunting ? '' : 'disabled' ?>></td>
            <td><input type="number" step="0.5" min="0.5" name="item[<?= (int) $it['id'] ?>][qty]" value="<?= rtrim(rtrim(number_format((float) $it['qty'], 2, '.', ''), '0'), '.') ?>" <?= $sunting ? '' : 'disabled' ?>></td>
            <td><input type="text" name="item[<?= (int) $it['id'] ?>][satuan]" value="<?= e($it['satuan']) ?>" <?= $sunting ? '' : 'disabled' ?>></td>
            <td><input type="text" inputmode="numeric" class="uang"
                   name="item[<?= (int) $it['id'] ?>][harga]"
                   value="<?= number_format((float) $it['harga'], 0, ',', '.') ?>" <?= $sunting ? '' : 'disabled' ?>></td>
            <td style="text-align:center"><input type="checkbox" name="item[<?= (int) $it['id'] ?>][opsional]" value="1" <?= $it['opsional'] ? 'checked' : '' ?> <?= $sunting ? '' : 'disabled' ?>
                   title="Opsional tidak masuk total"></td>
            <td class="actions">
              <?php if ($sunting): ?>
                <button class="btn sm ghost danger" type="submit" form="hapus<?= (int) $it['id'] ?>">×</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?>
          <tr><td colspan="7" class="sub" style="padding:18px">Belum ada baris. Kebutuhan vendor di halaman klien
            belum dicentang saat penawaran ini dibuat — tambahkan manual di bawah.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($sunting): ?>
      <div class="row c2" style="margin-top:16px">
        <div class="field"><label>Diskon (Rp)</label>
          <input type="text" inputmode="numeric" class="uang" name="diskon"
                 value="<?= number_format((float) $qq['diskon'], 0, ',', '.') ?>">
          <p class="hint" style="margin:6px 0 0">Dipotong dari subtotal. Item opsional tidak ikut dihitung.</p></div>
        <div class="field"><label>Berlaku sampai</label>
          <input type="date" name="valid_until" value="<?= e((string) $qq['valid_until']) ?>"></div>
      </div>
      <div class="field"><label>Catatan di penawaran</label>
        <textarea name="catatan" rows="3" placeholder="Syarat, cakupan, hal yang tidak termasuk…"><?= e((string) $qq['catatan']) ?></textarea></div>

      <div style="display:flex;gap:9px;flex-wrap:wrap;margin-top:6px">
        <button class="btn solid" type="submit">Simpan penawaran</button>
        <button class="btn ghost" type="submit" form="tambah">+ Tambah baris</button>
      </div>
    <?php endif; ?>
  </form>

  <?php if ($sunting): ?>
    <form method="post" id="tambah"><?= csrfField() ?>
      <input type="hidden" name="act" value="item_tambah"><input type="hidden" name="id" value="<?= $qid ?>"></form>
    <?php foreach ($items as $it): ?>
      <form method="post" id="hapus<?= (int) $it['id'] ?>"><?= csrfField() ?>
        <input type="hidden" name="act" value="item_hapus"><input type="hidden" name="id" value="<?= $qid ?>">
        <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>"></form>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php if ($bolehSunting && !$terkunci): ?>
<div class="grid g2">
  <div class="card">
    <h2>Kirim ke klien</h2>
    <p class="sub">Teks WhatsApp dirangkai dari isi penawaran ini. Klien Indonesia membaca
      di layar HP sambil chat — kalau harus mengunduh PDF dulu, banyak yang tidak jadi baca.</p>
    <details style="margin-bottom:13px">
      <summary class="btn sm ghost" style="list-style:none">Lihat teksnya</summary>
      <textarea rows="9" readonly style="margin-top:9px;font-family:var(--mono);font-size:12px"><?= e(quoteTeksWA($qid)) ?></textarea>
    </details>
    <form method="post">
      <?= csrfField() ?><input type="hidden" name="act" value="kirim"><input type="hidden" name="id" value="<?= $qid ?>">
      <label style="display:flex;gap:8px;align-items:center;margin-bottom:11px;font-size:13.5px">
        <input type="checkbox" name="via_wa" value="1"> Kirim otomatis lewat WhatsApp
      </label>
      <button class="btn solid" type="submit">Tandai terkirim</button>
    </form>
    <?php if ($qq['sent_at']): ?>
      <p class="hint" style="margin-top:11px">Terkirim <?= tanggalID(substr($qq['sent_at'], 0, 10)) ?>.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Klien menawar</h2>
    <p class="sub">Catat angka yang <b>diminta klien</b>, bukan yang kita ajukan. Selisihnya
      yang menentukan revisi berikutnya — dan tanpa dicatat, angka itu hilang di chat.</p>
    <form method="post">
      <?= csrfField() ?><input type="hidden" name="act" value="nego"><input type="hidden" name="id" value="<?= $qid ?>">
      <div class="field"><label>Angka yang diminta (Rp)</label>
        <input type="text" inputmode="numeric" class="uang" name="nego_nilai"
               value="<?= $qq['nego_nilai'] ? number_format((float) $qq['nego_nilai'], 0, ',', '.') : '' ?>"
               placeholder="35.000.000"></div>
      <div class="field"><label>Alasannya</label>
        <input type="text" name="nego_catatan" value="<?= e((string) $qq['nego_catatan']) ?>"
               placeholder="Budget dari orang tua, dekorasi dirasa terlalu ramai…"></div>
      <button class="btn" type="submit">Catat tawaran</button>
    </form>
  </div>
</div>

<div class="card">
  <h2>Putuskan</h2>
  <p class="sub">Revisi menyalin seluruh baris ke penawaran baru — yang lama tetap utuh
    karena itu dokumen yang sudah dipegang klien.</p>
  <div style="display:flex;gap:9px;flex-wrap:wrap;align-items:flex-start">
    <form method="post"><?= csrfField() ?>
      <input type="hidden" name="act" value="revisi"><input type="hidden" name="id" value="<?= $qid ?>">
      <button class="btn" type="submit">Buat revisi →</button></form>

    <form method="post" onsubmit="return confirm('Tandai deal? Klien pindah ke admin office.')"><?= csrfField() ?>
      <input type="hidden" name="act" value="cocok"><input type="hidden" name="id" value="<?= $qid ?>">
      <button class="btn solid" type="submit">Klien setuju — deal</button></form>

    <details>
      <summary class="btn sm ghost danger" style="list-style:none">Tidak cocok</summary>
      <form method="post" style="margin-top:9px;display:flex;gap:7px;align-items:flex-end"><?= csrfField() ?>
        <input type="hidden" name="act" value="tidak_cocok"><input type="hidden" name="id" value="<?= $qid ?>">
        <div class="field" style="margin:0;min-width:260px"><label>Alasan (wajib)</label>
          <input type="text" name="alasan" required placeholder="Ambil WO lain, budget tidak ketemu…"></div>
        <button class="btn sm danger ghost" type="submit">Catat</button>
      </form>
    </details>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h2>Riwayat penawaran klien ini</h2>
  <p class="sub">Termasuk yang sudah tidak berlaku. Admin office membaca ini untuk tahu
    apa yang sudah dijanjikan dan apa yang dikorbankan saat menawar.</p>
  <table class="tbl">
    <thead><tr><th>Nomor</th><th>Status</th><th class="num">Kita ajukan</th><th class="num">Klien minta</th><th>Tanggal</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lain as $r): ?>
      <tr style="<?= (int) $r['id'] === $qid ? 'background:var(--ivory-07)' : '' ?>">
        <td data-l="Nomor"><b><?= e($r['nomor']) ?></b><?= $r['revisi'] > 1 ? ' <span class="muted">rev ' . (int) $r['revisi'] . '</span>' : '' ?></td>
        <td data-l="Status"><span class="pill <?= $r['status'] === 'cocok' ? 'ok' : ($r['status'] === 'tidak_cocok' ? 'bad' : 'draft') ?>"><?= e(str_replace('_', ' ', $r['status'])) ?></span></td>
        <td class="num" data-l="Kita ajukan"><?= rupiah((float) $r['total']) ?></td>
        <td class="num" data-l="Klien minta"><?= $r['nego_nilai'] ? rupiah((float) $r['nego_nilai']) : '—' ?></td>
        <td class="num" data-l="Tanggal"><?= tanggalID(substr($r['created_at'], 0, 10)) ?></td>
        <td class="actions">
          <a class="btn sm ghost" href="?id=<?= (int) $r['id'] ?>&cetak=1" target="_blank">PDF</a>
          <?php if ((int) $r['id'] !== $qid): ?><a class="btn sm" href="?id=<?= (int) $r['id'] ?>">Buka</a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<style>
/* Awalan Rp menempel di dalam kotak, bukan cuma di label. Saat menyunting
   sepuluh baris harga, label di atas kotak sudah lepas dari pandangan —
   yang terlihat cuma deretan angka telanjang. */
.rp { position: relative; display: block }
/* Kotak angka: rata kanan, lebar digit seragam. Tanpa awalan Rp yang
   ditumpuk di dalam kotak — satuannya sudah disebut sekali di kepala kolom,
   dan overlay di atas input selalu bermasalah begitu fontnya belum termuat
   atau kotaknya menyempit. */
input.uang {
  text-align: right;
  font-family: var(--mono);
  font-variant-numeric: tabular-nums;
  letter-spacing: .3px;
}
input.uang:disabled { opacity: .55 }
</style>

<script>
/* Pemisah ribuan hidup saat mengetik.
   Tanpa ini, "20000000000" dan "2000000000" terlihat nyaris sama di layar —
   dan selisihnya sepuluh kali lipat. Untuk angka pernikahan yang rutin
   menyentuh ratusan juta, itu salah ketik yang mahal dan sulit terlihat.
   Server tetap membersihkan titiknya sendiri, jadi ini murni bantuan baca. */
(function () {
  const fmt = v => {
    const angka = String(v).replace(/\D/g, '').replace(/^0+(?=\d)/, '');
    return angka ? angka.replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '';
  };
  document.querySelectorAll('input.uang').forEach(el => {
    el.addEventListener('input', () => {
      // Hitung angka sebelum kursor supaya posisinya tidak melompat ke ujung
      // setiap kali titik baru disisipkan.
      const sebelum = el.value.slice(0, el.selectionStart).replace(/\D/g, '').length;
      el.value = fmt(el.value);
      let pos = 0, n = 0;
      while (pos < el.value.length && n < sebelum) { if (/\d/.test(el.value[pos])) n++; pos++; }
      el.setSelectionRange(pos, pos);
    });
    el.addEventListener('blur', () => { if (el.value.trim() === '') el.value = '0'; });
  });
})();
</script>

<?php adminFoot();
