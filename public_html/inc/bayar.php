<?php
/**
 * ============================================================
 * TERMIN PEMBAYARAN — satu jalur untuk semua uang masuk
 * ============================================================
 *
 * payments          = JADWAL (termin: nama, nominal, jatuh tempo)
 * payment_receipts  = UANG MASUK (per transfer; bisa sebagian, bisa untuk
 *                     beberapa termin sekaligus, punya nomor kwitansi)
 *
 * payments.terbayar adalah cache jumlah penerimaan sah, dan paid_at berarti
 * "lunas penuh pada tanggal ini". Keduanya HANYA ditulis bayarHitungUlang().
 * Semua query lain cukup memakai (amount − terbayar) untuk sisa tagihan.
 *
 * Aturan peran:
 *   - termin DP saat klien Menunggu DP  → owner & admin early
 *   - termin lain / klien sudah deal    → owner & admin office
 *   - peran lama 'editor' tidak boleh mencatat uang
 */
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/paket.php';
require_once __DIR__ . '/wa.php';

const BAYAR_METODE = ['Transfer bank', 'Tunai', 'QRIS', 'Lainnya'];
const BUKTI_MAKS   = 8 * 1024 * 1024;

/* ============================================================
   IZIN
   ============================================================ */
function bayarBoleh(array $user, string $stage, bool $isDp): bool
{
    $peran = $user['role'] ?? '';
    if ($peran === 'owner') return true;
    if ($peran === 'admin_early') return $isDp && !stageSudahDeal($stage);
    if ($peran === 'admin_office') return stageSudahDeal($stage);
    return false;
}

function bayarSisa(array $p): float
{
    return max(0.0, round((float) $p['amount'] - (float) ($p['terbayar'] ?? 0), 2));
}

/* ============================================================
   HITUNG ULANG — satu-satunya penulis terbayar & paid_at
   ============================================================ */
function bayarHitungUlang(int $paymentId): void
{
    $p = one("SELECT id, amount, method FROM payments WHERE id = ?", [$paymentId]);
    if (!$p) return;
    $r = one("SELECT COALESCE(SUM(jumlah),0) s, MAX(tanggal) t FROM payment_receipts
              WHERE payment_id = ? AND status = 'sah'", [$paymentId]);
    $m = one("SELECT metode FROM payment_receipts WHERE payment_id = ? AND status = 'sah'
              ORDER BY tanggal DESC, id DESC LIMIT 1", [$paymentId]);
    $terbayar = round((float) ($r['s'] ?? 0), 2);
    $lunas    = (float) $p['amount'] > 0 && $terbayar + 0.5 >= (float) $p['amount'];
    q("UPDATE payments SET terbayar = ?, paid_at = ?, method = ? WHERE id = ?",
      [$terbayar, $lunas ? $r['t'] : null, $m['metode'] ?? $p['method'], $paymentId]);
}

/** Nomor kwitansi berikutnya untuk tahun tanggal itu: KW/2026/0007. Panggil di dalam kunci. */
function kwitansiNomorBaru(string $tanggal): string
{
    $th = substr($tanggal, 0, 4);
    $awal = "KW/$th/";
    $n = (int) (one("SELECT MAX(CAST(SUBSTRING(kwitansi_no, ?) AS UNSIGNED)) n FROM payment_receipts
                      WHERE kwitansi_no LIKE ?", [strlen($awal) + 1, $awal . '%'])['n'] ?? 0);
    return $awal . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
}

function bayarKunci(): void
{
    if ((int) (one("SELECT GET_LOCK('calla_kwitansi', 8) g")['g'] ?? 0) !== 1) {
        throw new RuntimeException('Sistem sedang mencatat pembayaran lain. Coba lagi sebentar.');
    }
}

function bayarLepas(): void
{
    try { q("SELECT RELEASE_LOCK('calla_kwitansi')"); } catch (Throwable $e) {}
}

/**
 * Catat uang masuk. Dialokasikan ke termin yang belum lunas — termin yang
 * dipilih dulu, lalu DP, lalu menurut jatuh tempo — dan semua alokasi dari
 * satu transfer memakai SATU nomor kwitansi.
 *
 * $opt: payment_id, pengirim, bukti, catatan, user_id, sumber
 * @return array{kwitansi:string, id:int, alokasi:array, ringkas:string}
 */
function bayarCatat(int $clientId, float $jumlah, string $tanggal, string $metode, array $opt = []): array
{
    $jumlah = round($jumlah, 2);
    if ($jumlah <= 0) throw new RuntimeException('Jumlah pembayaran belum diisi.');
    $d = DateTime::createFromFormat('Y-m-d', $tanggal);
    if (!$d || $d->format('Y-m-d') !== $tanggal) throw new RuntimeException('Tanggal diterima tidak terbaca.');
    if ($tanggal > date('Y-m-d')) throw new RuntimeException('Tanggal diterima tidak boleh setelah hari ini.');
    $metode = in_array($metode, BAYAR_METODE, true) ? $metode : 'Lainnya';
    $pilih  = (int) ($opt['payment_id'] ?? 0);
    $uid    = $opt['user_id'] ?? null;

    bayarKunci();
    $pdo = db();
    try {
        // Tombol yang tertekan dua kali: transfer yang sama, oleh orang yang
        // sama, dalam 15 detik — hampir pasti klik ganda, bukan dua transfer.
        if (one("SELECT 1 FROM payment_receipts WHERE client_id = ? AND tanggal = ? AND status = 'sah'
                  AND user_id <=> ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 SECOND)
                GROUP BY kwitansi_no HAVING ABS(SUM(jumlah) - ?) < 0.5 LIMIT 1",
                [$clientId, $tanggal, $uid, $jumlah])) {
            throw new RuntimeException('Pembayaran yang sama baru saja dicatat. Periksa daftar penerimaan.');
        }

        $termin = all("SELECT * FROM payments WHERE client_id = ? AND paid_at IS NULL AND amount > terbayar
                       ORDER BY (id = ?) DESC, (kode = 'dealing') DESC, due_date IS NULL, due_date, sort_order, id",
                      [$clientId, $pilih]);
        $totalSisa = array_sum(array_map('bayarSisa', $termin));
        if (!$termin) throw new RuntimeException('Semua termin sudah lunas. Tambah termin dulu bila ada tagihan baru.');
        if ($jumlah > $totalSisa + 0.5) {
            throw new RuntimeException('Lebih bayar ' . rupiah($jumlah - $totalSisa) . ' — sisa seluruh tagihan '
                . rupiah($totalSisa) . '. Periksa nominalnya, atau tambah termin dulu.');
        }

        $pdo->beginTransaction();
        $no = kwitansiNomorBaru($tanggal);
        $sisaUang = $jumlah; $alokasi = []; $idPertama = 0;
        foreach ($termin as $p) {
            if ($sisaUang <= 0.004) break;
            $ambil = min(bayarSisa($p), $sisaUang);
            if ($ambil <= 0) continue;
            q("INSERT INTO payment_receipts (client_id, payment_id, kwitansi_no, tanggal, jumlah, metode, pengirim,
                                             bukti, status, sumber, catatan, user_id)
               VALUES (?,?,?,?,?,?,?,?, 'sah', ?, ?, ?)",
              [$clientId, $p['id'], $no, $tanggal, $ambil, $metode,
               mb_substr(trim((string) ($opt['pengirim'] ?? '')), 0, 120),
               ($opt['bukti'] ?? null) ?: null,
               in_array($opt['sumber'] ?? 'admin', ['admin', 'portal'], true) ? ($opt['sumber'] ?? 'admin') : 'admin',
               mb_substr(trim((string) ($opt['catatan'] ?? '')), 0, 255), $uid]);
            if (!$idPertama) $idPertama = insertId();
            $sisaUang = round($sisaUang - $ambil, 2);
            $alokasi[] = ['payment_id' => (int) $p['id'], 'label' => $p['label'], 'jumlah' => $ambil,
                          'lunas' => $ambil + 0.5 >= bayarSisa($p), 'sisa' => round(bayarSisa($p) - $ambil, 2),
                          'kode' => $p['kode'], 'amount' => (float) $p['amount']];
        }
        foreach ($alokasi as $a) bayarHitungUlang($a['payment_id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        bayarLepas();
        throw $e;
    }
    bayarLepas();

    $ringkas = implode('; ', array_map(fn($a) => $a['label'] . ($a['lunas'] ? ' lunas'
        : ' ' . rupiah($a['jumlah']) . ' (sisa ' . rupiah($a['sisa']) . ')'), $alokasi));
    clientLog($clientId, 'bayar', rupiah($jumlah) . " diterima ($no)", $ringkas . ' · ' . $metode, $uid);
    return ['kwitansi' => $no, 'id' => $idPertama, 'alokasi' => $alokasi, 'ringkas' => $ringkas];
}

/**
 * Batalkan satu penerimaan (semua alokasi dengan nomor kwitansi yang sama).
 * Uang tidak pernah dihapus — barisnya tetap ada, dicoret, beralasan.
 */
function bayarBatal(int $clientId, int $receiptId, string $alasan, int $userId): array
{
    $alasan = trim($alasan);
    if ($alasan === '') throw new RuntimeException('Alasan pembatalan wajib diisi.');
    $r = one("SELECT * FROM payment_receipts WHERE id = ? AND client_id = ?", [$receiptId, $clientId]);
    if (!$r || $r['status'] !== 'sah') throw new RuntimeException('Penerimaan tidak ditemukan atau sudah dibatalkan.');

    $baris = $r['kwitansi_no'] !== ''
        ? all("SELECT * FROM payment_receipts WHERE client_id = ? AND kwitansi_no = ? AND status = 'sah'", [$clientId, $r['kwitansi_no']])
        : [$r];
    $total = 0.0;
    foreach ($baris as $b) {
        q("UPDATE payment_receipts SET status = 'batal', batal_at = NOW(), batal_oleh = ?, batal_alasan = ?
           WHERE id = ? AND client_id = ?", [$userId, mb_substr($alasan, 0, 255), $b['id'], $clientId]);
        bayarHitungUlang((int) $b['payment_id']);
        $total += (float) $b['jumlah'];
    }
    $label = $r['kwitansi_no'] ?: 'penerimaan lama';
    clientLog($clientId, 'bayar', 'Penerimaan dibatalkan: ' . rupiah($total) . " ($label)", $alasan, $userId);
    if (function_exists('logAudit')) logAudit($userId, 'bayar_batal', 'clients#' . $clientId, "$label · " . rupiah($total) . ' · ' . $alasan);

    $info = [];
    $c = one("SELECT stage FROM clients WHERE id = ?", [$clientId]);
    $dp = terminDp($clientId);
    if ($c && stageSudahDeal($c['stage']) && $dp && !$dp['paid_at']) {
        $info[] = 'Perhatian: DP klien ini sekarang belum lunas, tapi tahapnya tidak dimundurkan otomatis.';
    }
    return ['total' => $total, 'info' => $info];
}

/* ============================================================
   RINGKASAN — dipakai panel DAN dashboard pengantin
   ============================================================ */

/**
 * Status satu termin dalam bahasa yang sopan untuk klien.
 * @return array{kode:string, teks:string}
 */
function bayarStatus(array $p, ?string $hariIni = null): array
{
    $hariIni ??= date('Y-m-d');
    if ($p['paid_at']) return ['kode' => 'lunas', 'teks' => 'Lunas ' . tanggalID($p['paid_at'])];
    $sebagian = (float) ($p['terbayar'] ?? 0) > 0;
    $pre = $sebagian ? 'Diterima ' . rupiah((float) $p['terbayar']) . ' dari ' . rupiah((float) $p['amount']) . ' · ' : '';
    if (!$p['due_date']) return ['kode' => $sebagian ? 'sebagian' : 'menyusul', 'teks' => $pre . 'Tanggal menyusul'];
    $d = (int) round((strtotime($p['due_date']) - strtotime($hariIni)) / 86400);
    if ($d < 0)  return ['kode' => 'lewat', 'teks' => $pre . 'Lewat jatuh tempo ' . (-$d) . ' hari'];
    if ($d === 0) return ['kode' => 'hari_ini', 'teks' => $pre . 'Jatuh tempo hari ini'];
    if ($d <= 14) return ['kode' => 'dekat', 'teks' => $pre . 'Jatuh tempo ' . $d . ' hari lagi'];
    return ['kode' => $sebagian ? 'sebagian' : 'belum', 'teks' => $pre . 'Paling lambat ' . tanggalID($p['due_date'])];
}

/**
 * Ringkasan pembayaran satu klien. Kuncinya ditulis eksplisit — catatan
 * internal (payments.note, receipts.catatan, nama staf) tidak mungkin ikut.
 */
function bayarRingkas(int $clientId): array
{
    $termin = [];
    $kontrak = $diterima = 0.0; $berikutnya = null;
    foreach (all("SELECT id, kode, label, amount, terbayar, due_date, paid_at, method, sort_order
                  FROM payments WHERE client_id = ? ORDER BY sort_order, id", [$clientId]) as $p) {
        $kontrak += (float) $p['amount'];
        $diterima += (float) $p['terbayar'];
        $st = bayarStatus($p);
        $baris = [
            'id' => (int) $p['id'], 'label' => (string) $p['label'], 'kode' => (string) $p['kode'],
            'amount' => (float) $p['amount'], 'terbayar' => (float) $p['terbayar'], 'sisa' => bayarSisa($p),
            'due_date' => $p['due_date'], 'paid_at' => $p['paid_at'], 'metode' => (string) $p['method'],
            'status' => $st['kode'], 'status_teks' => $st['teks'],
        ];
        $termin[] = $baris;
        if (!$p['paid_at'] && bayarSisa($p) > 0 && !$berikutnya) $berikutnya = $baris;
    }
    $kwitansi = [];
    foreach (all("SELECT MIN(id) id, kwitansi_no, MIN(tanggal) tanggal, SUM(jumlah) jumlah, MAX(status) status
                  FROM payment_receipts WHERE client_id = ? AND kwitansi_no <> '' AND status IN ('sah','batal')
                  GROUP BY kwitansi_no, status ORDER BY MIN(tanggal), MIN(id)", [$clientId]) as $k) {
        $kwitansi[] = ['id' => (int) $k['id'], 'nomor' => $k['kwitansi_no'], 'tanggal' => $k['tanggal'],
                       'jumlah' => (float) $k['jumlah'], 'batal' => $k['status'] === 'batal',
                       'url' => dokUrl('kw', (int) $k['id'])];
    }
    return [
        'kontrak' => $kontrak, 'diterima' => $diterima, 'sisa' => max(0, $kontrak - $diterima),
        // floor, dan 99 selama masih ada sisa: "100% sudah dibayar" di samping
        // "Sisa Rp 400.000" membingungkan klien.
        'persen' => $kontrak > 0 ? (int) min($kontrak - $diterima > 0.5 ? 99 : 100, floor($diterima / $kontrak * 100)) : 0,
        'termin' => $termin, 'berikutnya' => $berikutnya, 'kwitansi' => $kwitansi,
    ];
}

/* ============================================================
   TAUTAN DOKUMEN BERTANDA TANGAN (kwitansi)
   ============================================================ */
function dokKunci(): string
{
    return hash_hmac('sha256', 'dokumen-v1', APP_KEY);
}

function dokSig(string $jenis, int $id): string
{
    return substr(hash_hmac('sha256', $jenis . '|' . $id, dokKunci()), 0, 24);
}

/** Tautan satu dokumen — aman diteruskan ke orang tua, tidak membuka dashboard. */
function dokUrl(string $jenis, int $id): string
{
    return url('dokumen.php?j=' . rawurlencode($jenis) . '&id=' . $id . '&s=' . dokSig($jenis, $id));
}

function dokSah(string $jenis, int $id, string $sig): bool
{
    return $id > 0 && preg_match('/^[a-f0-9]{24}$/', $sig) && hash_equals(dokSig($jenis, $id), $sig);
}

/* ============================================================
   TERBILANG (untuk kwitansi)
   ============================================================ */
function terbilang(float $n): string
{
    $n = (int) round($n);
    $s = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    $f = function (int $x) use (&$f, $s): string {
        if ($x < 12) return $s[$x];
        if ($x < 20) return $f($x - 10) . ' belas';
        if ($x < 100) return $f(intdiv($x, 10)) . ' puluh' . ($x % 10 ? ' ' . $f($x % 10) : '');
        if ($x < 200) return 'seratus' . ($x - 100 ? ' ' . $f($x - 100) : '');
        if ($x < 1000) return $f(intdiv($x, 100)) . ' ratus' . ($x % 100 ? ' ' . $f($x % 100) : '');
        if ($x < 2000) return 'seribu' . ($x - 1000 ? ' ' . $f($x - 1000) : '');
        if ($x < 1000000) return $f(intdiv($x, 1000)) . ' ribu' . ($x % 1000 ? ' ' . $f($x % 1000) : '');
        if ($x < 1000000000) return $f(intdiv($x, 1000000)) . ' juta' . ($x % 1000000 ? ' ' . $f($x % 1000000) : '');
        if ($x < 1000000000000) return $f(intdiv($x, 1000000000)) . ' miliar' . ($x % 1000000000 ? ' ' . $f($x % 1000000000) : '');
        return $f(intdiv($x, 1000000000000)) . ' triliun' . ($x % 1000000000000 ? ' ' . $f($x % 1000000000000) : '');
    };
    if ($n === 0) return 'nol rupiah';
    return trim(preg_replace('/\s+/', ' ', $f($n))) . ' rupiah';
}

/* ============================================================
   BUKTI TRANSFER — disimpan di luar akses publik
   ============================================================ */
function buktiDir(): string
{
    $kandidat = [];
    if (defined('DIR_BUKTI')) $kandidat[] = DIR_BUKTI;
    $kandidat[] = dirname(DIR_ROOT) . '/privat/bukti';
    $kandidat[] = DIR_UPLOAD . '/bukti';
    foreach ($kandidat as $i => $d) {
        if (!is_dir($d)) @mkdir($d, 0750, true);
        if (is_dir($d) && is_writable($d)) {
            // Cadangan di dalam web root: kunci dengan .htaccess + index kosong.
            if (str_starts_with(realpath($d) ?: $d, realpath(DIR_ROOT) ?: DIR_ROOT)) {
                if (!is_file("$d/.htaccess")) @file_put_contents("$d/.htaccess", "Require all denied\nDeny from all\n");
                if (!is_file("$d/index.html")) @file_put_contents("$d/index.html", '');
            }
            return rtrim($d, '/');
        }
    }
    throw new RuntimeException('Folder bukti transfer tidak bisa dibuat. Periksa izin folder di hosting.');
}

/**
 * Simpan bukti transfer dari formulir admin. Gambar di-encode ulang jadi
 * JPEG (EXIF/GPS terbuang, berkas "poliglot" patah); PDF disimpan apa adanya
 * setelah diperiksa tanda tangannya. Mengembalikan path relatif.
 */
function buktiSimpan(array $file, bool $bolehPdf = true): string
{
    require_once __DIR__ . '/image.php';
    $izin = ['image/jpeg', 'image/png', 'image/webp'];
    if ($bolehPdf) $izin[] = 'application/pdf';
    [$ok, $mime] = validateUpload($file, $izin, BUKTI_MAKS);
    if (!$ok) throw new RuntimeException('Bukti transfer: ' . $mime);

    $sub = date('Y/m');
    $dir = buktiDir() . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) throw new RuntimeException('Folder bukti tidak bisa dibuat.');
    $nama = bin2hex(random_bytes(16));

    if ($mime === 'application/pdf') {
        $kepala = (string) file_get_contents($file['tmp_name'], false, null, 0, 5);
        if ($kepala !== '%PDF-') throw new RuntimeException('Berkas PDF tidak valid.');
        $tujuan = "$dir/$nama.pdf";
        if (!move_uploaded_file($file['tmp_name'], $tujuan) && !@rename($file['tmp_name'], $tujuan)) {
            throw new RuntimeException('Bukti gagal disimpan.');
        }
    } else {
        $info = @getimagesize($file['tmp_name']);
        if (!$info || $info[0] * $info[1] > 40000000) throw new RuntimeException('Gambar bukti terlalu besar atau rusak.');
        $im = imgLoad($file['tmp_name'], $mime);
        $im = imgResize($im, 2000);
        $tujuan = "$dir/$nama.jpg";
        if (!imagejpeg($im, $tujuan, 82)) throw new RuntimeException('Bukti gagal disimpan.');
    }
    @chmod($tujuan, 0640);
    return $sub . '/' . basename($tujuan);
}

/** Path absolut bukti — hanya kalau benar-benar di dalam folder bukti. */
function buktiPath(?string $rel): ?string
{
    if (!$rel || !preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.(jpg|pdf)$#', $rel)) return null;
    try { $dasar = realpath(buktiDir()); } catch (Throwable $e) { return null; }
    $p = realpath(buktiDir() . '/' . $rel);
    return ($p && $dasar && str_starts_with($p, $dasar . DIRECTORY_SEPARATOR) && is_file($p)) ? $p : null;
}

/* ============================================================
   PESAN WHATSAPP — kwitansi & tagihan
   ============================================================ */

/** Room langsung klien (bukan grup, bukan vendor). */
function bayarRoomKlien(int $clientId): int
{
    require_once __DIR__ . '/chat.php';
    try { return chatSinkronKlien($clientId); } catch (Throwable $e) { return 0; }
}

function bayarNamaKlien(array $c): string
{
    return trim($c['name'] . ($c['partner_name'] ? ' & ' . $c['partner_name'] : ''));
}

/** Teks kwitansi untuk WhatsApp. */
function bayarTeksKwitansi(int $receiptId): ?array
{
    $r = one("SELECT * FROM payment_receipts WHERE id = ?", [$receiptId]);
    if (!$r || $r['kwitansi_no'] === '' || $r['status'] !== 'sah') return null;
    $c = one("SELECT id, name, partner_name, phone, stage FROM clients WHERE id = ?", [$r['client_id']]);
    $grup = all("SELECT r.jumlah, p.label, p.paid_at FROM payment_receipts r JOIN payments p ON p.id = r.payment_id
                 WHERE r.client_id = ? AND r.kwitansi_no = ? AND r.status = 'sah' ORDER BY p.sort_order", [$r['client_id'], $r['kwitansi_no']]);
    $jumlah = array_sum(array_map(fn($g) => (float) $g['jumlah'], $grup));
    $untuk  = implode(', ', array_map(fn($g) => $g['label'] . ($g['paid_at'] ? '' : ' (sebagian)'), $grup));
    $rk = bayarRingkas((int) $r['client_id']);
    $url = dokUrl('kw', (int) $r['id']);
    $teks = 'Terima kasih, ' . $c['name'] . '. Pembayaran ' . $untuk . ' sebesar *' . rupiah($jumlah) . '* sudah kami terima pada '
          . tanggalID($r['tanggal']) . '.'
          . "\n\nKwitansi " . $r['kwitansi_no'] . ': ' . $url
          . "\n\nTotal diterima " . rupiah($rk['diterima']) . ' dari ' . rupiah($rk['kontrak']) . '.'
          . ($rk['berikutnya'] ? "\nBerikutnya: " . $rk['berikutnya']['label'] . ' ' . rupiah($rk['berikutnya']['sisa'])
              . ($rk['berikutnya']['due_date'] ? ' paling lambat ' . tanggalID($rk['berikutnya']['due_date']) : '') . '.' : "\nSeluruh pembayaran sudah lunas. Terima kasih!");
    $nama = 'Kwitansi-' . preg_replace('/[^A-Za-z0-9]+/', '-', $r['kwitansi_no'] . '-' . $c['name']) . '.pdf';
    return ['teks' => $teks, 'url' => $url, 'nama' => $nama, 'client' => $c, 'receipt' => $r];
}

/** Kirim kwitansi lewat gateway; tanpa gateway kembalikan tautan wa.me. */
function bayarKirimKwitansi(int $receiptId, ?int $userId): array
{
    $k = bayarTeksKwitansi($receiptId);
    if (!$k) return ['ok' => false, 'error' => 'Kwitansi tidak ditemukan.'];
    $c = $k['client'];
    $waUrl = $c['phone'] ? 'https://wa.me/' . waNomor($c['phone']) . '?text=' . rawurlencode($k['teks']) : '';
    require_once __DIR__ . '/wa.php';
    if (!waSiap() || !$c['phone']) return ['ok' => false, 'wa_url' => $waUrl, 'error' => 'Gateway WhatsApp belum tersambung.'];
    $room = bayarRoomKlien((int) $c['id']);
    if (!$room) return ['ok' => false, 'wa_url' => $waUrl, 'error' => 'Nomor WhatsApp klien tidak valid.'];
    require_once __DIR__ . '/chat.php';
    $r = chatKirim($room, $k['teks'], $userId, ['client_id' => (int) $c['id'],
                   'berkas' => ['url' => $k['url'], 'nama' => $k['nama']]]);
    if ($r['ok']) {
        q("UPDATE payment_receipts SET kwitansi_wa_at = NOW() WHERE client_id = ? AND kwitansi_no = ?",
          [$c['id'], $k['receipt']['kwitansi_no']]);
    }
    return $r + ['wa_url' => $waUrl];
}

/**
 * Teks tagihan untuk satu atau beberapa termin klien.
 * $jenis: 'tagihan' (manual) | 'sebelum' | 'telat'
 */
function bayarTeksTagihan(array $c, array $termin, string $jenis = 'tagihan'): string
{
    $baris = []; $total = 0.0; $tgl = null;
    foreach ($termin as $p) {
        $sisa = bayarSisa($p);
        $total += $sisa;
        $baris[] = '• ' . $p['label'] . ' ' . rupiah($sisa) . ($p['due_date'] ? ' (paling lambat ' . tanggalID($p['due_date']) . ')' : '');
        if ($p['due_date'] && (!$tgl || $p['due_date'] < $tgl)) $tgl = $p['due_date'];
    }
    $rek = rekeningBaris();
    // Hanya tautan yang SUDAH ada. Membuat token di sini akan menghidupkan
    // lagi dashboard yang sengaja dimatikan admin. Dibaca langsung supaya
    // cron & Ringkasan (yang tidak memuat inc/portal.php) memakai teks yang sama.
    $portal = '';
    if (stageSudahDeal((string) ($c['stage'] ?? '')) && setting('portal_aktif', '1') !== '0') {
        try {
            $tok = (string) (one("SELECT portal_token FROM clients WHERE id = ?", [(int) $c['id']])['portal_token'] ?? '');
            if ($tok !== '') $portal = url('p/' . $tok);
        } catch (Throwable $e) { $portal = ''; }
    }
    $daftar = implode("\n", $baris) . (count($baris) > 1 ? "\nTotal *" . rupiah($total) . '*' : '');
    $kaki = ($rek ? "\n\nTransfer ke:\n" . implode("\n", $rek) : '')
          . ($portal ? "\n\nRincian & jadwal lengkap: " . $portal : '');

    return match ($jenis) {
        'sebelum' => 'Halo ' . $c['name'] . ', semoga persiapannya lancar. Kami mengingatkan dengan hormat:' . "\n" . $daftar . $kaki
                   . "\n\nKalau sudah transfer, abaikan pesan ini dan kirim buktinya di sini ya. Terima kasih.",
        'telat'   => 'Halo ' . $c['name'] . ', mohon maaf mengganggu. Kami belum menemukan pembayaran berikut:' . "\n" . $daftar . $kaki
                   . "\n\nMungkin sudah ditransfer tapi buktinya belum sampai — bisa dikirim di sini. Perlu penyesuaian jadwal? Balas saja, kami bantu.",
        default   => 'Halo ' . $c['name'] . ', berikut tagihan pembayaran pernikahan kalian:' . "\n" . $daftar . $kaki
                   . "\n\nSudah transfer? Balas pesan ini dengan bukti transfernya ya. Terima kasih.",
    };
}

/** Kirim tagihan (manual atau dari pengingat) dan tandai termin yang diingatkan. */
function bayarKirimTagihan(int $clientId, array $paymentIds, ?int $userId, string $jenis = 'tagihan'): array
{
    $c = one("SELECT id, name, partner_name, phone, stage FROM clients WHERE id = ?", [$clientId]);
    if (!$c) return ['ok' => false, 'error' => 'Klien tidak ditemukan.'];
    $ids = array_values(array_filter(array_map('intval', $paymentIds)));
    if (!$ids) return ['ok' => false, 'error' => 'Tidak ada termin yang ditagih.'];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $termin = all("SELECT * FROM payments WHERE client_id = ? AND id IN ($in) AND paid_at IS NULL AND amount > terbayar
                   ORDER BY due_date IS NULL, due_date, sort_order", [$clientId, ...$ids]);
    if (!$termin) return ['ok' => false, 'error' => 'Termin itu sudah lunas.'];
    $teks = bayarTeksTagihan($c, $termin, $jenis);
    $waUrl = $c['phone'] ? 'https://wa.me/' . waNomor($c['phone']) . '?text=' . rawurlencode($teks) : '';

    require_once __DIR__ . '/wa.php';
    $hasil = ['ok' => false, 'wa_url' => $waUrl, 'teks' => $teks, 'error' => 'Gateway WhatsApp belum tersambung.'];
    if (waSiap() && $c['phone'] && ($room = bayarRoomKlien($clientId))) {
        require_once __DIR__ . '/chat.php';
        $hasil = chatKirim($room, $teks, $userId, ['client_id' => $clientId]) + ['wa_url' => $waUrl, 'teks' => $teks];
    }
    if (!empty($hasil['ok'])) {
        foreach ($termin as $p) {
            $kode = (($p['due_date'] && $p['due_date'] < date('Y-m-d')) || $jenis === 'telat' ? 'telat@' : 'sebelum@') . ($p['due_date'] ?: '-');
            q("UPDATE payments SET ingat_kode = ?, ingat_at = NOW() WHERE id = ?", [$kode, $p['id']]);
        }
        clientLog($clientId, 'bayar', ($jenis === 'tagihan' ? 'Tagihan dikirim' : 'Pengingat pembayaran (' . $jenis . ')'),
                  implode(', ', array_map(fn($p) => $p['label'] . ' ' . rupiah(bayarSisa($p)), $termin)), $userId);
    }
    return $hasil;
}

/**
 * Pengingat otomatis harian (dari cron). Minimal dan sopan:
 *   - H-3 s/d hari-H tempo: satu pengingat "sebelum"
 *   - H+2 s/d H+14 setelah tempo: satu pengingat "telat" — lalu berhenti dan
 *     diserahkan ke manusia (muncul di dashboard).
 * Satu pesan per klien per hari. Bawaan MATI (setting bayar_ingat_aktif).
 *
 * @return array daftar rencana [client, jenis, termin[], status]
 */
function bayarPengingatHarian(bool $kirim, ?string $hariIni = null): array
{
    $hariIni ??= date('Y-m-d');
    $mulai = (string) setting('bayar_ingat_mulai', '');
    $baris = all("SELECT p.*, c.name, c.partner_name, c.phone, c.stage FROM payments p
                  JOIN clients c ON c.id = p.client_id
                  WHERE p.paid_at IS NULL AND p.amount > p.terbayar AND p.due_date IS NOT NULL
                    AND c.stage IN ('deal','persiapan','harih') AND c.pengingat_bayar = 1 AND c.phone <> ''
                    AND p.due_date BETWEEN DATE_SUB(?, INTERVAL 14 DAY) AND DATE_ADD(?, INTERVAL 3 DAY)
                  ORDER BY c.id, p.due_date", [$hariIni, $hariIni]);
    $rencana = [];
    foreach ($baris as $p) {
        $d = (int) round((strtotime($p['due_date']) - strtotime($hariIni)) / 86400);
        $jenis = null;
        if ($d >= 0 && $d <= 3 && !in_array($p['ingat_kode'], ['sebelum@' . $p['due_date'], 'telat@' . $p['due_date']], true)) {
            $jenis = 'sebelum';
        } elseif ($d <= -2 && $d >= -14 && $p['ingat_kode'] !== 'telat@' . $p['due_date']
                  && $mulai !== '' && $p['due_date'] >= $mulai && $p['stage'] !== 'harih') {
            $jenis = 'telat';
        }
        if (!$jenis) continue;
        $cid = (int) $p['client_id'];
        $rencana[$cid] ??= ['client_id' => $cid, 'nama' => bayarNamaKlien($p), 'jenis' => $jenis, 'termin' => [], 'status' => 'rencana'];
        if ($jenis === 'telat') $rencana[$cid]['jenis'] = 'telat';
        $rencana[$cid]['termin'][] = $p;
    }

    foreach ($rencana as $cid => &$r) {
        // Ditahan: klien baru saja mengirim gambar/berkas di room langsungnya
        // (kemungkinan bukti transfer yang belum dicatat) atau ada bukti menunggu.
        $tahan = (bool) one("SELECT 1 FROM payment_receipts WHERE client_id = ? AND status = 'menunggu' LIMIT 1", [$cid]);
        if (!$tahan) {
            try {
                $tahan = (bool) one("SELECT 1 FROM wa_messages m JOIN wa_chats ch ON ch.id = m.chat_id
                                     WHERE ch.client_id = ? AND ch.jenis = 'klien' AND ch.is_group = 0
                                       AND m.direction = 'masuk' AND m.media_url IS NOT NULL AND m.media_url <> ''
                                       AND m.created_at > DATE_SUB(NOW(), INTERVAL 48 HOUR) LIMIT 1", [$cid]);
            } catch (Throwable $e) { $tahan = false; }
        }
        if ($tahan) { $r['status'] = 'ditahan'; continue; }
        if (!$kirim) continue;
        $h = bayarKirimTagihan($cid, array_map(fn($p) => (int) $p['id'], $r['termin']), null, $r['jenis']);
        $r['status'] = !empty($h['ok']) ? 'terkirim' : 'gagal: ' . ($h['error'] ?? '?');
        if (PHP_SAPI === 'cli') sleep(2);
    }
    unset($r);
    return array_values($rencana);
}
