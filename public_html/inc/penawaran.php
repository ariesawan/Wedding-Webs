<?php
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/pipeline.php';

/**
 * ============================================================
 * PENAWARAN & PRICE LIST
 * ============================================================
 *
 * Menjawab pertanyaan "PL itu satu dokumen statis atau ada versinya":
 * ternyata bukan dua-duanya. PL adalah TURUNAN dari tipe klien, jadi
 * dokumennya disusun sistem, bukan diambil dari berkas tetap.
 *
 *   tematis   — klien datang dengan bayangan acara. Item disusun dari
 *               kebutuhan yang dia sebutkan, harga mengikuti kebutuhan itu.
 *               Total adalah HASIL, bukan batas.
 *
 *   budgeting — klien datang dengan angka. Plafon dikunci lebih dulu,
 *               item diisi menurut prioritas sampai plafon habis, sisanya
 *               ditandai opsional. Inilah yang dimaksud owner dengan
 *               "menurut stop vendor": begitu plafon tersentuh, berhenti
 *               menambah vendor — jangan diam-diam melewati anggaran.
 *
 * Karena keduanya lahir dari data klien yang sama, satu klien bisa
 * menerima beberapa revisi tanpa menimpa yang lama. Yang lama tetap
 * tersimpan lengkap dengan alasan kenapa ditolak — itu bahan "analyze
 * penyebab" di catatan owner.
 */

/** Prioritas pengisian saat mode budgeting: yang tanpa ini acara batal duluan. */
const QUOTE_PRIORITAS = [
    'venue', 'catering', 'dekorasi', 'make-up', 'busana', 'foto', 'video',
    'sound-system', 'mc', 'music', 'lighting', 'undangan', 'tenda',
    'kursi', 'meja', 'genset', 'multimedia', 'wedding-cake', 'souvenir',
];

function quoteNomor(int $clientId): string
{
    $prefix = setting('quote_prefix', 'CLP');
    // MAX(id), bukan COUNT(*): begitu satu penawaran dihapus, COUNT mundur
    // satu dan nomor berikutnya kembar dengan yang sudah dipegang klien.
    $urut   = (int) (one("SELECT COALESCE(MAX(id),0) c FROM quotes")['c'] ?? 0) + 1;
    return sprintf('%s/%s/%04d/%d', $prefix, date('ym'), $urut, $clientId);
}

/**
 * Susun draf penawaran dari kebutuhan vendor yang sudah dicatat di
 * tahap spesifikasi.
 *
 * Harga per item diambil berurutan: harga yang dikunci di client_vendor_needs,
 * lalu harga vendor yang sudah dipilih, lalu 0 (biar terlihat kosong dan
 * diisi manual — lebih baik daripada menebak angka yang salah).
 */
function quoteBuat(int $clientId, string $jenis = 'penawaran', ?int $userId = null): int
{
    $c = one("SELECT * FROM clients WHERE id = ?", [$clientId]);
    if (!$c) throw new RuntimeException('Klien tidak ditemukan.');

    $tipe   = $c['tipe_klien'] ?: 'tematis';
    $plafon = $tipe === 'budgeting' ? (float) ($c['budget_estimate'] ?? 0) : null;

    $revisi = (int) (one("SELECT COALESCE(MAX(revisi),0) r FROM quotes WHERE client_id = ?", [$clientId])['r'] ?? 0) + 1;

    q("INSERT INTO quotes (client_id, nomor, revisi, tipe, jenis, status, plafon,
                           valid_until, token, created_by)
       VALUES (?,?,?,?,?, 'draf', ?, ?, ?, ?)",
      [$clientId, quoteNomor($clientId), $revisi, $tipe, $jenis, $plafon,
       date('Y-m-d', strtotime('+' . (int) setting('quote_valid_days', '14') . ' day')),
       bin2hex(random_bytes(16)), $userId]);

    $qid = insertId();

    // Kebutuhan yang sudah dipilih klien, diurutkan menurut prioritas acara.
    $urutan = "FIELD(vc.slug, '" . implode("','", QUOTE_PRIORITAS) . "')";
    $needs = all("SELECT n.*, vc.nama kat_nama, vc.slug kat_slug,
                         v.name vendor_nama, v.price_note
                  FROM client_vendor_needs n
                  JOIN vendor_categories vc ON vc.id = n.category_id
                  LEFT JOIN vendors v ON v.id = n.vendor_id
                  WHERE n.client_id = ?
                  ORDER BY IF($urutan = 0, 999, $urutan), n.sort_order", [$clientId]);

    $terpakai = 0.0;
    $i = 0;

    foreach ($needs as $n) {
        $harga = (float) ($n['budget_alokasi'] ?? 0);

        // Mode budgeting: begitu plafon terlampaui, item berikutnya tetap
        // dicantumkan tapi ditandai opsional. Klien perlu melihat apa yang
        // dikorbankan oleh angkanya — bukan cuma menerima daftar yang sudah dipotong.
        $opsional = 0;
        if ($tipe === 'budgeting' && $plafon > 0) {
            if ($terpakai + $harga > $plafon) {
                $opsional = 1;
            } else {
                $terpakai += $harga;
            }
        }

        q("INSERT INTO quote_items (quote_id, category_id, label, detail, qty, satuan,
                                    harga, jumlah, opsional, sort_order)
           VALUES (?,?,?,?,1,'paket',?,?,?,?)",
          [$qid, $n['category_id'], $n['kat_nama'],
           trim(($n['vendor_nama'] ? $n['vendor_nama'] . ' · ' : '') . $n['note']),
           $harga, $harga, $opsional, ($i++) * 10]);
    }

    quoteHitung($qid);
    return $qid;
}

/** Hitung ulang subtotal/total. Item opsional TIDAK masuk total. */
function quoteHitung(int $quoteId): array
{
    $r = one("SELECT COALESCE(SUM(CASE WHEN opsional = 0 THEN jumlah ELSE 0 END),0) wajib,
                     COALESCE(SUM(CASE WHEN opsional = 1 THEN jumlah ELSE 0 END),0) opsi
              FROM quote_items WHERE quote_id = ?", [$quoteId]);

    $sub = (float) $r['wajib'];
    $dis = (float) (one("SELECT diskon FROM quotes WHERE id = ?", [$quoteId])['diskon'] ?? 0);

    q("UPDATE quotes SET subtotal = ?, total = ? WHERE id = ?", [$sub, max(0, $sub - $dis), $quoteId]);
    return ['subtotal' => $sub, 'opsional' => (float) $r['opsi'], 'total' => max(0, $sub - $dis)];
}

/**
 * Rangkai penawaran jadi pesan WhatsApp.
 *
 * Sengaja teks biasa, bukan tautan PDF. Klien Indonesia membaca penawaran
 * di layar HP sambil chat — kalau harus mengunduh dulu, tingkat baca turun
 * jauh. Tautan rincian tetap disertakan untuk yang mau lihat lengkap.
 */
function quoteTeksWA(int $quoteId): string
{
    $qq = one("SELECT q.*, c.name, c.partner_name, c.wedding_date, c.venue, c.guest_estimate
               FROM quotes q JOIN clients c ON c.id = q.client_id WHERE q.id = ?", [$quoteId]);
    if (!$qq) return '';

    $items = all("SELECT * FROM quote_items WHERE quote_id = ? ORDER BY sort_order", [$quoteId]);
    $nama  = trim($qq['name'] . ($qq['partner_name'] ? ' & ' . $qq['partner_name'] : ''));

    $b = [];
    $b[] = '*' . ($qq['jenis'] === 'pricelist' ? 'PRICE LIST' : 'PENAWARAN') . ' — CALLALILY PARTY*';
    $b[] = 'No. ' . $qq['nomor'] . ($qq['revisi'] > 1 ? ' (revisi ' . $qq['revisi'] . ')' : '');
    $b[] = '';
    $b[] = 'Untuk: *' . $nama . '*';
    if ($qq['wedding_date'])   $b[] = 'Tanggal: ' . tanggalID($qq['wedding_date']);
    if ($qq['venue'])          $b[] = 'Lokasi: ' . $qq['venue'];
    if ($qq['guest_estimate']) $b[] = 'Estimasi tamu: ' . number_format((int) $qq['guest_estimate'], 0, ',', '.');
    $b[] = '';

    if ($qq['tipe'] === 'budgeting' && $qq['plafon'] > 0) {
        $b[] = '_Disusun menyesuaikan anggaran ' . rupiah((float) $qq['plafon']) . '._';
        $b[] = '';
    }

    $b[] = '*Rincian*';
    $wajib = array_filter($items, fn($i) => !$i['opsional']);
    $opsi  = array_filter($items, fn($i) => $i['opsional']);

    foreach ($wajib as $it) {
        $b[] = '• ' . $it['label'] . ($it['detail'] ? ' (' . $it['detail'] . ')' : '')
             . ' — ' . rupiah((float) $it['jumlah']);
    }

    $b[] = '';
    $b[] = '*Total: ' . rupiah((float) $qq['total']) . '*';
    if ($qq['diskon'] > 0) $b[] = '_Sudah termasuk potongan ' . rupiah((float) $qq['diskon']) . '._';

    if ($opsi) {
        $b[] = '';
        $b[] = '*Bisa ditambahkan (di luar anggaran)*';
        foreach ($opsi as $it) $b[] = '• ' . $it['label'] . ' — ' . rupiah((float) $it['jumlah']);
    }

    $b[] = '';
    $b[] = '*Termin pembayaran*';
    foreach (all("SELECT * FROM payment_templates WHERE is_active = 1 ORDER BY urutan") as $t) {
        $nominal = round((float) $qq['total'] * (float) $t['persen'] / 100);
        $kapan = $t['offset_hari'] === null ? 'saat tanda tangan kontrak'
                                            : 'H-' . (int) $t['offset_hari'];
        $b[] = '• ' . $t['label'] . ' (' . rtrim(rtrim(number_format((float) $t['persen'], 2, ',', '.'), '0'), ',') . '%) — '
             . rupiah($nominal) . ', ' . $kapan;
    }

    if ($qq['catatan']) { $b[] = ''; $b[] = $qq['catatan']; }
    if ($qq['valid_until']) { $b[] = ''; $b[] = '_Berlaku sampai ' . tanggalID($qq['valid_until']) . '._'; }

    $b[] = '';
    $b[] = 'Rincian lengkap: ' . url() . '/penawaran.php?t=' . $qq['token'];

    return implode("\n", $b);
}

/**
 * Tandai penawaran terkirim, lalu majukan tahap kliennya.
 *
 * Satu pintu untuk dua cara kirim. Sebelumnya tahap hanya maju kalau
 * dikirim lewat WhatsApp otomatis; "Tandai terkirim" untuk kiriman manual —
 * cara yang paling sering dipakai — mengubah status penawaran tapi
 * membiarkan kliennya tertinggal di "Prospek baru", lengkap dengan tenggat
 * "Kirim price list" yang terus menyala merah padahal sudah dikirim.
 */
function quoteTandaiTerkirim(int $quoteId, ?int $userId = null): array
{
    $qq = one("SELECT id, client_id, jenis, status, nomor, total FROM quotes WHERE id = ?", [$quoteId]);
    if (!$qq) throw new RuntimeException('Penawaran tidak ditemukan.');

    q("UPDATE quotes SET status = IF(status = 'draf', 'terkirim', status),
                         sent_at = COALESCE(sent_at, NOW())
       WHERE id = ?", [$quoteId]);

    $pl     = $qq['jenis'] === 'pricelist';
    $kolom  = $pl ? 'pl_sent_at' : 'penawaran_sent_at';
    q("UPDATE clients SET $kolom = COALESCE($kolom, NOW()) WHERE id = ?", [$qq['client_id']]);

    // Maju saja, tidak pernah mundur: price list tambahan untuk klien yang
    // sudah deal tidak boleh menyeretnya kembali ke tahap Price list.
    $r = clientMajuKe((int) $qq['client_id'], $pl ? 'pricelist' : 'penawaran', $userId,
                      'Otomatis: ' . $qq['nomor'] . ' terkirim.');
    return $r;
}

/**
 * Kirim penawaran ke WhatsApp klien lewat room chat.
 * Pesannya masuk riwayat room, jadi terlihat sama seperti percakapan lain.
 */
function quoteKirimWA(int $quoteId, ?int $userId = null): array
{
    $qq = one("SELECT q.*, c.phone, c.id cid FROM quotes q
               JOIN clients c ON c.id = q.client_id WHERE q.id = ?", [$quoteId]);
    if (!$qq)             return ['ok' => false, 'error' => 'Penawaran tidak ditemukan.'];
    if (!$qq['phone'])    return ['ok' => false, 'error' => 'Klien belum punya nomor WhatsApp.'];

    $chatId = chatRoom($qq['phone']);
    if (!$chatId)         return ['ok' => false, 'error' => 'Nomor WhatsApp klien tidak valid.'];

    $r = chatKirim($chatId, quoteTeksWA($quoteId), $userId, ['client_id' => (int) $qq['cid']]);

    if ($r['ok']) {
        q("UPDATE quotes SET sent_wa_id = ? WHERE id = ?", [$r['id'] ?? null, $quoteId]);
        quoteTandaiTerkirim($quoteId, $userId);
    }

    return $r + ['chat_id' => $chatId];
}

/**
 * Klien setuju.
 *
 * Arti "setuju" bergantung jenis dokumennya:
 *   price list → klien cocok dengan kisaran harga; lanjut menggali
 *                spesifikasi (konsultasi, kebutuhan vendor) — BELUM deal.
 *   penawaran  → deal. Nilai deal = total penawaran ini, termin disusun,
 *                klien pindah ke admin office.
 *
 * Dulu keduanya langsung deal. Price list yang disetujui ikut menyusun
 * termin dan menyerahkan klien ke admin office, sebelum ada spesifikasi
 * maupun penawaran sungguhan.
 */
function quoteCocok(int $quoteId, ?int $userId = null): array
{
    $qq = one("SELECT q.client_id, q.total, q.jenis, q.nomor, c.stage
               FROM quotes q JOIN clients c ON c.id = q.client_id WHERE q.id = ?", [$quoteId]);
    if (!$qq) throw new RuntimeException('Penawaran tidak ditemukan.');
    $cid = (int) $qq['client_id'];

    if ($qq['jenis'] === 'penawaran' && (float) $qq['total'] <= 0) {
        throw new RuntimeException('Total penawaran ini masih Rp 0. Isi harganya dulu sebelum ditandai deal.');
    }
    if ($qq['stage'] === 'batal') {
        throw new RuntimeException('Klien ini tercatat tidak jadi. Aktifkan lagi dari halaman klien sebelum menandai setuju.');
    }

    q("UPDATE quotes SET status = 'cocok', decided_at = NOW(), sent_at = COALESCE(sent_at, NOW())
       WHERE id = ?", [$quoteId]);

    if ($qq['jenis'] === 'pricelist') {
        q("UPDATE clients SET pl_sent_at = COALESCE(pl_sent_at, NOW()) WHERE id = ?", [$cid]);
        $r = clientMajuKe($cid, 'spesifikasi', $userId, 'Price list ' . $qq['nomor'] . ' cocok.');
        if (!$r['changed']) clientLog($cid, 'catatan', 'Price list ' . $qq['nomor'] . ' cocok', '', $userId);
        return ['tahap' => 'spesifikasi', 'info' => $r['info']];
    }

    // Penawaran lain yang masih terbuka tidak lagi berlaku — yang dipegang
    // klien sekarang adalah yang disetujui ini.
    q("UPDATE quotes SET status = 'revisi'
       WHERE client_id = ? AND id <> ? AND jenis = 'penawaran' AND status IN ('draf','terkirim')",
      [$cid, $quoteId]);

    q("UPDATE clients SET deal_value = ?, penawaran_sent_at = COALESCE(penawaran_sent_at, NOW())
       WHERE id = ?", [$qq['total'], $cid]);
    $r = stageSudahDeal($qq['stage'])
       ? ['changed' => false, 'info' => []]
       : clientSetStage($cid, 'deal', $userId, 'Penawaran ' . $qq['nomor'] . ' disetujui klien.');

    // Klien yang sudah deal sebelumnya (misalnya tambahan paket): nilai
    // kontraknya berubah, termin yang belum ada disusun.
    if (!$r['changed']) {
        $n = terminSusun($cid);
        if ($n) $r['info'][] = "Termin pembayaran disusun dari template ($n termin).";
        clientLog($cid, 'catatan', 'Penawaran ' . $qq['nomor'] . ' disetujui', rupiah((float) $qq['total']), $userId);
    }
    return ['tahap' => 'deal', 'info' => $r['info']];
}

/**
 * Klien menolak dan mundur. Alasannya wajib — itu isi "analyze penyebab".
 *
 * Kalau kliennya belum deal, tahapnya ikut pindah ke "Tidak jadi". Dulu
 * hanya status penawarannya yang berubah; kliennya tetap tercatat aktif di
 * papan dengan tenggat yang terus lewat, sehingga daftar "perlu ditindak"
 * penuh oleh klien yang sebenarnya sudah pergi.
 *
 * Klien yang masih mau menawar BUKAN di sini — pakai "Klien menawar" lalu
 * "Buat revisi".
 */
function quoteTidakCocok(int $quoteId, string $alasan, ?int $userId = null): array
{
    $qq = one("SELECT q.client_id, q.jenis, q.nomor, c.stage
               FROM quotes q JOIN clients c ON c.id = q.client_id WHERE q.id = ?", [$quoteId]);
    if (!$qq) throw new RuntimeException('Penawaran tidak ditemukan.');
    $cid = (int) $qq['client_id'];

    q("UPDATE quotes SET status = 'tidak_cocok', decided_at = NOW(), alasan = ? WHERE id = ?",
      [mb_substr($alasan, 0, 400), $quoteId]);

    if (!stageSudahDeal($qq['stage']) && $qq['stage'] !== 'batal') {
        $r = clientSetStage($cid, 'batal', $userId,
                            ($qq['jenis'] === 'pricelist' ? 'Price list' : 'Penawaran') . ' ' . $qq['nomor']
                            . ' tidak cocok: ' . $alasan);
        // Titik gugurnya mengikuti dokumen yang ditolak, bukan tahap papan —
        // klien bisa saja masih tercatat "Prospek baru" saat menolak price list.
        q("UPDATE clients SET stage_batal = ? WHERE id = ?",
          [$qq['jenis'] === 'pricelist' ? 'pricelist' : 'penawaran', $cid]);
        return ['batal' => true, 'info' => $r['info']];
    }

    clientLog($cid, 'catatan', 'Penawaran ' . $qq['nomor'] . ' ditolak', $alasan, $userId);
    return ['batal' => false, 'info' => []];
}

/** Termin dihitung dari template. Dipertahankan untuk pemanggil lama. */
function terminDariTemplate(float $total, ?string $weddingDate): array
{
    return terminKlien($total, $weddingDate);
}
