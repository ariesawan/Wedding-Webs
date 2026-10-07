# Callalily CMS v24 — pemasangan di DirectAdmin

Tiga hal baru di paket ini:

1. **Ringkasan (dashboard admin) baru** — berbeda per peran. Admin early dan
   admin office mendapat **antrean "kerjakan dari atas"** dengan tombol satu
   ketuk; owner mendapat gambaran bisnis (uang masuk, keputusan yang menunggu,
   antrean tim, arus kas, tanggal terisi, corong).
2. **Termin pembayaran** yang sungguhan — bayar sebagian / cicil, kwitansi
   bernomor (PDF), tagihan & pengingat lewat WhatsApp.
3. **Dashboard pengantin** — halaman pribadi untuk klien setelah DP: hitung
   mundur, pembayaran & kwitansi, jadwal meeting, isian keluarga & adat,
   referensi dekor, vendor, dan dokumen.

Alur v23 tetap: admin early (biodata awal → price list → cocok → DP 30%) →
admin office (biodata lengkap & keluarga, venue, dekor, vendor, termin,
meeting → persiapan → hari-H).

## 1. Backup dulu (wajib)

DirectAdmin → **File Manager** → pilih folder `public_html` → **Compress** (zip),
lalu unduh. Database: **phpMyAdmin** → pilih `tuguasri_wo` → **Export** → Go.

Simpan backup DI LUAR `public_html`.

## 2. Unggah & ekstrak

1. DirectAdmin → File Manager → buka `domains/callalily.party/public_html/`
2. **Upload** `callalily-v24.zip` ke folder itu.
3. Klik kanan zip → **Extract** → tujuan `public_html` (folder yang sama),
   centang **overwrite / timpa** bila ditanya.
4. Hapus `callalily-v24.zip` dari `public_html` setelah selesai.

Yang TIDAK ikut di paket (aman, tidak tertimpa): `inc/config.php`, folder
`foto/`, `video/`, `uploads/`.

Bisa langsung dipasang di atas v22 maupun v23.

## 3. Database — biasanya tidak perlu apa-apa

Buka panel (`/admin/`) sekali sebagai owner. Yang belum ada ditambahkan
**otomatis**: kolom cicilan termin, tabel penerimaan (kwitansi), kolom
dashboard pengantin, dan satu indeks untuk Ringkasan. Termin lama yang sudah
lunas otomatis menjadi satu penerimaan (tanpa nomor kwitansi, tidak dikirim
ke siapa pun).

Kalau muncul kotak kuning *"Struktur database belum bisa diperbarui otomatis"*:
phpMyAdmin → `tuguasri_wo` → tab **SQL** → tempel isi `db/migration-v24.sql`
→ Go. Aman dijalankan berulang (sudah termasuk isi v22 dan v23). Baris
terakhir hasilnya harus: `4 | 2 | 1 | 1 | 7 | 1 | 4 | 1 | 3 | 1`.

## 4. Isi sekali setelah pasang

Panel → **Pengaturan**:

| Kartu | Isian | Kenapa |
|---|---|---|
| Price list & pembayaran | nama bank, no. rekening, atas nama | Tercetak di PDF, teks tagihan, dan dashboard pengantin |
| Price list & pembayaran | WA admin early / admin office | Tombol "Tanya admin" di Ringkasan owner, PIC di PDF, kontak di dashboard pengantin |
| Termin pembayaran | susunan termin (DP 30% + termin + pelunasan) dan tenggat DP | Dipakai saat klien cocok. Klien lama tidak ikut berubah |
| Pengingat pembayaran | kota & penanda tangan kwitansi | Tercetak di kwitansi PDF |
| Pengingat pembayaran | **Kirim pengingat otomatis** | **Bawaan MATI.** Nyalakan setelah yakin gateway WhatsApp (Fonnte) jalan dan cron terpasang |
| Dashboard pengantin | aktif, kunci formulir mulai H-…, kalimat sambutan | Sakelar utama — mematikan semua tautan sekaligus bila perlu |

Panel → **Paket & price list**: isi **harga** tiap paket (kalau belum).

## 5. Cron (sekali saja)

DirectAdmin → **Cron Jobs**, satu baris, sekali sehari:

```
0 8 * * * /usr/local/bin/php /home/USER/domains/callalily.party/public_html/cron/harian.php >> /home/USER/calla.log 2>&1
```

Ganti `USER` dengan nama akun. Isinya: pengingat H-1 pertemuan, tahap
otomatis (persiapan → hari-H → selesai), pengingat pembayaran (bila
dinyalakan), dan spreadsheet. Pengaturan → *Pengingat pembayaran* menampilkan
**kapan cron terakhir berjalan** — kalau tertulis "belum pernah tercatat"
sehari setelah pasang, cron-nya belum jalan.

## 6. Cek cepat setelah pasang

1. Masuk sebagai **admin early** → Ringkasan menampilkan antrean bertingkat
   *Mendesak / Hari ini / Segera*. Ketuk chip **Menunggu DP** → daftar
   tersaring tanpa memuat ulang.
2. Pada baris klien Menunggu DP → **DP masuk ✓** → isi jumlah → kwitansi
   tercatat; kalau DP lunas, klien pindah ke admin office.
3. Masuk sebagai **admin office** → kartu **Hari-H berikutnya** di atas,
   antrean termin & checklist di bawahnya.
4. Buka klien deal → tab **Pembayaran** → **Catat pembayaran masuk** (coba
   sebagian) → **Kwitansi** terbuka sebagai PDF.
5. Kartu **Dashboard pengantin** di tab Ikhtisar → **Lihat sebagai klien** →
   halaman `/p/…` terbuka. Kirim tautannya ke WhatsApp klien dari tombol yang
   sama.
6. Masuk sebagai **owner** → Ringkasan menampilkan angka bulan ini, *Perlu
   keputusanmu*, *Antrean tim* (dengan **Lihat antrean early/office**), *Arus
   kas*, *Tanggal terisi*, dan *Corong 12 bulan*.

## 7. Yang berubah

### Ringkasan (dashboard admin)
- **Admin early**: satu antrean berurutan — formulir yang gagal jadi klien,
  konsultasi yang belum dicatat hasilnya, DP lewat tempo / jatuh tempo,
  prospek yang menunggu price list, price list yang belum dijawab, tawaran
  klien, tindak lanjut. Tiap baris menulis **kenapa** ia ada dan memberi
  tombol yang langsung bekerja: *DP masuk ✓*, *Tagih DP*, *Siapkan price
  list*, *Klien cocok → tagih DP*, *Lanjut / Masih dipikir*, *Jadikan klien*,
  WhatsApp dengan teks siap kirim, dan **Lainnya ▾ → Tunda** (besok / 2 hari /
  Senin). Di samping: agenda 7 hari (tombol Gabung Zoom/Meet, Peta,
  Telepon, Ingatkan via WA), tanggal yang sudah terisi, dan empat angka.
- **Admin office**: kartu **Hari-H berikutnya** (checklist & pembayaran),
  antrean termin lewat tempo / jatuh tempo (*Lunas ✓*, *Kirim tagihan*),
  langkah checklist (*✓ centang langsung*), penyusunan deal (6 titik kesiapan:
  biodata, venue, dekor, vendor, termin, meeting), agenda + tagihan minggu
  ini, hari-H 30 hari, dan tabel **Kesiapan semua acara**.
- **Owner**: angka bulan ini dibanding hari yang sama bulan lalu, **Perlu
  keputusanmu** (tawar-menawar, klien yang ragu setelah DP, DP/termin yang
  macet, pertemuan & formulir yang tertinggal), **Antrean tim** + pratinjau
  persis apa yang dilihat tiap admin, minggu ini, **arus kas** (uang diterima
  vs dijadwalkan 6 bulan ke depan), **tanggal terisi**, **corong** dengan
  kecepatan kirim price list, dan *Perlu perhatian* (integrasi & situs).
- Setiap tombol memakai aturan yang sama seperti di halaman klien (peran,
  konfirmasi, penjaga catat ganda) lalu kembali ke baris berikutnya di
  Ringkasan.

### Termin pembayaran
- Uang masuk dicatat per transfer, boleh **sebagian**; kelebihan otomatis
  masuk ke termin berikutnya. Setiap transfer mendapat **kwitansi bernomor**
  (`KW/2026/0001`) yang bisa dikirim ke WhatsApp klien sebagai PDF.
- Salah catat? **Batalkan penerimaan** (owner, atau pencatatnya dalam 24 jam)
  — termin kembali seperti semula, kwitansi tercap *DIBATALKAN*.
- Nilai kontrak berubah → termin yang belum lunas menyesuaikan; tanggal
  pernikahan bergeser → tanggal termin ikut bergeser.
- DP dikonfirmasi admin early / owner; termin setelah deal oleh admin office /
  owner. Kalau DP lunas lewat jalan lain (nominal DP diubah, kontrak
  disesuaikan), klien otomatis diserahkan ke admin office.
- Klien yang **sudah punya kwitansi tidak bisa dihapus** — tandai *Tidak
  jadi* saja. Nomor kwitansi tidak pernah dipakai ulang.
- Kwitansi lama tidak berubah isinya ketika pembayaran berikutnya masuk
  (tetap tertulis *sebagian*, tidak tiba-tiba dicap *LUNAS*).
- Ganti paket sebelum DP masuk → DP dan termin ikut nilai baru.

### Dashboard pengantin
- Tautan pribadi `callalily.party/p/…` (acak 32 karakter, tidak diindeks).
  Aktif setelah DP lunas; mati otomatis bila klien batal.
- Klien bisa mengisi data keluarga, prosesi adat, dan referensi dekor —
  dikunci mulai H-30 (bisa diatur). Admin mendapat pemberitahuan dan melihat
  isiannya di tab Biodata lengkap / Acara & dekor.
- Nomor telepon & alamat disamarkan; tidak ada nama staf atau catatan internal
  yang tampil.

## 8. Catatan

- **Zoom / Google Calendar**: periksa Panel → Integrasi. Bila Google meminta
  *hubungkan ulang*, tekan tombolnya sekali.
- Tanpa gateway WhatsApp, semua tombol WhatsApp tetap jalan lewat
  `wa.me` (membuka WhatsApp dengan teks siap kirim); hanya pengiriman otomatis
  (kwitansi, tagihan, pengingat) yang butuh gateway.

## 9. Bersih-bersih (keamanan)

Hapus dari `public_html` bila masih ada: `admin/setup.php`, `cek.php`,
`tools/`, salinan `*.bak-…`, `admin/error_log`, zip lama, dan berkas
`BACA-DULU-v22.md` / `BACA-DULU-v23.md` / `db/migration-v23.sql` (sudah
digantikan). Lalu kosongkan **Trash** di File Manager.
