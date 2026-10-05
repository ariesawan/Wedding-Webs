-- =====================================================================
-- Callalily Party CMS — migration-v17b.sql  (PERBAIKAN)
-- =====================================================================
--
-- Untuk keadaan: migration-v17.sql berhenti di tengah dengan
--   #1060 - Duplicate column name 'pria_anak_ke'
-- karena sebagian sudah pernah jalan di percobaan sebelumnya.
--
-- Berkas ini AMAN DIJALANKAN BERULANG. Tiap perubahan diperiksa dulu ke
-- information_schema, baru dijalankan kalau memang belum ada. Yang sudah
-- terpasang dilewati diam-diam.
--
-- Kenapa v17 asli tidak begini sejak awal: MySQL tidak punya
-- "ADD COLUMN IF NOT EXISTS" (MariaDB punya, MySQL tidak), jadi satu-satunya
-- cara yang jalan di dua-duanya adalah memeriksa information_schema lalu
-- menyusun pernyataannya lewat prepared statement. Lebih berisik dibaca,
-- tapi tidak meledak saat diulang.
--
-- Catatan penting yang saya salah sebut sebelumnya: START TRANSACTION tidak
-- melindungi DDL. CREATE TABLE dan ALTER TABLE memicu implicit commit di
-- MySQL, jadi migrasi yang gagal di tengah TETAP meninggalkan perubahan yang
-- sudah sempat jalan. Backup tetap wajib.
--
--   mysqldump -u USER -p NAMADB > backup-pre-v17b-$(date +%F).sql
-- =====================================================================

SET NAMES utf8mb4;

-- Prosedur bantu: tambah kolom hanya bila belum ada.
DROP PROCEDURE IF EXISTS `tambah_kolom_bila_perlu`;
DELIMITER $$
CREATE PROCEDURE `tambah_kolom_bila_perlu`(
    IN p_tabel  VARCHAR(64),
    IN p_kolom  VARCHAR(64),
    IN p_definisi TEXT
)
BEGIN
    DECLARE ada INT DEFAULT 0;
    SELECT COUNT(*) INTO ada
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = p_tabel
       AND COLUMN_NAME  = p_kolom;

    IF ada = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_tabel, '` ADD COLUMN `', p_kolom, '` ', p_definisi);
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
        SELECT CONCAT('DITAMBAH  : ', p_tabel, '.', p_kolom) AS hasil;
    ELSE
        SELECT CONCAT('sudah ada : ', p_tabel, '.', p_kolom) AS hasil;
    END IF;
END$$
DELIMITER ;


-- ---------------------------------------------------------------------
-- 1. TABEL ORANG TUA
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `client_family` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `pihak`     ENUM('pria','wanita') NOT NULL,
  `peran`     ENUM('ayah','ibu','wali') NOT NULL,
  `nama`          VARCHAR(120) NOT NULL DEFAULT '',
  `nama_undangan` VARCHAR(190) NOT NULL DEFAULT '',
  `status`   ENUM('hidup','almarhum') NOT NULL DEFAULT 'hidup',
  `telepon`  VARCHAR(30)  NOT NULL DEFAULT '',
  `catatan`  VARCHAR(255) NOT NULL DEFAULT '',
  `urutan`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_family` (`client_id`,`pihak`,`peran`),
  CONSTRAINT `fk_family_client` FOREIGN KEY (`client_id`)
    REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 2. IDENTITAS MEMPELAI UNTUK UNDANGAN
-- ---------------------------------------------------------------------
CALL tambah_kolom_bila_perlu('client_wedding_info', 'pria_anak_ke',   'TINYINT UNSIGNED NULL');
CALL tambah_kolom_bila_perlu('client_wedding_info', 'pria_dari',      'TINYINT UNSIGNED NULL COMMENT ''dari berapa bersaudara''');
CALL tambah_kolom_bila_perlu('client_wedding_info', 'pria_alamat',    'VARCHAR(255) NOT NULL DEFAULT ''''');
CALL tambah_kolom_bila_perlu('client_wedding_info', 'wanita_anak_ke', 'TINYINT UNSIGNED NULL');
CALL tambah_kolom_bila_perlu('client_wedding_info', 'wanita_dari',    'TINYINT UNSIGNED NULL');
CALL tambah_kolom_bila_perlu('client_wedding_info', 'wanita_alamat',  'VARCHAR(255) NOT NULL DEFAULT ''''');


-- ---------------------------------------------------------------------
-- 3. PENANDA DATA LENGKAP
-- ---------------------------------------------------------------------
CALL tambah_kolom_bila_perlu('clients', 'data_lengkap_at', 'DATETIME NULL COMMENT ''Pertama kali panel Data lengkap disimpan''');
CALL tambah_kolom_bila_perlu('clients', 'data_lengkap_by', 'INT UNSIGNED NULL');

-- Indeks. Sama urusannya: tidak ada ADD KEY IF NOT EXISTS.
DROP PROCEDURE IF EXISTS `tambah_indeks_bila_perlu`;
DELIMITER $$
CREATE PROCEDURE `tambah_indeks_bila_perlu`()
BEGIN
    DECLARE ada INT DEFAULT 0;
    SELECT COUNT(*) INTO ada
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'clients'
       AND INDEX_NAME   = 'idx_clients_datalengkap';
    IF ada = 0 THEN
        ALTER TABLE `clients` ADD KEY `idx_clients_datalengkap` (`data_lengkap_at`, `stage`);
        SELECT 'DITAMBAH  : idx_clients_datalengkap' AS hasil;
    ELSE
        SELECT 'sudah ada : idx_clients_datalengkap' AS hasil;
    END IF;
END$$
DELIMITER ;

CALL tambah_indeks_bila_perlu();


-- Prosedur bantu dibuang lagi. Tidak ada gunanya menetap di basis data
-- produksi, dan pengguna DB shared hosting sering tidak punya hak CREATE
-- ROUTINE permanen — meninggalkannya bisa mengganggu ekspor berikutnya.
DROP PROCEDURE IF EXISTS `tambah_kolom_bila_perlu`;
DROP PROCEDURE IF EXISTS `tambah_indeks_bila_perlu`;


-- =====================================================================
-- VERIFIKASI — harus mengembalikan 8
-- =====================================================================
SELECT COUNT(*) AS kolom_v17_terpasang
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND ((TABLE_NAME = 'client_wedding_info'
         AND COLUMN_NAME IN ('pria_anak_ke','pria_dari','pria_alamat',
                             'wanita_anak_ke','wanita_dari','wanita_alamat'))
     OR (TABLE_NAME = 'clients'
         AND COLUMN_NAME IN ('data_lengkap_at','data_lengkap_by')));

SELECT COUNT(*) AS tabel_client_family
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_family';
