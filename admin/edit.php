<?php
require __DIR__ . '/_inc.php';
require_login();

$works = all_works();
$id = (string)($_GET['id'] ?? $_POST['id'] ?? '');
$idx = null;
foreach ($works as $i => $x) if ($x['id'] === $id) { $idx = $i; break; }
$w = $idx !== null ? $works[$idx] : [
    'id' => '', 'title' => '', 'slug' => '', 'client' => '', 'agency' => '', 'year' => date('Y'),
    'category' => 'commercial', 'role' => '', 'disciplines' => [], 'summary' => '', 'body' => '',
    'credits' => '', 'link' => '', 'video_url' => '', 'cover' => [], 'gallery' => [], 'featured' => false, 'status' => 'draft',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    if (($_POST['action'] ?? '') === 'delete' && $idx !== null) {
        remove_upload($w['cover']['large'] ?? null); remove_upload($w['cover']['thumb'] ?? null);
        foreach ($w['gallery'] ?? [] as $g) { remove_upload($g['src'] ?? null); remove_upload($g['thumb'] ?? null); }
        array_splice($works, $idx, 1);
        write_json('works', $works);
        flash('Deleted “' . $w['title'] . '”.');
        redirect(url('admin/'));
    }

    $t = fn($k) => trim((string)($_POST[$k] ?? ''));
    $w['title']    = $t('title');
    $w['client']   = $t('client');
    $w['agency']   = $t('agency');
    $w['year']     = preg_match('/^\d{4}$/', $t('year')) ? (int)$t('year') : '';
    $w['category'] = isset(CATEGORIES[$t('category')]) ? $t('category') : 'commercial';
    $w['role']     = $t('role');
    $w['disciplines'] = array_values(array_unique(array_filter(array_map('trim', explode(',', $t('disciplines'))), 'strlen')));
    $w['summary']  = $t('summary');
    $w['body']     = str_replace("\r", '', (string)($_POST['body'] ?? ''));
    $w['credits']  = str_replace("\r", '', (string)($_POST['credits'] ?? ''));
    $w['link']     = filter_var($t('link'), FILTER_VALIDATE_URL) && preg_match('~^https?://~', $t('link')) ? $t('link') : '';
    $w['video_url']= $t('video_url');
    $w['featured'] = !empty($_POST['featured']);
    $w['status']   = ($_POST['status'] ?? '') === 'published' ? 'published' : 'draft';
    if ($w['title'] === '') $errors[] = 'Give it a title.';

    // Existing gallery: captions, order, wide, remove
    $gal = [];
    foreach ($w['gallery'] ?? [] as $i => $g) {
        $p = $_POST['g'][$i] ?? null;
        if (!$p) { $gal[] = $g; continue; }
        if (!empty($p['remove'])) { if (($w['cover']['large'] ?? '') === ($g['src'] ?? '')) $w['cover'] = []; remove_upload($g['src'] ?? null); if (($g['thumb'] ?? '') !== ($g['src'] ?? '')) remove_upload($g['thumb'] ?? null); continue; }
        if (!empty($p['make_cover']) && ($g['type'] ?? 'image') === 'image') $w['cover'] = ['large' => $g['src'], 'thumb' => $g['thumb'] ?? $g['src']];
        $g['caption'] = trim((string)($p['caption'] ?? ''));
        $g['wide'] = !empty($p['wide']);
        $g['_o'] = (float)($p['order'] ?? $i);
        $gal[] = $g;
    }
    usort($gal, fn($a, $b) => ($a['_o'] ?? 0) <=> ($b['_o'] ?? 0));
    $gal = array_map(function ($g) { unset($g['_o']); return $g; }, $gal);

    $label = $w['title'] ?: 'work';
    if (!empty($_POST['remove_cover'])) $w['cover'] = [];
    if ($w['title'] !== '') foreach (files_of('cover') as $f) {
        try {
            $s = store_upload($f, $label);
            if ($s['type'] !== 'image') throw new RuntimeException('The cover needs to be an image.');
            $w['cover'] = ['large' => $s['src'], 'thumb' => $s['thumb']];
        } catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); }
    }
    if ($w['title'] !== '') foreach (files_of('gallery') as $f) {
        try { $s = store_upload($f, $label); $gal[] = $s + ['caption' => '', 'wide' => false]; }
        catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); }
    }
    $w['gallery'] = $gal;
    if (empty($w['cover']) && $gal) foreach ($gal as $g) if (($g['type'] ?? '') === 'image') { $w['cover'] = ['large' => $g['src'], 'thumb' => $g['thumb']]; break; }

    if ($w['title'] !== '') {
        $wantSlug = $t('slug') ?: $w['title'];
        $w['slug'] = unique_slug($wantSlug, $w['id'] ?: null);
        $w['updated'] = date('c');
        unset($w['sample'], $w['needs_details'], $w['ai_suggested']);
        if ($idx === null) { $w['id'] = new_id(); $w['created'] = date('c'); $works[] = $w; }
        else $works[$idx] = $w;
        write_json('works', $works);
        if ($errors) flash('Saved, but: ' . implode(' ', $errors), 'err');
        else flash($w['status'] === 'published' ? 'Saved. It’s live.' : 'Saved as draft.');
        redirect(url('admin/edit.php?id=' . $w['id']));
    }
}

$isNew = $w['id'] === '';
admin_head($isNew ? 'New project' : $w['title'], $isNew ? 'new' : 'work');
$maxUp = ini_get('upload_max_filesize'); $maxPost = ini_get('post_max_size');
?>
<div class="a-head">
  <h1><?= $isNew ? 'New project' : e($w['title']) ?></h1>
  <?php if (!$isNew): ?><a class="btn btn--ghost" href="<?= work_url($w) ?>" target="_blank">Preview ↗</a><?php endif; ?>
</div>
<?php foreach ($errors as $er): ?><p class="flash flash--err"><?= e($er) ?></p><?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="edit">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= e($w['id']) ?>">

  <div class="edit__main">
    <label class="big">Title<input name="title" value="<?= e($w['title']) ?>" required placeholder="e.g. Night Market Campaign"></label>
    <label>One-line summary <small>Shows under the title and in search results</small>
      <input name="summary" value="<?= e($w['summary']) ?>" maxlength="240" placeholder="What it was, in one honest sentence."></label>

    <fieldset class="grid2">
      <label>Client<input name="client" value="<?= e($w['client']) ?>"></label>
      <label>Agency <small>if you made it through one</small><input name="agency" value="<?= e($w['agency']) ?>"></label>
      <label>Year<input name="year" value="<?= e((string)$w['year']) ?>" inputmode="numeric" pattern="\d{4}" placeholder="2019"></label>
      <label>Your role<input name="role" value="<?= e($w['role']) ?>" placeholder="Creative Director, Illustrator…"></label>
    </fieldset>

    <label>Kind of work</label>
    <div class="radios">
      <?php foreach (CATEGORIES as $k => $c): ?>
        <label class="radio" style="--cc:<?= e($c['color']) ?>"><input type="radio" name="category" value="<?= e($k) ?>"<?= $w['category'] === $k ? ' checked' : '' ?>><span><?= e($c['label']) ?></span></label>
      <?php endforeach; ?>
    </div>

    <label>Disciplines <small>comma separated, used for filtering</small>
      <input name="disciplines" value="<?= e(implode(', ', $w['disciplines'] ?? [])) ?>" placeholder="Branding, Illustration, Motion" list="disc"></label>
    <datalist id="disc"><?php foreach (lines('disciplines') as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>

    <label>The story <small>Blank line = new paragraph. **bold**, *italic*, [link](https://…)</small>
      <textarea name="body" rows="12" placeholder="What was the problem? What did you make? What happened after?"><?= e($w['body']) ?></textarea></label>

    <label>Credits <small>optional</small><textarea name="credits" rows="3" placeholder="Photography: …"><?= e($w['credits']) ?></textarea></label>

    <fieldset class="grid2">
      <label>Video link <small>YouTube or Vimeo</small><input name="video_url" value="<?= e($w['video_url']) ?>" placeholder="https://vimeo.com/…"></label>
      <label>Live link <small>optional</small><input name="link" value="<?= e($w['link']) ?>" placeholder="https://…"></label>
    </fieldset>

    <h2>Images &amp; video</h2>
    <p class="hint">Upload limit on your host: <?= e($maxUp) ?> per file, <?= e($maxPost) ?> per save. Images are resized automatically.</p>

    <div class="coverbox">
      <div class="coverbox__img" style="--cc:<?= e(cat_color($w['category'])) ?>">
        <?php if (!empty($w['cover']['thumb'])): ?><img src="<?= e(media_url($w['cover']['thumb'])) ?>" alt="Cover"><?php else: ?><span>No cover yet: a color poster shows instead</span><?php endif; ?>
      </div>
      <div>
        <label>Cover image<input type="file" name="cover" accept="image/*"></label>
        <?php if (!empty($w['cover'])): ?><label class="chk"><input type="checkbox" name="remove_cover" value="1"> Remove cover</label><?php endif; ?>
      </div>
    </div>

    <?php if (!empty($w['gallery'])): ?>
    <ol class="gal">
      <?php foreach ($w['gallery'] as $i => $g): ?>
        <li>
          <div class="gal__img">
            <?php if (($g['type'] ?? '') === 'video'): ?><video src="<?= e(media_url($g['src'])) ?>" muted></video>
            <?php else: ?><img src="<?= e(media_url($g['thumb'] ?? $g['src'])) ?>" alt="" loading="lazy"><?php endif; ?>
          </div>
          <input name="g[<?= $i ?>][caption]" value="<?= e($g['caption'] ?? '') ?>" placeholder="Caption (optional)">
          <div class="gal__opts">
            <label title="Position">#<input class="num" name="g[<?= $i ?>][order]" value="<?= $i + 1 ?>" inputmode="numeric"></label>
            <label class="chk"><input type="checkbox" name="g[<?= $i ?>][wide]" value="1"<?= !empty($g['wide']) ? ' checked' : '' ?>> Full width</label>
            <?php if (($g['type'] ?? '') !== 'video'): ?><label class="chk"><input type="checkbox" name="g[<?= $i ?>][make_cover]" value="1"> Make cover</label><?php endif; ?>
            <label class="chk danger"><input type="checkbox" name="g[<?= $i ?>][remove]" value="1"> Remove</label>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>
    <label class="drop">Add images or video to the gallery
      <input type="file" name="gallery[]" multiple accept="image/*,video/mp4,video/webm" data-preview>
      <span class="drop__list" data-preview-list></span>
    </label>
  </div>

  <aside class="edit__side">
    <div class="panel">
      <label>Status
        <select name="status">
          <option value="draft"<?= $w['status'] !== 'published' ? ' selected' : '' ?>>Draft (hidden)</option>
          <option value="published"<?= $w['status'] === 'published' ? ' selected' : '' ?>>Live on site</option>
        </select>
      </label>
      <label class="chk"><input type="checkbox" name="featured" value="1"<?= !empty($w['featured']) ? ' checked' : '' ?>> Feature on home page</label>
      <label>URL slug <small>blank = from title</small><input name="slug" value="<?= e($w['slug']) ?>"></label>
      <button class="btn btn--wide" type="submit">Save</button>
    </div>
    <?php if (!$isNew): ?>
      <?php if (!empty($w['ai_suggested'])): ?><p class="flash flash--ai">Claude wrote these details from the images. Check them, fix anything wrong, then save.</p><?php endif; ?>
      <?php if (!empty($w['source'])): ?><p class="meta">Imported from<br><?= e($w['source']) ?></p><?php endif; ?>
      <p class="meta">Created <?= e(substr($w['created'] ?? '', 0, 10)) ?><br>Updated <?= e(substr($w['updated'] ?? '', 0, 10)) ?></p>
      <button class="btn btn--danger btn--wide" name="action" value="delete" formnovalidate data-confirm="Delete this project and its uploaded files? This can’t be undone.">Delete project</button>
    <?php endif; ?>
  </aside>
</form>
<?php admin_foot();
