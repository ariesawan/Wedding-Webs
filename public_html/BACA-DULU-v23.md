# Callalily CMS v23 — pemasangan di DirectAdmin

Paket ini menyusun ulang alur sesuai arahan owner:

**admin early** — biodata awal → kirim price list (PDF lewat WhatsApp) → klien
cocok → tagih **DP 30%** → DP masuk → **admin office** — biodata lengkap &
keluarga, venue, konsep dekor, vendor, termin pembayaran, meeting → persiapan.

Plus: halaman **price list** di situs (paket bisa diubah dari panel), dan
**formulir publik yang setiap kirimannya pasti tercatat**.

## 1. Backup dulu (wajib)

DirectAdmin → **File Manager** → pilih folder `public_html` → **Compress** (zip),
lalu unduh. Database: **phpMyAdmin** → pilih `tuguasri_wo` → **Export** → Go.

Simpan backup DI LUAR `public_html`.

## 2. Unggah & ekstrak

1. DirectAdmin → File Manager → buka `domains/callalily.party/public_html/`
2. **Upload** `callalily-v23.zip` ke folder itu.
3. Klik kanan zip → **Extract** → tujuan `public_html` (folder yang sama),
   centang **overwrite / timpa** bila ditanya.
4. Hapus `callalily-v23.zip` dari `public_html` setelah selesai.

Yang TIDAK ikut di paket (aman, tidak tertimpa): `inc/config.php`, folder
`foto/`, `video/`, `uploads/`.

Paket ini membawa pustaka PDF (`inc/lib/fpdf/`) — tidak perlu memasang apa
pun di server.

## 3. Database — biasanya tidak perlu apa-apa

Buka panel (`/admin/`) sekali sebagai owner. Yang belum ada ditambahkan
**otomatis**: tahap *Menunggu DP*, kolom paket, konsep dekor, log formulir,
dan tiga paket contoh (Prasaja, Semanak, Sidomukti — **harga kosong**).
Klien yang masih di tahap lama *Spesifikasi* / *Penawaran* dipindah ke
*Price list terkirim*.

Kalau muncul kotak kuning *"Struktur database belum bisa diperbarui otomatis"*:
phpMyAdmin → `tuguasri_wo` → tab **SQL** → tempel isi `db/migration-v23.sql`
→ Go. Aman dijalankan berulang (sudah termasuk isi v22).

## 4. Isi sekali setelah pasang (penting)

Panel → **Pengaturan**:

| Isian | Kenapa |
|---|---|
| **Nomor WhatsApp** situs | Tombol WA di formulir & price list. Kalau masih `6281234567890` (contoh), situs memakai nomor admin early |
| **Price list & pembayaran** → nama bank, no. rekening, atas nama | Tercetak di PDF price list dan teks tagihan DP |
| WA admin early / admin office | Nomor PIC di PDF; admin early juga menerima notifikasi formulir masuk (kalau gateway WA aktif) |

Panel → **Paket & price list**: isi **harga** tiap paket, sesuaikan isinya.
Selama harga kosong, situs menulis "harga dikirim lewat WhatsApp" dan price
list tidak bisa ditandai *cocok* (DP 30% dihitung dari harga).

## 5. Bersih-bersih (keamanan)

Hapus dari `public_html` lewat File Manager bila masih ada:

| Berkas / folder | Kenapa |
|---|---|
| `_layout.php`, `auth.php`, `klien.php`, `pengguna.php` di root (BUKAN yang di `admin/` / `inc/`) | Salinan nyasar pemasangan lama |
| `*.bak-…`, `admin/error_log` | Kode sumber / path server bisa terbaca publik |
| `admin/setup.php`, `cek.php`, `tools/` | Alat pemasangan — tidak boleh tertinggal |
| `galeri.html`, `backup.zip`, `files.zip`, zip lain | Versi lama / bisa diunduh siapa pun |

Lalu kosongkan **Trash** di File Manager.

## 6. Cek cepat setelah pasang

1. Buka `https://callalily.party/pricelist` → tiga paket tampil.
2. Klik **Pilih paket ini** → formulir terbuka dengan paket tercentang →
   kirim data uji → muncul di **Klien** sebagai Prospek baru dan di
   **Formulir masuk** sebagai *Jadi klien*.
3. Buka klien uji → **Siapkan price list …** → isi harga → **Unduh PDF**.
4. **Sudah saya kirim manual** → **Klien cocok → tagih DP 30%** →
   **Konfirmasi DP masuk** → klien pindah ke admin office.
5. Hapus klien uji (tab Biodata awal → Hapus klien).

## Yang berubah

- Tahap baru **Menunggu DP**. *Cocok* tidak lagi langsung deal: DP 30% ditagih
  dulu; begitu dikonfirmasi masuk, klien deal dan pindah ke admin office.
- Tahap *Spesifikasi* & *Penawaran* tidak dipakai lagi. Menjadwalkan
  konsultasi tidak memindahkan tahap.
- **Paket & price list** menggantikan *Template penawaran*. Price list
  berbentuk paket + rincian isi, dicetak PDF, dikirim lewat WhatsApp.
- Halaman situs baru **/pricelist**; tautannya ada di menu atas, footer, dan
  bagian "Mencari harga?" di beranda.
- **Formulir publik** diringkas (biodata awal + paket). Penyebab kiriman
  "tidak ke-track" ditutup: token tidak lagi kedaluwarsa karena sesi, kolom
  jebakan bot tidak lagi terisi otomatis oleh peramban, dan setiap kiriman —
  juga yang gagal — tercatat di **Formulir masuk** lengkap dengan isinya.
- Halaman klien: tab **Biodata awal**, **Price list**, **Biodata lengkap**
  (keluarga, adat), **Acara & dekor** (konsep dekor), Vendor, Pembayaran.
