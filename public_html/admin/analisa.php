<?php
/**
 * ANALISA — kenapa gugur, kenapa berhasil.
 *
 * Catatan owner menyebut "analyze penyebab" dua kali di jalur gagal
 * (setelah PL dan setelah penawaran) dan sekali di ujung acara. Ketiganya
 * ditampung di sini, dan sengaja TIDAK dipisah jadi laporan menang vs kalah.
 *
 * Alasannya: sebab kalah saja tidak bisa ditindaklanjuti. Kalau sepuluh
 * klien gugur karena "harga di atas ekspektasi", itu belum berarti harga
 * harus turun — mungkin lima belas klien lain justru deal dengan harga sama
 * dan menyebut portofolio sebagai alasannya. Yang bisa ditindaklanjuti
 * adalah selisih antara keduanya, dan itu hanya kelihatan kalau disandingkan.
 */
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/pipeline.php';
require_once __DIR__ . '/../inc/grafik.php';

$user = requireLogin();

/* ============================================================
   SIMPAN CATATAN ANALISA
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    try {
        $cid   = (int) ($_POST['client_id'] ?? 0);
        $momen = $_POST['momen'] ?? '';
        $hasil = $_POST['hasil'] ?? '';

        if (!$cid) throw new RuntimeException('Klien tidak dipilih.');
        if (!in_array($momen, ['pricelist','penawaran','deal','pascaacara'], true))
            throw new RuntimeException('Momen tidak dikenal.');
        if (!in_array($hasil, ['gagal','berhasil'], true))
            throw new RuntimeException('Hasil tidak dikenal.');

        $sebab = trim($_POST['sebab'] ?? '');
        if ($sebab === '') throw new RuntimeException('Sebab wajib dipilih — tanpa itu catatannya tidak bisa dihitung.');

        $c = one("SELECT created_at, deal_value FROM clients WHERE id = ?", [$cid]);
        $hari = $c ? max(0, (int) ((time() - strtotime($c['created_at'])) / 86400)) : null;

        q("INSERT INTO client_analisa
            (client_id, momen, hasil, sebab, sebab_lain, pesaing, selisih,
             nilai, skor, catatan, hari_proses, created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
          [$cid, $momen, $hasil, $sebab,
           mb_substr(trim($_POST['sebab_lain'] ?? ''), 0, 160),
           mb_substr(trim($_POST['pesaing'] ?? ''), 0, 120),
           ($_POST['selisih'] ?? '') !== '' ? (float) preg_replace('/\D/', '', $_POST['selisih']) : null,
           $hasil === 'berhasil' ? ($c['deal_value'] ?? null) : null,
           ($_POST['skor'] ?? '') !== '' ? (int) $_POST['skor'] : null,
           trim($_POST['catatan'] ?? ''), $hari, $user['id']]);

        clientLog($cid, 'catatan',
                  'Analisa ' . ($hasil === 'gagal' ? 'kegagalan' : 'keberhasilan'),
                  trim($_POST['catatan'] ?? ''), $user['id']);

        flash('Analisa tersimpan.');
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    redirect('admin/analisa.php' . (!empty($_POST['kembali']) ? '?klien=' . (int) $_POST['client_id'] : ''));
}

$klienId = (int) ($_GET['klien'] ?? 0);
$sebabList = all("SELECT * FROM analisa_sebab WHERE is_active = 1 ORDER BY hasil, urutan");
$sebabPeta = [];
foreach ($sebabList as $sb) $sebabPeta[$sb['kode']] = $sb['label'];

/* ============================================================
   FORMULIR UNTUK SATU KLIEN
   ============================================================ */
if ($klienId) {
    $k = one("SELECT * FROM clients WHERE id = ?", [$klienId]);
    if (!$k) { flash('Klien tidak ditemukan.', 'err'); redirect('admin/analisa.php'); }

    $riwayat = all("SELECT * FROM client_analisa WHERE client_id = ? ORDER BY created_at DESC", [$klienId]);
    $nama = trim($k['name'] . ($k['partner_name'] ? ' & ' . $k['partner_name'] : ''));

    // Tebak momen dan hasil dari tahap sekarang, supaya tidak perlu mengisi
    // hal yang sudah diketahui sistem.
    $tebakHasil = in_array($k['stage'], ['deal','persiapan','harih','selesai'], true) ? 'berhasil' : 'gagal';
    $tebakMomen = match ($k['stage']) {
        'pricelist'  => 'pricelist',
        'selesai'    => 'pascaacara',
        'deal', 'persiapan', 'harih' => 'deal',
        default      => ($k['stage_batal'] ?: 'penawaran'),
    };

    adminHead('Analisa · ' . $nama, 'analisa');
    pageHead('Analisa — ' . $nama,
             'Tahap sekarang: ' . stageLabel($k['stage']) . '. Catat sebabnya selagi ingatannya masih segar.',
             '<a class="btn ghost" href="klien.php?id=' . $klienId . '">← Ke halaman klien</a>');
    ?>
    <form method="post" class="card">
      <?= csrfField() ?>
      <input type="hidden" name="client_id" value="<?= $klienId ?>">
      <input type="hidden" name="kembali" value="1">

      <div class="row c2">
        <div class="field"><label for="ha">Hasil</label>
          <select id="ha" name="hasil" onchange="pilihSebab(this.value)">
            <option value="gagal"    <?= $tebakHasil === 'gagal' ? 'selected' : '' ?>>Gugur — tidak berlanjut</option>
            <option value="berhasil" <?= $tebakHasil === 'berhasil' ? 'selected' : '' ?>>Berhasil — lanjut / deal</option>
          </select></div>
        <div class="field"><label for="mo">Terjadi di titik mana</label>
          <select id="mo" name="momen">
            <?php foreach (['pricelist'=>'Setelah price list dikirim','penawaran'=>'Setelah penawaran dikirim',
                            'deal'=>'Saat kontrak / deal','pascaacara'=>'Setelah acara selesai'] as $k2=>$v): ?>
              <option value="<?= $k2 ?>" <?= $tebakMomen === $k2 ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>

      <div class="field"><label for="sb">Sebab utama</label>
        <select id="sb" name="sebab" required>
          <?php foreach ($sebabList as $sb): ?>
            <option value="<?= e($sb['kode']) ?>" data-hasil="<?= e($sb['hasil']) ?>"><?= e($sb['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="hint" style="margin:6px 0 0">Pilih satu yang paling menentukan. Nuansanya taruh di catatan.</p>
      </div>

      <div class="field"><label for="sl">Kalau "lainnya", sebutkan</label>
        <input type="text" id="sl" name="sebab_lain" placeholder="Sebab yang belum ada di daftar"></div>

      <div class="row c2" id="blokKalah">
        <div class="field"><label for="pe">Kalah dari siapa</label>
          <input type="text" id="pe" name="pesaing" placeholder="Nama WO lain, kalau diketahui"></div>
        <div class="field"><label for="se">Selisih harga</label>
          <input type="text" id="se" name="selisih" data-rp inputmode="numeric" placeholder="Berapa lebih murah pesaingnya"></div>
      </div>

      <div class="field" id="blokSkor" style="display:none"><label for="sk">Kepuasan klien (1–5)</label>
        <select id="sk" name="skor">
          <option value="">— belum dinilai —</option>
          <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= str_repeat('★', $i) ?></option><?php endfor; ?>
        </select></div>

      <div class="field"><label for="ct">Catatan</label>
        <textarea id="ct" name="catatan" rows="3" placeholder="Apa yang sebenarnya terjadi. Tulis apa adanya — ini dibaca lagi enam bulan lagi."></textarea></div>

      <button class="btn solid" type="submit">Simpan analisa</button>
    </form>

    <?php if ($riwayat): ?>
    <div class="card">
      <h2>Analisa sebelumnya</h2>
      <table class="tbl">
        <?php foreach ($riwayat as $r): ?>
          <tr>
            <td data-l="Kapan" style="width:130px" class="num"><?= tanggalID($r['created_at']) ?></td>
            <td data-l="Hasil">
              <b style="color:<?= $r['hasil'] === 'gagal' ? 'var(--rose-text)' : 'var(--sage-text)' ?>">
                <?= $r['hasil'] === 'gagal' ? 'Gugur' : 'Berhasil' ?></b> ·
              <?= e($sebabPeta[$r['sebab']] ?? $r['sebab']) ?>
              <?php if ($r['sebab_lain']): ?> — <?= e($r['sebab_lain']) ?><?php endif; ?>
              <?php if ($r['catatan']): ?><br><span class="muted"><?= nl2br(e($r['catatan'])) ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>

    <script>
    function pilihSebab(h){
      // Sebab menang dan sebab kalah tidak boleh tercampur — memilih
      // "harga di atas ekspektasi" pada klien yang deal akan mengotori laporan.
      const s = document.getElementById('sb');
      let pertama = null;
      for (const o of s.options){
        const cocok = o.dataset.hasil === h;
        o.hidden = !cocok;
        o.disabled = !cocok;
        if (cocok && !pertama) pertama = o;
      }
      if (pertama && s.selectedOptions[0]?.hidden) pertama.selected = true;
      document.getElementById('blokKalah').style.display = h === 'gagal' ? '' : 'none';
      document.getElementById('blokSkor').style.display  = h === 'berhasil' ? '' : 'none';
    }
    pilihSebab(document.getElementById('ha').value);
    </script>
    <?php
    adminFoot();
    exit;
}

/* ============================================================
   LAPORAN
   ============================================================

   Disusun ulang dengan satu pertanyaan sebagai pemandu:
   "angka ini mengubah keputusan apa?"

   Versi sebelumnya menjawab "kenapa klien kalah" — menarik dibaca, tapi
   tidak bisa dipakai menyetel apa pun di Meta Ads Manager atau TikTok Ads.
   Sekarang urutannya dibalik: yang di atas adalah angka yang langsung
   diterjemahkan jadi setelan iklan (kapan tayang, lokasi, umur anggaran,
   channel mana yang layak dibayar), dan analisa menang/kalah turun ke bawah
   sebagai penjelasnya.
   ============================================================ */
$dari   = $_GET['dari']   ?? date('Y-m-d', strtotime('-24 month'));
$sampai = $_GET['sampai'] ?? date('Y-m-d');

$deal = "stage IN ('deal','persiapan','harih','selesai')";

/* ---------- 1. KAPAN IKLAN HARUS TAYANG ----------
   Dua angka yang jarang dipunya WO, padahal paling menentukan:
   berapa lama sebelum hari-H orang mulai mencari, dan bulan apa acaranya
   menumpuk. Selisih keduanya = bulan iklan harus sudah jalan. */
$leadTime = one("SELECT
      COUNT(*) n,
      ROUND(AVG(DATEDIFF(wedding_date, created_at))) rata,
      ROUND(MIN(DATEDIFF(wedding_date, created_at))) cepat,
      ROUND(MAX(DATEDIFF(wedding_date, created_at))) lama
    FROM clients
    WHERE wedding_date IS NOT NULL AND $deal
      AND DATEDIFF(wedding_date, created_at) BETWEEN 0 AND 1200") ?: [];

$musim = all("SELECT MONTH(wedding_date) bln, COUNT(*) n
              FROM clients WHERE wedding_date IS NOT NULL AND $deal
              GROUP BY MONTH(wedding_date) ORDER BY bln");
$musimPeta = [];
foreach ($musim as $m) $musimPeta[(int) $m['bln']] = (int) $m['n'];
$maxMusim = max(1, $musimPeta ? max($musimPeta) : 1);

$namaBulan = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
$rataLead  = (int) ($leadTime['rata'] ?? 0);

/* ---------- 1b. USIA ----------
   Setelan pertama di Meta Ads Manager maupun TikTok Ads adalah rentang usia.
   Dihitung terpisah pria dan wanita karena keduanya sering terpaut beberapa
   tahun, dan iklan biasanya diarahkan ke salah satunya lebih dulu. */
$usiaPria = $usiaWanita = [];
$statUsia = [];
try {
    foreach (['pria', 'wanita'] as $sisi) {
        $rows = all("SELECT usia_$sisi u FROM clients
                     WHERE usia_$sisi IS NOT NULL AND $deal");
        foreach ($rows as $r) {
            $band = rentangUsia((int) $r['u']);
            if (!$band) continue;
            if ($sisi === 'pria') $usiaPria[$band]   = ($usiaPria[$band]   ?? 0) + 1;
            else                  $usiaWanita[$band] = ($usiaWanita[$band] ?? 0) + 1;
        }
    }
    $statUsia = one("SELECT ROUND(AVG(usia_pria),1) rp, ROUND(AVG(usia_wanita),1) rw,
                            COUNT(*) n
                     FROM clients WHERE $deal AND (usia_pria IS NOT NULL OR usia_wanita IS NOT NULL)") ?: [];
} catch (Throwable $e) { /* kolom belum ada — jalankan migration-v13 */ }

$urutBand = ['18–24','25–29','30–34','35–44','45+'];
$rapikan = function (array $d) use ($urutBand) {
    $out = [];
    foreach ($urutBand as $b) if (!empty($d[$b])) $out[$b] = $d[$b];
    return $out;
};
$usiaPria = $rapikan($usiaPria);
$usiaWanita = $rapikan($usiaWanita);

$kerja = [];
try {
    $kerja = all("SELECT k, COUNT(*) n FROM (
                    SELECT LOWER(TRIM(kerja_pria)) k FROM clients WHERE kerja_pria <> '' AND $deal
                    UNION ALL
                    SELECT LOWER(TRIM(kerja_wanita)) FROM clients WHERE kerja_wanita <> '' AND $deal
                  ) x GROUP BY k ORDER BY n DESC LIMIT 8");
} catch (Throwable $e) { $kerja = []; }

/* ---------- 2. SIAPA YANG DIIKLANKAN ---------- */
$perKota = all("SELECT COALESCE(NULLIF(city,''),'(tidak diisi)') kota,
                       COUNT(*) total, SUM($deal) deal,
                       COALESCE(AVG(CASE WHEN $deal THEN deal_value END),0) rata
                FROM clients WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY kota ORDER BY deal DESC, total DESC LIMIT 10", [$dari, $sampai]);

// Band anggaran. Batasnya sengaja kasar — untuk menyetel iklan yang
// dibutuhkan cuma "kelas mana yang jadi", bukan angka presisi.
$bandBudget = all("SELECT
    CASE
      WHEN budget_estimate IS NULL OR budget_estimate = 0 THEN 'Tidak disebut'
      WHEN budget_estimate <  25000000  THEN 'Di bawah 25 jt'
      WHEN budget_estimate <  50000000  THEN '25 – 50 jt'
      WHEN budget_estimate < 100000000  THEN '50 – 100 jt'
      WHEN budget_estimate < 200000000  THEN '100 – 200 jt'
      ELSE 'Di atas 200 jt'
    END band,
    COUNT(*) total, SUM($deal) deal
  FROM clients WHERE DATE(created_at) BETWEEN ? AND ?
  GROUP BY band ORDER BY MIN(COALESCE(budget_estimate,0))", [$dari, $sampai]);

$bandTamu = all("SELECT
    CASE
      WHEN guest_estimate IS NULL OR guest_estimate = 0 THEN 'Tidak disebut'
      WHEN guest_estimate <  300  THEN 'Di bawah 300'
      WHEN guest_estimate <  700  THEN '300 – 700'
      WHEN guest_estimate < 1500  THEN '700 – 1.500'
      ELSE 'Di atas 1.500'
    END band,
    COUNT(*) total, SUM($deal) deal
  FROM clients WHERE DATE(created_at) BETWEEN ? AND ?
  GROUP BY band ORDER BY MIN(COALESCE(guest_estimate,0))", [$dari, $sampai]);

/* ---------- 3. CHANNEL MANA YANG LAYAK DIBAYAR ----------
   Diurutkan menurut jumlah DEAL, bukan jumlah masuk. Channel yang
   mendatangkan seratus penanya tapi nol kontrak bukan channel yang berhasil —
   itu channel yang menghabiskan waktu balas pesan. */
$perSumber = all("SELECT source,
                         COUNT(*) total,
                         SUM($deal) deal,
                         COALESCE(SUM(CASE WHEN $deal THEN deal_value ELSE 0 END),0) nilai,
                         ROUND(AVG(DATEDIFF(COALESCE(contract_signed_at, updated_at), created_at))) hari
                  FROM clients WHERE DATE(created_at) BETWEEN ? AND ?
                  GROUP BY source ORDER BY deal DESC, total DESC", [$dari, $sampai]);

/* ---------- 4. BAHAN KONTEN ---------- */
$vendorDiminta = [];
$adatDist = [];
$tipeDist = [];
try {
    $vendorDiminta = all("SELECT vc.nama, COUNT(*) n
                          FROM client_vendor_needs n
                          JOIN vendor_categories vc ON vc.id = n.category_id
                          GROUP BY vc.id ORDER BY n DESC LIMIT 12");
    $adatDist = all("SELECT COALESCE(NULLIF(prosesi_adat,''),'(belum diisi)') adat, COUNT(*) n
                     FROM client_wedding_info GROUP BY adat ORDER BY n DESC");
} catch (Throwable $e) { /* tabel belum ada — bagian ini dilewat */ }

$tipeDist = all("SELECT COALESCE(NULLIF(tipe_klien,''),'(belum dipilih)') tipe,
                        COUNT(*) total, SUM($deal) deal
                 FROM clients GROUP BY tipe ORDER BY total DESC");

/* ---------- 5. MENANG / KALAH (penjelas, bukan pemandu) ---------- */
$sebabGagal = all("SELECT sebab, COUNT(*) n FROM client_analisa
                   WHERE hasil = 'gagal' AND DATE(created_at) BETWEEN ? AND ?
                   GROUP BY sebab ORDER BY n DESC LIMIT 6", [$dari, $sampai]);
$sebabMenang = all("SELECT sebab, COUNT(*) n FROM client_analisa
                    WHERE hasil = 'berhasil' AND DATE(created_at) BETWEEN ? AND ?
                    GROUP BY sebab ORDER BY n DESC LIMIT 6", [$dari, $sampai]);

$belumDianalisa = all("SELECT id, name, partner_name, stage, updated_at
                       FROM clients c
                       WHERE c.stage IN ('batal','selesai')
                         AND NOT EXISTS (SELECT 1 FROM client_analisa a WHERE a.client_id = c.id)
                       ORDER BY c.updated_at DESC LIMIT 10");

$totalKlien = (int) (one("SELECT COUNT(*) c FROM clients")['c'] ?? 0);

/** Batang perbandingan sederhana. */
function batang(int $n, int $max, string $warna = 'var(--ember)'): string
{
    $w = $max > 0 ? max(2, round($n / $max * 100)) : 2;
    return '<div style="height:7px;background:var(--ivory-07);border-radius:4px;overflow:hidden">'
         . '<div style="height:100%;width:' . $w . '%;background:' . $warna . ';border-radius:4px"></div></div>';
}

adminHead('Analisa', 'analisa');
pageHead('Analisa',
    'Disusun untuk satu keputusan: siapa yang diiklankan, kapan, dan lewat channel mana.');
?>

<form method="get" class="card" style="display:flex;gap:11px;align-items:flex-end;flex-wrap:wrap">
  <div class="field" style="margin:0"><label for="d1">Dari</label>
    <input type="date" id="d1" name="dari" value="<?= e($dari) ?>"></div>
  <div class="field" style="margin:0"><label for="d2">Sampai</label>
    <input type="date" id="d2" name="sampai" value="<?= e($sampai) ?>"></div>
  <button class="btn ghost" type="submit">Terapkan</button>
</form>

<?php if ($totalKlien < 10): ?>
  <div class="card" style="border-color:var(--ember-line)">
    <h2>Datanya belum cukup</h2>
    <p class="sub" style="margin:0">Baru <b><?= $totalKlien ?></b> klien tercatat. Angka di bawah tetap
      dihitung, tapi jangan dipakai menyetel iklan dulu — pola dari sepuluh klien atau kurang lebih
      sering kebetulan daripada kecenderungan. Isi dulu klien lama yang sudah selesai supaya
      bagian ini punya dasar.</p>
  </div>
<?php endif; ?>

<!-- ============ 1. KAPAN IKLAN TAYANG ============ -->
<div class="card">
  <h2>Kapan iklan harus tayang</h2>
  <p class="sub">Dua angka ini yang menentukan bulan berapa anggaran iklan dinyalakan.</p>

  <div class="grid g2" style="align-items:start">
    <div>
      <span class="lab" style="display:block;margin-bottom:8px">Jarak dari bertanya ke hari-H</span>
      <?php if (empty($leadTime['n'])): ?>
        <p class="muted" style="font-size:13px">Belum ada klien deal yang punya tanggal nikah.</p>
      <?php else: ?>
        <div style="display:flex;align-items:baseline;gap:9px;margin-bottom:6px">
          <span style="font-family:var(--serif);font-size:38px;color:var(--ember)"><?= round($rataLead / 30, 1) ?></span>
          <span style="color:var(--ivory-60);font-size:14px">bulan rata-rata</span>
        </div>
        <p class="muted" style="font-size:12.5px;margin:0">
          Rentang <?= round(($leadTime['cepat'] ?? 0) / 30, 1) ?>–<?= round(($leadTime['lama'] ?? 0) / 30, 1) ?> bulan,
          dari <?= (int) $leadTime['n'] ?> klien deal.</p>
        <p style="font-size:13.2px;color:var(--ivory);margin-top:11px;line-height:1.6">
          Artinya iklan yang menyasar pernikahan bulan tertentu harus sudah tayang
          <b><?= round($rataLead / 30, 1) ?> bulan sebelumnya.</b> Menayangkannya di bulan acara
          sendiri hampir selalu terlambat — vendornya sudah dipilih.</p>
      <?php endif; ?>
    </div>

    <div>
      <span class="lab" style="display:block;margin-bottom:8px">Bulan acara menumpuk</span>
      <?php if (!$musim): ?>
        <p class="muted" style="font-size:13px">Belum ada tanggal nikah tercatat.</p>
      <?php else: ?>
        <?php
          $kolom = [];
          for ($b = 1; $b <= 12; $b++) $kolom[$namaBulan[$b]] = $musimPeta[$b] ?? 0;
          // Bulan yang harus dipasangi iklan = bulan puncak dikurangi lead time.
          $sorotIklan = [];
          if ($rataLead > 0) {
              foreach ($musimPeta as $b => $n) {
                  if ($n <= 0) continue;
                  $sorotIklan[] = $namaBulan[(($b - (int) round($rataLead / 30) - 1) % 12 + 12) % 12 + 1];
              }
          }
        ?>
        <?= grafikBatang($kolom, array_values(array_unique($sorotIklan))) ?>
        <?php if ($sorotIklan): ?>
          <p class="hint" style="margin:10px 0 0">Kolom <span style="color:var(--ember)">berwarna</span>
            adalah bulan iklan harus sudah tayang, dihitung mundur <?= round($rataLead / 30, 1) ?> bulan
            dari puncak acara.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ============ 1b. DEMOGRAFI ============ -->
<div class="card">
  <h2>Usia mempelai</h2>
  <p class="sub">Setelan pertama di Meta Ads Manager dan TikTok Ads. Dihitung dari klien yang
    <b>jadi</b>, bukan yang bertanya — dan dipisah pria/wanita karena keduanya sering terpaut
    beberapa tahun.</p>

  <?php if (!$usiaPria && !$usiaWanita): ?>
    <div class="empty" style="padding:28px 16px">
      <p>Belum ada data usia</p>
      <span>Kolom usia baru ditambahkan. Isi di halaman klien — tanpa itu bagian ini
        tidak bisa menghasilkan setelan iklan apa pun.</span>
    </div>
  <?php else: ?>
    <div class="grid g3" style="align-items:start">
      <div style="text-align:center">
        <span class="lab" style="display:block;margin-bottom:9px">Mempelai pria</span>
        <?= grafikDonat($usiaPria,
              $statUsia['rp'] ? (string) round((float) $statUsia['rp']) : '—', 'RATA-RATA') ?>
        <div style="text-align:left;margin-top:10px"><?= grafikLegenda($usiaPria) ?></div>
      </div>
      <div style="text-align:center">
        <span class="lab" style="display:block;margin-bottom:9px">Mempelai wanita</span>
        <?= grafikDonat($usiaWanita,
              $statUsia['rw'] ? (string) round((float) $statUsia['rw']) : '—', 'RATA-RATA') ?>
        <div style="text-align:left;margin-top:10px"><?= grafikLegenda($usiaWanita) ?></div>
      </div>
      <div>
        <span class="lab" style="display:block;margin-bottom:9px">Pekerjaan tersering</span>
        <?php if (!$kerja): ?>
          <p class="muted" style="font-size:13px">Belum diisi.</p>
        <?php else: $maxK2 = max(1, (int) $kerja[0]['n']); foreach ($kerja as $k2): ?>
          <div style="margin-bottom:9px">
            <div style="display:flex;justify-content:space-between;font-size:12.6px;margin-bottom:3px">
              <span style="color:var(--ivory)"><?= e(ucfirst($k2['k'])) ?></span>
              <span class="mono muted"><?= (int) $k2['n'] ?></span>
            </div>
            <?= batang((int) $k2['n'], $maxK2) ?>
          </div>
        <?php endforeach; endif; ?>
        <?php if ($usiaPria || $usiaWanita): ?>
          <p class="hint" style="margin-top:12px">Salin ke Ads Manager:
            <b>usia <?= e(array_key_first($usiaWanita) ?: array_key_first($usiaPria)) ?></b>,
            lokasi dan minat menyusul dari kartu berikutnya.</p>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- ============ 2. SIAPA YANG DIIKLANKAN ============ -->
<div class="card">
  <h2>Siapa yang diiklankan</h2>
  <p class="sub">Tiga setelan yang bisa langsung disalin ke Meta Ads Manager atau TikTok Ads.</p>

  <div class="grid g3" style="align-items:start">
    <div>
      <span class="lab" style="display:block;margin-bottom:10px">Lokasi</span>
      <?php
        $b_ = [];
        foreach ($perKota as $x) $b_[] = ['label' => $x['kota'], 'total' => (int) $x['total'], 'deal' => (int) $x['deal']];
        echo grafikBatangGanda($b_);
      ?>
    </div>
    <div>
      <span class="lab" style="display:block;margin-bottom:10px">Anggaran yang jadi</span>
      <?php
        $b_ = [];
        foreach ($bandBudget as $x) $b_[] = ['label' => $x['band'], 'total' => (int) $x['total'], 'deal' => (int) $x['deal']];
        echo grafikBatangGanda($b_);
      ?>
    </div>
    <div>
      <span class="lab" style="display:block;margin-bottom:10px">Ukuran acara</span>
      <?php
        $b_ = [];
        foreach ($bandTamu as $x) $b_[] = ['label' => $x['band'] . ' tamu', 'total' => (int) $x['total'], 'deal' => (int) $x['deal']];
        echo grafikBatangGanda($b_);
      ?>
    </div>
  </div>
</div>

<!-- ============ 3. CHANNEL ============ -->
<div class="card">
  <h2>Channel mana yang layak dibayar</h2>
  <p class="sub">Diurutkan menurut jumlah deal, bukan jumlah yang bertanya. Channel dengan seratus
    penanya dan nol kontrak bukan channel yang berhasil — itu channel yang menghabiskan waktu balas pesan.</p>
  <table class="tbl">
    <thead><tr><th>Sumber</th><th class="num">Masuk</th><th class="num">Deal</th>
      <th class="num">Konversi</th><th class="num">Nilai</th><th class="num">Lama proses</th></tr></thead>
    <?php foreach ($perSumber as $s2): $kv = $s2['total'] > 0 ? round($s2['deal'] / $s2['total'] * 100) : 0; ?>
      <tr>
        <td data-l="Sumber"><b><?= e(ucfirst($s2['source'])) ?></b></td>
        <td data-l="Masuk" class="num"><?= (int) $s2['total'] ?></td>
        <td data-l="Deal" class="num"><?= (int) $s2['deal'] ?></td>
        <td data-l="Konversi" class="num"
            style="color:<?= $kv >= 30 ? 'var(--sage-text)' : ($kv > 0 ? 'var(--ivory)' : 'var(--ivory-38)') ?>"><?= $kv ?>%</td>
        <td data-l="Nilai" class="num"><?= $s2['nilai'] > 0 ? rupiah((float) $s2['nilai'], true) : '—' ?></td>
        <td data-l="Lama" class="num muted"><?= $s2['hari'] !== null ? (int) $s2['hari'] . ' hari' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$perSumber): ?><tr><td colspan="6" class="muted">Belum ada data pada rentang ini.</td></tr><?php endif; ?>
  </table>
</div>

<!-- ============ 4. BAHAN KONTEN ============ -->
<div class="card">
  <h2>Bahan konten</h2>
  <p class="sub">Apa yang paling sering diminta — itu yang paling layak jadi materi iklan.</p>
  <div class="grid g3" style="align-items:start">
    <div>
      <span class="lab" style="display:block;margin-bottom:9px">Vendor paling diminta</span>
      <?php if (!$vendorDiminta): ?>
        <p class="muted" style="font-size:13px">Belum ada klien yang mengisi kebutuhan vendor.</p>
      <?php else: $maxV = max(1, (int) $vendorDiminta[0]['n']); foreach ($vendorDiminta as $v): ?>
        <div style="margin-bottom:8px">
          <div style="display:flex;justify-content:space-between;font-size:12.6px;margin-bottom:3px">
            <span style="color:var(--ivory)"><?= e($v['nama']) ?></span>
            <span class="mono muted"><?= (int) $v['n'] ?></span>
          </div>
          <?= batang((int) $v['n'], $maxV) ?>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div>
      <span class="lab" style="display:block;margin-bottom:9px">Prosesi adat</span>
      <?php if (!$adatDist): ?><p class="muted" style="font-size:13px">Belum ada base information terisi.</p>
      <?php else: $maxA = max(1, (int) $adatDist[0]['n']); foreach ($adatDist as $a): ?>
        <div style="margin-bottom:8px">
          <div style="display:flex;justify-content:space-between;font-size:12.6px;margin-bottom:3px">
            <span style="color:var(--ivory)"><?= e(ucfirst($a['adat'])) ?></span>
            <span class="mono muted"><?= (int) $a['n'] ?></span>
          </div>
          <?= batang((int) $a['n'], $maxA) ?>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div>
      <span class="lab" style="display:block;margin-bottom:9px">Tipe klien</span>
      <?php $maxTp = 1; foreach ($tipeDist as $t2) $maxTp = max($maxTp, (int) $t2['total']); ?>
      <?php foreach ($tipeDist as $t2): ?>
        <div style="margin-bottom:8px">
          <div style="display:flex;justify-content:space-between;font-size:12.6px;margin-bottom:3px">
            <span style="color:var(--ivory)"><?= e(ucfirst($t2['tipe'])) ?></span>
            <span class="mono muted"><?= (int) $t2['deal'] ?>/<?= (int) $t2['total'] ?></span>
          </div>
          <?= batang((int) $t2['total'], $maxTp) ?>
        </div>
      <?php endforeach; ?>
      <p class="hint" style="margin:8px 0 0">Kalau mayoritas <b>budgeting</b>, iklan sebaiknya menyebut
        angka. Kalau <b>tematis</b>, yang dijual visual dan konsep.</p>
    </div>
  </div>
</div>

<!-- ============ 5. MENANG / KALAH ============ -->
<div class="grid g2" style="align-items:start">
  <div class="card">
    <h2>Kenapa gugur</h2>
    <?php if (!$sebabGagal): ?>
      <div class="empty" style="padding:22px 14px"><p>Belum ada catatan</p>
        <span>Isi analisa tiap klien tidak jadi — ini yang menjelaskan kenapa angka di atas begitu.</span></div>
    <?php else: $mg = max(1, (int) $sebabGagal[0]['n']); foreach ($sebabGagal as $g): ?>
      <div style="margin-bottom:10px">
        <div style="display:flex;justify-content:space-between;font-size:12.8px;margin-bottom:3px">
          <span style="color:var(--ivory)"><?= e($sebabPeta[$g['sebab']] ?? $g['sebab']) ?></span>
          <span class="mono muted"><?= (int) $g['n'] ?></span>
        </div>
        <?= batang((int) $g['n'], $mg, 'var(--rose)') ?>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="card">
    <h2>Kenapa berhasil</h2>
    <?php if (!$sebabMenang): ?>
      <div class="empty" style="padding:22px 14px"><p>Belum ada catatan</p>
        <span>Alasan menang jadi kalimat iklan yang paling meyakinkan — karena itu kalimat klien sendiri.</span></div>
    <?php else: $mm = max(1, (int) $sebabMenang[0]['n']); foreach ($sebabMenang as $m2): ?>
      <div style="margin-bottom:10px">
        <div style="display:flex;justify-content:space-between;font-size:12.8px;margin-bottom:3px">
          <span style="color:var(--ivory)"><?= e($sebabPeta[$m2['sebab']] ?? $m2['sebab']) ?></span>
          <span class="mono muted"><?= (int) $m2['n'] ?></span>
        </div>
        <?= batang((int) $m2['n'], $mm, 'var(--sage)') ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php if ($belumDianalisa): ?>
<div class="card" style="border-color:var(--ember-line)">
  <h2>Belum dianalisa</h2>
  <p class="sub">Sudah selesai atau batal tapi sebabnya belum dicatat.</p>
  <table class="tbl">
    <?php foreach ($belumDianalisa as $b): ?>
      <tr>
        <td data-l="Klien" style="padding:10px 0">
          <b><?= e($b['name'] . ($b['partner_name'] ? ' & ' . $b['partner_name'] : '')) ?></b><br>
          <span class="muted mono"><?= e(stageLabel($b['stage'])) ?> · <?= tanggalID($b['updated_at']) ?></span>
        </td>
        <td class="actions"><a class="btn sm solid" href="?klien=<?= (int) $b['id'] ?>">Catat analisa</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<?php adminFoot();
