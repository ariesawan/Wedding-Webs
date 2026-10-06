<?php
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/paket.php';

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
/** Baris quotes baru (draf, tanpa isi). */
function quoteBaru(array $c, string $jenis, ?int $userId): int
{
    $tipe   = $c['tipe_klien'] ?: 'tematis';
    $plafon = $tipe === 'budgeting' ? (float) ($c['budget_estimate'] ?? 0) : null;
    $revisi = (int) (one("SELECT COALESCE(MAX(revisi),0) r FROM quotes WHERE client_id = ?", [$c['id']])['r'] ?? 0) + 1;

    q("INSERT INTO quotes (client_id, nomor, revisi, tipe, jenis, status, plafon,
                           valid_until, token, created_by)
       VALUES (?,?,?,?,?, 'draf', ?, ?, ?, ?)",
      [$c['id'], quoteNomor((int) $c['id']), $revisi, $tipe, $jenis, $plafon,
       date('Y-m-d', strtotime('+' . (int) setting('quote_valid_days', '14') . ' day')),
       bin2hex(random_bytes(16)), $userId]);
    return insertId();
}

function quoteBuat(int $clientId, string $jenis = 'penawaran', ?int $userId = null): int
{
    $c = one("SELECT * FROM clients WHERE id = ?", [$clientId]);
    if (!$c) throw new RuntimeException('Klien tidak ditemukan.');

    $tipe   = $c['tipe_klien'] ?: 'tematis';
    $plafon = $tipe === 'budgeting' ? (float) ($c['budget_estimate'] ?? 0) : null;
    $qid    = quoteBaru($c, $jenis, $userId);

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

/**
 * Price list / penawaran dari paket: "paket + rincian isi".
 *
 * Harga paket disimpan di quotes.paket_harga; baris isinya disalin dengan
 * harga 0 (artinya "termasuk paket"), tambahan opsional tetap dengan
 * harganya. Isinya SALINAN — mengubah paket besok tidak mengubah dokumen
 * yang sudah dipegang klien.
 */
function quoteDariPaket(int $clientId, int $tplId, string $jenis = 'pricelist', ?int $userId = null): int
{
    $c = one("SELECT * FROM clients WHERE id = ?", [$clientId]);
    if (!$c) throw new RuntimeException('Klien tidak ditemukan.');
    $t = one("SELECT * FROM quote_templates WHERE id = ?", [$tplId]);
    if (!$t) throw new RuntimeException('Paket tidak ditemukan.');

    $qid = quoteBaru($c, $jenis, $userId);
    $harga = (float) ($t['harga'] ?? 0);
    q("UPDATE quotes SET template_id = ?, paket_nama = ?, paket_harga = ?, catatan = ? WHERE id = ?",
      [$tplId, $t['nama'], $harga > 0 ? $harga : null, (string) $t['catatan_bawaan'], $qid]);

    // Satuan yang bergantung jumlah tamu ikut menyesuaikan klien ini — porsi
    // katering paket 400 tamu tidak boleh tercetak 400 untuk klien 150 tamu.
    $tamu = (int) ($c['guest_estimate'] ?? 0);
    foreach (all("SELECT * FROM quote_template_items WHERE template_id = ? ORDER BY sort_order, id", [$tplId]) as $i => $r) {
        $qty = (float) $r['qty'];
        if ($tamu > 0 && in_array(strtolower((string) $r['satuan']), ['porsi', 'pax', 'tamu', 'kursi', 'orang tamu'], true)) {
            $qty = $tamu;
        }
        $hrg = (float) $r['harga'];
        q("INSERT INTO quote_items (quote_id, category_id, kelompok, label, detail, qty, satuan, harga, jumlah, opsional, sort_order)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)",
          [$qid, $r['category_id'], (string) ($r['kelompok'] ?? ''), $r['label'], $r['detail'], $qty, $r['satuan'],
           $hrg, $hrg * $qty, (int) $r['opsional'], ($i + 1) * 10]);
    }
    quoteHitung($qid);
    return $qid;
}

/**
 * Hitung ulang subtotal/total.
 * Subtotal = harga paket + baris berharga yang tidak opsional. Opsional TIDAK
 * masuk total — itu tawaran tambahan, bukan bagian dari angka yang disetujui.
 */
function quoteHitung(int $quoteId): array
{
    $r = one("SELECT COALESCE(SUM(CASE WHEN opsional = 0 THEN jumlah ELSE 0 END),0) wajib,
                     COALESCE(SUM(CASE WHEN opsional = 1 THEN jumlah ELSE 0 END),0) opsi
              FROM quote_items WHERE quote_id = ?", [$quoteId]);
    $qq = one("SELECT diskon, paket_harga FROM quotes WHERE id = ?", [$quoteId]);

    $paket = (float) ($qq['paket_harga'] ?? 0);
    $sub   = $paket + (float) $r['wajib'];
    $dis   = (float) ($qq['diskon'] ?? 0);

    q("UPDATE quotes SET subtotal = ?, total = ? WHERE id = ?", [$sub, max(0, $sub - $dis), $quoteId]);
    return ['paket' => $paket, 'tambahan' => (float) $r['wajib'], 'subtotal' => $sub,
            'opsional' => (float) $r['opsi'], 'total' => max(0, $sub - $dis)];
}

/**
 * Semua bahan dokumen dalam satu bentuk — dipakai teks WA, halaman publik,
 * PDF, dan pratinjau admin, supaya keempatnya tidak pernah berbeda isi.
 */
function quoteData(int $quoteId): ?array
{
    $qq = one("SELECT q.*, c.name, c.partner_name, c.wedding_date, c.wedding_time, c.venue, c.city,
                      c.guest_estimate, c.stage, c.phone
               FROM quotes q JOIN clients c ON c.id = q.client_id WHERE q.id = ?", [$quoteId]);
    if (!$qq) return null;
    $items = all("SELECT * FROM quote_items WHERE quote_id = ? ORDER BY sort_order, id", [$quoteId]);

    $isi = $tambahan = $opsi = [];
    foreach ($items as $it) {
        if ($it['opsional'])                 $opsi[] = $it;
        elseif ((float) $it['jumlah'] > 0)   $tambahan[] = $it;
        else                                 $isi[] = $it;
    }
    $isPaket = trim((string) ($qq['paket_nama'] ?? '')) !== '';
    return [
        'q'        => $qq,
        'paket'    => $isPaket,
        'jenisLbl' => $qq['jenis'] === 'pricelist' ? 'Price list' : 'Penawaran',
        'nama'     => trim($qq['name'] . ($qq['partner_name'] ? ' & ' . $qq['partner_name'] : '')),
        'isi'      => kelompokkan($isi),
        'tambahan' => $tambahan,
        'opsi'     => $opsi,
        'termin'   => (float) $qq['total'] > 0 ? terminKlien((float) $qq['total'], $qq['wedding_date']) : [],
        'rekening' => rekeningBaris(),
        'wa'       => waNomorPic((string) $qq['stage']),
        'urlHal'   => url('penawaran.php?t=' . $qq['token']),
        'urlPdf'   => url('penawaran.php?t=' . $qq['token'] . '&pdf=1'),
        'berkas'   => quoteNamaBerkas($qq),
    ];
}

/** "PriceList-Prasaja-CLP-2610-0012-9.pdf" */
function quoteNamaBerkas(array $qq): string
{
    $jenis = $qq['jenis'] === 'pricelist' ? 'PriceList' : 'Penawaran';
    $paket = trim((string) ($qq['paket_nama'] ?? '')) !== '' ? '-' . preg_replace('/[^A-Za-z0-9]+/', '', ucwords((string) $qq['paket_nama'])) : '';
    return $jenis . mb_substr($paket, 0, 30) . '-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) $qq['nomor']) . '.pdf';
}

/** Persen tanpa nol berlebih: 30, 12,5 */
function persenTeks($p): string
{
    return rtrim(rtrim(number_format((float) $p, 2, ',', '.'), '0'), ',');
}

/**
 * Rangkai penawaran jadi pesan WhatsApp.
 *
 * Isinya ringkas dan bisa dibaca di layar HP; dokumen lengkapnya ikut
 * terlampir sebagai PDF (atau ditautkan bila penyedia WA tidak bisa
 * melampirkan berkas).
 */
function quoteTeksWA(int $quoteId): string
{
    $d = quoteData($quoteId);
    if (!$d) return '';
    $qq = $d['q'];
    $brand = setting('site_name', 'Callalily Party');

    $b = [];
    $b[] = 'Halo ' . $d['nama'] . ',';
    $b[] = 'Berikut ' . strtolower($d['jenisLbl']) . ' dari *' . $brand . '*:';
    $b[] = '';
    $b[] = '*' . strtoupper($d['jenisLbl']) . '* · No. ' . $qq['nomor'] . ($qq['revisi'] > 1 ? ' (revisi ' . $qq['revisi'] . ')' : '');
    if ($qq['wedding_date'])   $b[] = 'Tanggal: ' . tanggalID($qq['wedding_date']);
    if ($qq['venue'] || $qq['city']) $b[] = 'Lokasi: ' . ($qq['venue'] ?: $qq['city']);
    if ($qq['guest_estimate']) $b[] = 'Perkiraan tamu: ' . number_format((int) $qq['guest_estimate'], 0, ',', '.');
    $b[] = '';

    if ($d['paket']) {
        $b[] = '*Paket ' . $qq['paket_nama'] . '*' . ((float) $qq['paket_harga'] > 0 ? ' — ' . rupiah((float) $qq['paket_harga']) : '');
    }
    if ($d['isi']) {
        $b[] = $d['paket'] ? 'Termasuk:' : '*Rincian*';
        foreach ($d['isi'] as $kel => $baris) {
            $b[] = '_' . $kel . '_';
            foreach ($baris as $it) {
                $x = array_filter([$it['detail'], qtyTeks($it)]);
                $b[] = '• ' . $it['label'] . ($x ? ' (' . implode(', ', $x) . ')' : '');
            }
        }
    }
    if ($d['tambahan']) {
        $b[] = '';
        $b[] = $d['paket'] ? '*Tambahan*' : '*Rincian berbiaya*';
        foreach ($d['tambahan'] as $it) $b[] = '• ' . $it['label'] . ($it['detail'] ? ' (' . $it['detail'] . ')' : '') . ' — ' . rupiah((float) $it['jumlah']);
    }

    $b[] = '';
    if ((float) $qq['total'] > 0) {
        $b[] = '*Total: ' . rupiah((float) $qq['total']) . '*';
        if ((float) $qq['diskon'] > 0) $b[] = '_Sudah termasuk potongan ' . rupiah((float) $qq['diskon']) . '._';
    } else {
        $b[] = '_Harga menyesuaikan susunan acara — kami konfirmasi lewat chat ini._';
    }

    if ($d['opsi']) {
        $b[] = '';
        $b[] = '*Bisa ditambahkan*';
        foreach ($d['opsi'] as $it) $b[] = '• ' . $it['label'] . ((float) $it['jumlah'] > 0 ? ' — ' . rupiah((float) $it['jumlah']) : '');
    }

    if ($d['termin']) {
        $b[] = '';
        $b[] = '*Pembayaran*';
        foreach ($d['termin'] as $t) {
            $b[] = '• ' . $t['label'] . ($t['persen'] !== null ? ' (' . persenTeks($t['persen']) . '%)' : '')
                 . ' — ' . rupiah($t['amount']) . ($t['due_date'] ? ', paling lambat ' . tanggalID($t['due_date']) : '');
        }
        if ($d['rekening']) $b[] = 'Transfer ke ' . implode(' ', $d['rekening']);
    }

    if ($qq['valid_until']) { $b[] = ''; $b[] = '_Berlaku sampai ' . tanggalID($qq['valid_until']) . '._'; }
    $b[] = '';
    $b[] = 'Dokumen PDF: ' . $d['urlPdf'];
    $b[] = 'Kalau cocok, balas pesan ini — kami bantu proses DP-nya. Terima kasih.';

    return implode("\n", $b);
}

/**
 * Tandai terkirim, lalu majukan tahap kliennya ke "Price list terkirim".
 *
 * Satu pintu untuk dua cara kirim (WA otomatis dan kirim manual). Maju saja,
 * tidak pernah mundur: price list tambahan untuk klien yang sudah DP tidak
 * boleh menyeretnya kembali.
 */
function quoteTandaiTerkirim(int $quoteId, ?int $userId = null): array
{
    $qq = one("SELECT id, client_id, jenis, status, nomor, total FROM quotes WHERE id = ?", [$quoteId]);
    if (!$qq) throw new RuntimeException('Penawaran tidak ditemukan.');

    q("UPDATE quotes SET status = IF(status = 'draf', 'terkirim', status),
                         sent_at = COALESCE(sent_at, NOW())
       WHERE id = ?", [$quoteId]);

    $kolom = $qq['jenis'] === 'pricelist' ? 'pl_sent_at' : 'penawaran_sent_at';
    q("UPDATE clients SET $kolom = COALESCE($kolom, NOW()) WHERE id = ?", [$qq['client_id']]);

    return clientMajuKe((int) $qq['client_id'], 'pricelist', $userId, 'Otomatis: ' . $qq['nomor'] . ' terkirim.');
}

/**
 * Kirim ke WhatsApp klien lewat room chat, dengan PDF terlampir.
 * Pesannya masuk riwayat room, jadi terlihat sama seperti percakapan lain.
 */
function quoteKirimWA(int $quoteId, ?int $userId = null): array
{
    $d = quoteData($quoteId);
    if (!$d)                  return ['ok' => false, 'error' => 'Penawaran tidak ditemukan.'];
    if (!$d['q']['phone'])    return ['ok' => false, 'error' => 'Klien belum punya nomor WhatsApp.'];

    $chatId = chatRoom($d['q']['phone']);
    if (!$chatId)             return ['ok' => false, 'error' => 'Nomor WhatsApp klien tidak valid.'];

    $r = chatKirim($chatId, quoteTeksWA($quoteId), $userId, [
        'client_id' => (int) $d['q']['client_id'],
        'berkas'    => ['url' => $d['urlPdf'], 'nama' => $d['berkas']],
    ]);

    if ($r['ok']) {
        q("UPDATE quotes SET sent_wa_id = ? WHERE id = ?", [$r['id'] ?? null, $quoteId]);
        quoteTandaiTerkirim($quoteId, $userId);
    }

    return $r + ['chat_id' => $chatId];
}

/**
 * Klien cocok.
 *
 * Alur owner: cocok → DP 30% → admin office. Jadi "cocok" memindahkan klien
 * ke tahap Menunggu DP: nilai deal = total dokumen ini, termin disusun, DP
 * ditagih. Serah terima ke admin office baru terjadi saat DP ditandai lunas.
 *
 * Klien yang sudah DP/deal (misalnya menyetujui tambahan): nilai kontraknya
 * diperbarui dan termin yang belum dibayar disesuaikan.
 */
function quoteCocok(int $quoteId, ?int $userId = null): array
{
    $qq = one("SELECT q.client_id, q.total, q.jenis, q.nomor, q.revisi_dari, c.stage, c.deal_value
               FROM quotes q JOIN clients c ON c.id = q.client_id WHERE q.id = ?", [$quoteId]);
    if (!$qq) throw new RuntimeException('Penawaran tidak ditemukan.');
    $cid = (int) $qq['client_id'];
    $jenis = $qq['jenis'] === 'pricelist' ? 'Price list' : 'Penawaran';
    $total = (float) $qq['total'];

    if ($total <= 0) {
        throw new RuntimeException('Totalnya masih Rp 0 — isi harga paket dulu, karena DP 30% dihitung dari angka ini.');
    }
    if ($qq['stage'] === 'batal') {
        throw new RuntimeException('Klien ini tercatat tidak jadi. Aktifkan lagi dari halaman klien sebelum menandai cocok.');
    }

    // Dokumen ini MENGGANTI kontrak atau MENAMBAHINYA?
    //   - sebelum DP masuk (termasuk Menunggu DP): selalu mengganti — hanya
    //     ada satu angka yang sedang ditagih DP-nya;
    //   - sesudah DP: mengganti hanya kalau ini revisi dari dokumen yang
    //     sudah disetujui; selain itu tambahan (mis. paket tambahan) yang
    //     nilainya DITAMBAHKAN ke kontrak, bukan menimpanya.
    $praDp = !stageSudahDeal($qq['stage']) && $qq['stage'] !== 'dp';
    $dariCocok = $qq['revisi_dari']
        ? (bool) one("SELECT id FROM quotes WHERE id = ? AND status = 'cocok'", [(int) $qq['revisi_dari']])
        : false;
    $ganti = !stageSudahDeal($qq['stage']) || $dariCocok;
    $nilai = $ganti ? $total : (float) ($qq['deal_value'] ?? 0) + $total;

    $lunas = (float) (one("SELECT COALESCE(SUM(amount),0) v FROM payments WHERE client_id = ? AND paid_at IS NOT NULL",
                          [$cid])['v'] ?? 0);
    if ($nilai < $lunas) {
        throw new RuntimeException('Nilai kontrak baru (' . rupiah($nilai) . ') lebih kecil daripada yang sudah dibayar ('
            . rupiah($lunas) . '). Periksa dulu termin di tab Pembayaran.');
    }

    q("UPDATE quotes SET status = 'cocok', decided_at = NOW(), sent_at = COALESCE(sent_at, NOW())
       WHERE id = ?", [$quoteId]);
    // Dokumen lain yang masih terbuka tidak lagi berlaku; yang dulu disetujui
    // ikut gugur kalau dokumen ini menggantikannya.
    q("UPDATE quotes SET status = 'revisi'
       WHERE client_id = ? AND id <> ? AND status IN ('draf','terkirim'" . ($ganti ? ",'cocok'" : '') . ")", [$cid, $quoteId]);
    q("UPDATE clients SET deal_value = ? WHERE id = ?", [$nilai, $cid]);

    if ($praDp) {
        $r = clientMajuKe($cid, 'dp', $userId, $jenis . ' ' . $qq['nomor'] . ' cocok.');
        if (!$r['changed']) $r = clientSetStage($cid, 'dp', $userId, $jenis . ' ' . $qq['nomor'] . ' cocok.');
        return ['tahap' => 'dp', 'info' => $r['info']];
    }

    // Sudah di Menunggu DP / sudah deal: angka kontrak berubah.
    $n = terminSusun($cid) ?: terminSesuaikan($cid);
    if ($qq['stage'] === 'dp' && ($dp = terminDp($cid)) && !$dp['paid_at']) {
        // Tagihan DP di tindakan berikutnya ikut angka baru.
        q("UPDATE clients SET next_action = ?, next_action_at = ? WHERE id = ?",
          ['Tagih ' . $dp['label'] . ' · ' . rupiah((float) $dp['amount']),
           $dp['due_date'] ?: date('Y-m-d', strtotime('+3 day')), $cid]);
    }
    $ket = $ganti ? 'Nilai kontrak ' . rupiah($nilai) : 'Tambahan ' . rupiah($total) . ' · nilai kontrak jadi ' . rupiah($nilai);
    clientLog($cid, 'catatan', $jenis . ' ' . $qq['nomor'] . ' disetujui', $ket . ($n ? " · $n termin disesuaikan" : ''), $userId);
    $info = ["$ket." . ($n ? " $n termin yang belum dibayar disesuaikan." : '')];
    return ['tahap' => $qq['stage'], 'info' => $info];
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
