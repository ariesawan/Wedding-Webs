<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../inc/pipeline.php';
require_once __DIR__ . '/../inc/vendor.php';
require_once __DIR__ . '/../inc/chat.php';
require_once __DIR__ . '/../partials/blok-top5.php';
require_once __DIR__ . '/../inc/wa.php';
require_once __DIR__ . '/../inc/penawaran.php';
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

    q("INSERT INTO client_wedding_info
        (client_id, akad_tanggal, akad_jam, akad_lokasi,
         resepsi_tanggal, resepsi_jam, resepsi_lokasi,
         prosesi_adat, prosesi_adat_lainnya, prosesi_adat_detail,
         venue_tipe, jenis_acara, sitting_mode, catatan)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE
         akad_tanggal=VALUES(akad_tanggal), akad_jam=VALUES(akad_jam), akad_lokasi=VALUES(akad_lokasi),
         resepsi_tanggal=VALUES(resepsi_tanggal), resepsi_jam=VALUES(resepsi_jam), resepsi_lokasi=VALUES(resepsi_lokasi),
         prosesi_adat=VALUES(prosesi_adat), prosesi_adat_lainnya=VALUES(prosesi_adat_lainnya),
         prosesi_adat_detail=VALUES(prosesi_adat_detail), venue_tipe=VALUES(venue_tipe),
         jenis_acara=VALUES(jenis_acara), sitting_mode=VALUES(sitting_mode),
         catatan=IF(VALUES(catatan) <> '', VALUES(catatan), catatan)",
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
       trim($_POST['base_catatan'] ?? '')]);

    simpanTamu($id, $tRes, $tAkad);
    simpanKebutuhan($id);
}

/**
 * Tamu resepsi dan tamu akad — satu pintu untuk semua formulir.
 *
 * Angka tamu dulu ditulis dari tiga tempat dengan aturan berbeda: formulir
 * klien baru (ke client_wedding_info), kartu Data klien (mengirim
 * tamu_resepsi tapi menyimpan guest_estimate = NULL karena kolom lamanya
 * sudah tidak ada di formulir), dan Susunan hari (langsung ke
 * guest_estimate). Hasilnya angka di ringkasan, penawaran, dan susunan bisa
 * berbeda untuk klien yang sama.
 *
 * guest_estimate tetap jadi angka utama (penawaran, papan, analisa),
 * diturunkan dari resepsi lalu akad. NULL = kolom itu tidak dikirim, biarkan.
 */
function simpanTamu(int $id, ?int $resepsi, ?int $akad): void
{
    if ($resepsi === null && $akad === null) return;
    try {
        q("INSERT INTO client_wedding_info (client_id, tamu_resepsi, tamu_akad) VALUES (?,?,?)
           ON DUPLICATE KEY UPDATE
             tamu_resepsi = COALESCE(VALUES(tamu_resepsi), tamu_resepsi),
             tamu_akad    = COALESCE(VALUES(tamu_akad), tamu_akad)", [$id, $resepsi, $akad]);
        $wi = one("SELECT tamu_resepsi, tamu_akad FROM client_wedding_info WHERE client_id = ?", [$id]);
        $utama = (int) ($wi['tamu_resepsi'] ?? 0) ?: (int) ($wi['tamu_akad'] ?? 0);
    } catch (Throwable $e) {
        $utama = (int) ($resepsi ?: $akad);   // kolom tamu_* belum ada di database lama
    }
    q("UPDATE clients SET guest_estimate = ? WHERE id = ?", [$utama ?: null, $id]);
}

/**
 * Jenis vendor yang dibutuhkan + Top 5 prioritas.
 *
 * Disimpan lewat aksi sendiri ('kebutuhan'). Dulu kartu Kebutuhan vendor
 * mengirim aksi 'save' hanya dengan nama klien — dan 'save' menulis ulang
 * SELURUH baris klien dari isi formulir. Setiap kali "Simpan kebutuhan"
 * ditekan, pasangan, email, WhatsApp, tanggal, venue, budget, dan catatan
 * klien terhapus jadi kosong.
 */
function simpanKebutuhan(int $id): void
{
    // Jenis vendor yang dibutuhkan — dipakai penawaran sebagai daftar item.
    if (isset($_POST['vendor_need']) || isset($_POST['kebutuhan_form'])) {
        q("DELETE FROM client_vendor_needs WHERE client_id = ?", [$id]);
        $urut = 0;
        foreach ((array) ($_POST['vendor_need'] ?? []) as $catId) {
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
            $ada  = fn(string ...$k) => (bool) array_filter($k, fn($x) => array_key_exists($x, $_POST));
            $teks = fn(string $k, int $max = 190) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
            $uang = fn(string $k) => ($_POST[$k] ?? '') !== '' ? (float) preg_replace('/\D/', '', (string) $_POST[$k]) : null;

            // HANYA kolom yang benar-benar dikirim formulir yang ditulis.
            //
            // Dulu aksi ini menyusun seluruh baris klien dari $_POST dengan
            // nilai kosong sebagai bawaan. Formulir yang memuat sebagian kolom
            // saja — kartu Kebutuhan vendor misalnya, yang hanya mengirim nama —
            // menghapus pasangan, email, WhatsApp, tanggal, venue, budget, dan
            // catatan klien setiap kali disimpan.
            $data = [];
            if ($ada('name') || !$id) {
                $data['name'] = $teks('name', 120);
                if ($data['name'] === '') throw new RuntimeException('Nama klien wajib diisi.');
            }
            if ($ada('email')) {
                $data['email'] = $teks('email');
                if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL))
                    throw new RuntimeException('Email tidak valid.');
            }
            foreach (['partner_name' => 120, 'phone' => 40, 'city' => 120, 'package' => 120,
                      'sumber_detail' => 120, 'next_action' => 190] as $k => $max) {
                if ($ada($k)) $data[$k] = $teks($k, $max);
            }
            if ($ada('instagram')) $data['instagram'] = ltrim($teks('instagram', 80), '@');
            if ($ada('notes'))     $data['notes'] = trim((string) $_POST['notes']);
            if ($ada('next_action_at')) $data['next_action_at'] = trim((string) $_POST['next_action_at']) ?: null;
            if ($ada('source')) {
                $data['source'] = in_array($_POST['source'], ['instagram','whatsapp','web','referral','vendor','walkin','lainnya'], true)
                                ? $_POST['source'] : 'lainnya';
            }
            if ($ada('paket_minat')) {
                // Paket diminati menunjuk ke paket di menu Paket & price list,
                // supaya tombol "Kirim price list" bisa langsung memakainya.
                $pm = (int) $_POST['paket_minat'];
                $pt = $pm ? one("SELECT id, nama FROM quote_templates WHERE id = ?", [$pm]) : null;
                $data['paket_minat'] = $pt ? (int) $pt['id'] : null;
                // Teks lama (klien sebelum v23) dibiarkan kalau tidak memilih paket.
                if ($pt) $data['package'] = $pt['nama'];
            }
            if ($ada('budget_estimate')) $data['budget_estimate'] = $uang('budget_estimate');
            if ($ada('deal_value'))      $data['deal_value']      = $uang('deal_value');

            // Hari-H, jam, dan venue diturunkan dari Base information: resepsi
            // dulu, lalu akad. Satu fakta, satu tempat — kalau tanggal nikah
            // diketik terpisah, yang satu diam-diam menimpa yang lain.
            if ($ada('resepsi_tanggal', 'akad_tanggal', 'wedding_date')) {
                $data['wedding_date'] = trim($_POST['resepsi_tanggal'] ?? '')
                                     ?: (trim($_POST['akad_tanggal'] ?? '') ?: (trim($_POST['wedding_date'] ?? '') ?: null));
            }
            if ($ada('resepsi_jam', 'akad_jam', 'wedding_time')) {
                $data['wedding_time'] = trim($_POST['resepsi_jam'] ?? '')
                                     ?: (trim($_POST['akad_jam'] ?? '') ?: (trim($_POST['wedding_time'] ?? '') ?: null));
            }
            if ($ada('resepsi_lokasi', 'akad_lokasi', 'venue')) {
                $data['venue'] = mb_substr(trim($_POST['resepsi_lokasi'] ?? '')
                              ?: (trim($_POST['akad_lokasi'] ?? '') ?: trim($_POST['venue'] ?? '')), 0, 190);
            }

            // Tipe klien menentukan cara penawaran disusun nanti:
            //   tematis   -> item mengikuti request, total adalah hasil
            //   budgeting -> plafon dikunci, vendor diisi sampai plafon habis
            if ($ada('tipe_klien')) {
                $data['tipe_klien'] = in_array($_POST['tipe_klien'], ['tematis','budgeting'], true)
                                    ? $_POST['tipe_klien'] : '';
            }
            foreach (['usia_pria', 'usia_wanita'] as $ku) {
                if (!$ada($ku)) continue;
                $v = (int) $_POST[$ku];
                // Di luar 17–80 hampir pasti salah ketik, bukan data nyata.
                // Menyimpannya akan merusak rentang usia di laporan iklan.
                $data[$ku] = ($v >= 17 && $v <= 80) ? $v : null;
            }
            foreach (['kerja_pria', 'kerja_wanita'] as $kk) {
                if ($ada($kk)) $data[$kk] = $teks($kk, 80);
            }

            if ($id) {
                $lama = one("SELECT wedding_date FROM clients WHERE id = ?", [$id]);
                if (!$lama) throw new RuntimeException('Klien tidak ditemukan.');
                if ($data) {
                    $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
                    q("UPDATE clients SET $set WHERE id = ?", [...array_values($data), $id]);
                }
                flash('Data klien tersimpan.');

                // Tanggal nikah bergeser -> seluruh checklist ikut digeser.
                $tglBaru = $data['wedding_date'] ?? null;
                if ($tglBaru && $lama['wedding_date'] !== $tglBaru) {
                    // Termin yang dihitung mundur dari hari-H ikut bergeser, dan
                    // klien deal yang belum punya event (tanggal baru diketahui
                    // setelah DP) dibuatkan sekarang.
                    $nt = terminGeser($id, $tglBaru);
                    if ($nt) clientLog($id, 'sistem', 'Jatuh tempo termin digeser', "$nt termin mengikuti tanggal baru.", $user['id']);
                    if (eventPastikan($id)) flash('Data klien tersimpan. Event dibuat di menu Event.');
                    $n = retimeTasks($id, $tglBaru);
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

                $data += ['source' => 'lainnya', 'city' => 'Yogyakarta'];
                $data['stage']            = 'baru';
                $data['stage_changed_at'] = date('Y-m-d H:i:s');
                $data['created_by']       = $user['id'];
                if (empty($data['next_action'])) {
                    $data['next_action']    = stageNext('baru');
                    $data['next_action_at'] = date('Y-m-d', strtotime('+' . stageSla('baru') . ' day'));
                }
                $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
                q("INSERT INTO clients ($cols) VALUES (" . implode(',', array_fill(0, count($data), '?')) . ")", array_values($data));
                $id = insertId();
                clientLog($id, 'sistem', 'Klien dicatat', 'Sumber: ' . $data['source'], $user['id']);
                flash('Klien baru dicatat. Langkah berikutnya: kirim price list.');
                $plLangsung = ($_POST['lanjut'] ?? '') === 'pl';
            }
            simpanBaseInfo($id);
            // Formulir lama yang masih mengirim satu angka tamu.
            if (!isset($_POST['base_info']) && $ada('guest_estimate')) {
                simpanTamu($id, ($_POST['guest_estimate'] ?? '') !== '' ? max(0, (int) $_POST['guest_estimate']) : null, null);
            }
            // Room chat dibuat/ditautkan di sini, bukan menunggu pesan pertama.
            try { chatSinkronKlien($id); } catch (Throwable $e) { /* gagal sinkron bukan alasan simpan batal */ }
            // "Simpan & susun price list": langsung ke dokumennya.
            if (!empty($plLangsung)) {
                if (!empty($data['paket_minat'])) {
                    $qid = quoteDariPaket($id, (int) $data['paket_minat'], 'pricelist', (int) $user['id']);
                    clientLog($id, 'sistem', 'Price list dibuat dari paket', $data['package'], $user['id']);
                    flash('Klien dicatat dan price list paket "' . $data['package'] . '" disiapkan. Periksa, lalu kirim lewat WhatsApp.');
                    redirect('admin/penawaran.php?id=' . $qid);
                }
                redirect('admin/penawaran.php?client=' . $id);
            }
            redirect('admin/klien.php?id=' . $id . (isset($_POST['tab']) ? '#' . preg_replace('/[^a-z]/', '', $_POST['tab']) : ''));
        }

        elseif ($act === 'kebutuhan') {
            $id = (int) ($_POST['id'] ?? 0);
            if (!one("SELECT id FROM clients WHERE id = ?", [$id])) throw new RuntimeException('Klien tidak ditemukan.');
            $_POST['kebutuhan_form'] = 1;
            simpanKebutuhan($id);
            $n = (int) (one("SELECT COUNT(*) n FROM client_vendor_needs WHERE client_id = ?", [$id])['n'] ?? 0);
            clientLog($id, 'catatan', 'Kebutuhan vendor diperbarui', "$n jenis vendor", $user['id']);
            flash("Kebutuhan vendor tersimpan ($n jenis). Daftar ini yang jadi baris penawaran.");
            redirect('admin/klien.php?id=' . $id . '#kebutuhan');
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

        elseif ($act === 'dp_masuk') {
            // DP 30% masuk = serah terima ke admin office. Satu tombol, satu
            // pintu: termin DP ditandai lunas, klien deal, event dibuat.
            $id = (int) ($_POST['id'] ?? 0);
            if (!in_array($user['role'] ?? '', ['owner', 'admin_early'], true))
                throw new RuntimeException('Konfirmasi DP dilakukan admin early atau owner.');
            $r = dpDiterima($id, (int) $user['id'], (string) ($_POST['tanggal'] ?? ''),
                            mb_substr(trim((string) ($_POST['metode'] ?? '')), 0, 60));
            flash($r['dp']['label'] . ' ' . rupiah((float) $r['dp']['amount']) . ' tercatat masuk. '
                . 'Klien sekarang Deal dan dipegang admin office.'
                . ($r['info'] ? "\n" . implode("\n", $r['info']) : ''));
            redirect('admin/klien.php?id=' . $id);
        }

        elseif ($act === 'dekor') {
            $id = (int) ($_POST['id'] ?? 0);
            if (!one("SELECT id FROM clients WHERE id = ?", [$id])) throw new RuntimeException('Klien tidak ditemukan.');
            $konsep = mb_substr(trim((string) ($_POST['konsep_dekor'] ?? '')), 0, 8000);
            q("INSERT INTO client_wedding_info (client_id, konsep_dekor) VALUES (?, ?)
               ON DUPLICATE KEY UPDATE konsep_dekor = VALUES(konsep_dekor)", [$id, $konsep]);
            clientLog($id, 'catatan', 'Konsep dekor diperbarui', mb_strimwidth($konsep, 0, 160, '…'), $user['id']);
            flash('Konsep dekor tersimpan.');
            redirect('admin/klien.php?id=' . $id . '#dekor');
        }

        elseif ($act === 'stage') {
            $id = (int) $_POST['id'];
            // Admin office hanya memindahkan klien yang sudah DP, dan tidak
            // mundur ke tahap sebelum DP (kecuali menandai tidak jadi). Serah
            // terima hanya lewat konfirmasi DP oleh admin early / owner.
            if (($user['role'] ?? '') === 'admin_office') {
                $kini = (string) (one("SELECT stage FROM clients WHERE id = ?", [$id])['stage'] ?? '');
                $ke   = (string) ($_POST['stage'] ?? '');
                if (!stageSudahDeal($kini) || (!stageSudahDeal($ke) && $ke !== 'batal'))
                    throw new RuntimeException('Tahap sebelum DP dipegang admin early.');
            }
            $r  = clientSetStage($id, $_POST['stage'] ?? '', $user['id'], trim($_POST['note'] ?? ''));
            flash('Tahap diperbarui.' . ($r['info'] ? "\n" . implode("\n", $r['info']) : ''));
            redirect('admin/klien.php?id=' . $id);
        }

        elseif ($act === 'susunan') {
            $id = (int) $_POST['id'];

            // Layanan (checkbox)
            $lay = array_values(array_intersect(array_keys(layananBawaan()), (array) ($_POST['services'] ?? [])));
            q("UPDATE clients SET services = ?, crew_count = ? WHERE id = ?", [
                implode(',', $lay),
                ($_POST['crew_count'] ?? '') !== '' ? (int) $_POST['crew_count'] : null,
                $id,
            ]);
            // Angka tamu di kartu ini = tamu resepsi. Lewat simpanTamu() supaya
            // ringkasan, penawaran, dan susunan memegang angka yang sama.
            if (($_POST['guest_estimate'] ?? '') !== '') simpanTamu($id, max(0, (int) $_POST['guest_estimate']), null);

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

        elseif ($act === 'task_generate') {
            $id = (int) $_POST['id'];
            $n  = checklistSusun($id);
            flash($n ? "Checklist persiapan dibuat ($n langkah)." : 'Checklist sudah ada.');
            redirect('admin/klien.php?id=' . $id . '#checklist');
        }

        elseif ($act === 'pay_generate') {
            $id = (int) $_POST['id'];
            $c0 = one("SELECT deal_value FROM clients WHERE id = ?", [$id]);
            if (!$c0 || (float) $c0['deal_value'] <= 0)
                throw new RuntimeException('Nilai deal belum diisi. Isi dulu di tab Data klien.');
            $n = terminSusun($id);
            if ($n) clientLog($id, 'bayar', "Termin disusun dari template ($n termin)", rupiah((float) $c0['deal_value']), $user['id']);
            flash($n ? "Termin pembayaran disusun dari template ($n termin)." : 'Termin sudah ada — hapus dulu kalau mau disusun ulang.');
            redirect('admin/klien.php?id=' . $id . '#uang');
        }

        elseif ($act === 'task_toggle') {
            $tid = (int) $_POST['task_id'];
            $t   = one("SELECT * FROM client_tasks WHERE id = ? AND client_id = ?", [$tid, (int) $_POST['id']]);
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
            q("DELETE FROM client_tasks WHERE id = ? AND client_id = ?", [(int) $_POST['task_id'], (int) $_POST['id']]);
            redirect('admin/klien.php?id=' . (int) $_POST['id'] . '#checklist');
        }

        elseif ($act === 'pay_save') {
            $id  = (int) $_POST['id'];
            $pid = (int) ($_POST['payment_id'] ?? 0);
            $amt = (float) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
            $par = [trim($_POST['label'] ?? 'Termin'), $amt, trim($_POST['due_date'] ?? '') ?: null,
                    trim($_POST['paid_at'] ?? '') ?: null, trim($_POST['method'] ?? ''), trim($_POST['note'] ?? '')];
            if ($pid) {
                q("UPDATE payments SET label=?, amount=?, due_date=?, paid_at=?, method=?, note=? WHERE id=? AND client_id=?", [...$par, $pid, $id]);
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
            $p   = one("SELECT * FROM payments WHERE id = ? AND client_id = ?", [$pid, (int) $_POST['id']]);
            if (!$p) throw new RuntimeException('Termin tidak ditemukan.');
            // Termin DP ditandai lunas saat klien menunggu DP = serah terima.
            // Lewat dpDiterima supaya tahap, event, dan pegangan ikut pindah.
            $cDp = one("SELECT stage FROM clients WHERE id = ?", [(int) $p['client_id']]);
            if (!$p['paid_at'] && ($cDp['stage'] ?? '') === 'dp' && (int) (terminDp((int) $p['client_id'])['id'] ?? 0) === $pid) {
                if (!in_array($user['role'] ?? '', ['owner', 'admin_early'], true))
                    throw new RuntimeException('Konfirmasi DP dilakukan admin early atau owner.');
                $r = dpDiterima((int) $p['client_id'], (int) $user['id']);
                flash($p['label'] . ' diterima — klien sekarang Deal dan dipegang admin office.'
                    . ($r['info'] ? "\n" . implode("\n", $r['info']) : ''));
                redirect('admin/klien.php?id=' . (int) $p['client_id']);
            }
            q("UPDATE payments SET paid_at = " . ($p['paid_at'] ? 'NULL' : 'CURDATE()') . " WHERE id = ?", [$pid]);
            if ($p && !$p['paid_at']) {
                clientLog((int) $_POST['id'], 'bayar', $p['label'] . ' diterima', rupiah($p['amount']), $user['id']);
            }
            redirect('admin/klien.php?id=' . (int) $_POST['id'] . '#uang');
        }

        elseif ($act === 'pay_del') {
            q("DELETE FROM payments WHERE id = ? AND client_id = ?", [(int) $_POST['payment_id'], (int) $_POST['id']]);
            redirect('admin/klien.php?id=' . (int) $_POST['id'] . '#uang');
        }

        elseif ($act === 'delete') {
            $id = (int) $_POST['id'];
            if (($user['role'] ?? '') === 'admin_office')
                throw new RuntimeException('Admin office tidak menghapus klien. Minta owner atau admin early.');
            // Semua tabel turunan ikut dibersihkan. Dulu hanya tiga yang
            // dihapus; penawaran, kebutuhan vendor, susunan, dan data lengkap
            // tertinggal tanpa induk — dan nomor penawarannya tetap terhitung.
            try {
                q("DELETE qi FROM quote_items qi JOIN quotes qq ON qq.id = qi.quote_id WHERE qq.client_id = ?", [$id]);
            } catch (Throwable $e) { /* tabel lama */ }
            foreach (['client_tasks', 'payments', 'client_activities', 'quotes', 'client_vendor_needs',
                      'client_top_vendors', 'client_segments', 'client_wedding_info', 'client_family',
                      'client_vendors', 'vendor_messages', 'client_analisa'] as $t) {
                try { q("DELETE FROM `$t` WHERE client_id = ?", [$id]); } catch (Throwable $e) { /* tabel belum ada */ }
            }
            q("UPDATE meetings SET client_id = NULL WHERE client_id = ?", [$id]);
            foreach (['wa_chats' => 'client_id', 'wa_messages' => 'client_id'] as $t => $kol) {
                try { q("UPDATE `$t` SET `$kol` = NULL WHERE `$kol` = ?", [$id]); } catch (Throwable $e) {}
            }
            try { q("UPDATE wa_chats SET room_client_id = NULL WHERE room_client_id = ?", [$id]); } catch (Throwable $e) {}
            q("DELETE FROM clients WHERE id = ?", [$id]);
            logAudit((int) $user['id'], 'klien_hapus', 'clients#' . $id);
            flash('Klien dihapus beserta penawaran, checklist, pembayaran, dan riwayatnya.');
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
                   WHERE a.client_id = ? ORDER BY a.created_at DESC, a.id DESC LIMIT 60", [$c['id']]);
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

    // ---- Bahan tampilan bertahap ----
    // Halaman ini dulu menumpuk sebelas kartu dalam satu kolom untuk SETIAP
    // klien — prospek yang baru bertanya harga ikut disuguhi formulir orang
    // tua, checklist hari-H, dan termin pembayaran. Sekarang: satu kartu
    // "Langkah sekarang" yang isinya mengikuti tahap, lalu tab untuk sisanya.
    $quotes = [];
    try { $quotes = all("SELECT * FROM quotes WHERE client_id = ? ORDER BY id DESC", [$c['id']]); } catch (Throwable $e) {}
    $plAkhir = $pnAkhir = null;
    foreach ($quotes as $qx) {
        if ($qx['jenis'] === 'pricelist' && !$plAkhir) $plAkhir = $qx;
        if ($qx['jenis'] === 'penawaran' && !$pnAkhir) $pnAkhir = $qx;
    }
    $nKebutuhan = 0; $sudahAnalisa = false;
    try { $nKebutuhan = (int) (one("SELECT COUNT(*) n FROM client_vendor_needs WHERE client_id = ?", [$c['id']])['n'] ?? 0); } catch (Throwable $e) {}
    try { $sudahAnalisa = (bool) one("SELECT 1 FROM client_analisa WHERE client_id = ? LIMIT 1", [$c['id']]); } catch (Throwable $e) {}

    $stage     = $c['stage'];
    $sudahDeal = stageSudahDeal($stage);
    $bolehJual = in_array($peranSaya, ['owner', 'admin_early'], true);   // menyusun & memutus penawaran
    $officeTunggu = $peranSaya === 'admin_office' && !$sudahDeal && $stage !== 'batal';

    // Dokumen terakhir yang masih berlaku (revisi lama dilewati).
    $qAkhir = null;
    foreach ($quotes as $qx) if ($qx['status'] !== 'revisi') { $qAkhir = $qx; break; }
    $paketPilihan = $bolehJual ? paketDaftar(false) : [];
    $pMinat = null;
    foreach ($paketPilihan as $t) if ((int) $t['id'] === (int) ($c['paket_minat'] ?? 0)) $pMinat = $t;
    $dpRow = in_array($stage, ['dp', 'deal'], true) ? terminDp((int) $c['id']) : null;
    $rek   = rekeningBaris();
    $waKlien = $c['phone'] ? preg_replace('/\D/', '', $c['phone']) : '';
    if (str_starts_with($waKlien, '0')) $waKlien = '62' . substr($waKlien, 1);

    $stQ = fn(string $s) => [
        'draf' => ['Draf', 'draft'], 'terkirim' => ['Terkirim', 'warn'], 'cocok' => ['Disetujui', 'live'],
        'revisi' => ['Direvisi', 'draft'], 'tidak_cocok' => ['Ditolak', 'bad'],
    ][$s] ?? [$s, 'draft'];

    // Formulir kecil pindah tahap — dipakai beberapa tombol di kartu langkah.
    $formTahap = function (string $ke, string $label, string $cls = 'solid', string $tanya = '') use ($c): string {
        return '<form method="post" style="display:inline"' . ($tanya ? ' onsubmit="return confirm(' . e(json_encode($tanya)) . ')"' : '') . '>'
             . csrfField() . '<input type="hidden" name="act" value="stage"><input type="hidden" name="id" value="' . (int) $c['id'] . '">'
             . '<input type="hidden" name="stage" value="' . e($ke) . '"><button class="btn ' . $cls . '" type="submit">' . e($label) . '</button></form>';
    };
    // Butir "sudah / belum" di kartu langkah.
    $butir = fn(bool $ok, string $teks, string $href = '') =>
        '<li class="' . ($ok ? 'ok' : '') . '"><span class="tanda">' . ($ok ? '✓' : '○') . '</span>'
        . ($href && !$ok ? '<a href="' . e($href) . '">' . $teks . '</a>' : '<span>' . $teks . '</span>') . '</li>';

    $tglHariH = $c['wedding_date'] ? hariID($c['wedding_date']) . ', ' . tanggalID($c['wedding_date']) : '';
    $hitungMundur = $c['wedding_date'] === null ? '—'
        : ($hK > 0 ? 'H-' . $hK : ($hK === 0 ? 'Hari ini' : ($sudahDeal ? 'Selesai' : 'Sudah lewat')));
    $nextTask = null;
    foreach ($tasks as $t) if (!$t['done_at']) { $nextTask = $t; break; }
    $nextPay = null;
    foreach ($pays as $p) if (!$p['paid_at']) { $nextPay = $p; break; }

    pageHead($judul,
        stageLabel($stage) . ' · ' . (PIPE_STAGES[$stage]['desc'] ?? ''),
        '<a class="btn ghost" href="klien.php">← Semua klien</a>');
?>

<!-- ---------- Jalur tahap ---------- -->
<div class="card jalur">
  <ol class="stepper" aria-label="Perjalanan klien">
    <?php $lewat = $stage !== 'batal'; $posKini = stageUrut($stage);
      foreach (array_merge(PIPE_ACTIVE, ['selesai']) as $i => $st):
        $kls = $stage === 'batal' ? 'todo' : ($i < $posKini ? 'done' : ($i === $posKini ? 'now' : 'todo')); ?>
      <li class="<?= $kls ?>"><span class="no"><?= $kls === 'done' ? '✓' : $i + 1 ?></span><span class="tx"><?= e(stageLabel($st)) ?></span></li>
    <?php endforeach; ?>
  </ol>

  <div class="jalur-bawah">
    <div style="display:flex;gap:9px;flex-wrap:wrap;align-items:center">
      <?php $office = ($c['pic_role'] ?? '') === 'admin_office'; ?>
      <span class="lab">Pegangan</span>
      <span class="pill" style="<?= $office ? 'border-color:var(--sage);color:var(--sage)' : 'border-color:var(--ember-line);color:var(--ember)' ?>">
        <?= $office ? 'Admin office' : 'Admin early' ?></span>
      <?php if ($stage === 'batal'): ?><span class="pill bad">Tidak jadi</span><?php endif; ?>
      <?php if ($dlTertunda): ?><a class="pill warn" href="#datalengkap" style="text-decoration:none">Data lengkap belum diisi →</a><?php endif; ?>
    </div>
    <?php if (!$officeTunggu): ?>
    <details class="pindah">
      <summary class="btn sm ghost">Ubah tahap manual</summary>
      <form method="post" class="pindah-form">
        <?= csrfField() ?><input type="hidden" name="act" value="stage"><input type="hidden" name="id" value="<?= $c['id'] ?>">
        <div class="field" style="margin:0;min-width:180px">
          <label>Tahap</label>
          <select name="stage" onchange="this.form.note.required = this.value === 'batal'">
            <?php foreach (array_unique([...TAHAP_PILIHAN, $stage]) as $k): ?>
              <option value="<?= $k ?>" <?= $stage === $k ? 'selected' : '' ?>><?= e($k === 'batal' ? 'Tidak jadi' : stageLabel($k)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="margin:0;flex:1;min-width:200px">
          <label>Catatan / alasan</label>
          <input type="text" name="note" placeholder="Wajib bila memilih Tidak jadi">
        </div>
        <button class="btn" type="submit">Pindahkan</button>
      </form>
      <p class="hint" style="margin:8px 0 0">Biasanya tidak perlu: tahap maju sendiri saat price list terkirim, klien cocok, dan DP ditandai masuk.</p>
    </details>
    <?php endif; ?>
  </div>
</div>

<div class="g-side">
  <div>
    <!-- ---------- Langkah sekarang ---------- -->
    <div class="card langkah-kini" id="langkah">
      <span class="lab">Langkah sekarang</span>
      <?php if ($officeTunggu): ?>
        <h2>Belum diserahkan</h2>
        <p class="sub" style="margin:0">Klien ini masih tahap <b><?= e(stageLabel($stage)) ?></b> dan dipegang admin early.
          Begitu DP 30% masuk, klien pindah ke papanmu otomatis.</p>

      <?php elseif ($stage === 'baru'): ?>
        <h2>Lengkapi biodata awal &amp; kirim price list</h2>
        <p class="sub"><?= $c['dari_form'] ? 'Masuk sendiri lewat formulir — prospek paling panas. ' : '' ?>Cek data awalnya, lalu kirim price list:
          dari paket di situs atau template sendiri. Dokumennya PDF dan dikirim lewat WhatsApp; begitu terkirim, tahap maju ke <b>Price list terkirim</b>.</p>
        <ul class="butir">
          <?= $butir($c['name'] !== '' && (string) $c['partner_name'] !== '', 'Nama kedua mempelai', '#data') ?>
          <?= $butir((bool) $c['phone'], $c['phone'] ? 'WhatsApp ' . e($c['phone']) : 'Nomor WhatsApp', '#data') ?>
          <?= $butir((bool) $c['wedding_date'], $c['wedding_date'] ? 'Tanggal rencana ' . e(tanggalID($c['wedding_date'])) : 'Tanggal rencana', '#data') ?>
          <?= $butir((bool) $c['guest_estimate'], $c['guest_estimate'] ? '±' . number_format((int) $c['guest_estimate'], 0, ',', '.') . ' tamu' : 'Perkiraan jumlah tamu', '#data') ?>
          <?= $butir((bool) $pMinat, $pMinat ? 'Paket diminati: ' . e($pMinat['nama']) : 'Paket diminati belum dipilih', '#data') ?>
        </ul>
        <div class="aksi">
          <?php if ($plAkhir && $plAkhir['status'] === 'draf'): ?>
            <a class="btn solid" href="penawaran.php?id=<?= (int) $plAkhir['id'] ?>">Lanjutkan price list <?= e($plAkhir['nomor']) ?> →</a>
          <?php elseif ($bolehJual && $pMinat): ?>
            <form method="post" action="penawaran.php" style="display:inline">
              <?= csrfField() ?><input type="hidden" name="act" value="buat"><input type="hidden" name="jenis" value="pricelist">
              <input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="template_id" value="<?= (int) $pMinat['id'] ?>">
              <button class="btn solid" type="submit">Siapkan price list <?= e($pMinat['nama']) ?> →</button></form>
            <a class="btn" href="penawaran.php?client=<?= (int) $c['id'] ?>">Paket lain / susun sendiri</a>
          <?php elseif ($bolehJual): ?>
            <a class="btn solid" href="penawaran.php?client=<?= (int) $c['id'] ?>">Pilih paket &amp; susun price list →</a>
          <?php endif; ?>
          <?php if ($waKlien): ?><a class="btn ghost" target="_blank" rel="noopener" href="https://wa.me/<?= e($waKlien) ?>">WhatsApp ↗</a><?php endif; ?>
          <a class="btn ghost" href="jadwal.php?new=1&client=<?= (int) $c['id'] ?>">Jadwalkan konsultasi</a>
        </div>

      <?php elseif (in_array($stage, ['pricelist', 'spesifikasi', 'penawaran'], true)): ?>
        <h2>Tunggu tanggapan klien</h2>
        <?php if ($qAkhir): [$sl, $sc] = $stQ($qAkhir['status']); $totQ = (float) $qAkhir['total']; ?>
          <p class="sub"><b><?= e($qAkhir['nomor']) ?></b><?= !empty($qAkhir['paket_nama']) ? ' · ' . e($qAkhir['paket_nama']) : '' ?>
            · <?= $totQ > 0 ? rupiah($totQ) : '<span style="color:var(--ember)">harga belum diisi</span>' ?>
            · <span class="pill <?= $sc ?>"><?= e($sl) ?></span>
            <?= $qAkhir['sent_at'] ? ' · terkirim ' . e(labelHari(substr($qAkhir['sent_at'], 0, 10))) : '' ?>
            · <?= $qAkhir['seen_at'] ? '<span style="color:var(--sage)">sudah dibuka klien ' . e(labelHari(substr($qAkhir['seen_at'], 0, 10))) . '</span>' : 'belum dibuka klien' ?>
            <?php if (!empty($qAkhir['nego_nilai'])): ?><br>Klien menawar <b style="color:var(--ember)"><?= rupiah((float) $qAkhir['nego_nilai']) ?></b><?= $qAkhir['nego_catatan'] ? ' — ' . e($qAkhir['nego_catatan']) : '' ?><?php endif; ?></p>
          <?php if ($bolehJual): ?>
          <div class="aksi">
            <?php if ($qAkhir['status'] === 'cocok'): ?>
              <?= $formTahap('dp', 'Lanjut: tagih DP 30% →') ?>
            <?php elseif ($totQ > 0 && in_array($qAkhir['status'], ['draf', 'terkirim'], true)): ?>
              <form method="post" action="penawaran.php" style="display:inline"
                    onsubmit="return confirm(<?= e(json_encode('Klien cocok dengan ' . $qAkhir['nomor'] . ' (' . rupiah($totQ) . ')? DP 30% (' . rupiah(round($totQ * 0.3)) . ') langsung ditagih.')) ?>)">
                <?= csrfField() ?><input type="hidden" name="act" value="cocok"><input type="hidden" name="id" value="<?= (int) $qAkhir['id'] ?>">
                <button class="btn solid" type="submit">Klien cocok → tagih DP 30%</button></form>
            <?php endif; ?>
            <a class="btn" href="penawaran.php?id=<?= (int) $qAkhir['id'] ?>">Buka price list</a>
            <a class="btn ghost" href="jadwal.php?new=1&client=<?= (int) $c['id'] ?>">Jadwalkan konsultasi</a>
          </div>
          <?php if ($totQ <= 0): ?>
            <p class="hint" style="margin:10px 0 0;color:var(--ember)">Harga paket belum diisi — isi dulu di price list supaya DP 30% bisa dihitung.</p>
          <?php else: ?>
            <p class="hint" style="margin:10px 0 0">Klien menawar → buka price list, catat tawarannya lalu buat revisi. Tidak cocok → catat di sana juga (masuk Analisa).</p>
          <?php endif; ?>
          <?php endif; ?>
        <?php else: ?>
          <p class="sub">Belum ada price list tercatat untuk klien ini.</p>
          <?php if ($bolehJual): ?><div class="aksi"><a class="btn solid" href="penawaran.php?client=<?= (int) $c['id'] ?>">Susun price list →</a></div><?php endif; ?>
        <?php endif; ?>

      <?php elseif ($stage === 'dp'): ?>
        <h2>Tagih DP 30%</h2>
        <?php if ($dpRow): ?>
          <p class="sub">Klien cocok<?= $c['deal_value'] ? ' dengan nilai <b>' . rupiah((float) $c['deal_value']) . '</b>' : '' ?>.
            Tagih <b><?= e($dpRow['label']) ?> <?= rupiah((float) $dpRow['amount']) ?></b><?= $dpRow['due_date'] ? ' paling lambat <b>' . e(tanggalID($dpRow['due_date'])) . '</b>' : '' ?>.
            Begitu DP masuk, klien otomatis diserahkan ke <b>admin office</b> untuk biodata lengkap, dekor, venue, termin, dan meeting.</p>
          <?php if ($bolehJual): ?>
            <form method="post" class="row c3" style="align-items:end;margin:4px 0 10px">
              <?= csrfField() ?><input type="hidden" name="act" value="dp_masuk"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <div class="field" style="margin:0"><label>Tanggal DP masuk</label><input type="date" name="tanggal" value="<?= date('Y-m-d') ?>"></div>
              <div class="field" style="margin:0"><label>Cara bayar</label>
                <select name="metode"><option>Transfer bank</option><option>Tunai</option><option>QRIS</option><option>Lainnya</option></select></div>
              <div class="field" style="margin:0"><button class="btn solid" type="submit" style="width:100%"
                onclick="return confirm('DP sudah benar-benar masuk? Klien akan diserahkan ke admin office.')">Konfirmasi DP masuk</button></div>
            </form>
            <?php
              $tagihWA = 'Halo ' . $c['name'] . ', terima kasih sudah memilih ' . setting('site_name', 'Callalily Party') . '.'
                . "\n\nUntuk mengunci tanggal" . ($c['wedding_date'] ? ' ' . tanggalID($c['wedding_date']) : '')
                . ', mohon transfer ' . $dpRow['label'] . ' sebesar ' . rupiah((float) $dpRow['amount'])
                . ($dpRow['due_date'] ? ' paling lambat ' . tanggalID($dpRow['due_date']) : '') . '.'
                . ($rek ? "\n\n" . implode("\n", $rek) : '')
                . "\n\nSetelah transfer, mohon kirim bukti transfernya di sini ya. Terima kasih!";
            ?>
            <div class="aksi">
              <?php if ($waKlien): ?><a class="btn" target="_blank" rel="noopener" href="https://wa.me/<?= e($waKlien) ?>?text=<?= rawurlencode($tagihWA) ?>">Kirim tagihan DP lewat WhatsApp ↗</a><?php endif; ?>
              <a class="btn ghost" href="#uang">Lihat termin</a>
              <?php if ($qAkhir): ?><a class="btn ghost" href="penawaran.php?id=<?= (int) $qAkhir['id'] ?>">Price list</a><?php endif; ?>
            </div>
            <?php if (!$rek): ?><p class="hint" style="margin:10px 0 0;color:var(--ember)">Nomor rekening belum diisi di Pengaturan → Price list &amp; pembayaran, jadi teks tagihan belum memuat rekening.</p><?php endif; ?>
          <?php endif; ?>
        <?php else: ?>
          <p class="sub">Nilai deal belum ada, jadi DP 30% belum bisa dihitung. Isi harga di price list yang disetujui, atau nilai deal di tab Biodata awal.</p>
          <?php if ($qAkhir): ?><div class="aksi"><a class="btn solid" href="penawaran.php?id=<?= (int) $qAkhir['id'] ?>">Buka price list</a></div><?php endif; ?>
        <?php endif; ?>

      <?php elseif ($stage === 'deal'):
        $meetOffice = array_filter($meets, fn($m) => !$c['handover_at'] || $m['start_at'] >= $c['handover_at']);
        $venueAda = !empty($wi['resepsi_lokasi']) || !empty($wi['akad_lokasi']) || !empty($c['venue']); ?>
        <h2>Deal — susun acara bersama klien</h2>
        <p class="sub">DP sudah masuk<?= !empty($c['handover_at']) ? ' ' . e(mb_strtolower(labelHari(substr($c['handover_at'], 0, 10)))) : '' ?><?= $c['deal_value'] ? ' · kontrak ' . rupiah((float) $c['deal_value']) : '' ?>.
          Lengkapi bersama klien, lalu mulai persiapan.</p>
        <ul class="butir">
          <?= $butir(!empty($c['data_lengkap_at']), 'Biodata lengkap &amp; keluarga mempelai', '#datalengkap') ?>
          <?= $butir($venueAda, $venueAda ? 'Venue: ' . e($wi['resepsi_lokasi'] ?? '' ?: ($wi['akad_lokasi'] ?? '' ?: $c['venue'])) : 'Pilih venue &amp; jam acara', '#data') ?>
          <?= $butir(trim((string) ($wi['konsep_dekor'] ?? '')) !== '', 'Konsep &amp; susunan dekor', '#dekor') ?>
          <?= $butir((bool) $vAda, $vAda ? count($vAda) . ' vendor dipilih' : 'Pilih vendor', '#vendor') ?>
          <?= $butir(count($pays) > 1, $pays ? count($pays) . ' termin pembayaran tersusun' : 'Susun termin pembayaran', '#uang') ?>
          <?= $butir((bool) $meetOffice, $meetOffice ? count($meetOffice) . ' meeting tercatat' : 'Jadwalkan meeting pertama', 'jadwal.php?new=1&client=' . (int) $c['id']) ?>
        </ul>
        <div class="aksi">
          <?= $formTahap('persiapan', 'Mulai persiapan →', 'solid', 'Mulai persiapan? Checklist H-90 sampai H+3 dibuat otomatis.') ?>
          <a class="btn ghost" href="jadwal.php?new=1&client=<?= (int) $c['id'] ?>">Jadwalkan meeting</a>
        </div>

      <?php elseif ($stage === 'persiapan' || $stage === 'harih'): ?>
        <h2><?= $stage === 'harih' ? 'Minggu hari-H' : 'Persiapan berjalan' ?></h2>
        <p class="sub"><?= count($tasks) ? $done . ' dari ' . count($tasks) . ' langkah checklist selesai.' : 'Checklist belum dibuat.' ?>
          <?php if ($nextTask): ?><br>Berikutnya: <b><?= e($nextTask['title']) ?></b><?= $nextTask['due_date'] ? ' · ' . e(labelHari($nextTask['due_date'])) : '' ?><?php endif; ?>
          <?php if ($nextPay): ?><br>Tagihan berikutnya: <b><?= e($nextPay['label']) ?></b> <?= rupiah((float) $nextPay['amount']) ?><?= $nextPay['due_date'] ? ' · ' . e(labelHari($nextPay['due_date'])) : '' ?><?php endif; ?></p>
        <?php if (count($tasks)): ?><div class="bar-progress" style="margin-bottom:14px"><i style="width:<?= round($done / count($tasks) * 100) ?>%"></i></div><?php endif; ?>
        <div class="aksi">
          <a class="btn solid" href="#checklist">Buka checklist</a>
          <a class="btn ghost" href="#uang">Pembayaran</a>
          <?php if ($stage === 'harih'): ?><?= $formTahap('selesai', 'Tandai acara selesai', 'ghost', 'Tandai acara ini selesai?') ?><?php endif; ?>
        </div>

      <?php elseif ($stage === 'selesai'): ?>
        <h2>Acara selesai</h2>
        <p class="sub">Minta testimoni dan izin pakai foto selagi kesannya masih hangat<?= $sudahAnalisa ? '.' : ', lalu catat kenapa klien ini berhasil.' ?></p>
        <?php if (!$sudahAnalisa): ?><div class="aksi"><a class="btn solid" href="analisa.php?klien=<?= (int) $c['id'] ?>">Catat analisa →</a></div><?php endif; ?>

      <?php elseif ($stage === 'batal'): ?>
        <h2>Tidak jadi</h2>
        <p class="sub"><?= $c['lost_reason'] ? 'Alasan: ' . e($c['lost_reason']) : 'Alasan tidak dicatat.' ?></p>
        <div class="aksi">
          <?php if (!$sudahAnalisa): ?><a class="btn solid" href="analisa.php?klien=<?= (int) $c['id'] ?>">Catat sebabnya di Analisa →</a><?php endif; ?>
          <?= $formTahap('baru', 'Aktifkan lagi', 'ghost', 'Aktifkan lagi klien ini sebagai prospek baru?') ?>
        </div>
      <?php endif; ?>

      <?php if (!in_array($stage, ['selesai', 'batal'], true) && !$officeTunggu): ?>
        <details class="tidakjadi">
          <summary>Klien mundur / tidak jadi?</summary>
          <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:10px">
            <?= csrfField() ?><input type="hidden" name="act" value="stage"><input type="hidden" name="id" value="<?= $c['id'] ?>">
            <input type="hidden" name="stage" value="batal">
            <div class="field" style="margin:0;flex:1;min-width:220px"><label>Alasan (wajib)</label>
              <input type="text" name="note" required placeholder="Ambil WO lain, tanggal bentrok, budget…"></div>
            <button class="btn sm danger" type="submit">Tandai tidak jadi</button>
          </form>
        </details>
      <?php endif; ?>
    </div>

    <!-- ---------- Tab ---------- -->
    <?php
    $tabs = [
        // Urutannya mengikuti perjalanan klien: biodata awal & price list
        // (admin early), lalu biodata lengkap, acara & dekor, vendor, dan
        // pembayaran (admin office, setelah DP).
        'ikhtisar'    => ['Ikhtisar', 0],
        'data'        => ['Biodata awal', 0],
        'penawaran'   => ['Price list', count($quotes)],
        'datalengkap' => ['Biodata lengkap', 0],
        'kebutuhan'   => ['Acara & dekor', 0],
        'vendor'      => ['Vendor', count($vAda)],
        'uang'        => ['Pembayaran', count($pays)],
    ];
    ?>
    <nav class="tabs" id="tabKlien" role="tablist">
      <?php foreach ($tabs as $k => [$lbl, $n]): ?>
        <a href="#tab-<?= $k ?>" role="tab" data-tab="<?= $k ?>"><?= e($lbl) ?><?php if ($n): ?> <span class="n"><?= $n ?></span><?php endif; ?>
          <?php if ($k === 'datalengkap' && $dlTertunda): ?><span class="titik" title="Belum diisi"></span><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>

    <section class="tabpane" data-pane="ikhtisar" id="tab-ikhtisar">
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

    <!-- ---------- Pertemuan ---------- -->
    <div class="card" id="pertemuan">
      <div style="display:flex;justify-content:space-between;gap:12px;align-items:baseline;flex-wrap:wrap">
        <h2>Pertemuan</h2>
        <a class="btn sm ghost" href="jadwal.php?new=1&client=<?= $c['id'] ?>">+ Jadwalkan</a>
      </div>
      <?php if (!$meets): ?>
        <p class="sub" style="margin:0">Belum ada pertemuan. Konsultasi (admin early) maupun meeting persiapan (admin office) dijadwalkan dari sini.</p>
      <?php else: foreach ($meets as $m): ?>
        <div style="padding:11px 0;border-bottom:1px solid var(--ivory-07)">
          <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline">
            <span class="mono" style="font-size:12px"><?= tanggalID($m['start_at']) ?>, <?= date('H.i', strtotime($m['start_at'])) ?></span>
            <?php if ($m['outcome']): ?>
              <span class="pill <?= ['lanjut'=>'live','pikir'=>'warn','batal'=>'bad'][$m['outcome']] ?>">
                <?= ['lanjut'=>'Lanjut','pikir'=>'Dipikir','batal'=>'Tidak jadi'][$m['outcome']] ?></span>
            <?php elseif ($m['status'] === 'canceled'): ?>
              <span class="pill draft">Dibatalkan</span>
            <?php elseif (strtotime($m['start_at']) < time()): ?>
              <a class="pill warn" href="jadwal.php?edit=<?= $m['id'] ?>" style="text-decoration:none">Catat hasil →</a>
            <?php else: ?>
              <a class="pill" href="jadwal.php?edit=<?= $m['id'] ?>" style="text-decoration:none"><?= e(labelHari(substr($m['start_at'], 0, 10))) ?></a>
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
      <?php endforeach; endif; ?>
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
          <?php foreach ($acts as $i => $a):
            // Delapan teratas cukup untuk tahu "terakhir ada apa"; sisanya dilipat.
            if ($i === 8): ?>
        </ul>
        <details class="tl-lagi"><summary>Tampilkan <?= count($acts) - 8 ?> catatan lebih lama</summary>
        <ul class="tl">
            <?php endif; ?>
            <li class="<?= $i === 0 ? 'hi' : '' ?>">
              <span class="w"><?= tanggalID($a['created_at'], true) ?><?= $a['uname'] ? ' · ' . e($a['uname']) : '' ?></span>
              <div class="t"><?= e($a['title']) ?></div>
              <?php if ($a['detail']): ?><div class="d"><?= e($a['detail']) ?></div><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if (count($acts) > 8): ?></details><?php endif; ?>
      <?php else: ?>
        <p class="sub">Belum ada riwayat.</p>
      <?php endif; ?>
    </div>
    </section>

    <section class="tabpane" data-pane="kebutuhan" id="tab-kebutuhan">
    <!-- ---------- Acara, venue & dekor ---------- -->
    <div class="card" id="dekor">
      <h2>Acara, venue &amp; dekor</h2>
      <p class="sub">Disusun admin office bersama klien setelah DP. Tanggal, jam, dan lokasi akad/resepsi diubah di
        <a href="#data" style="color:var(--ember)">Biodata awal</a>; susunan dekor dicatat di sini.</p>
      <div class="grid g2" style="margin-bottom:14px">
        <div><span class="lab">Akad / pemberkatan</span>
          <div style="margin-top:5px;font-size:14px"><?= !empty($wi['akad_tanggal']) ? e(tanggalID($wi['akad_tanggal'])) . (!empty($wi['akad_jam']) ? ' · ' . substr($wi['akad_jam'], 0, 5) : '') : '<span class="muted">Tanggal belum ada</span>' ?><br>
            <?= !empty($wi['akad_lokasi']) ? e($wi['akad_lokasi']) : '<span class="muted">Lokasi belum dipilih</span>' ?></div></div>
        <div><span class="lab">Resepsi</span>
          <div style="margin-top:5px;font-size:14px"><?= !empty($wi['resepsi_tanggal']) ? e(tanggalID($wi['resepsi_tanggal'])) . (!empty($wi['resepsi_jam']) ? ' · ' . substr($wi['resepsi_jam'], 0, 5) : '') : ($c['wedding_date'] ? e(tanggalID($c['wedding_date'])) : '<span class="muted">Tanggal belum ada</span>') ?><br>
            <?= !empty($wi['resepsi_lokasi']) ? e($wi['resepsi_lokasi']) : ($c['venue'] ? e($c['venue']) : '<span class="muted">Venue belum dipilih</span>') ?></div></div>
      </div>
      <form method="post">
        <?= csrfField() ?><input type="hidden" name="act" value="dekor"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <div class="field"><label for="kd">Konsep &amp; susunan dekor</label>
          <textarea id="kd" name="konsep_dekor" rows="7" placeholder="Tema &amp; palet warna&#10;Pelaminan / backdrop&#10;Bunga (segar / artifisial), meja tamu, area foto&#10;Jalur masuk, pencahayaan&#10;Referensi (tautan Pinterest / Instagram)"><?= e($wi['konsep_dekor'] ?? '') ?></textarea>
          <p class="hint" style="margin:6px 0 0">Ditulis sesuai hasil meeting dengan klien — ini yang dibawa ke vendor dekorasi.</p></div>
        <button class="btn solid" type="submit">Simpan konsep dekor</button>
      </form>
    </div>
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
      <p class="sub">Jenis vendor yang diminta klien. Daftar inilah yang jadi baris penawaran saat
        disusun tanpa template — urutannya mengikuti prioritas acara.</p>

      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="act" value="kebutuhan">
        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <input type="hidden" name="kebutuhan_form" value="1">

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
    </section>

    <section class="tabpane" data-pane="penawaran" id="tab-penawaran">
    <div class="card" id="daftarpenawaran">
      <div style="display:flex;justify-content:space-between;gap:12px;align-items:baseline;flex-wrap:wrap">
        <h2>Price list &amp; penawaran</h2>
        <?php if ($bolehJual): ?><a class="btn sm solid" href="penawaran.php?client=<?= (int) $c['id'] ?>">+ Buat baru</a><?php endif; ?>
      </div>
      <?php if (!$quotes): ?>
        <p class="sub" style="margin:0">Belum ada. Price list disusun dari paket (atau template kosong) lalu dikirim sebagai PDF lewat WhatsApp.</p>
      <?php else: ?>
        <table class="tbl">
          <thead><tr><th>Nomor</th><th>Jenis</th><th>Status</th><th class="num">Total</th><th>Dibuka klien</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($quotes as $qx): [$sl, $sc] = $stQ($qx['status']); ?>
            <tr>
              <td data-l="Nomor"><b><?= e($qx['nomor']) ?></b><?= $qx['revisi'] > 1 ? ' <span class="muted">rev ' . (int) $qx['revisi'] . '</span>' : '' ?></td>
              <td data-l="Jenis"><?= $qx['jenis'] === 'pricelist' ? 'Price list' : 'Penawaran' ?></td>
              <td data-l="Status"><span class="pill <?= $sc ?>"><?= e($sl) ?></span></td>
              <td class="num" data-l="Total"><?= (float) $qx['total'] > 0 ? rupiah((float) $qx['total']) : 'Rp 0' ?></td>
              <td class="num" data-l="Dibuka klien"><?= $qx['seen_at'] ? tanggalID(substr($qx['seen_at'], 0, 10)) : '—' ?></td>
              <td class="actions"><a class="btn sm" href="penawaran.php?id=<?= (int) $qx['id'] ?>">Buka</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    </section>

    <section class="tabpane" data-pane="datalengkap" id="tab-datalengkap">
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
        <p class="sub" style="color:var(--ember)">Klien belum DP. Biodata lengkap biasanya diisi admin office
          setelah DP masuk — boleh diisi sekarang kalau datanya kebetulan sudah diketahui, tapi bukan syarat
          mengirim price list.</p>
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
    </section>

    <section class="tabpane" data-pane="vendor" id="tab-vendor">
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
    </section>

    <section class="tabpane" data-pane="uang" id="tab-uang">
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
        <p class="sub">Belum ada termin. Termin disusun otomatis dari <b>template pembayaran</b> — sama dengan yang
          tercantum di penawaran — begitu klien deal dan nilai kontraknya ada.</p>
        <?php if ((float) $c['deal_value'] > 0): ?>
          <form method="post" style="margin-bottom:6px"><?= csrfField() ?>
            <input type="hidden" name="act" value="pay_generate"><input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button class="btn solid sm" type="submit">Susun termin dari template (<?= rupiah((float) $c['deal_value'], true) ?>)</button>
          </form>
        <?php elseif ($sudahDeal): ?>
          <p class="hint">Nilai deal belum diisi. Isi di tab <a href="#data" style="color:var(--ember)">Data klien</a>, lalu susun terminnya di sini.</p>
        <?php endif; ?>
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
    <?php else: ?>
    <div class="card" id="checklist">
      <h2>Checklist persiapan</h2>
      <p class="sub" style="margin-bottom:<?= $sudahDeal ? '14px' : '0' ?>">17 langkah dari H-90 sampai H+3, dihitung mundur dari tanggal pernikahan.
        Dibuat otomatis saat klien masuk tahap <b>Persiapan</b>.</p>
      <?php if ($sudahDeal): ?>
        <form method="post"><?= csrfField() ?>
          <input type="hidden" name="act" value="task_generate"><input type="hidden" name="id" value="<?= $c['id'] ?>">
          <button class="btn sm" type="submit">Buat checklist sekarang</button></form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    </section>

    <section class="tabpane" data-pane="data" id="tab-data">
    <?php
      // Akad & resepsi diisi di sini. Klien lama yang belum punya baris
      // base information diisi awal dari tanggal/jam/venue di tabel clients,
      // supaya menyimpan formulir ini tidak mengosongkan tanggal hari-H.
      $tanpaBase = empty($wi['akad_tanggal']) && empty($wi['resepsi_tanggal'])
                && empty($wi['akad_lokasi']) && empty($wi['resepsi_lokasi']);
      $rt = $wi['resepsi_tanggal'] ?? null; $rj = $wi['resepsi_jam'] ?? null; $rl = $wi['resepsi_lokasi'] ?? '';
      if ($tanpaBase) { $rt = $c['wedding_date']; $rj = $c['wedding_time']; $rl = $c['venue']; }
      $vt = array_filter(explode(',', (string) ($wi['venue_tipe'] ?? '')));
      $ja = $wi['jenis_acara'] ?? '';
    ?>
    <div class="card" id="data">
      <h2>Biodata awal</h2>
      <p class="sub">Cukup untuk mengirim price list. Hari-H, jam, dan venue di ringkasan diambil dari resepsi — kalau tidak ada resepsi, dari akad.
        Orang tua, prosesi adat, usia, dan pekerjaan ada di tab <a href="#datalengkap" style="color:var(--ember)">Biodata lengkap &amp; keluarga</a>.</p>
      <form method="post">
        <?= csrfField() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= $c['id'] ?>">
        <input type="hidden" name="base_info" value="1"><input type="hidden" name="tab" value="data">

        <span class="lab bagian">Pengantin &amp; kontak</span>
        <div class="row c2">
          <div class="field"><label>Mempelai pria</label><input type="text" name="name" required value="<?= e($c['name']) ?>"></div>
          <div class="field"><label>Mempelai wanita</label><input type="text" name="partner_name" value="<?= e($c['partner_name']) ?>"></div>
        </div>
        <div class="row c3">
          <div class="field"><label>WhatsApp</label><input type="text" name="phone" value="<?= e($c['phone']) ?>"></div>
          <div class="field"><label>Email</label><input type="email" name="email" value="<?= e($c['email']) ?>"></div>
          <div class="field"><label>Instagram</label><input type="text" name="instagram" value="<?= e($c['instagram']) ?>"></div>
        </div>
        <div class="row c2">
          <div class="field"><label>Tahu dari mana</label>
            <select name="source">
              <?php foreach (['instagram'=>'Instagram','whatsapp'=>'WhatsApp','web'=>'Situs web','referral'=>'Rekomendasi','vendor'=>'Vendor','walkin'=>'Datang langsung','lainnya'=>'Lainnya'] as $k => $v): ?>
                <option value="<?= $k ?>" <?= $c['source'] === $k ? 'selected' : '' ?>><?= $v ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>Rincinya</label><input type="text" name="sumber_detail" value="<?= e($c['sumber_detail'] ?? '') ?>" placeholder="Akun @siapa, teman siapa, vendor mana"></div>
        </div>

        <span class="lab bagian">Acara</span>
        <div class="dua">
          <div>
            <span class="sub-lab">Akad / pemberkatan</span>
            <div class="row c2">
              <div class="field"><label>Tanggal</label><input type="date" name="akad_tanggal" value="<?= e((string) ($wi['akad_tanggal'] ?? '')) ?>"></div>
              <div class="field"><label>Jam</label><input type="time" name="akad_jam" value="<?= !empty($wi['akad_jam']) ? substr($wi['akad_jam'], 0, 5) : '' ?>"></div>
            </div>
            <div class="field"><label>Lokasi</label><input type="text" name="akad_lokasi" value="<?= e($wi['akad_lokasi'] ?? '') ?>" placeholder="Masjid, gereja, atau rumah"></div>
            <div class="field"><label>Tamu akad</label><input type="number" name="tamu_akad" min="0" step="25" value="<?= e((string) ($wi['tamu_akad'] ?? '')) ?>"></div>
          </div>
          <div>
            <span class="sub-lab">Resepsi</span>
            <div class="row c2">
              <div class="field"><label>Tanggal</label><input type="date" name="resepsi_tanggal" value="<?= e((string) ($rt ?? '')) ?>"></div>
              <div class="field"><label>Jam</label><input type="time" name="resepsi_jam" value="<?= $rj ? substr($rj, 0, 5) : '' ?>"></div>
            </div>
            <div class="field"><label>Lokasi</label><input type="text" name="resepsi_lokasi" value="<?= e($rl) ?>" placeholder="Gedung / venue resepsi"></div>
            <div class="field"><label>Tamu resepsi</label><input type="number" name="tamu_resepsi" min="0" step="50" value="<?= e((string) ($wi['tamu_resepsi'] ?? ($c['guest_estimate'] ?? ''))) ?>"></div>
          </div>
        </div>
        <div class="row c3">
          <div class="field"><label>Kota</label><input type="text" name="city" value="<?= e($c['city']) ?>"></div>
          <div class="field"><label>Venue</label>
            <div style="display:flex;gap:16px;padding-top:10px">
              <label class="inline"><input type="checkbox" name="venue_tipe[indoor]" value="1" <?= in_array('indoor', $vt, true) ? 'checked' : '' ?>> Indoor</label>
              <label class="inline"><input type="checkbox" name="venue_tipe[outdoor]" value="1" <?= in_array('outdoor', $vt, true) ? 'checked' : '' ?>> Outdoor</label>
            </div></div>
          <div class="field"><label>Jenis acara</label>
            <select name="jenis_acara" onchange="document.getElementById('smData').hidden = this.value !== 'sitting'">
              <option value="">— belum ditentukan —</option>
              <option value="standing" <?= $ja === 'standing' ? 'selected' : '' ?>>Standing party</option>
              <option value="sitting" <?= $ja === 'sitting' ? 'selected' : '' ?>>Sitting arrangement</option>
            </select>
            <div id="smData" <?= $ja === 'sitting' ? '' : 'hidden' ?> style="margin-top:8px">
              <label class="inline" style="display:flex"><input type="radio" name="sitting_mode" value="per_seat" <?= ($wi['sitting_mode'] ?? '') === 'per_seat' ? 'checked' : '' ?>> Per seat — nama di tiap kursi</label>
              <label class="inline" style="display:flex"><input type="radio" name="sitting_mode" value="per_block" <?= ($wi['sitting_mode'] ?? '') === 'per_block' ? 'checked' : '' ?>> Per block — duduk bebas</label>
            </div></div>
        </div>

        <span class="lab bagian">Anggaran</span>
        <div class="row c2" style="margin-bottom:10px">
          <label class="pilih-tipe"><input type="radio" name="tipe_klien" value="tematis" <?= ($c['tipe_klien'] ?? '') !== 'budgeting' ? 'checked' : '' ?>>
            <span><b>On tematis</b><small>Paket mengikuti request. Budget hanya perkiraan.</small></span></label>
          <label class="pilih-tipe"><input type="radio" name="tipe_klien" value="budgeting" <?= ($c['tipe_klien'] ?? '') === 'budgeting' ? 'checked' : '' ?>>
            <span><b>On budgeting</b><small>Plafon dikunci, vendor diisi sampai plafon habis.</small></span></label>
        </div>
        <div class="row c3">
          <div class="field"><label>Perkiraan budget / plafon</label><input type="text" name="budget_estimate" data-rp inputmode="numeric" value="<?= $c['budget_estimate'] ? (int) $c['budget_estimate'] : '' ?>"></div>
          <div class="field"><label>Paket diminati</label>
            <?php $semuaPaket = paketDaftar(false); ?>
            <select name="paket_minat">
              <option value="0">— belum memilih —</option>
              <?php foreach ($semuaPaket as $t): ?>
                <option value="<?= (int) $t['id'] ?>" <?= (int) ($c['paket_minat'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['nama']) ?><?= empty($t['tampil_web']) ? ' (internal)' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!$c['paket_minat'] && $c['package']): ?><p class="hint">Tertulis: <?= e($c['package']) ?></p><?php endif; ?></div>
          <div class="field"><label>Nilai deal</label><input type="text" name="deal_value" data-rp inputmode="numeric" value="<?= $c['deal_value'] ? (int) $c['deal_value'] : '' ?>">
            <p class="hint">Terisi sendiri dari penawaran yang disetujui.</p></div>
        </div>
        <div class="field"><label>Catatan</label><textarea name="notes" rows="4"><?= e($c['notes']) ?></textarea></div>
        <button class="btn solid" type="submit">Simpan data klien</button>
      </form>

      <?php if ($peranSaya !== 'admin_office'): ?>
        <hr class="hr">
        <form method="post" onsubmit="return confirm('Hapus klien ini beserta penawaran, checklist, pembayaran, dan riwayatnya? Tidak bisa dibatalkan.\n\nKalau kliennya sekadar tidak jadi, pakai "Tidak jadi" saja supaya tercatat di Analisa.')">
          <?= csrfField() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>">
          <button class="btn sm danger ghost" type="submit">Hapus klien</button>
          <span class="hint" style="margin-left:8px">Untuk data salah ketik / ganda. Klien yang mundur cukup ditandai tidak jadi.</span>
        </form>
      <?php endif; ?>
    </div>
    </section>
  </div>

  <!-- ================= KOLOM SAMPING ================= -->
  <div>
    <div class="card ringkas">
      <div class="ringkas-h">
        <div class="hitung"><?= e($hitungMundur) ?></div>
        <div class="lab" style="margin-top:8px"><?= $tglHariH ? e($tglHariH) : 'Tanggal belum ada' ?></div>
      </div>
      <table class="tbl" style="font-size:13px">
        <tr><td class="lab">Tahap</td><td class="right"><b><?= e(stageLabel($stage)) ?></b></td></tr>
        <?php if ($c['tipe_klien']): ?><tr><td class="lab">Tipe</td><td class="right"><?= $c['tipe_klien'] === 'budgeting' ? 'On budgeting' : 'On tematis' ?></td></tr><?php endif; ?>
        <?php if ($c['venue'] || $c['city']): ?><tr><td class="lab">Lokasi</td><td class="right"><?= e($c['venue'] ?: $c['city']) ?></td></tr><?php endif; ?>
        <?php if (!empty($wi['tamu_resepsi'])): ?>
          <tr><td class="lab">Tamu resepsi</td><td class="right"><?= number_format((int) $wi['tamu_resepsi'], 0, ',', '.') ?></td></tr>
        <?php elseif ($c['guest_estimate']): ?>
          <tr><td class="lab">Tamu</td><td class="right"><?= number_format((int) $c['guest_estimate'], 0, ',', '.') ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($wi['tamu_akad'])): ?>
          <tr><td class="lab">Tamu akad</td><td class="right"><?= number_format((int) $wi['tamu_akad'], 0, ',', '.') ?></td></tr>
        <?php endif; ?>
        <?php if ($c['budget_estimate'] && !$c['deal_value']): ?><tr><td class="lab"><?= $c['tipe_klien'] === 'budgeting' ? 'Plafon' : 'Budget' ?></td><td class="right"><?= rupiah((float) $c['budget_estimate'], true) ?></td></tr><?php endif; ?>
        <?php if ($c['deal_value']): ?><tr><td class="lab">Nilai deal</td><td class="right"><?= rupiah((float) $c['deal_value']) ?></td></tr><?php endif; ?>
        <?php if ($pays): ?><tr><td class="lab">Terbayar</td><td class="right"><?= $money['persen'] ?>%</td></tr><?php endif; ?>
        <tr><td class="lab">Sumber</td><td class="right"><?= e(ucfirst($c['source'])) ?><?= $c['dari_form'] ? ' · formulir' : '' ?></td></tr>
      </table>

      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px">
        <?php if ($c['phone']): ?>
          <a class="btn sm" target="_blank" rel="noopener" href="https://wa.me/<?= e(preg_replace('/\D/', '', $c['phone'])) ?>">WhatsApp ↗</a>
        <?php endif; ?>
        <?php if ($c['email']): ?><a class="btn sm ghost" href="mailto:<?= e($c['email']) ?>">Email</a><?php endif; ?>
        <?php if ($c['instagram']): ?><a class="btn sm ghost" target="_blank" rel="noopener" href="https://instagram.com/<?= e($c['instagram']) ?>">IG ↗</a><?php endif; ?>
        <?php if ($c['event_id']): ?><a class="btn sm ghost" href="event.php?edit=<?= (int) $c['event_id'] ?>">Event</a><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
/* Tab halaman klien.
   Tanpa JavaScript semua bagian tetap tampil berurutan. Dengan JavaScript,
   hanya satu tab terlihat; tautan #jangkar lama (#susunan, #uang, #chat …)
   — dipakai redirect sesudah menyimpan — membuka tab yang memuatnya. */
(() => {
  // Di layar sempit jalur tahap digeser sampai tahap sekarang terlihat.
  const kini = document.querySelector('.stepper li.now');
  if (kini) { const ol = kini.parentElement; ol.scrollLeft = kini.offsetLeft - ol.clientWidth / 2 + kini.clientWidth / 2; }

  const nav = document.getElementById('tabKlien');
  if (!nav) return;
  const panes = [...document.querySelectorAll('.tabpane')];
  document.body.classList.add('tabs-on');

  const buka = (nama, gulir) => {
    let ada = false;
    panes.forEach(p => { const on = p.dataset.pane === nama; p.hidden = !on; ada ||= on; });
    if (!ada) return false;
    nav.querySelectorAll('a').forEach(a => a.classList.toggle('on', a.dataset.tab === nama));
    try { sessionStorage.setItem('klienTab<?= (int) $c['id'] ?>', nama); } catch (_) {}
    if (gulir) nav.scrollIntoView({ block: 'start', behavior: 'smooth' });
    return true;
  };

  const dariHash = (gulir) => {
    const h = decodeURIComponent(location.hash.slice(1));
    if (!h) return false;
    if (h.startsWith('tab-')) return buka(h.slice(4), gulir);
    const el = document.getElementById(h);
    const pane = el?.closest('.tabpane');
    if (!pane) return false;
    buka(pane.dataset.pane, false);
    requestAnimationFrame(() => el.scrollIntoView({ block: 'start', behavior: gulir ? 'smooth' : 'auto' }));
    return true;
  };

  nav.addEventListener('click', e => {
    const a = e.target.closest('a[data-tab]');
    if (!a) return;
    e.preventDefault();
    history.replaceState(null, '', '#tab-' + a.dataset.tab);
    buka(a.dataset.tab, false);
  });
  // Tautan di dalam halaman (#kebutuhan, #datalengkap, …) ikut membuka tabnya.
  document.addEventListener('click', e => {
    const a = e.target.closest('a[href^="#"]');
    if (!a || a.closest('#tabKlien')) return;
    const id = a.getAttribute('href').slice(1);
    const el = document.getElementById(id);
    const pane = el?.closest('.tabpane');
    if (!pane) return;
    e.preventDefault();
    history.replaceState(null, '', '#' + id);
    buka(pane.dataset.pane, false);
    el.scrollIntoView({ block: 'start', behavior: 'smooth' });
  });
  window.addEventListener('hashchange', () => dariHash(true));

  if (!dariHash(false)) {
    let awal = 'ikhtisar';
    try { awal = sessionStorage.getItem('klienTab<?= (int) $c['id'] ?>') || awal; } catch (_) {}
    buka(awal, false) || buka('ikhtisar', false);
  }
})();
</script>

<?php
/* ============================================================
   FORMULIR KLIEN BARU
   ============================================================ */
elseif ($new):
  pageHead('Klien baru',
           'Biodata awal — cukup untuk mengirim price list. Biodata lengkap, keluarga, acara, dan dekor '
         . 'dilengkapi admin office setelah DP 30% masuk.',
           '<a class="btn ghost" href="klien.php">← Kembali</a>');
  $paketBaru = paketDaftar(false);
?>
<form method="post">
  <?= csrfField() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="0">

  <div class="card">
    <h2>Mempelai &amp; kontak</h2>
    <div class="row c2">
      <div class="field"><label for="n">Mempelai pria</label>
        <input type="text" id="n" name="name" required autofocus placeholder="Zakki"></div>
      <div class="field"><label for="pn">Mempelai wanita</label>
        <input type="text" id="pn" name="partner_name" placeholder="Winda"></div>
    </div>
    <div class="row c3">
      <div class="field"><label for="ph">WhatsApp</label>
        <input type="text" id="ph" name="phone" inputmode="tel" placeholder="0812…"></div>
      <div class="field"><label for="em">Email</label>
        <input type="email" id="em" name="email"></div>
      <div class="field"><label for="ig">Instagram</label>
        <input type="text" id="ig" name="instagram" placeholder="zakkiwinda"></div>
    </div>
    <div class="row c2">
      <div class="field"><label for="src">Tahu dari mana</label>
        <select id="src" name="source">
          <?php foreach (['instagram'=>'Instagram','whatsapp'=>'WhatsApp','web'=>'Situs web','referral'=>'Rekomendasi teman','vendor'=>'Vendor lain','walkin'=>'Datang langsung','lainnya'=>'Lainnya'] as $k => $v): ?>
            <option value="<?= $k ?>"><?= $v ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label for="sd">Rincinya</label>
        <input type="text" id="sd" name="sumber_detail" placeholder="Akun @siapa, teman siapa, vendor mana"></div>
    </div>
  </div>

  <div class="card">
    <h2>Rencana acara</h2>
    <div class="row c3">
      <div class="field"><label for="wd">Tanggal rencana</label><input type="date" id="wd" name="wedding_date"></div>
      <div class="field"><label for="ge">Perkiraan tamu</label><input type="number" id="ge" name="guest_estimate" step="50" min="0" placeholder="400"></div>
      <div class="field"><label for="be">Perkiraan budget</label><input type="text" id="be" name="budget_estimate" data-rp inputmode="numeric" placeholder="Rp"></div>
    </div>
    <div class="row c2">
      <div class="field"><label for="ct">Kota</label><input type="text" id="ct" name="city" value="Yogyakarta"></div>
      <div class="field"><label for="vn">Venue, kalau sudah ada</label><input type="text" id="vn" name="venue"></div>
    </div>
    <div class="field"><label for="nt">Catatan</label>
      <textarea id="nt" name="notes" rows="3" placeholder="Konsep yang diinginkan, adat, kendala, permintaan khusus."></textarea></div>
  </div>

  <div class="card">
    <h2>Paket diminati</h2>
    <p class="sub">Paket ini yang dipakai saat menyiapkan price list. Isi dan harganya diatur di
      <a href="paket.php" style="color:var(--ember)">Paket &amp; price list</a>.</p>
    <div class="paket-pilih">
      <?php foreach ($paketBaru as $t): $hl = paketHargaLabel($t, true); ?>
        <label class="pilih-tipe"><input type="radio" name="paket_minat" value="<?= (int) $t['id'] ?>">
          <span><b><?= e($t['nama']) ?></b><small><?= $hl ? e($hl) : 'Harga belum diisi' ?><?= !empty($t['tamu']) ? ' · ±' . number_format((int) $t['tamu'], 0, ',', '.') . ' tamu' : '' ?><?= empty($t['tampil_web']) ? ' · internal' : '' ?></small></span></label>
      <?php endforeach; ?>
      <label class="pilih-tipe"><input type="radio" name="paket_minat" value="0" checked>
        <span><b>Belum memilih</b><small>Pilih nanti saat menyusun price list.</small></span></label>
    </div>
  </div>

  <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
    <button class="btn solid" type="submit" name="lanjut" value="pl">Simpan &amp; siapkan price list →</button>
    <button class="btn ghost" type="submit">Simpan saja</button>
  </div>
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
  // Admin office hanya melihat kolom tahapnya sendiri — kolom prospek yang
  // selalu kosong untuknya cuma memanjangkan papan.
  $tahapTampil = $peranSaya === 'admin_office' ? ['deal','persiapan','harih'] : PIPE_ACTIVE;

  $nilai = (float) (one("SELECT COALESCE(SUM(deal_value),0) v FROM clients WHERE stage IN ('deal','persiapan','harih')")['v'] ?? 0);
  $prospek = (float) (one("SELECT COALESCE(SUM(budget_estimate),0) v FROM clients WHERE stage IN ('baru','pricelist','dp')")['v'] ?? 0);

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
    <?php foreach ($tahapTampil as $st): ?><option value="<?= $st ?>" <?= $fTahap === $st ? 'selected' : '' ?>><?= e(stageLabel($st)) ?></option><?php endforeach; ?></select>
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
  <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
    <h2 style="margin:0">Papan pipeline</h2>
    <span class="hint" style="margin:0">Garis kiri oranye = tenggat hari ini, merah = sudah lewat. Geser ke samping untuk tahap berikutnya.</span>
  </div>
  <div class="board pipa" style="margin-top:16px">
    <?php foreach ($tahapTampil as $st):
      $rows = $byStage[$st] ?? []; ?>
      <div class="col<?= $fTahap === $st ? ' hot' : '' ?>">
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

<?php $kalBuka = isset($_GET['b']) || isset($_GET['y']); ?>
<details class="card kalender" style="margin-top:18px" <?= $kalBuka ? 'open' : '' ?>>
  <summary>
    <h2 style="display:inline">Kalender ketersediaan</h2>
    <span class="lab" style="margin-left:10px"><?= e($kal['nama']) ?> · <?= $kal['jumlahAcara'] ?> hari-H</span>
    <span class="hint" style="display:block;margin:4px 0 0">Untuk menjawab "tanggal sekian masih kosong?" — klik untuk membuka.</span>
  </summary>
  <div style="display:flex;justify-content:flex-end;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-top:12px">
    <div style="display:flex;gap:7px;align-items:center;flex-wrap:wrap">
      <a class="btn sm ghost" href="?y=<?= $prevY ?>&b=<?= $prevB ?>">←</a>
      <span class="mono" style="min-width:132px;text-align:center;font-size:13px"><?= e($kal['nama']) ?></span>
      <a class="btn sm ghost" href="?y=<?= $nextY ?>&b=<?= $nextB ?>">→</a>
      <?php if ($kalBulan != date('n') || $kalTahun != date('Y')): ?>
        <a class="btn sm ghost" href="?y=<?= date('Y') ?>&b=<?= date('n') ?>">Bulan ini</a>
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
</details>


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
