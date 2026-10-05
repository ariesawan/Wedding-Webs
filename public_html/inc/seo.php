<?php
// ldService() memakai layananBawaan() yang tinggal di pipeline.php.
// Sebelumnya bergantung pada kebetulan: halaman yang memuat pipeline lebih
// dulu berjalan, yang tidak memuatnya mati dengan fatal. Berkas yang memakai
// sesuatu harus memuatnya sendiri — bukan berharap pemanggilnya sudah.
require_once __DIR__ . '/pipeline.php';
/**
 * SEO: meta tag, Open Graph, Twitter Card, canonical, dan JSON-LD terstruktur.
 *
 * Kenapa JSON-LD dan bukan microdata? Google secara eksplisit merekomendasikan
 * JSON-LD, dan ia terpisah dari markup — jadi mengubah tampilan tidak merusak
 * data terstruktur.
 */

function seoHead(array $o): string
{
    $title   = $o['title']   ?? setting('site_name', 'Callalily Party');
    $desc    = $o['desc']    ?? setting('site_description', '');
    $canon   = $o['canonical'] ?? url(ltrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/'));
    $image   = $o['image']   ?? setting('default_og_image', url('foto/dream-come-true.jpg'));
    $type    = $o['type']    ?? 'website';
    $robots  = $o['robots']  ?? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
    $locale  = 'id_ID';

    $h  = '<title>' . e($title) . "</title>\n";
    $h .= '<meta name="description" content="' . e($desc) . '">' . "\n";
    $h .= '<link rel="canonical" href="' . e($canon) . '">' . "\n";
    $h .= '<meta name="robots" content="' . e($robots) . '">' . "\n";

    $h .= '<meta property="og:site_name" content="' . e(setting('site_name', 'Callalily Party')) . '">' . "\n";
    $h .= '<meta property="og:locale" content="' . $locale . '">' . "\n";
    $h .= '<meta property="og:type" content="' . e($type) . '">' . "\n";
    $h .= '<meta property="og:title" content="' . e($title) . '">' . "\n";
    $h .= '<meta property="og:description" content="' . e($desc) . '">' . "\n";
    $h .= '<meta property="og:url" content="' . e($canon) . '">' . "\n";
    $h .= '<meta property="og:image" content="' . e($image) . '">' . "\n";
    $h .= '<meta property="og:image:alt" content="' . e($o['image_alt'] ?? $title) . '">' . "\n";

    if ($type === 'article') {
        if (!empty($o['published'])) $h .= '<meta property="article:published_time" content="' . e(date('c', strtotime($o['published']))) . '">' . "\n";
        if (!empty($o['modified']))  $h .= '<meta property="article:modified_time" content="' . e(date('c', strtotime($o['modified']))) . '">' . "\n";
        if (!empty($o['section']))   $h .= '<meta property="article:section" content="' . e($o['section']) . '">' . "\n";
        foreach (($o['tags'] ?? []) as $t) $h .= '<meta property="article:tag" content="' . e($t) . '">' . "\n";
    }

    $h .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
    $h .= '<meta name="twitter:title" content="' . e($title) . '">' . "\n";
    $h .= '<meta name="twitter:description" content="' . e($desc) . '">' . "\n";
    $h .= '<meta name="twitter:image" content="' . e($image) . '">' . "\n";

    if (!empty($o['prev'])) $h .= '<link rel="prev" href="' . e($o['prev']) . '">' . "\n";
    if (!empty($o['next'])) $h .= '<link rel="next" href="' . e($o['next']) . '">' . "\n";

    $h .= '<link rel="alternate" type="application/rss+xml" title="Artikel Callalily Party" href="' . url('feed.php') . '">' . "\n";

    foreach (($o['jsonld'] ?? []) as $block) {
        $h .= jsonLd($block);
    }
    return $h;
}

function jsonLd(array $data): string
{
    return '<script type="application/ld+json">'
        . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . "</script>\n";
}

/** Entitas bisnis — dipakai di semua halaman sebagai dasar Knowledge Graph. */
function ldOrganization(): array
{
    return [
        '@context' => 'https://schema.org',
        '@type'    => 'LocalBusiness',
        '@id'      => url('#organization'),
        'name'     => setting('site_name', 'Callalily Party'),
        'alternateName' => 'Callalily Party Wedding Organizer',
        'description'   => setting('site_description', ''),
        'url'      => url(),
        'image'    => setting('default_og_image', url('foto/dream-come-true.jpg')),
        'telephone'=> setting('wa_number') ? '+' . preg_replace('/\D/', '', setting('wa_number')) : null,
        'email'    => setting('contact_email', 'callalily.party@yahoo.com'),
        'priceRange' => setting('price_range', 'Rp'),
        'address'  => [
            '@type'           => 'PostalAddress',
            'streetAddress'   => setting('address_street', 'Jl. Magelang KM 9, Denggung'),
            'addressLocality' => setting('address_city', 'Sleman'),
            'addressRegion'   => setting('address_region', 'Daerah Istimewa Yogyakarta'),
            'postalCode'      => setting('address_zip', '55511'),
            'addressCountry'  => 'ID',
        ],
        'areaServed' => array_map(
            fn($c) => ['@type' => 'City', 'name' => trim($c)],
            explode(',', setting('area_served', 'Yogyakarta, Sleman, Bantul, Magelang, Solo, Semarang'))
        ),
        'openingHoursSpecification' => [[
            '@type'     => 'OpeningHoursSpecification',
            'dayOfWeek' => ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'],
            'opens'     => '09:00', 'closes' => '17:00',
        ]],
        'sameAs' => array_values(array_filter([
            setting('ig_url'), setting('fb_url'), setting('tiktok_url'), setting('youtube_url'),
        ])),
    ];
}

/**
 * Skema layanan.
 *
 * LocalBusiness memberi tahu Google SIAPA dan DI MANA. Service memberi tahu
 * APA yang dijual. Tanpa yang kedua, mesin pencari harus menebaknya dari
 * teks halaman — dan beranda ini teksnya puitis, jadi tebakannya lemah.
 */
function ldService(): array
{
    $kota = array_map('trim', explode(',', setting('area_served', 'Yogyakarta, Sleman, Bantul, Magelang, Solo, Semarang')));

    return [
        '@context'    => 'https://schema.org',
        '@type'       => 'Service',
        '@id'         => url('#service'),
        'serviceType' => 'Wedding Planning',
        'name'        => 'Jasa Wedding Organizer ' . ($kota[0] ?? 'Yogyakarta'),
        'provider'    => ['@id' => url('#organization')],
        'areaServed'  => array_map(fn($c) => ['@type' => 'City', 'name' => $c], $kota),
        'description' => setting('service_description',
            'Perencanaan dan pelaksanaan pernikahan lengkap: koordinasi vendor, '
          . 'dekorasi, katering, dokumentasi, rias, hiburan, dan manajemen hari-H.'),
        'hasOfferCatalog' => [
            '@type' => 'OfferCatalog',
            'name'  => 'Paket Wedding Organizer',
            'itemListElement' => array_values(array_map(
                fn($l) => [
                    '@type' => 'Offer',
                    'itemOffered' => ['@type' => 'Service', 'name' => $l[0], 'description' => $l[1]],
                ],
                layananBawaan()
            )),
        ],
    ];
}

function ldBreadcrumb(array $items): array
{
    $list = [];
    foreach (array_values($items) as $i => [$name, $u]) {
        $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $u];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
}

function ldArticle(array $p): array
{
    $img = $p['cover'] ? url('uploads/blog/' . $p['cover'] . '.jpg') : setting('default_og_image');
    return [
        '@context'         => 'https://schema.org',
        '@type'            => 'BlogPosting',
        'headline'         => mb_substr($p['title'], 0, 110),
        'description'      => $p['meta_description'] ?: $p['excerpt'],
        'image'            => [$img],
        'datePublished'    => date('c', strtotime($p['published_at'] ?: $p['created_at'])),
        'dateModified'     => date('c', strtotime($p['updated_at'] ?: $p['created_at'])),
        'author'           => ['@type' => 'Organization', 'name' => setting('site_name', 'Callalily Party'), 'url' => url()],
        'publisher'        => ['@id' => url('#organization')],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => url('blog/' . $p['slug'])],
        'inLanguage'       => 'id-ID',
        'wordCount'        => str_word_count(strip_tags($p['content'] ?? '')),
    ];
}

function ldEvent(array $ev): array
{
    $past = strtotime($ev['event_date']) < time();
    return [
        '@context'   => 'https://schema.org',
        '@type'      => 'Event',
        'name'       => $ev['title'],
        'startDate'  => date('c', strtotime($ev['event_date'])),
        'endDate'    => date('c', strtotime($ev['end_date'] ?: $ev['event_date'])),
        'eventStatus'=> 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'description'=> excerptFrom($ev['description'] ?? '', 300),
        'image'      => $ev['cover'] ? [url('uploads/event/' . $ev['cover'] . '.jpg')] : [],
        'location'   => [
            '@type'   => 'Place',
            'name'    => $ev['venue'] ?: 'Yogyakarta',
            'address' => ['@type' => 'PostalAddress', 'addressLocality' => $ev['city'] ?: 'Yogyakarta', 'addressCountry' => 'ID'],
        ],
        'organizer'  => ['@id' => url('#organization')],

        // performer dan offers diminta Google untuk tipe Event. Keduanya
        // sempat kosong dan Search Console mengirim peringatan.
        //
        // Untuk pernikahan, "performer" memang bukan artis panggung — tapi
        // penyelenggaralah yang menjalankan acaranya, dan itu yang paling
        // jujur mengisi peran ini. Mengarang nama band yang tidak ada justru
        // membuat datanya salah.
        'performer'  => [
            '@type' => 'Organization',
            'name'  => setting('site_name', 'Callalily Party'),
            '@id'   => url('#organization'),
        ],

        // Acara pernikahan bukan acara berbayar untuk umum — tidak ada tiket
        // yang dijual. offers diisi apa adanya: harga nol, tidak tersedia
        // untuk umum. Ini yang dimaksud Google dengan Event tertutup, dan
        // lebih benar daripada mengosongkannya sama sekali.
        'offers'     => [
            '@type'         => 'Offer',
            'url'           => url('event/' . $ev['slug']),
            'price'         => '0',
            'priceCurrency' => 'IDR',
            'availability'  => 'https://schema.org/InStock',
            'validFrom'     => date('c', strtotime('-30 day', strtotime($ev['event_date']))),
        ],

        'url'        => url('event/' . $ev['slug']),
        'isAccessibleForFree' => true,
    ];
}

function ldFaq(array $pairs): array
{
    return [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => array_map(fn($p) => [
            '@type'          => 'Question',
            'name'           => $p[0],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $p[1]],
        ], $pairs),
    ];
}

/**
 * Skor SEO on-page sederhana untuk editor artikel.
 * Bukan pengganti Search Console — tujuannya mencegah artikel terbit tanpa
 * meta description, tanpa H2, atau dengan judul kepanjangan.
 */
function seoAudit(array $p): array
{
    $checks = [];
    $title = trim($p['title'] ?? '');
    $meta  = trim($p['meta_description'] ?? '');
    $kw    = mb_strtolower(trim($p['focus_keyword'] ?? ''));
    $html  = $p['content'] ?? '';
    $text  = mb_strtolower(strip_tags($html));
    $words = str_word_count(strip_tags($html));

    $len = mb_strlen($p['meta_title'] ?: $title);
    $checks[] = ['Judul SEO 45–60 karakter', $len >= 45 && $len <= 60, "$len karakter"];

    $mlen = mb_strlen($meta);
    $checks[] = ['Meta description 120–158 karakter', $mlen >= 120 && $mlen <= 158, "$mlen karakter"];

    $checks[] = ['Panjang artikel minimal 600 kata', $words >= 600, "$words kata"];
    $checks[] = ['Ada minimal satu subjudul H2', (bool) preg_match('/<h2[\s>]/i', $html), ''];
    $checks[] = ['Gambar sampul terpasang', !empty($p['cover']), ''];

    $imgs = preg_match_all('/<img\b[^>]*>/i', $html, $m);
    $noAlt = 0;
    foreach ($m[0] ?? [] as $tag) if (!preg_match('/\balt\s*=\s*("[^"]+"|\'[^\']+\')/i', $tag)) $noAlt++;
    $checks[] = ['Semua gambar punya atribut alt', $noAlt === 0, $imgs ? "$noAlt dari $imgs tanpa alt" : 'tidak ada gambar'];

    $checks[] = ['Ada tautan internal ke halaman sendiri', str_contains($html, BASE_URL) || preg_match('#href="/#', $html), ''];

    if ($kw !== '') {
        $checks[] = ['Kata kunci ada di judul',        str_contains(mb_strtolower($title), $kw), ''];
        $checks[] = ['Kata kunci ada di slug',         str_contains($p['slug'] ?? '', slugify($kw)), ''];
        $checks[] = ['Kata kunci ada di meta description', str_contains(mb_strtolower($meta), $kw), ''];
        $checks[] = ['Kata kunci muncul di 100 kata pertama', str_contains(mb_substr($text, 0, 700), $kw), ''];
        $dens = $words > 0 ? round(substr_count($text, $kw) / max($words, 1) * 100, 2) : 0;
        $checks[] = ['Kepadatan kata kunci 0,5–2,5%', $dens >= 0.5 && $dens <= 2.5, "$dens%"];
    }

    $pass  = count(array_filter($checks, fn($c) => $c[1]));
    $score = count($checks) ? (int) round($pass / count($checks) * 100) : 0;
    return ['score' => $score, 'checks' => $checks];
}
