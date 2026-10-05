# Callalily CMS v22 — pemasangan di DirectAdmin

Paket ini memperbaiki alur klien → price list → penawaran → deal, dan merapikan
tampilan panel (Ringkasan, daftar klien, halaman klien, menu samping).

## 1. Backup dulu (wajib)

DirectAdmin → **File Manager** → pilih folder `public_html` → **Compress** (zip),
lalu unduh. Database: **phpMyAdmin** → pilih `tuguasri_wo` → **Export** → Go.

Simpan backup DI LUAR `public_html`.

## 2. Unggah & ekstrak

1. DirectAdmin → File Manager → buka `domains/callalily.party/public_html/`
2. **Upload** `callalily-v22.zip` ke folder itu.
3. Klik kanan zip → **Extract** → tujuan `public_html` (folder yang sama),
   centang **overwrite / timpa** bila ditanya.
4. Hapus `callalily-v22.zip` dari `public_html` setelah selesai.

Yang TIDAK ikut di paket (aman, tidak tertimpa): `inc/config.php`, folder
`foto/`, `video/`, `uploads/`.

Izin berkas: kalau ada halaman 403/500 setelah ekstrak, pilih semua berkas
`.php` → **Set Permissions** → 644, folder 755.

## 3. Database — biasanya tidak perlu apa-apa

Buka panel (`/admin/`) sekali sebagai owner. Kolom yang belum ada
(v19 tawar-menawar, v21 tamu akad/resepsi) ditambahkan **otomatis**.

Kalau muncul kotak kuning *"Struktur database belum bisa diperbarui otomatis"*:
phpMyAdmin → `tuguasri_wo` → tab **SQL** → tempel isi `db/migration-v22.sql`
→ Go. Aman dijalankan berulang.

## 4. Bersih-bersih (penting untuk keamanan)

Hapus dari `public_html` lewat File Manager:

| Berkas / folder | Kenapa |
|---|---|
| `_layout.php`, `auth.php`, `klien.php`, `pengguna.php` (di root, BUKAN yang di `admin/` / `inc/`) | Salinan nyasar dari pemasangan lama. Di paket ini sudah diganti penolak 404, tapi lebih baik dihapus |
| `admin/klien.php.bak-…`, `admin/_layout.php.bak-…`, `inc/auth.php.bak-…` | Kode sumber bisa diunduh publik |
| `admin/error_log` | Memuat path server |
| folder `{db,inc,admin,partials,assets}` dan `paket/` | Folder kosong sisa salah ekstrak |
| `admin/setup.php`, `cek.php`, `tools/cek-fungsi.php` | Alat pemasangan — tidak boleh tertinggal |
| `galeri.html` | Versi statis lama, sudah diganti `/galeri` |
| `backup.zip`, `files.zip`, zip lain | Bisa diunduh siapa pun |

Lalu kosongkan **Trash** di File Manager.

## 5. Cek cepat setelah pasang

1. Masuk sebagai **owner** → Ringkasan tampil 4 angka + "Jalur klien".
2. Buka satu klien → ada kartu **Langkah sekarang** dan tab di bawahnya.
3. Buka `https://callalily.party/form` → kirim satu data uji → muncul di
   Klien sebagai Prospek baru (dulu formulir ini gagal menyimpan).
4. Buka penawaran yang sudah terkirim → salin tautan dari teks WA →
   buka di HP: halaman rincian tampil (dulu galat 500).

## Yang berubah di alur

- **Price list "cocok" → tahap Spesifikasi**, bukan langsung Deal.
- **Tandai terkirim** (kirim manual lewat WA) ikut memajukan tahap.
- **Deal** mengambil nilai dari penawaran yang disetujui, dan termin mengikuti
  *template pembayaran* (30% / 20% H-60 / 30% H-30 / 20% H-7) — sama dengan yang
  tertulis di penawaran ke klien. Dulu panel menagih DP 30% + H-60 + H-14.
- **Jadwalkan konsultasi** untuk prospek tidak lagi gagal setengah jalan.
- **Simpan kebutuhan vendor** tidak lagi mengosongkan data klien (pasangan,
  email, WA, tanggal, venue, budget, catatan terhapus — ini bug lama).
- **Tidak cocok** pada price list/penawaran → klien masuk arsip "Tidak jadi"
  dan langsung diarahkan ke Analisa. Alasan "tidak jadi" kini wajib.
- Form Jadwal punya dua pilihan "klien" yang saling menimpa — yang kedua dibuang.
