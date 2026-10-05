<?php
/**
 * Pemrosesan gambar dengan GD (selalu ada di cPanel, tidak butuh Imagick/Composer).
 *
 * Alur upload foto galeri:
 *   original  -> foto/<slug>.jpg        (maks 1800px, kualitas 82)
 *   thumbnail -> foto/<slug>-thumb.jpg  (maks 640px,  kualitas 78)
 *   webp      -> foto/<slug>.webp + -thumb.webp (bila GD mendukung)
 *
 * Konvensi <slug>.jpg / <slug>-thumb.jpg SENGAJA dipertahankan agar galeri
 * bola 3D yang sudah ada tetap jalan tanpa perlu diubah logikanya.
 */

function imgAssertGd(): void
{
    if (!function_exists('imagecreatetruecolor')) {
        throw new RuntimeException('Ekstensi GD belum aktif. Aktifkan lewat cPanel > Select PHP Version > Extensions > gd.');
    }
}

/** Validasi berkas upload: cek MIME asli, bukan sekadar ekstensi. */
function validateUpload(array $file, array $allowedMime, int $maxSize): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return [false, 'Parameter upload tidak valid.'];
    }
    switch ($file['error']) {
        case UPLOAD_ERR_OK: break;
        case UPLOAD_ERR_NO_FILE: return [false, 'Tidak ada berkas yang dipilih.'];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return [false, 'Berkas melebihi batas upload server (upload_max_filesize).'];
        default: return [false, 'Upload gagal, kode error ' . $file['error'] . '.'];
    }
    if ($file['size'] > $maxSize) {
        return [false, 'Ukuran berkas ' . round($file['size'] / 1048576, 1) . ' MB, melebihi batas ' . round($maxSize / 1048576) . ' MB.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedMime, true)) {
        return [false, 'Tipe berkas ' . $mime . ' tidak diizinkan.'];
    }
    return [true, $mime];
}

/** Buat GD resource dari file, sekaligus koreksi orientasi EXIF (foto HP sering miring). */
function imgLoad(string $path, string $mime)
{
    imgAssertGd();
    $im = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        'image/gif'  => @imagecreatefromgif($path),
        default      => false,
    };
    if (!$im) throw new RuntimeException('Gambar tidak bisa dibaca / format tidak didukung.');

    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $o    = $exif['Orientation'] ?? 1;
        if ($o === 3) $im = imagerotate($im, 180, 0);
        elseif ($o === 6) $im = imagerotate($im, -90, 0);
        elseif ($o === 8) $im = imagerotate($im, 90, 0);
    }
    return $im;
}

/** Resize proporsional dengan sisi terpanjang = $max. Tidak memperbesar gambar kecil. */
function imgResize($src, int $max)
{
    $w = imagesx($src); $h = imagesy($src);
    if (max($w, $h) <= $max) return $src;
    $ratio = $max / max($w, $h);
    $nw = max(1, (int) round($w * $ratio));
    $nh = max(1, (int) round($h * $ratio));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // latar putih untuk PNG transparan -> JPG
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $dst;
}

/**
 * Simpan foto galeri. Mengembalikan array metadata untuk disimpan ke DB.
 * $dir  : direktori tujuan absolut
 * $slug : nama berkas tanpa ekstensi
 */
function saveGalleryImage(array $file, string $dir, string $slug, int $maxFull = 1800, int $maxThumb = 640): array
{
    [$ok, $mime] = validateUpload($file, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], MAX_IMAGE_SIZE);
    if (!$ok) throw new RuntimeException($mime);

    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Direktori tujuan tidak bisa dibuat: ' . $dir);
    }
    if (!is_writable($dir)) throw new RuntimeException('Direktori tidak writable: ' . $dir . ' (chmod 755, owner harus user cPanel).');

    // Naikkan memory sementara: foto 24MP butuh ~100 MB saat di-decode GD.
    $old = ini_get('memory_limit');
    @ini_set('memory_limit', '512M');

    try {
        $src   = imgLoad($file['tmp_name'], $mime);
        $full  = imgResize($src, $maxFull);
        $thumb = imgResize($src, $maxThumb);

        $pFull  = "$dir/$slug.jpg";
        $pThumb = "$dir/$slug-thumb.jpg";
        imagejpeg($full,  $pFull,  82);
        imagejpeg($thumb, $pThumb, 78);

        // WebP: ~30% lebih ringan, bagus untuk Core Web Vitals (skor SEO).
        $hasWebp = function_exists('imagewebp');
        if ($hasWebp) {
            imagewebp($full,  "$dir/$slug.webp", 82);
            imagewebp($thumb, "$dir/$slug-thumb.webp", 78);
        }

        $meta = [
            'width'  => imagesx($full),
            'height' => imagesy($full),
            'webp'   => $hasWebp ? 1 : 0,
            'bytes'  => filesize($pFull) ?: 0,
        ];

        foreach ([$src, $full, $thumb] as $r) { if ($r instanceof GdImage) imagedestroy($r); }
        return $meta;
    } finally {
        @ini_set('memory_limit', $old);
    }
}

/** Simpan satu gambar biasa (cover blog / poster event) -> <slug>.jpg + <slug>-thumb.jpg */
function saveCoverImage(array $file, string $dir, string $slug): array
{
    return saveGalleryImage($file, $dir, $slug, 1600, 600);
}

/** Simpan video mentah (tanpa transcode — hosting shared tidak punya ffmpeg). */
function saveVideo(array $file, string $dir, string $slug): string
{
    [$ok, $mime] = validateUpload($file, ['video/mp4', 'video/quicktime', 'video/webm'], MAX_VIDEO_SIZE);
    if (!$ok) throw new RuntimeException($mime);

    $ext  = match ($mime) { 'video/mp4' => 'mp4', 'video/webm' => 'webm', default => 'mp4' };
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Direktori video tidak bisa dibuat.');
    $dest = "$dir/$slug.$ext";
    if (!move_uploaded_file($file['tmp_name'], $dest)) throw new RuntimeException('Gagal memindahkan berkas video.');
    @chmod($dest, 0644);
    return basename($dest);
}

/** Hapus semua turunan berkas sebuah slug. */
function deleteImageSet(string $dir, string $slug): void
{
    foreach (['.jpg', '-thumb.jpg', '.webp', '-thumb.webp'] as $suf) {
        $p = "$dir/$slug$suf";
        if (is_file($p)) @unlink($p);
    }
}
