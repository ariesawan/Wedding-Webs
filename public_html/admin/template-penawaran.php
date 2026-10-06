<?php
/**
 * Template penawaran kini menjadi bagian dari "Paket & price list"
 * (admin/paket.php) — satu tabel yang sama, satu halaman penyunting.
 * Berkas ini hanya meneruskan tautan lama.
 */
require_once __DIR__ . '/_layout.php';
requireLogin();
redirect('admin/paket.php' . (!empty($_GET['id']) ? '?id=' . (int) $_GET['id'] : ''));
