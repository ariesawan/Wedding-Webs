<?php
/**
 * ============================================================
 * PDF KWITANSI
 * ============================================================
 *
 * Satu kwitansi = satu transfer (nomor KW/tahun/urut), walau uangnya
 * dialokasikan ke beberapa termin. Isinya hanya yang layak dibaca klien:
 * tidak ada catatan internal, nama staf, harga vendor, atau isi penawaran.
 * Penandatangan = setting kwitansi_penandatangan, cadangan nama usaha.
 */
require_once __DIR__ . '/pdf-penawaran.php';
require_once __DIR__ . '/bayar.php';

/** Bangun PDF kwitansi dari salah satu id penerimaannya. */
function kwitansiPdf(int $receiptId): string
{
    $r = one("SELECT * FROM payment_receipts WHERE id = ?", [$receiptId]);
    if (!$r || $r['kwitansi_no'] === '' || !in_array($r['status'], ['sah', 'batal'], true)) {
        throw new RuntimeException('Kwitansi tidak ditemukan.');
    }
    $c = one("SELECT id, name, partner_name, wedding_date FROM clients WHERE id = ?", [$r['client_id']]);
    $grup = all("SELECT r.jumlah, r.status, p.label, p.amount, p.terbayar, p.paid_at
                 FROM payment_receipts r JOIN payments p ON p.id = r.payment_id
                 WHERE r.client_id = ? AND r.kwitansi_no = ? AND r.status = ?
                 ORDER BY p.sort_order, p.id", [$r['client_id'], $r['kwitansi_no'], $r['status']]);
    $jumlah = array_sum(array_map(fn($g) => (float) $g['jumlah'], $grup));
    $batal  = $r['status'] === 'batal';
    $rk     = bayarRingkas((int) $r['client_id']);

    $brand   = setting('site_name', 'Callalily Party');
    $tagline = setting('site_tagline', 'Wedding Organizer · Yogyakarta');
    $alamat  = trim(implode(', ', array_filter([setting('address_street', ''), setting('address_city', ''), setting('address_region', '')])));
    $kota    = setting('kwitansi_kota', '') ?: (setting('address_city', '') ?: 'Yogyakarta');
    $ttd     = setting('kwitansi_penandatangan', '') ?: $brand;
    $wa      = waTampil(waNomorPic('deal'));
    // Nama dari data klien yang dipegang admin — BUKAN nama lengkap yang bisa
    // diubah pengantin lewat dashboard: kwitansi yang sudah terbit tidak
    // boleh berubah isinya setelah dikirim.
    $namaKlien = bayarNamaKlien($c);

    $pdf = new PdfPenawaran('P', 'mm', 'A4');
    $pdf->SetMargins(18, 18, 18);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AliasNbPages();
    $pdf->SetTitle('Kwitansi ' . $r['kwitansi_no'], true);
    $pdf->SetAuthor($brand, true);
    $pdf->kaki = 'Dibuat oleh sistem ' . $brand . ' · ' . $r['kwitansi_no'];
    $pdf->AddPage();
    $lebar = $pdf->GetPageWidth() - 36;

    // ---------- Kop ----------
    $pdf->SetFont('Times', 'BI', 22);
    $pdf->warna(PdfPenawaran::TINTA);
    $pdf->Cell($lebar * 0.6, 9, pdfTeks($brand), 0, 0, 'L');
    $pdf->SetFont('Helvetica', 'B', 15);
    $pdf->warna(PdfPenawaran::AKSEN);
    $pdf->Cell($lebar * 0.4, 9, 'KWITANSI', 0, 1, 'R');
    $yKop = $pdf->GetY();
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->warna(PdfPenawaran::ABU);
    foreach (array_filter([$tagline, $alamat, 'WhatsApp ' . $wa]) as $b) $pdf->Cell($lebar * 0.6, 4.3, pdfTeks($b), 0, 2, 'L');
    $yKiri = $pdf->GetY();
    $pdf->SetXY(18 + $lebar * 0.6, $yKop);
    foreach (['No. ' . $r['kwitansi_no'], $kota . ', ' . tanggalID($r['tanggal'])] as $b) {
        $pdf->Cell($lebar * 0.4, 4.3, pdfTeks($b), 0, 2, 'R');
    }
    $pdf->SetY(max($yKiri, $pdf->GetY()) + 3);
    $pdf->SetDrawColor(...PdfPenawaran::TINTA);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(18, $pdf->GetY(), 18 + $lebar, $pdf->GetY());
    $pdf->SetLineWidth(0.2);
    $pdf->Ln(7);

    // ---------- Isi ----------
    $label = function (string $teks) use ($pdf) {
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->warna(PdfPenawaran::PUDAR);
        $pdf->Cell(46, 6, pdfTeks(mb_strtoupper($teks)), 0, 0);
    };
    $isi = function (string $teks, string $gaya = '', float $ukuran = 10.5) use ($pdf, $lebar) {
        $pdf->SetFont('Helvetica', $gaya, $ukuran);
        $pdf->warna(PdfPenawaran::TINTA);
        $pdf->MultiCell($lebar - 46, 6, pdfTeks($teks), 0, 'L');
        $pdf->Ln(1.5);
    };

    $label('Telah terima dari');
    $isi($namaKlien . ($r['pengirim'] !== '' && mb_strtolower($r['pengirim']) !== mb_strtolower($namaKlien)
        ? "\nmelalui transfer a.n. " . $r['pengirim'] : ''), 'B');
    $label('Uang sejumlah');
    $isi(rupiah($jumlah), 'B', 14);

    // Kotak terbilang
    $pdf->SetX(18 + 46);
    $pdf->SetFillColor(...PdfPenawaran::LATAR);
    $pdf->SetFont('Helvetica', 'I', 9.5);
    $pdf->warna(PdfPenawaran::ABU);
    $pdf->MultiCell($lebar - 46, 6, pdfTeks('# ' . ucfirst(terbilang($jumlah)) . ' #'), 0, 'L', true);
    $pdf->Ln(3);

    $untuk = implode(', ', array_map(fn($g) => $g['label'] . ((float) $g['jumlah'] + 0.5 < (float) $g['amount'] && !$g['paid_at'] ? ' (sebagian)' : ''), $grup));
    $label('Untuk pembayaran');
    $isi($untuk . "\nJasa wedding organizer pernikahan " . bayarNamaKlien($c)
        . ($c['wedding_date'] ? ', ' . hariID($c['wedding_date']) . ' ' . tanggalID($c['wedding_date']) : ''));
    $label('Cara bayar');
    $isi($r['metode'] ?: 'Transfer bank');

    // ---------- Ringkasan kontrak ----------
    $pdf->Ln(2);
    $pdf->judul('Ringkasan pembayaran');
    $pdf->barisAngka('Nilai kontrak', rupiah($rk['kontrak']));
    $pdf->barisAngka('Sudah diterima', rupiah($rk['diterima']));
    $pdf->barisAngka('Sisa', rupiah($rk['sisa']), 'B');
    if ($rk['berikutnya']) {
        $b = $rk['berikutnya'];
        $pdf->SetFont('Helvetica', '', 8.8);
        $pdf->warna(PdfPenawaran::ABU);
        $pdf->MultiCell($lebar, 4.6, pdfTeks('Berikutnya: ' . $b['label'] . ' ' . rupiah($b['sisa'])
            . ($b['due_date'] ? ', paling lambat ' . tanggalID($b['due_date']) : '') . '.'), 0, 'L');
    }

    // ---------- Tanda tangan ----------
    $pdf->Ln(10);
    $pdf->cukup(30);
    $x = 18 + $lebar - 62;
    $pdf->SetX($x);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->warna(PdfPenawaran::ABU);
    $pdf->Cell(62, 5, pdfTeks($kota . ', ' . tanggalID($r['tanggal'])), 0, 2, 'C');
    $pdf->Ln(16);
    $pdf->SetX($x);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->warna(PdfPenawaran::TINTA);
    $pdf->Cell(62, 5, pdfTeks($ttd), 'T', 1, 'C');

    // ---------- Cap ----------
    $cap = $batal ? 'DIBATALKAN' : ($rk['sisa'] <= 0.5 ? 'LUNAS' : '');
    if ($cap !== '') {
        $pdf->SetFont('Helvetica', 'B', 26);
        [$rr, $gg, $bb] = $batal ? [176, 58, 58] : [47, 115, 85];
        $pdf->SetTextColor($rr, $gg, $bb);
        $pdf->SetDrawColor($rr, $gg, $bb);
        $pdf->SetLineWidth(0.8);
        $w = $pdf->GetStringWidth($cap) + 12;
        $pdf->SetXY(18, $pdf->GetY() - 24);
        $pdf->Cell($w, 13, $cap, 1, 1, 'C');
        $pdf->SetLineWidth(0.2);
    }
    return $pdf->Output('S');
}

function kwitansiNamaBerkas(int $receiptId): string
{
    $r = one("SELECT r.kwitansi_no, c.name FROM payment_receipts r JOIN clients c ON c.id = r.client_id WHERE r.id = ?", [$receiptId]);
    return 'Kwitansi-' . trim(preg_replace('/[^A-Za-z0-9]+/', '-', ($r['kwitansi_no'] ?? '') . '-' . ($r['name'] ?? '')), '-') . '.pdf';
}
