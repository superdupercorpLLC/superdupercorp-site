<?php
/** Shared media + record helpers used by the admin and the ingest API. */
declare(strict_types=1);

const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
const VIDEO_TYPES = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];

/** MIME type from file contents; works even when the fileinfo extension is disabled. */
function detect_mime(string $path, string $name = ''): string {
    if (class_exists('finfo')) {
        $m = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($m) return $m;
    }
    $info = @getimagesize($path);
    if (!empty($info['mime'])) return $info['mime'];
    $head = (string) @file_get_contents($path, false, null, 0, 16);
    if (substr($head, 4, 4) === 'ftyp') return str_contains(substr($head, 8, 4), 'qt') ? 'video/quicktime' : 'video/mp4';
    if (str_starts_with($head, "\x1A\x45\xDF\xA3")) return 'video/webm';
    return 'application/octet-stream';
}

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
    $mime = detect_mime($file['tmp_name'], $file['name']);
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
