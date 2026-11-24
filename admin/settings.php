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
    'Privacy' => [
        'message_retention_days' => ['Delete Inbox messages after this many days (0 = keep forever)', 'number'],
        'email_full_text'        => ['Include the full message in notification emails (untick to email only a “you have a new enquiry” notice; email is not encrypted)', 'check'],
    ],
];
$defaults = ['message_retention_days' => '365', 'email_full_text' => '1'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (isset($_POST['totp_action'])) {
        $a = auth();
        $pwOk = password_verify((string)($_POST['current_password'] ?? ''), $a['hash'] ?? '');
        if ($_POST['totp_action'] === 'enable') {
            $secret = (string)($_SESSION['totp_new'] ?? '');
            $step = $secret ? totp_verify($secret, (string)($_POST['totp'] ?? '')) : null;
            if (!$pwOk) flash('Two-step login not turned on: the password was wrong.', 'err');
            elseif ($step === null) flash('Two-step login not turned on: that code didn’t match. Check your phone’s clock and try the newest code.', 'err');
            else {
                $a['totp'] = ['secret' => seal($secret), 'last' => $step, 'since' => date('c')];
                write_json('auth', $a);
                unset($_SESSION['totp_new']);
                security_log('2fa_enabled');
                flash('Two-step login is on. You’ll need a code from your phone each time you log in.');
            }
        } elseif ($_POST['totp_action'] === 'disable') {
            $code = !empty($a['totp']) ? totp_verify((string) unseal((string) $a['totp']['secret']), (string)($_POST['totp'] ?? '')) : null;
            if (!$pwOk || $code === null) flash('Two-step login is still on: the password or code was wrong.', 'err');
            else { unset($a['totp']); write_json('auth', $a); security_log('2fa_disabled'); flash('Two-step login is off.'); }
        }
        redirect(url('admin/settings.php') . '#security');
    }
    if (isset($_POST['key_action'])) {
        $a = auth();
        $a['api_keys'] = $a['api_keys'] ?? [];
        if ($_POST['key_action'] === 'new') {
            $key = 'sdc_' . bin2hex(random_bytes(24));
            $a['api_keys'][] = ['id' => new_id(), 'label' => mb_substr(trim((string)($_POST['label'] ?? '')) ?: 'Uploader', 0, 60),
                                'hash' => hash('sha256', $key), 'created' => date('c'), 'last_used' => ''];
            $_SESSION['new_key'] = $key;
            security_log('api_key_created', end($a['api_keys'])['label']);
            flash('New uploader key created. Copy it now: it will not be shown again.');
        } elseif ($_POST['key_action'] === 'revoke') {
            $a['api_keys'] = array_values(array_filter($a['api_keys'], fn($k) => $k['id'] !== ($_POST['key_id'] ?? '')));
            flash('Key revoked. Anything using it stops working immediately.');
            security_log('api_key_revoked');
        }
        write_json('auth', $a);
        redirect(url('admin/settings.php') . '#keys');
    }
    $s = read_json('site', []);
    foreach ($fields as $group) foreach ($group as $k => [, $type]) {
        $v = trim(str_replace("\r", '', (string)($_POST[$k] ?? '')));
        if ($type === 'check') $v = isset($_POST[$k]) ? '1' : '0';
        if ($type === 'number') $v = ctype_digit($v) ? (string) min((int) $v, 36500) : $defaults[$k];
        $s[$k] = $v;
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
        if (mb_strlen($np) < 12) { flash('Copy saved. New password needs 12+ characters.', 'err'); redirect(url('admin/settings.php')); }
        $a['hash'] = password_hash($np, PASSWORD_DEFAULT);
        $a['session_epoch'] = (int) ($a['session_epoch'] ?? 0) + 1; // signs out every other browser
        write_json('auth', $a);
        $_SESSION['epoch'] = $a['session_epoch'];
        security_log('password_changed');
        flash('Saved. Password changed, and any other browsers logged in to the admin were signed out.');
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
      <?php foreach ($group as $k => [$label, $type]): $val = is_string($s[$k] ?? null) ? $s[$k] : ($defaults[$k] ?? ''); ?>
        <?php if ($type === 'check'): ?>
        <label class="chk"><input type="checkbox" name="<?= e($k) ?>" value="1"<?= $val === '1' ? ' checked' : '' ?>> <?= e($label) ?></label>
        <?php continue; endif; ?>
        <label><?= e($label) ?>
          <?php if ($type === 'text'): ?><input name="<?= e($k) ?>" value="<?= e($val) ?>">
          <?php elseif ($type === 'number'): ?><input name="<?= e($k) ?>" value="<?= e($val) ?>" inputmode="numeric" pattern="[0-9]*" style="max-width:140px">
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
      <label>New password <small>12+ characters, leave blank to keep</small><input type="password" name="new_password" autocomplete="new-password"></label>
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
<?php
$a = auth();
if (empty($a['totp']) && empty($_SESSION['totp_new'])) $_SESSION['totp_new'] = base32_encode(random_bytes(20));
$host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'site'));
$log = array_reverse(read_json('security-log', []));
$labels = ['login' => 'Logged in', 'login_failed' => 'Failed login', 'password_set' => 'Password set', 'password_changed' => 'Password changed',
           '2fa_enabled' => 'Two-step login on', '2fa_disabled' => 'Two-step login off', 'api_key_created' => 'Uploader key created',
           'api_key_revoked' => 'Uploader key revoked', 'api_key_rejected' => 'Wrong uploader key used', 'self_update' => 'Code updated', 'logout' => 'Logged out'];
?>
<section class="panel" id="security" style="max-width:860px;margin-top:40px">
  <h2 style="margin-top:0">Security</h2>
  <?php if (!empty($a['totp'])): ?>
    <p>🔒 <b>Two-step login is on</b> (since <?= e(substr((string) $a['totp']['since'], 0, 10)) ?>). Logging in needs your password and a code from your phone.</p>
    <details><summary>Turn it off</summary>
      <form method="post" class="stack" style="max-width:360px"><?= csrf_field() ?><input type="hidden" name="totp_action" value="disable">
        <label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
        <label>Code from your app<input name="totp" required inputmode="numeric" autocomplete="one-time-code"></label>
        <button class="btn btn--small btn--danger">Turn off two-step login</button></form>
    </details>
  <?php else: ?>
    <p><b>Turn on two-step login.</b> The Inbox holds clients’ names, emails and messages, so a password alone shouldn’t be enough to read them.
       With two-step login, a stolen password is useless without your phone.</p>
    <ol class="hint">
      <li>Install an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password, Authy…).</li>
      <li>Add an account by entering this setup key by hand (time-based, 6 digits):
        <p class="newkey"><?= e(trim(chunk_split((string) $_SESSION['totp_new'], 4, ' '))) ?></p>
        <small>Some apps take a link instead: <code style="word-break:break-all"><?= e('otpauth://totp/' . rawurlencode(setting('studio_name', 'SDC') . ':' . $host) . '?secret=' . $_SESSION['totp_new'] . '&issuer=' . rawurlencode(setting('studio_name', 'SDC'))) ?></code></small></li>
      <li>Type the code the app shows, plus your password.</li>
    </ol>
    <form method="post" class="row" style="flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="totp_action" value="enable">
      <input name="totp" placeholder="6-digit code" required inputmode="numeric" autocomplete="one-time-code" style="max-width:160px">
      <input type="password" name="current_password" placeholder="Current password" required autocomplete="current-password" style="max-width:220px">
      <button class="btn btn--small">Turn on</button></form>
    <p class="hint">Lost your phone? In cPanel File Manager, open <code>auth.json</code> in the site’s data folder and delete the <code>"totp"</code> entry.</p>
  <?php endif; ?>

  <h3>Recent security activity</h3>
  <?php if (!$log): ?><p class="hint">Nothing yet.</p><?php else: ?>
  <ul class="keys">
    <?php foreach (array_slice($log, 0, 25) as $l): ?>
      <li><span><b><?= e($labels[$l['event']] ?? $l['event']) ?></b><?= $l['detail'] !== '' ? ' · ' . e($l['detail']) : '' ?></span>
          <small><?= e(date('M j, Y g:ia', strtotime($l['at']))) ?> · <?= e($l['ip'] ?: '?') ?></small></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php admin_foot();
