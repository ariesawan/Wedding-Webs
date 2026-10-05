<?php
require_once __DIR__ . '/http.php';

/**
 * ============================================================
 * ZOOM — Server-to-Server OAuth
 * ============================================================
 *
 * JWT App sudah dimatikan Zoom sejak Juni 2023, jadi satu-satunya cara membuat
 * meeting dari server tanpa interaksi user adalah Server-to-Server OAuth (S2S).
 *
 * Cara membuatnya:
 *   marketplace.zoom.us > Develop > Build App > Server-to-Server OAuth
 *   Scope minimal: meeting:write:admin  (granular: meeting:write:meeting:admin)
 *   Catat: Account ID, Client ID, Client Secret
 *
 * Token berlaku 1 jam; kita cache di tabel settings supaya tidak minta token
 * di setiap request (Zoom membatasi jumlah request token per akun).
 */

const ZOOM_TOKEN_URL = 'https://zoom.us/oauth/token';
const ZOOM_API       = 'https://api.zoom.us/v2';

function zoomConfigured(): bool
{
    return setting('zoom_account_id') && setting('zoom_client_id') && setting('zoom_client_secret');
}

function zoomAccessToken(): string
{
    $tok = setting('zoom_access_token');
    $exp = (int) setting('zoom_token_expires', '0');
    if ($tok && $exp > time()) return $tok;

    if (!zoomConfigured()) throw new RuntimeException('Kredensial Zoom belum diisi. Buka Admin > Integrasi.');

    $basic = base64_encode(setting('zoom_client_id') . ':' . setting('zoom_client_secret'));
    $r = httpForm(ZOOM_TOKEN_URL, [
        'grant_type' => 'account_credentials',
        'account_id' => setting('zoom_account_id'),
    ], ['Authorization: Basic ' . $basic]);

    if ($r['code'] !== 200 || empty($r['json']['access_token'])) {
        throw new RuntimeException('Gagal mengambil token Zoom: ' . ($r['json']['reason'] ?? $r['json']['message'] ?? $r['raw']));
    }
    settingSet('zoom_access_token',  $r['json']['access_token'], true);
    settingSet('zoom_token_expires', (string) (time() + (int) ($r['json']['expires_in'] ?? 3600) - 120));
    return $r['json']['access_token'];
}

function zoomCall(string $method, string $path, ?array $body = null): array
{
    $r = httpJson($method, ZOOM_API . $path, ['Authorization: Bearer ' . zoomAccessToken()], $body);
    if ($r['code'] >= 400) {
        throw new RuntimeException('Zoom API ' . $r['code'] . ': ' . ($r['json']['message'] ?? $r['raw']));
    }
    return $r['json'];
}

/**
 * Buat meeting terjadwal.
 * type 2 = scheduled meeting. Waktu dikirim dalam format lokal + timezone
 * (bukan UTC) supaya Zoom menampilkan jam yang sama dengan yang diinput owner.
 */
function zoomCreateMeeting(array $m): array
{
    $host  = setting('zoom_host_email', 'me');       // 'me' = pemilik kredensial S2S
    $start = date('Y-m-d\TH:i:s', strtotime($m['start_at']));
    $dur   = max(15, (int) round((strtotime($m['end_at']) - strtotime($m['start_at'])) / 60));

    $body = [
        'topic'      => mb_substr($m['title'], 0, 200),
        'type'       => 2,
        'start_time' => $start,
        'duration'   => $dur,
        'timezone'   => $m['timezone'] ?: APP_TZ,
        'agenda'     => mb_substr(strip_tags($m['notes'] ?? ''), 0, 2000),
        'settings'   => [
            'join_before_host'  => false,
            'waiting_room'      => true,      // cegah Zoom-bombing
            'mute_upon_entry'   => true,
            'approval_type'     => 2,         // tanpa registrasi
            'audio'             => 'both',
            'auto_recording'    => 'none',
            'meeting_authentication' => false,
        ],
    ];

    return zoomCall('POST', '/users/' . rawurlencode($host) . '/meetings', $body);
}

function zoomUpdateMeeting(string $meetingId, array $m): void
{
    $dur = max(15, (int) round((strtotime($m['end_at']) - strtotime($m['start_at'])) / 60));
    zoomCall('PATCH', '/meetings/' . rawurlencode($meetingId), [
        'topic'      => mb_substr($m['title'], 0, 200),
        'start_time' => date('Y-m-d\TH:i:s', strtotime($m['start_at'])),
        'duration'   => $dur,
        'timezone'   => $m['timezone'] ?: APP_TZ,
    ]);
}

function zoomDeleteMeeting(string $meetingId): void
{
    try {
        zoomCall('DELETE', '/meetings/' . rawurlencode($meetingId) . '?schedule_for_reminder=true');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), '404')) throw $e;
    }
}

/**
 * Ambil rekaman cloud sebuah meeting Zoom.
 *
 * PENTING — batasannya:
 *  - Butuh scope tambahan `recording:read:admin` di aplikasi Server-to-Server.
 *  - Cloud recording hanya ada di paket Zoom BERBAYAR. Akun Basic (gratis)
 *    merekam ke komputer host, dan berkas itu tidak pernah menyentuh API.
 *  - Rekaman baru muncul beberapa menit sampai puluhan menit setelah meeting
 *    berakhir, tergantung durasi.
 *
 * Mengembalikan null bila belum/tidak ada, bukan melempar error — supaya
 * panel tidak rusak hanya karena akun Zoom-nya paket gratis.
 */
function zoomFetchRecording(string $meetingId): ?array
{
    try {
        $r = zoomCall('GET', '/meetings/' . rawurlencode($meetingId) . '/recordings');
    } catch (Throwable $e) {
        $m = $e->getMessage();
        if (str_contains($m, '404') || str_contains($m, '3301')) return null;   // belum ada rekaman
        throw $e;
    }

    $berkas = $r['recording_files'] ?? [];
    if (!$berkas) return null;

    $video = null; $transkrip = null;
    foreach ($berkas as $f) {
        $t = strtoupper($f['file_type'] ?? '');
        if ($t === 'MP4' && !$video)        $video = $f;
        if ($t === 'TRANSCRIPT' && !$transkrip) $transkrip = $f;
    }

    return [
        'share_url'  => $r['share_url'] ?? null,
        'play_url'   => $video['play_url'] ?? null,
        'duration'   => isset($r['duration']) ? (int) $r['duration'] : null,
        'start_time' => $r['start_time'] ?? null,
        'transkrip'  => $transkrip['download_url'] ?? null,
        'total'      => count($berkas),
    ];
}

/** Apakah scope rekaman sudah diberikan? Dipakai untuk pesan yang jelas di panel. */
function zoomBisaRekaman(): bool
{
    return zoomConfigured() && setting('zoom_recording_enabled') === '1';
}
