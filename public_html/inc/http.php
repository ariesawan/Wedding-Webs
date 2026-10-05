<?php
/** Pembungkus cURL tipis — semua panggilan API lewat sini agar error tertangani seragam. */
function httpJson(string $method, string $url, array $headers = [], $body = null, int $timeout = 20): array
{
    $ch = curl_init($url);
    $h  = array_merge(['Accept: application/json'], $headers);

    if ($body !== null) {
        if (is_array($body) && ($headers['form'] ?? false) === false && !in_array('Content-Type: application/x-www-form-urlencoded', $h, true)) {
            $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $h[]  = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'CallalilyCMS/1.0 (+' . BASE_URL . ')',
    ]);

    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Koneksi ke ' . parse_url($url, PHP_URL_HOST) . ' gagal: ' . $err);
    }
    $json = json_decode($raw, true);
    return ['code' => $code, 'json' => is_array($json) ? $json : [], 'raw' => $raw];
}

/** POST form-encoded (dipakai untuk endpoint OAuth token). */
function httpForm(string $url, array $fields, array $headers = [], int $timeout = 20): array
{
    return httpJson('POST', $url,
        array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers),
        http_build_query($fields), $timeout);
}
