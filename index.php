<?php
require __DIR__ . '/inc/bootstrap.php';
$works    = published_works();
$featured = array_values(array_filter($works, fn($w) => !empty($w['featured'])));
if (!$featured) $featured = array_slice($works, 0, 6);
$featured = array_slice($featured, 0, 7);
$counts = array_fill_keys(array_keys(CATEGORIES), 0);
foreach ($works as $w) if (isset($counts[$w['category'] ?? ''])) $counts[$w['category']]++;
$years = array_filter(array_map(fn($w) => (int)($w['year'] ?? 0), $works));
$disciplines = lines('disciplines');
$clients = lines('clients');

page_head('', '', 'home');
?>
<section class="hero">
  <h1 class="hero__word" aria-label="<?= e(setting('studio_name', 'SUPER DUPER CORP')) ?>">
    <span>SUPER</span><span>DUPER</span><span>CORP<i class="hero__dot"></i></span>
  </h1>
  <div class="hero__side">
    <p class="hero__lede"><?= e(setting('tagline')) ?></p>
    <?php if ($years): ?>
      <p class="hero__stat"><b><?= count($works) ?></b> projects<br><b><?= min($years) ?>–<?= max($years) ?></b> and counting</p>
    <?php endif; ?>
  </div>
</section>

<?php if ($disciplines): ?>
<div class="ticker" aria-hidden="true"><div class="ticker__track">
  <?php for ($i = 0; $i < 2; $i++): foreach ($disciplines as $d): ?><span><?= e($d) ?></span><b>✷</b><?php endforeach; endfor; ?>
</div></div>
<?php endif; ?>

<?php if ($featured): ?>
<section class="section">
  <div class="section__head">
    <h2 class="h-xl">Selected work</h2>
    <a class="btn" href="<?= url('work') ?>">Everything →</a>
  </div>
  <div class="feature-grid">
    <?php foreach ($featured as $i => $w): ?>
      <a class="card card--f<?= $i ?>" href="<?= work_url($w) ?>" style="--cc:<?= e(cat_color($w['category'] ?? '')) ?>">
        <div class="card__media"><?= cover($w, $i === 0 ? 'large' : 'thumb') ?></div>
        <div class="card__meta">
          <span class="card__title"><?= e($w['title']) ?></span>
          <span class="card__sub"><?= e(implode(' · ', array_filter([$w['client'] ?? '', $w['year'] ?? '']))) ?></span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="section cats">
  <h2 class="h-xl">Four kinds of work</h2>
  <div class="cats__grid">
    <?php foreach (CATEGORIES as $k => $c): ?>
      <a class="cat" href="<?= url('work?c=' . $k) ?>" style="--cc:<?= e($c['color']) ?>">
        <span class="cat__n"><?= str_pad((string)$counts[$k], 2, '0', STR_PAD_LEFT) ?></span>
        <span class="cat__l"><?= e($c['label']) ?></span>
        <span class="cat__d"><?= e(setting('cat_' . $k)) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<?php if ($clients): ?>
<section class="section clients">
  <h2 class="h-xl">Made with &amp; for</h2>
  <ul class="clients__list">
    <?php foreach ($clients as $c): ?><li><?= e($c) ?></li><?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php page_foot(); ?>
