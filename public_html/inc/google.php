<?php
require_once __DIR__ . '/http.php';

/**
 * ============================================================
 * GOOGLE CALENDAR — OAuth 2.0 (Authorization Code + refresh token)
 * ============================================================
 *
 * KENAPA OAuth, BUKAN SERVICE ACCOUNT?
 * Service account tidak bisa mengundang tamu (attendee) pada akun Gmail biasa —
 * itu hanya jalan di Google Workspace dengan Domain-Wide Delegation. Selain itu
 * event yang dibuat service account masuk ke kalender milik service account,
 * bukan kalender owner. Karena owner WO umumnya pakai Gmail biasa dan ingin
 * event muncul di kalender pribadinya + klien menerima undangan email, maka
 * alur yang benar adalah: owner login sekali (consent), kita simpan refresh_token,
 * lalu server menukarnya jadi access_token setiap kali dibutuhkan.
 *
 * Scope yang diminta: https://www.googleapis.com/auth/calendar.events
 * (cukup untuk buat/ubah/hapus event; TIDAK bisa baca kalender lain — least privilege)
 */

const G_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
const G_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const G_API       = 'https://www.googleapis.com/calendar/v3';
const G_SCOPE_CAL   = 'https://www.googleapis.com/auth/calendar.events';
const G_SCOPE_SHEET = 'https://www.googleapis.com/auth/spreadsheets';
const G_SHEETS_API  = 'https://sheets.googleapis.com/v4/spreadsheets';
const G_DRIVE_API   = 'https://www.googleapis.com/drive/v3';
const G_SCOPE_DRIVE = 'https://www.googleapis.com/auth/drive.file';

/**
 * Scope yang diminta. Sheets & Drive hanya diminta bila fitur spreadsheet
 * dinyalakan — prinsip izin sekecil mungkin. drive.file memberi akses HANYA
 * ke berkas yang dibuat aplikasi ini, bukan seluruh Google Drive owner.
 */
function googleScopes(): string
{
    $s = [G_SCOPE_CAL];
    if (setting('sheet_enabled') === '1') { $s[] = G_SCOPE_SHEET; $s[] = G_SCOPE_DRIVE; }
    // Search Console dibaca lewat OAuth yang sama. Menambah scope di sini
    // berarti izin lama harus diberikan ULANG — Google tidak menambahkan
    // scope baru ke token yang sudah ada. Karena itu tombol "Hubungkan"
    // perlu ditekan sekali lagi setelah pembaruan ini.
    if (setting('gsc_enabled') === '1') $s[] = 'https://www.googleapis.com/auth/webmasters.readonly';
    return implode(' ', $s);
}

/** Scope yang benar-benar sudah diberikan owner (disimpan saat consent). */
function googleGrantedScopes(): array
{
    return array_filter(explode(' ', (string) setting('google_scopes', '')));
}

function googleHasScope(string $scope): bool
{
    return in_array($scope, googleGrantedScopes(), true);
}

function googleConfigured(): bool
{
    return setting('google_client_id') && setting('google_client_secret');
}

function googleConnected(): bool
{
    return googleConfigured() && (bool) setting('google_refresh_token');
}

/** URL untuk tombol "Hubungkan Google Calendar". */
function googleAuthUrl(string $state): string
{
    return G_AUTH_URL . '?' . http_build_query([
        'client_id'              => setting('google_client_id'),
        'redirect_uri'           => GOOGLE_REDIRECT_URI,
        'response_type'          => 'code',
        'scope'                  => googleScopes(),
        'access_type'            => 'offline',   // wajib, supaya dapat refresh_token
        'prompt'                 => 'consent',   // paksa consent agar refresh_token selalu dikirim ulang
        'include_granted_scopes' => 'true',
        'state'                  => $state,
    ]);
}

/** Tukar authorization code -> refresh token (dipanggil sekali dari oauth-callback.php). */
function googleExchangeCode(string $code): void
{
    $r = httpForm(G_TOKEN_URL, [
        'code'          => $code,
        'client_id'     => setting('google_client_id'),
        'client_secret' => setting('google_client_secret'),
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]);
    if ($r['code'] !== 200 || empty($r['json']['access_token'])) {
        throw new RuntimeException('Google menolak kode otorisasi: ' . ($r['json']['error_description'] ?? $r['raw']));
    }
    if (empty($r['json']['refresh_token'])) {
        throw new RuntimeException('Google tidak mengirim refresh_token. Cabut akses aplikasi di myaccount.google.com/permissions lalu hubungkan ulang.');
    }
    settingSet('google_scopes', $r['json']['scope'] ?? googleScopes());
    settingSet('google_refresh_token', $r['json']['refresh_token'], true);
    settingSet('google_access_token',  $r['json']['access_token'],  true);
    settingSet('google_token_expires', (string) (time() + (int) $r['json']['expires_in'] - 60));
}

/** Access token yang selalu valid; di-refresh otomatis saat kedaluwarsa. */
function googleAccessToken(): string
{
    $tok = setting('google_access_token');
    $exp = (int) setting('google_token_expires', '0');
    if ($tok && $exp > time()) return $tok;

    $refresh = setting('google_refresh_token');
    if (!$refresh) throw new RuntimeException('Google Calendar belum terhubung. Buka Admin > Integrasi.');

    $r = httpForm(G_TOKEN_URL, [
        'refresh_token' => $refresh,
        'client_id'     => setting('google_client_id'),
        'client_secret' => setting('google_client_secret'),
        'grant_type'    => 'refresh_token',
    ]);
    if ($r['code'] !== 200 || empty($r['json']['access_token'])) {
        // invalid_grant = refresh token dicabut/kedaluwarsa -> owner harus hubungkan ulang
        if (($r['json']['error'] ?? '') === 'invalid_grant') {
            settingSet('google_refresh_token', null, true);
            throw new RuntimeException('Izin Google sudah dicabut atau kedaluwarsa. Hubungkan ulang di Admin > Integrasi.');
        }
        throw new RuntimeException('Gagal memperbarui token Google: ' . ($r['json']['error_description'] ?? $r['raw']));
    }
    settingSet('google_access_token',  $r['json']['access_token'], true);
    settingSet('google_token_expires', (string) (time() + (int) $r['json']['expires_in'] - 60));
    if (!empty($r['json']['scope'])) settingSet('google_scopes', $r['json']['scope']);
    return $r['json']['access_token'];
}

function gcalCall(string $method, string $path, ?array $body = null, array $query = []): array
{
    $url = G_API . $path . ($query ? '?' . http_build_query($query) : '');
    $r   = httpJson($method, $url, ['Authorization: Bearer ' . googleAccessToken()], $body);
    if ($r['code'] >= 400) {
        $msg = $r['json']['error']['message'] ?? $r['raw'];
        throw new RuntimeException('Google Calendar API ' . $r['code'] . ': ' . $msg);
    }
    return $r['json'];
}

/**
 * Buat event + undang klien lewat email.
 *
 * $m: baris tabel meetings.
 * sendUpdates=all  -> Google yang mengirim email undangan ke attendee.
 * conferenceDataVersion=1 -> wajib bila kita minta Google Meet dibuatkan.
 */
function gcalCreateEvent(array $m): array
{
    $calId    = setting('google_calendar_id', 'primary');
    $tz       = $m['timezone'] ?: APP_TZ;
    $attendees = [];

    foreach (meetingRecipients($m) as $mail) {
        $attendees[] = ['email' => $mail, 'responseStatus' => 'needsAction'];
    }

    $desc = trim(($m['notes'] ?? '') . "\n\n");
    if (!empty($m['zoom_join_url'])) {
        $desc .= "Tautan Zoom: " . $m['zoom_join_url'] . "\n";
        if (!empty($m['zoom_passcode'])) $desc .= "Passcode: " . $m['zoom_passcode'] . "\n";
    }
    $desc .= "\n— " . setting('site_name', 'Callalily Party') . "\n" . url();

    $body = [
        'summary'     => $m['title'],
        'description' => trim($desc),
        'start'       => ['dateTime' => date('c', strtotime($m['start_at'])), 'timeZone' => $tz],
        'end'         => ['dateTime' => date('c', strtotime($m['end_at'])),   'timeZone' => $tz],
        'attendees'   => $attendees,
        'guestsCanModify'      => false,
        'guestsCanInviteOthers'=> false,
        'reminders'   => ['useDefault' => false, 'overrides' => [
            ['method' => 'email', 'minutes' => 24 * 60],
            ['method' => 'popup', 'minutes' => 60],
        ]],
    ];

    if ($m['mode'] === 'onsite') {
        $body['location'] = $m['location_text'] ?: setting('address_street', '');
    } elseif ($m['mode'] === 'zoom' && !empty($m['zoom_join_url'])) {
        $body['location'] = $m['zoom_join_url'];
    } elseif ($m['mode'] === 'meet') {
        // Minta Google membuatkan tautan Google Meet
        $body['conferenceData'] = [
            'createRequest' => [
                'requestId'             => 'calla-' . bin2hex(random_bytes(8)),
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ],
        ];
    }

    $q = ['sendUpdates' => 'all'];
    if ($m['mode'] === 'meet') $q['conferenceDataVersion'] = 1;

    return gcalCall('POST', "/calendars/" . rawurlencode($calId) . "/events", $body, $q);
}

function gcalUpdateEvent(array $m): array
{
    if (empty($m['gcal_event_id'])) return gcalCreateEvent($m);
    $calId = setting('google_calendar_id', 'primary');
    $tz    = $m['timezone'] ?: APP_TZ;

    $body = [
        'summary'   => $m['title'],
        'start'     => ['dateTime' => date('c', strtotime($m['start_at'])), 'timeZone' => $tz],
        'end'       => ['dateTime' => date('c', strtotime($m['end_at'])),   'timeZone' => $tz],
        'attendees' => array_map(fn($mail) => ['email' => $mail], meetingRecipients($m)),
        'description' => trim(($m['notes'] ?? '') . (!empty($m['zoom_join_url']) ? "\n\nTautan Zoom: " . $m['zoom_join_url'] : '')),
    ];
    if ($m['mode'] === 'onsite') $body['location'] = $m['location_text'];
    if ($m['mode'] === 'zoom' && !empty($m['zoom_join_url'])) $body['location'] = $m['zoom_join_url'];

    return gcalCall('PATCH', "/calendars/" . rawurlencode($calId) . "/events/" . rawurlencode($m['gcal_event_id']),
                    $body, ['sendUpdates' => 'all']);
}

function gcalDeleteEvent(string $eventId): void
{
    $calId = setting('google_calendar_id', 'primary');
    try {
        gcalCall('DELETE', "/calendars/" . rawurlencode($calId) . "/events/" . rawurlencode($eventId),
                 null, ['sendUpdates' => 'all']);
    } catch (RuntimeException $e) {
        // 404/410 = sudah terhapus di sisi Google; tidak perlu dianggap error.
        if (!str_contains($e->getMessage(), '404') && !str_contains($e->getMessage(), '410')) throw $e;
    }
}

/** Daftar email penerima undangan: klien + email tambahan, tervalidasi & unik. */
function meetingRecipients(array $m): array
{
    $list = array_merge([$m['client_email'] ?? ''], preg_split('/[,;\s]+/', $m['extra_emails'] ?? '') ?: []);
    $out  = [];
    foreach ($list as $mail) {
        $mail = trim($mail);
        if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)) $out[strtolower($mail)] = $mail;
    }
    return array_values($out);
}
