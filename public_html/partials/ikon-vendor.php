<?php
/**
 * Ikon kategori vendor.
 *
 * Digambar sebagai SVG sebaris, bukan ditarik dari pustaka ikon.
 * Alasannya sederhana: dua belas ikon garis sederhana lebih kecil daripada
 * satu permintaan HTTP ke CDN mana pun, dan ikut mewarisi warna teks
 * induknya tanpa perlu satu baris CSS tambahan.
 *
 * currentColor dipakai di semua stroke — jadi kartu yang berubah warna
 * saat disorot membawa ikonnya ikut berubah, tanpa aturan hover terpisah.
 */

function ikonVendor(string $kunci, int $ukuran = 26): string
{
    $d = match ($kunci) {
        // Bangunan beratap pelana — venue, tenda, gedung
        'venue'    => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v10h13V10"/><path d="M10 20v-5h4v5"/>',

        // Lengkung janur dengan kelopak
        'dekorasi' => '<path d="M12 21V9"/><path d="M12 9c0-3 2.5-5 5-5 0 3-2 5-5 5Z"/>'
                    . '<path d="M12 9c0-3-2.5-5-5-5 0 3 2 5 5 5Z"/><path d="M7 21h10"/>',

        // Tudung saji
        'catering' => '<path d="M4 17h16"/><path d="M5.5 17a6.5 6.5 0 0 1 13 0"/>'
                    . '<path d="M12 10.5V8"/><circle cx="12" cy="7" r="1.2"/><path d="M3 20h18"/>',

        // Kamera
        'foto'     => '<path d="M4 8h3l1.5-2h7L17 8h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>',

        // Kamera video
        'video'    => '<rect x="3" y="7" width="12" height="10" rx="2"/><path d="m15 11 6-3.5v9L15 13"/>',

        // Kuas rias
        'makeup'   => '<path d="M14 4.5 19.5 10 10 19.5H4.5V14z"/><path d="m12.5 6.5 5 5"/>'
                    . '<path d="M4.5 19.5 9 15"/>',

        // Gantungan busana
        'busana'   => '<path d="M12 7a2 2 0 1 1 2-2"/><path d="M12 7v2.5L4 15v3h16v-3l-8-5.5"/>',

        // Not balok
        'musik'    => '<path d="M9 18V6l10-2v12"/><circle cx="6.5" cy="18" r="2.5"/>'
                    . '<circle cx="16.5" cy="16" r="2.5"/>',

        // Mikrofon genggam
        'mc'       => '<rect x="9" y="3" width="6" height="11" rx="3"/>'
                    . '<path d="M5.5 12a6.5 6.5 0 0 0 13 0"/><path d="M12 18.5V21"/>',

        // Pengeras suara
        'sound'    => '<path d="m4 9.5 5-.5V15l-5-.5z"/><path d="m9 9 6-4v14l-6-4"/>'
                    . '<path d="M18 9.5a4 4 0 0 1 0 5"/>',

        // Amplop terbuka
        'undangan' => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="m3 8 9 6 9-6"/>',

        // Kue bertingkat
        'kue'      => '<path d="M12 3v3"/><path d="M6 11h12v3H6z"/><path d="M4.5 14h15v6h-15z"/>'
                    . '<path d="M9 11V9h6v2"/>',

        default    => '<circle cx="12" cy="12" r="8"/>',
    };

    return '<svg viewBox="0 0 24 24" width="' . $ukuran . '" height="' . $ukuran . '"'
         . ' fill="none" stroke="currentColor" stroke-width="1.4"'
         . ' stroke-linecap="round" stroke-linejoin="round"'
         . ' aria-hidden="true" focusable="false">' . $d . '</svg>';
}
