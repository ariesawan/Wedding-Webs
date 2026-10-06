<?php
/**
 * ============================================================
 * PDF PRICE LIST / PENAWARAN
 * ============================================================
 *
 * Dokumen yang dikirim ke WhatsApp klien. Dibuat di server dengan FPDF
 * (satu berkas, tanpa Composer) — bukan "Ctrl+P → Simpan PDF" dari
 * peramban, karena berkasnya harus bisa dilampirkan otomatis oleh gateway
 * WhatsApp tanpa ada orang yang membukanya lebih dulu.
 *
 * Susunannya mengikuti pilihan owner: paket + rincian isi. Satu harga paket,
 * isi dikelompokkan tanpa harga per baris, tambahan & opsional dengan
 * harganya sendiri, lalu termin pembayaran dan rekening.
 *
 * Font inti PDF (Helvetica/Times) hanya mengenal Windows-1252, jadi teks
 * UTF-8 diterjemahkan dulu. Huruf Indonesia, "—", "•", "±", "×" aman; simbol
 * di luar itu diganti padanan terdekat.
 */
if (!defined('FPDF_FONTPATH')) define('FPDF_FONTPATH', __DIR__ . '/lib/fpdf/font/');
require_once __DIR__ . '/lib/fpdf/fpdf.php';
require_once __DIR__ . '/penawaran.php';

class PdfPenawaran extends FPDF
{
    public string $kaki = '';

    /** Warna dasar dokumen. */
    const TINTA = [28, 24, 16];
    const ABU   = [110, 104, 94];
    const PUDAR = [160, 154, 144];
    const AKSEN = [169, 101, 27];
    const GARIS = [222, 214, 200];
    const LATAR = [247, 243, 234];

    public function warna(array $c): void { $this->SetTextColor($c[0], $c[1], $c[2]); }

    public function Footer(): void
    {
        $this->SetY(-13);
        $this->SetDrawColor(...self::GARIS);
        $this->Line($this->lMargin, $this->GetY() - 2, $this->w - $this->rMargin, $this->GetY() - 2);
        $this->SetFont('Helvetica', '', 7.5);
        $this->warna(self::PUDAR);
        $this->Cell(0, 5, pdfTeks($this->kaki), 0, 0, 'L');
        $this->Cell(0, 5, pdfTeks('Halaman ' . $this->PageNo() . '/{nb}'), 0, 0, 'R');
    }

    /** Pindah halaman kalau sisa ruang kurang dari $tinggi mm. */
    public function cukup(float $tinggi): void
    {
        if ($this->GetY() + $tinggi > $this->PageBreakTrigger) $this->AddPage();
    }

    /** Judul bagian: huruf kapital kecil berwarna aksen + garis tipis. */
    public function judul(string $teks): void
    {
        $this->cukup(16);
        $this->Ln(4);
        $this->SetFont('Helvetica', 'B', 8);
        $this->warna(self::AKSEN);
        $this->Cell(0, 5, pdfTeks(mb_strtoupper($teks)), 0, 1);
        $this->SetDrawColor(...self::GARIS);
        $this->Line($this->lMargin, $this->GetY(), $this->w - $this->rMargin, $this->GetY());
        $this->Ln(2.5);
    }

    /** Baris dua kolom: teks kiri (boleh panjang) + angka kanan. */
    public function barisAngka(string $kiri, string $kanan, string $gaya = '', float $ukuran = 9.5, string $sub = ''): void
    {
        $lebarAngka = 42;
        $lebarKiri  = $this->w - $this->lMargin - $this->rMargin - $lebarAngka;
        $this->cukup(8);
        $y = $this->GetY();
        $this->SetFont('Helvetica', $gaya, $ukuran);
        $this->warna(self::TINTA);
        $this->MultiCell($lebarKiri, 5, pdfTeks($kiri), 0, 'L');
        if ($sub !== '') {
            $this->SetFont('Helvetica', '', 8);
            $this->warna(self::ABU);
            $this->MultiCell($lebarKiri, 4, pdfTeks($sub), 0, 'L');
        }
        $yAkhir = $this->GetY();
        $this->SetXY($this->lMargin + $lebarKiri, $y);
        $this->SetFont('Helvetica', $gaya, $ukuran);
        $this->warna(self::TINTA);
        $this->Cell($lebarAngka, 5, pdfTeks($kanan), 0, 0, 'R');
        $this->SetY(max($yAkhir, $y + 5) + 1);
    }
}

/** UTF-8 → Windows-1252 untuk font inti PDF. */
function pdfTeks(string $s): string
{
    $s = strtr($s, ['→' => '->', '←' => '<-', '≈' => '~', '…' => '...', "\u{00A0}" => ' ']);
    $hasil = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
    return $hasil === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $hasil;
}

/** Bangun PDF satu price list / penawaran. Mengembalikan isi berkas (biner). */
function quotePdf(int $quoteId): string
{
    $d = quoteData($quoteId);
    if (!$d) throw new RuntimeException('Penawaran tidak ditemukan.');
    $qq = $d['q'];

    $brand   = setting('site_name', 'Callalily Party');
    $tagline = setting('site_tagline', 'Wedding Organizer · Yogyakarta');
    $alamat  = trim(implode(', ', array_filter([setting('address_street', ''), setting('address_city', ''),
                                                 setting('address_region', '')])));
    $situs   = preg_replace('#^https?://#', '', rtrim(BASE_URL, '/'));
    $email   = (string) setting('contact_email', '');
    $wa      = waTampil($d['wa']);

    $pdf = new PdfPenawaran('P', 'mm', 'A4');
    $pdf->SetMargins(16, 16, 16);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AliasNbPages();
    // true = sudah UTF-8. Tanpa itu FPDF memanggil utf8_encode(), yang
    // berstatus usang sejak PHP 8.2 dan bisa menyisipkan pesan ke berkas.
    $pdf->SetTitle($d['jenisLbl'] . ' ' . $qq['nomor'] . ' - ' . $d['nama'], true);
    $pdf->SetAuthor($brand, true);
    $pdf->kaki = $brand . ' · WhatsApp ' . $wa . ($situs ? ' · ' . $situs : '');
    $pdf->AddPage();
    $lebar = $pdf->GetPageWidth() - 32;

    // ---------- Kop ----------
    $pdf->SetFont('Times', 'BI', 22);
    $pdf->warna(PdfPenawaran::TINTA);
    $pdf->Cell($lebar * 0.6, 9, pdfTeks($brand), 0, 0, 'L');
    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->warna(PdfPenawaran::AKSEN);
    $pdf->Cell($lebar * 0.4, 9, pdfTeks(mb_strtoupper($d['jenisLbl'])), 0, 1, 'R');

    $yKop = $pdf->GetY();
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->warna(PdfPenawaran::ABU);
    $kiri = array_filter([$tagline, $alamat, 'WhatsApp ' . $wa . ($email ? ' · ' . $email : '')]);
    foreach ($kiri as $baris) $pdf->Cell($lebar * 0.6, 4.3, pdfTeks($baris), 0, 2, 'L');
    $yKiri = $pdf->GetY();

    $pdf->SetXY(16 + $lebar * 0.6, $yKop);
    $kanan = ['No. ' . $qq['nomor'] . ($qq['revisi'] > 1 ? ' · rev ' . (int) $qq['revisi'] : ''),
              'Tanggal ' . tanggalID(substr((string) $qq['created_at'], 0, 10))];
    if ($qq['valid_until']) $kanan[] = 'Berlaku s/d ' . tanggalID($qq['valid_until']);
    foreach ($kanan as $baris) $pdf->Cell($lebar * 0.4, 4.3, pdfTeks($baris), 0, 2, 'R');
    $pdf->SetY(max($yKiri, $pdf->GetY()) + 3);
    $pdf->SetDrawColor(...PdfPenawaran::TINTA);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(16, $pdf->GetY(), 16 + $lebar, $pdf->GetY());
    $pdf->SetLineWidth(0.2);
    $pdf->Ln(5);

    // ---------- Untuk ----------
    $info = [['Untuk', $d['nama']]];
    if ($qq['wedding_date']) $info[] = ['Tanggal acara', hariID($qq['wedding_date']) . ', ' . tanggalID($qq['wedding_date'])];
    if ($qq['venue'] || $qq['city']) $info[] = ['Lokasi', trim($qq['venue'] . ($qq['venue'] && $qq['city'] ? ', ' : '') . $qq['city'])];
    if ($qq['guest_estimate']) $info[] = ['Perkiraan tamu', '±' . number_format((int) $qq['guest_estimate'], 0, ',', '.') . ' tamu'];
    $kol = $lebar / max(1, count($info));
    $yInfo = $pdf->GetY();
    $yMaks = $yInfo;
    foreach ($info as $i => [$lbl, $val]) {
        $pdf->SetXY(16 + $i * $kol, $yInfo);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->warna(PdfPenawaran::PUDAR);
        $pdf->Cell($kol - 3, 4, pdfTeks(mb_strtoupper($lbl)), 0, 2);
        $pdf->SetFont('Helvetica', 'B', 9.5);
        $pdf->warna(PdfPenawaran::TINTA);
        $pdf->MultiCell($kol - 3, 4.6, pdfTeks($val), 0, 'L');
        $yMaks = max($yMaks, $pdf->GetY());
    }
    // Nama atau lokasi yang panjang turun ke baris berikutnya — kotak paket
    // mulai di bawah kolom yang paling tinggi, bukan di jarak tetap.
    $pdf->SetY(max($yInfo + 14, $yMaks + 3));

    // ---------- Kotak paket ----------
    if ($d['paket']) {
        $pdf->cukup(24);
        $y = $pdf->GetY();
        $pdf->SetFillColor(...PdfPenawaran::LATAR);
        $pdf->SetDrawColor(...PdfPenawaran::GARIS);
        $pdf->Rect(16, $y, $lebar, 18, 'DF');
        $pdf->SetXY(21, $y + 3);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->warna(PdfPenawaran::AKSEN);
        $pdf->Cell(80, 4, 'PAKET', 0, 2);
        $pdf->SetFont('Times', 'BI', 16);
        $pdf->warna(PdfPenawaran::TINTA);
        $pdf->Cell($lebar - 70, 8, pdfTeks($qq['paket_nama']), 0, 0);
        $pdf->SetXY(16 + $lebar - 70, $y + 6);
        $harga = (float) $qq['paket_harga'];
        $pdf->SetFont('Helvetica', 'B', $harga > 0 ? 14 : 9);
        $pdf->Cell(65, 8, pdfTeks($harga > 0 ? rupiah($harga) : 'Harga dikonfirmasi admin'), 0, 0, 'R');
        $pdf->SetY($y + 22);
    }

    // ---------- Rincian isi ----------
    if ($d['isi']) {
        $pdf->judul($d['paket'] ? 'Termasuk dalam paket' : 'Rincian');
        foreach ($d['isi'] as $kel => $baris) {
            $pdf->cukup(12);
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->warna(PdfPenawaran::TINTA);
            $pdf->Cell(0, 5.5, pdfTeks($kel), 0, 1);
            foreach ($baris as $it) {
                $pdf->cukup(6);
                $x = array_filter([$it['detail'], qtyTeks($it)]);
                $pdf->SetX(20);
                $pdf->SetFont('Helvetica', '', 9.2);
                $pdf->warna(PdfPenawaran::TINTA);
                $label = '•  ' . $it['label'];
                $w = $pdf->GetStringWidth(pdfTeks($label));
                if ($x && $w < $lebar - 30) {
                    $pdf->Cell($w + 1.5, 5, pdfTeks($label), 0, 0);
                    $pdf->SetFont('Helvetica', '', 8.6);
                    $pdf->warna(PdfPenawaran::ABU);
                    $pdf->MultiCell($lebar - 6 - $w - 1.5, 5, pdfTeks('— ' . implode(' · ', $x)), 0, 'L');
                } else {
                    $pdf->MultiCell($lebar - 4, 5, pdfTeks($label . ($x ? ' — ' . implode(' · ', $x) : '')), 0, 'L');
                }
            }
            $pdf->Ln(1.5);
        }
    }

    // ---------- Tambahan berbiaya ----------
    if ($d['tambahan']) {
        $pdf->judul($d['paket'] ? 'Tambahan' : 'Rincian berbiaya');
        foreach ($d['tambahan'] as $it) {
            $x = array_filter([$it['detail'], qtyTeks($it) && (float) $it['qty'] != 1
                ? qtyTeks($it) . ' × ' . rupiah((float) $it['harga']) : '']);
            $pdf->barisAngka($it['label'], rupiah((float) $it['jumlah']), '', 9.5, implode(' · ', $x));
        }
    }

    // ---------- Total ----------
    $pdf->cukup(28);
    $pdf->Ln(3);
    $xTot = 16 + $lebar * 0.45;
    $wTot = $lebar * 0.55;
    $rowTot = function (string $l, string $v, bool $tebal = false) use ($pdf, $xTot, $wTot) {
        $pdf->SetX($xTot);
        $pdf->SetFont('Helvetica', $tebal ? 'B' : '', $tebal ? 12 : 9.5);
        $pdf->warna($tebal ? PdfPenawaran::TINTA : PdfPenawaran::ABU);
        $pdf->Cell($wTot * 0.5, $tebal ? 8 : 5.5, pdfTeks($l), 0, 0);
        $pdf->warna(PdfPenawaran::TINTA);
        $pdf->Cell($wTot * 0.5, $tebal ? 8 : 5.5, pdfTeks($v), 0, 1, 'R');
    };
    $paketHarga = (float) ($qq['paket_harga'] ?? 0);
    $tambahan   = array_sum(array_map(fn($i) => (float) $i['jumlah'], $d['tambahan']));
    if ($d['paket'] && $paketHarga > 0 && ($tambahan > 0 || (float) $qq['diskon'] > 0)) {
        $rowTot('Harga paket', rupiah($paketHarga));
        if ($tambahan > 0) $rowTot('Tambahan', rupiah($tambahan));
    } elseif ((float) $qq['diskon'] > 0) {
        $rowTot('Subtotal', rupiah((float) $qq['subtotal']));
    }
    if ((float) $qq['diskon'] > 0) $rowTot('Potongan', '− ' . rupiah((float) $qq['diskon']));
    $pdf->SetDrawColor(...PdfPenawaran::TINTA);
    $pdf->Line($xTot, $pdf->GetY() + 1, 16 + $lebar, $pdf->GetY() + 1);
    $pdf->Ln(2);
    $rowTot('TOTAL', (float) $qq['total'] > 0 ? rupiah((float) $qq['total']) : 'Dikonfirmasi admin', true);

    // ---------- Opsional ----------
    if ($d['opsi']) {
        $pdf->judul('Bisa ditambahkan — di luar total');
        foreach ($d['opsi'] as $it) {
            $pdf->barisAngka($it['label'], (float) $it['jumlah'] > 0 ? rupiah((float) $it['jumlah']) : 'tanya admin',
                             '', 9.2, (string) $it['detail']);
        }
    }

    // ---------- Pembayaran ----------
    if ($d['termin']) {
        $pdf->judul('Pembayaran');
        $pdf->SetFont('Helvetica', '', 7.5);
        $pdf->warna(PdfPenawaran::PUDAR);
        $w = [$lebar * 0.42, $lebar * 0.12, $lebar * 0.22, $lebar * 0.24];
        foreach (['TERMIN', '%', 'NOMINAL', 'PALING LAMBAT'] as $i => $h) {
            $pdf->Cell($w[$i], 5, $h, 0, 0, $i >= 1 ? 'R' : 'L');
        }
        $pdf->Ln(6);
        foreach ($d['termin'] as $t) {
            $pdf->cukup(6);
            $pdf->SetFont('Helvetica', '', 9.2);
            $pdf->warna(PdfPenawaran::TINTA);
            $pdf->Cell($w[0], 5.5, pdfTeks($t['label']), 'B', 0);
            $pdf->Cell($w[1], 5.5, pdfTeks($t['persen'] !== null ? persenTeks($t['persen']) . '%' : ''), 'B', 0, 'R');
            $pdf->Cell($w[2], 5.5, pdfTeks(rupiah($t['amount'])), 'B', 0, 'R');
            $pdf->Cell($w[3], 5.5, pdfTeks($t['due_date'] ? tanggalID($t['due_date']) : '—'), 'B', 1, 'R');
        }
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', '', 8.6);
        $pdf->warna(PdfPenawaran::ABU);
        $pdf->MultiCell($lebar, 4.5, pdfTeks('DP 30% mengunci tanggal acara. Setelah DP diterima, persiapan dilanjutkan bersama admin office: '
            . 'biodata lengkap & keluarga, dekorasi, venue, vendor, dan jadwal meeting.'), 0, 'L');
        if ($d['rekening']) {
            $pdf->Ln(1.5);
            $pdf->SetFont('Helvetica', 'B', 9.2);
            $pdf->warna(PdfPenawaran::TINTA);
            $pdf->MultiCell($lebar, 5, pdfTeks('Transfer ke: ' . implode(' ', $d['rekening'])), 0, 'L');
        }
    }

    // ---------- Catatan & ketentuan ----------
    if (trim((string) $qq['catatan']) !== '') {
        $pdf->judul('Catatan & ketentuan');
        $pdf->SetFont('Helvetica', '', 8.8);
        $pdf->warna(PdfPenawaran::ABU);
        foreach (preg_split('/\R/', trim((string) $qq['catatan'])) as $baris) {
            if (trim($baris) === '') { $pdf->Ln(2); continue; }
            $pdf->SetX(16);
            $pdf->MultiCell($lebar, 4.6, pdfTeks('•  ' . ltrim($baris, "-•* \t")), 0, 'L');
        }
    }

    // ---------- Penutup ----------
    $pdf->cukup(22);
    $pdf->Ln(6);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->warna(PdfPenawaran::TINTA);
    $pdf->MultiCell($lebar, 5, pdfTeks('Kalau cocok, balas lewat WhatsApp ' . $wa . ' — kami bantu proses DP dan '
        . 'menyiapkan langkah berikutnya. Terima kasih sudah mempercayakan hari kalian kepada ' . $brand . '.'), 0, 'L');

    return $pdf->Output('S');
}

/** Kirim PDF ke peramban. $unduh = true memaksa simpan, false = buka di tab. */
function quotePdfKirim(int $quoteId, bool $unduh = false): never
{
    $d = quoteData($quoteId);
    if (!$d) { http_response_code(404); exit('Penawaran tidak ditemukan.'); }
    $isi = quotePdf($quoteId);
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($isi));
    header('Content-Disposition: ' . ($unduh ? 'attachment' : 'inline') . '; filename="' . $d['berkas'] . '"');
    header('Cache-Control: private, max-age=0, no-cache');
    header('X-Robots-Tag: noindex, nofollow');
    echo $isi;
    exit;
}
