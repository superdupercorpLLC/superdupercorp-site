<?php
require __DIR__ . '/inc/bootstrap.php';
start_session();
$sent = false; $err = '';
$v = ['name' => '', 'email' => '', 'company' => '', 'kind' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? ''));
    $tooFast = (time() - (int)($_SESSION['contact_t'] ?? 0)) < 3;
    $recent  = (time() - (int)($_SESSION['contact_last'] ?? 0)) < 60;
    if (($_POST['website'] ?? '') !== '' || $tooFast) {
        $sent = true; // silently drop bots
    } elseif ($recent) {
        $err = 'Easy, just sent one. Give it a minute.';
    } elseif ($v['name'] === '' || !filter_var($v['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($v['message']) < 5) {
        $err = 'We need your name, a real email, and a few words about what you have in mind.';
    } elseif (mb_strlen($v['message']) > 8000) {
        $err = 'That message is very long. Trim it down a bit, or just email us.';
    } else {
        $msgs = read_json('messages', []);
        $msgs[] = $v + ['at' => date('c'), 'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'read' => false];
        write_json('messages', array_slice($msgs, -500));
        if ($to = setting('email')) {
            $clean = fn($s) => str_replace(["\r", "\n"], ' ', $s);
            $body = "Name: {$v['name']}\nEmail: {$v['email']}\nCompany: {$v['company']}\nType: {$v['kind']}\n\n{$v['message']}\n";
            $host = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
            @mail($to, '=?UTF-8?B?' . base64_encode('New enquiry: ' . $clean($v['name'])) . '?=', $body,
                "From: website@{$host}\r\nReply-To: " . $clean($v['email']) . "\r\nContent-Type: text/plain; charset=UTF-8");
        }
        $_SESSION['contact_last'] = time();
        $sent = true;
    }
}
$_SESSION['contact_t'] = time();
page_head('Contact', 'Start a project with ' . setting('studio_name', 'SUPER DUPER CORP') . '.', 'contact');
?>
<section class="pagehead">
  <h1 class="h-mega">Say hi</h1>
  <p class="pagehead__lede"><?= e(setting('contact_intro')) ?></p>
</section>

<section class="contact">
  <?php if ($sent): ?>
    <div class="contact__done">
      <p class="h-xl">Got it.</p>
      <p>Thanks, <?= e($v['name'] ?: 'friend') ?>. Your message landed. You'll hear back soon.</p>
    </div>
  <?php else: ?>
  <form class="form" method="post" novalidate>
    <?php if ($err): ?><p class="form__err" role="alert"><?= e($err) ?></p><?php endif; ?>
    <div class="form__row">
      <label>Your name<input name="name" required value="<?= e($v['name']) ?>" autocomplete="name"></label>
      <label>Email<input name="email" type="email" required value="<?= e($v['email']) ?>" autocomplete="email"></label>
    </div>
    <div class="form__row">
      <label>Company <span>(optional)</span><input name="company" value="<?= e($v['company']) ?>" autocomplete="organization"></label>
      <label>What's it for?
        <select name="kind">
          <?php foreach (['', 'A new project', 'Agency partnership', 'Buying / licensing art', 'Press', 'Something else'] as $o): ?>
            <option value="<?= e($o) ?>"<?= $v['kind'] === $o ? ' selected' : '' ?>><?= e($o ?: 'Pick one') ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <label>Tell us about it<textarea name="message" rows="7" required><?= e($v['message']) ?></textarea></label>
    <label class="hp" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>
    <button class="btn btn--big" type="submit">Send it →</button>
  </form>
  <?php endif; ?>
  <aside class="contact__aside">
    <?php if ($em = setting('email')): ?><p class="kicker">Email</p><p><a class="contact__mail" href="mailto:<?= e($em) ?>"><?= e($em) ?></a></p><?php endif; ?>
    <?php if ($loc = setting('location')): ?><p class="kicker">Based in</p><p><?= e($loc) ?></p><?php endif; ?>
  </aside>
</section>
<?php page_foot(); ?>
