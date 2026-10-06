/* Callalily Admin — perilaku bersama seluruh panel. */
(() => {
  'use strict';

  /* ---------- Laci navigasi di layar sempit ---------- */
  const body = document.body;
  const toggle = document.getElementById('railToggle');
  const scrim  = document.getElementById('railScrim');
  const setOpen = (on) => {
    body.classList.toggle('rail-open', on);
    toggle?.setAttribute('aria-expanded', on ? 'true' : 'false');
  };
  toggle?.addEventListener('click', () => setOpen(!body.classList.contains('rail-open')));
  scrim?.addEventListener('click', () => setOpen(false));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') setOpen(false); });
  // Menutup laci begitu layar melebar lagi, supaya scroll body tidak terkunci.
  matchMedia('(min-width:901px)').addEventListener('change', e => { if (e.matches) setOpen(false); });

  /* ---------- Tombol mata pada isian kata sandi ----------
     Disuntik lewat JS, bukan ditulis di tiap form, supaya semua halaman
     (login, setup, reset, ganti sandi) dapat perilaku yang sama. */
  const EYE_OPEN  = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M1.5 12S5.2 5 12 5s10.5 7 10.5 7-3.7 7-10.5 7S1.5 12 1.5 12Z"/><circle cx="12" cy="12" r="3.2"/></svg>';
  const EYE_SHUT  = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M9.9 5.2A9.9 9.9 0 0 1 12 5c6.8 0 10.5 7 10.5 7a18 18 0 0 1-3.2 4.1M6.2 6.8A18 18 0 0 0 1.5 12S5.2 19 12 19a9.8 9.8 0 0 0 4.3-1M10 10a2.8 2.8 0 0 0 4 4"/><path d="m2.5 2.5 19 19"/></svg>';

  document.querySelectorAll('input[type=password]').forEach(inp => {
    if (inp.dataset.noEye !== undefined) return;
    if (inp.closest('.pw-wrap')) return;   // sudah pernah diproses

    const wrap = document.createElement('div');
    wrap.className = 'pw-wrap';
    inp.parentNode.insertBefore(wrap, inp);
    wrap.appendChild(inp);

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pw-eye';
    btn.innerHTML = EYE_OPEN;
    btn.setAttribute('aria-label', 'Tampilkan kata sandi');
    btn.title = 'Tampilkan kata sandi';
    wrap.appendChild(btn);

    btn.addEventListener('click', () => {
      const show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      btn.innerHTML = show ? EYE_SHUT : EYE_OPEN;
      const lbl = show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi';
      btn.setAttribute('aria-label', lbl);
      btn.title = lbl;
      // Kembalikan kursor ke posisi semula supaya tidak lompat ke awal.
      const pos = inp.value.length;
      inp.focus();
      try { inp.setSelectionRange(pos, pos); } catch (_) {}
    });
  });

  /* ---------- Indikator kekuatan kata sandi ---------- */
  document.querySelectorAll('input[data-strength]').forEach(inp => {
    const bar = document.createElement('div');
    bar.className = 'pw-meter';
    bar.innerHTML = '<span></span><em></em>';
    const host = inp.closest('.pw-wrap') || inp;
    host.after(bar);
    const fill = bar.querySelector('span'), txt = bar.querySelector('em');

    inp.addEventListener('input', () => {
      const v = inp.value;
      let score = 0;
      if (v.length >= 12) score++;
      if (v.length >= 16) score++;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
      if (/[0-9]/.test(v)) score++;
      if (/[^A-Za-z0-9]/.test(v)) score++;
      if (v.length < 12) score = Math.min(score, 1);

      const label = ['Terlalu pendek', 'Lemah', 'Cukup', 'Baik', 'Kuat', 'Sangat kuat'][score] || '';
      const color = score <= 1 ? 'var(--rose)' : score <= 3 ? 'var(--ember)' : 'var(--sage)';
      fill.style.width = (v ? (score / 5) * 100 : 0) + '%';
      fill.style.background = color;
      txt.textContent = v ? label : '';
      txt.style.color = color;
    });
  });

  /* ---------- Konfirmasi hapus yang tidak mengandalkan confirm() polos ---------- */
  document.querySelectorAll('form[data-confirm]').forEach(f => {
    f.addEventListener('submit', e => {
      // {jumlah} diganti nominal yang sedang terisi, supaya yang dikonfirmasi
      // adalah angka yang benar-benar akan dicatat.
      let pesan = f.dataset.confirm;
      if (pesan.includes('{jumlah}')) {
        const j = f.querySelector('[name=jumlah], input[data-rp]');
        const n = (j?.value || '').replace(/\D/g, '');
        pesan = pesan.replace('{jumlah}', n ? new Intl.NumberFormat('id-ID').format(n) : '0');
      }
      if (!confirm(pesan)) e.preventDefault();
    });
  });

  /* ---------- Kirim sekali: kunci tombol setelah ditekan ----------
     Uang tercatat dua kali atau tagihan terkirim dua kali adalah kesalahan
     paling mahal di panel ini — terutama dari ponsel dengan sinyal lemah.
     Dipasang SETELAH konfirmasi: kalau konfirmasi dibatalkan, tombol tetap hidup. */
  document.querySelectorAll('form[data-sekali]').forEach(f => {
    f.addEventListener('submit', e => {
      if (e.defaultPrevented) return;
      if (f.dataset.terkirim) { e.preventDefault(); return; }
      f.dataset.terkirim = '1';
      const b = f.querySelector('button[type=submit], button:not([type])');
      if (b) setTimeout(() => { b.disabled = true; b.dataset.asal = b.textContent; b.textContent = 'Menyimpan…'; }, 0);
    });
  });
  // Kembali lewat tombol Back (bfcache): hidupkan lagi tombolnya.
  window.addEventListener('pageshow', e => {
    if (!e.persisted) return;
    document.querySelectorAll('form[data-terkirim]').forEach(f => {
      delete f.dataset.terkirim;
      const b = f.querySelector('button[disabled]');
      if (b) { b.disabled = false; if (b.dataset.asal) b.textContent = b.dataset.asal; }
    });
  });

  /* ---------- Isian mata uang: tampilkan pemisah ribuan ---------- */
  document.querySelectorAll('input[data-rupiah]').forEach(inp => {
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = inp.name;
    inp.removeAttribute('name');
    inp.after(hidden);

    const fmt = n => n ? new Intl.NumberFormat('id-ID').format(n) : '';
    const sync = () => {
      const raw = inp.value.replace(/\D/g, '');
      hidden.value = raw;
      inp.value = fmt(raw);
    };
    inp.value = fmt(inp.value.replace(/\D/g, ''));
    hidden.value = inp.value.replace(/\D/g, '');
    inp.addEventListener('input', sync);
    inp.addEventListener('blur', sync);
  });
})();

/* ===========================================================
   Pengalih tema
   Pilihan disimpan di cookie, bukan localStorage, supaya PHP
   bisa membacanya dan memasang data-theme sejak render pertama.
   Tanpa itu, halaman sempat berkedip putih di mode gelap.
   =========================================================== */
(() => {
  const btn = document.getElementById('themeBtn');
  if (!btn) return;
  btn.addEventListener('click', () => {
    const root = document.documentElement;
    const sekarang = root.getAttribute('data-theme')
      || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    const baru = sekarang === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', baru);
    const setahun = 60 * 60 * 24 * 365;
    document.cookie = `calla_theme=${baru};path=/;max-age=${setahun};samesite=Lax`
      + (location.protocol === 'https:' ? ';secure' : '');
  });
})();

/* ===========================================================
   Isian nominal rupiah
   Menampilkan 40.000.000 sambil diketik, tapi yang dikirim ke
   server tetap angka mentah — pemisah ribuan dibuang saat submit
   supaya PHP tidak perlu menebak format.
   =========================================================== */
(() => {
  const fmt = n => n.replace(/\D/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.');

  const terbilang = angka => {
    const n = parseInt(angka.replace(/\D/g, ''), 10);
    if (!n) return '';
    if (n >= 1e9)  return (n / 1e9).toFixed(n % 1e9 ? 2 : 0).replace('.', ',').replace(/,?0+$/, '') + ' miliar';
    if (n >= 1e6)  return (n / 1e6).toFixed(n % 1e6 ? 1 : 0).replace('.', ',').replace(/,0$/, '') + ' juta';
    if (n >= 1e3)  return Math.round(n / 1e3) + ' ribu';
    return '';
  };

  document.querySelectorAll('input[data-rp]').forEach(inp => {
    // bungkus supaya prefiks "Rp" bisa ditempel lewat CSS
    if (!inp.parentElement.classList.contains('rp')) {
      const w = document.createElement('label');
      w.className = 'rp';
      inp.parentNode.insertBefore(w, inp);
      w.appendChild(inp);
      const hint = document.createElement('span');
      hint.className = 'rp-hint';
      w.appendChild(hint);
    }
    const hint = inp.parentElement.querySelector('.rp-hint');
    inp.setAttribute('inputmode', 'numeric');
    inp.setAttribute('autocomplete', 'off');
    if (!inp.placeholder) inp.placeholder = '0';

    const gambar = () => {
      const posisi = inp.value.length - inp.selectionStart;
      inp.value = fmt(inp.value);
      const p = Math.max(0, inp.value.length - posisi);
      try { inp.setSelectionRange(p, p); } catch (_) {}
      if (hint) hint.textContent = terbilang(inp.value);
    };

    if (inp.value) { inp.value = fmt(inp.value); if (hint) hint.textContent = terbilang(inp.value); }
    inp.addEventListener('input', gambar);

    // Bersihkan titik sebelum dikirim
    const form = inp.form;
    if (form && !form.dataset.rpBound) {
      form.dataset.rpBound = '1';
      form.addEventListener('submit', () => {
        form.querySelectorAll('input[data-rp]').forEach(i => { i.value = i.value.replace(/\D/g, ''); });
      });
    }
  });
})();


/* ===========================================================
   Ringkasan
   =========================================================== */
(() => {
  /* ---------- Saringan antrean: tautan biasa, disaring di tempat ---------- */
  document.querySelectorAll('nav.saring').forEach(nav => {
    const kartu = nav.closest('section') || document;
    const kosong = kartu.querySelector('.saring-kosong');
    const terap = (k) => {
      let terlihat = 0;
      const perTier = {};
      kartu.querySelectorAll('li.q').forEach(li => {
        const cocok = !k || (li.dataset.saring || '').split(' ').includes(k);
        li.hidden = !cocok;
        if (cocok) { terlihat++; const t = (li.className.match(/\bt(\d)\b/) || [])[1]; perTier[t] = (perTier[t] || 0) + 1; }
      });
      kartu.querySelectorAll('li.tier-lab').forEach(l => {
        const n = perTier[l.dataset.tier] || 0;
        l.hidden = n === 0;
        const b = l.querySelector('b'); if (b) b.textContent = n;
      });
      nav.querySelectorAll('a').forEach(a => a.setAttribute('aria-pressed', (a.dataset.saring || '') === k ? 'true' : 'false'));
      if (kosong) kosong.hidden = terlihat > 0;
      // Baris yang tersembunyi di balik "Tampilkan n lainnya" ikut terbuka saat menyaring.
      kartu.querySelectorAll('details.antrean-lebih').forEach(d => { if (k) d.open = true; });
    };
    nav.addEventListener('click', e => {
      const a = e.target.closest('a[data-saring]');
      if (!a) return;
      e.preventDefault();
      const k = a.dataset.saring || '';
      terap(k);
      try {
        const u = new URL(location.href);
        if (k) u.searchParams.set('saring', k); else u.searchParams.delete('saring');
        u.hash = '';
        history.replaceState(null, '', u.toString());
      } catch (_) {}
    });
    const awal = (nav.querySelector('a[aria-pressed=true]') || {}).dataset?.saring || '';
    if (awal) terap(awal);
  });

  /* ---------- Jam relatif pertemuan: diperbarui tiap menit ---------- */
  const relatif = (t) => {
    const a = Date.parse(t.dataset.mulai), b = Date.parse(t.dataset.selesai), n = Date.now();
    if (isNaN(a)) return;
    let s = '';
    if (n >= b) s = 'selesai';
    else if (n >= a) s = 'sedang berlangsung';
    else {
      const m = Math.ceil((a - n) / 60000);
      if (m <= 90) s = 'mulai ' + m + ' menit lagi';
      else if (new Date(a).toDateString() === new Date(n).toDateString()) s = 'mulai ' + Math.round(m / 60) + ' jam lagi';
    }
    t.textContent = s;
  };
  const jam = document.querySelectorAll('time[data-mulai]');
  if (jam.length) setInterval(() => jam.forEach(relatif), 60000);

  /* ---------- Grafik: ketuk/arahkan → angka ditulis ke keterangan (aria-live) ---------- */
  document.querySelectorAll('figure.grafik').forEach(fig => {
    const ket = fig.querySelector('[data-keterangan]');
    if (!ket) return;
    const asal = ket.textContent;
    const tulis = (el) => { ket.textContent = el ? el.getAttribute('data-tip') : asal; };
    fig.addEventListener('mouseover', e => { const el = e.target.closest('[data-tip]'); if (el) tulis(el); });
    fig.addEventListener('mouseleave', () => tulis(null));
    fig.addEventListener('focusin', e => { const el = e.target.closest('[data-tip]'); if (el) tulis(el); });
    fig.addEventListener('click', e => { const el = e.target.closest('[data-tip]'); if (el && !el.closest('a')) tulis(el); });
  });

  /* ---------- Bagian lipat yang terbuka di layar lebar ---------- */
  if (matchMedia('(min-width:1000px)').matches) document.querySelectorAll('details[data-buka-lebar]').forEach(d => { d.open = true; });

  /* ---------- Popover (DP masuk, Lainnya): satu terbuka, klik di luar menutup ---------- */
  const pop = 'details.pop-dp, details.lainnya';
  document.addEventListener('toggle', e => {
    const d = e.target;
    if (!(d instanceof HTMLDetailsElement) || !d.matches(pop) || !d.open) return;
    document.querySelectorAll(pop).forEach(x => { if (x !== d) x.open = false; });
  }, true);
  document.addEventListener('click', e => {
    if (e.target.closest(pop)) return;
    document.querySelectorAll(pop + '[open]').forEach(x => { x.open = false; });
  });
})();
