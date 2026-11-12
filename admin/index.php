<?php
require __DIR__ . '/_inc.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $works = all_works();
    $act = $_POST['action'] ?? '';
    $ids = array_flip((array)($_POST['ids'] ?? []));
    if ($act === 'delete_samples') {
        $works = array_values(array_filter($works, fn($w) => empty($w['sample'])));
        flash('Sample projects removed.');
    } else {
        $n = 0;
        foreach ($works as &$w) {
            if (!isset($ids[$w['id']])) continue;
            $n++;
            match ($act) {
                'publish'   => $w['status'] = 'published',
                'draft'     => $w['status'] = 'draft',
                'feature'   => $w['featured'] = true,
                'unfeature' => $w['featured'] = false,
                default     => null,
            };
            $w['updated'] = date('c');
        }
        unset($w);
        if ($act === 'delete') {
            foreach ($works as $w) if (isset($ids[$w['id']])) {
                remove_upload($w['cover']['large'] ?? null); remove_upload($w['cover']['thumb'] ?? null);
                foreach ($w['gallery'] ?? [] as $g) { remove_upload($g['src'] ?? null); remove_upload($g['thumb'] ?? null); }
            }
            $works = array_values(array_filter($works, fn($w) => !isset($ids[$w['id']])));
        }
        flash($n ? "Updated $n project" . ($n > 1 ? 's' : '') . '.' : 'Select some projects first.', $n ? 'ok' : 'err');
    }
    write_json('works', $works);
    redirect(url('admin/') . (isset($_GET['s']) ? '?s=' . urlencode((string)$_GET['s']) : ''));
}

$works = all_works();
usort($works, fn($a, $b) => [(int)($b['year'] ?? 0), $b['updated'] ?? ''] <=> [(int)($a['year'] ?? 0), $a['updated'] ?? '']);
$status = $_GET['s'] ?? 'all';
$counts = ['all' => count($works), 'published' => 0, 'draft' => 0];
foreach ($works as $w) $counts[$w['status'] ?? 'draft']++;
if ($status !== 'all') $works = array_filter($works, fn($w) => ($w['status'] ?? 'draft') === $status);
$hasSamples = (bool) array_filter(all_works(), fn($w) => !empty($w['sample']));

admin_head('Work', 'work');
?>
<div class="a-head">
  <h1>Work</h1>
  <div class="row">
    <a class="btn" href="<?= url('admin/edit.php') ?>">+ New project</a>
    <a class="btn btn--ghost" href="<?= url('admin/import.php') ?>">Bulk import archive</a>
  </div>
</div>

<?php if ($hasSamples): ?>
<form method="post" class="note">
  <?= csrf_field() ?><input type="hidden" name="action" value="delete_samples">
  <span><b>Sample projects are showing.</b> They only demonstrate the layouts. Remove them once your real work is in.</span>
  <button class="btn btn--small" data-confirm="Delete all sample projects?">Remove samples</button>
</form>
<?php endif; ?>

<nav class="tabs">
  <?php foreach (['all' => 'All', 'published' => 'Live', 'draft' => 'Drafts'] as $k => $l): ?>
    <a href="?s=<?= $k ?>"<?= $status === $k ? ' aria-current="page"' : '' ?>><?= $l ?> <span><?= $counts[$k] ?></span></a>
  <?php endforeach; ?>
  <input class="tabs__q" type="search" placeholder="Filter list…" data-tablefilter>
</nav>

<form method="post">
  <?= csrf_field() ?>
  <div class="bulk">
    <label class="chk"><input type="checkbox" data-checkall> All</label>
    <select name="action">
      <option value="publish">Publish</option>
      <option value="draft">Move to drafts</option>
      <option value="feature">Feature on home</option>
      <option value="unfeature">Un-feature</option>
      <option value="delete">Delete</option>
    </select>
    <button class="btn btn--small" data-confirm-delete>Apply to selected</button>
  </div>
  <?php if (!$works): ?>
    <p class="empty">Nothing here yet. <a href="<?= url('admin/edit.php') ?>">Add your first project</a> or <a href="<?= url('admin/import.php') ?>">bulk import a folder of work</a>.</p>
  <?php endif; ?>
  <ul class="wlist" data-table>
    <?php foreach ($works as $w): $thumb = $w['cover']['thumb'] ?? ''; ?>
      <li data-hay="<?= e(strtolower(($w['title'] ?? '') . ' ' . ($w['client'] ?? '') . ' ' . ($w['year'] ?? ''))) ?>">
        <input type="checkbox" name="ids[]" value="<?= e($w['id']) ?>" aria-label="Select <?= e($w['title']) ?>">
        <a class="wlist__thumb" href="<?= url('admin/edit.php?id=' . e($w['id'])) ?>" style="--cc:<?= e(cat_color($w['category'] ?? '')) ?>">
          <?php if ($thumb): ?><img src="<?= e(media_url($thumb)) ?>" alt="" loading="lazy"><?php endif; ?>
        </a>
        <a class="wlist__title" href="<?= url('admin/edit.php?id=' . e($w['id'])) ?>">
          <b><?= e($w['title'] ?: 'Untitled') ?></b>
          <span><?= e(implode(' · ', array_filter([$w['client'] ?? '', cat_label($w['category'] ?? ''), (string)($w['year'] ?? '')]))) ?></span>
        </a>
        <span class="wlist__tags">
          <?php if (!empty($w['featured'])): ?><i class="tag tag--feat">★ Home</i><?php endif; ?>
          <?php if (!empty($w['sample'])): ?><i class="tag">Sample</i><?php endif; ?>
          <i class="tag tag--<?= e($w['status'] ?? 'draft') ?>"><?= ($w['status'] ?? '') === 'published' ? 'Live' : 'Draft' ?></i>
        </span>
        <a class="wlist__view" href="<?= work_url($w) ?>" target="_blank" aria-label="Preview">↗</a>
      </li>
    <?php endforeach; ?>
  </ul>
</form>
<?php admin_foot();
