<?php
require __DIR__ . '/inc/bootstrap.php';
$works = published_works();
$cat = $_GET['c'] ?? 'all';
if ($cat !== 'all' && !isset(CATEGORIES[$cat])) $cat = 'all';
$tags = [];
foreach ($works as $w) foreach ($w['disciplines'] ?? [] as $t) $tags[$t] = ($tags[$t] ?? 0) + 1;
arsort($tags);

page_head('Work', 'The full archive of ' . setting('studio_name', 'SUPER DUPER CORP') . ' — personal, commercial, corporate and agency work.', 'work');
?>
<section class="pagehead">
  <h1 class="h-mega">Work<sup><?= count($works) ?></sup></h1>
  <p class="pagehead__lede"><?= e(setting('work_intro')) ?></p>
</section>

<div class="filters" data-filters>
  <div class="filters__row" role="group" aria-label="Filter by kind">
    <button class="chip" data-cat="all" aria-pressed="<?= $cat === 'all' ? 'true' : 'false' ?>">All</button>
    <?php foreach (CATEGORIES as $k => $c): ?>
      <button class="chip" data-cat="<?= e($k) ?>" style="--cc:<?= e($c['color']) ?>" aria-pressed="<?= $cat === $k ? 'true' : 'false' ?>"><?= e($c['label']) ?></button>
    <?php endforeach; ?>
  </div>
  <div class="filters__row">
    <label class="sr-only" for="f-tag">Discipline</label>
    <select id="f-tag" class="select" data-tag>
      <option value="">Any discipline</option>
      <?php foreach ($tags as $t => $n): ?><option value="<?= e(strtolower($t)) ?>"><?= e($t) ?> (<?= $n ?>)</option><?php endforeach; ?>
    </select>
    <label class="sr-only" for="f-q">Search</label>
    <input id="f-q" class="select" type="search" placeholder="Search client, title…" data-q>
    <div class="viewtoggle" role="group" aria-label="View">
      <button data-view="grid" aria-pressed="true">Grid</button>
      <button data-view="list" aria-pressed="false">Archive</button>
    </div>
  </div>
</div>

<section class="works" data-works data-view="grid">
  <?php
  $lastYear = null;
  foreach ($works as $w):
    $hay = strtolower(implode(' ', [$w['title'] ?? '', $w['client'] ?? '', $w['agency'] ?? '', $w['summary'] ?? '', implode(' ', $w['disciplines'] ?? [])]));
    $y = $w['year'] ?? '';
    if ($y !== $lastYear): $lastYear = $y; ?>
      <div class="works__year" data-year="<?= e((string)$y) ?>"><?= e((string)($y ?: 'Undated')) ?></div>
    <?php endif; ?>
    <a class="card" href="<?= work_url($w) ?>"
       data-cat="<?= e($w['category'] ?? '') ?>"
       data-tags="<?= e(strtolower(implode('|', $w['disciplines'] ?? []))) ?>"
       data-hay="<?= e($hay) ?>" data-y="<?= e((string)$y) ?>"
       style="--cc:<?= e(cat_color($w['category'] ?? '')) ?>">
      <div class="card__media"><?= cover($w) ?></div>
      <div class="card__meta">
        <span class="card__title"><?= e($w['title']) ?></span>
        <span class="card__sub"><?= e($w['client'] ?? '') ?></span>
        <span class="card__cat"><?= e(cat_label($w['category'] ?? '')) ?></span>
        <span class="card__yr"><?= e((string)$y) ?></span>
      </div>
    </a>
  <?php endforeach; ?>
  <p class="works__empty" data-empty hidden>Nothing matches that yet. The archive is still growing.</p>
</section>
<script>window.__initialCat = <?= json_encode($cat) ?>;</script>
<?php page_foot(); ?>
