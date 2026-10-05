<?php
require_once __DIR__ . '/google.php';
require_once __DIR__ . '/zoom.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/ics.php';

/**
 * Orkestrasi satu jadwal meeting.
 *
 * Urutannya penting:
 *   1) Zoom dibuat DULU (kalau mode zoom), supaya tautannya bisa ikut masuk
 *      ke deskripsi & lokasi event Google Calendar.
 *   2) Baru event Google Calendar dibuat dengan sendUpdates=all -> Google yang
 *      mengirim undangan resmi ke email klien (RSVP jalan, muncul di kalender klien).
 *   3) Email tambahan dari sisi kita hanya pelengkap bermerek + lampiran .ics
 *      untuk klien yang tidak memakai Google.
 *
 * Kegagalan integrasi TIDAK membatalkan penyimpanan data. Jadwal tetap tersimpan
 * di database dan errornya dicatat di kolom sync_error supaya owner bisa
 * menekan "Sinkronkan ulang" tanpa mengetik ulang semuanya.
 */
function meetingSync(int $id, bool $sendEmail = true): array
{
    $m = one("SELECT * FROM meetings WHERE id = ?", [$id]);
    if (!$m) throw new RuntimeException('Jadwal tidak ditemukan.');

    $errors = [];

    // ---------- 1. Zoom ----------
    if ($m['mode'] === 'zoom' && zoomConfigured()) {
        try {
            if ($m['zoom_meeting_id']) {
                zoomUpdateMeeting($m['zoom_meeting_id'], $m);
            } else {
                $z = zoomCreateMeeting($m);
                q("UPDATE meetings SET zoom_meeting_id = ?, zoom_join_url = ?, zoom_start_url = ?, zoom_passcode = ? WHERE id = ?",
                  [(string) $z['id'], $z['join_url'] ?? null, $z['start_url'] ?? null, $z['password'] ?? null, $id]);
                $m = one("SELECT * FROM meetings WHERE id = ?", [$id]);
            }
        } catch (Throwable $e) {
            $errors[] = 'Zoom: ' . $e->getMessage();
        }
    } elseif ($m['mode'] === 'zoom' && !zoomConfigured()) {
        $errors[] = 'Zoom: kredensial belum diisi di Admin > Integrasi.';
    }

    // ---------- 2. Google Calendar ----------
    if (googleConnected()) {
        try {
            $ev = $m['gcal_event_id'] ? gcalUpdateEvent($m) : gcalCreateEvent($m);
            $meet = $ev['hangoutLink']
                 ?? ($ev['conferenceData']['entryPoints'][0]['uri'] ?? null);
            q("UPDATE meetings SET gcal_event_id = ?, gcal_html_link = ?, meet_url = ? WHERE id = ?",
              [$ev['id'] ?? null, $ev['htmlLink'] ?? null, $meet, $id]);
            $m = one("SELECT * FROM meetings WHERE id = ?", [$id]);
        } catch (Throwable $e) {
            $errors[] = 'Google Calendar: ' . $e->getMessage();
        }
    } else {
        $errors[] = 'Google Calendar belum terhubung — undangan kalender tidak terkirim.';
    }

    // ---------- 3. Email bermerek + .ics ----------
    if ($sendEmail) {
        try {
            $ics = buildIcs($m);
            $ok  = sendMail($m['client_email'], $m['client_name'],
                    'Jadwal pertemuan: ' . $m['title'],
                    meetingInviteHtml($m),
                    [['name' => 'jadwal-callalily.ics', 'mime' => 'text/calendar; charset=UTF-8; method=REQUEST', 'data' => $ics]]);
            if (!$ok) $errors[] = 'Email undangan gagal dikirim (cek pengaturan SMTP).';
        } catch (Throwable $e) {
            $errors[] = 'Email: ' . $e->getMessage();
        }
    }

    q("UPDATE meetings SET sync_error = ? WHERE id = ?", [$errors ? mb_substr(implode(' | ', $errors), 0, 500) : null, $id]);
    return $errors;
}

/** Batalkan jadwal: hapus di Zoom & Google (Google otomatis kirim email pembatalan). */
function meetingCancel(int $id): array
{
    $m = one("SELECT * FROM meetings WHERE id = ?", [$id]);
    if (!$m) throw new RuntimeException('Jadwal tidak ditemukan.');
    $errors = [];

    if ($m['zoom_meeting_id']) {
        try { zoomDeleteMeeting($m['zoom_meeting_id']); }
        catch (Throwable $e) { $errors[] = 'Zoom: ' . $e->getMessage(); }
    }
    if ($m['gcal_event_id'] && googleConnected()) {
        try { gcalDeleteEvent($m['gcal_event_id']); }
        catch (Throwable $e) { $errors[] = 'Google Calendar: ' . $e->getMessage(); }
    }
    q("UPDATE meetings SET status = 'canceled', sync_error = ? WHERE id = ?",
      [$errors ? implode(' | ', $errors) : null, $id]);
    return $errors;
}

/** Deteksi bentrok jadwal sebelum menyimpan. */
function meetingConflicts(string $start, string $end, ?int $ignoreId = null): array
{
    $sql = "SELECT id, title, client_name, start_at, end_at FROM meetings
            WHERE status = 'scheduled' AND start_at < ? AND end_at > ?";
    $par = [$end, $start];
    if ($ignoreId) { $sql .= " AND id <> ?"; $par[] = $ignoreId; }
    return all($sql . " ORDER BY start_at", $par);
}
