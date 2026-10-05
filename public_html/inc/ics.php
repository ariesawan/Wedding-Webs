<?php
/**
 * Berkas .ics (RFC 5545) — dilampirkan ke email undangan.
 * Gunanya: klien yang tidak pakai Gmail (Outlook, Apple Calendar, Yahoo)
 * tetap bisa menambahkan jadwal ke kalendernya dengan satu klik.
 */
function buildIcs(array $m): string
{
    $fold = function (string $line): string {
        // RFC 5545: baris maksimal 75 oktet, lanjutan diawali spasi.
        $out = ''; $len = 0;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $b = strlen($ch);
            if ($len + $b > 73) { $out .= "\r\n "; $len = 1; }
            $out .= $ch; $len += $b;
        }
        return $out;
    };
    $esc = fn(string $s): string => str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n"], $s);

    $uid   = 'meet-' . $m['id'] . '@' . parse_url(BASE_URL, PHP_URL_HOST);
    $start = gmdate('Ymd\THis\Z', strtotime($m['start_at']));
    $end   = gmdate('Ymd\THis\Z', strtotime($m['end_at']));
    $now   = gmdate('Ymd\THis\Z');

    $loc = match ($m['mode']) {
        'onsite' => $m['location_text'] ?: setting('address_street', ''),
        'zoom'   => $m['zoom_join_url'] ?: 'Zoom',
        'meet'   => $m['meet_url'] ?: 'Google Meet',
        default  => 'Panggilan telepon',
    };

    $desc = trim($m['notes'] ?? '');
    if (!empty($m['zoom_join_url'])) $desc .= "\\nZoom: " . $m['zoom_join_url'];
    if (!empty($m['meet_url']))      $desc .= "\\nGoogle Meet: " . $m['meet_url'];

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Callalily Party//CMS//ID',
        'CALSCALE:GREGORIAN',
        'METHOD:REQUEST',
        'BEGIN:VEVENT',
        'UID:' . $uid,
        'DTSTAMP:' . $now,
        'DTSTART:' . $start,
        'DTEND:' . $end,
        $fold('SUMMARY:' . $esc($m['title'])),
        $fold('DESCRIPTION:' . $esc($desc)),
        $fold('LOCATION:' . $esc($loc)),
        $fold('ORGANIZER;CN=' . $esc(setting('site_name', 'Callalily Party')) . ':mailto:' . setting('contact_email')),
        $fold('ATTENDEE;CN=' . $esc($m['client_name']) . ';RSVP=TRUE:mailto:' . $m['client_email']),
        'STATUS:' . ($m['status'] === 'canceled' ? 'CANCELLED' : 'CONFIRMED'),
        'SEQUENCE:0',
        'BEGIN:VALARM',
        'TRIGGER:-PT60M',
        'ACTION:DISPLAY',
        'DESCRIPTION:Pengingat pertemuan',
        'END:VALARM',
        'END:VEVENT',
        'END:VCALENDAR',
    ];
    return implode("\r\n", $lines) . "\r\n";
}
