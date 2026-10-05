<?php
/**
 * Blok "Top 5 prioritas vendor".
 *
 * Dipakai dua tempat dengan tampilan berbeda tapi nama field yang sama:
 * panel admin dan formulir publik. Nama field yang sama itu yang penting —
 * satu logika penyimpanan melayani keduanya, jadi tidak ada jalan untuk
 * salah satunya diperbarui dan yang lain tertinggal.
 *
 * Yang dipilih adalah KATEGORI, bukan nama vendor. Lima kategori teratas
 * mendapat alokasi anggaran utama; sisanya menyesuaikan yang tersisa.
 */

/**
 * @param array $kategori  daftar [id, nama] kategori induk
 * @param array $terpilih  [urutan => category_id] yang sudah tersimpan
 * @param bool  $publik    true = kelas CSS formulir publik
 */
function blokTop5(array $kategori, array $terpilih = [], bool $publik = false): string
{
    if (!$kategori) return '';

    $out = '<div class="top5' . ($publik ? ' top5-p' : '') . '">';

    for ($i = 1; $i <= 5; $i++) {
        $nilai = (int) ($terpilih[$i] ?? 0);

        // Peringkat 1 dan 2 diberi penanda visual berbeda. Dalam praktik,
        // dua teratas yang benar-benar menentukan rasa acara — sisanya
        // bergerak jauh lebih longgar saat anggaran ditekan.
        $utama = $i <= 2;

        $out .= '<div class="top5-r">'
              . '<span class="top5-n' . ($utama ? ' utama' : '') . '">' . $i . '</span>'
              . '<select name="top_vendor[' . $i . ']" class="top5-s" data-rank="' . $i . '">'
              . '<option value="">— belum dipilih —</option>';

        foreach ($kategori as $k) {
            $out .= '<option value="' . (int) $k['id'] . '"'
                  . ($nilai === (int) $k['id'] ? ' selected' : '') . '>'
                  . e($k['nama']) . '</option>';
        }

        $out .= '</select></div>';
    }

    return $out . '</div>';
}

/** Skrip pendamping: sinkron dengan centang kebutuhan + cegah pilihan ganda. */
function blokTop5Skrip(): string
{
    return <<<'JS'
<script>
(() => {
  const sel = [...document.querySelectorAll('.top5-s')];
  if (!sel.length) return;
  const cek = [...document.querySelectorAll('.vn-cek, .vn-cek2, .vn-cekf')];

  function segarkan() {
    // Kategori yang sudah dipakai di peringkat lain disembunyikan dari
    // peringkat berikutnya. Melarangnya lewat pesan galat saat menyimpan
    // jauh lebih menjengkelkan daripada tidak menawarkannya sejak awal.
    const dipakai = new Set(sel.map(s => s.value).filter(Boolean));

    // Kalau ada centang kebutuhan di halaman ini, pilihan dibatasi ke
    // kategori yang memang dibutuhkan. Kalau tidak ada (mis. halaman tanpa
    // blok kebutuhan), semua kategori tetap boleh dipilih.
    const dibutuhkan = cek.length
      ? new Set(cek.filter(c => c.checked).map(c => c.value))
      : null;

    for (const s of sel) {
      for (const o of s.options) {
        if (!o.value) continue;
        const bentrok = dipakai.has(o.value) && s.value !== o.value;
        const diluar  = dibutuhkan && !dibutuhkan.has(o.value);
        o.hidden = o.disabled = bentrok || diluar;
      }
      // Pilihan yang jadi tidak sah karena centangnya dilepas ikut dikosongkan,
      // supaya tidak tersimpan diam-diam sebagai prioritas yang tak dibutuhkan.
      if (s.value && dibutuhkan && !dibutuhkan.has(s.value)) s.value = '';
    }
  }

  sel.forEach(s => s.addEventListener('change', segarkan));
  cek.forEach(c => c.addEventListener('change', segarkan));
  segarkan();
})();
</script>
JS;
}
