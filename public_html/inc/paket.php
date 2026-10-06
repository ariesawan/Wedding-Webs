<?php
/**
 * ============================================================
 * PAKET PRICE LIST
 * ============================================================
 *
 * Satu tabel untuk dua kegunaan (quote_templates):
 *   - paket yang tampil di halaman price list situs (tampil_web = 1)
 *   - template internal untuk menyusun price list / penawaran khusus
 *
 * Bentuknya "paket + rincian isi": satu harga paket, lalu daftar isinya
 * dikelompokkan (Wedding Organizer, Rias & busana, Dekorasi, …) TANPA harga
 * per baris. Baris bertanda opsional adalah tambahan berharga sendiri di
 * luar harga paket.
 */

/** Nomor WhatsApp yang boleh ditulis ke klien. */
function waNomorPublik(): string
{
    // 6281234567890 adalah contoh di kotak isian Pengaturan yang ikut
    // tersimpan apa adanya. Nomor itu tidak boleh sampai ke klien — dulu
    // tombol "Buka WhatsApp" di formulir mengarah ke sana.
    $n = preg_replace('/\D/', '', (string) setting('wa_number', ''));
    if ($n === '' || $n === '6281234567890') $n = preg_replace('/\D/', '', (string) setting('wa_admin_biasa', ''));
    if ($n === '') $n = '6281329787728';   // nomor yang tertanam di beranda sejak awal
    if (str_starts_with($n, '0')) $n = '62' . substr($n, 1);
    return $n;
}

/**
 * Nomor penanggung jawab menurut tahap: admin early sebelum DP, admin office
 * sesudahnya. Jatuh ke nomor publik bila belum diisi di Pengaturan.
 */
function waNomorPic(string $stage): string
{
    $k = stageSudahDeal($stage) ? 'wa_admin_office' : 'wa_admin_biasa';
    $n = preg_replace('/\D/', '', (string) setting($k, ''));
    if ($n === '') return waNomorPublik();
    return str_starts_with($n, '0') ? '62' . substr($n, 1) : $n;
}

/** "0819-0550-3634" — nomor yang enak dibaca di dokumen. */
function waTampil(string $n): string
{
    $n = preg_replace('/\D/', '', $n);
    if (str_starts_with($n, '62')) $n = '0' . substr($n, 2);
    return trim(implode('-', str_split($n, 4)), '-');
}

/** Baris rekening untuk dokumen dan teks WA. Kosong bila belum diisi. */
function rekeningBaris(): array
{
    $bank = trim((string) setting('bank_nama', ''));
    $no   = trim((string) setting('bank_norek', ''));
    $an   = trim((string) setting('bank_atasnama', ''));
    if ($bank === '' && $no === '') return [];
    return array_values(array_filter([$bank . ($no ? ' ' . $no : ''), $an ? 'a.n. ' . $an : '']));
}

/** Semua paket/template, atau hanya yang tampil di situs. */
function paketDaftar(bool $webSaja = false): array
{
    try {
        return all("SELECT t.*,
                           (SELECT COUNT(*) FROM quote_template_items i WHERE i.template_id = t.id AND i.opsional = 0) n_isi,
                           (SELECT COUNT(*) FROM quote_template_items i WHERE i.template_id = t.id AND i.opsional = 1) n_opsi
                      FROM quote_templates t
                     WHERE t.is_active = 1" . ($webSaja ? " AND t.tampil_web = 1" : "") . "
                     ORDER BY t.tampil_web DESC, t.urutan, t.id");
    } catch (Throwable $e) {
        return [];   // kolom paket belum ada (skema lama)
    }
}

function paketBySlug(string $slug): ?array
{
    if ($slug === '') return null;
    try { return one("SELECT * FROM quote_templates WHERE slug = ? AND is_active = 1", [$slug]); }
    catch (Throwable $e) { return null; }
}

/**
 * Isi paket dikelompokkan: ['isi' => [kelompok => [baris…]], 'opsi' => [baris…]].
 * Baris tanpa kelompok masuk "Lainnya" supaya tetap terlihat.
 */
function paketIsi(int $templateId): array
{
    $isi = []; $opsi = [];
    foreach (all("SELECT * FROM quote_template_items WHERE template_id = ? ORDER BY sort_order, id", [$templateId]) as $r) {
        if ($r['opsional']) { $opsi[] = $r; continue; }
        $isi[trim((string) ($r['kelompok'] ?? '')) ?: 'Lainnya'][] = $r;
    }
    return ['isi' => $isi, 'opsi' => $opsi];
}

/** Kelompokkan baris (dari quote_items atau template) menurut kolom kelompok. */
function kelompokkan(array $baris): array
{
    $out = [];
    foreach ($baris as $r) $out[trim((string) ($r['kelompok'] ?? '')) ?: 'Lainnya'][] = $r;
    return $out;
}

/** "mulai Rp 35 jt" / "Rp 35.000.000" / '' bila harga belum diisi. */
function paketHargaLabel(array $t, bool $ringkas = false): string
{
    $h = (float) ($t['harga'] ?? 0);
    if ($h <= 0) return '';
    $rp = $ringkas ? rupiah($h, true) : rupiah($h);
    return (!empty($t['harga_mulai']) ? 'mulai ' : '') . $rp;
}

/** Qty + satuan yang enak dibaca: "2 set", "±300 porsi". Kosong untuk "1 paket". */
function qtyTeks(array $r): string
{
    $q = (float) ($r['qty'] ?? 1);
    $s = trim((string) ($r['satuan'] ?? ''));
    if ($q == 1 && ($s === '' || $s === 'paket')) return '';
    $angka = rtrim(rtrim(number_format($q, 2, ',', '.'), '0'), ',');
    return trim($angka . ' ' . $s);
}

function slugPaket(string $nama, int $kecuali = 0): string
{
    $dasar = slugify($nama, 120) ?: 'paket';
    $slug = $dasar; $i = 2;
    while (one("SELECT id FROM quote_templates WHERE slug = ? AND id <> ?", [$slug, $kecuali])) {
        $slug = $dasar . '-' . $i++;
    }
    return $slug;
}
