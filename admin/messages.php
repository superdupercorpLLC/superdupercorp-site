<?php
require __DIR__ . '/_inc.php';
require_login();
$msgs = read_json('messages', []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $i = (int)($_POST['i'] ?? -1);
    if (isset($msgs[$i])) {
        if (($_POST['action'] ?? '') === 'delete') array_splice($msgs, $i, 1);
        else $msgs[$i]['read'] = !($msgs[$i]['read'] ?? false);
        write_json('messages', $msgs);
    }
    redirect(url('admin/messages.php'));
}
admin_head('Inbox', 'messages');
?>
<div class="a-head"><h1>Inbox</h1></div>
<p class="lede">Everything sent through the contact form. A copy is emailed to <b><?= e(setting('email') ?: '(no email set in Site copy)') ?></b>, but shared hosts sometimes drop email, so this list is the backup.</p>
<?php if (!$msgs): ?><p class="empty">No messages yet.</p><?php endif; ?>
<div class="msgs">
<?php foreach (array_reverse($msgs, true) as $i => $m): ?>
  <article class="msg<?= empty($m['read']) ? ' is-new' : '' ?>">
    <header>
      <b><?= e($m['name']) ?></b> · <a href="mailto:<?= e($m['email']) ?>?subject=<?= rawurlencode('Re: your message to ' . setting('studio_name', 'SUPER DUPER CORP')) ?>"><?= e($m['email']) ?></a>
      <?php if ($m['company']): ?> · <?= e($m['company']) ?><?php endif; ?>
      <?php if ($m['kind']): ?> <i class="tag"><?= e($m['kind']) ?></i><?php endif; ?>
      <time><?= e(date('M j, Y g:ia', strtotime($m['at']))) ?></time>
    </header>
    <p><?= nl2br(e($m['message'])) ?></p>
    <form method="post" class="row">
      <?= csrf_field() ?><input type="hidden" name="i" value="<?= $i ?>">
      <button class="btn btn--small btn--ghost" name="action" value="toggle"><?= empty($m['read']) ? 'Mark read' : 'Mark unread' ?></button>
      <button class="btn btn--small btn--danger" name="action" value="delete" data-confirm="Delete this message?">Delete</button>
    </form>
  </article>
<?php endforeach; ?>
</div>
<?php admin_foot();
