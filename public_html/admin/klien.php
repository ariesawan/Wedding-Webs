<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/pipeline.php';
require_once __DIR__ . '/../inc/vendor.php';
require_once __DIR__ . '/../inc/chat.php';
require_once __DIR__ . '/../partials/blok-top5.php';
require_once __DIR__ . '/../inc/wa.php';
$user = requireLogin();

/**
 * Simpan Base Information ke tabel terpisah.
 *
 * Dipisah dari tabel clients karena isinya hanya relevan setelah klien
 * masuk tahap spesifikasi — kalau digabung, baris clients penuh kolom
 * kosong untuk prospek yang baru bertanya harga.
 */
function simpanBaseInfo(int $id): void
{
    // Kalau formulirnya tidak memuat blok ini sama sekali (mis. simpan cepat
    // dari halaman lain), jangan menimpa data yang sudah ada dengan kosong.
    if (!isset($_POST['base_info'])) return;

    $venue = [];
    foreach (['indoor','outdoor'] as $v) if (!empty($_POST['venue_tipe'][$v])) $venue[] = $v;

    $jenis = in_array($_POST['jenis_acara'] ?? '', ['standing','sitting'], true) ? $_POST['jenis_acara'] : '';
    $mode  = $jenis === 'sitting' && in_array($_POST['sitting_mode'] ?? '', ['per_seat','per_block'], true)
           ? $_POST['sitting_mode'] : '';

    // Prosesi adat pindah ke panel Data lengkap (diisi setelah deal), tapi
    // kolomnya masih satu baris dengan base info. Kalau formulir yang
    // mengirim POST ini tidak memuatnya, nilai lama harus dibawa ikut —
    // kalau tidak, menyimpan Base information dari tahap spesifikasi akan
    // diam-diam mengosongkan urutan prosesi yang sudah susah payah dicatat.
    if (isset($_POST['data_lengkap'])) {
        $adat = [mb_substr(trim($_POST['prosesi_adat'] ?? ''), 0, 40),
                 mb_substr(trim($_POST['prosesi_adat_lainnya'] ?? ''), 0, 120),
                 trim($_POST['prosesi_adat_detail'] ?? '')];
    } else {
        $lamaWi = one("SELECT prosesi_adat, prosesi_adat_lainnya, prosesi_adat_detail
                       FROM client_wedding_info WHERE client_id = ?", [$id]);
        $adat = [$lamaWi['prosesi_adat'] ?? '', $lamaWi['prosesi_adat_lainnya'] ?? '',
                 $lamaWi['prosesi_adat_detail'] ?? ''];
    }

    // Tamu akad dan tamu resepsi. guest_estimate di tabel clients tetap jadi
    // angka utama — diturunkan dari resepsi lalu akad, sama seperti tanggal
    // dan venue — supaya penawaran, analisa, dan papan pipeline tidak perlu
    // tahu soal pemisahan ini.
    $tAkad = ($_POST['tamu_akad'] ?? '') !== '' ? max(0, (int) $_POST['tamu_akad']) : null;
    $tRes  = ($_POST['tamu_resepsi'] ?? '') !== '' ? max(0, (int) $_POST['tamu_resepsi']) : null;
    if ($utama = ($tRes ?: $tAkad)) {
        q("UPDATE clients SET guest_estimate = ? WHERE id = ?", [$utama, $id]);
    }

    q("INSERT INTO client_wedding_info
        (client_id, akad_tanggal, akad_jam, akad_lokasi,
         resepsi_tanggal, resepsi_jam, resepsi_lokasi,
         prosesi_adat, prosesi_adat_lainnya, prosesi_adat_detail,
         venue_tipe, jenis_acara, sitting_mode, catatan, tamu_akad, tamu_resepsi)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE
         akad_tanggal=VALUES(akad_tanggal), akad_jam=VALUES(akad_jam), akad_lokasi=VALUES(akad_lokasi),
         resepsi_tanggal=VALUES(resepsi_tanggal), resepsi_jam=VALUES(resepsi_jam), resepsi_lokasi=VALUES(resepsi_lokasi),
         prosesi_adat=VALUES(prosesi_adat), prosesi_adat_lainnya=VALUES(prosesi_adat_lainnya),
         prosesi_adat_detail=VALUES(prosesi_adat_detail), venue_tipe=VALUES(venue_tipe),
         jenis_acara=VALUES(jenis_acara), sitting_mode=VALUES(sitting_mode),
         catatan=IF(VALUES(catatan) <> '', VALUES(catatan), catatan),
         tamu_akad=COALESCE(VALUES(tamu_akad), tamu_akad),
         tamu_resepsi=COALESCE(VALUES(tamu_resepsi), tamu_resepsi)",
      [$id,
       trim($_POST['akad_tanggal'] ?? '') ?: null,
       trim($_POST['akad_jam'] ?? '') ?: null,
       mb_substr(trim($_POST['akad_lokasi'] ?? ''), 0, 190),
       trim($_POST['resepsi_tanggal'] ?? '') ?: null,
       trim($_POST['resepsi_jam'] ?? '') ?: null,
       mb_substr(trim($_POST['resepsi_lokasi'] ?? ''), 0, 190),
       $adat[0], $adat[1], $adat[2],
       $venue ? implode(',', $venue) : null,
       $jenis, $mode,
       trim($_POST['base_catatan'] ?? ''), $tAkad, $tRes]);

    // Jenis vendor yang dibutuhkan — dipakai penawaran sebagai daftar item.
    if (isset($_POST['vendor_need'])) {
        q("DELETE FROM client_vendor_needs WHERE client_id = ?", [$id]);
        $urut = 0;
        foreach ((array) $_POST['vendor_need'] as $catId) {
            $catId = (int) $catId;
            if ($catId <= 0) continue;
            q("INSERT IGNORE INTO client_vendor_needs (client_id, category_id, sort_order)
               VALUES (?,?,?)", [$id, $catId, $urut++ * 10]);
        }
    }

    // Top 5 = PERINGKAT kategori, bukan daftar nama vendor.
    // top_vendor[1..5] berisi category_id. Lima kategori teratas inilah yang
    // mendapat alokasi anggaran utama saat penawaran disusun; sisanya
    // menyesuaikan yang tersisa.
    if (isset($_POST['top_vendor'])) {
        q("DELETE FROM client_top_vendors WHERE client_id = ?", [$id]);

        $sudah = [];
        foreach ((array) $_POST['top_vendor'] as $urutan => $catId) {
            $urutan = (int) $urutan;
            $catId  = (int) $catId;
            if ($urutan < 1 || $urutan > 5 || $catId <= 0) continue;

            // Kategori yang sama tidak boleh menempati dua peringkat.
            // Antarmuka sudah mencegahnya, tapi kiriman POST bisa datang
            // dari mana saja — penjagaan di sini yang menentukan.
            if (in_array($catId, $sudah, true)) continue;
            $sudah[] = $catId;

            q("INSERT INTO client_top_vendors (client_id, category_id, urutan, nama)
               VALUES (?,?,?,'')", [$id, $catId, $urutan]);
        }
    }
}

/**
 * Simpan DATA LENGKAP — lanjutan setelah penawaran disetujui.
 *
 * Pemisahan ini bukan soal tata letak. Formulir intake dulu menanyakan usia,
 * pekerjaan, dan urutan prosesi kepada orang yang baru bertanya harga di DM.
 * Tidak satu pun dipakai quoteBuat() untuk menyusun penawaran, jadi admin
 * early mengetik dua puluh kolom yang belum tentu jadi apa-apa — dan yang
 * paling sering terjadi: dikosongi semua, lalu tidak pernah ditagih lagi.
 *
 * Yang di sini semuanya baru punya arti setelah ada kontrak: nama orang tua
 * untuk undangan, urutan prosesi untuk rundown, usia dan pekerjaan untuk
 * penargetan iklan.
 */
function simpanDataLengkap(int $id, int $userId): void
{
    if (!isset($_POST['data_lengkap'])) return;

    // Identitas mempelai untuk undangan dan naskah MC.
    $angka = function (string $k): ?int {
        $v = (int) ($_POST[$k] ?? 0);
        return ($v >= 1 && $v <= 20) ? $v : null;
    };
    q("INSERT INTO client_wedding_info
        (client_id, pria_anak_ke, pria_dari, pria_alamat,
         wanita_anak_ke, wanita_dari, wanita_alamat)
       VALUES (?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE
         pria_anak_ke=VALUES(pria_anak_ke),     pria_dari=VALUES(pria_dari),
         pria_alamat=VALUES(pria_alamat),
         wanita_anak_ke=VALUES(wanita_anak_ke), wanita_dari=VALUES(wanita_dari),
         wanita_alamat=VALUES(wanita_alamat)",
      [$id, $angka('pria_anak_ke'), $angka('pria_dari'),
       mb_substr(trim($_POST['pria_alamat'] ?? ''), 0, 255),
       $angka('wanita_anak_ke'), $angka('wanita_dari'),
       mb_substr(trim($_POST['wanita_alamat'] ?? ''), 0, 255)]);

    // Prosesi adat. UPDATE terpisah, bukan lewat simpanBaseInfo(): fungsi itu
    // menulis seluruh baris termasuk tanggal akad dan resepsi, sedangkan
    // formulir ini tidak memuatnya.
    if (isset($_POST['prosesi_adat'])) {
        q("UPDATE client_wedding_info
              SET prosesi_adat = ?, prosesi_adat_lainnya = ?, prosesi_adat_detail = ?
            WHERE client_id = ?",
          [mb_substr(trim($_POST['prosesi_adat']), 0, 40),
           mb_substr(trim($_POST['prosesi_adat_lainnya'] ?? ''), 0, 120),
           trim($_POST['prosesi_adat_detail'] ?? ''), $id]);
    }

    // Orang tua. Baris yang namanya dikosongi dihapus, bukan disimpan kosong —
    // ada keluarga dengan orang tua tunggal, dan baris kosong akan muncul
    // sebagai nama hampa di undangan.
    foreach (['pria','wanita'] as $pihak) {
        foreach (['ayah','ibu','wali'] as $peran) {
            $nama = mb_substr(trim($_POST["ortu_{$pihak}_{$peran}_nama"] ?? ''), 0, 120);
            if ($nama === '') {
                q("DELETE FROM client_family WHERE client_id = ? AND pihak = ? AND peran = ?",
                  [$id, $pihak, $peran]);
                continue;
            }
            $status = ($_POST["ortu_{$pihak}_{$peran}_status"] ?? '') === 'almarhum' ? 'almarhum' : 'hidup';
            q("INSERT INTO client_family
                (client_id, pihak, peran, nama, nama_undangan, status, telepon, catatan, urutan)
               VALUES (?,?,?,?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE
                 nama=VALUES(nama), nama_undangan=VALUES(nama_undangan),
                 status=VALUES(status), telepon=VALUES(telepon), catatan=VALUES(catatan)",
              [$id, $pihak, $peran, $nama,
               mb_substr(trim($_POST["ortu_{$pihak}_{$peran}_undangan"] ?? ''), 0, 190),
               $status,
               mb_substr(trim($_POST["ortu_{$pihak}_{$peran}_telepon"] ?? ''), 0, 30),
               mb_substr(trim($_POST["ortu_{$pihak}_{$peran}_catatan"] ?? ''), 0, 255),
               ['ayah'=>1,'ibu'=>2,'wali'=>3][$peran]]);
        }
    }

    // Penanda diisi sistem, bukan dicentang manual. Yang dicari owner bukan
    // "sudah diklik belum", tapi "sudah ada isinya belum".
    q("UPDATE clients SET data_lengkap_at = COALESCE(data_lengkap_at, NOW()),
                          data_lengkap_by = COALESCE(data_lengkap_by, ?)
       WHERE id = ?", [$userId, $id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'save') {
            $id   = (int) ($_POST['id'] ?? 0);
            $nama = trim($_POST['name'] ?? '');
            if ($nama === '') throw new RuntimeException('Nama klien wajib diisi.');

            $email = trim($_POST['email'] ?? '');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email tidak valid.');

            // Hari-H, jam, dan venue diturunkan dari Base information.
            // Formulir tidak lagi menanyakannya terpisah: dulu "tanggal nikah"
            // di kartu Rencana acara bertabrakan dengan tanggal akad/resepsi di
            // Base information — dua tempat untuk fakta yang sama, dan yang satu
            // diam-diam menimpa yang lain tanpa ketahuan.
            $tglNikah = trim($_POST['wedding_date'] ?? '')
                     ?: (trim($_POST['resepsi_tanggal'] ?? '')
                     ?: (trim($_POST['akad_tanggal'] ?? '') ?: null));
            $data = [
                'name'            => $nama,
                'partner_name'    => trim($_POST['partner_name'] ?? ''),
                'email'           => $email,
                'phone'           => trim($_POST['phone'] ?? ''),
                'instagram'       => ltrim(trim($_POST['instagram'] ?? ''), '@'),
                'source'          => in_array($_POST['source'] ?? '', ['instagram','whatsapp','web','referral','vendor','walkin','lainnya'], true) ? $_POST['source'] : 'lainnya',
                'wedding_date'    => $tglNikah,
                'wedding_time'    => trim($_POST['wedding_time'] ?? '')
                                  ?: (trim($_POST['resepsi_jam'] ?? '')
                                  ?: (trim($_POST['akad_jam'] ?? '') ?: null)),
                'venue'           => trim($_POST['venue'] ?? '')
                                  ?: (trim($_POST['resepsi_lokasi'] ?? '')
                                  ?: trim($_POST['akad_lokasi'] ?? '')),
                'city'            => trim($_POST['city'] ?? 'Yogyakarta'),
                'guest_estimate'  => ($_POST['guest_estimate'] ?? '') !== '' ? (int) $_POST['guest_estimate'] : null,
                'package'         => trim($_POST['package'] ?? ''),
                'budget_estimate' => ($_POST['budget_estimate'] ?? '') !== '' ? (float) preg_replace('/\D/', '', $_POST['budget_estimate']) : null,
                'deal_value'      => ($_POST['deal_value'] ?? '') !== '' ? (float) preg_replace('/\D/', '', $_POST['deal_value']) : null,
                'next_action'     => trim($_POST['next_action'] ?? ''),
                'next_action_at'  => trim($_POST['next_action_at'] ?? '') ?: null,
                'notes'           => trim($_POST['notes'] ?? ''),
            ];

            // Tipe klien menentukan cara penawaran disusun nanti:
            //   tematis   -> item mengikuti request, total adalah hasil
            //   budgeting -> plafon dikunci, vendor diisi sampai plafon habis
            if (isset($_POST['tipe_klien'])) {
                $data['tipe_klien'] = in_array($_POST['tipe_klien'], ['tematis','budgeting'], true)
                                    ? $_POST['tipe_klien'] : '';
            }
            foreach (['usia_pria', 'usia_wanita'] as $ku) {
                if (!isset($_POST[$ku])) continue;
                $v = (int) $_POST[$ku];
                // Di luar 17–80 hampir pasti salah ketik, bukan data nyata.
                // Menyimpannya akan merusak rentang usia di laporan iklan.
                $data[$ku] = ($v >= 17 && $v <= 80) ? $v : null;
            }
            foreach (['kerja_pria', 'kerja_wanita'] as $kk) {
                if (isset($_POST[$kk])) $data[$kk] = mb_substr(trim($_POST[$kk]), 0, 80);
            }
            if (isset($_POST['sumber_detail'])) {
                $data['sumber_detail'] = mb_substr(trim($_POST['sumber_detail']), 0, 120);
            }

            if ($id) {
                $lama = one("SELECT wedding_date FROM clients WHERE id = ?", [$id]);
                $set  = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
                q("UPDATE clients SET $set WHERE id = ?", [...array_values($data), $id]);

                // Tanggal nikah bergeser -> seluruh checklist ikut digeser.
                if ($tglNikah && $lama['wedding_date'] !== $tglNikah) {
                    $n = retimeTasks($id, $tglNikah);
                    if ($n) {
                        clientLog($id, 'sistem', 'Tanggal pernikahan diubah', "Jatuh tempo $n langkah checklist ikut digeser.", $user['id']);
                        flash("Tersimpan. $n langkah checklist digeser mengikuti tanggal baru.");
                    }
                }
            } else {
                // Admin office tidak membuat klien. Dia MENERIMA klien yang
                // sudah deal dari admin early. Kalau dia bisa bikin sendiri,
                // muncul klien tanpa penawaran, tanpa riwayat, tanpa nilai —
                // dan papan pipeline kehilangan artinya.
                if (($user['role'] ?? '') === 'admin_office')
                    throw new RuntimeException(
                        'Admin office tidak mencatat klien baru. Klien masuk lewat admin early, '
                      . 'lalu berpindah ke sini setelah penawarannya deal.');

                $data['stage']            = 'baru';
                $data['stage_changed_at'] = date('Y-m-d H:i:s');
                $data['created_by']       = $user['id'];
                if (!$data['next_action']) {
                    $data['next_action']    = stageNext('baru');
                    $data['next_action_at'] = date('Y-m-d', strtotime('+' . stageSla('baru') . ' day'));
                }
                $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
                q("INSERT INTO clients ($cols) VALUES (" . implode(',', array_fill(0, count($data), '?')) . ")", array_values($data));
                $id = insertId();
                clientLog($id, 'sistem', 'Klien dicatat', 'Sumber: ' . $data['source'], $user['id']);
                flash('Klien baru dicatat.');
            }
            simpanBaseInfo($id);
            // Room chat dibuat/ditautkan di sini, bukan menunggu pesan pertama.
            try { chatSinkronKlien($id); } catch (Throwable $e) { /* gagal sinkron bukan alasan simpan batal */ }
            redirect('admin/klien.php?id=' . $id);
        }

        elseif ($act === 'datalengkap') {
            $id = (int) ($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('Klien tidak ditemukan.');

            // Usia dan pekerjaan hidup di tabel clients, bukan wedding_info.
            $set = []; $par = [];
            foreach (['usia_pria','usia_wanita'] as $ku) {
                if (!isset($_POST[$ku])) continue;
                $v = (int) $_POST[$ku];
                $set[] = "`$ku` = ?";
                $par[] = ($v >= 17 && $v <= 80) ? $v : null;
            }
            foreach (['kerja_pria','kerja_wanita'] as $kk) {
                if (!isset($_POST[$kk])) continue;
                $set[] = "`$kk` = ?";
                $par[] = mb_substr(trim($_POST[$kk]), 0, 80);
            }
            if ($set) q("UPDATE clients SET " . implode(', ', $set) . " WHERE id = ?", [...$par, $id]);

            // simpanBaseInfo() TIDAK dipanggil di sini. Formulir ini tidak
            // memuat akad/resepsi/venue, dan fungsi itu menulis seluruh
            // barisnya — memanggilnya akan mengosongkan tanggal hari-H yang
            // sudah dipakai seluruh checklist.
            $baru = empty(one("SELECT data_lengkap_at FROM clients WHERE id = ?", [$id])['data_lengkap_at'] ?? null);
            simpanDataLengkap($id, (int) $user['id']);

            if ($baru) clientLog($id, 'catatan', 'Data lengkap mulai diisi', '', $user['id']);
            flash('Data lengkap tersimpan.');
            redirect('admin/klien.php?id=' . $id . '#datalengkap');
        }

        elseif ($act === 'stage') {
            $id = (int) $_POST['id'];
            $r  = clientSetStage($id, $_POST['stage'] ?? '', $user['id'], trim($_POST['note'] ?? ''));
            flash('Tahap diperbarui.' . ($r['info'] ? "\n" . implode("\n", $r['info']) : ''));
            redirect('admin/klien.php?id=' . $id);
        }

        elseif ($act === 'susunan') {
            $id = (int) $_POST['id'];

            // Layanan (checkbox)
            $lay = array_values(array_intersect(array_keys(layananBawaan()), (array) ($_POST['services'] ?? [])));
            q("UPDATE clients SET services = ?, crew_count = ?, guest_estimate = ? WHERE id = ?", [
                implode(',', $lay),
                ($_POST['crew_count'] ?? '') !== '' ? (int) $_POST['crew_count'] : null,
                ($_POST['guest_estimate'] ?? '') !== '' ? (int) $_POST['guest_estimate'] : null,
                $id,
            ]);

            // Segmen: tulis ulang seluruhnya — lebih sederhana dan tidak
            // meninggalkan baris yatim dibanding menambal satu per satu.
            q("DELETE FROM client_segments WHERE client_id = ?", [$id]);
            $urut = 0;
            foreach (momenBawaan() as [$key, $off, $label, $ds, $de]) {
                if (!in_array($key, (array) ($_POST['momen'] ?? []), true)) continue;
                $m = trim($_POST['m_start'][$key] ?? '') ?: $ds;
                $e = trim($_POST['m_end'][$key] ?? '')   ?: $de;
                q("INSERT INTO client_segments (client_id, seg_key, label, day_offset, start_time, end_time, sort_order)
                   VALUES (?,?,?,?,?,?,?)", [$id, $key, $label, $off, $m ?: null, $e ?: null, $urut += 10]);
            }
            // Segmen tambahan di luar daftar bawaan
            foreach ((array) ($_POST['x_label'] ?? []) as $i => $lbl) {
                $lbl = trim($lbl);
                if ($lbl === '') continue;
                q("INSERT INTO client_segments (client_id, seg_key, label, day_offset, start_time, end_time, note, sort_order)
                   VALUES (?,'',?,?,?,?,?,?)", [
                    $id, $lbl, (int) ($_POST['x_day'][$i] ?? 0),
                    trim($_POST['x_start'][$i] ?? '') ?: null,
                    trim($_POST['x_end'][$i] ?? '') ?: null,
                    trim($_POST['x_note'][$i] ?? ''), $urut += 10,
                ]);
            }

            $c2 = one("SELECT * FROM clients WHERE id = ?", [$id]);
            clientLog($id, 'catatan', 'Susunan hari diperbarui',
                      ringkasanSusunan($c2, segmenKlien($id)), $user['id']);
            flash('Susunan hari tersimpan.');
            redirect('admin/klien.php?id=' . $id . '#susunan');
        }
        elseif ($act === 'vendor_add') {
            $id  = (int) $_POST['id'];
            $vid = (int) ($_POST['vendor_id'] ?? 0);
            if (!$vid) throw new RuntimeException('Pilih vendor lebih dulu.');
            $max = (int) (one("SELECT COALESCE(MAX(sort_order),0) m FROM client_vendors WHERE client_id = ?", [$id])['m'] ?? 0);
            q("INSERT IGNORE INTO client_vendors (client_id, vendor_id, sort_order) VALUES (?,?,?)", [$id, $vid, $max + 10]);
            $v = one("SELECT name FROM vendors WHERE id = ?", [$vid]);
            clientLog($id, 'catatan', 'Vendor ditambahkan: ' . ($v['name'] ?? ''), '', $user['id']);
            flash('Vendor ditambahkan ke pesta ini.');
            redirect('admin/klien.php?id=' . $id . '#vendor');
        }
        elseif ($act === 'vendor_update') {
            $id  = (int) $_POST['id'];
            $cvid = (int) $_POST['cv_id'];
            $st = isset(VENDOR_STATUS[$_POST['status'] ?? '']) ? $_POST['status'] : 'dipertimbangkan';
            q("UPDATE client_vendors SET status = ?, price = ?, note = ? WHERE id = ? AND client_id = ?", [
                $st,
                ($_POST['price'] ?? '') !== '' ? (float) preg_replace('/\D/', '', $_POST['price']) : null,
                trim($_POST['note'] ?? ''), $cvid, $id,
            ]);
            flash('Status vendor diperbarui.');
            redirect('admin/klien.php?id=' . $id . '#vendor');
        }
        elseif ($act === 'vendor_del') {
            $id = (int) $_POST['id'];
            q("DELETE FROM client_vendors WHERE id = ? AND client_id = ?", [(int) $_POST['cv_id'], $id]);
            flash('Vendor dilepas dari pesta ini. Riwayat percakapannya tetap tersimpan.');
            redirect('admin/klien.php?id=' . $id . '#vendor');
        }
        elseif ($act === 'vendor_msg') {
            $id   = (int) $_POST['id'];
            $vid  = (int) $_POST['vendor_id'];
            $isi  = trim($_POST['body'] ?? '');
            $arah = in_array($_POST['direction'] ?? '', ['keluar','masuk','catatan'], true) ? $_POST['direction'] : 'keluar';
            $kanal = $_POST['channel'] ?? 'wa';
            if ($isi === '') throw new RuntimeException('Isi pesan tidak boleh kosong.');

            $mid = catatPesan($id, $vid, $arah, $isi, $kanal, $user['id']);

            // Pesan keluar lewat WhatsApp dikirim sungguhan bila penyedia aktif.
            if ($arah === 'keluar' && $kanal === 'wa' && waSiap()) {
                $v = one("SELECT phone FROM vendors WHERE id = ?", [$vid]);
                $r = waKirim($v['phone'] ?? '', $isi);
                if ($r['ok']) {
                    q("UPDATE vendor_messages SET wa_status='terkirim', wa_id=?, sent_at=NOW() WHERE id=?", [$r['id'], $mid]);
                    flash('Pesan terkirim ke WhatsApp vendor.');
                } else {
                    q("UPDATE vendor_messages SET wa_status='gagal', wa_error=? WHERE id=?",
                      [mb_substr($r['error'], 0, 400), $mid]);
                    flash('Pesan tersimpan tapi GAGAL terkirim: ' . $r['error'], 'err');
                }
            } else {
                flash($arah === 'keluar'
                    ? (waSiap() ? 'Pesan dicatat.' : 'Pesan dicatat. Penyedia WhatsApp belum aktif — kirim manual lewat tombol WA.')
                    : 'Catatan tersimpan.');
            }
            redirect('admin/klien.php?id=' . $id . '&v=' . $vid . '#chat');
        }
        elseif ($act === 'note') {
            $id = (int) $_POST['id'];
            $t  = trim($_POST['title'] ?? '');
            if ($t === '') throw new RuntimeException('Catatan tidak boleh kosong.');
            clientLog($id, 'catatan', $t, trim($_POST['detail'] ?? ''), $user['id']);
            flash('Catatan ditambahkan.');
            redirect('admin/klien.php?id=' . $id);
        }

        elseif ($act === 'nextaction') {
            $id = (int) $_POST['id'];
            q("UPDATE clients SET next_action = ?, next_action_at = ? WHERE id = ?",
              [trim($_POST['next_action'] ?? ''), trim($_POST['next_action_at'] ?? '') ?: null, $id]);
            flash('Tindakan berikutnya diperbarui.');
            redirect('admin/klien.php?id=' . $id);
        }

        elseif ($act === 'task_toggle') {
            $tid = (int) $_POST['task_id'];
            $t   = one("SELECT * FROM client_tasks WHERE id = ?", [$tid]);
            if ($t) {
                q("UPDATE client_tasks SET done_at = " . ($t['done_at'] ? 'NULL' : 'NOW()') . " WHERE id = ?", [$tid]);
            }
            redirect('admin/klien.php?id=' . (int) $_POST['id'] . '#checklist');
        }

        elseif ($act === 'task_add') {
            $id  = (int) $_POST['id'];
            $t   = trim($_POST['title'] ?? '');
            if ($t === '') throw new RuntimeException('Judul langkah wajib diisi.');
            $max = (int) (one("SELECT COALESCE(MAX(sort_order),0) m FROM client_tasks WHERE client_id = ?", [$id])['m'] ?? 0);
            q("INSERT INTO client_tasks (client_id, title, detail, due_date, sort_order) VALUES (?, ?, ?, ?, ?)",
              [$id, $t, trim($_POST['detail'] ?? ''), trim($_POST['due_date'] ?? '') ?: null, $max + 10]);
            flash('Langkah ditambahkan.');
            redirect('admin/klien.php?id=' . $id . '#checklist');
        }

        elseif ($act === 'task_del') {
            q("DELETE FROM client_tasks WHERE id = ?", [(int) $_POST['task_id']]);
            redirect('admin/klien.php?id=' . (int) $_POST['id'] . '#checklist');
        }

        elseif ($act === 'pay_save') {
            $id  = (int) $_POST['id'];
            $pid = (int) ($_POST['payment_id'] ?? 0);
            $amt = (float) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
            $par = [trim($_POST['label'] ?? 'Termin'), $amt, trim($_POST['due_date'] ?? '') ?: null,
                    trim($_POST['paid_at'] ?? '') ?: null, trim($_POST['method'] ?? ''), trim($_POST['note'] ?? '')];
            if ($pid) {
                q("UPDATE payments SET label=?, amount=?, due_date=?, paid_at=?, method=?, note=? WHERE id=?", [...$par, $pid]);
            } else {
                $max = (int) (one("SELECT COALESCE(MAX(sort_order),0) m FROM payments WHERE client_id = ?", [$id])['m'] ?? 0);
                q("INSERT INTO payments (client_id, label, amount, due_date, paid_at, method, note, sort_order)
                   VALUES (?,?,?,?,?,?,?,?)", [$id, ...$par, $max + 10]);
            }
            flash('Termin pembayaran disimpan.');
            redirect('admin/klien.php?id=' . $id . '#uang');
        }

        elseif ($act === 'pay_paid') {
            $pid = (int) $_POST['payment_id'];
            $p   = one("SELECT * FROM payments WHERE id = ?", [$pid]);
            q("UPDATE payments SET paid_at = " . ($p['paid_at'] ? 'NULL' : 'CURDATE()') . " WHERE id = ?", [$pid]);
            if ($p && !$p['paid_at']) {
                clientLog((int) $_POST['id'], 'bayar', $p['label'] . ' diterima', rupiah($p['amount']), $user['id']);
            }
            redirect('admin/klien.php?id=' . (int) $_POST['id'] . '#uang');
        }

        elseif ($act === 'pay_del') {
            q("DELETE FROM payments WHERE id = ?", [(int) $_POST['payment_id']]);
            redirect('admin/klien.php?id=' . (int) $_POST['id'] . '#uang');
        }

        elseif ($act === 'delete') {
            $id = (int) $_POST['id'];
            foreach (['client_tasks', 'payments', 'client_activities'] as $t) q("DELETE FROM `$t` WHERE client_id = ?", [$id]);
            q("UPDATE meetings SET client_id = NULL WHERE client_id = ?", [$id]);
            q("DELETE FROM clients WHERE id = ?", [$id]);
            flash('Klien dihapus beserta checklist, pembayaran, dan riwayatnya.');
            redirect('admin/klien.php');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
        redirect('admin/klien.php' . (!empty($_POST['id']) ? '?id=' . (int) $_POST['id'] : ''));
    }
}

$id  = (int) ($_GET['id'] ?? 0);
$c   = $id ? one("SELECT * FROM clients WHERE id = ?", [$id]) : null;
$new = isset($_GET['new']);

// Peran menentukan pekerjaan, bukan cuma menu. Admin early memulai klien dan
// membawanya sampai deal; admin office melanjutkan sesudahnya. Karena itu
// formulir klien baru bukan miliknya.
$peranSaya  = $user['role'] ?? '';
$bolehBuat  = $peranSaya !== 'admin_office';
if ($new && !$bolehBuat) {
    flash('Admin office tidak mencatat klien baru — klien berpindah ke sini setelah deal.', 'err');
    redirect('admin/klien.php');
}

adminHead('Klien', 'klien');

/* ============================================================
   TAMPILAN DETAIL SATU KLIEN
   ============================================================ */
if ($c):
    $money  = clientMoney($c['id']);
    $tasks  = all("SELECT * FROM client_tasks WHERE client_id = ? ORDER BY COALESCE(due_date,'2099-12-31'), sort_order", [$c['id']]);
    $pays   = all("SELECT * FROM payments WHERE client_id = ? ORDER BY sort_order, id", [$c['id']]);
    $acts   = all("SELECT a.*, u.name uname FROM client_activities a LEFT JOIN users u ON u.id = a.user_id
                   WHERE a.client_id = ? ORDER BY a.created_at DESC LIMIT 60", [$c['id']]);
    $meets  = all("SELECT * FROM meetings WHERE client_id = ? ORDER BY start_at DESC", [$c['id']]);
    $segmen = segmenKlien($c['id']);
    $segByKey = [];
    foreach ($segmen as $sg) if ($sg['seg_key']) $segByKey[$sg['seg_key']] = $sg;
    $segLain  = array_values(array_filter($segmen, fn($sg) => !$sg['seg_key']));
    $layAktif = array_filter(explode(',', $c['services'] ?? ''));
    $rentang  = rentangHari($segmen);
    $vGrup    = vendorKlien($c['id']);
    $vBiaya   = biayaVendor($c['id']);
    $vAda     = array_merge(...array_values($vGrup) ?: [[]]);
    $vPilih   = (int) ($_GET['v'] ?? 0);
    $vAktif   = null;
    foreach ($vAda as $x) if ((int) $x['vendor_id'] === $vPilih) $vAktif = $x;
    if (!$vAktif && $vAda) $vAktif = $vAda[0];
    $vPesan   = $vAktif ? pesanVendor($c['id'], (int) $vAktif['vendor_id']) : [];
    $vTersedia = all("SELECT id, name, category, city FROM vendors WHERE is_active = 1
                      AND id NOT IN (SELECT vendor_id FROM client_vendors WHERE client_id = ?)
                      ORDER BY category, name", [$c['id']]);
    // Data lengkap — dibaca di sini supaya kartunya bisa menunjukkan apa yang
    // masih kosong, bukan sekadar formulir kosong yang terlihat sama saja
    // antara "belum diisi" dan "memang tidak ada".
    $wi = $fam = [];
    try {
        $wi = one("SELECT * FROM client_wedding_info WHERE client_id = ?", [$c['id']]) ?: [];
        foreach (all("SELECT * FROM client_family WHERE client_id = ?", [$c['id']]) as $f) {
            $fam[$f['pihak'] . '_' . $f['peran']] = $f;
        }
    } catch (Throwable $e) { $wi = $fam = []; }
    $ortu = fn(string $k, string $f) => $fam[$k][$f] ?? '';

    // Sudah deal tapi data lengkap belum disentuh sama sekali.
    $dlTertunda = in_array($c['stage'], ['deal','persiapan','harih'], true) && empty($c['data_lengkap_at']);

    $done   = count(array_filter($tasks, fn($t) => $t['done_at']));
    $judul  = $c['name'] . ($c['partner_name'] ? ' & ' . $c['partner_name'] : '');
    $hK     = hariKe($c['wedding_date']);

    pageHead($judul,
        stageLabel($c['stage']) . ' · ' . (PIPE_STAGES[$c['stage']]['desc'] ?? ''),
        '<a class="btn ghost" href="klien.php">← Semua klien</a>');
?>

<!-- ---------- Jalur tahap ---------- -->
<div class="card">
  <span class="lab">Perjalanan klien</span>
  <div style="display:flex;gap:6px;flex-wrap:wrap;margin:12px 0 4px">
    <?php $lewat = true; foreach (PIPE_ACTIVE as $st):
      $ini = $c['stage'] === $st;
      if ($ini) $lewat = false;
      $warna = $ini ? 'background:var(--ember);border-color:var(--ember);color:var(--ink);font-weight:600'
                    : ($lewat ? 'border-color:var(--sage);color:var(--sage)' : 'border-color:var(--ivory-12);color:var(--ivory-38)');
    ?>
      <span class="pill" style="<?= $warna ?>"><?= e(stageLabel($st)) ?></span>
    <?php endforeach; ?>
    <?php if ($c['stage'] === 'selesai'): ?><span class="pill" style="background:var(--sage);border-color:var(--sage);color:var(--ink);font-weight:600">Selesai</span><?php endif; ?>
    <?php if ($c['stage'] === 'batal'): ?><span class="pill bad">Tidak jadi</span><?php endif; ?>
  </div>

  <?php
  // pic_role sudah berpindah sendiri ke admin_office sejak v8 tapi tidak
  // pernah ditampilkan, jadi serah-terimanya tidak kelihatan oleh siapa pun.
  $office = ($c['pic_role'] ?? '') === 'admin_office';
  ?>
  <div style="display:flex;gap:9px;flex-wrap:wrap;align-items:center;margin-top:12px">
    <span class="lab">Pegangan</span>
    <span class="pill" style="<?= $office ? 'border-color:var(--sage);color:var(--sage)' : 'border-color:var(--ember-line);color:var(--ember)' ?>">
      <?= $office ? 'Admin office' : 'Admin early' ?>
    </span>
    <?php if ($office && !empty($c['handover_at'])): ?>
      <span class="hint" style="margin:0">Diserahkan <?= tanggalID(substr($c['handover_at'], 0, 10)) ?></span>
    <?php elseif (!$office): ?>
      <span class="hint" style="margin:0">Berpindah otomatis ke admin office begitu deal.</span>
    <?php endif; ?>
    <?php if ($dlTertunda): ?>
      <a class="pill warn" href="#datalengkap" style="text-decoration:none">Data lengkap belum diisi →</a>
    <?php endif; ?>
  </div>

  <?php if ($c['stage'] !== 'batal' && $c['stage'] !== 'selesai'):
    $urut = array_values(PIPE_ACTIVE);
    $pos  = array_search($c['stage'], $urut, true);
    $maju = $pos !== false && isset($urut[$pos + 1]) ? $urut[$pos + 1] : null;
  ?>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;align-items:center">
    <?php if ($maju): ?>
      <form method="post" style="display:inline"><?= csrfField() ?>
        <input type="hidden" name="act" value="stage"><input type="hidden" name="id" value="<?= $c['id'] ?>">
        <input type="hidden" name="stage" value="<?= $maju ?>">
        <button class="btn solid" type="submit">Majukan ke <?= e(stageLabel($maju)) ?> →</button>
      </form>
    <?php endif; ?>
    <details style="display:inline-block">
      <summary class="btn ghost" style="list-style:none">Pindah ke tahap lain</summary>
      <form method="post" style="margin-top:11px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <?= csrfField() ?><input type="hidden" name="act" value="stage"><input type="hidden" name="id" value="<?= $c['id'] ?>">
        <div class="field" style="margin:0;min-width:190px">
          <label>Tahap</label>
          <select name="stage">
            <?php foreach (PIPE_STAGES as $k => $m): ?>
              <option value="<?= $k ?>" <?= $c['stage'] === $k ? 'selected' : '' ?>><?= e($m['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="margin:0;flex:1;min-width:220px">
          <label>Catatan / alasan</label>
          <input type="text" name="note" placeholder="Wajib diisi bila memilih Tidak jadi">
        </div>
        <button class="btn" type="submit">Pindahkan</button>
      </form>
    </details>
  </div>
  <?php elseif ($c['stage'] === 'batal' && $c['lost_reason']): ?>
    <div class="flash err" style="margin:16px 0 0"><span>Alasan tidak jadi: <?= e($c['lost_reason']) ?></span></div>
  <?php endif; ?>
</div>

  <?php
  // Pengarah tugas. Dua peran membuka halaman yang sama tapi datang untuk
  // urusan berbeda — tanpa penunjuk ini, keduanya melihat dinding kartu yang
  // identik dan harus menebak mana bagiannya.
  $sudahDealKlien = in_array($c['stage'], ['deal','persiapan','harih','selesai'], true);
  if ($peranSaya === 'admin_office' && !$sudahDealKlien): ?>
    <div class="card" style="border-color:rgba(224,108,90,.45)">
      <h2 style="margin-bottom:4px">Klien ini belum diserahkan</h2>
      <p class="sub" style="margin:0">Masih tahap <b><?= e(stageLabel($c['stage'])) ?></b> dan
        jadi pegangan admin early. Data lengkap baru bisa ditagih setelah penawarannya deal —
        sebelum itu, belum tentu jadi.</p>
    </div>
  <?php elseif ($peranSaya === 'admin_office'): ?>
    <div class="card" style="border-color:rgba(233,168,92,.4)">
      <h2 style="margin-bottom:4px">Bagianmu</h2>
      <p class="sub" style="margin:0 0 11px">Penawaran sudah deal. Yang tersisa: lengkapi
        orang tua, prosesi adat, dan susunan acara.</p>
      <a class="btn sm solid" href="#datalengkap">Buka Data lengkap →</a>
      <a class="btn sm ghost" href="#susunan">Susunan hari</a>
      <a class="btn sm ghost" href="penawaran.php?client=<?= (int) $c['id'] ?>">Riwayat penawaran</a>
    </div>
  <?php elseif ($peranSaya === 'admin_early' && !$sudahDealKlien): ?>
    <div class="card" style="border-color:rgba(233,168,92,.4)">
      <h2 style="margin-bottom:4px">Bagianmu</h2>
      <p class="sub" style="margin:0 0 11px">Bawa klien ini sampai deal: susun penawaran,
        kirim PDF-nya, lalu majukan tahapnya. Data lengkap menyusul setelah itu.</p>
      <a class="btn sm solid" href="penawaran.php?client=<?= (int) $c['id'] ?>">Susun penawaran →</a>
    </div>
  <?php endif; ?>


<div class="g-side">
  <div>
    <!-- ---------- Tindakan berikutnya ---------- -->
    <?php if ($c['stage'] !== 'selesai' && $c['stage'] !== 'batal'):
      $telat = $c['next_action_at'] && strtotime($c['next_action_at']) < strtotime(date('Y-m-d')); ?>
    <div class="card" style="<?= $telat ? 'border-color:rgba(217,123,123,.45)' : 'border-color:rgba(233,168,92,.35)' ?>">
      <h2>Tindakan berikutnya</h2>
      <form method="post" style="display:flex;gap:11px;flex-wrap:wrap;align-items:flex-end">
        <?= csrfField() ?><input type="hidden" name="act" value="nextaction"><input type="hidden" name="id" value="<?= $c['id'] ?>">
        <div class="field" style="margin:0;flex:1;min-width:230px">
          <label>Apa yang harus dilakukan</label>
          <input type="text" name="next_action" value="<?= e($c['next_action']) ?>" placeholder="<?= e(stageNext($c['stage'])) ?>">
        </div>
        <div class="field" style="margin:0;min-width:160px">
          <label>Paling lambat</label>
          <input type="date" name="next_action_at" value="<?= e($c['next_action_at'] ?? '') ?>">
        </div>
        <button class="btn" type="submit">Simpan</button>
      </form>
      <?php if ($c['next_action_at']): ?>
        <p class="hint" style="margin-top:10px;<?= $telat ? 'color:var(--rose)' : '' ?>">
          <?= $telat ? 'Sudah lewat tenggat — ' : '' ?><?= e(labelHari($c['next_action_at'])) ?> (<?= tanggalID($c['next_action_at']) ?>)
        </p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ---------- Susunan hari ---------- -->
    <div class="card" id="susunan">
      <h2>Susunan hari</h2>
      <p class="sub">Rangkaian acara, layanan, dan jumlah tamu — sama persis dengan penyusun brief di situs. Klien yang deal lewat WhatsApp bisa dicatat di sini.</p>

      <form method="post">
        <?= csrfField() ?><input type="hidden" name="act" value="susunan"><input type="hidden" name="id" value="<?= $c['id'] ?>">

        <?php
        // Acara ditampilkan HANYA kalau dipilih untuk klien ini. Sebelumnya
        // seluruh daftar muncul sekaligus dengan centang kosong — itu membuat
        // panel seolah memaksakan susunan bawaan, padahal tidak semua pesta
        // memakai siraman, panggih, atau after-party. Yang belum dipakai
        // pindah ke dropdown di bawah, jadi bisa ditambah satu per satu.
        $terpakai = 0;
        foreach (momenBawaan() as [$key, $o, $label, $ds, $de]) if (isset($segByKey[$key])) $terpakai++;
        ?>

        <div id="momenList">
        <?php foreach ([-1 => 'H-1 · sehari sebelumnya', 0 => 'Hari-H'] as $off => $judulHari):
          $daftar = array_filter(momenBawaan(), fn($m) => $m[1] === $off);
          $adaDiHari = false;
          foreach ($daftar as [$key]) if (isset($segByKey[$key])) { $adaDiHari = true; break; } ?>
          <span class="lab momen-hari" data-off="<?= $off ?>"
                style="display:<?= $adaDiHari ? 'block' : 'none' ?>;margin:14px 0 8px"><?= e($judulHari) ?></span>
          <?php foreach ($daftar as [$key, $o, $label, $ds, $de]):
            $ada = isset($segByKey[$key]);
            $sg  = $segByKey[$key] ?? null; ?>
            <div class="ck momen-baris" id="mb_<?= $key ?>" data-off="<?= $off ?>"
                 <?= $ada ? '' : 'hidden' ?>>
              <input type="checkbox" name="momen[]" value="<?= $key ?>" id="m_<?= $key ?>"
                     <?= $ada ? 'checked' : '' ?> hidden>
              <span class="tx"><b><?= e($label) ?></b></span>
              <span style="display:flex;gap:6px;align-items:center;flex:0 0 auto">
                <input type="time" name="m_start[<?= $key ?>]" style="width:112px;padding:7px 9px"
                       value="<?= $ada && $sg['start_time'] ? substr($sg['start_time'],0,5) : $ds ?>">
                <span class="muted">–</span>
                <input type="time" name="m_end[<?= $key ?>]" style="width:112px;padding:7px 9px"
                       value="<?= $ada && $sg['end_time'] ? substr($sg['end_time'],0,5) : $de ?>">
                <button type="button" class="momen-hapus" data-key="<?= $key ?>" title="Hapus acara ini"
                        style="border:1px solid var(--ivory-12);background:transparent;color:var(--ivory-38);
                               width:28px;height:28px;border-radius:8px;cursor:pointer;font-size:14px;
                               line-height:1;flex:0 0 28px">×</button>
              </span>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
        </div>

        <div id="momenKosong" style="display:<?= $terpakai ? 'none' : 'block' ?>;
             padding:18px 0;color:var(--ivory-38);font-size:13.2px">
          Belum ada acara dipilih. Tambahkan yang dipakai pesta ini — tidak semua pernikahan
          memakai susunan yang sama.
        </div>

        <div style="display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-top:12px">
          <select id="momenPilih" style="flex:1;min-width:190px;max-width:320px">
            <option value="">— tambah acara —</option>
            <?php foreach ([-1 => 'H-1', 0 => 'Hari-H'] as $off => $ket):
              foreach (array_filter(momenBawaan(), fn($m) => $m[1] === $off) as [$key, $o, $label]):
                if (isset($segByKey[$key])) continue; ?>
                <option value="<?= $key ?>" data-off="<?= $off ?>"><?= e($ket . ' · ' . $label) ?></option>
            <?php endforeach; endforeach; ?>
          </select>
          <button type="button" class="btn sm ghost" id="momenTambah">+ Tambahkan</button>
        </div>

        <script>
        (() => {
          const pilih  = document.getElementById('momenPilih');
          const kosong = document.getElementById('momenKosong');

          const segarkan = () => {
            // Judul hari disembunyikan kalau tidak ada acara di bawahnya —
            // label "Hari-H" yang menggantung tanpa isi lebih membingungkan
            // daripada tidak ada label sama sekali.
            let total = 0;
            document.querySelectorAll('.momen-hari').forEach(h => {
              const isi = [...document.querySelectorAll('.momen-baris')]
                .filter(b => b.dataset.off === h.dataset.off && !b.hidden).length;
              h.style.display = isi ? 'block' : 'none';
              total += isi;
            });
            kosong.style.display = total ? 'none' : 'block';
          };

          document.getElementById('momenTambah').addEventListener('click', () => {
            const k = pilih.value;
            if (!k) return;
            const baris = document.getElementById('mb_' + k);
            if (baris) {
              baris.hidden = false;
              baris.querySelector('input[type=checkbox]').checked = true;
            }
            pilih.querySelector(`option[value="${k}"]`)?.remove();
            pilih.value = '';
            segarkan();
          });

          document.querySelectorAll('.momen-hapus').forEach(b => {
            b.addEventListener('click', () => {
              const k = b.dataset.key;
              const baris = document.getElementById('mb_' + k);
              baris.hidden = true;
              baris.querySelector('input[type=checkbox]').checked = false;

              // Dikembalikan ke dropdown, bukan dibuang — supaya bisa
              // ditambahkan lagi tanpa memuat ulang halaman.
              const lbl = baris.querySelector('.tx b').textContent;
              const ket = baris.dataset.off === '-1' ? 'H-1' : 'Hari-H';
              const opt = new Option(ket + ' · ' + lbl, k);
              opt.dataset.off = baris.dataset.off;
              pilih.add(opt);
              segarkan();
            });
          });

          segarkan();
        })();
        </script>

        <span class="lab" style="display:block;margin:20px 0 8px">Acara tambahan di luar daftar</span>
        <div id="segLain">
          <?php foreach (array_merge($segLain, [null]) as $i => $x): ?>
            <div class="row c3" style="margin-bottom:9px;align-items:end">
              <div class="field" style="margin:0"><input type="text" name="x_label[]" placeholder="Nama acara"
                   value="<?= $x ? e($x['label']) : '' ?>"></div>
              <div class="field" style="margin:0">
                <select name="x_day[]">
                  <option value="0"  <?= $x && (int)$x['day_offset'] === 0  ? 'selected' : '' ?>>Hari-H</option>
                  <option value="-1" <?= $x && (int)$x['day_offset'] === -1 ? 'selected' : '' ?>>H-1</option>
                </select>
              </div>
              <div class="field" style="margin:0;display:flex;gap:6px">
                <input type="time" name="x_start[]" value="<?= $x && $x['start_time'] ? substr($x['start_time'],0,5) : '' ?>">
                <input type="time" name="x_end[]"   value="<?= $x && $x['end_time']   ? substr($x['end_time'],0,5)   : '' ?>">
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <button class="btn sm ghost" type="button" id="tambahSeg">+ Baris acara</button>

        <hr class="hr">
        <span class="lab" style="display:block;margin-bottom:8px">Layanan yang diambil</span>
        <?php foreach (layananBawaan() as $k => [$nama, $ket]): ?>
          <label class="check"><input type="checkbox" name="services[]" value="<?= $k ?>" <?= in_array($k, $layAktif, true) ? 'checked' : '' ?>>
            <span><?= e($nama) ?> <span class="muted" style="font-size:12px">— <?= e($ket) ?></span></span></label>
        <?php endforeach; ?>

        <div class="row c2" style="margin-top:16px">
          <div class="field"><label for="ge2">Perkiraan tamu</label>
            <input type="number" id="ge2" name="guest_estimate" step="25" min="0" value="<?= e($c['guest_estimate']) ?>"></div>
          <div class="field"><label for="kr">Kru lapangan</label>
            <input type="number" id="kr" name="crew_count" min="0" value="<?= e($c['crew_count'] ?? '') ?>">
            <p class="hint">Saran dari jumlah tamu: <b id="kruSaran"><?= kruDisarankan((int) $c['guest_estimate']) ?: '—' ?></b> orang (1 kru per <?= tamuPerKru() ?> tamu).</p></div>
        </div>

        <?php if ($rentang['mulai']): ?>
          <div style="display:flex;gap:24px;flex-wrap:wrap;padding:14px 0;border-top:1px solid var(--ivory-12);margin-top:6px">
            <div><span class="lab">Rentang hari-H</span><div class="mono" style="font-size:16px;margin-top:4px;color:var(--ember)"><?= substr($rentang['mulai'],0,5) ?>–<?= substr($rentang['selesai'],0,5) ?></div></div>
            <div><span class="lab">Durasi acara</span><div class="mono" style="font-size:16px;margin-top:4px"><?= $rentang['durasi'] ?> jam</div></div>
            <div><span class="lab">Jumlah acara</span><div class="mono" style="font-size:16px;margin-top:4px"><?= count($segmen) ?></div></div>
          </div>
        <?php endif; ?>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">
          <button class="btn solid" type="submit">Simpan susunan</button>
          <?php if ($segmen): ?>
            <button class="btn ghost" type="button" id="salinBrief">Salin brief</button>
            <?php if ($c['phone']): ?>
              <a class="btn ghost" target="_blank" rel="noopener"
                 href="https://wa.me/<?= e(preg_replace('/\D/', '', $c['phone'])) ?>?text=<?= rawurlencode(ringkasanSusunan($c, $segmen)) ?>">Kirim ke WhatsApp ↗</a>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <textarea id="briefTeks" hidden><?= e(ringkasanSusunan($c, $segmen)) ?></textarea>
      </form>
    </div>

    <!-- ---------- Checklist ---------- -->
    <?php if ($tasks): ?>
    <div class="card" id="checklist">
      <h2>Checklist persiapan <span class="lab" style="margin-left:8px"><?= $done ?>/<?= count($tasks) ?> SELESAI</span></h2>
      <div class="bar-progress"><i style="width:<?= count($tasks) ? round($done / count($tasks) * 100) : 0 ?>%"></i></div>
      <p class="sub" style="margin-top:10px">Jatuh tempo dihitung mundur dari tanggal pernikahan. Mengubah tanggal nikah akan menggeser semuanya sekaligus.</p>

      <?php foreach ($tasks as $t):
        $d = hariKe($t['due_date']);
        $cls = $t['done_at'] ? '' : ($d !== null && $d < 0 ? 'late' : ($d !== null && $d <= 7 ? 'soon' : '')); ?>
        <div class="ck <?= $t['done_at'] ? 'done' : '' ?>">
          <form method="post" style="display:contents">
            <?= csrfField() ?><input type="hidden" name="act" value="task_toggle">
            <input type="hidden" name="id" value="<?= $c['id'] ?>"><input type="hidden" name="task_id" value="<?= $t['id'] ?>">
            <input type="checkbox" <?= $t['done_at'] ? 'checked' : '' ?> onchange="this.form.submit()" aria-label="Tandai selesai">
          </form>
          <span class="tx">
            <b><?= e($t['title']) ?></b>
            <?php if ($t['detail']): ?><span><?= e($t['detail']) ?></span><?php endif; ?>
          </span>
          <span class="wh <?= $cls ?>">
            <?= $t['due_date'] ? e(labelHari($t['due_date'])) : '—' ?>
            <?php if ($t['due_date']): ?><br><span style="font-size:9.5px;opacity:.65"><?= date('d/m', strtotime($t['due_date'])) ?></span><?php endif; ?>
          </span>
          <form method="post" style="display:inline" onsubmit="return confirm('Hapus langkah ini?')">
            <?= csrfField() ?><input type="hidden" name="act" value="task_del">
            <input type="hidden" name="id" value="<?= $c['id'] ?>"><input type="hidden" name="task_id" value="<?= $t['id'] ?>">
            <button class="btn sm ghost" type="submit" style="border:0;padding:4px 7px" title="Hapus">×</button>
          </form>
        </div>
      <?php endforeach; ?>

      <details style="margin-top:16px">
        <summary style="cursor:pointer;color:var(--ember);font-size:13.5px;padding:6px 0">+ Tambah langkah</summary>
        <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:11px">
          <?= csrfField() ?><input type="hidden" name="act" value="task_add"><input type="hidden" name="id" value="<?= $c['id'] ?>">
          <div class="field" style="margin:0;flex:2;min-width:200px"><label>Langkah</label><input type="text" name="title" required></div>
          <div class="field" style="margin:0;flex:2;min-width:180px"><label>Keterangan</label><input type="text" name="detail"></div>
          <div class="field" style="margin:0;min-width:150px"><label>Jatuh tempo</label><input type="date" name="due_date"></div>
          <button class="btn" type="submit">Tambah</button>
        </form>
      </details>
    </div>
    <?php endif; ?>

    <!-- ---------- Kebutuhan vendor (dari base information) ---------- -->
    <?php
    // Data ini diisi di formulir klien baru tapi sebelumnya tidak pernah
    // ditampilkan di mana pun — jadi terlihat seperti hilang. Ditaruh SEBELUM
    // "Vendor pesta ini" karena urutannya memang begitu: tentukan apa yang
    // dibutuhkan, baru pilih siapa yang mengerjakan.
    $katSemua = $kebutuhan = $topVendor = [];
    try {
        $katSemua  = all("SELECT id, nama FROM vendor_categories
                          WHERE parent_id IS NULL AND is_active = 1 ORDER BY urutan, nama");
        $kebutuhan = all("SELECT n.category_id, vc.nama
                          FROM client_vendor_needs n
                          JOIN vendor_categories vc ON vc.id = n.category_id
                          WHERE n.client_id = ? ORDER BY n.sort_order", [$c['id']]);
        foreach (all("SELECT * FROM client_top_vendors WHERE client_id = ? ORDER BY category_id, urutan",
                     [$c['id']]) as $tv) {
            $topVendor[(int) $tv['category_id']][] = $tv;
        }
    } catch (Throwable $e) { $katSemua = []; }
    $dipilih = array_column($kebutuhan, 'category_id');
    ?>
    <?php if ($katSemua): ?>
    <div class="card" id="kebutuhan">
      <h2>Kebutuhan vendor
        <span class="lab" style="margin-left:8px"><?= count($kebutuhan) ?> JENIS</span>
      </h2>
      <p class="sub">Jenis vendor yang diminta klien, beserta nama yang sudah mereka incar sendiri.
        Daftar inilah yang jadi baris penawaran.</p>

      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="act" value="save">
        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <input type="hidden" name="base_info" value="1">
        <input type="hidden" name="name" value="<?= e($c['name']) ?>">

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:7px 14px">
          <?php foreach ($katSemua as $k): ?>
            <label style="display:flex;gap:7px;align-items:center;font-size:13.3px;padding:3px 0">
              <input type="checkbox" class="vn-cek2" name="vendor_need[]" value="<?= (int) $k['id'] ?>"
                     data-kat="<?= (int) $k['id'] ?>" <?= in_array($k['id'], $dipilih) ? 'checked' : '' ?>>
              <?= e($k['nama']) ?>
            </label>
          <?php endforeach; ?>
        </div>

        <span class="lab" style="display:block;margin:20px 0 4px">Top 5 prioritas</span>
        <p class="hint" style="margin:0 0 11px">Lima jenis yang harus dapat kualitas terbaik.
          Sisanya menyesuaikan anggaran yang tersisa.</p>
        <?php
          $rank = [];
          foreach ($topVendor as $kid => $baris) foreach ($baris as $b) $rank[(int) $b['urutan']] = $kid;
          echo blokTop5($katSemua, $rank);
        ?>

        <button class="btn solid" type="submit" style="margin-top:16px">Simpan kebutuhan</button>
      </form>

      <?= blokTop5Skrip() ?>
    </div>
    <?php endif; ?>

    <!-- ---------- Data lengkap (lanjutan setelah deal) ---------- -->
    <?php
    $sudahDeal = in_array($c['stage'], ['deal','persiapan','harih','selesai'], true);
    ?>
    <div class="card" id="datalengkap"
         style="<?= $dlTertunda ? 'border-color:rgba(233,168,92,.45)' : '' ?>">
      <h2>Data lengkap
        <?php if (!empty($c['data_lengkap_at'])): ?>
          <span class="lab" style="margin-left:8px;color:var(--sage)">TERISI</span>
        <?php elseif ($sudahDeal): ?>
          <span class="lab" style="margin-left:8px;color:var(--ember)">BELUM DIISI</span>
        <?php else: ?>
          <span class="lab" style="margin-left:8px">BELUM DEAL</span>
        <?php endif; ?>
      </h2>

      <?php if (!$sudahDeal): ?>
        <p class="sub" style="color:var(--ember)">Klien belum deal. Blok ini boleh diisi sekarang
          kalau datanya kebetulan sudah diketahui, tapi bukan syarat menyusun penawaran —
          jangan menahan penawaran gara-gara ini.</p>
      <?php elseif ($dlTertunda): ?>
        <p class="sub" style="color:var(--ember)">Sudah deal
          <?= !empty($c['handover_at']) ? '(' . e(labelHari(substr($c['handover_at'], 0, 10))) . ')' : '' ?>
          tapi data lengkapnya belum ditagih. Nama orang tua dan urutan prosesi yang paling sering
          dicari mendadak menjelang cetak undangan.</p>
      <?php else: ?>
        <p class="sub">Data yang baru punya arti setelah ada kontrak: nama untuk undangan,
          urutan prosesi untuk rundown, usia dan pekerjaan untuk penargetan iklan.</p>
      <?php endif; ?>

      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="act" value="datalengkap">
        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <input type="hidden" name="data_lengkap" value="1">

        <span class="lab" style="display:block;margin:4px 0 9px">Mempelai</span>
        <div class="row c2">
          <div class="field"><label>Usia pria</label>
            <input type="number" name="usia_pria" min="17" max="80"
                   value="<?= e((string) ($c['usia_pria'] ?? '')) ?>"></div>
          <div class="field"><label>Usia wanita</label>
            <input type="number" name="usia_wanita" min="17" max="80"
                   value="<?= e((string) ($c['usia_wanita'] ?? '')) ?>"></div>
        </div>
        <div class="row c2">
          <div class="field"><label>Pekerjaan pria</label>
            <input type="text" name="kerja_pria" value="<?= e($c['kerja_pria'] ?? '') ?>"
                   placeholder="Karyawan swasta, PNS, wiraswasta…"></div>
          <div class="field"><label>Pekerjaan wanita</label>
            <input type="text" name="kerja_wanita" value="<?= e($c['kerja_wanita'] ?? '') ?>"></div>
        </div>

        <p class="hint" style="margin:2px 0 12px">Usia dan pekerjaan tidak dipakai penawaran —
          ini yang jadi rentang usia dan minat saat memasang iklan Instagram dan TikTok.</p>

        <div class="row c2">
          <div class="field"><label>Pria — anak ke</label>
            <div style="display:flex;gap:8px;align-items:center">
              <input type="number" name="pria_anak_ke" min="1" max="20" style="width:80px"
                     value="<?= e((string) ($wi['pria_anak_ke'] ?? '')) ?>">
              <span style="font-size:13px;color:var(--ivory-38)">dari</span>
              <input type="number" name="pria_dari" min="1" max="20" style="width:80px"
                     value="<?= e((string) ($wi['pria_dari'] ?? '')) ?>">
              <span style="font-size:13px;color:var(--ivory-38)">bersaudara</span>
            </div></div>
          <div class="field"><label>Wanita — anak ke</label>
            <div style="display:flex;gap:8px;align-items:center">
              <input type="number" name="wanita_anak_ke" min="1" max="20" style="width:80px"
                     value="<?= e((string) ($wi['wanita_anak_ke'] ?? '')) ?>">
              <span style="font-size:13px;color:var(--ivory-38)">dari</span>
              <input type="number" name="wanita_dari" min="1" max="20" style="width:80px"
                     value="<?= e((string) ($wi['wanita_dari'] ?? '')) ?>">
              <span style="font-size:13px;color:var(--ivory-38)">bersaudara</span>
            </div></div>
        </div>
        <div class="row c2">
          <div class="field"><label>Alamat pria</label>
            <input type="text" name="pria_alamat" value="<?= e($wi['pria_alamat'] ?? '') ?>"
                   placeholder="Sesuai yang dicetak di undangan"></div>
          <div class="field"><label>Alamat wanita</label>
            <input type="text" name="wanita_alamat" value="<?= e($wi['wanita_alamat'] ?? '') ?>"></div>
        </div>

        <hr class="hr">

        <span class="lab" style="display:block;margin:4px 0 5px">Orang tua</span>
        <p class="hint" style="margin:0 0 14px">Nama undangan boleh berbeda dari nama biasa —
          undangan menulis lengkap dengan gelar, MC membacanya apa adanya. Kosongkan nama
          untuk menghapus baris. Baris <b>wali</b> hanya diisi kalau memang ada.</p>

        <?php foreach (['pria' => 'Pihak mempelai pria', 'wanita' => 'Pihak mempelai wanita'] as $pihak => $judulPihak): ?>
          <div style="margin-bottom:18px;padding:13px 15px;border:1px solid var(--ivory-12);border-radius:11px">
            <span class="lab" style="display:block;margin-bottom:11px;color:var(--ember)"><?= $judulPihak ?></span>

            <?php foreach (['ayah' => 'Ayah', 'ibu' => 'Ibu', 'wali' => 'Wali (opsional)'] as $peran => $judulPeran):
              $pre = "ortu_{$pihak}_{$peran}_"; $key = $pihak . '_' . $peran; ?>
              <div style="margin-bottom:13px">
                <span style="display:block;font-size:12.5px;color:var(--ivory-60);margin-bottom:6px"><?= $judulPeran ?></span>
                <div class="row c2">
                  <div class="field" style="margin-bottom:8px"><label>Nama</label>
                    <input type="text" name="<?= $pre ?>nama" value="<?= e($ortu($key, 'nama')) ?>"></div>
                  <div class="field" style="margin-bottom:8px"><label>Nama di undangan</label>
                    <input type="text" name="<?= $pre ?>undangan" value="<?= e($ortu($key, 'nama_undangan')) ?>"
                           placeholder="Bapak H. Sutrisno, S.E."></div>
                </div>
                <div class="row c2">
                  <div class="field" style="margin-bottom:0"><label>Telepon</label>
                    <input type="text" name="<?= $pre ?>telepon" value="<?= e($ortu($key, 'telepon')) ?>"></div>
                  <div class="field" style="margin-bottom:0"><label>Status</label>
                    <select name="<?= $pre ?>status">
                      <option value="hidup" <?= $ortu($key, 'status') !== 'almarhum' ? 'selected' : '' ?>>Masih ada</option>
                      <option value="almarhum" <?= $ortu($key, 'status') === 'almarhum' ? 'selected' : '' ?>>Almarhum / almarhumah</option>
                    </select></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>

        <hr class="hr">

        <span class="lab" style="display:block;margin:4px 0 9px">Prosesi adat</span>
        <div class="row c2">
          <div class="field"><label>Adat</label>
            <select name="prosesi_adat">
              <option value="">— tidak ada / belum ditentukan —</option>
              <?php foreach (['jawa'=>'Jawa','chinese'=>'Chinese','batak'=>'Batak','lainnya'=>'Suku lainnya'] as $k => $v): ?>
                <option value="<?= $k ?>" <?= ($wi['prosesi_adat'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>Kalau suku lain, sebutkan</label>
            <input type="text" name="prosesi_adat_lainnya" value="<?= e($wi['prosesi_adat_lainnya'] ?? '') ?>"
                   placeholder="Minang, Bugis, Sunda…"></div>
        </div>
        <div class="field"><label>Urutan prosesi</label>
          <textarea name="prosesi_adat_detail" rows="4"
            placeholder="Diisi manual — tiap keluarga punya urutan sendiri."><?= e($wi['prosesi_adat_detail'] ?? '') ?></textarea>
          <p class="hint" style="margin:6px 0 0">Ini yang dibaca saat menyusun rundown dan
            technical meeting. Salin apa adanya dari keluarga, jangan dirapikan sendiri.</p></div>

        <button class="btn solid" type="submit" style="margin-top:6px">Simpan data lengkap</button>
        <?php if (!empty($c['data_lengkap_at'])): ?>
          <p class="hint" style="margin:10px 0 0">Pertama diisi <?= tanggalID(substr($c['data_lengkap_at'], 0, 10)) ?>.</p>
        <?php endif; ?>
      </form>
    </div>

    <!-- ---------- Vendor & percakapan ---------- -->
    <div class="card" id="vendor">
      <h2>Vendor pesta ini
        <?php if ($vBiaya['jumlah']): ?><span class="lab" style="margin-left:8px"><?= $vBiaya['n_deal'] ?>/<?= $vBiaya['jumlah'] ?> DEAL<?= $vBiaya['deal'] > 0 ? ' · ' . rupiah($vBiaya['deal'], true) : '' ?></span><?php endif; ?>
      </h2>
      <p class="sub">Vendor diambil dari daftar induk di menu Vendor. Percakapan dengan tiap vendor tercatat terpisah per pesta.</p>

      <?php if (!$vAda): ?>
        <div class="empty" style="padding:30px 16px">
          <p>Belum ada vendor untuk pesta ini</p>
          <span>Pilih dari daftar induk di bawah. Kalau vendornya belum terdaftar, tambahkan dulu di menu Vendor.</span>
        </div>
      <?php else: ?>
        <?php foreach ($vGrup as $kat => $daftar): ?>
          <span class="lab" style="display:block;margin:16px 0 8px"><?= e(katVendor($kat)) ?></span>
          <?php foreach ($daftar as $x): [$sLabel, $sCls] = statusVendor($x['status']); ?>
            <div class="vrow<?= $vAktif && $vAktif['id'] === $x['id'] ? ' on' : '' ?>">
              <div class="vnama">
                <b><?= e($x['name']) ?></b>
                <?php if ($x['pic_name']): ?><span class="muted mono"><?= e($x['pic_name']) ?></span><?php endif; ?>
              </div>
              <form method="post" class="vform">
                <?= csrfField() ?><input type="hidden" name="act" value="vendor_update">
                <input type="hidden" name="id" value="<?= $c['id'] ?>"><input type="hidden" name="cv_id" value="<?= $x['id'] ?>">
                <select name="status" onchange="this.form.submit()">
                  <?php foreach (VENDOR_STATUS as $sk => $sv): ?>
                    <option value="<?= $sk ?>" <?= $x['status'] === $sk ? 'selected' : '' ?>><?= e($sv[0]) ?></option>
                  <?php endforeach; ?>
                </select>
                <input type="text" name="price" data-rp value="<?= $x['price'] ? (int) $x['price'] : '' ?>" placeholder="harga">
                <input type="text" name="note" value="<?= e($x['note']) ?>" placeholder="catatan singkat">
                <button class="btn sm ghost" type="submit">Simpan</button>
              </form>
              <div class="vaksi">
                <a class="btn sm <?= $vAktif && $vAktif['id'] === $x['id'] ? 'solid' : 'ghost' ?>" href="?id=<?= $c['id'] ?>&v=<?= $x['vendor_id'] ?>#chat">Percakapan</a>
                <?php if ($x['phone']): ?>
                  <a class="btn sm ghost" target="_blank" rel="noopener" href="<?= e(waTautan($x['phone'], pesanPembuka($c, $x))) ?>">WA ↗</a>
                <?php endif; ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Lepas vendor ini dari pesta?')">
                  <?= csrfField() ?><input type="hidden" name="act" value="vendor_del">
                  <input type="hidden" name="id" value="<?= $c['id'] ?>"><input type="hidden" name="cv_id" value="<?= $x['id'] ?>">
                  <button class="btn sm ghost" type="submit" title="Lepas">×</button></form>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($vTersedia): ?>
        <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:18px;padding-top:16px;border-top:1px solid var(--ivory-12)">
          <?= csrfField() ?><input type="hidden" name="act" value="vendor_add"><input type="hidden" name="id" value="<?= $c['id'] ?>">
          <div class="field" style="margin:0;flex:1;min-width:230px">
            <label for="vsel">Tambah vendor ke pesta ini</label>
            <select id="vsel" name="vendor_id">
              <option value="">— pilih vendor —</option>
              <?php $katLalu = null; foreach ($vTersedia as $x):
                if ($x['category'] !== $katLalu) { if ($katLalu !== null) echo '</optgroup>'; echo '<optgroup label="' . e(katVendor($x['category'])) . '">'; $katLalu = $x['category']; } ?>
                <option value="<?= $x['id'] ?>"><?= e($x['name']) ?><?= $x['city'] ? ' · ' . e($x['city']) : '' ?></option>
              <?php endforeach; if ($katLalu !== null) echo '</optgroup>'; ?>
            </select>
          </div>
          <button class="btn" type="submit">Tambahkan</button>
          <a class="btn ghost" href="vendor.php?new=1">+ Vendor baru</a>
        </form>
      <?php endif; ?>
    </div>

    <!-- ---------- Percakapan vendor ---------- -->
    <?php if ($vAktif): ?>
    <div class="card" id="chat">
      <h2>Percakapan · <?= e($vAktif['name']) ?></h2>
      <p class="sub">
        Khusus pesta <b><?= e($judul) ?></b>. Vendor yang sama di pesta lain punya riwayat sendiri.
        Pengiriman tetap lewat WhatsApp — panel ini menyiapkan pesannya dan menyimpan salinannya.
      </p>

      <?php if (waSiap()): ?>
        <p class="hint" style="margin:-6px 0 10px">Balasan vendor masuk sendiri ke room ini. Halaman menyegarkan tiap 20 detik.</p>
        <meta http-equiv="refresh" content="20">
      <?php endif; ?>
      <div class="chat" id="chatBox">
        <?php if (!$vPesan): ?>
          <p class="muted" style="text-align:center;padding:22px 0;font-size:13px">Belum ada percakapan tercatat.</p>
        <?php else: foreach ($vPesan as $m): ?>
          <div class="msg <?= e($m['direction']) ?>">
            <div class="isi"><?= nl2br(e($m['body'])) ?></div>
            <div class="meta">
              <?= ['keluar' => 'Dikirim', 'masuk' => 'Balasan vendor', 'catatan' => 'Catatan'][$m['direction']] ?>
              · <?= tanggalID($m['created_at'], true) ?><?= $m['oleh'] ? ' · ' . e($m['oleh']) : '' ?>
              <?php if ($m['direction'] === 'keluar' && ($m['wa_status'] ?? 'lokal') !== 'lokal'):
                $ikon = ['antre'=>'◷','terkirim'=>'✓','sampai'=>'✓✓','dibaca'=>'✓✓','gagal'=>'✕'][$m['wa_status']] ?? ''; ?>
                · <span class="st-<?= e($m['wa_status']) ?>" title="<?= e($m['wa_error'] ?: $m['wa_status']) ?>"><?= $ikon ?></span>
              <?php endif; ?>
            </div>
            <?php if (!empty($m['wa_error'])): ?>
              <div class="gagal"><?= e($m['wa_error']) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <form method="post" style="margin-top:16px">
        <?= csrfField() ?><input type="hidden" name="act" value="vendor_msg">
        <input type="hidden" name="id" value="<?= $c['id'] ?>"><input type="hidden" name="vendor_id" value="<?= $vAktif['vendor_id'] ?>">
        <div class="field">
          <label for="msg">Tulis pesan</label>
          <textarea id="msg" name="body" rows="5" placeholder="Ketik pesan, atau tempel balasan vendor di sini."><?= $vPesan ? '' : e(pesanPembuka($c, $vAktif)) ?></textarea>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
          <select name="direction" style="width:auto;min-width:158px">
            <option value="keluar">Pesan keluar</option>
            <option value="masuk">Balasan vendor</option>
            <option value="catatan">Catatan internal</option>
          </select>
          <select name="channel" style="width:auto;min-width:132px">
            <option value="wa">WhatsApp</option><option value="telepon">Telepon</option>
            <option value="email">Email</option><option value="tatap">Tatap muka</option>
          </select>
          <?php if (waSiap()): ?>
            <button class="btn solid" type="submit">Kirim</button>
            <span class="mono muted" style="font-size:11px">Terkirim langsung ke WhatsApp vendor</span>
          <?php else: ?>
            <button class="btn solid" type="submit">Catat</button>
            <?php if ($vAktif['phone']): ?>
              <button class="btn" type="button" id="kirimWa" data-wa="<?= e(waNomor($vAktif['phone'])) ?>">Catat &amp; buka WhatsApp ↗</button>
            <?php endif; ?>
            <a class="mono" style="font-size:11px;color:var(--ember)" href="integrasi.php#wa">Aktifkan pengiriman langsung →</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <!-- ---------- Pembayaran ---------- -->
    <div class="card" id="uang">
      <h2>Pembayaran</h2>
      <?php if ($pays): ?>
        <div style="display:flex;gap:22px;flex-wrap:wrap;margin-bottom:6px">
          <div><span class="lab">Nilai kontrak</span><div class="money" style="font-size:16px;margin-top:4px"><?= rupiah($money['total']) ?></div></div>
          <div><span class="lab">Diterima</span><div class="money" style="font-size:16px;margin-top:4px;color:var(--sage)"><?= rupiah($money['lunas']) ?></div></div>
          <div><span class="lab">Sisa</span><div class="money" style="font-size:16px;margin-top:4px;color:var(--ember)"><?= rupiah($money['sisa']) ?></div></div>
        </div>
        <div class="bar-progress"><i style="width:<?= $money['persen'] ?>%"></i></div>
        <p class="hint"><?= $money['persen'] ?>% terbayar</p>

        <table class="tbl" style="margin-top:16px">
          <thead><tr><th>Termin</th><th>Nominal</th><th>Jatuh tempo</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($pays as $p):
            $telat = !$p['paid_at'] && $p['due_date'] && strtotime($p['due_date']) < time(); ?>
            <tr>
              <td data-l="Termin"><b><?= e($p['label']) ?></b><?php if ($p['method']): ?><br><span class="muted mono"><?= e($p['method']) ?></span><?php endif; ?></td>
              <td class="money" data-l="Nominal"><?= rupiah($p['amount']) ?></td>
              <td class="num" data-l="Jatuh tempo"><?= $p['due_date'] ? tanggalID($p['due_date']) : '—' ?></td>
              <td data-l="Status">
                <?php if ($p['paid_at']): ?><span class="pill live dot">Lunas <?= date('d/m', strtotime($p['paid_at'])) ?></span>
                <?php elseif ($telat): ?><span class="pill bad">Terlambat</span>
                <?php else: ?><span class="pill draft">Belum</span><?php endif; ?>
              </td>
              <td class="actions">
                <form method="post" style="display:inline"><?= csrfField() ?>
                  <input type="hidden" name="act" value="pay_paid"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                  <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                  <button class="btn sm <?= $p['paid_at'] ? 'ghost' : 'solid' ?>" type="submit"><?= $p['paid_at'] ? 'Batal lunas' : 'Tandai lunas' ?></button></form>
                <form method="post" style="display:inline" onsubmit="return confirm('Hapus termin ini?')"><?= csrfField() ?>
                  <input type="hidden" name="act" value="pay_del"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                  <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                  <button class="btn sm danger" type="submit">Hapus</button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="sub">Belum ada termin. Termin dibuat otomatis saat klien masuk tahap <b>Deal</b> dan nilai kontraknya sudah diisi — atau tambahkan manual di bawah.</p>
      <?php endif; ?>

      <details style="margin-top:16px">
        <summary style="cursor:pointer;color:var(--ember);font-size:13.5px;padding:6px 0">+ Tambah termin</summary>
        <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:11px">
          <?= csrfField() ?><input type="hidden" name="act" value="pay_save"><input type="hidden" name="id" value="<?= $c['id'] ?>">
          <div class="field" style="margin:0;min-width:140px"><label>Nama termin</label><input type="text" name="label" required placeholder="DP 30%"></div>
          <div class="field" style="margin:0;min-width:150px"><label>Nominal</label><input type="text" name="amount" data-rp inputmode="numeric" placeholder="15000000"></div>
          <div class="field" style="margin:0;min-width:150px"><label>Jatuh tempo</label><input type="date" name="due_date"></div>
          <div class="field" style="margin:0;min-width:130px"><label>Metode</label><input type="text" name="method" placeholder="Transfer BCA"></div>
          <button class="btn" type="submit">Tambah</button>
        </form>
      </details>
    </div>

    <!-- ---------- Riwayat ---------- -->
    <div class="card">
      <h2>Riwayat</h2>
      <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:20px">
        <?= csrfField() ?><input type="hidden" name="act" value="note"><input type="hidden" name="id" value="<?= $c['id'] ?>">
        <div class="field" style="margin:0;flex:1;min-width:220px"><label>Catatan baru</label>
          <input type="text" name="title" required placeholder="Ditelepon, minta revisi paket"></div>
        <div class="field" style="margin:0;flex:1;min-width:200px"><label>Rincian</label>
          <input type="text" name="detail" placeholder="opsional"></div>
        <button class="btn" type="submit">Catat</button>
      </form>

      <?php if ($acts): ?>
        <ul class="tl">
          <?php foreach ($acts as $i => $a): ?>
            <li class="<?= $i === 0 ? 'hi' : '' ?>">
              <span class="w"><?= tanggalID($a['created_at'], true) ?><?= $a['uname'] ? ' · ' . e($a['uname']) : '' ?></span>
              <div class="t"><?= e($a['title']) ?></div>
              <?php if ($a['detail']): ?><div class="d"><?= e($a['detail']) ?></div><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="sub">Belum ada riwayat.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- ================= KOLOM SAMPING ================= -->
  <div>
    <div class="card">
      <h2>Ringkasan</h2>
      <?php if ($c['wedding_date']): ?>
        <div style="text-align:center;padding:14px 0 6px;border-bottom:1px solid var(--ivory-12);margin-bottom:14px">
          <div style="font-family:var(--serif);font-style:italic;font-size:34px;line-height:1;color:var(--ember)">
            <?= $hK !== null && $hK > 0 ? 'H-' . $hK : ($hK === 0 ? 'HARI INI' : 'Selesai') ?>
          </div>
          <div class="lab" style="margin-top:8px"><?= hariID($c['wedding_date']) ?>, <?= tanggalID($c['wedding_date']) ?></div>
        </div>
      <?php endif; ?>
      <table class="tbl" style="font-size:13px">
        <tr><td class="lab" style="padding:7px 0">Tahap</td><td class="right" style="padding:7px 0"><b><?= e(stageLabel($c['stage'])) ?></b></td></tr>
        <?php if ($c['venue'] || $c['city']): ?><tr><td class="lab" style="padding:7px 0">Lokasi</td><td class="right" style="padding:7px 0"><?= e($c['venue'] ?: $c['city']) ?></td></tr><?php endif; ?>
        <?php if (!empty($wi['tamu_resepsi'])): ?>
          <tr><td class="lab" style="padding:7px 0">Tamu resepsi</td><td class="right" style="padding:7px 0"><?= number_format((int) $wi['tamu_resepsi'], 0, ',', '.') ?></td></tr>
        <?php elseif ($c['guest_estimate']): ?>
          <tr><td class="lab" style="padding:7px 0">Tamu</td><td class="right" style="padding:7px 0"><?= number_format((int) $c['guest_estimate'], 0, ',', '.') ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($wi['tamu_akad'])): ?>
          <tr><td class="lab" style="padding:7px 0">Tamu akad</td><td class="right" style="padding:7px 0"><?= number_format((int) $wi['tamu_akad'], 0, ',', '.') ?></td></tr>
        <?php endif; ?>
        <?php if ($c['package']): ?><tr><td class="lab" style="padding:7px 0">Paket</td><td class="right" style="padding:7px 0"><?= e($c['package']) ?></td></tr><?php endif; ?>
        <?php if ($c['deal_value']): ?><tr><td class="lab" style="padding:7px 0">Nilai deal</td><td class="right money" style="padding:7px 0"><?= rupiah($c['deal_value']) ?></td></tr><?php endif; ?>
        <tr><td class="lab" style="padding:7px 0">Sumber</td><td class="right" style="padding:7px 0"><?= e(ucfirst($c['source'])) ?></td></tr>
      </table>

      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px">
        <?php if ($c['phone']): ?>
          <a class="btn sm" target="_blank" rel="noopener"
             href="https://wa.me/<?= e(preg_replace('/\D/', '', $c['phone'])) ?>">WhatsApp ↗</a>
        <?php endif; ?>
        <?php if ($c['email']): ?><a class="btn sm ghost" href="mailto:<?= e($c['email']) ?>">Email</a><?php endif; ?>
        <?php if ($c['instagram']): ?><a class="btn sm ghost" target="_blank" rel="noopener" href="https://instagram.com/<?= e($c['instagram']) ?>">IG ↗</a><?php endif; ?>
      </div>

      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:11px">
        <a class="btn sm solid" href="jadwal.php?new=1&client=<?= $c['id'] ?>">+ Jadwalkan pertemuan</a>
        <?php if ($c['event_id']): ?><a class="btn sm ghost" href="event.php?edit=<?= (int) $c['event_id'] ?>">Buka event</a><?php endif; ?>
      </div>
    </div>

    <?php if ($meets): ?>
    <div class="card">
      <h2>Pertemuan</h2>
      <?php foreach ($meets as $m): ?>
        <div style="padding:11px 0;border-bottom:1px solid var(--ivory-07)">
          <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline">
            <span class="mono" style="font-size:12px"><?= tanggalID($m['start_at']) ?>, <?= date('H.i', strtotime($m['start_at'])) ?></span>
            <?php if ($m['outcome']): ?>
              <span class="pill <?= ['lanjut'=>'live','pikir'=>'warn','batal'=>'bad'][$m['outcome']] ?>">
                <?= ['lanjut'=>'Lanjut','pikir'=>'Dipikir','batal'=>'Tidak jadi'][$m['outcome']] ?></span>
            <?php elseif (strtotime($m['start_at']) < time()): ?>
              <a class="pill warn" href="jadwal.php?edit=<?= $m['id'] ?>" style="text-decoration:none">Catat hasil →</a>
            <?php endif; ?>
          </div>
          <div style="font-size:13.5px;margin-top:4px"><?= e($m['title']) ?></div>
          <?php if ($m['outcome_note']): ?><div style="font-size:12px;color:var(--ivory-60);margin-top:3px"><?= e($m['outcome_note']) ?></div><?php endif; ?>
          <?php if (!empty($m['minutes'])): ?>
            <details style="margin-top:6px">
              <summary style="cursor:pointer;font-size:11.5px;color:var(--ember)">Notulen</summary>
              <div style="font-size:12.5px;color:var(--ivory-60);margin-top:6px;white-space:pre-line;line-height:1.6"><?= e($m['minutes']) ?></div>
            </details>
          <?php endif; ?>
          <?php if (!empty($m['zoom_recording_url'])): ?>
            <a class="mono" style="font-size:11px;color:var(--ember)" target="_blank" rel="noopener" href="<?= e($m['zoom_recording_url']) ?>">Rekaman ↗</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card">
      <h2>Data klien</h2>
      <form method="post">
        <?= csrfField() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= $c['id'] ?>">
        <div class="row c2">
          <div class="field"><label>Mempelai pria</label><input type="text" name="name" required value="<?= e($c['name']) ?>"></div>
          <div class="field"><label>Mempelai wanita</label><input type="text" name="partner_name" value="<?= e($c['partner_name']) ?>"></div>
        </div>
        <p class="hint" style="margin:-4px 0 12px">Usia, pekerjaan, orang tua, dan prosesi adat
          pindah ke kartu <a href="#datalengkap" style="color:var(--ember)">Data lengkap</a> —
          dua tempat untuk satu fakta selalu berakhir dengan yang satu menimpa yang lain.</p>
        <div class="field"><label>Email</label><input type="email" name="email" value="<?= e($c['email']) ?>"></div>
        <div class="row c2">
          <div class="field"><label>WhatsApp</label><input type="text" name="phone" value="<?= e($c['phone']) ?>"></div>
          <div class="field"><label>Instagram</label><input type="text" name="instagram" value="<?= e($c['instagram']) ?>"></div>
        </div>
        <div class="row c2">
          <div class="field"><label>Tanggal nikah</label><input type="date" name="wedding_date" value="<?= e($c['wedding_date'] ?? '') ?>"></div>
          <div class="field"><label>Jam</label><input type="time" name="wedding_time" value="<?= $c['wedding_time'] ? substr($c['wedding_time'], 0, 5) : '' ?>"></div>
        </div>
        <div class="field"><label>Venue</label><input type="text" name="venue" value="<?= e($c['venue']) ?>"></div>
        <div class="row c2">
          <div class="field"><label>Kota</label><input type="text" name="city" value="<?= e($c['city']) ?>"></div>
          <div class="field"><label>Tamu resepsi</label>
            <input type="number" name="tamu_resepsi" step="50"
                   value="<?= e((string) ($wi['tamu_resepsi'] ?? $c['guest_estimate'] ?? '')) ?>"></div>
          <div class="field"><label>Tamu akad / pemberkatan</label>
            <input type="number" name="tamu_akad" step="25"
                   value="<?= e((string) ($wi['tamu_akad'] ?? '')) ?>"></div>
        </div>
        <div class="field"><label>Paket</label><input type="text" name="package" value="<?= e($c['package']) ?>"></div>
        <div class="row c2">
          <div class="field"><label>Perkiraan budget</label><input type="text" name="budget_estimate" data-rp inputmode="numeric" value="<?= $c['budget_estimate'] ? (int) $c['budget_estimate'] : '' ?>"></div>
          <div class="field"><label>Nilai deal</label><input type="text" name="deal_value" data-rp inputmode="numeric" value="<?= $c['deal_value'] ? (int) $c['deal_value'] : '' ?>"></div>
        </div>
        <div class="field"><label>Sumber</label>
          <select name="source">
            <?php foreach (['instagram'=>'Instagram','whatsapp'=>'WhatsApp','web'=>'Situs web','referral'=>'Rekomendasi','vendor'=>'Vendor','walkin'=>'Datang langsung','lainnya'=>'Lainnya'] as $k => $v): ?>
              <option value="<?= $k ?>" <?= $c['source'] === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Catatan</label><textarea name="notes" rows="4"><?= e($c['notes']) ?></textarea></div>
        <input type="hidden" name="next_action" value="<?= e($c['next_action']) ?>">
        <input type="hidden" name="next_action_at" value="<?= e($c['next_action_at'] ?? '') ?>">
        <button class="btn solid" type="submit">Simpan data</button>
      </form>
      <hr class="hr">
      <form method="post" onsubmit="return confirm('Hapus klien ini beserta seluruh checklist, pembayaran, dan riwayatnya? Tidak bisa dibatalkan.')">
        <?= csrfField() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>">
        <button class="btn sm danger ghost" type="submit">Hapus klien</button>
      </form>
    </div>
  </div>
</div>

<?php
/* ============================================================
   FORMULIR KLIEN BARU
   ============================================================ */
elseif ($new):
  pageHead('Klien baru',
           'Cukup sampai yang dibutuhkan untuk menyusun penawaran. Orang tua, prosesi adat, '
         . 'usia, dan pekerjaan diisi belakangan lewat panel Data lengkap setelah klien deal.',
           '<a class="btn ghost" href="klien.php">← Kembali</a>');

  // Master jenis vendor dibaca dari tabel, bukan konstanta PHP — catatan owner
  // menegaskan daftarnya "diisi sendiri", jadi harus bisa tumbuh tanpa deploy.
  $katInduk = [];
  try {
      $katInduk = all("SELECT id, nama FROM vendor_categories
                       WHERE parent_id IS NULL AND is_active = 1 ORDER BY urutan, nama");
  } catch (Throwable $e) { $katInduk = []; }
?>
<form method="post">
  <?= csrfField() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="0">
  <input type="hidden" name="base_info" value="1">

  <div class="card">
    <h2>Pengantin</h2>
    <div class="row c2">
      <div class="field"><label for="n">Mempelai pria</label>
        <input type="text" id="n" name="name" required autofocus placeholder="Zakki"></div>
      <div class="field"><label for="pn">Mempelai wanita</label>
        <input type="text" id="pn" name="partner_name" placeholder="Winda"></div>
    </div>
    <div class="row c2">
      <div class="field"><label for="em">Email</label>
        <input type="email" id="em" name="email" placeholder="zakki@email.com"></div>
      <div class="field"><label for="ph">WhatsApp</label>
        <input type="text" id="ph" name="phone" placeholder="628123456789"></div>
    </div>
    <div class="row c2">
      <div class="field"><label for="ig">Instagram</label>
        <input type="text" id="ig" name="instagram" placeholder="zakkiwinda"></div>
      <div class="field"><label for="src">Tahu dari mana</label>
        <select id="src" name="source">
          <?php foreach (['instagram'=>'Instagram','whatsapp'=>'WhatsApp','web'=>'Situs web','referral'=>'Rekomendasi teman','vendor'=>'Vendor lain','walkin'=>'Datang langsung','lainnya'=>'Lainnya'] as $k => $v): ?>
            <option value="<?= $k ?>"><?= $v ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="field"><label for="sd">Rincinya</label>
      <input type="text" id="sd" name="sumber_detail" placeholder="Akun @siapa, teman siapa, vendor mana"></div>

    <span class="lab" style="display:block;margin:16px 0 6px">Tipe klien</span>
    <p class="hint" style="margin:0 0 9px">Menentukan cara penawaran disusun nanti. Bisa diubah belakangan.</p>
    <div class="row c2" style="gap:9px">
      <label style="display:flex;gap:9px;align-items:flex-start;padding:11px 13px;
                    border:1px solid var(--ivory-12);border-radius:10px;cursor:pointer;margin:0">
        <input type="radio" name="tipe_klien" value="tematis" checked style="margin-top:3px">
        <span><b style="color:var(--ivory)">On tematis</b> — wedding dream.<br>
          <span style="font-size:12.3px;color:var(--ivory-38)">Paket mengikuti request. Budget jadi perkiraan, bukan batas.</span></span>
      </label>
      <label style="display:flex;gap:9px;align-items:flex-start;padding:11px 13px;
                    border:1px solid var(--ivory-12);border-radius:10px;cursor:pointer;margin:0">
        <input type="radio" name="tipe_klien" value="budgeting" style="margin-top:3px">
        <span><b style="color:var(--ivory)">On budgeting</b> — paket menyesuaikan anggaran.<br>
          <span style="font-size:12.3px;color:var(--ivory-38)">Plafon dikunci. Vendor diisi menurut prioritas sampai plafon habis, sisanya jadi opsional.</span></span>
      </label>
    </div>
  </div>

  <div class="card">
    <h2>Base information</h2>
    <p class="sub">Boleh dikosongi sekarang dan dilengkapi saat konsultasi.
      Tanggal hari-H, venue, dan jam diambil dari resepsi — kalau tidak ada resepsi, dari akad.</p>

    <div class="grid g2" style="align-items:start">
      <div>
        <span class="lab" style="display:block;margin-bottom:7px">Akad / pemberkatan</span>
        <div class="row c2">
          <div class="field"><label for="at">Tanggal</label><input type="date" id="at" name="akad_tanggal"></div>
          <div class="field"><label for="aj">Jam</label><input type="time" id="aj" name="akad_jam"></div>
        </div>
        <div class="field"><label for="al">Lokasi</label>
          <input type="text" id="al" name="akad_lokasi" placeholder="Masjid, gereja, atau rumah"></div>
      </div>
      <div>
        <span class="lab" style="display:block;margin-bottom:7px">Resepsi</span>
        <div class="row c2">
          <div class="field"><label for="rt">Tanggal</label><input type="date" id="rt" name="resepsi_tanggal"></div>
          <div class="field"><label for="rj">Jam</label><input type="time" id="rj" name="resepsi_jam"></div>
        </div>
        <div class="field"><label for="rl">Lokasi</label>
          <input type="text" id="rl" name="resepsi_lokasi" placeholder="Royal Ambarrukmo"></div>
      </div>
    </div>

    <div class="row c2">
      <div class="field"><label for="ge">Tamu resepsi</label>
        <input type="number" id="ge" name="tamu_resepsi" step="50" placeholder="500"></div>
      <div class="field"><label for="ga">Tamu akad / pemberkatan</label>
        <input type="number" id="ga" name="tamu_akad" step="25" placeholder="150">
        <p class="hint" style="margin:6px 0 0">Biasanya jauh lebih sedikit. Boleh dikosongi
          kalau acaranya menyatu.</p></div>
    </div>
    <div class="row c2">
      <div class="field"><label for="ct">Kota</label>
        <input type="text" id="ct" name="city" value="Yogyakarta"></div>
      <div></div>
    </div>

    <div class="row c2">
      <div class="field"><label>Venue</label>
        <div style="display:flex;gap:16px;padding-top:8px">
          <label style="display:flex;gap:6px;align-items:center;font-size:13.5px">
            <input type="checkbox" name="venue_tipe[indoor]" value="1"> Indoor</label>
          <label style="display:flex;gap:6px;align-items:center;font-size:13.5px">
            <input type="checkbox" name="venue_tipe[outdoor]" value="1"> Outdoor</label>
        </div>
        <p class="hint" style="margin:6px 0 0">Boleh dua-duanya.</p>
      </div>
      <div class="field"><label for="ja">Jenis acara</label>
        <select id="ja" name="jenis_acara"
                onchange="document.getElementById('sm').style.display = this.value === 'sitting' ? '' : 'none'">
          <option value="">— belum ditentukan —</option>
          <option value="standing">Standing party</option>
          <option value="sitting">Sitting arrangement</option>
        </select>
        <div id="sm" style="display:none;margin-top:9px">
          <label style="display:block;font-size:13px;margin-bottom:4px">
            <input type="radio" name="sitting_mode" value="per_seat"> Per seat — ada nama di tiap kursi</label>
          <label style="display:block;font-size:13px">
            <input type="radio" name="sitting_mode" value="per_block"> Per block — piring terbang, duduk bebas</label>
        </div>
      </div>
    </div>

    <div class="row c2">
      <div class="field"><label for="be"><span id="lblBudget">Perkiraan budget</span></label>
        <input type="text" id="be" name="budget_estimate" data-rp inputmode="numeric" placeholder="40000000">
        <p class="hint" id="hintBudget" style="margin:6px 0 0">Angka kasar dari klien. Tidak mengikat.</p></div>
      <div class="field"><label for="pk">Paket diminati</label>
        <input type="text" id="pk" name="package" placeholder="Kalau klien sudah menyebut nama paket"></div>
    </div>

    <div class="field"><label for="nt">Catatan</label>
      <textarea id="nt" name="notes" rows="3"
        placeholder="Konsep yang diinginkan, kendala, permintaan khusus."></textarea></div>

    <p class="hint" style="margin:0">Rangkaian jam per acara diisi belakangan lewat panel
      <b>Susunan hari</b>. Nama orang tua, prosesi adat, usia, dan pekerjaan lewat panel
      <b>Data lengkap</b> — dua-duanya di halaman klien ini setelah disimpan.</p>
  </div>

  <?php if ($katInduk): ?>
  <div class="card">
    <h2>Jenis vendor yang dibutuhkan</h2>
    <p class="sub">Centang yang relevan. Tiap yang dicentang membuka daftar lima vendor incaran klien
      untuk jenis itu sendiri — daftar ini yang nanti jadi baris penawaran.</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:7px 14px">
      <?php foreach ($katInduk as $k): ?>
        <label style="display:flex;gap:7px;align-items:center;font-size:13.3px;padding:3px 0">
          <input type="checkbox" class="vn-cek" name="vendor_need[]" value="<?= (int) $k['id'] ?>"
                 data-kat="<?= (int) $k['id'] ?>">
          <?= e($k['nama']) ?>
        </label>
      <?php endforeach; ?>
    </div>

    <div id="alokasi" hidden style="margin:16px 0 0;padding:13px 15px;border:1px solid var(--ember-line);
         border-radius:11px;background:var(--ember-soft)">
      <span class="lab" style="display:block;margin-bottom:5px">Pembagian plafon</span>
      <p class="hint" style="margin:0 0 10px">Mode <b>budgeting</b>: lima kategori prioritas di bawah
        mendapat alokasi utama, sisanya menyesuaikan anggaran yang tersisa. Yang tidak kebagian tetap
        masuk penawaran tapi ditandai <b>opsional</b> — klien perlu melihat apa yang dikorbankan
        angkanya, bukan menerima daftar yang sudah dipangkas diam-diam.</p>
      <div style="display:flex;justify-content:space-between;font-size:12.8px;margin-bottom:4px">
        <span style="color:var(--ivory-60)"><span id="alokJml">0</span> jenis vendor dicentang</span>
        <span class="mono" style="color:var(--ember)">± <span id="alokPer">—</span> per jenis</span>
      </div>
      <div style="height:8px;background:var(--ivory-07);border-radius:4px;overflow:hidden">
        <div id="alokBar" style="height:100%;width:0;background:var(--ember);border-radius:4px;transition:width .2s"></div>
      </div>
    </div>

    <span class="lab" style="display:block;margin:20px 0 4px">Top 5 prioritas</span>
    <p class="hint" style="margin:0 0 11px">Dari yang dicentang di atas, lima mana yang harus dapat
      kualitas terbaik. Urutan menentukan: peringkat 1 dan 2 yang paling menentukan rasa acara,
      sisanya bergerak lebih longgar saat anggaran ditekan.</p>
    <?= blokTop5($katInduk) ?>
  </div>
  <?php endif; ?>

  <div style="margin-top:18px">
    <button class="btn solid" type="submit">Simpan klien</button>
  </div>

<script>
/* Tipe klien mengubah ARTI kolom budget, bukan sekadar menandainya.
   Sebelumnya memilih tematis atau budgeting tidak mengubah apa pun sampai
   penawaran dibuat — jadi pilihannya terasa hiasan. Sekarang bedanya terlihat
   saat mengisi: pada budgeting, angka itu plafon, wajib, dan langsung dibagi
   ke jenis vendor yang dicentang. */
const rpFmt = n => 'Rp ' + Math.round(n).toLocaleString('id-ID');

function hitungAlokasi() {
  const blok = document.getElementById('alokasi');
  if (!blok || blok.hidden) return;
  const n = document.querySelectorAll('.vn-cek:checked').length;
  const plafon = parseInt((document.getElementById('be').value || '0').replace(/\D/g, ''), 10) || 0;
  document.getElementById('alokJml').textContent = n;
  document.getElementById('alokPer').textContent = (n > 0 && plafon > 0) ? rpFmt(plafon / n) : '—';
  document.getElementById('alokBar').style.width = Math.min(100, n * 100 / 12) + '%';
}

function terapkanTipe() {
  const budgeting = document.querySelector('input[name="tipe_klien"]:checked')?.value === 'budgeting';
  const be = document.getElementById('be');
  if (!be) return;

  document.getElementById('lblBudget').textContent = budgeting ? 'Plafon anggaran' : 'Perkiraan budget';
  document.getElementById('hintBudget').innerHTML = budgeting
    ? 'Batas atas yang <b>tidak boleh dilewati</b>. Vendor diisi sampai plafon habis, lalu berhenti.'
    : 'Angka kasar dari klien. Tidak mengikat.';
  be.required = budgeting;
  be.style.borderColor = budgeting ? 'var(--ember-line)' : '';

  const blok = document.getElementById('alokasi');
  if (blok) blok.hidden = !budgeting;
  hitungAlokasi();
}

document.querySelectorAll('input[name="tipe_klien"]').forEach(r => r.addEventListener('change', terapkanTipe));
document.getElementById('be')?.addEventListener('input', hitungAlokasi);

document.querySelectorAll('.vn-cek').forEach(cb => cb.addEventListener('change', hitungAlokasi));
terapkanTipe();
</script>
<?= blokTop5Skrip() ?>
</form>

<?php
/* ============================================================
   PAPAN PIPELINE
   ============================================================ */
else:
  $due   = clientsDue();
  $fTahap  = isset(PIPE_STAGES[$_GET['tahap'] ?? '']) ? $_GET['tahap'] : '';
  $fSumber = trim($_GET['src'] ?? '');
  $fCari   = trim($_GET['q'] ?? '');
  // Saringan pegangan: menjawab pertanyaan yang selama ini tidak bisa dijawab
  // papan mana pun — "mana klien yang sudah jadi tanggung jawab office".
  $fPic    = in_array($_GET['pic'] ?? '', ['admin_biasa','admin_office','dl_kosong'], true) ? $_GET['pic'] : '';
  $w = ["stage NOT IN ('selesai','batal')"]; $par = [];
  if ($fTahap)  { $w[0] = "stage = ?"; $par[] = $fTahap; }
  if ($fSumber) { $w[] = "source = ?"; $par[] = $fSumber; }
  if ($fPic === 'dl_kosong') {
      $w[] = "stage IN ('deal','persiapan','harih') AND data_lengkap_at IS NULL";
  } elseif ($fPic) { $w[] = "pic_role = ?"; $par[] = $fPic; }

  // Cakupan bawaan menurut peran. Bukan sekadar saringan yang bisa dibersihkan:
  // admin office memang tidak punya urusan dengan prospek yang belum deal, dan
  // menampilkannya cuma bikin dia mengira ada yang harus dikerjakan.
  //
  // Ini juga yang membuat ketergantungan alurnya terasa: kalau admin early
  // belum menutup satu deal pun, papan admin office memang kosong — bukan
  // error, memang belum ada yang diserahkan.
  if ($peranSaya === 'admin_office') {
      $w[] = "stage IN ('deal','persiapan','harih')";
  }
  if ($fCari)   { $w[] = "(name LIKE ? OR partner_name LIKE ? OR email LIKE ? OR phone LIKE ? OR venue LIKE ?)";
                  array_push($par, "%$fCari%", "%$fCari%", "%$fCari%", "%$fCari%", "%$fCari%"); }
  $semua = all("SELECT * FROM clients WHERE " . implode(' AND ', $w)
               . " ORDER BY COALESCE(next_action_at,'2099-12-31'), id DESC", $par);
  $totalKlien = (int) (one($peranSaya === 'admin_office'
      ? "SELECT COUNT(*) c FROM clients WHERE stage IN ('deal','persiapan','harih')"
      : "SELECT COUNT(*) c FROM clients WHERE stage NOT IN ('selesai','batal')")['c'] ?? 0);
  $arsip = all("SELECT * FROM clients WHERE stage IN ('selesai','batal') ORDER BY updated_at DESC LIMIT 40");
  $kalBulan = intval_between($_GET['b'] ?? date('n'), 1, 12, (int) date('n'));
  $kalTahun = intval_between($_GET['y'] ?? date('Y'), 2020, 2100, (int) date('Y'));
  $kal = kalenderBulan($kalTahun, $kalBulan);
  $prevB = $kalBulan === 1 ? 12 : $kalBulan - 1;  $prevY = $kalBulan === 1 ? $kalTahun - 1 : $kalTahun;
  $nextB = $kalBulan === 12 ? 1 : $kalBulan + 1;  $nextY = $kalBulan === 12 ? $kalTahun + 1 : $kalTahun;

  $byStage = [];
  foreach ($semua as $x) $byStage[$x['stage']][] = $x;

  $nilai = (float) (one("SELECT COALESCE(SUM(deal_value),0) v FROM clients WHERE stage IN ('deal','persiapan','harih')")['v'] ?? 0);
  $prospek = (float) (one("SELECT COALESCE(SUM(budget_estimate),0) v FROM clients WHERE stage IN ('baru','pricelist','spesifikasi','penawaran')")['v'] ?? 0);

  pageHead('Klien',
           $peranSaya === 'admin_office'
             ? 'Klien yang sudah deal dan diserahkan admin early. Tugasnya melengkapi data — orang tua, prosesi, susunan acara.'
             : 'Setiap prospek punya satu tahap dan satu tindakan berikutnya yang bertanggal — supaya tidak ada yang menguap.',
           $bolehBuat ? '<a class="btn solid" href="?new=1">+ Klien baru</a>' : '');
?>

<div class="grid g4">
  <div class="stat accent"><span class="n"><?= count($due) ?></span><span class="d">Perlu ditindaklanjuti</span></div>
  <div class="stat"><span class="n"><?= count($semua) ?></span><span class="d">Klien aktif</span></div>
  <div class="stat"><span class="n" style="font-size:22px"><?= rupiah($nilai, true) ?></span><span class="d">Nilai terkunci</span></div>
  <div class="stat"><span class="n" style="font-size:22px"><?= rupiah($prospek, true) ?></span><span class="d">Potensi prospek</span></div>
</div>

<?php if ($due): ?>
<div class="card" style="margin-top:18px;border-color:rgba(233,168,92,.4)">
  <h2>Perlu ditindaklanjuti sekarang</h2>
  <p class="sub">Tenggat tindakannya hari ini atau sudah lewat.</p>
  <table class="tbl">
    <thead><tr><th>Klien</th><th>Tahap</th><th>Yang harus dilakukan</th><th>Tenggat</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($due as $x): $t = strtotime($x['next_action_at']) < strtotime(date('Y-m-d')); ?>
      <tr>
        <td data-l="Klien"><b><?= e($x['name'] . ($x['partner_name'] ? ' & ' . $x['partner_name'] : '')) ?></b>
          <?php if ($x['wedding_date']): ?><br><span class="muted mono"><?= tanggalID($x['wedding_date']) ?></span><?php endif; ?></td>
        <td data-l="Tahap"><span class="pill draft"><?= e(stageLabel($x['stage'])) ?></span></td>
        <td data-l="Yang harus dilakukan"><?= e($x['next_action']) ?></td>
        <td class="num" data-l="Tenggat" style="<?= $t ? 'color:var(--rose)' : 'color:var(--ember)' ?>"><?= e(labelHari($x['next_action_at'])) ?></td>
        <td class="actions"><a class="btn sm solid" href="?id=<?= $x['id'] ?>">Buka</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($peranSaya === 'admin_office' && !$semua && !$fCari && !$fTahap && !$fSumber): ?>
<div class="card" style="margin-top:18px;text-align:center;padding:44px 24px">
  <h2 style="margin-bottom:6px">Belum ada klien yang diserahkan</h2>
  <p class="sub" style="max-width:470px;margin:0 auto">
    Klien masuk ke sini setelah admin early menutup deal — bukan dicatat dari sini.
    Selama belum ada penawaran yang disetujui, papan ini memang kosong.
  </p>
</div>
<?php endif; ?>

<form method="get" class="filterbar" style="margin-top:18px">
  <input type="text" name="q" value="<?= e($fCari) ?>" placeholder="Cari nama, email, telepon, atau venue">
  <select name="tahap"><option value="">Semua tahap</option>
    <?php $tahapTampil = $peranSaya === 'admin_office' ? ['deal','persiapan','harih'] : PIPE_ACTIVE;
          foreach ($tahapTampil as $st): ?><option value="<?= $st ?>" <?= $fTahap === $st ? 'selected' : '' ?>><?= e(stageLabel($st)) ?></option><?php endforeach; ?></select>
  <select name="src"><option value="">Semua sumber</option>
    <?php foreach (['instagram'=>'Instagram','whatsapp'=>'WhatsApp','web'=>'Situs web','referral'=>'Rekomendasi','vendor'=>'Vendor','walkin'=>'Datang langsung','lainnya'=>'Lainnya'] as $k=>$v): ?>
      <option value="<?= $k ?>" <?= $fSumber === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
  <select name="pic"><option value="">Semua pegangan</option>
    <?php foreach (['admin_biasa'=>'Admin early','admin_office'=>'Admin office','dl_kosong'=>'Deal, data lengkap kosong'] as $k=>$v): ?>
      <option value="<?= $k ?>" <?= $fPic === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
  <button class="btn sm" type="submit">Saring</button>
  <?php if ($fCari || $fTahap || $fSumber || $fPic): ?><a class="btn sm ghost" href="klien.php">Bersihkan</a><?php endif; ?>
  <span class="hit"><?= count($semua) ?> dari <?= $totalKlien ?> klien aktif</span>
</form>

<div class="card" style="margin-top:18px">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap">
    <div>
      <h2>Kalender ketersediaan</h2>
      <p class="sub">Tanggal berpenanda sudah terisi. <?= $kal['jumlahAcara'] ?> hari-H di bulan ini.</p>
    </div>
    <div style="display:flex;gap:7px;align-items:center;flex-wrap:wrap">
      <a class="btn sm ghost" href="?y=<?= $prevY ?>&b=<?= $prevB ?>">←</a>
      <span class="mono" style="min-width:132px;text-align:center;font-size:13px"><?= e($kal['nama']) ?></span>
      <a class="btn sm ghost" href="?y=<?= $nextY ?>&b=<?= $nextB ?>">→</a>
      <?php if ($kalBulan != date('n') || $kalTahun != date('Y')): ?>
        <a class="btn sm ghost" href="klien.php">Bulan ini</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="kal" style="margin-top:16px">
    <?php foreach (['Sen','Sel','Rab','Kam','Jum','Sab','Min'] as $h): ?>
      <div class="kal-h"><?= $h ?></div>
    <?php endforeach; ?>
    <?php foreach ($kal['sel'] as $sel):
      if ($sel === null) { echo '<div class="kal-x"></div>'; continue; }
      $acara = $sel['isi']['acara'] ?? [];
      $event = $sel['isi']['event'] ?? [];
      $temu  = $sel['isi']['temu']  ?? [];
      $dow   = (int) date('N', strtotime($sel['tanggal']));
      $cls   = [];
      if ($acara) $cls[] = 'penuh';
      elseif ($event) $cls[] = 'ada';
      elseif ($temu) $cls[] = 'temu';
      else $cls[] = 'kosong';
      if ($sel['tanggal'] === date('Y-m-d')) $cls[] = 'kini';
      if ($dow >= 6) $cls[] = 'pekan';
      if (strtotime($sel['tanggal']) < strtotime(date('Y-m-d'))) $cls[] = 'lalu';
      $judul = [];
      foreach ($acara as $a) $judul[] = 'Hari-H: ' . $a['label'] . ($a['ket'] ? ' — ' . $a['ket'] : '');
      foreach ($event as $a) $judul[] = 'Event: ' . $a['label'];
      foreach ($temu  as $a) $judul[] = 'Pertemuan ' . $a['ket'] . ' — ' . $a['label'];
    ?>
      <div class="kal-d <?= implode(' ', $cls) ?>" <?= $judul ? 'title="' . e(implode("\n", $judul)) . '"' : '' ?>>
        <span class="n"><?= $sel['hari'] ?></span>
        <?php foreach (array_slice($acara, 0, 2) as $a): ?>
          <a class="kal-i acara" href="?id=<?= $a['id'] ?>"><?= e(mb_strimwidth($a['label'], 0, 18, '…')) ?></a>
        <?php endforeach; ?>
        <?php foreach (array_slice($event, 0, 1) as $a): ?>
          <a class="kal-i event" href="event.php?edit=<?= $a['id'] ?>"><?= e(mb_strimwidth($a['label'], 0, 18, '…')) ?></a>
        <?php endforeach; ?>
        <?php foreach (array_slice($temu, 0, 2) as $a): ?>
          <a class="kal-i temu" href="jadwal.php?edit=<?= $a['id'] ?>"><?= e($a['ket']) ?> <?= e(mb_strimwidth($a['label'], 0, 12, '…')) ?></a>
        <?php endforeach; ?>
        <?php $sisa = max(0, count($acara) - 2) + max(0, count($event) - 1) + max(0, count($temu) - 2);
              if ($sisa): ?><span class="kal-s">+<?= $sisa ?> lagi</span><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="kal-ket">
    <span><i class="k-kosong"></i> Kosong</span>
    <span><i class="k-temu"></i> Ada pertemuan</span>
    <span><i class="k-ada"></i> Ada event</span>
    <span><i class="k-penuh"></i> Hari-H terisi</span>
  </div>
</div>

<div class="card" style="margin-top:18px">
  <h2>Papan pipeline</h2>
  <p class="sub">Garis kiri kartu berwarna oranye bila tenggat hari ini, merah bila sudah lewat.</p>
  <div class="board" style="margin-top:16px">
    <?php foreach (PIPE_ACTIVE as $st):
      $rows = $byStage[$st] ?? []; ?>
      <div class="col">
        <h3><span><?= e(stageLabel($st)) ?></span><b><?= count($rows) ?></b></h3>
        <?php if (!$rows): ?>
          <p style="font-size:12px;color:var(--ivory-38);padding:6px 2px">—</p>
        <?php else: foreach ($rows as $x):
          $d = hariKe($x['next_action_at']);
          $cls = $d === null ? '' : ($d < 0 ? 'late' : ($d === 0 ? 'due' : '')); ?>
          <a class="lead <?= $cls ?>" href="?id=<?= $x['id'] ?>">
            <span class="nm"><?= e($x['name'] . ($x['partner_name'] ? ' & ' . $x['partner_name'] : '')) ?></span>
            <?php if ($x['wedding_date']): ?>
              <span class="dt"><?= date('d M Y', strtotime($x['wedding_date'])) ?> · <?= e(labelHari($x['wedding_date'])) ?></span>
            <?php endif; ?>
            <?php if ($x['next_action']): ?>
              <span class="na"><?= e(mb_strimwidth($x['next_action'], 0, 46, '…')) ?><?= $x['next_action_at'] ? ' · ' . e(labelHari($x['next_action_at'])) : '' ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($arsip): ?>
<div class="card">
  <h2>Arsip</h2>
  <p class="sub">Sudah selesai atau tidak jadi. Alasan yang tidak jadi dicatat supaya bisa dievaluasi.</p>
  <table class="tbl">
    <thead><tr><th>Klien</th><th>Tanggal acara</th><th>Status</th><th>Catatan</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($arsip as $x): ?>
      <tr>
        <td data-l="Klien"><b><?= e($x['name'] . ($x['partner_name'] ? ' & ' . $x['partner_name'] : '')) ?></b></td>
        <td class="num" data-l="Tanggal acara"><?= $x['wedding_date'] ? tanggalID($x['wedding_date']) : '—' ?></td>
        <td data-l="Status"><span class="pill <?= $x['stage'] === 'selesai' ? 'live' : 'bad' ?>"><?= e(stageLabel($x['stage'])) ?></span></td>
        <td data-l="Catatan"><?= e(mb_strimwidth($x['lost_reason'] ?: '—', 0, 60, '…')) ?></td>
        <td class="actions"><a class="btn sm ghost" href="?id=<?= $x['id'] ?>">Buka</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<script>
(() => {
  // baris acara tambahan
  document.getElementById('tambahSeg')?.addEventListener('click', () => {
    const w = document.getElementById('segLain');
    const b = w.lastElementChild.cloneNode(true);
    b.querySelectorAll('input').forEach(i => i.value = '');
    w.appendChild(b);
  });
  // saran jumlah kru mengikuti jumlah tamu
  const ge = document.getElementById('ge2'), ks = document.getElementById('kruSaran');
  ge?.addEventListener('input', () => {
    const n = parseInt(ge.value, 10);
    ks.textContent = n > 0 ? Math.max(2, Math.ceil(n / <?= tamuPerKru() ?>)) : '—';
  });
  // catat pesan lalu buka WhatsApp dengan isi yang sama
  document.getElementById('kirimWa')?.addEventListener('click', e => {
    const f = e.target.closest('form');
    const isi = f.querySelector('[name=body]').value.trim();
    if (!isi) { alert('Isi pesannya dulu.'); return; }
    window.open('https://wa.me/' + e.target.dataset.wa + '?text=' + encodeURIComponent(isi), '_blank', 'noopener');
    f.submit();
  });

  // salin brief
  document.getElementById('salinBrief')?.addEventListener('click', async e => {
    try {
      await navigator.clipboard.writeText(document.getElementById('briefTeks').value);
      const t = e.target.textContent; e.target.textContent = 'Tersalin';
      setTimeout(() => e.target.textContent = t, 1600);
    } catch (_) {}
  });
})();
</script>
<?php endif; adminFoot();
