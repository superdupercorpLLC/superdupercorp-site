<?php
require __DIR__ . '/_inc.php';
$auth = auth();
$setup = empty($auth['hash']);
$err = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '?';
$codeFile = DATA_DIR . '/SETUP-CODE.txt';

if ($setup && !is_file($codeFile)) {
    file_put_contents($codeFile, strtoupper(bin2hex(random_bytes(4))) . "\n");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $pw = (string)($_POST['password'] ?? '');
    if ($setup) {
        $code = trim((string) @file_get_contents($codeFile));
        if (!hash_equals($code, strtoupper(trim((string)($_POST['code'] ?? ''))))) {
            $err = 'That setup code doesn’t match. Check data/SETUP-CODE.txt on your server.';
        } elseif (mb_strlen($pw) < 10) {
            $err = 'Use at least 10 characters.';
        } elseif ($pw !== ($_POST['password2'] ?? '')) {
            $err = 'The two passwords don’t match.';
        } else {
            write_json('auth', ['hash' => password_hash($pw, PASSWORD_DEFAULT), 'fails' => []]);
            @unlink($codeFile);
            session_regenerate_id(true);
            $_SESSION['sdc_admin'] = true;
            flash('You’re in. Password saved.');
            redirect(url('admin/'));
        }
    } else {
        $fails = array_filter($auth['fails'][$ip] ?? [], fn($t) => $t > time() - 900);
        if (count($fails) >= 8) {
            $err = 'Too many attempts. Try again in 15 minutes.';
        } elseif (password_verify($pw, $auth['hash'])) {
            unset($auth['fails'][$ip]);
            write_json('auth', $auth);
            session_regenerate_id(true);
            $_SESSION['sdc_admin'] = true;
            redirect(url('admin/'));
        } else {
            $fails[] = time();
            $auth['fails'][$ip] = array_values($fails);
            write_json('auth', $auth);
            $err = 'Wrong password.';
        }
    }
}
admin_head($setup ? 'Set up' : 'Log in');
?>
<div class="login">
  <h1><?= $setup ? 'Set your password' : 'Log in' ?></h1>
  <?php if ($setup): ?>
    <p>First time here. To prove you own this server, open <code>data/SETUP-CODE.txt</code> in cPanel File Manager and enter the code below.</p>
  <?php endif; ?>
  <?php if ($err): ?><p class="flash flash--err"><?= e($err) ?></p><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <?php if ($setup): ?><label>Setup code<input name="code" required autocomplete="off"></label><?php endif; ?>
    <label>Password<input type="password" name="password" required autofocus autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>"></label>
    <?php if ($setup): ?><label>Again<input type="password" name="password2" required autocomplete="new-password"></label><?php endif; ?>
    <button class="btn"><?= $setup ? 'Save & enter' : 'Enter' ?></button>
  </form>
</div>
<?php admin_foot();
