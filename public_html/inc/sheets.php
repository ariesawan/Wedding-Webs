<?php
require_once __DIR__ . '/google.php';

/**
 * ============================================================
 * GOOGLE SHEETS — ekspor otomatis pipeline, jadwal, dan keuangan
 * ============================================================
 *
 * Kenapa perlu? Panel admin bagus untuk mengerjakan, tapi buruk untuk
 * mengolah angka. Owner sering mau memfilter, membuat pivot, atau berbagi
 * rekap ke rekan tanpa memberi akses panel. Spreadsheet menjawab itu.
 *
 * Arahnya SATU JALUR: sistem → spreadsheet. Sheet tidak pernah dibaca
 * balik untuk mengubah database. Ini disengaja — kalau dua arah, konflik
 * penyuntingan bersamaan akan merusak data dan hampir mustahil ditelusuri
 * di shared hosting tanpa antrean pekerjaan.
 *
 * Scope drive.file hanya memberi akses ke berkas yang DIBUAT aplikasi ini,
 * bukan seluruh Drive owner.
 */

function sheetsEnabled(): bool
{
    return setting('sheet_enabled') === '1' && googleConnected() && googleHasScope(G_SCOPE_SHEET);
}

function sheetsCall(string $method, string $path, ?array $body = null, string $base = G_SHEETS_API): array
{
    $r = httpJson($method, $base . $path, ['Authorization: Bearer ' . googleAccessToken()], $body);
    if ($r['code'] >= 400) {
        $msg = $r['json']['error']['message'] ?? $r['raw'];
        if ($r['code'] === 403 && stripos($msg, 'scope') !== false) {
            $msg .= ' — izin Spreadsheet belum diberikan. Buka Integrasi lalu tekan "Hubungkan ulang".';
        }
        throw new RuntimeException('Google Sheets API ' . $r['code'] . ': ' . $msg);
    }
    return $r['json'];
}

/** Buat spreadsheet baru berisi seluruh tab yang dibutuhkan. */
function sheetsCreate(): string
{
    $judul = setting('site_name', 'Callalily Party') . ' — Data Operasional';
    $res = sheetsCall('POST', '', [
        'properties' => ['title' => $judul, 'locale' => 'id_ID', 'timeZone' => APP_TZ],
        'sheets' => array_map(fn($t) => ['properties' => ['title' => $t]],
                              ['Klien', 'Jadwal', 'Pembayaran', 'Checklist', 'Event', 'Ringkasan']),
    ]);
    $id = $res['spreadsheetId'] ?? '';
    if (!$id) throw new RuntimeException('Google tidak mengembalikan ID spreadsheet.');
    settingSet('sheet_id', $id);
    settingSet('sheet_url', $res['spreadsheetUrl'] ?? ('https://docs.google.com/spreadsheets/d/' . $id));
    return $id;
}

/** Pastikan sebuah tab ada; kalau belum, buat. */
function sheetsEnsureTab(string $sheetId, string $tab): void
{
    static $known = [];
    if (!isset($known[$sheetId])) {
        $meta = sheetsCall('GET', '/' . rawurlencode($sheetId) . '?fields=sheets.properties.title');
        $known[$sheetId] = array_column(array_column($meta['sheets'] ?? [], 'properties'), 'title');
    }
    if (in_array($tab, $known[$sheetId], true)) return;

    sheetsCall('POST', '/' . rawurlencode($sheetId) . ':batchUpdate', [
        'requests' => [['addSheet' => ['properties' => ['title' => $tab]]]],
    ]);
    $known[$sheetId][] = $tab;
}

/** Tulis ulang isi satu tab: bersihkan lalu isi dari baris pertama. */
function sheetsWriteTab(string $sheetId, string $tab, array $rows): void
{
    sheetsEnsureTab($sheetId, $tab);
    $range = rawurlencode("'" . str_replace("'", "''", $tab) . "'");

    sheetsCall('POST', '/' . rawurlencode($sheetId) . '/values/' . $range . ':clear', []);
    if (!$rows) return;

    sheetsCall('PUT', '/' . rawurlencode($sheetId) . '/values/' . $range
               . '?valueInputOption=USER_ENTERED', ['values' => $rows]);
}

/** Tebalkan baris judul dan bekukan supaya tetap terlihat saat digulir. */
function sheetsFormatHeaders(string $sheetId): void
{
    $meta = sheetsCall('GET', '/' . rawurlencode($sheetId) . '?fields=sheets.properties');
    $req = [];
    foreach ($meta['sheets'] ?? [] as $s) {
        $gid = $s['properties']['sheetId'] ?? null;
        if ($gid === null) continue;
        $req[] = ['updateSheetProperties' => [
            'properties' => ['sheetId' => $gid, 'gridProperties' => ['frozenRowCount' => 1]],
            'fields'     => 'gridProperties.frozenRowCount',
        ]];
        $req[] = ['repeatCell' => [
            'range' => ['sheetId' => $gid, 'startRowIndex' => 0, 'endRowIndex' => 1],
            'cell'  => ['userEnteredFormat' => [
                'backgroundColor' => ['red' => 0.05, 'green' => 0.06, 'blue' => 0.12],
                'textFormat'      => ['bold' => true, 'foregroundColor' => ['red' => 0.91, 'green' => 0.66, 'blue' => 0.36]],
            ]],
            'fields' => 'userEnteredFormat(backgroundColor,textFormat)',
        ]];
        $req[] = ['autoResizeDimensions' => [
            'dimensions' => ['sheetId' => $gid, 'dimension' => 'COLUMNS', 'startIndex' => 0, 'endIndex' => 14],
        ]];
    }
    if ($req) sheetsCall('POST', '/' . rawurlencode($sheetId) . ':batchUpdate', ['requests' => $req]);
}

/**
 * Sinkronkan semua tab. Mengembalikan ringkasan jumlah baris per tab.
 * Sengaja menulis ulang seluruh isi, bukan menambah baris — dengan begitu
 * data yang dihapus di sistem ikut hilang dari sheet, dan tidak ada risiko
 * baris ganda kalau cron kebetulan jalan dua kali.
 */
function sheetsSyncAll(): array
{
    if (!setting('sheet_enabled')) throw new RuntimeException('Fitur Spreadsheet belum diaktifkan.');
    if (!googleConnected())        throw new RuntimeException('Google belum terhubung.');
    if (!googleHasScope(G_SCOPE_SHEET)) {
        throw new RuntimeException('Izin Spreadsheet belum diberikan. Buka Integrasi lalu tekan "Hubungkan ulang Google".');
    }

    $id = setting('sheet_id');
    if (!$id) $id = sheetsCreate();

    $tgl = fn($d) => $d ? date('Y-m-d', strtotime($d)) : '';
    $out = [];

    // ---------- Klien ----------
    $rows = [['ID','Nama','Pasangan','Email','Telepon','Sumber','Tahap','Tgl Nikah','Venue','Kota',
              'Estimasi Tamu','Paket','Nilai Deal','Tindakan Berikutnya','Tenggat','Alasan Batal','Dibuat']];
    foreach (all("SELECT * FROM clients ORDER BY id") as $c) {
        $rows[] = [
            $c['id'], $c['name'], $c['partner_name'], $c['email'], $c['phone'], $c['source'],
            stageLabel($c['stage']), $tgl($c['wedding_date']), $c['venue'], $c['city'],
            $c['guest_estimate'] ?: '', $c['package'],
            $c['deal_value'] !== null ? (float) $c['deal_value'] : '',
            $c['next_action'], $tgl($c['next_action_at']), $c['lost_reason'], $tgl($c['created_at']),
        ];
    }
    sheetsWriteTab($id, 'Klien', $rows);
    $out['Klien'] = count($rows) - 1;

    // ---------- Jadwal ----------
    $rows = [['ID','Judul','Klien','Email','Mulai','Selesai','Format','Status','Hasil','Catatan Hasil','Tautan']];
    foreach (all("SELECT * FROM meetings ORDER BY start_at DESC") as $m) {
        $rows[] = [
            $m['id'], $m['title'], $m['client_name'], $m['client_email'],
            date('Y-m-d H:i', strtotime($m['start_at'])), date('Y-m-d H:i', strtotime($m['end_at'])),
            ['meet'=>'Google Meet','zoom'=>'Zoom','onsite'=>'Tatap muka','phone'=>'Telepon'][$m['mode']] ?? $m['mode'],
            $m['status'],
            ['' => '', 'lanjut' => 'Lanjut', 'pikir' => 'Masih dipikir', 'batal' => 'Tidak jadi'][$m['outcome'] ?? ''] ?? '',
            $m['outcome_note'] ?? '',
            $m['zoom_join_url'] ?: ($m['meet_url'] ?: ''),
        ];
    }
    sheetsWriteTab($id, 'Jadwal', $rows);
    $out['Jadwal'] = count($rows) - 1;

    // ---------- Pembayaran ----------
    $rows = [['ID','Klien','Termin','Nominal','Jatuh Tempo','Dibayar','Metode','Status','Catatan']];
    foreach (all("SELECT p.*, c.name, c.partner_name FROM payments p
                  JOIN clients c ON c.id = p.client_id
                  ORDER BY p.client_id, p.sort_order") as $p) {
        $nama = $p['name'] . ($p['partner_name'] ? ' & ' . $p['partner_name'] : '');
        $st = $p['paid_at'] ? 'Lunas'
            : (($p['due_date'] && strtotime($p['due_date']) < time()) ? 'Terlambat' : 'Belum');
        $rows[] = [$p['id'], $nama, $p['label'], (float) $p['amount'],
                   $tgl($p['due_date']), $tgl($p['paid_at']), $p['method'], $st, $p['note']];
    }
    sheetsWriteTab($id, 'Pembayaran', $rows);
    $out['Pembayaran'] = count($rows) - 1;

    // ---------- Checklist ----------
    $rows = [['Klien','Tgl Nikah','Langkah','Keterangan','Offset','Jatuh Tempo','Selesai']];
    foreach (all("SELECT t.*, c.name, c.partner_name, c.wedding_date FROM client_tasks t
                  JOIN clients c ON c.id = t.client_id
                  ORDER BY c.wedding_date, t.sort_order") as $t) {
        $nama = $t['name'] . ($t['partner_name'] ? ' & ' . $t['partner_name'] : '');
        $rows[] = [$nama, $tgl($t['wedding_date']), $t['title'], $t['detail'],
                   ($t['offset_day'] <= 0 ? 'H' . $t['offset_day'] : 'H+' . $t['offset_day']),
                   $tgl($t['due_date']), $t['done_at'] ? $tgl($t['done_at']) : ''];
    }
    sheetsWriteTab($id, 'Checklist', $rows);
    $out['Checklist'] = count($rows) - 1;

    // ---------- Event ----------
    $rows = [['ID','Judul','Pasangan','Tanggal','Venue','Kota','Kategori','Tamu','Tampil']];
    foreach (all("SELECT * FROM events ORDER BY event_date DESC") as $ev) {
        $rows[] = [$ev['id'], $ev['title'], $ev['couple'], $tgl($ev['event_date']),
                   $ev['venue'], $ev['city'], $ev['category'], $ev['guest_count'] ?: '',
                   $ev['is_published'] ? 'Ya' : 'Tidak'];
    }
    sheetsWriteTab($id, 'Event', $rows);
    $out['Event'] = count($rows) - 1;

    // ---------- Ringkasan (pakai rumus, jadi hidup di dalam spreadsheet) ----------
    $stat = fn(string $s) => (int) (one("SELECT COUNT(*) c FROM clients WHERE stage = ?", [$s])['c'] ?? 0);
    $rows = [
        ['Ringkasan', 'Nilai', '', 'Diperbarui', date('Y-m-d H:i')],
        [],
        ['PIPELINE', '', '', 'NILAI', ''],
    ];
    foreach (PIPE_STAGES as $key => $meta) {
        $rows[] = [$meta['label'], $stat($key), '', '', ''];
    }
    $tot = one("SELECT COALESCE(SUM(deal_value),0) v FROM clients WHERE stage NOT IN ('batal')");
    $lun = one("SELECT COALESCE(SUM(amount),0) v FROM payments WHERE paid_at IS NOT NULL");
    $rows[] = [];
    $rows[] = ['Total nilai deal', (float) ($tot['v'] ?? 0)];
    $rows[] = ['Sudah diterima',   (float) ($lun['v'] ?? 0)];
    $rows[] = ['Belum tertagih',   '=B' . (count($rows) - 1) . '-B' . count($rows)];
    sheetsWriteTab($id, 'Ringkasan', $rows);
    $out['Ringkasan'] = count($rows);

    try { sheetsFormatHeaders($id); } catch (Throwable $e) { /* format gagal bukan alasan gagal total */ }

    settingSet('sheet_last_sync', date('Y-m-d H:i:s'));
    settingSet('sheet_last_error', '');
    return $out;
}

function sheetsUrl(): ?string
{
    $id = setting('sheet_id');
    return $id ? (setting('sheet_url') ?: 'https://docs.google.com/spreadsheets/d/' . $id) : null;
}

/**
 * Sinkron diam-diam setelah perubahan penting (simpan jadwal, ubah tahap).
 * Sengaja menelan semua error: kegagalan menulis ke spreadsheet tidak boleh
 * membatalkan pekerjaan utama owner. Errornya disimpan untuk ditampilkan
 * di halaman Integrasi.
 */
function sheetSyncQuiet(): void
{
    if (setting('sheet_autosync') !== '1' || !sheetsEnabled()) return;
    try {
        sheetsSyncAll();
    } catch (Throwable $e) {
        settingSet('sheet_last_error', mb_substr($e->getMessage(), 0, 400));
        error_log('Sheets autosync gagal: ' . $e->getMessage());
    }
}

/* ============================================================
   PEMBACA SPREADSHEET
   Membuka isi Google Sheets langsung di panel, supaya data yang
   memang tinggal di spreadsheet — daftar vendor, harga, kontak —
   tidak perlu dibuka di tab lain.
   Hanya BACA. Penyuntingan tetap dilakukan di Google Sheets,
   supaya tidak ada dua sumber kebenaran yang bisa berbeda.
   ============================================================ */

/** Ambil ID dari URL Sheets, atau kembalikan apa adanya bila sudah berupa ID. */
function sheetIdDari(string $s): string
{
    $s = trim($s);
    if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]{20,})#', $s, $m)) return $m[1];
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $s);
}

/** Daftar nama tab beserta jumlah baris/kolomnya. */
function sheetTabs(string $sheetId): array
{
    $r = sheetsCall('GET', '/' . rawurlencode($sheetId) . '?fields=properties.title,sheets.properties');
    $out = ['judul' => $r['properties']['title'] ?? '(tanpa judul)', 'tabs' => []];
    foreach ($r['sheets'] ?? [] as $s) {
        $p = $s['properties'] ?? [];
        $out['tabs'][] = [
            'nama'   => $p['title'] ?? '',
            'baris'  => $p['gridProperties']['rowCount'] ?? 0,
            'kolom'  => $p['gridProperties']['columnCount'] ?? 0,
        ];
    }
    return $out;
}

/**
 * Baca isi satu tab.
 *
 * Dua jebakan yang ditangani di sini:
 *
 * 1. Google memangkas sel kosong di ujung kanan SETIAP baris, jadi tiap baris
 *    bisa berbeda panjang. Lebar tabel harus diambil dari baris TERPANJANG,
 *    bukan dari baris pertama — kalau tidak, spreadsheet yang baris pertamanya
 *    cuma berisi judul akan memotong seluruh kolom di bawahnya.
 *
 * 2. Baris pertama sering bukan judul kolom, melainkan judul dokumen atau sel
 *    yang digabung. Karena itu bawaannya memakai huruf kolom (A, B, C…) seperti
 *    di Google Sheets, dan baris pertama tetap ditampilkan sebagai data.
 *    Pengguna bisa menyalakan mode "baris 1 = judul kolom" bila memang begitu.
 *
 * $maks membatasi jumlah baris — tabel puluhan ribu baris tidak berguna
 * dirender penuh di peramban dan hanya membuat panel berat.
 */
function sheetBaca(string $sheetId, string $tab, int $maks = 500, bool $barisJudul = false): array
{
    // BZ = 78 kolom. Cukup lebar untuk hampir semua sheet kerja,
    // tanpa menarik seluruh grid kosong yang dibuat Sheets secara bawaan.
    $range = rawurlencode("'" . str_replace("'", "''", $tab) . "'!A1:BZ" . ($maks + 1));
    $r = sheetsCall('GET', '/' . rawurlencode($sheetId) . '/values/' . $range
                    . '?majorDimension=ROWS&valueRenderOption=FORMATTED_VALUE');
    $rows = $r['values'] ?? [];
    if (!$rows) return ['judul' => [], 'baris' => [], 'terpotong' => false, 'lebar' => 0];

    // Lebar = kolom terisi terjauh di seluruh baris.
    $lebar = 0;
    foreach ($rows as $b) $lebar = max($lebar, count($b));
    $lebar = max($lebar, 1);

    foreach ($rows as &$b) $b = array_pad(array_slice($b, 0, $lebar), $lebar, '');
    unset($b);

    if ($barisJudul) {
        $judul = array_shift($rows) ?: [];
        $judul = array_pad(array_slice($judul, 0, $lebar), $lebar, '');
    } else {
        $judul = [];
        for ($i = 0; $i < $lebar; $i++) $judul[] = kolomHuruf($i);
    }

    return ['judul' => $judul, 'baris' => $rows, 'terpotong' => count($rows) >= $maks, 'lebar' => $lebar];
}

/** 0 -> A, 25 -> Z, 26 -> AA — sama seperti penamaan kolom di spreadsheet. */
function kolomHuruf(int $i): string
{
    $s = '';
    for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $s = chr(65 + ($n - 1) % 26) . $s;
    }
    return $s;
}
