<?php
/**
 * Callback OAuth Google. State diverifikasi untuk mencegah CSRF pada alur
 * otorisasi — tanpa ini, penyerang bisa memaksa owner menautkan akun Google
 * milik penyerang ke panel.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/google.php';
requireLogin();

$state = $_GET['state'] ?? '';
$saved = $_SESSION['oauth_state'] ?? '';
unset($_SESSION['oauth_state']);

if (!$saved || !hash_equals($saved, $state)) {
    flash('Parameter state tidak cocok. Ulangi proses dari halaman Integrasi.', 'err');
    redirect('admin/integrasi.php');
}

if (!empty($_GET['error'])) {
    flash('Google menolak permintaan izin: ' . $_GET['error'], 'err');
    redirect('admin/integrasi.php');
}

try {
    googleExchangeCode($_GET['code'] ?? '');
    flash('Google Calendar terhubung. Jadwal baru akan otomatis masuk kalender dan mengundang klien.');
} catch (Throwable $e) {
    flash($e->getMessage(), 'err');
}
redirect('admin/integrasi.php');
