<?php
/**
 * Pengirim email: mode mail() (default cPanel) atau SMTP autentikasi.
 *
 * Untuk hosting sendiri, SMTP lewat mail.domain-anda.com hampir selalu lebih
 * baik daripada mail(): header Return-Path benar, ada autentikasi, sehingga
 * SPF/DKIM cocok dan email tidak masuk spam.
 */

function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody, array $attachments = []): bool
{
    $fromMail = setting('mail_from_email', setting('contact_email', 'no-reply@' . parse_url(BASE_URL, PHP_URL_HOST)));
    $fromName = setting('mail_from_name', 'Callalily Party');
    $boundary = '=_calla_' . bin2hex(random_bytes(12));

    $headers = [
        'MIME-Version: 1.0',
        'From: ' . mimeName($fromName) . ' <' . $fromMail . '>',
        'Reply-To: ' . $fromMail,
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . parse_url(BASE_URL, PHP_URL_HOST) . '>',
        'X-Mailer: CallalilyCMS',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
    ];

    $body  = "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";

    foreach ($attachments as $att) {
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: {$att['mime']}; name=\"{$att['name']}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$att['name']}\"\r\n\r\n";
        $body .= chunk_split(base64_encode($att['data'])) . "\r\n";
    }
    $body .= "--$boundary--\r\n";

    $subjectEnc = mimeName($subject);
    $to = $toName ? mimeName($toName) . ' <' . $toEmail . '>' : $toEmail;

    if (setting('mail_method', 'mail') === 'smtp') {
        return smtpSend($fromMail, $toEmail, $to, $subjectEnc, implode("\r\n", $headers), $body);
    }
    return @mail($to, $subjectEnc, $body, implode("\r\n", $headers), '-f' . $fromMail);
}

/** Encode header non-ASCII (RFC 2047) supaya judul berbahasa Indonesia tidak rusak. */
function mimeName(string $s): string
{
    return preg_match('/[\x80-\xFF]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/** Klien SMTP minimal: EHLO -> STARTTLS/SSL -> AUTH LOGIN -> MAIL FROM -> RCPT -> DATA */
function smtpSend(string $from, string $rcpt, string $toHeader, string $subject, string $headers, string $body): bool
{
    $host = setting('smtp_host', 'localhost');
    $port = (int) setting('smtp_port', '587');
    $user = setting('smtp_user');
    $pass = setting('smtp_pass');
    $sec  = setting('smtp_secure', 'tls');   // tls | ssl | none

    $target = ($sec === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $fp = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT,
          stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]));
    if (!$fp) { error_log("SMTP connect gagal: $errstr ($errno)"); return false; }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp): string {
        $out = '';
        while ($line = fgets($fp, 640)) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $out;
    };
    $cmd = function (string $c, string $expect) use ($fp, $read): bool {
        fwrite($fp, $c . "\r\n");
        $r = $read();
        if (!str_starts_with($r, $expect)) { error_log("SMTP: '$c' -> $r"); return false; }
        return true;
    };

    $read();
    $ehlo = 'EHLO ' . (parse_url(BASE_URL, PHP_URL_HOST) ?: 'localhost');
    if (!$cmd($ehlo, '250')) { fclose($fp); return false; }

    if ($sec === 'tls') {
        if (!$cmd('STARTTLS', '220')) { fclose($fp); return false; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; }
        if (!$cmd($ehlo, '250')) { fclose($fp); return false; }
    }
    if ($user) {
        if (!$cmd('AUTH LOGIN', '334')) { fclose($fp); return false; }
        if (!$cmd(base64_encode($user), '334')) { fclose($fp); return false; }
        if (!$cmd(base64_encode($pass), '235')) { fclose($fp); return false; }
    }
    if (!$cmd("MAIL FROM:<$from>", '250')) { fclose($fp); return false; }
    if (!$cmd("RCPT TO:<$rcpt>", '250'))   { fclose($fp); return false; }
    if (!$cmd('DATA', '354'))              { fclose($fp); return false; }

    $data = "To: $toHeader\r\nSubject: $subject\r\n$headers\r\n\r\n$body";
    // Dot-stuffing: baris yang diawali "." harus digandakan (RFC 5321).
    $data = preg_replace('/^\./m', '..', $data);
    fwrite($fp, $data . "\r\n.\r\n");
    $ok = str_starts_with($read(), '250');

    $cmd('QUIT', '221');
    fclose($fp);
    return $ok;
}

/** Template email undangan meeting. */
function meetingInviteHtml(array $m): string
{
    $ink = '#17181A'; $ivory = '#F1EAD9'; $ember = '#E9A85C';
    $join = $m['mode'] === 'zoom' ? $m['zoom_join_url'] : ($m['mode'] === 'meet' ? $m['meet_url'] : null);
    $mode = match ($m['mode']) {
        'onsite' => 'Tatap muka — ' . e($m['location_text'] ?: setting('address_street', '')),
        'zoom'   => 'Zoom Meeting',
        'meet'   => 'Google Meet',
        default  => 'Panggilan telepon',
    };

    $btn = $join ? '<p style="margin:28px 0"><a href="' . e($join) . '" style="background:' . $ember . ';color:' . $ink . ';padding:14px 28px;border-radius:999px;text-decoration:none;font-weight:600;display:inline-block">Gabung pertemuan</a></p>' : '';
    $pass = !empty($m['zoom_passcode']) ? '<tr><td style="padding:6px 0;color:#8b8f9e">Passcode</td><td style="padding:6px 0"><b>' . e($m['zoom_passcode']) . '</b></td></tr>' : '';

    return '<!DOCTYPE html><html lang="id"><body style="margin:0;background:' . $ink . ';font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:' . $ivory . '">
<div style="max-width:560px;margin:0 auto;padding:40px 28px">
  <p style="font-family:Georgia,serif;font-style:italic;font-size:24px;margin:0 0 4px">' . e(setting('site_name', 'Callalily Party')) . '</p>
  <p style="font-size:10px;letter-spacing:.18em;text-transform:uppercase;color:' . $ember . ';margin:0 0 32px">Wedding Organizer · Yogyakarta</p>

  <h1 style="font-family:Georgia,serif;font-weight:400;font-size:26px;line-height:1.3;margin:0 0 16px">' . e($m['title']) . '</h1>
  <p style="color:#b9b3a4;line-height:1.7;margin:0 0 24px">Halo ' . e($m['client_name']) . ', pertemuan Anda dengan tim kami sudah dijadwalkan. Detailnya di bawah ini — undangan kalender juga sudah dikirim ke email ini.</p>

  <table style="width:100%;border-top:1px solid rgba(241,234,217,.15);border-bottom:1px solid rgba(241,234,217,.15);font-size:14px">
    <tr><td style="padding:12px 0;color:#8b8f9e;width:110px">Hari</td><td style="padding:12px 0">' . hariID($m['start_at']) . '</td></tr>
    <tr><td style="padding:6px 0;color:#8b8f9e">Tanggal</td><td style="padding:6px 0"><b>' . tanggalID($m['start_at']) . '</b></td></tr>
    <tr><td style="padding:6px 0;color:#8b8f9e">Waktu</td><td style="padding:6px 0"><b>' . date('H.i', strtotime($m['start_at'])) . ' – ' . date('H.i', strtotime($m['end_at'])) . ' WIB</b></td></tr>
    <tr><td style="padding:6px 0;color:#8b8f9e">Format</td><td style="padding:12px 0 6px">' . $mode . '</td></tr>
    ' . $pass . '
  </table>
  ' . $btn . '
  ' . ($m['notes'] ? '<p style="color:#b9b3a4;line-height:1.7;font-size:14px;border-left:2px solid ' . $ember . ';padding-left:16px">' . nl2br(e($m['notes'])) . '</p>' : '') . '

  <p style="color:#8b8f9e;font-size:12.5px;line-height:1.7;margin-top:36px">Perlu mengubah jadwal? Balas email ini atau hubungi kami di WhatsApp ' . e(setting('wa_number', '')) . '.<br>Berkas <b>.ics</b> terlampir bisa langsung ditambahkan ke Outlook, Apple Calendar, atau kalender lain.</p>
  <p style="color:#5e6272;font-size:11px;letter-spacing:.1em;text-transform:uppercase;margin-top:28px">' . e(setting('address_street', '')) . ' · ' . e(setting('address_city', '')) . '</p>
</div></body></html>';
}
