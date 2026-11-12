<?php
require __DIR__ . '/inc/bootstrap.php';
$services = lines('services');
$clients  = lines('clients');
$timeline = lines('timeline');
page_head('Studio', setting('about_intro'), 'about');
?>
<section class="pagehead">
  <h1 class="h-mega">Studio</h1>
</section>

<section class="about">
  <p class="about__statement"><?= e(setting('about_intro')) ?></p>

  <div class="about__founder">
    <?php if ($p = setting('founder_photo')): ?>
      <img class="about__photo" src="<?= e(media_url($p)) ?>" alt="<?= e(setting('founder_name')) ?>">
    <?php else: ?>
      <div class="about__photo about__photo--empty" aria-hidden="true"><?= e(mb_substr(setting('founder_name', 'M'), 0, 1)) ?></div>
    <?php endif; ?>
    <div>
      <p class="kicker"><?= e(setting('founder_title', 'Founder & Creative Director')) ?></p>
      <h2 class="h-xl"><?= e(setting('founder_name')) ?></h2>
      <div class="prose"><?= prose(setting('founder_bio')) ?></div>
    </div>
  </div>
</section>

<?php if ($services): ?>
<section class="section">
  <h2 class="h-xl">What we do</h2>
  <ol class="services">
    <?php foreach ($services as $i => $s): [$t, $d] = array_pad(array_map('trim', explode('|', $s, 2)), 2, ''); ?>
      <li><span class="services__n"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span><span class="services__t"><?= e($t) ?></span><span class="services__d"><?= e($d) ?></span></li>
    <?php endforeach; ?>
  </ol>
</section>
<?php endif; ?>

<?php if ($timeline): ?>
<section class="section">
  <h2 class="h-xl">Along the way</h2>
  <ul class="timeline">
    <?php foreach ($timeline as $t): [$y, $d] = array_pad(array_map('trim', explode('|', $t, 2)), 2, ''); ?>
      <li><span class="timeline__y"><?= e($y) ?></span><span><?= e($d) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($clients): ?>
<section class="section clients">
  <h2 class="h-xl">Made with &amp; for</h2>
  <ul class="clients__list"><?php foreach ($clients as $c): ?><li><?= e($c) ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
<?php page_foot(); ?>
