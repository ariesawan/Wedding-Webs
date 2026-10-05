<?php
/**
 * Settings key-value.
 * Nilai sensitif (client_secret, refresh_token) dienkripsi AES-256-GCM dengan APP_KEY,
 * supaya dump database saja tidak cukup untuk mengambil alih Google Calendar / Zoom owner.
 */

function &settingsCache(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (all("SELECT k, v, is_secret FROM settings") as $r) {
            $cache[$r['k']] = $r['is_secret'] ? decryptValue($r['v']) : $r['v'];
        }
    }
    return $cache;
}

function setting(string $key, ?string $default = null): ?string
{
    $c = &settingsCache();
    $v = $c[$key] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}

function settingSet(string $key, ?string $value, bool $secret = false): void
{
    $stored = ($secret && $value !== null && $value !== '') ? encryptValue($value) : $value;
    q("INSERT INTO settings (k, v, is_secret) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE v = VALUES(v), is_secret = VALUES(is_secret)",
       [$key, $stored, $secret ? 1 : 0]);

    $c = &settingsCache();
    $c[$key] = $value;   // cache ikut diperbarui, tidak perlu query ulang
}

function encryptValue(string $plain): string
{
    $key = hash('sha256', APP_KEY, true);
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) throw new RuntimeException('Enkripsi gagal.');
    return base64_encode($iv . $tag . $ct);
}

function decryptValue(?string $blob): ?string
{
    if ($blob === null || $blob === '') return null;
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 29) return null;
    $key = hash('sha256', APP_KEY, true);
    $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
                           substr($raw, 0, 12), substr($raw, 12, 16));
    return $out === false ? null : $out;
}
