# Pengamanan di DirectAdmin

Catatan ini khusus untuk `callalily.party` di DirectAdmin. Urutannya dari yang
paling berdampak ke yang paling opsional — kalau waktunya terbatas, kerjakan
bagian 1 sampai 5 dulu.

Path akun DirectAdmin berbeda dari cPanel:

```
/home/USER/domains/callalily.party/public_html/     ← root situs
/home/USER/domains/callalily.party/logs/            ← log akses & error
```

---

## 1. HTTPS wajib menyala lebih dulu

Tanpa HTTPS, kata sandi admin dan cookie sesi lewat sebagai teks polos di
jaringan. Ini bukan soal peringkat SEO — ini soal akun panel bisa diambil orang
yang satu WiFi dengan Anda.

**DirectAdmin → SSL Certificates → Free & automatic certificate from Let's Encrypt.**
Centang `www` dan domain utama, lalu Save.

Setelah sertifikat terbit, buka `.htaccess` dan **hapus tanda pagar** pada blok
force-HTTPS. Jangan diaktifkan sebelum sertifikat jadi — situs akan jadi loop
redirect.

Setelah yakin semua halaman jalan di HTTPS, aktifkan juga HSTS di `.htaccess`:

```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

Aktifkan **setelah** yakin, bukan sebelum. HSTS mengunci peramban ke HTTPS
selama setahun; kalau sertifikat bermasalah, situs tidak bisa diakses sama sekali.

---

## 2. Berkas yang harus dihapus dari server

```bash
cd ~/domains/callalily.party/public_html
rm -f admin/setup.php cek.php
ls -la db/                 # pastikan schema.sql tidak bisa diakses publik
```

`cek.php` menampilkan nama database, versi PHP, dan daftar berkas — berguna saat
memasang, berbahaya kalau ditinggal.

Uji cepat dari peramban, ketiganya harus **403 atau 404**:

```
https://callalily.party/inc/config.php
https://callalily.party/db/schema.sql
https://callalily.party/cron/harian.php
```

Kalau `inc/config.php` malah menampilkan kode atau halaman kosong 200, berarti
`.htaccess` di dalam `inc/` tidak terbaca — lihat bagian 4.

---

## 3. Izin berkas

```bash
cd ~/domains/callalily.party/public_html
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod 600 inc/config.php          # hanya pemilik yang boleh membaca
chmod 755 foto video uploads uploads/blog uploads/event
```

**Jangan pakai 777.** Di DirectAdmin, PHP berjalan sebagai user akun Anda
(mod_ruid2 / php-fpm per-user), jadi 755 sudah cukup untuk menulis. 777 justru
membuat berkas bisa ditulis proses lain di server yang sama.

`config.php` diberi 600 karena isinya kata sandi database dan `APP_KEY` —
kunci yang membuka kredensial Google dan Zoom di database.

---

## 4. Pastikan .htaccess benar-benar dibaca

DirectAdmin bisa berjalan dengan Apache, atau **nginx sebagai reverse proxy**,
atau **nginx murni**. Pada nginx murni, `.htaccess` **diabaikan total** — dan
itu berarti `inc/`, `db/`, dan `cron/` terbuka untuk umum.

Cek di **DirectAdmin → Domain Setup → callalily.party**, lihat bagian web server.

**Kalau nginx murni**, semua proteksi harus dipindah ke konfigurasi nginx.
Buat `~/domains/callalily.party/nginx.conf` (atau lewat Custom HTTPD
Configurations di panel admin server):

```nginx
location ~ ^/(inc|db|cron)/ { deny all; return 404; }
location ~ /\.(ht|git|env) { deny all; return 404; }
location ~ ^/(uploads|foto|video)/.*\.(php|phtml|pl|py|cgi|sh)$ { deny all; return 404; }
location = /admin/setup.php { deny all; return 404; }
```

Kalau Anda tidak punya akses ke konfigurasi nginx, mintakan ke administrator
server. Ini bukan opsional — tanpa itu, `config.php` bisa diunduh siapa saja.

---

## 5. Kunci direktori admin dengan lapisan kedua

Panel sudah punya login sendiri, tapi menambah satu lapisan di depannya membuat
serangan otomatis berhenti sebelum menyentuh PHP sama sekali.

**DirectAdmin → Password Protected Directories → pilih `public_html/admin`.**
Buat user dan kata sandi yang **berbeda** dari akun panel.

Efeknya: peramban meminta kata sandi sebelum halaman login muncul. Bot yang
memindai `/admin/` akan langsung kena 401 tanpa membebani PHP atau database.

Kalau merepotkan untuk dipakai sehari-hari, alternatifnya batasi per-IP di
`admin/.htaccess`:

```apache
<RequireAny>
  Require ip 114.x.x.        # IP kantor
  Require ip 36.x.x.x        # IP rumah
</RequireAny>
```

Hanya kalau IP Anda statis. IP dinamis akan mengunci diri sendiri.

---

## 6. Cadangan — ini yang paling sering diabaikan

Keamanan tanpa cadangan tidak ada artinya. Kalau situs diretas atau database
rusak, satu-satunya jalan pulang adalah salinan yang bersih.

**DirectAdmin → Create/Restore Backups → Schedule**, jalankan harian.

Tapi cadangan yang tersimpan di server yang sama ikut hilang kalau servernya
bermasalah. Tambahkan cron untuk mengunduh salinan database:

```
30 2 * * * mysqldump -u USER -pPASS NAMADB | gzip > ~/backup/db-$(date +\%F).sql.gz
0 3 * * 0 find ~/backup -name 'db-*.sql.gz' -mtime +30 -delete
```

Unduh isi `~/backup/` ke komputer atau penyimpanan awan secara berkala.
Cadangan yang tidak pernah diuji pulih sama dengan tidak punya cadangan —
sesekali coba impor ke database uji.

---

## 7. Pembaruan dan pemantauan

- **PHP**: DirectAdmin → PHP Version. Pakai 8.2 atau 8.3. PHP 8.0 dan 8.1 sudah
  lewat masa dukungan keamanan.
- **Log gagal login**: panel mencatatnya di tabel `login_attempts` dan
  `audit_log`. Sesekali periksa:

  ```sql
  SELECT ip, tries, locked_until FROM login_attempts ORDER BY last_try DESC LIMIT 20;
  SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 30;
  ```

- **Log akses**: `~/domains/callalily.party/logs/`. Cari pemindaian:

  ```bash
  grep -iE "wp-login|xmlrpc|\.env|phpmyadmin|/admin/" access.log | tail -40
  ```

- **Brute force**: DirectAdmin punya **Brute Force Monitor** bawaan. Pastikan
  menyala dan ambangnya wajar (misal 10 kegagalan dalam 5 menit).

---

## 8. Yang sudah ditangani aplikasi

Supaya jelas mana yang tidak perlu Anda pikirkan lagi:

| Ancaman | Penanganan |
|---|---|
| SQL injection | Prepared statement dengan `EMULATE_PREPARES=false` — parameter tidak pernah digabung ke string SQL |
| XSS tersimpan | Konten artikel disaring whitelist tag; `<script>`, `<iframe>`, atribut `on*`, dan `javascript:` dibuang beserta isinya |
| XSS terpantul | Semua keluaran lewat `e()` (`htmlspecialchars` dengan `ENT_QUOTES`) |
| CSRF | Token per-sesi di semua POST, dibandingkan dengan `hash_equals` |
| Session fixation | `session_regenerate_id(true)` setiap login berhasil |
| Pencurian cookie | `httponly`, `samesite=Lax`, `secure` otomatis saat HTTPS |
| Brute force login | Kunci 15 menit setelah 5 gagal, per IP |
| Enumerasi email | Verifikasi kata sandi tetap dijalankan pada hash dummy walau email tidak ada, jadi waktu respons seragam |
| Kata sandi bocor dari dump DB | bcrypt cost 12 |
| Kredensial Google/Zoom bocor dari dump DB | AES-256-GCM dengan `APP_KEY` yang tersimpan di berkas, bukan di database |
| Web shell lewat upload | Tipe berkas divalidasi dari isi (`finfo`), bukan ekstensi; eksekusi PHP dimatikan di `uploads/`, `foto/`, `video/` |
| Token reset dicuri dari DB | Yang disimpan hanya SHA-256-nya; token asli tidak pernah masuk database |
| Clickjacking | `X-Frame-Options: SAMEORIGIN` |
| MIME sniffing | `X-Content-Type-Options: nosniff` |

Yang **tidak** ditangani aplikasi dan tetap tanggung jawab server: HTTPS,
izin berkas, proteksi direktori kalau memakai nginx, cadangan, dan pembaruan PHP.

---

## Ringkasan urutan kerja

```bash
# 1. HTTPS lewat panel, lalu aktifkan force-HTTPS di .htaccess
# 2. Hapus berkas pemasangan
cd ~/domains/callalily.party/public_html && rm -f admin/setup.php cek.php

# 3. Izin
find . -type d -exec chmod 755 {} \; && find . -type f -exec chmod 644 {} \;
chmod 600 inc/config.php

# 4. Uji proteksi — ketiganya harus 403/404
curl -o /dev/null -w "%{http_code}\n" https://callalily.party/inc/config.php
curl -o /dev/null -w "%{http_code}\n" https://callalily.party/db/schema.sql
curl -o /dev/null -w "%{http_code}\n" https://callalily.party/cron/harian.php

# 5. Password Protected Directories pada /admin lewat panel
# 6. Jadwalkan cadangan
```
