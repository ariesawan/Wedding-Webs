<?php
/**
 * DIAGNOSTIK SEO.
 *
 * Halaman ini sengaja memisahkan dua hal yang sering dicampur:
 *
 *   1. Yang bisa diperiksa mesin — meta, skema, sitemap, kecepatan.
 *      Ini yang ditampilkan sebagai daftar centang di bawah.
 *
 *   2. Yang menentukan peringkat sebenarnya — Google Business Profile,
 *      ulasan, tautan dari situs lain, umur domain, dan konten yang
 *      benar-benar menjawab pertanyaan orang.
 *
 * Nomor 1 hanya syarat masuk. Menyelesaikan semuanya TIDAK membuat situs
 * naik ke posisi teratas; ia cuma memastikan tidak ada yang menahan.
 * Karena itu daftar tugas manual ditaruh di halaman yang sama, bukan
 * disembunyikan di dokumentasi — supaya tidak ada yang mengira pekerjaan
 * sudah selesai begitu semua centang berwarna hijau.
 */
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/seo.php';
require_once __DIR__ . '/../inc/pipeline.php';
require_once __DIR__ . '/../inc/gsc.php';

$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if (($_POST['act'] ?? '') === 'gsc_on') {
        settingSet('gsc_enabled', '1');
        flash('Sinkron Search Console dinyalakan. Sekarang tekan "Hubungkan ulang Google" — '
            . 'izin lama tidak otomatis mencakup scope baru.');
    } elseif (($_POST['act'] ?? '') === 'gsc_site') {
        settingSet('gsc_site', trim($_POST['site'] ?? ''));
        flash('Properti Search Console disimpan.');
    }
    redirect('admin/seo.php');
}

// ---------- Data Search Console ----------
$gscSitus = trim((string) setting('gsc_site'));
$gscRingkasan = $gscKunci = $gscHampir = [];
$gscDaftar = [];

if (gscAktif()) {
    $gscDaftar = gscDaftarSitus();
    if ($gscSitus === '') { $gscSitus = gscTebakSitus(); if ($gscSitus) settingSet('gsc_site', $gscSitus); }
    if ($gscSitus !== '') {
        $gscRingkasan = gscRingkas($gscSitus);
        $gscKunci     = gscPerforma($gscSitus, 'query', 28, 20);
        $gscHampir    = gscHampirNaik($gscSitus);
    }
}

$cek = [];
$tambah = function (string $judul, bool $lulus, string $pesan, string $aksi = '') use (&$cek) {
    $cek[] = ['judul' => $judul, 'lulus' => $lulus, 'pesan' => $pesan, 'aksi' => $aksi];
};

/* ---------- Identitas & verifikasi ---------- */
// Properti Domain diverifikasi lewat DNS, bukan meta tag — jadi kolom
// gsc_verification akan selamanya kosong dan pemeriksaan lama akan selalu
// gagal walau propertinya sudah sah. Kalau API sudah bisa membacanya,
// itu bukti verifikasi yang jauh lebih kuat daripada ada atau tidaknya tag.
$gsc = setting('gsc_verification');
$gscTerbukti = $gscSitus !== '';
$tambah('Verifikasi Google Search Console', $gscTerbukti || (bool) $gsc,
    $gscTerbukti
        ? 'Terverifikasi — properti ' . $gscSitus . ' terbaca lewat API.'
        : ($gsc ? 'Meta tag verifikasi terpasang di beranda.'
                : 'Belum terhubung. Tanpa ini tidak ada cara melihat kata kunci apa yang benar-benar membawa orang ke situs.'),
    ($gscTerbukti || $gsc) ? '' : 'integrasi.php');

$ga = setting('ga_measurement_id');
$tambah('Google Analytics', (bool) $ga,
    $ga ? 'Terpasang.' : 'Belum diisi. Opsional, tapi tanpa ini tidak ketahuan halaman mana yang dibaca sampai habis.',
    $ga ? '' : 'pengaturan.php');

/* ---------- Meta dasar ---------- */
$judul = setting('site_name', 'Callalily Party') . ' — Wedding Organizer Yogyakarta';
$panjangJudul = mb_strlen($judul);
$tambah('Panjang judul beranda', $panjangJudul >= 30 && $panjangJudul <= 65,
    $panjangJudul . ' karakter. Google memotong sekitar 60 — di atas itu ekornya hilang di hasil pencarian.');

$desk = (string) setting('site_description');
$panjangDesk = mb_strlen($desk);
$tambah('Deskripsi beranda', $panjangDesk >= 70 && $panjangDesk <= 165,
    $panjangDesk === 0
        ? 'Kosong. Google akan mengarang cuplikan sendiri dari isi halaman, dan beranda ini teksnya puitis — hasilnya sering tidak menjual.'
        : $panjangDesk . ' karakter. Rentang aman 70–165.',
    $panjangDesk >= 70 && $panjangDesk <= 165 ? '' : 'pengaturan.php');

$adaKataKunci = stripos($judul, 'wedding') !== false
             && (stripos($judul, 'yogyakarta') !== false || stripos($judul, 'jogja') !== false);
$tambah('Kata kunci utama ada di judul', $adaKataKunci,
    $adaKataKunci ? 'Judul beranda memuat "wedding" dan nama kota.'
                  : 'Judul beranda tidak menyebut jenis jasa dan kota sekaligus.');

/* ---------- Skema terstruktur ---------- */
$alamat = setting('address_street') && setting('address_city');
$tambah('Alamat pada skema LocalBusiness', (bool) $alamat,
    $alamat ? 'Terisi — inilah yang menghubungkan situs dengan pencarian lokal.'
            : 'Alamat kosong. Untuk jasa yang dicari per kota, ini bagian yang paling berpengaruh.',
    $alamat ? '' : 'pengaturan.php');

$telp = setting('wa_number');
$tambah('Nomor telepon pada skema', (bool) $telp,
    $telp ? 'Terisi.' : 'Kosong. Nomor yang sama harus dipakai di situs, Google Business Profile, dan Instagram.',
    $telp ? '' : 'pengaturan.php');

$sosial = array_filter([setting('ig_url'), setting('fb_url'), setting('tiktok_url'), setting('youtube_url')]);
$tambah('Tautan media sosial (sameAs)', count($sosial) >= 2,
    count($sosial) . ' tautan terisi. Ini yang dipakai Google untuk memastikan akun Instagram dan situs ini milik usaha yang sama.',
    count($sosial) >= 2 ? '' : 'pengaturan.php');

/* ---------- Konten ---------- */
$artikel = (int) (one("SELECT COUNT(*) c FROM posts WHERE status = 'published'")['c'] ?? 0);
$tambah('Artikel terbit', $artikel >= 8,
    $artikel . ' artikel. Halaman jasa saja jarang cukup — yang mendatangkan pengunjung biasanya tulisan yang menjawab pertanyaan calon klien.',
    $artikel >= 8 ? '' : 'blog.php');

$artikelLokal = (int) (one("SELECT COUNT(*) c FROM posts
    WHERE status = 'published' AND (title LIKE '%jogja%' OR title LIKE '%yogya%'
       OR content LIKE '%Yogyakarta%')")['c'] ?? 0);
$tambah('Artikel yang menyebut lokasi', $artikelLokal >= 3,
    $artikelLokal . ' artikel menyebut Yogyakarta. Untuk kata kunci berbasis kota, isi halaman harus benar-benar membicarakan kota itu — bukan cuma menempelkan namanya di judul.');

$galeri = (int) (one("SELECT COUNT(*) c FROM gallery WHERE is_published = 1")['c'] ?? 0);
$tambah('Foto galeri terbit', $galeri >= 12,
    $galeri . ' foto. Sudah didaftarkan ke sitemap gambar, jadi bisa muncul di Google Images.',
    $galeri >= 12 ? '' : 'galeri.php');

$event = (int) (one("SELECT COUNT(*) c FROM events WHERE is_published = 1")['c'] ?? 0);
$tambah('Event terbit', $event >= 3,
    $event . ' event tampil publik. Tiap acara nyata jadi satu halaman dengan skema Event tersendiri.',
    $event >= 3 ? '' : 'event.php');

$seoLemah = all("SELECT id, title, seo_score FROM posts
                 WHERE status = 'published' AND seo_score < 70 ORDER BY seo_score LIMIT 5");
$tambah('Skor SEO artikel', count($seoLemah) === 0,
    count($seoLemah) === 0 ? 'Semua artikel di atas 70.'
                           : count($seoLemah) . ' artikel di bawah 70.');

/* ---------- Teknis ---------- */
$adaEn = is_file(__DIR__ . '/../inc/lang/en.php');
$jmlEn = $adaEn ? count(require __DIR__ . '/../inc/lang/en.php') : 0;
$tambah('Versi Inggris beranda', $jmlEn >= 20,
    $jmlEn . ' kalimat sudah punya padanan Inggris. Kalimat yang belum diterjemahkan tampil dalam bahasa Indonesia — halaman tetap utuh, tapi campur bahasa menurunkan kualitasnya di mata pembaca maupun mesin pencari.',
    $jmlEn >= 20 ? '' : '');

$https = setting('site_url') ? str_starts_with(setting('site_url'), 'https://') : true;
$tambah('HTTPS', $https, $https ? 'Situs dipaksa ke HTTPS lewat .htaccess.' : 'BASE_URL masih http://.');

$lulus = count(array_filter($cek, fn($c) => $c['lulus']));
$total = count($cek);
$persen = $total ? round($lulus / $total * 100) : 0;

adminHead('SEO', 'seo');
pageHead('SEO & Search Console',
         'Bagian yang bisa diperiksa mesin ada di atas. Yang menentukan peringkat sebenarnya ada di bawah.');
?>

<?php if (!gscAktif()): ?>
  <div class="card" style="border-color:var(--ember-line)">
    <h2>Sambungkan Search Console</h2>
    <p class="sub" style="margin-bottom:14px">Panel ini sekarang bisa membaca data pencarian
      langsung dari Google — memakai izin OAuth yang sama dengan Calendar, jadi tidak ada
      kredensial kedua yang perlu diurus.</p>
    <form method="post" style="display:inline"><?= csrfField() ?>
      <button class="btn solid" name="act" value="gsc_on">Nyalakan sinkron Search Console</button>
    </form>
    <?php if (setting('gsc_enabled') === '1'): ?>
      <a class="btn ghost" href="integrasi.php" style="margin-left:8px">Hubungkan ulang Google →</a>
      <p class="hint" style="margin-top:10px">Sudah dinyalakan, tapi izinnya belum mencakup Search Console.
        Google tidak menambahkan scope baru ke token lama — persetujuannya harus diberikan sekali lagi.</p>
    <?php endif; ?>
  </div>
<?php elseif ($gscSitus === ''): ?>
  <div class="card" style="border-color:var(--rose-line)">
    <h2>Properti belum dipilih</h2>
    <?php if (!$gscDaftar): ?>
      <p class="sub">Akun Google yang terhubung tidak punya properti Search Console yang terverifikasi.
        Pastikan akun yang sama dengan yang dipakai mendaftarkan callalily.party.</p>
    <?php else: ?>
      <form method="post" style="display:flex;gap:9px;align-items:flex-end;flex-wrap:wrap">
        <?= csrfField() ?><input type="hidden" name="act" value="gsc_site">
        <div class="field" style="margin:0;flex:1;min-width:240px">
          <label for="gs">Properti</label>
          <select id="gs" name="site">
            <?php foreach ($gscDaftar as $d): ?>
              <option value="<?= e($d['url']) ?>"><?= e($d['url']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn solid">Simpan</button>
      </form>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="card">
    <h2>Data pencarian nyata</h2>
    <p class="sub"><?= e($gscSitus) ?> · 28 hari sampai
      <?= !empty($gscRingkasan['sampai']) ? e(tanggalID($gscRingkasan['sampai'])) : '—' ?>.
      Search Console selalu tertinggal 2–3 hari, jadi hari ini sengaja tidak dihitung.</p>

    <?php if (empty($gscRingkasan) || !empty($gscRingkasan['kosong'])): ?>
      <div class="empty" style="padding:26px 16px">
        <p>Belum ada data</p>
        <span>Properti baru butuh beberapa hari sampai Google mengumpulkan data pertamanya.
          Sementara itu, kirim sitemap dan minta pengindeksan beranda lewat URL Inspection.</span>
      </div>
    <?php else: ?>
      <div class="board" style="margin-bottom:18px">
        <div class="tile"><span class="n"><?= (int) $gscRingkasan['klik'] ?></span><span class="l">Klik</span></div>
        <div class="tile"><span class="n"><?= number_format((int) $gscRingkasan['tayang'], 0, ',', '.') ?></span><span class="l">Tayang</span></div>
        <div class="tile"><span class="n"><?= $gscRingkasan['ctr'] ?>%</span><span class="l">CTR</span></div>
        <div class="tile"><span class="n"><?= $gscRingkasan['posisi'] ?></span><span class="l">Posisi rata-rata</span></div>
      </div>

      <div class="grid g2" style="align-items:start">
        <div>
          <span class="lab" style="display:block;margin-bottom:9px">Kata kunci teratas</span>
          <?php if (!$gscKunci || isset($gscKunci['error'])): ?>
            <p class="muted" style="font-size:13px"><?= e($gscKunci['error'] ?? 'Belum ada.') ?></p>
          <?php else: ?>
            <table class="tbl">
              <thead><tr><th>Kata kunci</th><th class="num">Klik</th><th class="num">Tayang</th><th class="num">Posisi</th></tr></thead>
              <?php foreach (array_slice($gscKunci, 0, 12) as $k): ?>
                <tr>
                  <td data-l="Kata kunci"><?= e($k['kunci']) ?></td>
                  <td data-l="Klik" class="num"><?= $k['klik'] ?></td>
                  <td data-l="Tayang" class="num muted"><?= $k['tayang'] ?></td>
                  <td data-l="Posisi" class="num"
                      style="color:<?= $k['posisi'] <= 10 ? 'var(--sage-text)' : 'var(--ivory-60)' ?>"><?= $k['posisi'] ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          <?php endif; ?>
        </div>

        <div>
          <span class="lab" style="display:block;margin-bottom:9px">Hampir masuk halaman satu</span>
          <p class="hint" style="margin:0 0 9px">Posisi 8–20. Ini tempat paling menguntungkan untuk
            dikerjakan: Google sudah menganggap situs ini relevan, tapi orang belum melihatnya.
            Menaikkan satu kata kunci dari 12 ke 8 hampir selalu lebih murah daripada mengejar
            kata kunci baru dari nol.</p>
          <?php if (!$gscHampir || isset($gscHampir['error'])): ?>
            <p class="muted" style="font-size:13px">Belum ada kata kunci di rentang itu.</p>
          <?php else: foreach ($gscHampir as $h): ?>
            <div style="display:flex;justify-content:space-between;gap:10px;padding:7px 0;
                        border-bottom:1px solid var(--ivory-07);font-size:13px">
              <span style="color:var(--ivory);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($h['kunci']) ?></span>
              <span class="mono" style="flex:0 0 auto;color:var(--ember)">#<?= $h['posisi'] ?>
                <span style="color:var(--ivory-38)">· <?= $h['tayang'] ?>×</span></span>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h2>Kesiapan teknis</h2>
  <div style="display:flex;align-items:center;gap:14px;margin:12px 0 18px">
    <div style="flex:1;height:11px;background:var(--ivory-07);border-radius:6px;overflow:hidden">
      <div style="height:100%;width:<?= $persen ?>%;border-radius:6px;
                  background:<?= $persen >= 80 ? 'var(--sage)' : ($persen >= 50 ? 'var(--ember)' : 'var(--rose)') ?>"></div>
    </div>
    <span class="mono" style="font-size:14px;color:var(--ivory)"><?= $lulus ?>/<?= $total ?></span>
  </div>

  <table class="tbl">
    <?php foreach ($cek as $c): ?>
      <tr>
        <td style="width:30px;padding:11px 0;font-size:16px;vertical-align:top;
                   color:<?= $c['lulus'] ? 'var(--sage-text)' : 'var(--rose-text)' ?>">
          <?= $c['lulus'] ? '✓' : '○' ?></td>
        <td data-l="Pemeriksaan">
          <b style="color:var(--ivory)"><?= e($c['judul']) ?></b><br>
          <span class="muted" style="font-size:12.7px"><?= e($c['pesan']) ?></span>
        </td>
        <td class="actions">
          <?php if ($c['aksi']): ?><a class="btn sm ghost" href="<?= e($c['aksi']) ?>">Perbaiki</a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="card">
  <h2>Rencana 90 hari</h2>
  <p class="sub">Diurutkan menurut dampak, bukan menurut mudahnya. Nomor 1 dan 2 menyumbang
    lebih besar daripada seluruh checklist teknis di atas digabung.</p>

  <?php
  $artikelTerbit = (int) (one("SELECT COUNT(*) c FROM posts WHERE status='published'")['c'] ?? 0);
  $rencana = [
    [
      'w' => 'Minggu 1',
      'j' => 'Google Business Profile',
      'd' => 'Untuk pencarian "wedding organizer jogja", tiga hasil teratas adalah peta — dan yang '
           . 'muncul di peta itu profil bisnis, bukan situs. Selama profilnya belum ada, situs '
           . 'sebagus apa pun tidak bisa merebut posisi itu. Daftar di business.google.com dengan '
           . 'alamat, jam buka, foto, dan nomor yang PERSIS sama dengan yang ada di situs — beda '
           . 'satu digit saja sudah cukup membuat Google ragu keduanya usaha yang sama.',
      'selesai' => false,
    ],
    [
      'w' => 'Minggu 1–12',
      'j' => 'Ulasan Google, terus-menerus',
      'd' => 'Faktor terkuat pada hasil peta, dan yang paling sering diabaikan. Minta ke tiap '
           . 'pasangan yang acaranya baru selesai — checklist H+3 sudah memuat langkah minta '
           . 'testimoni, tinggal ditambahi tautan ulasan Google. Sepuluh ulasan dalam tiga bulan '
           . 'lebih berpengaruh daripada tiga puluh artikel.',
      'selesai' => false,
    ],
    [
      'w' => 'Minggu 2–10',
      'j' => 'Delapan artikel yang menjawab pertanyaan nyata',
      'd' => 'Sekarang ada ' . $artikelTerbit . ' artikel. Halaman jasa jarang menang sendirian; '
           . 'yang mendatangkan pengunjung adalah tulisan yang menjawab apa yang benar-benar '
           . 'diketik orang. Satu artikel per minggu sudah cukup — konsisten lebih penting '
           . 'daripada banyak sekaligus.',
      'selesai' => $artikelTerbit >= 8,
    ],
    [
      'w' => 'Minggu 3–12',
      'j' => 'Tautan dari vendor dan venue',
      'd' => 'Minta venue dan vendor yang sudah Anda pakai mencantumkan Callalily di halaman '
           . '"rekomendasi WO" mereka. Satu tautan dari venue besar di Yogyakarta bernilai lebih '
           . 'dari puluhan direktori umum — dan ini bisa diminta sambil menutup kerja sama, '
           . 'bukan pekerjaan tambahan.',
      'selesai' => false,
    ],
    [
      'w' => 'Minggu 4 dan seterusnya',
      'j' => 'Berhenti menebak',
      'd' => 'Setelah 3–4 minggu, data Search Console mulai terisi. Buka daftar '
           . '"hampir masuk halaman satu" di atas dan kerjakan yang ada di posisi 8–20. '
           . 'Dari titik itu semua keputusan berbasis kata kunci yang nyata diketik orang, '
           . 'bukan dugaan.',
      'selesai' => false,
    ],
  ];
  ?>

  <div style="display:grid;gap:2px">
    <?php foreach ($rencana as $i => $r): ?>
      <div style="display:flex;gap:14px;padding:14px 0;border-bottom:1px solid var(--ivory-07)">
        <span style="flex:0 0 26px;height:26px;border-radius:50%;display:grid;place-items:center;
                     font-family:var(--mono);font-size:11.5px;
                     background:<?= $r['selesai'] ? 'var(--sage-soft)' : 'var(--ember-soft)' ?>;
                     color:<?= $r['selesai'] ? 'var(--sage-text)' : 'var(--ember)' ?>">
          <?= $r['selesai'] ? '✓' : $i + 1 ?></span>
        <div style="min-width:0">
          <div style="display:flex;gap:9px;align-items:baseline;flex-wrap:wrap">
            <b style="color:var(--ivory);font-size:14px"><?= e($r['j']) ?></b>
            <span class="mono" style="font-size:10.5px;color:var(--ivory-38);letter-spacing:.08em">
              <?= e(mb_strtoupper($r['w'])) ?></span>
          </div>
          <p style="font-size:13.2px;color:var(--ivory-60);margin:5px 0 0;line-height:1.65"><?= e($r['d']) ?></p>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($artikelTerbit < 8): ?>
    <span class="lab" style="display:block;margin:18px 0 8px">Judul yang bisa langsung dikerjakan</span>
    <p class="hint" style="margin:0 0 10px">Dipilih karena tiga alasan sekaligus: orang benar-benar
      mencarinya, pesaing di Yogyakarta jarang menjawabnya dengan tuntas, dan Anda punya
      pengalaman nyatanya.</p>
    <div style="display:grid;gap:7px">
      <?php foreach ([
        'Biaya wedding organizer di Jogja: rincian jujur dari yang mengerjakan',
        'Beda wedding organizer, wedding planner, dan MUA — dan kapan butuh yang mana',
        'Urutan prosesi panggih adat Jawa lengkap beserta maknanya',
        'Berapa lama sebelum hari-H harus mulai cari WO?',
        'Standing party atau sitting arrangement? Cara memilih menurut jumlah tamu',
        'Rincian yang paling sering terlupa saat menyusun rundown pernikahan',
        'Venue outdoor di Jogja: yang perlu disiapkan kalau hujan',
        'Checklist H-90 sampai hari-H untuk pengantin yang mengurus sendiri',
      ] as $judul): ?>
        <div style="display:flex;gap:9px;align-items:flex-start;font-size:13.2px;
                    padding:8px 11px;border:1px solid var(--ivory-07);border-radius:9px">
          <span style="color:var(--ember);flex:0 0 auto">·</span>
          <span style="color:var(--ivory-60)"><?= e($judul) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="hint" style="margin:10px 0 0">Judul pertama biasanya yang paling banyak dibaca —
      dan paling jarang dijawab pesaing, karena banyak yang enggan menyebut angka.</p>
  <?php endif; ?>
</div>

<div class="card" style="border-color:rgba(233,168,92,.4)">
  <h2>Yang tidak bisa dikerjakan dari panel ini</h2>
  <p class="sub">Empat hal berikut menentukan peringkat jauh lebih besar daripada seluruh daftar di atas,
     dan semuanya butuh tindakan manual di luar situs.</p>

  <ol style="display:grid;gap:16px;margin:14px 0 0;padding-left:20px;font-size:13.6px;color:var(--ivory-60)">
    <li>
      <b style="color:var(--ivory)">Google Business Profile</b><br>
      Untuk pencarian seperti "wedding organizer jogja", tiga hasil teratas biasanya adalah peta —
      dan yang muncul di peta bukan situs, melainkan profil bisnis. Daftarkan di
      <span class="mono" style="font-size:12.5px">business.google.com</span> dengan alamat, jam buka,
      foto, dan nomor yang <b>persis sama</b> dengan yang ada di situs. Ketidakcocokan satu digit nomor
      saja sudah cukup membuat Google ragu keduanya usaha yang sama.
    </li>
    <li>
      <b style="color:var(--ivory)">Ulasan</b><br>
      Jumlah dan kesegaran ulasan di Google Business Profile adalah faktor terkuat pada hasil peta.
      Minta ulasan ke pasangan yang acaranya baru selesai — checklist H+3 sudah memuat langkah minta
      testimoni, tinggal ditambahi tautan ulasan Google.
    </li>
    <li>
      <b style="color:var(--ivory)">Tautan dari situs lain</b><br>
      Vendor yang Anda pakai, venue, dan media pernikahan lokal. Satu tautan dari situs venue besar di
      Yogyakarta bernilai lebih dari puluhan direktori umum.
    </li>
    <li>
      <b style="color:var(--ivory)">Waktu</b><br>
      Domain baru butuh beberapa bulan sebelum diperlakukan setara oleh Google, seberapa pun rapi
      teknisnya. Tidak ada jalan pintas untuk bagian ini.
    </li>
  </ol>
</div>

<div class="card">
  <h2>Langkah pendaftaran</h2>
  <ol style="display:grid;gap:11px;margin:10px 0 0;padding-left:20px;font-size:13.6px;color:var(--ivory-60)">
    <li>Buka <span class="mono" style="font-size:12.5px">search.google.com/search-console</span>, tambahkan properti
        <b>Domain</b> (bukan awalan URL) untuk <span class="mono" style="font-size:12.5px">callalily.party</span>.</li>
    <li>Verifikasi lewat data DNS TXT di DirectAdmin, atau salin nilai <span class="mono" style="font-size:12.5px">content=</span>
        dari meta tag ke <a href="pengaturan.php" style="color:var(--ember)">Pengaturan</a>.</li>
    <li>Kirim sitemap: masukkan <span class="mono" style="font-size:12.5px">sitemap.xml</span> di menu Sitemaps.
        <a href="<?= e(url('sitemap.xml')) ?>" target="_blank" rel="noopener" style="color:var(--ember)">Lihat sitemap ↗</a></li>
    <li>Pakai <b>URL Inspection</b> untuk beranda dan minta pengindeksan. Sisanya menyusul sendiri.</li>
    <li>Tunggu 3–7 hari, lalu buka laporan <b>Performa</b> untuk melihat kata kunci apa yang sebenarnya
        membawa orang ke situs. Sering kali berbeda jauh dari yang diduga — dan itulah yang seharusnya
        jadi bahan artikel berikutnya.</li>
  </ol>
</div>

<?php if ($seoLemah): ?>
<div class="card">
  <h2>Artikel yang skornya rendah</h2>
  <table class="tbl">
    <?php foreach ($seoLemah as $p): ?>
      <tr>
        <td data-l="Artikel"><b><?= e(mb_strimwidth($p['title'], 0, 60, '…')) ?></b></td>
        <td data-l="Skor" class="num" style="color:var(--rose-text)"><?= (int) $p['seo_score'] ?>/100</td>
        <td class="actions"><a class="btn sm ghost" href="blog.php?edit=<?= (int) $p['id'] ?>">Perbaiki</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<?php adminFoot();
