</main>
<footer class="foot">
  <div class="foot__cta">
    <p class="kicker">Got something worth making?</p>
    <a class="foot__big" href="<?= url('contact') ?>">Let's talk&nbsp;→</a>
  </div>
  <div class="foot__cols">
    <div>
      <strong><?= e(setting('legal_name', 'SUPER DUPER CORP LLC')) ?></strong><br>
      <?= e(setting('location')) ?>
    </div>
    <div>
      <?php if ($em = setting('email')): ?><a href="mailto:<?= e($em) ?>"><?= e($em) ?></a><br><?php endif; ?>
      <?php foreach (lines('socials') as $s): [$label, $href] = array_pad(array_map('trim', explode('|', $s, 2)), 2, ''); if (!$href) continue; ?>
        <a href="<?= e($href) ?>" target="_blank" rel="noopener"><?= e($label) ?></a><br>
      <?php endforeach; ?>
    </div>
    <div class="foot__legal">© <?= date('Y') ?> <?= e(setting('legal_name', 'SUPER DUPER CORP LLC')) ?>. All work © its respective owners.</div>
  </div>
</footer>
<script src="<?= url('assets/js/site.js') ?>?v=1" defer></script>
</body>
</html>
