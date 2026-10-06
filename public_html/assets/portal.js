/* Dashboard pengantin — sengaja kecil dan tanpa handler inline (CSP). */
(() => {
  // Salin nomor rekening / nominal.
  document.querySelectorAll('[data-salin]').forEach(b => b.addEventListener('click', async () => {
    const teks = b.getAttribute('data-salin') || '';
    try { await navigator.clipboard.writeText(teks); }
    catch (e) {
      const t = document.createElement('textarea'); t.value = teks; document.body.appendChild(t);
      t.select(); try { document.execCommand('copy'); } catch (x) {} t.remove();
    }
    const asal = b.textContent; b.textContent = 'Tersalin ✓';
    setTimeout(() => { b.textContent = asal; }, 1600);
  }));

  // Formulir: cegah kirim ganda & ingatkan bila meninggalkan isian yang belum disimpan.
  let kotor = false;
  document.querySelectorAll('form[data-jaga]').forEach(f => {
    f.addEventListener('input', () => { kotor = true; });
    f.addEventListener('submit', () => {
      kotor = false;
      const b = f.querySelector('button[type=submit]');
      if (b) setTimeout(() => { b.disabled = true; b.textContent = 'Menyimpan…'; }, 0);
    });
  });
  window.addEventListener('beforeunload', e => { if (kotor) { e.preventDefault(); e.returnValue = ''; } });
})();
