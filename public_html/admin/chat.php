<?php
/**
 * Room WhatsApp di dalam panel.
 *
 * Tampilannya sengaja dibuat mirip WhatsApp Web supaya tidak perlu belajar
 * ulang. Yang membedakan: setiap room bisa ditempelkan ke satu pesta, jadi
 * obrolan dengan vendor yang sama untuk pesta A dan pesta B tetap terbaca
 * konteksnya — hal yang justru tidak bisa dilakukan WhatsApp asli.
 */
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/vendor.php';
require_once __DIR__ . '/../inc/chat.php';
require_once __DIR__ . '/../inc/pipeline.php';

$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';
    if ($act === 'grup_segar') {
        $r = waGrupSegarkan();
        flash($r['ok']
            ? 'Daftar grup disegarkan di sisi WhatsApp. Sekarang tekan "Tarik ke panel".'
            : 'Gagal menyegarkan: ' . ($r['error'] ?? 'tidak diketahui'), $r['ok'] ? 'ok' : 'err');
    } elseif ($act === 'uji_webhook') {
        // Panggil webhook kita sendiri dengan muatan tiruan Fonnte.
        // Ini memisahkan dua kemungkinan yang dari panel terlihat sama:
        // endpoint-nya rusak, atau Fonnte memang tidak pernah memanggil.
        $sebelum = (int) (one("SELECT COUNT(*) c FROM wa_messages WHERE direction = 'masuk'")['c'] ?? 0);
        $urlHook = url('wa-webhook.php') . '?token=' . rawurlencode((string) setting('wa_gateway_token'));

        $r = httpJson('POST', $urlHook, [], [
            'device'  => setting('wa_gateway_device', 'uji'),
            'sender'  => '628000000001',
            'message' => 'Uji webhook dari panel · ' . date('H:i:s'),
            'name'    => 'Uji Sistem',
            'inboxid' => 'uji-' . bin2hex(random_bytes(6)),
        ]);

        $sesudah = (int) (one("SELECT COUNT(*) c FROM wa_messages WHERE direction = 'masuk'")['c'] ?? 0);

        if ($r['code'] === 0) {
            flash('Server ini tidak bisa memanggil dirinya sendiri (koneksi gagal). '
                . 'Sebagian hosting memang memblokirnya — hasilnya tidak bisa disimpulkan. '
                . 'Uji dari luar: buka URL webhook di peramban, harus muncul "ditolak", bukan 404 atau 500.', 'err');
        } elseif ($r['code'] === 404) {
            flash('404 — berkas wa-webhook.php tidak ditemukan di server. Belum terunggah, atau salah lokasi.', 'err');
        } elseif ($r['code'] === 403) {
            flash('403 — endpoint hidup tapi token ditolak. Token di Pengaturan berbeda dengan yang ada di URL. '
                . 'Samakan keduanya dengan token perangkat di Fonnte.', 'err');
        } elseif ($r['code'] >= 500) {
            flash('HTTP ' . $r['code'] . ' — endpoint error. Periksa error_log di root DirectAdmin.', 'err');
        } elseif ($sesudah > $sebelum) {
            flash('Endpoint SEHAT. Pesan uji berhasil masuk ke room. '
                . 'Berarti masalahnya di sisi Fonnte: URL webhook belum tersimpan (tekan Update setelah mengisinya), '
                . 'atau perangkatnya bukan yang sedang terhubung.');
        } else {
            flash('Endpoint membalas HTTP ' . $r['code'] . ' tapi pesan tidak masuk room. '
                . 'Kemungkinan muatannya ditolak diam-diam — periksa Kotak masuk dan wa_log.', 'err');
        }
    } elseif ($act === 'tanpa_token') {
        // Menyingkirkan token dari persamaan selama 30 menit.
        //
        // Kalau Fonnte tersandung pada query string di URL webhook, panggilannya
        // tidak akan pernah tercatat selama token masih disyaratkan — permintaan
        // yang ditolak pun tetap tercatat, tapi hanya kalau permintaannya SAMPAI.
        // Dengan mode ini, URL tanpa ?token= bisa didaftarkan dan diuji langsung.
        //
        // Berbatas waktu, bukan saklar permanen: endpoint terbuka berarti siapa pun
        // bisa menyuntikkan pesan palsu ke panel. Tiga puluh menit cukup untuk
        // menguji, terlalu singkat untuk jadi lubang yang terlupakan.
        if ((int) setting('wa_hook_open_until') > time()) {
            settingSet('wa_hook_open_until', '0');
            flash('Mode terbuka dimatikan. Token kembali diwajibkan.');
        } else {
            settingSet('wa_hook_open_until', (string) (time() + 1800));
            flash('Webhook menerima tanpa token selama 30 menit. '
                . 'Sekarang di Fonnte, ganti URL-nya jadi https://callalily.party/wa-webhook.php '
                . '(TANPA ?token=), tekan Update, lalu kirim satu pesan dari HP lain.');
        }
    } elseif ($act === 'grup_tarik') {
        $r = waGrupTarik();
        flash($r['ok']
            ? $r['jumlah'] . ' grup baru masuk panel (total ' . ($r['total'] ?? 0) . ' grup terbaca).'
            : 'Gagal menarik: ' . ($r['error'] ?? 'tidak diketahui'), $r['ok'] ? 'ok' : 'err');
    }
    redirect('admin/chat.php' . ($_GET['chat'] ?? '' ? '?chat=' . (int) $_GET['chat'] : ''));
}

// Daftar grup disegarkan sendiri sekali sehari — tapi HANYA lewat
// get-whatsapp-group, yang cuma membaca. fetch-group sengaja TIDAK
// diotomatiskan: dokumentasi Fonnte memperingatkan pemanggilan berlebihan
// berisiko membuat nomor kena banned, dan risiko itu tidak sepadan untuk
// menghemat satu klik yang cuma perlu ditekan saat ada grup baru.
if (waPenyedia() === 'gateway' && setting('wa_gateway_token')) {
    $terakhir = setting('wa_group_synced_at');
    if (!$terakhir || strtotime($terakhir) < strtotime('-1 day')) {
        try { waGrupTarik(); } catch (Throwable $e) { /* gagal tarik bukan alasan halaman tidak terbuka */ }
    }
}

// Room yatim (belum tertaut klien/vendor) ditautkan ulang di sini. Murah:
// hanya menyentuh baris yang kedua kaitannya kosong, jadi setelah sekali
// beres pemanggilan berikutnya tidak menemukan apa-apa.
try { chatTautUlang(); } catch (Throwable $e) { /* jangan halangi halaman */ }

$cari   = trim($_GET['cari'] ?? '');
$filter = $_GET['filter'] ?? 'semua';
$aktif  = (int) ($_GET['chat'] ?? 0);

// Mulai room baru dari nomor mentah (mis. dari halaman klien/vendor).
if (!empty($_GET['nomor'])) {
    $id = chatRoom($_GET['nomor']);
    if ($id) redirect('chat.php?chat=' . $id);
}

$rooms = chatDaftar($cari, $filter);
if (!$aktif && $rooms) $aktif = (int) $rooms[0]['id'];

$room = $aktif ? one("SELECT ch.*, c.name klien_nama, c.partner_name, c.stage,
                             v.name vendor_nama, v.category
                      FROM wa_chats ch
                      LEFT JOIN clients c ON c.id = ch.client_id
                      LEFT JOIN vendors v ON v.id = ch.vendor_id
                      WHERE ch.id = ?", [$aktif]) : null;

$pesan   = $room ? chatPesan($aktif, 0, 60) : [];
$pestaId = $room ? chatPestaAktif($aktif)   : null;
$pesta   = $pestaId ? one("SELECT id, name, partner_name, stage, wedding_date FROM clients WHERE id = ?", [$pestaId]) : null;
if ($room) chatTandaiBaca($aktif);

// Daftar pesta aktif untuk pemilih konteks.
$pestaAktif = all("SELECT id, name, partner_name FROM clients
                   WHERE stage NOT IN ('selesai','batal') ORDER BY wedding_date IS NULL, wedding_date");

$maxId = 0;
foreach ($pesan as $m) $maxId = max($maxId, (int) $m['id']);

adminHead('Chat', 'chat');
?>
<style>
/* Tinggi diukur JS dari posisi sebenarnya (lihat bawah). Nilai di sini cuma
   cadangan supaya tata letak tidak melompat sebelum skrip jalan — dan supaya
   halaman tetap terpakai kalau JavaScript mati. dvh, bukan vh, karena bilah
   alamat peramban ponsel membuat vh berbohong. */
.wa{display:grid;grid-template-columns:320px 1fr;gap:0;height:calc(100dvh - 190px);
    min-height:420px;border:var(--card-border);border-radius:14px;overflow:hidden;background:var(--ink-2)}
/* min-height:0 wajib pada anak flex yang isinya menggulir. Tanpa itu panel
   menolak menyusut di bawah tinggi isinya, jadi daftar room meluber keluar
   kotak alih-alih memunculkan bilah gulir — persis bug yang sama dengan rail
   menu sebelumnya. */
.wa-kiri{border-right:var(--card-border);display:flex;flex-direction:column;min-width:0;min-height:0}
.wa-cari{padding:12px;border-bottom:var(--card-border);display:grid;gap:8px}
.wa-cari input{width:100%;padding:8px 11px;border-radius:9px;border:1px solid var(--ivory-12);
    background:var(--ink-3);color:var(--ivory);font:inherit;font-size:13.5px}
.wa-tab{display:flex;gap:5px;flex-wrap:wrap}
.wa-tab a{font-size:11.5px;padding:4px 10px;border-radius:20px;border:1px solid var(--ivory-12);
    color:var(--ivory-60);text-decoration:none;font-family:var(--mono);letter-spacing:.04em}
.wa-tab a.on{background:var(--ember);border-color:var(--ember);color:#fff}
.wa-list{overflow-y:auto;flex:1 1 auto;min-height:0;overscroll-behavior:contain}
.wa-item{display:flex;gap:10px;padding:11px 12px;border-bottom:1px solid var(--ivory-07);
    text-decoration:none;color:inherit;align-items:flex-start}
.wa-item:hover{background:var(--ivory-07)}
.wa-item.on{background:var(--ember-soft);border-left:3px solid var(--ember);padding-left:9px}
.wa-av{width:38px;height:38px;border-radius:50%;flex:0 0 38px;display:grid;place-items:center;
    background:var(--ink-3);font-family:var(--serif);font-size:16px;color:var(--ivory-60)}
.wa-av.klien{background:var(--ember-soft);color:var(--ember)}
.wa-av.vendor{background:var(--sage-soft);color:var(--sage-text)}
.wa-meta{min-width:0;flex:1}
.wa-nama{font-size:13.5px;color:var(--ivory);font-weight:600;white-space:nowrap;
    overflow:hidden;text-overflow:ellipsis}
.wa-cuplik{font-size:12px;color:var(--ivory-38);white-space:nowrap;overflow:hidden;
    text-overflow:ellipsis;margin-top:2px}
.wa-kanan-atas{text-align:right;flex:0 0 auto;display:grid;gap:4px;justify-items:end}
.wa-jam{font-size:10.5px;color:var(--ivory-30);font-family:var(--mono)}
.wa-badge{background:var(--ember);color:#fff;font-size:10.5px;min-width:18px;height:18px;
    border-radius:9px;display:grid;place-items:center;padding:0 5px;font-family:var(--mono)}

.wa-kanan{display:flex;flex-direction:column;min-width:0;min-height:0}
.wa-head{padding:11px 15px;border-bottom:var(--card-border);display:flex;
    align-items:center;gap:11px;flex-wrap:wrap}
.wa-head h3{font-family:var(--serif);font-size:17px;margin:0;color:var(--ivory)}
.wa-head .sub{font-size:11.5px;color:var(--ivory-38);font-family:var(--mono)}
.wa-body{flex:1 1 auto;min-height:0;overflow-y:auto;padding:16px;display:flex;
    flex-direction:column;gap:3px;background:var(--ink);overscroll-behavior:contain}
.wa-sep{align-self:center;font-size:10.5px;font-family:var(--mono);color:var(--ivory-38);
    background:var(--ink-2);padding:3px 11px;border-radius:20px;margin:10px 0 6px;
    border:1px solid var(--ivory-07)}
.b{max-width:70%;padding:7px 11px;border-radius:10px;font-size:13.8px;line-height:1.5;
    word-wrap:break-word;position:relative}
.b.masuk{align-self:flex-start;background:var(--ink-2);border:var(--card-border);
    border-bottom-left-radius:3px}
.b.keluar{align-self:flex-end;background:var(--ember-soft);border:1px solid var(--ember-line);
    border-bottom-right-radius:3px}
.b.catatan{align-self:center;background:transparent;border:1px dashed var(--ivory-12);
    color:var(--ivory-60);font-size:12.5px;max-width:80%;font-style:italic}
.b a{color:var(--ember)}
.b code{font-family:var(--mono);font-size:12.5px;background:var(--ivory-07);padding:1px 4px;border-radius:4px}
.b .t{display:block;text-align:right;font-size:10px;font-family:var(--mono);
    color:var(--ivory-30);margin-top:3px;user-select:none}
.b .t.read{color:var(--ember)}
.b .t.bad{color:var(--rose)}
.wa-tulis{border-top:var(--card-border);padding:11px 13px;display:flex;gap:9px;align-items:flex-end}
.wa-tulis textarea{flex:1;resize:none;min-height:40px;max-height:140px;padding:10px 12px;
    border-radius:11px;border:1px solid var(--ivory-12);background:var(--ink-3);
    color:var(--ivory);font:inherit;font-size:13.8px;line-height:1.5}
.wa-kirim{border:0;background:var(--ember);color:#fff;width:40px;height:40px;border-radius:50%;
    cursor:pointer;font-size:16px;flex:0 0 40px;display:grid;place-items:center}
.wa-kirim:disabled{opacity:.45;cursor:not-allowed}
.wa-kosong{flex:1;display:grid;place-items:center;text-align:center;padding:40px;color:var(--ivory-38)}
.wa-warn{padding:8px 15px;background:var(--rose-soft);border-bottom:1px solid var(--rose-line);
    font-size:12.3px;color:var(--rose-text)}
@media(max-width:820px){
  /* Di ponsel dua panel berdampingan tidak muat. Daftar dan ruang percakapan
     jadi bergantian: begitu satu room dibuka, daftarnya minggir — sama seperti
     WhatsApp sendiri. Menumpuk keduanya membuat ruang chat tinggal seiris. */
  .wa{grid-template-columns:1fr;height:calc(100dvh - 150px)}
  .wa-kiri{border-right:0;border-bottom:var(--card-border)}
  .wa.buka .wa-kiri{display:none}
  .wa:not(.buka) .wa-kanan{display:none}
  .wa-kembali{display:inline-flex !important}
  .b{max-width:86%}
}
.wa-kembali{display:none;align-items:center;justify-content:center;width:32px;height:32px;
  border-radius:9px;border:1px solid var(--ivory-12);background:var(--ink-3);
  color:var(--ivory);flex:0 0 32px;font-size:15px;text-decoration:none}
</style>

<?php pageHead('Chat'); ?>

<?php
/* Diagnosa muncul HANYA saat gejalanya ada: gateway aktif tapi belum pernah
   ada satu pun pesan masuk. Ditampilkan terus-menerus, panel ini cuma jadi
   kebisingan yang lama-lama diabaikan. */
$pernahMasuk = (int) (one("SELECT COUNT(*) c FROM wa_messages WHERE direction = 'masuk'")['c'] ?? 0);

// Kartu ini juga muncul saat SUDAH ada pesan masuk tapi belakangan ada yang
// ditolak — sebagian pesan hilang lebih sulit disadari daripada semuanya hilang.
$adaTolak = 0;
try {
    $adaTolak = (int) (one("SELECT COUNT(*) c FROM wa_log
        WHERE (arah = 'tolak' OR payload LIKE '[TOLAK]%')
          AND created_at > DATE_SUB(NOW(), INTERVAL 2 DAY)")['c'] ?? 0);
} catch (Throwable $e) { $adaTolak = 0; }

if (waSiap() && ($pernahMasuk === 0 || $adaTolak > 0)):
    $hit    = one("SELECT created_at, arah, http_code, LEFT(payload,200) p FROM wa_log
                   WHERE arah IN ('masuk','tolak') ORDER BY id DESC LIMIT 1");
    $ditolak = (int) (one("SELECT COUNT(*) c FROM wa_log
                   WHERE (arah = 'tolak' OR payload LIKE '[TOLAK]%')
                     AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")['c'] ?? 0);
    $urlHook = url('wa-webhook.php') . '?token=' . setting('wa_gateway_token');
?>
  <details class="card" style="border-color:rgba(217,123,123,.45);margin-bottom:16px">
    <summary style="cursor:pointer;list-style:none">
      <span style="font-family:var(--serif);font-style:italic;font-size:20px;color:var(--rose-text)">
        <?= $pernahMasuk === 0 ? 'Balasan belum pernah masuk' : 'Ada pesan yang tidak jadi masuk' ?></span>
      <span class="lab" style="margin-left:10px">KETUK UNTUK RINCIAN</span>
    </summary>
    <p class="sub">Pesan keluar berhasil terkirim, tapi belum ada satu pun yang masuk.
      Artinya pengirimannya sehat — yang bermasalah jalur baliknya.</p>

    <?php if (!$hit): ?>
      <p style="font-size:13.5px;color:var(--ivory-60);margin:12px 0">
        <b style="color:var(--ivory)">Fonnte belum pernah memanggil server ini sama sekali.</b>
        Berarti URL webhook belum terdaftar, atau <span class="mono">autoread</span> di perangkat
        Fonnte masih mati — tanpa autoread, pesan masuk tidak pernah diteruskan.</p>
    <?php elseif ($ditolak > 0): ?>
      <?php
        // Panggilan dari server sendiri (uji endpoint) TIDAK boleh dihitung
        // sebagai bukti Fonnte memanggil — dua-duanya POST ke berkas yang sama,
        // dan menyamakannya membuat diagnosis salah arah.
        $dariLuar = 0;
        try {
            $dariLuar = (int) (one("SELECT COUNT(*) c FROM wa_log
                WHERE arah = 'masuk' AND payload LIKE '[POST %'
                  AND payload NOT LIKE '%CallalilyCMS%'")['c'] ?? 0);
        } catch (Throwable $e) { $dariLuar = 0; }
      ?>
      <p style="font-size:13.5px;color:var(--ivory-60);margin:12px 0">
        <?php if ($dariLuar === 0): ?>
          <b style="color:var(--rose-text)">Semua panggilan berasal dari server ini sendiri.</b>
          Tidak ada satu pun yang datang dari Fonnte. Endpoint terbukti sehat — yang belum terjadi
          adalah Fonnte memanggilnya.
          <?php $dev = waNomorPerangkat(); if ($dev): ?>
            <br><br>Perangkat yang terhubung: <span class="mono"><?= e($dev) ?></span>.
            Pastikan nomor yang Anda pakai menguji <b>bukan nomor itu</b> — perangkat tidak bisa
            menerima pesan masuk dari dirinya sendiri.
          <?php endif; ?>
        <?php else: ?>
          <b style="color:var(--rose-text)">Fonnte memanggil <?= $dariLuar ?>×, tapi <?= $ditolak ?>× ditolak.</b>
          Hampir selalu karena URL yang didaftarkan tidak memuat <span class="mono">?token=</span>.
        <?php endif; ?>
      </p>
    <?php else: ?>
      <p style="font-size:13.5px;color:var(--ivory-60);margin:12px 0">
        Panggilan terakhir <b><?= e(tanggalID($hit['created_at'])) ?></b> diterima, tapi isinya tidak
        dikenali sebagai pesan. Cuplikan mentahnya:</p>
      <pre style="font-family:var(--mono);font-size:11.5px;color:var(--ivory-60);background:var(--ink-3);
                  padding:10px 12px;border-radius:9px;overflow-x:auto;margin:0 0 12px"><?= e($hit['p']) ?></pre>
    <?php endif; ?>

    <?php
      // Riwayat mentah panggilan webhook. Ditampilkan apa adanya karena
      // pertanyaan "apakah Fonnte pernah memanggil" hanya bisa dijawab oleh
      // catatan ini — bukan oleh tebakan soal setelan di sisi sana.
      // Uji dari panel sengaja DIPISAH dari panggilan luar. Sebelumnya keduanya
      // dicampur dalam satu daftar berisi enam baris — dan karena tombol uji
      // ditekan berkali-kali, panggilan Fonnte yang sesungguhnya akan terdorong
      // keluar daftar sebelum sempat terlihat. Diagnostik yang menutupi bukti
      // lebih buruk daripada tidak ada diagnostik.
      $riwayat = $luar = [];
      $nLuar = 0;
      try {
          // Filter POSITIF, bukan negatif. Versi sebelumnya menghitung apa pun
          // yang tidak bertanda CallalilyCMS — termasuk catatan lama dari sebelum
          // penanda itu dipasang. Hasilnya angka yang terlihat meyakinkan padahal
          // isinya uji peramban dari jam-jam sebelumnya. Sekarang hanya entri yang
          // BENAR-BENAR punya jejak pemanggil yang dihitung.
          $luar = all("SELECT created_at, arah, http_code, LEFT(payload,200) p
                       FROM wa_log
                       WHERE arah <> 'keluar'
                         AND payload REGEXP '^\\[(POST|GET|PUT) '
                         AND payload NOT LIKE '%CallalilyCMS%'
                       ORDER BY id DESC LIMIT 15");
          $nLuar = (int) (one("SELECT COUNT(*) c FROM wa_log
                       WHERE arah <> 'keluar'
                         AND payload REGEXP '^\\[(POST|GET|PUT) '
                         AND payload NOT LIKE '%CallalilyCMS%'")['c'] ?? 0);
          $riwayat = all("SELECT created_at, LEFT(payload,120) p FROM wa_log
                          WHERE payload LIKE '%CallalilyCMS%' ORDER BY id DESC LIMIT 3");
      } catch (Throwable $e) { $luar = []; }
    ?>

    <div style="display:flex;gap:10px;align-items:center;margin:16px 0 10px;padding:11px 14px;
                border-radius:10px;border:1px solid <?= $nLuar > 0 ? 'var(--sage-line)' : 'var(--rose-line)' ?>;
                background:<?= $nLuar > 0 ? 'var(--sage-soft)' : 'var(--rose-soft)' ?>">
      <span style="font-family:var(--serif);font-size:30px;
                   color:<?= $nLuar > 0 ? 'var(--sage-text)' : 'var(--rose-text)' ?>"><?= $nLuar ?></span>
      <span style="font-size:13.2px;color:var(--ivory-60);line-height:1.5">
        panggilan dari <b style="color:var(--ivory)">luar server ini</b><br>
        <span style="font-size:12.2px">Uji dari tombol di bawah tidak dihitung di sini — itu server
        memanggil dirinya sendiri, bukan bukti Fonnte bekerja.</span>
      </span>
    </div>

    <?php if ((int) setting('wa_hook_open_until') > time()):
      $sisaMenit = (int) ceil(((int) setting('wa_hook_open_until') - time()) / 60); ?>
      <div style="margin:14px 0 10px;padding:11px 14px;border-radius:10px;
                  border:1px solid var(--ember-line);background:var(--ember-soft)">
        <b style="color:var(--ember);font-size:13.3px">Mode terbuka aktif — sisa <?= $sisaMenit ?> menit</b>
        <p style="font-size:12.9px;color:var(--ivory-60);margin:5px 0 0;line-height:1.6">
          Webhook menerima panggilan tanpa token. Daftarkan URL ini di Fonnte
          <b>tanpa bagian <span class="mono">?token=</span></b>:
          <span class="mono" style="color:var(--ember)"><?= e(url('wa-webhook.php')) ?></span><br>
          Kalau panggilan mulai masuk sekarang, berarti Fonnte memang tersandung query string —
          dan solusinya token dipindah ke header, bukan URL.
        </p>
      </div>
    <?php endif; ?>

    <span class="lab" style="display:block;margin:14px 0 6px">Panggilan dari luar</span>
    <?php if (!$luar): ?>
      <p style="font-size:13.2px;color:var(--ivory-60);margin:0 0 4px">
        <b style="color:var(--rose-text)">Kosong.</b> Belum ada satu pun permintaan dari luar yang
        sampai ke berkas ini — bahkan yang ditolak pun akan tercatat. Pencatatan berjalan sebelum
        pemeriksaan token, jadi kalau Fonnte memanggil, pasti tertulis di sini apa pun hasilnya.</p>
    <?php else: ?>
      <table class="tbl" style="margin-bottom:6px">
        <?php foreach ($luar as $r): ?>
          <tr>
            <td class="num mono" style="width:130px;font-size:11.5px;color:var(--ivory-38)"><?= e($r['created_at']) ?></td>
            <td class="mono" style="font-size:11.5px;color:<?= $r['arah'] === 'tolak' ? 'var(--rose-text)' : 'var(--sage-text)' ?>"><?= e($r['arah']) ?></td>
            <td class="mono" style="font-size:11.5px;color:var(--ivory-60);word-break:break-all"><?= e(mb_strimwidth($r['p'], 0, 120, '…')) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>

    <?php if ($riwayat): ?>
      <details style="margin-top:8px">
        <summary class="lab" style="cursor:pointer">Uji dari panel (<?= count($riwayat) ?> terakhir)</summary>
        <table class="tbl" style="margin-top:6px">
          <?php foreach ($riwayat as $r): ?>
            <tr>
              <td class="num mono" style="width:130px;font-size:11.5px;color:var(--ivory-38)"><?= e($r['created_at']) ?></td>
              <td class="mono" style="font-size:11.5px;color:var(--ivory-38)"><?= e(mb_strimwidth($r['p'], 0, 90, '…')) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </details>
    <?php endif; ?>

    <div style="margin:14px 0 0;padding:12px 14px;border:1px solid var(--ember-line);
                border-radius:10px;background:var(--ember-soft)">
      <b style="color:var(--ember);font-size:13.4px">Uji dari luar — 5 detik, dan menentukan</b>
      <p style="font-size:13.2px;color:var(--ivory-60);margin:6px 0 0;line-height:1.65">
        Buka URL webhook di bawah lewat peramban, di tab baru.
      </p>
      <ul style="font-size:13.2px;color:var(--ivory-60);margin:8px 0 0;padding-left:18px;line-height:1.75">
        <li>Muncul tulisan <span class="mono">ditolak</span> → endpoint hidup dan bisa dijangkau dari
          luar. Berarti Fonnte-nya: URL belum tersimpan (tekan <b>Update</b> setelah mengisi), atau
          perangkat yang diedit bukan yang sedang terhubung.</li>
        <li><b>404</b> → berkas belum terunggah ke server.</li>
        <li><b>500</b> → endpoint error; periksa <span class="mono">error_log</span> di root DirectAdmin.</li>
        <li><b>403 dari server</b> (halaman Apache, bukan tulisan kami) → mod_security memblokir.
          Minta hosting membuka aturan untuk berkas ini.</li>
        <li>Tidak terbuka sama sekali → masalah SSL atau DNS; Fonnte pun tidak akan bisa masuk.</li>
      </ul>
    </div>

    <span class="lab" style="display:block;margin:14px 0 5px">URL webhook yang harus didaftarkan</span>
    <input type="text" readonly value="<?= e($urlHook) ?>" onclick="this.select()"
           style="width:100%;font-family:var(--mono);font-size:12.3px;padding:9px 11px;
                  border-radius:9px;border:1px solid var(--ember-line);background:var(--ink-3);color:var(--ember)">
    <form method="post" style="margin:14px 0 0">
      <?= csrfField() ?>
      <button class="btn solid" name="act" value="uji_webhook">Uji endpoint sekarang</button>
      <button class="btn ghost" name="act" value="tanpa_token" style="margin-left:8px">
        <?= (int) setting('wa_hook_open_until') > time() ? 'Matikan mode terbuka' : 'Buka 30 menit tanpa token' ?>
      </button>
      <span class="muted" style="font-size:12.3px;margin-left:9px">
        Mengirim satu pesan tiruan ke webhook sendiri — tidak menyentuh WhatsApp sama sekali.</span>
    </form>

    <p class="hint" style="margin:12px 0 0">Tempel apa adanya di Fonnte → Device → Webhook URL,
      <b>termasuk bagian <span class="mono">?token=</span></b>. Tanpa itu setiap panggilan ditolak.
      Lalu nyalakan <span class="mono">autoread</span> di perangkat yang sama.</p>
  </details>
<?php endif; ?>

<?php if (!waSiap()): ?>
  <div class="flash warn" style="margin-bottom:14px">
    <span>Gateway WhatsApp belum aktif. Pesan tetap tercatat di room, tapi pengirimannya
    harus lewat tautan wa.me. <a href="integrasi.php">Atur di Integrasi →</a></span>
  </div>
<?php endif; ?>

<div class="wa<?= $room ? ' buka' : '' ?>" id="wa" data-chat="<?= $aktif ?>" data-max="<?= $maxId ?>">

  <aside class="wa-kiri">
    <div class="wa-cari">
      <form method="get"><input type="hidden" name="filter" value="<?= e($filter) ?>">
        <input name="cari" value="<?= e($cari) ?>" placeholder="Cari nama atau nomor…" autocomplete="off">
      </form>
      <?php if (waPenyedia() === 'gateway'): ?>
      <form method="post" style="display:flex;gap:6px">
        <?= csrfField() ?>
        <button class="btn sm ghost" name="act" value="grup_segar" style="flex:1"
                title="Panggil hanya setelah membuat grup baru — Fonnte memperingatkan pemanggilan berlebihan berisiko banned">Segarkan grup</button>
        <button class="btn sm ghost" name="act" value="grup_tarik" style="flex:1">Tarik ke panel</button>
      </form>
      <?php endif; ?>
      <div class="wa-tab">
        <?php foreach (['semua'=>'Semua','belum'=>'Belum dibaca','aktif'=>'Ada pesan','klien'=>'Klien','vendor'=>'Vendor','grup'=>'Grup','kontak'=>'Kontak','lain'=>'Lainnya'] as $k=>$l): ?>
          <a href="?filter=<?= $k ?><?= $cari ? '&cari=' . urlencode($cari) : '' ?>"
             class="<?= $filter === $k ? 'on' : '' ?>"><?= $l ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="wa-list" id="waList">
      <?php if (!$rooms): ?>
        <div style="padding:26px 16px;text-align:center;color:var(--ivory-38);font-size:13px">
          Belum ada percakapan.<br><span style="font-size:12px">Room terbuat sendiri begitu
          ada pesan masuk, atau saat Anda mengirim dari halaman klien/vendor.</span>
        </div>
      <?php endif; ?>
      <?php foreach ($rooms as $ch): $nm = chatNama($ch); ?>
        <a class="wa-item <?= (int) $ch['id'] === $aktif ? 'on' : '' ?>"
           href="?chat=<?= (int) $ch['id'] ?>&filter=<?= e($filter) ?><?= $cari ? '&cari=' . urlencode($cari) : '' ?>">
          <span class="wa-av <?= e($ch['jenis']) ?>"><?= e(mb_strtoupper(mb_substr($nm, 0, 1))) ?></span>
          <span class="wa-meta">
            <span class="wa-nama"><?= e($nm) ?></span>
            <span class="wa-cuplik"><?php if (!$ch['last_at']): ?><i style="opacity:.6">Belum ada pesan</i><?php else: ?><?= $ch['last_dir'] === 'keluar' ? 'Anda: ' : '' ?><?= e(mb_strimwidth($ch['last_body'], 0, 44, '…')) ?><?php endif; ?></span>
          </span>
          <span class="wa-kanan-atas">
            <span class="wa-jam"><?= e(chatWaktu($ch['last_at'])) ?></span>
            <?php if ($ch['unread'] > 0): ?><span class="wa-badge"><?= (int) $ch['unread'] ?></span><?php endif; ?>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </aside>

  <section class="wa-kanan">
    <?php if (!$room): ?>
      <div class="wa-kosong">
        <div><p style="font-family:var(--serif);font-size:19px;color:var(--ivory)">Pilih percakapan</p>
        <span style="font-size:13px">Atau tunggu pesan pertama masuk — room-nya terbuat otomatis.</span></div>
      </div>
    <?php else: $nm = chatNama($room); ?>

      <div class="wa-head">
        <a class="wa-kembali" href="?filter=<?= e($filter) ?>" title="Kembali ke daftar">←</a>
        <span class="wa-av <?= e($room['jenis']) ?>"><?= e(mb_strtoupper(mb_substr($nm, 0, 1))) ?></span>
        <div style="flex:1;min-width:0">
          <h3><?= e($nm) ?></h3>
          <span class="sub">+<?= e($room['wa_number']) ?>
            <?php if ($room['vendor_id']): ?> · vendor<?php endif; ?>
            <?php if ($room['client_id']): ?> · klien · <?= e(stageLabel($room['stage'] ?? '')) ?><?php endif; ?>
          </span>
        </div>

        <form method="post" action="chat-api.php" class="pesta-form" style="display:flex;gap:6px;align-items:center">
          <?= csrfField() ?>
          <input type="hidden" name="aksi" value="pesta">
          <input type="hidden" name="chat" value="<?= $aktif ?>">
          <select name="client" onchange="this.form.requestSubmit()"
                  style="padding:5px 8px;border-radius:8px;border:1px solid var(--ivory-12);
                         background:var(--ink-3);color:var(--ivory);font-size:12.3px;max-width:190px">
            <option value="">— tanpa pesta —</option>
            <?php foreach ($pestaAktif as $p): ?>
              <option value="<?= (int) $p['id'] ?>" <?= $pestaId === (int) $p['id'] ? 'selected' : '' ?>>
                <?= e($p['name'] . ($p['partner_name'] ? ' & ' . $p['partner_name'] : '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>

        <?php if ($pesta): ?>
          <a class="btn sm ghost" href="klien.php?id=<?= (int) $pesta['id'] ?>">Buka pesta</a>
        <?php endif; ?>
        <a class="btn sm ghost" href="https://wa.me/<?= e($room['wa_number']) ?>" target="_blank" rel="noopener">WhatsApp ↗</a>
      </div>

      <?php if (chatKeDiriSendiri($room['wa_number'])): ?>
        <div style="padding:11px 15px;border-bottom:1px solid var(--rose-line);background:var(--rose-soft)">
          <b style="color:var(--rose-text);font-size:13.3px">Nomor ini adalah perangkat gateway itu sendiri.</b>
          <p style="font-size:12.9px;color:var(--ivory-60);margin:5px 0 0;line-height:1.6">
            Pesan yang dikirim ke sini masuk ke obrolan "Message Yourself" — terkirim, tapi balasan
            yang Anda ketik di HP itu adalah pesan <b>keluar</b> dari sudut pandang gateway.
            Webhook hanya menyala untuk pesan <b>masuk</b>, jadi balasan dari nomor ini tidak akan
            pernah sampai ke panel. Uji dengan nomor lain.
          </p>
        </div>
      <?php endif; ?>

      <?php if (!empty($room['is_group'])): $ang = grupAnggota($aktif); ?>
        <div style="padding:9px 15px;border-bottom:var(--card-border);font-size:12.3px;color:var(--ivory-60)">
          <b>Grup.</b> Anggota terdeteksi dari yang pernah bicara di sini:
          <?php if (!$ang): ?><i>belum ada — akan terisi begitu ada yang mengirim pesan.</i>
          <?php else: foreach ($ang as $a): ?>
            <span style="display:inline-block;margin:2px 3px 0 0;padding:2px 8px;border-radius:20px;
                         border:1px solid var(--ivory-12);font-size:11.5px">
              <?= e($a['vendor_nama'] ?: ($a['nama'] ?: '+' . $a['wa_number'])) ?><?= $a['vendor_nama'] ? '' : ' ·  belum jadi vendor' ?>
            </span>
          <?php endforeach; endif; ?>
        </div>
      <?php endif; ?>

      <?php if (!$pestaId && $room['jenis'] === 'vendor'): ?>
        <div class="wa-warn">Room ini belum ditempelkan ke pesta mana pun — pesannya tidak akan
        muncul di riwayat klien. Pilih pesta di atas.</div>
      <?php endif; ?>

      <div class="wa-body" id="waBody">
        <?php $tglTerakhir = ''; foreach ($pesan as $m):
          $tgl = date('Y-m-d', strtotime($m['created_at']));
          if ($tgl !== $tglTerakhir): $tglTerakhir = $tgl; ?>
            <div class="wa-sep"><?= $tgl === date('Y-m-d') ? 'Hari ini' : e(tanggalID($tgl)) ?></div>
          <?php endif;
          [$ikon, $judul, $kelas] = chatCentang($m['wa_status']); ?>
          <div class="b <?= e($m['direction']) ?>" data-id="<?= (int) $m['id'] ?>">
            <?php if (!empty($room['is_group']) && $m['direction'] === 'masuk' && $m['sender_name'] !== ''): ?>
              <span style="display:block;font-size:11.5px;color:var(--ember);font-weight:600;margin-bottom:2px"><?= e($m['sender_name']) ?></span>
            <?php endif; ?>
            <?php if ($m['media_url']): ?>
              <a href="<?= e($m['media_url']) ?>" target="_blank" rel="noopener">
                📎 <?= e($m['media_name'] ?: 'Lampiran') ?></a><br>
            <?php endif; ?>
            <?= chatFormat($m['body']) ?>
            <span class="t <?= e($kelas) ?>" title="<?= e($judul . ($m['wa_error'] ? ' — ' . $m['wa_error'] : '')) ?>">
              <?= date('H.i', strtotime($m['created_at'])) ?>
              <?php if ($m['direction'] === 'keluar'): ?> <?= $ikon ?><?php endif; ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="wa-tulis">
        <textarea id="waTeks" rows="1" placeholder="Ketik pesan… (Enter kirim, Shift+Enter baris baru)"></textarea>
        <button class="wa-kirim" id="waSend" title="Kirim">➤</button>
      </div>

    <?php endif; ?>
  </section>
</div>

<script>
(() => {
  const wa = document.getElementById('wa');
  if (!wa) return;
  const chat = +wa.dataset.chat;
  if (!chat) return;

  const body = document.getElementById('waBody');
  const teks = document.getElementById('waTeks');
  const kirim = document.getElementById('waSend');
  const list = document.getElementById('waList');
  const csrf = document.querySelector('input[name="_csrf"]')?.value || '';
  let maxId = +wa.dataset.max || 0;
  let sibuk = false;

  const keBawah = () => { body.scrollTop = body.scrollHeight; };

  // Tinggi dihitung dari jarak sebenarnya elemen ke puncak dokumen, bukan
  // ditebak lewat calc(). Judul halaman, spanduk peringatan, dan flash
  // tingginya berubah-ubah — angka tetap apa pun pasti meleset di salah satu
  // keadaan, dan meleset di sini berarti kotak ketik terdorong keluar layar
  // sehingga harus digulir dulu sebelum bisa membalas.
  function ukurTinggi(){
    const atas = wa.getBoundingClientRect().top + window.scrollY;
    // Kalau kartu diagnosa di atas terlalu tinggi, sisa layar bisa habis dan
    // kotak ketik terdorong keluar pandangan. Batas bawah 70% tinggi layar
    // menjamin ruang percakapan selalu cukup untuk dipakai — halamannya boleh
    // digulir, kotak ketiknya tidak boleh hilang.
    const sisa = window.innerHeight - atas - 18;
    wa.style.height = Math.max(Math.round(window.innerHeight * 0.7), sisa) + 'px';
  }
  ukurTinggi();
  addEventListener('resize', ukurTinggi);
  keBawah();

  // Textarea tumbuh mengikuti isi, sampai batas maks-height dari CSS.
  const tumbuh = () => { teks.style.height = 'auto'; teks.style.height = Math.min(teks.scrollHeight, 140) + 'px'; };
  teks.addEventListener('input', tumbuh);

  function gambar(m) {
    const d = document.createElement('div');
    d.className = 'b ' + m.dir;
    d.dataset.id = m.id;
    let isi = m.media ? `<a href="${m.media.url}" target="_blank" rel="noopener">📎 ${m.media.nama || 'Lampiran'}</a><br>` : '';
    isi += m.html;
    isi += `<span class="t ${m.kelas}" title="${m.judul}">${m.jam}${m.dir === 'keluar' ? ' ' + m.ikon : ''}</span>`;
    d.innerHTML = isi;
    return d;
  }

  async function poll() {
    if (sibuk || document.hidden) return;
    sibuk = true;
    try {
      const u = new URL('chat-api.php', location.href);
      u.searchParams.set('aksi', 'poll');
      u.searchParams.set('chat', chat);
      u.searchParams.set('after', maxId);
      u.searchParams.set('fokus', document.hasFocus() ? '1' : '0');
      const r = await fetch(u, { headers: { 'X-Requested-With': 'fetch' } });
      if (!r.ok) return;
      const d = await r.json();

      const nempel = body.scrollHeight - body.scrollTop - body.clientHeight < 90;
      for (const m of d.pesan) {
        // Gelembung sementara dari kirim optimistis diganti versi server.
        const lama = body.querySelector('.b.sementara');
        if (m.dir === 'keluar' && lama) lama.remove();
        if (body.querySelector(`[data-id="${m.id}"]`)) continue;
        body.appendChild(gambar(m));
        maxId = Math.max(maxId, m.id);
      }
      if (d.pesan.length && nempel) keBawah();

      // Perbarui lencana di daftar kiri tanpa memuat ulang halaman.
      for (const rm of d.room || []) {
        const a = list?.querySelector(`a[href*="chat=${rm.id}"]`);
        if (!a) continue;
        a.querySelector('.wa-cuplik').textContent = rm.cuplik;
        a.querySelector('.wa-jam').textContent = rm.waktu;
        let b = a.querySelector('.wa-badge');
        if (rm.belum > 0 && rm.id !== chat) {
          if (!b) { b = document.createElement('span'); b.className = 'wa-badge'; a.querySelector('.wa-kanan-atas').appendChild(b); }
          b.textContent = rm.belum;
        } else if (b) b.remove();
      }
    } catch (_) { /* jaringan putus sesaat bukan alasan menghentikan polling */ }
    finally { sibuk = false; }
  }

  async function kirimPesan() {
    const isi = teks.value.trim();
    if (!isi) return;
    kirim.disabled = true;

    // Tampilkan dulu, konfirmasi menyusul — kalau gagal, ditandai merah.
    const sem = document.createElement('div');
    sem.className = 'b keluar sementara';
    sem.innerHTML = isi.replace(/[<>&]/g, c => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]))
      + '<span class="t">mengirim…</span>';
    body.appendChild(sem); keBawah();
    teks.value = ''; tumbuh();

    try {
      const fd = new FormData();
      fd.append('aksi', 'kirim'); fd.append('chat', chat);
      fd.append('teks', isi); fd.append('_csrf', csrf);
      const r = await fetch('chat-api.php', { method: 'POST', body: fd });
      const d = await r.json();
      if (!d.ok) {
        sem.classList.remove('sementara');
        sem.querySelector('.t').textContent = '⚠ ' + (d.error || 'gagal');
        sem.querySelector('.t').className = 't bad';
      } else if (d.wa_url) {
        // Gateway mati: buka wa.me supaya pesan tetap bisa dikirim manual.
        window.open(d.wa_url, '_blank', 'noopener');
      }
      poll();
    } catch (_) {
      sem.querySelector('.t').textContent = '⚠ jaringan';
    } finally { kirim.disabled = false; teks.focus(); }
  }

  kirim.addEventListener('click', kirimPesan);
  teks.addEventListener('keydown', ev => {
    if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); kirimPesan(); }
  });

  // Jeda polling menyesuaikan keadaan: cepat saat dilihat, lambat saat
  // tab di belakang, berhenti saat tab tersembunyi. Ini yang menjaga panel
  // tetap ringan walau dibuka seharian.
  let jeda = 4000, timer = null;
  const jadwalkan = () => { clearTimeout(timer); timer = setTimeout(async () => { await poll(); jadwalkan(); }, jeda); };
  document.addEventListener('visibilitychange', () => { jeda = document.hidden ? 30000 : 4000; jadwalkan(); });
  window.addEventListener('focus', () => { jeda = 4000; poll(); });
  window.addEventListener('blur',  () => { jeda = 12000; });
  jadwalkan();

  // Form pemilih pesta dikirim lewat fetch supaya halaman tidak lompat.
  document.querySelector('.pesta-form')?.addEventListener('submit', async ev => {
    ev.preventDefault();
    await fetch('chat-api.php', { method: 'POST', body: new FormData(ev.target) });
    location.reload();
  });
})();
</script>

<?php adminFoot();
