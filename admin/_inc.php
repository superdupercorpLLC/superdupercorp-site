<?php
/** Admin helpers: auth, CSRF, uploads, layout. */
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/media.php';
start_session();
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');


function auth(): array { return read_json('auth', []); }

function require_login(): void {
    if (empty($_SESSION['sdc_admin'])) { header('Location: ' . url('admin/login.php')); exit; }
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
    $unread = count(array_filter(read_json('messages', []), fn($m) => empty($m['read'])));
    $f = flash();
    ?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> · Admin</title>
<link rel="icon" href="<?= url('assets/favicon.svg') ?>" type="image/svg+xml">
<link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('admin/admin.css') ?>?v=1">
</head><body>
<?php if (!empty($_SESSION['sdc_admin'])): ?>
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
