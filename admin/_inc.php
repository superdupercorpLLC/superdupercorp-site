<?php
/** Admin helpers: auth, CSRF, uploads, layout. */
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
start_session();
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
const VIDEO_TYPES = ['video/mp4' => 'mp4', 'video/webm' => 'webm'];

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

/** Normalise $_FILES[name] into a flat list of files. */
function files_of(string $name): array {
    if (empty($_FILES[$name])) return [];
    $f = $_FILES[$name];
    if (!is_array($f['name'])) return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
    $out = [];
    foreach ($f['name'] as $i => $n) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $out[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

function upload_error_text(int $code): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'file is bigger than your host allows (' . ini_get('upload_max_filesize') . ')',
        UPLOAD_ERR_PARTIAL => 'upload was interrupted',
        default => 'upload failed (code ' . $code . ')',
    };
}

/**
 * Store one uploaded file. Images get a large (2400px) and thumb (1000px) version.
 * Returns ['type','src','thumb'] with paths relative to the site root.
 */
function store_upload(array $file, string $label): array {
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException($file['name'] . ': ' . upload_error_text($file['error']));
    if (!is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Invalid upload.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $dir = 'uploads/' . date('Y/m');
    if (!is_dir(ROOT . "/$dir")) mkdir(ROOT . "/$dir", 0755, true);
    $base = $dir . '/' . substr(slugify($label), 0, 40) . '-' . bin2hex(random_bytes(3));

    if (isset(VIDEO_TYPES[$mime])) {
        $dest = $base . '.' . VIDEO_TYPES[$mime];
        move_uploaded_file($file['tmp_name'], ROOT . "/$dest");
        return ['type' => 'video', 'src' => $dest, 'thumb' => ''];
    }
    if (!isset(IMAGE_TYPES[$mime])) throw new RuntimeException($file['name'] . ': only JPG, PNG, GIF, WEBP images or MP4/WEBM video.');

    if (!extension_loaded('gd') || $mime === 'image/gif') {
        $dest = $base . '.' . IMAGE_TYPES[$mime];
        move_uploaded_file($file['tmp_name'], ROOT . "/$dest");
        return ['type' => 'image', 'src' => $dest, 'thumb' => $dest];
    }

    @ini_set('memory_limit', '512M');
    $img = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
    if (!$img) throw new RuntimeException($file['name'] . ': could not read this image.');
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($file['tmp_name'])['Orientation'] ?? 1);
        if ($o === 3) $img = imagerotate($img, 180, 0);
        elseif ($o === 6) $img = imagerotate($img, -90, 0);
        elseif ($o === 8) $img = imagerotate($img, 90, 0);
    }
    $alpha = $mime === 'image/png' || $mime === 'image/webp';
    $large = save_resized($img, ROOT . "/$base-l", 2400, $alpha);
    $thumb = save_resized($img, ROOT . "/$base-t", 1000, $alpha);
    imagedestroy($img);
    return ['type' => 'image', 'src' => "$base-l.$large", 'thumb' => "$base-t.$thumb"];
}

function save_resized($img, string $pathNoExt, int $max, bool $alpha): string {
    $w = imagesx($img); $h = imagesy($img);
    $s = min(1, $max / max($w, $h));
    $nw = max(1, (int) round($w * $s)); $nh = max(1, (int) round($h * $s));
    $out = imagecreatetruecolor($nw, $nh);
    if ($alpha) { imagealphablending($out, false); imagesavealpha($out, true); }
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    if (function_exists('imagewebp')) { imagewebp($out, "$pathNoExt.webp", 84); $ext = 'webp'; }
    elseif ($alpha) { imagepng($out, "$pathNoExt.png", 7); $ext = 'png'; }
    else { imagejpeg($out, "$pathNoExt.jpg", 85); $ext = 'jpg'; }
    imagedestroy($out);
    return $ext;
}

/** Remove a stored file if it lives under /uploads. */
function remove_upload(?string $p): void {
    if (!$p || !str_starts_with($p, 'uploads/') || str_contains($p, '..')) return;
    $f = ROOT . '/' . $p;
    if (is_file($f)) @unlink($f);
}

function unique_slug(string $want, ?string $exceptId = null): string {
    $taken = [];
    foreach (all_works() as $w) if (($w['id'] ?? '') !== $exceptId) $taken[$w['slug']] = true;
    $s = slugify($want); $i = 2; $try = $s;
    while (isset($taken[$try])) $try = $s . '-' . $i++;
    return $try;
}

function new_id(): string { return bin2hex(random_bytes(6)); }

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
