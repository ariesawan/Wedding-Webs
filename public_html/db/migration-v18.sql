-- =====================================================================
-- Callalily Party CMS — migration-v18.sql
-- Jalankan SETELAH migration-v17.sql.
--
-- Isi: DUA AKUN ADMIN dengan peran berbeda.
--
-- Sampai v17 kolom users.role sudah ada (ENUM 'owner','editor') tapi tidak
-- pernah dibaca satu baris pun di seluruh kode — tidak ada requireOwner(),
-- tidak ada pengecekan di halaman mana pun. Siapa pun yang bisa masuk punya
-- akses penuh, termasuk hapus klien dan membuka integrasi.php yang menyimpan
-- kredensial OAuth Google dan Zoom.
--
-- Jangan campur aduk dengan clients.pic_role. Keduanya beda benda:
--   users.role     -> siapa ORANGNYA, menentukan apa yang boleh dibuka
--   clients.pic_role -> KLIEN ini sedang dipegang tahap yang mana
-- Satu melekat di akun, satu melekat di klien.
--
-- Backup dulu:
--   mysqldump -u USER -p NAMADB > backup-pre-v18-$(date +%F).sql
-- =====================================================================

SET NAMES utf8mb4;
START TRANSACTION;


-- ---------------------------------------------------------------------
-- 1. PERAN BARU
-- ---------------------------------------------------------------------
-- 'editor' dipertahankan supaya akun lama tidak jadi nilai ilegal saat ENUM
-- dipersempit — MySQL akan mengubahnya jadi string kosong tanpa memberi tahu,
-- dan akun itu langsung kehilangan seluruh akses tanpa jejak.

ALTER TABLE `users`
  MODIFY COLUMN `role`
    ENUM('owner','admin_early','admin_office','editor')
    NOT NULL DEFAULT 'admin_early'
    COMMENT 'owner=penuh; admin_early=prospek s/d deal; admin_office=setelah deal';

-- Akun 'editor' lama disamakan dengan admin_office: dia yang paling luas
-- aksesnya di antara dua peran admin, jadi tidak ada yang tiba-tiba kehilangan
-- halaman yang kemarin masih bisa dibuka.
UPDATE `users` SET `role` = 'admin_office' WHERE `role` = 'editor';


-- ---------------------------------------------------------------------
-- 2. PENGAMAN: OWNER TIDAK BOLEH HABIS
-- ---------------------------------------------------------------------
-- Kalau sampai nol owner, tidak ada lagi yang bisa membuat akun — dan
-- setup.php sudah menolak jalan selama tabel users tidak kosong. Jalan
-- keluarnya cuma lewat SQL manual. Penjagaannya ada di admin/pengguna.php,
-- tapi query ini dipakai untuk memastikan kondisi awalnya sudah benar.

-- SELECT COUNT(*) FROM users WHERE role = 'owner' AND is_active = 1;
-- Harus >= 1 sebelum lanjut.

COMMIT;


-- =====================================================================
-- MEMBUAT AKUN ADMIN PERTAMA
-- =====================================================================
-- Setelah migrasi ini, masuk sebagai owner lalu buka menu "Pengguna" yang
-- baru muncul di rail. Bikin akunnya dari sana — hash-nya dibuat PHP dengan
-- cost yang benar.
--
-- Kalau terpaksa lewat SQL, hash harus dihasilkan PHP, bukan MD5/SHA MySQL:
--   php -r 'echo password_hash("SandiPanjang123!", PASSWORD_BCRYPT, ["cost"=>12]);'
--
-- INSERT INTO users (name, email, password_hash, role) VALUES
--   ('Admin Early',  'early@callalily.party',  '$2y$12$...', 'admin_early'),
--   ('Admin Office', 'office@callalily.party', '$2y$12$...', 'admin_office');


-- =====================================================================
-- VERIFIKASI
-- =====================================================================
-- SELECT id, name, email, role, is_active FROM users ORDER BY role, id;
