<?php
require __DIR__ . '/_inc.php';
require_login();

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $files = files_of('files');
    $mode = ($_POST['mode'] ?? 'each') === 'one' ? 'one' : 'each';
    $cat = isset(CATEGORIES[$_POST['category'] ?? '']) ? $_POST['category'] : 'personal';
    $year = preg_match('/^\d{4}$/', (string)($_POST['year'] ?? '')) ? (int)$_POST['year'] : '';
    $client = trim((string)($_POST['client'] ?? ''));
    $tags = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['disciplines'] ?? ''))), 'strlen'));
    $works = all_works();
    $ok = 0; $errs = [];
    $base = fn($title) => [
        'id' => new_id(), 'title' => $title, 'slug' => '', 'client' => $client, 'agency' => '', 'year' => $year,
        'category' => $cat, 'role' => '', 'disciplines' => $tags, 'summary' => '', 'body' => '', 'credits' => '',
        'link' => '', 'video_url' => '', 'cover' => [], 'gallery' => [], 'featured' => false, 'status' => 'draft',
        'created' => date('c'), 'updated' => date('c'), 'imported' => true,
    ];
    $group = null;
    if ($mode === 'one') {
        $group = $base(trim((string)($_POST['group_title'] ?? '')) ?: 'Untitled set ' . date('Y-m-d H:i'));
        $group['slug'] = unique_slug($group['title']);
    }
    foreach ($files as $f) {
        $title = ucwords(trim(preg_replace('/[_\-.]+/', ' ', pathinfo($f['name'], PATHINFO_FILENAME))));
        try {
            $s = store_upload($f, $title ?: 'work');
            $item = $s + ['caption' => '', 'wide' => false];
            if ($mode === 'one') {
                $group['gallery'][] = $item;
                if (!$group['cover'] && $s['type'] === 'image') $group['cover'] = ['large' => $s['src'], 'thumb' => $s['thumb']];
            } else {
                $w = $base($title ?: 'Untitled');
                $w['slug'] = unique_slug($w['title']);
                if ($s['type'] === 'image') $w['cover'] = ['large' => $s['src'], 'thumb' => $s['thumb']];
                else $w['gallery'][] = $item;
                $works[] = $w;
                write_json('works', $works); // keeps slugs unique as we go
            }
            $ok++;
        } catch (RuntimeException $ex) { $errs[] = $ex->getMessage(); }
    }
    if ($group && $ok) $works[] = $group;
    write_json('works', $works);
    $result = [$ok, $errs, $group['id'] ?? null];
}
admin_head('Bulk import', 'import');
$maxUp = ini_get('upload_max_filesize'); $maxPost = ini_get('post_max_size'); $maxN = ini_get('max_file_uploads');
?>
<div class="a-head"><h1>Bulk import</h1></div>
<p class="lede">For digging through the archive. Drop in a pile of files and each one becomes a <b>draft</b>. You can name, sort and publish them later. Nothing goes live until you say so.</p>

<?php if ($result): [$ok, $errs, $gid] = $result; ?>
  <p class="flash flash--<?= $errs ? 'err' : 'ok' ?>">
    Imported <?= $ok ?> file<?= $ok === 1 ? '' : 's' ?>.
    <?php if ($gid): ?><a href="<?= url('admin/edit.php?id=' . e($gid)) ?>">Open the project →</a><?php else: ?><a href="<?= url('admin/?s=draft') ?>">See drafts →</a><?php endif; ?>
  </p>
  <?php foreach ($errs as $er): ?><p class="flash flash--err"><?= e($er) ?></p><?php endforeach; ?>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="edit edit--single">
  <?= csrf_field() ?>
  <label class="drop drop--big">Choose files (or drag them here)
    <input type="file" name="files[]" multiple required accept="image/*,video/mp4,video/webm" data-preview>
    <span class="drop__list" data-preview-list></span>
  </label>
  <p class="hint">Your host allows <?= e($maxUp) ?> per file, <?= e($maxPost) ?> and <?= e($maxN) ?> files per batch. Send big folders in several batches.</p>

  <fieldset>
    <legend>How should these be grouped?</legend>
    <label class="chk"><input type="radio" name="mode" value="each" checked> One draft project per file</label>
    <label class="chk"><input type="radio" name="mode" value="one"> All files in one project, named: <input name="group_title" placeholder="e.g. Sketchbooks 2011"></label>
  </fieldset>

  <p class="hint">Optional. Applied to everything in this batch, and you can change it later.</p>
  <label>Kind of work</label>
  <div class="radios">
    <?php foreach (CATEGORIES as $k => $c): ?>
      <label class="radio" style="--cc:<?= e($c['color']) ?>"><input type="radio" name="category" value="<?= e($k) ?>"<?= $k === 'personal' ? ' checked' : '' ?>><span><?= e($c['label']) ?></span></label>
    <?php endforeach; ?>
  </div>
  <fieldset class="grid3">
    <label>Year<input name="year" inputmode="numeric" pattern="\d{4}" placeholder="2014"></label>
    <label>Client<input name="client"></label>
    <label>Disciplines<input name="disciplines" placeholder="Illustration, Print"></label>
  </fieldset>
  <button class="btn btn--big">Import as drafts</button>
</form>
<?php admin_foot();
