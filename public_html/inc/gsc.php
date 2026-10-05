<?php
require_once __DIR__ . '/google.php';

/**
 * ============================================================
 * GOOGLE SEARCH CONSOLE
 * ============================================================
 *
 * Menarik data pencarian nyata ke dalam panel, memakai OAuth yang SUDAH ada
 * untuk Calendar — jadi tidak ada kredensial kedua yang perlu diurus.
 *
 * Kenapa ini penting, bukan sekadar enak dilihat:
 * halaman SEO selama ini menebak apa yang perlu diperbaiki dari isi situs
 * sendiri. Search Console tahu hal yang tidak bisa ditebak dari dalam —
 * kata kunci apa yang BENAR-BENAR diketik orang sampai situs ini muncul,
 * dan di posisi berapa. Itu satu-satunya sumber yang jujur, dan hampir
 * selalu berbeda dari dugaan pemilik situsnya.
 *
 * Scope-nya read-only. Panel ini tidak perlu mengubah apa pun di Search
 * Console, jadi meminta izin tulis hanya menambah risiko tanpa manfaat.
 */

const G_SCOPE_GSC = 'https://www.googleapis.com/auth/webmasters.readonly';
const GSC_API     = 'https://www.googleapis.com/webmasters/v3';

function gscAktif(): bool
{
    return googleConnected() && googleHasScope(G_SCOPE_GSC);
}

function gscCall(string $method, string $path, ?array $body = null): array
{
    $r = httpJson($method, GSC_API . $path,
                  ['Authorization: Bearer ' . googleAccessToken()], $body);
    if ($r['code'] >= 400) {
        $msg = $r['json']['error']['message'] ?? ('HTTP ' . $r['code']);
        throw new RuntimeException('Search Console: ' . $msg);
    }
    return $r['json'] ?? [];
}

/**
 * Daftar properti yang bisa diakses akun ini.
 *
 * Properti Domain muncul sebagai 'sc-domain:callalily.party', sedangkan
 * properti awalan URL sebagai 'https://callalily.party/'. Keduanya sah dan
 * bentuknya berbeda — inilah sebabnya siteUrl harus dipilih dari daftar,
 * bukan dirangkai sendiri dari alamat situs.
 */
function gscDaftarSitus(): array
{
    try {
        $j = gscCall('GET', '/sites');
        $out = [];
        foreach ($j['siteEntry'] ?? [] as $s) {
            if (($s['permissionLevel'] ?? '') === 'siteUnverifiedUser') continue;
            $out[] = ['url' => $s['siteUrl'], 'izin' => $s['permissionLevel'] ?? ''];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** Tebak properti yang cocok dengan domain situs ini. */
function gscTebakSitus(): string
{
    $host = parse_url(setting('site_url', ''), PHP_URL_HOST) ?: '';
    if ($host === '') return '';
    foreach (gscDaftarSitus() as $s) {
        if ($s['url'] === 'sc-domain:' . $host) return $s['url'];
    }
    foreach (gscDaftarSitus() as $s) {
        if (str_contains($s['url'], $host)) return $s['url'];
    }
    return '';
}

/**
 * Tarik data performa.
 *
 * Rentang bawaan mundur 28 hari tapi berhenti 3 hari sebelum hari ini:
 * data Search Console selalu tertinggal 2–3 hari, dan menyertakan hari yang
 * datanya belum lengkap membuat grafik terlihat anjlok di ujung kanan
 * padahal tidak terjadi apa-apa.
 */
function gscPerforma(string $siteUrl, string $dimensi = 'query', int $hari = 28, int $limit = 25): array
{
    if ($siteUrl === '') return [];

    $sampai = date('Y-m-d', strtotime('-3 day'));
    $dari   = date('Y-m-d', strtotime("-" . ($hari + 3) . " day"));

    try {
        $j = gscCall('POST', '/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query', [
            'startDate'  => $dari,
            'endDate'    => $sampai,
            'dimensions' => [$dimensi],
            'rowLimit'   => $limit,
        ]);
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }

    $out = [];
    foreach ($j['rows'] ?? [] as $r) {
        $out[] = [
            'kunci'    => $r['keys'][0] ?? '',
            'klik'     => (int) ($r['clicks'] ?? 0),
            'tayang'   => (int) ($r['impressions'] ?? 0),
            'ctr'      => round(($r['ctr'] ?? 0) * 100, 1),
            'posisi'   => round($r['position'] ?? 0, 1),
        ];
    }
    return $out;
}

/** Ringkasan total pada rentang yang sama. */
function gscRingkas(string $siteUrl, int $hari = 28): array
{
    if ($siteUrl === '') return [];
    $sampai = date('Y-m-d', strtotime('-3 day'));
    $dari   = date('Y-m-d', strtotime("-" . ($hari + 3) . " day"));

    try {
        $j = gscCall('POST', '/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query', [
            'startDate' => $dari, 'endDate' => $sampai, 'rowLimit' => 1,
        ]);
    } catch (Throwable $e) {
        return [];
    }

    $r = $j['rows'][0] ?? null;
    if (!$r) return ['klik' => 0, 'tayang' => 0, 'ctr' => 0, 'posisi' => 0, 'kosong' => true];

    return [
        'klik'   => (int) ($r['clicks'] ?? 0),
        'tayang' => (int) ($r['impressions'] ?? 0),
        'ctr'    => round(($r['ctr'] ?? 0) * 100, 1),
        'posisi' => round($r['position'] ?? 0, 1),
        'dari'   => $dari,
        'sampai' => $sampai,
    ];
}

/**
 * Kata kunci yang hampir masuk halaman satu.
 *
 * Posisi 11–20 adalah tempat paling menguntungkan untuk dikerjakan: sudah
 * dianggap relevan oleh Google, tapi belum terlihat orang karena ada di
 * halaman dua. Menaikkan satu kata kunci dari posisi 12 ke 8 hampir selalu
 * lebih murah daripada mengejar kata kunci baru dari nol.
 */
function gscHampirNaik(string $siteUrl, int $hari = 28): array
{
    $semua = gscPerforma($siteUrl, 'query', $hari, 200);
    if (isset($semua['error'])) return $semua;

    $out = array_filter($semua, fn($r) => $r['posisi'] >= 8 && $r['posisi'] <= 20 && $r['tayang'] >= 5);
    usort($out, fn($a, $b) => $b['tayang'] <=> $a['tayang']);
    return array_slice($out, 0, 15);
}
