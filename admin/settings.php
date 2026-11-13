<?php
require __DIR__ . '/_inc.php';
require_login();

$fields = [
    'Identity' => [
        'studio_name'      => ['Studio name (top-left logo text)', 'text'],
        'legal_name'       => ['Legal name (footer)', 'text'],
        'tagline'          => ['Tagline: the big line on the home page', 'text'],
        'meta_description' => ['Search engine description', 'text'],
    ],
    'Home page' => [
        'disciplines'   => ['Ticker words / disciplines: one per line', 'lines'],
        'cat_personal'  => ['Blurb: Personal & Art', 'text'],
        'cat_commercial'=> ['Blurb: Commercial', 'text'],
        'cat_corporate' => ['Blurb: Corporate', 'text'],
        'cat_agency'    => ['Blurb: Agency', 'text'],
        'clients'       => ['Clients & collaborators: one per line (leave empty to hide)', 'lines'],
    ],
    'Studio page' => [
        'about_intro'   => ['Studio statement: the big paragraph', 'area'],
        'founder_name'  => ['Founder name', 'text'],
        'founder_title' => ['Founder title', 'text'],
        'founder_bio'   => ['Founder bio. Blank line = new paragraph', 'area'],
        'services'      => ['Services: one per line, as  Name | short description', 'lines'],
        'timeline'      => ['Timeline: one per line, as  Year | what happened  (empty = hidden)', 'lines'],
    ],
    'Work & contact' => [
        'work_intro'    => ['Intro on the Work page', 'area'],
        'contact_intro' => ['Intro on the Contact page', 'area'],
        'email'         => ['Email (enquiries are sent here and saved in the Inbox)', 'text'],
        'location'      => ['Location', 'text'],
        'socials'       => ['Social links: one per line, as  Label | https://…', 'lines'],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (isset($_POST['key_action'])) {
        $a = auth();
        $a['api_keys'] = $a['api_keys'] ?? [];
        if ($_POST['key_action'] === 'new') {
            $key = 'sdc_' . bin2hex(random_bytes(24));
            $a['api_keys'][] = ['id' => new_id(), 'label' => mb_substr(trim((string)($_POST['label'] ?? '')) ?: 'Uploader', 0, 60),
                                'hash' => hash('sha256', $key), 'created' => date('c'), 'last_used' => ''];
            $_SESSION['new_key'] = $key;
            flash('New uploader key created. Copy it now: it will not be shown again.');
        } elseif ($_POST['key_action'] === 'revoke') {
            $a['api_keys'] = array_values(array_filter($a['api_keys'], fn($k) => $k['id'] !== ($_POST['key_id'] ?? '')));
            flash('Key revoked. Anything using it stops working immediately.');
        }
        write_json('auth', $a);
        redirect(url('admin/settings.php') . '#keys');
    }
    $s = read_json('site', []);
    foreach ($fields as $group) foreach ($group as $k => $_) {
        $s[$k] = trim(str_replace("\r", '', (string)($_POST[$k] ?? '')));
    }
    if (!empty($_POST['remove_photo'])) $s['founder_photo'] = '';
    foreach (files_of('founder_photo') as $f) {
        try { $up = store_upload($f, 'founder'); if ($up['type'] === 'image') $s['founder_photo'] = $up['src']; }
        catch (RuntimeException $ex) { flash($ex->getMessage(), 'err'); }
    }
    write_json('site', $s);

    $np = (string)($_POST['new_password'] ?? '');
    if ($np !== '') {
        $a = auth();
        if (!password_verify((string)($_POST['current_password'] ?? ''), $a['hash'] ?? '')) { flash('Copy saved, but the current password was wrong, so the password was not changed.', 'err'); redirect(url('admin/settings.php')); }
        if (mb_strlen($np) < 10) { flash('Copy saved. New password needs 10+ characters.', 'err'); redirect(url('admin/settings.php')); }
        $a['hash'] = password_hash($np, PASSWORD_DEFAULT);
        write_json('auth', $a);
    }
    if (!isset($_SESSION['flash'])) flash('Saved. Changes are live.');
    redirect(url('admin/settings.php'));
}

$s = read_json('site', []);
admin_head('Site copy', 'settings');
?>
<div class="a-head"><h1>Site copy</h1></div>
<p class="lede">Every word on the site that isn't a project lives here. Write it how you'd say it.</p>
<form method="post" enctype="multipart/form-data" class="edit edit--single">
  <?= csrf_field() ?>
  <?php foreach ($fields as $title => $group): ?>
    <fieldset class="panel">
      <legend><?= e($title) ?></legend>
      <?php foreach ($group as $k => [$label, $type]): $val = is_string($s[$k] ?? null) ? $s[$k] : ''; ?>
        <label><?= e($label) ?>
          <?php if ($type === 'text'): ?><input name="<?= e($k) ?>" value="<?= e($val) ?>">
          <?php else: ?><textarea name="<?= e($k) ?>" rows="<?= $type === 'area' ? 5 : max(4, min(14, substr_count($val, "\n") + 2)) ?>"><?= e($val) ?></textarea><?php endif; ?>
        </label>
        <?php if ($k === 'founder_title'): ?>
          <div class="coverbox">
            <div class="coverbox__img"><?php if (!empty($s['founder_photo'])): ?><img src="<?= e(media_url($s['founder_photo'])) ?>" alt=""><?php else: ?><span>No photo</span><?php endif; ?></div>
            <div><label>Founder photo<input type="file" name="founder_photo" accept="image/*"></label>
            <?php if (!empty($s['founder_photo'])): ?><label class="chk"><input type="checkbox" name="remove_photo" value="1"> Remove photo</label><?php endif; ?></div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </fieldset>
  <?php endforeach; ?>
  <fieldset class="panel">
    <legend>Change password</legend>
    <fieldset class="grid2">
      <label>Current password<input type="password" name="current_password" autocomplete="current-password"></label>
      <label>New password <small>leave blank to keep</small><input type="password" name="new_password" autocomplete="new-password"></label>
    </fieldset>
  </fieldset>
  <button class="btn btn--big sticky-save">Save everything</button>
</form>

<section class="panel" id="keys" style="max-width:860px;margin-top:40px">
  <h2 style="margin-top:0">Uploader keys</h2>
  <p class="hint">A key lets the <b>sdc</b> uploader on your Mac (and Claude) add work to this site as <b>drafts</b>. Keys can never publish anything. Revoke a key if a computer is lost.</p>
  <?php if (!empty($_SESSION['new_key'])): ?>
    <p><b>Your new key:</b> copy it into the uploader setup now.</p>
    <p class="newkey"><?= e($_SESSION['new_key']) ?></p>
    <?php unset($_SESSION['new_key']); ?>
  <?php endif; ?>
  <?php $keys = auth()['api_keys'] ?? []; if ($keys): ?>
  <ul class="keys">
    <?php foreach ($keys as $k): ?>
      <li><span><b><?= e($k['label']) ?></b><br><small>Created <?= e(substr($k['created'], 0, 10)) ?> · <?= $k['last_used'] ? 'last used ' . e(substr($k['last_used'], 0, 10)) : 'never used' ?></small></span>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="key_action" value="revoke"><input type="hidden" name="key_id" value="<?= e($k['id']) ?>">
          <button class="btn btn--small btn--danger" data-confirm="Revoke this key?">Revoke</button></form></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <form method="post" class="row" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="key_action" value="new">
    <input name="label" placeholder="Name it, e.g. Studio iMac" style="max-width:280px">
    <button class="btn btn--small">+ New key</button></form>
</section>
<?php admin_foot();
