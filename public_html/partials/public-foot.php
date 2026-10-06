</div>
</main>
<footer>
  <span><?= e(strtoupper(setting('site_name', 'Callalily Party'))) ?> WEDDING ORGANIZER · <?= e(setting('address_city', 'Yogyakarta')) ?></span>
  <span>
    <a href="<?= url() ?>">Beranda</a> ·
    <a href="<?= url('pricelist') ?>">Price list</a> ·
    <a href="<?= url('galeri') ?>">Galeri</a> ·
    <a href="<?= url('blog') ?>">Jurnal</a> ·
    <a href="<?= url('feed.php') ?>">RSS</a>
  </span>
  <span>© <?= date('Y') ?> · penawaran disusun per pasangan</span>
</footer>
<script>
(() => {
  const p = document.getElementById('prog');
  const upd = () => {
    const h = document.documentElement;
    const max = h.scrollHeight - h.clientHeight;
    p.style.width = (max > 0 ? (h.scrollTop / max) * 100 : 0) + '%';
  };
  addEventListener('scroll', upd, { passive: true }); upd();
})();
</script>
<?php if ($ga = setting('ga_measurement_id')): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= e($ga) ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','<?= e($ga) ?>');</script>
<?php endif; ?>
</body>
</html>
