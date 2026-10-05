<?php
/** Autentikasi admin: bcrypt + throttle per-IP + regenerate session id. */

function currentUser(): ?array
{
    static $u = null;
    if ($u !== null) return $u ?: null;
    if (empty($_SESSION['uid'])) return null;
    $u = one("SELECT id, name, email, role FROM users WHERE id = ? AND is_active = 1", [$_SESSION['uid']]) ?: false;
    return $u ?: null;
}

/**
 * ============================================================
 * PERAN PENGGUNA
 * ============================================================
 *
 * owner        — pemilik. Semua halaman, termasuk yang menyimpan kredensial.
 * admin_early  — admin depan. Menangani prospek sampai penawaran disetujui:
 *                catat klien baru, kirim price list, susun penawaran, deal.
 * admin_office — admin lanjut. Mengambil alih setelah deal: data lengkap,
 *                vendor, event, jadwal produksi.
 *
 * Yang TIDAK dipisah: halaman klien. Dua-duanya butuh membukanya — early
 * untuk menyusun penawaran, office untuk melanjutkan datanya. Yang membedakan
 * bukan aksesnya, tapi panel mana yang relevan, dan itu sudah ditandai
 * lewat clients.pic_role di kartu klien.
 */
const ROLE_LABEL = [
    'owner'        => 'Owner',
    'admin_early'  => 'Admin early',
    'admin_office' => 'Admin office',
    'editor'       => 'Editor (lama)',
];

/**
 * Halaman yang boleh dibuka tiap peran, dikunci pada NAMA BERKAS.
 *
 * Dikunci di nama berkas, bukan di kunci menu, karena penjagaan harus ikut
 * berlaku untuk POST. Di klien.php seluruh penanganan POST berjalan sebelum
 * adminHead() dipanggil — kalau penjagaannya ditaruh di sana, menu memang
 * hilang dari rail tapi kiriman POST langsung ke URL-nya tetap tembus.
 * requireLogin() berjalan di baris pertama, sebelum apa pun.
 */
const AKSES_PERAN = [
    'admin_early' => [
        'index', 'klien', 'chat', 'chat-api', 'wa-sesi', 'jadwal', 'cek-bentrok',
        'inbox', 'penyusun', 'analisa', 'alert-api', 'oauth-callback', 'logout',
        'penawaran', 'template-penawaran',
    ],
    'admin_office' => [
        'index', 'klien', 'chat', 'chat-api', 'wa-sesi', 'jadwal', 'cek-bentrok',
        'inbox', 'penyusun', 'analisa', 'alert-api', 'oauth-callback', 'logout',
        'event', 'vendor', 'vendor-kategori', 'galeri',
        // Office membuka penawaran untuk MEMBACA riwayat tawar-menawar —
        // penjagaan sunting ada di dalam halamannya, bukan di sini.
        'penawaran',
    ],
];

function userRole(): string
{
    return currentUser()['role'] ?? '';
}

function isOwner(): bool
{
    return userRole() === 'owner';
}

function roleLabel(string $r): string
{
    return ROLE_LABEL[$r] ?? $r;
}

/** Apakah peran ini boleh membuka berkas bernama $halaman (tanpa .php)? */
function bolehAkses(string $role, string $halaman): bool
{
    if ($role === 'owner') return true;
    // 'editor' warisan sebelum v18 disamakan dengan admin_office.
    if ($role === 'editor') $role = 'admin_office';
    return in_array($halaman, AKSES_PERAN[$role] ?? [], true);
}

/** Halaman khusus owner. Dipanggil eksplisit bila perlu penjagaan tambahan. */
function requireOwner(): array
{
    $u = requireLogin();
    if (($u['role'] ?? '') !== 'owner') {
        http_response_code(403);
        die('Halaman ini hanya untuk owner.');
    }
    return $u;
}

/** Panggil di baris pertama setiap halaman admin. */
function requireLogin(): array
{
    $u = currentUser();
    if (!$u) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/admin/';
        redirect('admin/login.php');
    }
    // Timeout idle 8 jam
    if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > 8 * 3600) {
        logoutUser();
        redirect('admin/login.php?timeout=1');
    }
    $_SESSION['last_seen'] = time();

    // Penjagaan peran. Berjalan sebelum penanganan POST halaman mana pun.
    $halaman = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
    if ($halaman !== '' && !bolehAkses($u['role'] ?? '', $halaman)) {
        logAudit((int) $u['id'], 'akses_ditolak', $halaman,
                 'Peran: ' . ($u['role'] ?? '-'));
        http_response_code(403);
        die('Halaman ini di luar wewenang ' . e(roleLabel($u['role'] ?? '')) . '. '
          . '<a href="index.php" style="color:#E9A85C">Kembali ke ringkasan</a>');
    }

    return $u;
}

function isLocked(string $ip): bool
{
    $r = one("SELECT tries, locked_until FROM login_attempts WHERE ip = ?", [$ip]);
    return $r && $r['locked_until'] && strtotime($r['locked_until']) > time();
}

function lockRemaining(string $ip): int
{
    $r = one("SELECT locked_until FROM login_attempts WHERE ip = ?", [$ip]);
    if (!$r || !$r['locked_until']) return 0;
    return max(0, (int) ceil((strtotime($r['locked_until']) - time()) / 60));
}

function noteFailedLogin(string $ip): void
{
    q("INSERT INTO login_attempts (ip, tries, last_try) VALUES (?, 1, NOW())
       ON DUPLICATE KEY UPDATE tries = tries + 1, last_try = NOW()", [$ip]);
    $r = one("SELECT tries FROM login_attempts WHERE ip = ?", [$ip]);
    if ($r && (int)$r['tries'] >= LOGIN_MAX_TRY) {
        q("UPDATE login_attempts SET locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE), tries = 0 WHERE ip = ?",
          [LOGIN_LOCK_MINUTES, $ip]);
    }
}

function clearLoginAttempts(string $ip): void
{
    q("DELETE FROM login_attempts WHERE ip = ?", [$ip]);
}

function attemptLogin(string $email, string $password): bool
{
    $ip = clientIp();
    $u  = one("SELECT id, password_hash FROM users WHERE email = ? AND is_active = 1", [$email]);

    // password_verify tetap dijalankan pada hash dummy walau user tidak ada,
    // supaya waktu respons seragam (tidak bocor "email ini terdaftar/tidak").
    $hash = $u['password_hash'] ?? '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30M1MlGwjueW';
    $ok   = password_verify($password, $hash);

    if (!$u || !$ok) { noteFailedLogin($ip); return false; }

    if (password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12])) {
        q("UPDATE users SET password_hash = ? WHERE id = ?",
          [password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $u['id']]);
    }

    session_regenerate_id(true);   // cegah session fixation
    $_SESSION['uid']       = (int) $u['id'];
    $_SESSION['last_seen'] = time();
    q("UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?", [$ip, $u['id']]);
    clearLoginAttempts($ip);
    return true;
}

function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ============================================================
   RESET KATA SANDI
   Token acak 32 byte dikirim lewat email, tetapi yang disimpan di database
   hanya SHA-256-nya. Jadi kalau tabel bocor, isinya tidak bisa dipakai
   mengambil alih akun — sama prinsipnya dengan menyimpan hash kata sandi.
   ============================================================ */

const RESET_TTL_MINUTES = 60;

/**
 * Membuat token reset. Mengembalikan token mentah untuk ditempel ke URL,
 * atau null bila email tidak terdaftar.
 *
 * Pemanggil TIDAK BOLEH membocorkan hasil null ke pengguna — pesan di layar
 * harus sama persis untuk email terdaftar maupun tidak, supaya halaman ini
 * tidak bisa dipakai menebak email mana yang ada di sistem.
 */
function createPasswordReset(string $email): ?string
{
    $u = one("SELECT id FROM users WHERE email = ? AND is_active = 1", [$email]);
    if (!$u) return null;

    // Token lama yang belum terpakai dibatalkan — hanya satu tautan yang berlaku.
    q("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL", [$u['id']]);

    $token = bin2hex(random_bytes(32));
    q("INSERT INTO password_resets (user_id, token_hash, expires_at, ip)
       VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)",
       [$u['id'], hash('sha256', $token), RESET_TTL_MINUTES, clientIp()]);

    return $token;
}

/** Ambil baris reset yang masih sah, atau null. */
function findValidReset(string $token): ?array
{
    if ($token === '' || !ctype_xdigit($token)) return null;
    return one("SELECT r.*, u.email, u.name FROM password_resets r
                JOIN users u ON u.id = r.user_id
                WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()",
               [hash('sha256', $token)]);
}

/** Pakai token: ganti kata sandi, tandai terpakai, buka kunci login. */
function consumePasswordReset(string $token, string $newPassword): bool
{
    $r = findValidReset($token);
    if (!$r) return false;

    db()->beginTransaction();
    try {
        q("UPDATE users SET password_hash = ? WHERE id = ?",
          [password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]), $r['user_id']]);
        q("UPDATE password_resets SET used_at = NOW() WHERE id = ?", [$r['id']]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    // Kunci login akibat percobaan gagal ikut dilepas — pemilik akun sudah
    // membuktikan kepemilikan lewat email, tidak perlu menunggu masa kunci habis.
    clearLoginAttempts(clientIp());
    logAudit($r['user_id'], 'password_reset', 'users#' . $r['user_id'], 'Lewat tautan email');
    return true;
}

/** Batasi permintaan reset: maksimal 3 per email per jam. */
function resetRequestThrottled(string $email): bool
{
    $r = one("SELECT COUNT(*) c FROM password_resets pr
              JOIN users u ON u.id = pr.user_id
              WHERE u.email = ? AND pr.created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)", [$email]);
    return (int) ($r['c'] ?? 0) >= 3;
}

/** Catat tindakan penting ke audit_log. */
function logAudit(?int $userId, string $action, string $target = '', string $detail = ''): void
{
    try {
        q("INSERT INTO audit_log (user_id, action, target, detail, ip) VALUES (?, ?, ?, ?, ?)",
          [$userId, $action, mb_substr($target, 0, 120), mb_substr($detail, 0, 500), clientIp()]);
    } catch (Throwable $e) {
        error_log('audit gagal: ' . $e->getMessage());
    }
}
