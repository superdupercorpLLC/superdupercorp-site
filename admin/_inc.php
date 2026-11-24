<?php
/** Admin helpers: auth, CSRF, uploads, layout. */
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/media.php';
if (!is_https() && !is_local_request()) { http_response_code(403); exit('The admin only works over https://'); }
send_security_headers(true);
start_session();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');


function auth(): array { return read_json('auth', []); }

const SESSION_IDLE = 2 * 3600;   // log out after 2 hours without activity
const SESSION_MAX  = 12 * 3600;  // and always after 12 hours

function logged_in(): bool {
    if (empty($_SESSION['sdc_admin'])) return false;
    $now = time();
    $ok = $now - (int) ($_SESSION['seen'] ?? 0) < SESSION_IDLE
       && $now - (int) ($_SESSION['login_at'] ?? 0) < SESSION_MAX
       && (int) ($_SESSION['epoch'] ?? -1) === (int) (auth()['session_epoch'] ?? 0); // bumped on password change
    if (!$ok) { $_SESSION = []; session_regenerate_id(true); return false; }
    $_SESSION['seen'] = $now;
    return true;
}

function require_login(): void {
    if (!logged_in()) { header('Location: ' . url('admin/login.php')); exit; }
}

function login_user(): void {
    session_regenerate_id(true);
    unset($_SESSION['pending_2fa']);
    $_SESSION['sdc_admin'] = true;
    $_SESSION['login_at'] = $_SESSION['seen'] = time();
    $_SESSION['epoch'] = (int) (auth()['session_epoch'] ?? 0);
    security_log('login');
}

/** Failed attempts (password, setup code, 2FA code) in the last 15 minutes. */
function too_many_fails(array $auth, string $ip): bool {
    $cut = time() - 900;
    $mine = count(array_filter($auth['fails'][$ip] ?? [], fn($t) => $t > $cut));
    $all = 0;
    foreach ($auth['fails'] ?? [] as $list) $all += count(array_filter((array) $list, fn($t) => $t > $cut));
    return $mine >= 8 || $all >= 40; // the global cap slows attacks spread over many IPs
}

function record_fail(array &$auth, string $ip, string $what): void {
    $cut = time() - 900;
    foreach ($auth['fails'] ?? [] as $k => $list) {
        $auth['fails'][$k] = array_values(array_filter((array) $list, fn($t) => $t > $cut));
        if (!$auth['fails'][$k]) unset($auth['fails'][$k]);
    }
    $auth['fails'][$ip][] = time();
    write_json('auth', $auth);
    security_log('login_failed', $what);
}

function csrf(): string {
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf()) . '">'; }
function check_csrf(): void {
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Session expired. Go back and reload the page.'); }
}

function flash(?string $msg = null, string $type = 'ok'): ?array {
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}
function redirect(string $to): void { header('Location: ' . $to); exit; }


function admin_head(string $title, string $active = ''): void {
    $unread = unread_messages();
    $f = flash();
    ?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> · Admin</title>
<link rel="icon" href="<?= url('assets/favicon.svg') ?>" type="image/svg+xml">
<link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('admin/admin.css') ?>?v=2">
</head><body>
<?php if (logged_in()): ?>
<header class="a-bar">
  <a class="a-mark" href="<?= url('admin/') ?>"><span class="dot"></span>Admin</a>
  <nav>
    <a href="<?= url('admin/') ?>"<?= $active === 'work' ? ' aria-current="page"' : '' ?>>Work</a>
    <a href="<?= url('admin/edit.php') ?>"<?= $active === 'new' ? ' aria-current="page"' : '' ?>>+ New</a>
    <a href="<?= url('admin/import.php') ?>"<?= $active === 'import' ? ' aria-current="page"' : '' ?>>Bulk import</a>
    <a href="<?= url('admin/settings.php') ?>"<?= $active === 'settings' ? ' aria-current="page"' : '' ?>>Site copy</a>
    <a href="<?= url('admin/messages.php') ?>"<?= $active === 'messages' ? ' aria-current="page"' : '' ?>>Inbox<?= $unread ? " <b class=\"badge\">$unread</b>" : '' ?></a>
    <a href="<?= url() ?>" target="_blank">View site ↗</a>
    <form method="post" action="<?= url('admin/logout.php') ?>"><?= csrf_field() ?><button>Log out</button></form>
  </nav>
</header>
<?php endif; ?>
<main class="a-main">
<?php if ($f): ?><p class="flash flash--<?= e($f[1]) ?>" role="status"><?= e($f[0]) ?></p><?php endif;
}

function admin_foot(): void { ?>
</main>
<script src="<?= url('admin/admin.js') ?>?v=1" defer></script>
</body></html><?php }
