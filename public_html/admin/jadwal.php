<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/meeting.php';
require_once __DIR__ . '/../inc/pipeline.php';
require_once __DIR__ . '/../inc/sheets.php';
$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'save') {
            $id    = (int) ($_POST['id'] ?? 0);
            $start = trim($_POST['start_at'] ?? '');
            $dur   = max(15, (int) ($_POST['duration'] ?? 60));
            if (!strtotime($start)) throw new RuntimeException('Waktu mulai tidak valid.');

            $email = trim($_POST['client_email'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email klien tidak valid.');

            $startAt = date('Y-m-d H:i:s', strtotime($start));
            $endAt   = date('Y-m-d H:i:s', strtotime($start) + $dur * 60);

            // Validasi email tambahan sebelum dikirim ke Google.
            $extra = [];
            foreach (preg_split('/[,;\s]+/', $_POST['extra_emails'] ?? '') ?: [] as $x) {
                $x = trim($x);
                if ($x === '') continue;
                if (!filter_var($x, FILTER_VALIDATE_EMAIL)) throw new RuntimeException("Email tambahan tidak valid: $x");
                $extra[] = $x;
            }

            $data = [
                'title'         => trim($_POST['title'] ?? '') ?: 'Konsultasi dengan ' . trim($_POST['client_name'] ?? ''),
                'client_name'   => trim($_POST['client_name'] ?? ''),
                'client_email'  => $email,
                'client_phone'  => trim($_POST['client_phone'] ?? ''),
                'extra_emails'  => implode(', ', $extra),
                'start_at'      => $startAt,
                'end_at'        => $endAt,
                'timezone'      => trim($_POST['timezone'] ?? APP_TZ),
                'mode'          => in_array($_POST['mode'] ?? '', ['onsite','zoom','meet','phone'], true) ? $_POST['mode'] : 'meet',
                'location_text' => trim($_POST['location_text'] ?? ''),
                'notes'         => trim($_POST['notes'] ?? ''),
                'event_id'      => ($_POST['event_id'] ?? '') !== '' ? (int) $_POST['event_id'] : null,
                'client_id'     => ($_POST['client_id'] ?? '') !== '' ? (int) $_POST['client_id'] : null,
                'created_by'    => $user['id'],
            ];
            if ($data['client_name'] === '') throw new RuntimeException('Nama klien wajib diisi.');

            if ($id) {
                $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
                q("UPDATE meetings SET $set WHERE id = ?", [...array_values($data), $id]);
            } else {
                $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
                q("INSERT INTO meetings ($cols) VALUES (" . implode(',', array_fill(0, count($data), '?')) . ")", array_values($data));
                $id = insertId();
            }

            // Sambungan antara jadwal dan pipeline: konsultasi terjadi di tahap
            // Spesifikasi, jadi prospek yang dijadwalkan bertemu ikut naik ke
            // sana. Dulu baris ini memanggil tahap 'meeting' yang sudah dihapus
            // sejak v8 — setiap jadwal untuk prospek baru gagal setengah jalan:
            // barisnya tersimpan, tapi kalender Google dan email tidak pernah
            // terkirim.
            if (!empty($data['client_id'])) {
                $cid = (int) $data['client_id'];
                clientMajuKe($cid, 'spesifikasi', $user['id'], 'Konsultasi dijadwalkan.');
                clientLog($cid, 'meeting', 'Pertemuan dijadwalkan: ' . $data['title'],
                    tanggalID($startAt, true) . ' WIB · ' .
                    ['meet'=>'Google Meet','zoom'=>'Zoom','onsite'=>'Tatap muka','phone'=>'Telepon'][$data['mode']],
                    $user['id']);
                // Tindakan berikutnya diarahkan ke tanggal pertemuan itu sendiri.
                q("UPDATE clients SET next_action = ?, next_action_at = ? WHERE id = ?",
                  ['Jalankan pertemuan, lalu catat hasilnya', date('Y-m-d', strtotime($startAt)), $cid]);
            }

            $errors = meetingSync($id, isset($_POST['send_email']));
            sheetSyncQuiet();
            flash($errors
                ? "Jadwal tersimpan, tetapi ada yang perlu diperiksa:\n" . implode("\n", $errors)
                : 'Jadwal tersimpan. Undangan kalender dan email sudah dikirim ke klien.',
                $errors ? 'warn' : 'ok');
            redirect('admin/jadwal.php?edit=' . $id);
        }

        elseif ($act === 'resync') {
            $errors = meetingSync((int) $_POST['id'], isset($_POST['send_email']));
            flash($errors ? "Sinkronisasi selesai dengan catatan:\n" . implode("\n", $errors) : 'Sinkronisasi berhasil.',
                  $errors ? 'warn' : 'ok');
        }
        elseif ($act === 'cancel') {
            $errors = meetingCancel((int) $_POST['id']);
            flash($errors ? "Dibatalkan, dengan catatan:\n" . implode("\n", $errors) : 'Jadwal dibatalkan. Klien menerima email pembatalan dari Google Calendar.',
                  $errors ? 'warn' : 'ok');
        }
        elseif ($act === 'minutes') {
            $mid = (int) $_POST['id'];
            $isi = trim($_POST['minutes'] ?? '');
            q("UPDATE meetings SET minutes = ?, minutes_at = NOW() WHERE id = ?", [$isi, $mid]);

            // Notulen ikut masuk garis waktu klien, supaya riwayatnya di satu tempat.
            $m = one("SELECT client_id, title FROM meetings WHERE id = ?", [$mid]);
            if ($m && $m['client_id'] && $isi !== '') {
                clientLog((int) $m['client_id'], 'meeting', 'Notulen: ' . $m['title'],
                          mb_substr($isi, 0, 1500), $user['id']);
            }
            // Tindak lanjut opsional langsung dari form notulen
            if ($m && $m['client_id'] && trim($_POST['tindak'] ?? '') !== '') {
                q("UPDATE clients SET next_action = ?, next_action_at = ? WHERE id = ?",
                  [trim($_POST['tindak']), trim($_POST['tindak_at'] ?? '') ?: date('Y-m-d', strtotime('+3 day')), $m['client_id']]);
            }
            flash('Notulen tersimpan.');
        }
        elseif ($act === 'zoom_rekaman') {
            $mid = (int) $_POST['id'];
            $m = one("SELECT zoom_meeting_id FROM meetings WHERE id = ?", [$mid]);
            if (!$m || !$m['zoom_meeting_id']) throw new RuntimeException('Pertemuan ini tidak memakai Zoom.');
            $rek = zoomFetchRecording($m['zoom_meeting_id']);
            if (!$rek) {
                flash('Belum ada rekaman untuk pertemuan ini. Rekaman cloud butuh paket Zoom berbayar dan baru siap beberapa menit setelah meeting selesai.', 'warn');
            } else {
                q("UPDATE meetings SET zoom_recording_url = ?, zoom_recording_at = NOW(), zoom_duration = ? WHERE id = ?",
                  [$rek['share_url'] ?: $rek['play_url'], $rek['duration'], $mid]);
                flash('Tautan rekaman Zoom berhasil diambil.');
            }
        }
        elseif ($act === 'outcome') {
            $mid  = (int) $_POST['id'];
            $hasil = in_array($_POST['outcome'] ?? '', ['lanjut','pikir','batal'], true) ? $_POST['outcome'] : '';
            $ket  = trim($_POST['outcome_note'] ?? '');
            q("UPDATE meetings SET outcome = ?, outcome_note = ?, status = 'done' WHERE id = ?", [$hasil, $ket, $mid]);

            $m = one("SELECT * FROM meetings WHERE id = ?", [$mid]);
            $pesan = 'Hasil pertemuan dicatat.';

            if ($m && $m['client_id']) {
                $label = ['lanjut' => 'Lanjut ke penawaran', 'pikir' => 'Masih dipikir', 'batal' => 'Tidak jadi'][$hasil] ?? '—';
                clientLog((int) $m['client_id'], 'meeting', 'Hasil pertemuan: ' . $label, $ket, $user['id']);

                // Hasil pertemuan langsung menentukan langkah berikutnya.
                if ($hasil === 'lanjut') {
                    // "Lanjut" artinya penawarannya siap DISUSUN — belum dikirim.
                    // Tahap Penawaran berarti "sudah dikirim, menunggu jawaban";
                    // memindahkannya ke sana di titik ini membuat papan bilang
                    // klien sedang menimbang dokumen yang belum pernah ada.
                    $cid = (int) $m['client_id'];
                    clientMajuKe($cid, 'spesifikasi', $user['id'], $ket);
                    q("UPDATE clients SET next_action = ?, next_action_at = ? WHERE id = ?",
                      ['Susun dan kirim penawaran', date('Y-m-d', strtotime('+3 day')), $cid]);
                    $pesan .= ' Tindakan berikutnya: susun dan kirim penawaran (tenggat 3 hari).';
                } elseif ($hasil === 'batal') {
                    clientSetStage((int) $m['client_id'], 'batal', $user['id'], $ket ?: 'Tidak berlanjut setelah konsultasi.');
                    $pesan .= ' Klien dipindahkan ke arsip Tidak jadi.';
                } elseif ($hasil === 'pikir') {
                    q("UPDATE clients SET next_action = ?, next_action_at = ? WHERE id = ?",
                      ['Follow up hasil konsultasi', date('Y-m-d', strtotime('+3 day')), $m['client_id']]);
                    $pesan .= ' Pengingat follow-up disetel 3 hari lagi.';
                }
            }
            flash($pesan);
        }
        elseif ($act === 'done') {
            q("UPDATE meetings SET status = 'done' WHERE id = ?", [(int) $_POST['id']]);
            flash('Ditandai selesai.');
        }
        elseif ($act === 'delete') {
            $m = one("SELECT * FROM meetings WHERE id = ?", [(int) $_POST['id']]);
            if ($m && $m['status'] !== 'canceled') meetingCancel((int) $m['id']);
            q("DELETE FROM meetings WHERE id = ?", [(int) $_POST['id']]);
            flash('Jadwal dihapus.');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    if ($act !== 'save') redirect('admin/jadwal.php');
}

$edit   = isset($_GET['edit']) ? one("SELECT * FROM meetings WHERE id = ?", [(int) $_GET['edit']]) : null;
$isNew  = isset($_GET['new']);
$events = all("SELECT id, title, event_date FROM events ORDER BY event_date DESC LIMIT 100");
$clients = all("SELECT id, name, partner_name, stage, wedding_date FROM clients
                WHERE stage NOT IN ('selesai','batal') ORDER BY name LIMIT 200");
// Dipanggil dari tombol "Jadwalkan pertemuan" di halaman klien.
$preClient = (int) ($_GET['client'] ?? 0);
$preData   = $preClient ? one("SELECT * FROM clients WHERE id = ?", [$preClient]) : null;
$klienList = all("SELECT id, name, partner_name, stage, email, phone FROM clients
                  WHERE stage NOT IN ('selesai','batal') ORDER BY name LIMIT 300");
$preClient = (int) ($_GET['client'] ?? 0);
$pc = ($preClient && !$edit) ? one("SELECT * FROM clients WHERE id = ?", [$preClient]) : null;

adminHead('Jadwal klien', 'jadwal');

// ---------- Peringatan integrasi ----------
$warn = [];
if (!googleConnected()) $warn[] = 'Google Calendar belum terhubung — undangan kalender tidak akan terkirim.';
if (!zoomConfigured())  $warn[] = 'Zoom belum dikonfigurasi — mode Zoom belum bisa dipakai (Google Meet tetap bisa).';
if ($warn) {
    echo '<div class="flash warn"><span>' . e(implode(' ', $warn))
       . ' <a href="integrasi.php" style="text-decoration:underline">Buka Integrasi →</a></span></div>';
}

if ($edit || $isNew):
    $v   = fn(string $k, $d = '') => e($edit[$k] ?? $d);
    $dur = $edit ? max(15, (int) round((strtotime($edit['end_at']) - strtotime($edit['start_at'])) / 60)) : (int) setting('meeting_duration', '60');
    pageHead($edit ? 'Ubah jadwal' : 'Jadwal baru',
             'Simpan sekali — Zoom dibuat, event masuk Google Calendar, dan undangan terkirim ke email klien.',
             '<a class="btn ghost" href="jadwal.php">← Kembali</a>');
?>
<form method="post">
  <?= csrfField() ?>
  <input type="hidden" name="act" value="save">
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

  <div class="g-side">
    <div>
      <div class="card">
        <h2>Klien</h2>
        <div class="field">
          <label for="cl">Kaitkan ke klien</label>
          <select id="cl" name="client_id">
            <option value="">— tidak dikaitkan —</option>
            <?php foreach ($klienList as $k): ?>
              <option value="<?= $k['id'] ?>"
                      data-email="<?= e($k['email']) ?>" data-phone="<?= e($k['phone']) ?>"
                      data-nama="<?= e(trim($k['name'] . ($k['partner_name'] ? ' & ' . $k['partner_name'] : ''))) ?>"
                      <?= (int) ($edit['client_id'] ?? $preClient ?? 0) === (int) $k['id'] ? 'selected' : '' ?>>
                <?= e($k['name'] . ($k['partner_name'] ? ' & ' . $k['partner_name'] : '')) ?> · <?= e(stageLabel($k['stage'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="hint">Kalau dikaitkan, pertemuan ini masuk ke linimasa klien dan hasilnya bisa langsung menggeser tahap pipeline. <a href="klien.php?new=1" style="color:var(--ember)">Tambah klien baru →</a></p>
        </div>
        <div class="row c2">
          <div class="field"><label for="cn">Nama klien</label>
            <input type="text" id="cn" name="client_name" required value="<?= e($edit['client_name'] ?? ($pc['name'] ?? '') . (!empty($pc['partner_name']) ? ' & ' . $pc['partner_name'] : '')) ?>" placeholder="Winda &amp; Zakki"></div>
          <div class="field"><label for="cp">Nomor WhatsApp</label>
            <input type="text" id="cp" name="client_phone" value="<?= e($edit['client_phone'] ?? $pc['phone'] ?? '') ?>" placeholder="628123456789"></div>
        </div>
        <div class="field"><label for="ce">Email klien</label>
          <input type="email" id="ce" name="client_email" required value="<?= e($edit['client_email'] ?? $pc['email'] ?? '') ?>">
          <p class="hint">Alamat ini yang diundang ke event Google Calendar — klien bisa menerima atau menolak langsung dari emailnya.</p></div>
        <div class="field"><label for="xe">Undang email lain</label>
          <input type="text" id="xe" name="extra_emails" value="<?= $v('extra_emails') ?>" placeholder="orangtua@email.com, vendor@email.com">
          <p class="hint">Pisahkan dengan koma. Semuanya ikut diundang ke event yang sama.</p></div>
      </div>

      <div class="card">
        <h2>Waktu</h2>
        <div class="row c3">
          <div class="field"><label for="sa">Mulai</label>
            <input type="datetime-local" id="sa" name="start_at" required
                   value="<?= $edit ? date('Y-m-d\TH:i', strtotime($edit['start_at'])) : date('Y-m-d\TH:i', strtotime('tomorrow 10:00')) ?>"></div>
          <div class="field"><label for="du">Durasi</label>
            <select id="du" name="duration">
              <?php foreach ([30, 45, 60, 90, 120, 180] as $d): ?>
                <option value="<?= $d ?>" <?= $dur === $d ? 'selected' : '' ?>><?= $d ?> menit</option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label for="tz">Zona waktu</label>
            <select id="tz" name="timezone">
              <?php foreach (['Asia/Jakarta' => 'WIB', 'Asia/Makassar' => 'WITA', 'Asia/Jayapura' => 'WIT'] as $z => $lbl): ?>
                <option value="<?= $z ?>" <?= ($edit['timezone'] ?? APP_TZ) === $z ? 'selected' : '' ?>><?= $lbl ?> — <?= $z ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
        <div id="conflictBox"></div>
      </div>

      <div class="card">
        <h2>Format pertemuan</h2>
        <div class="field"><label for="md">Cara bertemu</label>
          <select id="md" name="mode">
            <option value="meet"   <?= ($edit['mode'] ?? 'meet') === 'meet' ? 'selected' : '' ?>>Google Meet — tautan dibuat otomatis</option>
            <option value="zoom"   <?= ($edit['mode'] ?? '') === 'zoom' ? 'selected' : '' ?>>Zoom — tautan dibuat otomatis</option>
            <option value="onsite" <?= ($edit['mode'] ?? '') === 'onsite' ? 'selected' : '' ?>>Tatap muka di studio / venue</option>
            <option value="phone"  <?= ($edit['mode'] ?? '') === 'phone' ? 'selected' : '' ?>>Telepon</option>
          </select></div>
        <div class="field" id="locWrap" style="display:none">
          <label for="lt">Alamat pertemuan</label>
          <input type="text" id="lt" name="location_text" value="<?= $v('location_text', setting('address_street', '')) ?>">
        </div>
        <div class="field"><label for="cl">Klien terkait</label>
          <select id="cl" name="client_id">
            <option value="">— tanpa kaitan pipeline —</option>
            <?php foreach ($clients as $cl):
              $sel = (int) ($edit['client_id'] ?? $preClient) === (int) $cl['id']; ?>
              <option value="<?= $cl['id'] ?>" <?= $sel ? 'selected' : '' ?>>
                <?= e($cl['name'] . ($cl['partner_name'] ? ' & ' . $cl['partner_name'] : '')) ?> · <?= e(stageLabel($cl['stage'])) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="hint">Bila dikaitkan, hasil pertemuan akan otomatis menggerakkan tahap klien di papan pipeline.</p></div>

        <div class="field"><label for="ev">Kaitkan ke event</label>
          <select id="ev" name="event_id">
            <option value="">— tidak dikaitkan —</option>
            <?php foreach ($events as $ev): ?>
              <option value="<?= $ev['id'] ?>" <?= (int) ($edit['event_id'] ?? 0) === (int) $ev['id'] ? 'selected' : '' ?>>
                <?= e($ev['title']) ?> · <?= date('M Y', strtotime($ev['event_date'])) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label for="nt">Agenda / catatan</label>
          <textarea id="nt" name="notes" rows="4" placeholder="Bahas rundown akad, cek ketersediaan tanggal, hitung kebutuhan kru."><?= $v('notes') ?></textarea>
          <p class="hint">Ikut masuk ke deskripsi event kalender dan email klien.</p></div>
      </div>
    </div>

    <div>
      <div class="card">
        <h2>Judul &amp; pengiriman</h2>
        <div class="field"><label for="ti">Judul pertemuan</label>
          <input type="text" id="ti" name="title" value="<?= $v('title') ?>" placeholder="Konsultasi pertama — Callalily Party">
          <p class="hint">Kosongkan untuk dibuat dari nama klien.</p></div>
        <label class="check"><input type="checkbox" name="send_email" checked>
          Kirim email undangan bermerek + berkas .ics</label>
        <p class="hint">Google Calendar tetap mengirim undangan resminya sendiri. Email ini pelengkap untuk klien yang memakai Outlook atau Apple Calendar.</p>
        <div class="sticky-actions">
          <button class="btn solid" type="submit"><?= $edit ? 'Simpan &amp; sinkronkan ulang' : 'Buat jadwal &amp; kirim undangan' ?></button>
        </div>
      </div>

      <?php if ($edit && strtotime($edit['start_at']) < time() && $edit['status'] !== 'canceled'): ?>
      <div class="card" style="border-color:rgba(233,168,92,.4)">
        <h2>Bagaimana hasilnya?</h2>
        <p class="sub">Pertemuan ini sudah lewat. Catat hasilnya — tahap klien ikut berpindah sendiri.</p>
        <form method="post">
          <?= csrfField() ?><input type="hidden" name="act" value="outcome"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
          <div class="field">
            <label for="oc">Hasil</label>
            <select id="oc" name="outcome">
              <option value="">— belum ditentukan —</option>
              <option value="lanjut" <?= $edit['outcome'] === 'lanjut' ? 'selected' : '' ?>>Lanjut — siap dikirimi penawaran</option>
              <option value="pikir"  <?= $edit['outcome'] === 'pikir'  ? 'selected' : '' ?>>Masih menimbang — perlu ditindak lagi</option>
              <option value="batal"  <?= $edit['outcome'] === 'batal'  ? 'selected' : '' ?>>Tidak lanjut</option>
            </select>
          </div>
          <div class="field">
            <label for="on">Catatan</label>
            <textarea id="on" name="outcome_note" rows="3" placeholder="Apa yang dibahas, apa yang diminta, apa yang mengganjal."><?= e($edit['outcome_note']) ?></textarea>
          </div>
          <button class="btn solid" type="submit" style="width:100%">Simpan hasil</button>
        </form>
        <?php if ($edit['client_id']): ?>
          <p class="hint" style="margin-top:12px">Hasil "Lanjut" memindahkan klien ke tahap <b>Penawaran dikirim</b> dan menyetel tindakan berikutnya. "Tidak lanjut" memindahkan ke <b>Tidak jadi</b>.</p>
        <?php else: ?>
          <p class="hint" style="margin-top:12px">Jadwal ini belum dikaitkan ke klien, jadi hasilnya hanya dicatat di sini.</p>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($edit && strtotime($edit['start_at']) < time() + 3600): ?>
      <div class="card">
        <h2>Notulen</h2>
        <p class="sub">Catatan selama atau sesudah pertemuan. Tersimpan di sini dan ikut masuk ke riwayat klien.</p>
        <form method="post">
          <?= csrfField() ?><input type="hidden" name="act" value="minutes"><input type="hidden" name="id" value="<?= $edit['id'] ?>">
          <div class="field">
            <label for="mn">Apa yang dibahas</label>
            <textarea id="mn" name="minutes" rows="9" placeholder="Poin yang disepakati, keberatan klien, angka yang disebut, siapa yang mengerjakan apa."><?= e($edit['minutes'] ?? '') ?></textarea>
          </div>
          <?php if ($edit['client_id']): ?>
            <div class="row c2">
              <div class="field"><label for="tk">Tindak lanjut</label>
                <input type="text" id="tk" name="tindak" placeholder="Kirim revisi penawaran paket B"></div>
              <div class="field"><label for="tka">Paling lambat</label>
                <input type="date" id="tka" name="tindak_at" value="<?= date('Y-m-d', strtotime('+3 day')) ?>"></div>
            </div>
            <p class="hint" style="margin:-8px 0 14px">Diisi = tindakan berikutnya klien ikut diperbarui.</p>
          <?php endif; ?>
          <button class="btn solid" type="submit">Simpan notulen</button>
          <?php if (!empty($edit['minutes_at'])): ?>
            <p class="hint" style="margin-top:10px">Terakhir disimpan <?= tanggalID($edit['minutes_at'], true) ?></p>
          <?php endif; ?>
        </form>

        <?php if ($edit['zoom_meeting_id']): ?>
          <hr class="hr">
          <span class="lab">Rekaman Zoom</span>
          <?php if ($edit['zoom_recording_url']): ?>
            <p style="margin-top:8px"><a class="btn sm" href="<?= e($edit['zoom_recording_url']) ?>" target="_blank" rel="noopener">Buka rekaman ↗</a>
              <?php if ($edit['zoom_duration']): ?><span class="mono muted" style="margin-left:8px"><?= (int) $edit['zoom_duration'] ?> menit</span><?php endif; ?></p>
          <?php else: ?>
            <form method="post" style="margin-top:8px">
              <?= csrfField() ?><input type="hidden" name="act" value="zoom_rekaman"><input type="hidden" name="id" value="<?= $edit['id'] ?>">
              <button class="btn sm ghost" type="submit">Cek rekaman</button>
            </form>
            <p class="hint">Hanya tersedia bila akun Zoom berpaket berbayar dan cloud recording dinyalakan. Scope <code>recording:read:admin</code> harus ditambahkan di aplikasi Zoom.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="card" style="border-color:var(--ember-line)">
        <h2>Hasil pertemuan</h2>
        <p class="sub">Ini yang menentukan langkah berikutnya. Klien yang dikaitkan akan otomatis berpindah tahap.</p>
        <form method="post">
          <?= csrfField() ?><input type="hidden" name="act" value="outcome"><input type="hidden" name="id" value="<?= $edit['id'] ?>">
          <div class="field">
            <label for="oc">Bagaimana hasilnya</label>
            <select id="oc" name="outcome">
              <option value="" <?= ($edit['outcome'] ?? '') === '' ? 'selected' : '' ?>>— belum dicatat —</option>
              <option value="lanjut" <?= ($edit['outcome'] ?? '') === 'lanjut' ? 'selected' : '' ?>>Lanjut — kirim penawaran</option>
              <option value="pikir"  <?= ($edit['outcome'] ?? '') === 'pikir'  ? 'selected' : '' ?>>Masih dipikir — follow up 3 hari lagi</option>
              <option value="batal"  <?= ($edit['outcome'] ?? '') === 'batal'  ? 'selected' : '' ?>>Tidak jadi — masuk arsip</option>
            </select>
          </div>
          <div class="field">
            <label for="ocn">Catatan</label>
            <textarea id="ocn" name="outcome_note" rows="3" placeholder="Minta revisi paket, budget di bawah perkiraan, dsb."><?= e($edit['outcome_note'] ?? '') ?></textarea>
          </div>
          <button class="btn solid" type="submit">Simpan hasil</button>
        </form>
      </div>
      <?php endif; ?>

      <?php if ($edit): ?>
      <div class="card">
        <h2>Status integrasi</h2>
        <table class="tbl" style="font-size:13px">
          <tr><td class="lab" style="padding:8px 0">Google Calendar</td>
            <td class="right" style="padding:8px 0">
              <?= $edit['gcal_event_id'] ? '<span class="pill live dot">Tersinkron</span>' : '<span class="pill draft">Belum</span>' ?></td></tr>
          <?php if ($edit['gcal_html_link']): ?>
            <tr><td colspan="2" style="padding:8px 0"><a class="btn sm ghost" href="<?= e($edit['gcal_html_link']) ?>" target="_blank" rel="noopener">Buka di Google Calendar ↗</a></td></tr>
          <?php endif; ?>
          <?php if ($edit['meet_url']): ?>
            <tr><td colspan="2" style="padding:8px 0"><span class="lab">Tautan Google Meet</span><br>
              <a class="mono" href="<?= e($edit['meet_url']) ?>" target="_blank" rel="noopener" style="color:var(--ember);word-break:break-all"><?= e($edit['meet_url']) ?></a></td></tr>
          <?php endif; ?>
          <tr><td class="lab" style="padding:8px 0">Zoom</td>
            <td class="right" style="padding:8px 0">
              <?= $edit['zoom_meeting_id'] ? '<span class="pill live dot">Dibuat</span>' : '<span class="pill draft">—</span>' ?></td></tr>
          <?php if ($edit['zoom_join_url']): ?>
            <tr><td colspan="2" style="padding:8px 0">
              <span class="lab">Tautan untuk klien</span><br>
              <a class="mono" href="<?= e($edit['zoom_join_url']) ?>" target="_blank" rel="noopener" style="color:var(--ember);word-break:break-all"><?= e($edit['zoom_join_url']) ?></a>
              <?php if ($edit['zoom_passcode']): ?><br><span class="lab">Passcode</span> <code><?= e($edit['zoom_passcode']) ?></code><?php endif; ?>
              <?php if ($edit['zoom_start_url']): ?>
                <br><br><span class="lab">Tautan host — jangan dibagikan</span><br>
                <a class="mono muted" href="<?= e($edit['zoom_start_url']) ?>" target="_blank" rel="noopener" style="word-break:break-all">Mulai sebagai host ↗</a>
              <?php endif; ?>
            </td></tr>
          <?php endif; ?>
        </table>
        <?php if ($edit['sync_error']): ?>
          <div class="flash err" style="margin:16px 0 0"><span><?= nl2br(e($edit['sync_error'])) ?></span></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</form>

<script>
(() => {
  const md = document.getElementById('md'), loc = document.getElementById('locWrap');
  const toggleLoc = () => loc.style.display = md.value === 'onsite' ? '' : 'none';
  md.addEventListener('change', toggleLoc); toggleLoc();

  // Cek bentrok jadwal tanpa menunggu simpan.
  const sa = document.getElementById('sa'), du = document.getElementById('du');
  const box = document.getElementById('conflictBox');
  const id = document.querySelector('[name=id]').value;
  let t;
  const check = () => {
    clearTimeout(t);
    t = setTimeout(async () => {
      if (!sa.value) return;
      const r = await fetch(`cek-bentrok.php?start=${encodeURIComponent(sa.value)}&dur=${du.value}&id=${id}`);
      const j = await r.json();
      box.innerHTML = j.conflicts?.length
        ? `<div class="flash warn" style="margin:6px 0 0"><span>Bentrok dengan: ${j.conflicts.map(c => c.label).join(', ')}</span></div>`
        : '';
    }, 350);
  };
  sa.addEventListener('change', check); du.addEventListener('change', check); check();
})();
</script>

<?php else:
$filter = $_GET['f'] ?? 'upcoming';
$where  = match ($filter) {
    'past'     => "WHERE start_at < NOW() AND status <> 'canceled'",
    'canceled' => "WHERE status = 'canceled'",
    default    => "WHERE start_at >= NOW() AND status = 'scheduled'",
};
$order = $filter === 'upcoming' ? 'ASC' : 'DESC';
$fMode = in_array($_GET['mode'] ?? '', ['meet','zoom','onsite','phone'], true) ? $_GET['mode'] : '';
$fCari = trim($_GET['q'] ?? '');
$par = [];
$extra = '';
if ($fMode) { $extra .= " AND m.mode = ?"; $par[] = $fMode; }
if ($fCari) { $extra .= " AND (m.client_name LIKE ? OR m.client_email LIKE ? OR m.title LIKE ?)";
              array_push($par, "%$fCari%", "%$fCari%", "%$fCari%"); }

$rows  = all("SELECT m.*, c.name AS klien_nama, c.stage AS klien_stage FROM meetings m
              LEFT JOIN clients c ON c.id = m.client_id
              " . str_replace(['start_at','status'], ['m.start_at','m.status'], $where) . $extra . "
              ORDER BY m.start_at $order LIMIT 200", $par);

pageHead('Jadwal klien', 'Satu tempat untuk semua pertemuan: kalender, tautan rapat, dan undangan email.',
         '<a class="btn solid" href="?new=1">+ Jadwal baru</a>');
?>
<div class="tabs">
  <a href="?f=upcoming" class="<?= $filter === 'upcoming' ? 'on' : '' ?>">Akan datang</a>
  <a href="?f=past"     class="<?= $filter === 'past' ? 'on' : '' ?>">Sudah lewat</a>
  <a href="?f=canceled" class="<?= $filter === 'canceled' ? 'on' : '' ?>">Dibatalkan</a>
</div>

<form method="get" class="filterbar" style="margin-bottom:18px">
  <input type="hidden" name="f" value="<?= e($filter) ?>">
  <input type="text" name="q" value="<?= e($fCari) ?>" placeholder="Cari nama klien, email, atau agenda">
  <select name="mode"><option value="">Semua format</option>
    <?php foreach (['meet'=>'Google Meet','zoom'=>'Zoom','onsite'=>'Tatap muka','phone'=>'Telepon'] as $k=>$v): ?>
      <option value="<?= $k ?>" <?= $fMode === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
  <button class="btn sm" type="submit">Saring</button>
  <?php if ($fCari || $fMode): ?><a class="btn sm ghost" href="?f=<?= e($filter) ?>">Bersihkan</a><?php endif; ?>
  <span class="hit"><?= count($rows) ?> jadwal</span>
</form>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty">
      <p>Belum ada jadwal di daftar ini</p>
      <span>Buat jadwal baru — tautan rapat, undangan kalender, dan email ke klien dibuat sekaligus dalam satu langkah.</span>
      <a class="btn solid" href="?new=1">Buat jadwal</a>
    </div>
  <?php else: ?>
    <table class="tbl">
      <thead><tr><th>Waktu</th><th>Klien</th><th>Agenda</th><th>Format</th><th>Sinkron</th><th></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $m):
        $modeLbl = ['meet' => 'Google Meet', 'zoom' => 'Zoom', 'onsite' => 'Tatap muka', 'phone' => 'Telepon'][$m['mode']];
        $soon = strtotime($m['start_at']) - time();
      ?>
        <tr>
          <td data-l="Waktu" class="num">
            <b style="color:var(--ivory)"><?= tanggalID($m['start_at']) ?></b><br>
            <?= hariID($m['start_at']) ?>, <?= date('H.i', strtotime($m['start_at'])) ?>–<?= date('H.i', strtotime($m['end_at'])) ?>
            <?php if ($filter === 'upcoming' && $soon > 0 && $soon < 172800): ?>
              <br><span class="pill warn" style="margin-top:5px"><?= $soon < 86400 ? 'Hari ini/besok' : '2 hari lagi' ?></span>
            <?php endif; ?>
          </td>
          <td data-l="Klien"><b><?= e($m['client_name']) ?></b><br><span class="muted mono"><?= e($m['client_email']) ?></span>
            <?php if ($m['client_id']): ?>
              <br><a class="mono" style="color:var(--ember);font-size:11px" href="klien.php?id=<?= (int) $m['client_id'] ?>">Buka pipeline →</a>
            <?php endif; ?></td>
          <td data-l="Agenda"><?= e($m['title']) ?></td>
          <td data-l="Format"><?= $modeLbl ?>
            <?php if ($m['zoom_join_url']): ?><br><a class="mono" style="color:var(--ember)" href="<?= e($m['zoom_join_url']) ?>" target="_blank" rel="noopener">Tautan Zoom ↗</a>
            <?php elseif ($m['meet_url']): ?><br><a class="mono" style="color:var(--ember)" href="<?= e($m['meet_url']) ?>" target="_blank" rel="noopener">Tautan Meet ↗</a><?php endif; ?>
          </td>
          <td data-l="Status">
            <?php if ($m['status'] === 'canceled'): ?><span class="pill bad">Dibatalkan</span>
            <?php elseif ($m['sync_error']): ?><span class="pill warn dot" title="<?= e($m['sync_error']) ?>">Perlu dicek</span>
            <?php elseif ($m['gcal_event_id']): ?><span class="pill live dot">Tersinkron</span>
            <?php else: ?><span class="pill draft">Belum</span><?php endif; ?>
            <?php if ($m['outcome']): ?>
              <br><span class="pill <?= $m['outcome'] === 'lanjut' ? 'live' : ($m['outcome'] === 'batal' ? 'bad' : 'warn') ?>" style="margin-top:5px">
                <?= ['lanjut'=>'Lanjut','pikir'=>'Dipikir','batal'=>'Tidak lanjut'][$m['outcome']] ?></span>
            <?php elseif ($filter === 'past'): ?>
              <br><a href="?edit=<?= (int) $m['id'] ?>" class="pill warn" style="margin-top:5px;text-decoration:none">Catat hasil</a>
            <?php endif; ?>
          </td>
          <td class="actions" data-l="Tindakan">
            <a class="btn sm ghost" href="?edit=<?= (int) $m['id'] ?>">Ubah</a>
            <?php if ($m['status'] === 'scheduled'): ?>
              <form method="post" style="display:inline"><?= csrfField() ?>
                <input type="hidden" name="act" value="resync"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                <button class="btn sm ghost" type="submit">Sinkronkan</button></form>
            <?php endif; ?>
          </td>
          <td class="actions danger-col" data-l="Batalkan">
            <?php if ($m['status'] === 'scheduled'): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Batalkan jadwal ini? Klien akan menerima email pembatalan.')"><?= csrfField() ?>
                <input type="hidden" name="act" value="cancel"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                <button class="btn sm danger" type="submit">Batalkan</button></form>
            <?php else: ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Hapus permanen dari daftar?')"><?= csrfField() ?>
                <input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                <button class="btn sm danger" type="submit">Hapus</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php endif; adminFoot();
