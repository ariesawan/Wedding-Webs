# Callalily Party CMS

Sistem manajemen konten untuk situs Callalily Party. PHP 8.1+ dan MySQL/MariaDB,
**tanpa Composer, tanpa framework, tanpa Node** — dirancang supaya bisa langsung
dijalankan di shared hosting cPanel biasa.

Enam hal yang ditangani:

| Modul | Isi |
|---|---|
| **Galeri** | Upload foto & video, thumbnail + WebP otomatis, urutan keping bola 3D |
| **Blog** | Editor, audit SEO on-page, JSON-LD, sitemap & RSS otomatis |
| **Event** | Panel 2 & 3 beranda (akan datang / sudah terselenggara), pindah sendiri berdasarkan tanggal |
| **Jadwal klien** | Zoom + Google Calendar + undangan email + berkas `.ics`, sekali simpan |
| **Pipeline klien** | Prospek → deal → persiapan → hari-H, dengan checklist & termin pembayaran otomatis |
| **Spreadsheet** | Ekspor otomatis klien, jadwal, pembayaran, dan checklist ke Google Sheets |

---

## 1. Persyaratan hosting

| Kebutuhan | Keterangan |
|---|---|
| PHP | 8.1 atau lebih baru |
| Ekstensi | `pdo_mysql`, `gd`, `curl`, `openssl`, `mbstring`, `fileinfo`, `exif` |
| MySQL | 5.7+ / MariaDB 10.3+ (butuh dukungan `FULLTEXT` pada InnoDB) |
| Lain | `mod_rewrite` aktif, sertifikat SSL terpasang |

Cek dulu di **cPanel → Select PHP Version → Extensions**. Yang paling sering belum
aktif adalah `exif` (untuk koreksi orientasi foto dari HP) dan `gd`.

---

## 2. Menaruh berkas

Isi paket ini disalin ke `public_html/`. Berkas `index.html` dan `galeri.html`
yang lama **dihapus** — penggantinya `index.php` dan `galeri.php`.

Folder `foto/` dan `video/` yang sudah ada **jangan dihapus**. Skema database
sudah berisi 12 item galeri lama, dan nama berkasnya persis sama, jadi galeri
langsung terisi begitu dipasang.

```bash
cd ~/public_html
# amankan dulu yang lama
mkdir -p ~/backup-statis && cp index.html galeri.html ~/backup-statis/

unzip callalily-cms.zip -d ~/public_html/
rm -f index.html galeri.html

# folder tulis
mkdir -p uploads/blog uploads/event
find uploads foto video -type d -exec chmod 755 {} \;
chmod 755 uploads foto video
```

Jangan pakai `chmod 777`. Di cPanel, PHP berjalan sebagai user akun Anda
(suPHP/LSAPI), jadi `755` sudah cukup untuk menulis. `777` justru membuat berkas
bisa ditulis proses lain di server yang sama.

---

## 3. Database

**cPanel → MySQL Databases**: buat database, buat user, kaitkan dengan
*ALL PRIVILEGES*. Lalu impor skema:

```bash
mysql -u callalily_cms -p callalily_cms < db/schema.sql
```

Atau lewat **phpMyAdmin → Import → pilih `db/schema.sql`**.

---

## 4. Konfigurasi

```bash
cd ~/public_html/inc
cp config.sample.php config.php
openssl rand -hex 32          # salin hasilnya ke APP_KEY
nano config.php
```

Yang wajib diisi: `DB_*`, `BASE_URL` (tanpa slash di akhir), dan `APP_KEY`.

`APP_KEY` dipakai untuk mengenkripsi `client_secret` dan `refresh_token` di
tabel `settings`. **Kalau kunci ini diganti setelah integrasi tersambung,
kredensial lama tidak bisa dibaca lagi** dan Google/Zoom harus dihubungkan ulang.
Simpan salinannya di tempat aman.

---

## 5. Akun admin pertama

Buka `https://domain-anda.com/admin/setup.php`, isi nama, email, kata sandi
(minimal 12 karakter).

```bash
# WAJIB, segera setelah akun dibuat:
rm ~/public_html/admin/setup.php
```

Berkas itu memang mengunci diri sendiri setelah ada satu user, tapi tidak ada
alasan membiarkannya tetap ada.

Login di `https://domain-anda.com/admin/`.

---

## 6. Google Calendar

**Admin → Integrasi → Google Calendar.**

1. `console.cloud.google.com` → buat project baru.
2. **APIs & Services → Library** → aktifkan **Google Calendar API**.
3. **OAuth consent screen** → *External* → isi nama aplikasi dan email dukungan.
   Tekan **Publish app**. Kalau dibiarkan di status *Testing*, refresh token
   akan kedaluwarsa setiap 7 hari dan integrasi mati sendiri.
4. **Credentials → Create Credentials → OAuth client ID → Web application.**
5. *Authorized redirect URIs*, isi persis:
   `https://domain-anda.com/admin/oauth-callback.php`
6. Salin **Client ID** dan **Client Secret** ke form, simpan, lalu tekan
   **Hubungkan Google Calendar** dan setujui izinnya.

### Kenapa OAuth, bukan Service Account

Service account **tidak bisa mengundang tamu** pada akun Gmail biasa — itu hanya
berfungsi di Google Workspace dengan Domain-Wide Delegation. Selain itu event
yang dibuat service account masuk ke kalender milik service account, bukan
kalender owner.

Karena yang dibutuhkan adalah *event muncul di kalender owner* dan *klien
menerima undangan yang bisa di-RSVP*, alur yang benar adalah owner memberi izin
sekali, lalu server menyimpan `refresh_token` dan menukarnya jadi `access_token`
setiap kali dibutuhkan.

Scope yang diminta hanya `calendar.events` — cukup untuk membuat, mengubah, dan
menghapus event, tapi tidak bisa membaca isi kalender lain.

---

## 7. Zoom

**Admin → Integrasi → Zoom.**

1. `marketplace.zoom.us` → **Develop → Build App → Server-to-Server OAuth**.
2. Tab **Scopes**: tambahkan `meeting:write:admin`
   (pada akun dengan granular scope: `meeting:write:meeting:admin` dan
   `meeting:delete:meeting:admin`).
3. Tab **Activation**: aktifkan aplikasinya.
4. Salin **Account ID**, **Client ID**, **Client Secret** ke form.
5. Tekan **Uji koneksi Zoom** untuk memastikan.

JWT App sudah dimatikan Zoom sejak Juni 2023, jadi Server-to-Server OAuth adalah
satu-satunya cara membuat meeting dari server tanpa interaksi pengguna.

Zoom opsional. Kalau tidak diisi, mode **Google Meet** tetap berjalan dan
tautannya dibuat otomatis oleh Google Calendar.

---

## 8. Email

**Admin → Integrasi → Pengiriman email.** Pilih **SMTP**, bukan `mail()`.

| Isian | Nilai umum di cPanel |
|---|---|
| Host | `mail.domain-anda.com` |
| Port | `587` |
| Enkripsi | STARTTLS |
| User | alamat email lengkap, mis. `halo@domain-anda.com` |

Gunakan alamat pengirim **di domain sendiri**. Kalau memakai Gmail atau Yahoo
sebagai `From`, SPF domain tersebut akan gagal dan email masuk spam.

Pastikan juga di **cPanel → Email Deliverability**, status SPF dan DKIM keduanya
hijau. Tekan **Kirim uji** untuk memastikan.

---

## 9. Cron pengingat H-1

**cPanel → Cron Jobs**, jalankan sekali sehari:

```
0 8 * * * /usr/local/bin/php /home/USER/public_html/cron/reminder.php >> /home/USER/logs/reminder.log 2>&1
```

Ganti `USER` dengan nama akun cPanel. Cek path PHP yang benar dengan
`which php` atau lihat di **Select PHP Version**.

Skrip ini mengirim pengingat untuk pertemuan yang mulai 12–36 jam ke depan, dan
menandai `reminder_sent_at` supaya tidak terkirim dua kali.

---

## 10. Setelah terpasang

- **Admin → Pengaturan**: isi identitas bisnis, alamat, media sosial. Semua ini
  masuk ke data terstruktur `LocalBusiness` — inilah yang dibaca Google untuk
  hasil pencarian lokal "wedding organizer Jogja".
- **Google Search Console**: daftarkan domain, verifikasi lewat metode *HTML tag*
  (tempel nilai `content=` ke **Pengaturan → Verifikasi Search Console**), lalu
  kirim sitemap: `https://domain-anda.com/sitemap.xml`
- Edit `robots.txt`, ganti baris `Sitemap:` dengan domain yang benar.
- **Google Analytics 4**: tempel Measurement ID (`G-XXXXXXXXXX`) di Pengaturan.

---

---

## 11. Pipeline klien — dari prospek sampai hari-H

Setiap klien punya satu tahap dan satu tindakan berikutnya yang bertanggal.
Kalau tanggal itu lewat, klien muncul di **Hari ini** pada Ringkasan.

```
Prospek baru → Price list terkirim → Menunggu DP → Deal · penyusunan → Persiapan → Hari-H → Selesai
      └────────────── Tidak jadi (alasan wajib, dicatat di Analisa) ──────────────┘
         admin early  ─────────────────────────────┘└── admin office ──────────────────
```

Halaman klien dibuka dengan kartu **Langkah sekarang** — isinya mengikuti tahap,
lengkap dengan tombol yang dibutuhkan. Sisanya ada di tab: Ikhtisar, Biodata awal,
Price list, Biodata lengkap (keluarga, adat), Acara & dekor, Vendor, Pembayaran.

### Peran

| Peran | Bagian |
|---|---|
| Admin early | Prospek: biodata awal, kirim price list (PDF lewat WhatsApp), tanggapan klien, tagih & konfirmasi DP 30%. Juga mengurus **Paket & price list** dan **Formulir masuk** |
| Admin office | Setelah DP: biodata lengkap & keluarga, venue, konsep dekor, vendor, termin pembayaran, meeting, persiapan |
| Owner | Semuanya |

### Paket & price list

Menu **Paket & price list** berisi paket berbentuk *paket + rincian isi*: satu
harga paket, isi dikelompokkan (Wedding Organizer, Rias & busana, Dekorasi, …),
dan tambahan opsional berharga sendiri. Paket bertanda **Tampil di situs**
muncul di `https://callalily.party/pricelist`; selama harganya kosong, situs
menulis "harga dikirim lewat WhatsApp". Paket internal (mis. *Template kosong*)
hanya dipakai untuk menyusun price list khusus.

Price list dikirim sebagai **PDF**. Dengan gateway WhatsApp (Fonnte) di
Pengaturan, tombol **Kirim PDF lewat WhatsApp** melampirkan berkasnya langsung;
tanpa gateway, unduh PDF-nya atau buka WhatsApp klien dengan teks siap kirim,
lalu tekan **Sudah saya kirim manual**. Isi rekening dan nomor WA admin di
Pengaturan → *Price list & pembayaran* supaya ikut tercetak.

### Yang berjalan otomatis

| Kejadian | Akibatnya |
|---|---|
| Klien masuk lewat formulir / dicatat di panel | Tindakan "Kirim price list" bertanggal hari ini; paket yang dipilih klien tercatat sebagai *paket diminati* |
| Price list ditandai terkirim | Tahap → **Price list terkirim** |
| Klien menawar | Angka & alasannya dicatat, lalu **Buat revisi** |
| Price list **cocok** | Tahap → **Menunggu DP**: nilai deal = total price list, termin disusun dari *template pembayaran*, DP 30% ditagih |
| **DP masuk** (tombol Konfirmasi DP, atau tandai lunas termin DP) | Tahap → **Deal · penyusunan**, event dibuat, klien pindah ke **admin office** |
| Price list **tidak cocok** | Tahap → **Tidak jadi**, langsung diarahkan ke Analisa |
| Klien "tidak jadi" mengisi formulir lagi | Aktif kembali sebagai Prospek baru |
| Mulai persiapan | Checklist 17 langkah H-90 sampai H+3 |
| H-7 (deal atau persiapan) | Tahap → **Hari-H** (cron) |
| Acara lewat | Tahap → **Selesai** (cron) |

Menjadwalkan konsultasi/meeting **tidak** memindahkan tahap. Perpindahan
otomatis hanya **maju**: mengirim price list tambahan ke klien yang sudah deal
tidak menyeretnya mundur.

### Formulir publik

`/form` (dan `form.callalily.party`) hanya menanyakan biodata awal + paket.
**Setiap kiriman tercatat** di menu **Formulir masuk** — termasuk yang ditolak
penjagaan anti-bot, gagal validasi, atau galat database. Yang belum jadi klien
muncul di Ringkasan sebagai "Kiriman formulir belum jadi klien" dan bisa
dijadikan klien dengan satu tombol. Nomor WA dicocokkan dari 9 digit terakhir,
jadi kiriman ulang tidak membuat klien kembar.

### Tautan price list untuk klien

Teks WhatsApp memuat tautan `…/penawaran.php?t=…` (rincian di HP) dan
`…&pdf=1` (berkas PDF). Halaman itu hanya-baca, tidak diindeks, dan mencatat
kapan klien pertama kali membukanya.

### Struktur database

Kolom dan tabel yang dibutuhkan kode ditambahkan sendiri saat halaman pertama
dibuka setelah unggah (`inc/skema.php`). Kalau hosting menolak, panel owner
menampilkan peringatan kuning — jalankan `db/migration-v23.sql` lewat
phpMyAdmin.

---

## 12. Google Spreadsheet

**Admin → Integrasi → Google Spreadsheet.**

1. Centang **Aktifkan ekspor**, simpan.
2. Google butuh izin tambahan untuk Spreadsheet, jadi kembali ke kartu Google
   Calendar dan tekan **Hubungkan ulang** — setujui akses Spreadsheet.
3. Tekan **Buat spreadsheet baru**, lalu **Sinkronkan sekarang**.

Tab yang dibuat: `Klien`, `Jadwal`, `Pembayaran`, `Checklist`, `Event`, `Ringkasan`.

Arahnya **satu jalur**: sistem menulis ke sheet, sheet tidak pernah mengubah
database. Ini disengaja — kalau dua arah, penyuntingan bersamaan akan bentrok dan
hampir mustahil ditelusuri di shared hosting tanpa antrean pekerjaan.

Setiap sinkronisasi menulis ulang seluruh isi tab, jadi data yang dihapus di panel
ikut hilang dan tidak pernah ada baris ganda meski cron jalan dua kali.

Scope yang diminta hanya `spreadsheets` + `drive.file`. `drive.file` memberi akses
**hanya ke berkas yang dibuat aplikasi ini**, bukan seluruh Drive owner.

---

## 13. Lupa kata sandi

Tiga jalur, sesuai keadaan:

**a. Lewat email** — tautan *Lupa kata sandi?* di halaman masuk. Berlaku 60 menit,
sekali pakai. Butuh SMTP yang sudah jalan.

**b. Lewat SSH** — jalur darurat ketika SMTP belum siap atau email tidak bisa diakses:

```bash
cd ~/public_html
php cron/reset-password.php                        # daftar akun
php cron/reset-password.php email@anda.com         # sandi acak, ditampilkan di layar
php cron/reset-password.php email@anda.com 'FrasaSandiPanjang'
php cron/reset-password.php --link email@anda.com  # cetak tautan reset
```

Skrip ini menolak dijalankan lewat browser. Ia juga membersihkan kunci login
(throttle) untuk semua IP — berguna kalau owner terkunci karena salah sandi berkali-kali.

**c. Lewat database** — kalau PHP CLI pun tidak tersedia, buat hash bcrypt di
komputer lain lalu:
```sql
UPDATE users SET password_hash = '$2y$12$...' WHERE email = 'email@anda.com';
DELETE FROM login_attempts;
```

---

## 14. Kalau integrasi tidak jalan

**Admin → Integrasi** kini diawali panel **Pemeriksaan lingkungan**. Ia memeriksa,
berurutan dari penyebab yang paling sering:

1. **BASE_URL vs domain yang sedang dibuka** — penyebab nomor satu. Kalau
   `inc/config.php` masih berisi domain lama, semua redirect dan alamat callback
   OAuth mengarah ke tempat yang salah, dan Google menolak dengan
   `redirect_uri_mismatch`.
2. HTTPS aktif atau belum
3. Ekstensi PHP wajib (curl, openssl, gd, fileinfo, mbstring, exif)
4. APP_KEY sudah diisi dengan benar
5. Koneksi keluar ke googleapis.com, sheets.googleapis.com, api.zoom.us
6. Direktori upload bisa ditulis
7. Batas `upload_max_filesize` / `post_max_size`
8. `admin/setup.php` masih tertinggal di server

Di bawahnya ada kotak berisi **alamat callback yang harus didaftarkan** lengkap
dengan tombol salin. Harus sama persis — beda satu garis miring atau http vs https,
Google menolak.

---

## 15. Cron

Cukup satu baris untuk semua pekerjaan harian:

```
0 8 * * * /usr/local/bin/php /home/USER/public_html/cron/harian.php >> /home/USER/logs/calla.log 2>&1
```

Yang dikerjakan: pengingat H-1 pertemuan, perpindahan tahap otomatis
(persiapan → hari-H → selesai), pencatatan termin yang mendekati jatuh tempo,
dan sinkronisasi spreadsheet bila diaktifkan.

`cron/reminder.php` masih ada dan hanya menjalankan pengingat, untuk kompatibilitas
dengan cron lama.

---

## Panel error 500 padahal situs publik jalan

Penyebab paling sering: **`db/migration-v2.sql` belum dijalankan.**

Situs publik tidak menyentuh tabel baru (`clients`, `payments`, `client_tasks`,
`client_activities`, `password_resets`), jadi ia tetap tampil normal. Tetapi menu
samping panel menghitung lencana "Klien" dari tabel `clients` — begitu tabel itu
tidak ada, PDO melempar exception dan **seluruh halaman admin** balas 500.
Karena `APP_ENV` diset `production`, pesan aslinya tidak muncul di layar.

**Cara memastikan:** unggah `cek.php` ke root situs lalu buka
`https://domain-anda.com/cek.php`. Ia memeriksa versi PHP, ekstensi, config,
BASE_URL, kelengkapan berkas, struktur database, dan izin direktori — lalu
menyebut penyebabnya beserta perintah perbaikannya.

```bash
mysql -u USER -p NAMA_DB < db/migration-v2.sql
rm cek.php          # hapus setelah beres
```

**Kalau `cek.php` semua hijau tapi masih 500:** ubah sementara `APP_ENV` di
`inc/config.php` menjadi `'development'`. Pesan error aslinya akan tampil di layar.
Kembalikan ke `'production'` setelah selesai — jangan ditinggal, karena pesan error
PHP bisa membocorkan path server dan detail query.

**Melihat log error di cPanel:** menu *Errors*, atau berkas `error_log` di folder
yang sama dengan skrip yang gagal.

## Struktur berkas

```
public_html/
├── index.php              beranda (panel 2 & 3 dinamis)
├── galeri.php             bola galeri 3D, data dari database
├── blog.php               daftar artikel
├── blog-detail.php        artikel tunggal
├── event.php              detail event
├── sitemap.php            → /sitemap.xml
├── feed.php               RSS 2.0
├── 404.php
├── cek.php                pemeriksa pemasangan — hapus setelah beres
├── robots.txt
├── .htaccess              URL bersih + header keamanan
│
├── inc/                   [tidak bisa diakses dari web]
│   ├── config.php         satu-satunya berkas yang diedit saat instalasi
│   ├── db.php             PDO
│   ├── helpers.php        escape, slug, CSRF, tanggal Indonesia
│   ├── auth.php           login, bcrypt, throttle
│   ├── settings.php       key-value + enkripsi AES-256-GCM
│   ├── image.php          GD: resize, thumbnail, WebP, EXIF
│   ├── seo.php            meta, JSON-LD, audit on-page
│   ├── http.php           pembungkus cURL
│   ├── google.php         OAuth + Calendar API
│   ├── zoom.php           Server-to-Server OAuth
│   ├── ics.php            berkas kalender RFC 5545
│   ├── mailer.php         mail() / SMTP + template
│   ├── meeting.php        orkestrasi Zoom → Calendar → email
│   ├── pipeline.php       tahap klien, checklist, termin pembayaran
│   ├── sheets.php         ekspor ke Google Spreadsheet
│   ├── reminder.php       pengingat H-1
│   └── diagnostics.php    pemeriksaan lingkungan
│
├── admin/
│   ├── index.php          ringkasan + daftar tindakan hari ini
│   ├── klien.php          papan pipeline & detail klien
│   ├── galeri.php  event.php  blog.php  jadwal.php
│   ├── integrasi.php  pengaturan.php
│   ├── oauth-callback.php  cek-bentrok.php
│   ├── lupa-password.php  reset-password.php
│   ├── login.php  logout.php  setup.php ← HAPUS setelah instalasi
│   └── assets/admin.css
│
├── partials/
│   ├── events-upcoming.php   panel 2 beranda
│   ├── events-past.php       panel 3 beranda
│   ├── events.css
│   └── public-head.php  public-foot.php
│
├── cron/
│   ├── harian.php         SATU cron untuk semua pekerjaan harian
│   ├── reminder.php       pengingat saja (kompatibilitas cron lama)
│   └── reset-password.php reset kata sandi lewat SSH
├── db/schema.sql          instalasi baru
├── db/migration-v2.sql    untuk instalasi yang sudah berjalan
├── foto/  video/          galeri (berkas lama tetap dipakai)
└── uploads/blog/  uploads/event/
```

---

## Catatan keamanan

- `inc/`, `db/`, `cron/` diblokir dari akses web lewat `.htaccess`.
- `uploads/`, `foto/`, `video/` mematikan eksekusi PHP. Kalau suatu saat ada
  celah pada validasi upload, penyerang tetap tidak bisa menjalankan web shell
  dari sana.
- Semua form POST memakai token CSRF; `hash_equals` untuk perbandingan
  waktu-konstan.
- Login dikunci 15 menit setelah 5 kali gagal, per alamat IP.
- Password bcrypt cost 12. Verifikasi tetap dijalankan pada hash dummy walau
  email tidak terdaftar, supaya waktu respons tidak membocorkan email mana yang
  ada di sistem.
- Konten artikel disaring lewat whitelist tag; atribut `on*` dan `javascript:`
  dibuang sebelum disimpan.
- Query memakai prepared statement dengan `EMULATE_PREPARES=false`.
- Zoom `start_url` (tautan host) disimpan tapi **tidak pernah** dikirim ke klien
  — hanya `join_url` yang dibagikan.

---

## Pemecahan masalah

| Gejala | Penyebab yang paling sering |
|---|---|
| "Ekstensi GD belum aktif" | Aktifkan `gd` di Select PHP Version |
| Upload foto besar gagal | `upload_max_filesize` / `post_max_size` di MultiPHP INI Editor |
| Foto ter-upload miring | Ekstensi `exif` belum aktif |
| "Izin Google sudah dicabut" | OAuth consent screen masih *Testing* — tekan Publish app |
| Email tidak sampai | Cek SPF/DKIM di Email Deliverability; jangan pakai From Gmail |
| URL `/blog/xxx` jadi 404 | `mod_rewrite` mati, atau `AllowOverride` tidak `All` |
| Jadwal tersimpan tapi "Perlu dicek" | Buka jadwalnya, pesan errornya ada di panel Status integrasi |
| Setelah simpan malah diarahkan ke domain lain | `BASE_URL` di inc/config.php masih domain lama |
| `redirect_uri_mismatch` dari Google | Alamat callback di Console tidak sama persis dengan yang di panel Integrasi |
| Spreadsheet: "izin belum diberikan" | Aktifkan dulu fiturnya, lalu **Hubungkan ulang** Google agar scope bertambah |
| Terkunci karena salah sandi 5 kali | `php cron/reset-password.php EMAIL` — throttle ikut dibersihkan |
