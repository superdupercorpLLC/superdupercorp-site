<?php
require __DIR__ . '/inc/bootstrap.php';
$slug = (string)($_GET['slug'] ?? '');
$w = find_work($slug);
if (isset($_COOKIE['sdc'])) start_session();
$isAdmin = !empty($_SESSION['sdc_admin']);
if (!$w || (($w['status'] ?? '') !== 'published' && !$isAdmin)) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}
$pub = published_works();
$idx = array_search($w['slug'], array_column($pub, 'slug'), true);
$next = $pub ? $pub[($idx === false ? 0 : $idx + 1) % count($pub)] : null;
if ($next && $next['slug'] === $w['slug']) $next = null;
$color = cat_color($w['category'] ?? '');
if (!empty($w['cover']['large'])) $GLOBALS['__og_image'] = media_url($w['cover']['large']);
page_head($w['title'], $w['summary'] ?? '', 'work');
?>
<article class="project" style="--cc:<?= e($color) ?>">
  <?php if (($w['status'] ?? '') !== 'published'): ?><p class="draft-flag">Draft. Only you can see this.</p><?php endif; ?>
  <header class="project__head">
    <a class="project__cat" href="<?= url('work?c=' . e($w['category'] ?? '')) ?>"><?= e(cat_label($w['category'] ?? '')) ?></a>
    <h1 class="h-mega project__title"><?= e($w['title']) ?></h1>
    <?php if (!empty($w['summary'])): ?><p class="project__summary"><?= e($w['summary']) ?></p><?php endif; ?>
  </header>

  <dl class="project__facts">
    <?php foreach (['Client' => $w['client'] ?? '', 'Agency' => $w['agency'] ?? '', 'Year' => (string)($w['year'] ?? ''), 'Role' => $w['role'] ?? '', 'Discipline' => implode(', ', $w['disciplines'] ?? [])] as $k => $v): if ($v === '') continue; ?>
      <div><dt><?= $k ?></dt><dd><?= e($v) ?></dd></div>
    <?php endforeach; ?>
    <?php if (!empty($w['link'])): ?><div><dt>Live</dt><dd><a href="<?= e($w['link']) ?>" target="_blank" rel="noopener">Visit ↗</a></dd></div><?php endif; ?>
  </dl>

  <div class="project__hero">
    <?php if (!empty($w['cover']['large'])): ?>
      <img src="<?= e(media_url($w['cover']['large'])) ?>" alt="<?= e($w['title']) ?>">
    <?php elseif (!($w['video_url'] ?? '')): ?>
      <?= poster($w, 'poster--hero') ?>
    <?php endif; ?>
  </div>

  <?php if (($emb = video_embed($w['video_url'] ?? ''))): ?>
    <div class="project__video"><iframe src="<?= e($emb) ?>" title="<?= e($w['title']) ?> video" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen loading="lazy"></iframe></div>
  <?php endif; ?>

  <?php if (!empty($w['body'])): ?><div class="project__body prose"><?= prose($w['body']) ?></div><?php endif; ?>

  <?php if (!empty($w['gallery'])): ?>
  <div class="gallery" data-gallery>
    <?php foreach ($w['gallery'] as $g): ?>
      <figure class="gallery__item<?= !empty($g['wide']) ? ' is-wide' : '' ?>">
        <?php if (($g['type'] ?? 'image') === 'video'): ?>
          <video src="<?= e(media_url($g['src'])) ?>" controls playsinline preload="metadata"></video>
        <?php else: ?>
          <a href="<?= e(media_url($g['src'])) ?>" data-lightbox><img src="<?= e(media_url($g['thumb'] ?? $g['src'])) ?>" alt="<?= e($g['caption'] ?? $w['title']) ?>" loading="lazy" decoding="async"></a>
        <?php endif; ?>
        <?php if (!empty($g['caption'])): ?><figcaption><?= e($g['caption']) ?></figcaption><?php endif; ?>
      </figure>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($w['credits'])): ?>
    <div class="project__credits"><h2 class="kicker">Credits</h2><?= prose($w['credits']) ?></div>
  <?php endif; ?>
</article>

<?php if ($next): ?>
<a class="next" href="<?= work_url($next) ?>" style="--cc:<?= e(cat_color($next['category'] ?? '')) ?>">
  <span class="kicker">Next</span>
  <span class="next__t"><?= e($next['title']) ?> →</span>
</a>
<?php endif; ?>
<?php page_foot(); ?>
