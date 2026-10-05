<?php
require_once __DIR__ . '/chat.php';

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
    $urut   = (int) (one("SELECT COUNT(*) c FROM quotes")['c'] ?? 0) + 1;
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
        q("UPDATE quotes SET status = 'terkirim', sent_at = NOW(), sent_wa_id = ? WHERE id = ?",
          [$r['id'] ?? null, $quoteId]);

        // Tahap pipeline ikut maju — supaya tidak ada penawaran terkirim
        // yang klien-nya masih tercatat di tahap sebelumnya.
        $target = $qq['jenis'] === 'pricelist' ? 'pricelist' : 'penawaran';
        $kolom  = $qq['jenis'] === 'pricelist' ? 'pl_sent_at' : 'penawaran_sent_at';
        q("UPDATE clients SET $kolom = NOW() WHERE id = ?", [$qq['cid']]);

        if (function_exists('clientSetStage')) {
            try { clientSetStage((int) $qq['cid'], $target, $userId, 'Otomatis: ' . $qq['nomor'] . ' terkirim.'); }
            catch (Throwable $e) { /* tahap gagal maju bukan alasan membatalkan kiriman */ }
        }
    }

    return $r + ['chat_id' => $chatId];
}

/** Klien setuju. Tahap naik ke deal, termin ikut disusun oleh clientSetStage(). */
function quoteCocok(int $quoteId, ?int $userId = null): void
{
    $qq = one("SELECT client_id, total FROM quotes WHERE id = ?", [$quoteId]);
    if (!$qq) return;

    q("UPDATE quotes SET status = 'cocok', decided_at = NOW() WHERE id = ?", [$quoteId]);
    q("UPDATE clients SET deal_value = ?, contract_signed_at = NOW(),
                          pic_role = 'admin_office', handover_at = NOW()
       WHERE id = ?", [$qq['total'], $qq['client_id']]);

    if (function_exists('clientSetStage')) {
        clientSetStage((int) $qq['client_id'], 'deal', $userId, 'Penawaran disetujui klien.');
    }
}

/** Klien menolak — alasannya wajib, itu isi "analyze penyebab". */
function quoteTidakCocok(int $quoteId, string $alasan, ?int $userId = null): void
{
    $qq = one("SELECT client_id, jenis FROM quotes WHERE id = ?", [$quoteId]);
    if (!$qq) return;

    q("UPDATE quotes SET status = 'tidak_cocok', decided_at = NOW(), alasan = ? WHERE id = ?",
      [mb_substr($alasan, 0, 400), $quoteId]);
    q("UPDATE clients SET stage_batal = ? WHERE id = ?",
      [$qq['jenis'] === 'pricelist' ? 'pricelist' : 'penawaran', $qq['client_id']]);

    if (function_exists('clientLog')) {
        clientLog((int) $qq['client_id'], 'catatan', 'Penawaran ditolak', $alasan, $userId);
    }
}

/** Termin dihitung dari template, di-snapshot ke tabel payments. */
function terminDariTemplate(float $total, ?string $weddingDate): array
{
    $out = [];
    foreach (all("SELECT * FROM payment_templates WHERE is_active = 1 ORDER BY urutan") as $t) {
        $due = $t['offset_hari'] === null
            ? date('Y-m-d', strtotime('+3 day'))
            : ($weddingDate ? date('Y-m-d', strtotime($weddingDate . ' -' . (int) $t['offset_hari'] . ' day')) : null);

        $out[] = [
            'kode'       => $t['kode'],
            'label'      => $t['label'],
            'persen'     => (float) $t['persen'],
            'amount'     => round($total * (float) $t['persen'] / 100),
            'due_date'   => $due,
            'wajib'      => (int) $t['wajib'],
            'sort_order' => (int) $t['urutan'] * 10,
        ];
    }
    return $out;
}
