<?php
/**
 * SUPER DUPER CORP — shared bootstrap.
 * Flat-file storage (JSON in /data), no database required.
 */
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
// Private data (password hash, uploader keys, messages, content) lives OUTSIDE the web root when
// possible, e.g. /home/user/sdc-site-data next to /home/user/sdc-site, so the web server can never
// serve it, whatever its .htaccess support. Existing files in ./data are moved there automatically.
(function () {
    $outside = dirname(ROOT) . '/' . basename(ROOT) . '-data';
    if (!is_dir($outside)) @mkdir($outside, 0750);
    if (is_dir($outside) && is_writable($outside)) {
        foreach (glob(ROOT . '/data/*.{json,txt}', GLOB_BRACE) ?: [] as $f) {
            $to = $outside . '/' . basename($f);
            if (!file_exists($to)) @rename($f, $to); else @unlink($f);
        }
        define('DATA_DIR', $outside);
    } else {
        define('DATA_DIR', ROOT . '/data');
    }
})();
define('UPLOAD_DIR', ROOT . '/uploads');

const CATEGORIES = [
    'personal'  => ['label' => 'Personal & Art', 'short' => 'Art',       'color' => '#ff3b1f'],
    'commercial'=> ['label' => 'Commercial',     'short' => 'Commercial','color' => '#ffd400'],
    'corporate' => ['label' => 'Corporate',      'short' => 'Corporate', 'color' => '#2b3bff'],
    'agency'    => ['label' => 'Agency',         'short' => 'Agency',    'color' => '#00c46a'],
];

// Base URL path (works in a sub-folder too).
(function () {
    $root = str_replace('\\', '/', (string) realpath(ROOT));
    $doc  = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $base = ($doc !== '' && str_starts_with($root, $doc)) ? substr($root, strlen($doc)) : '';
    define('BASE', rtrim($base, '/') . '/');
})();

function url(string $path = ''): string { return BASE . ltrim($path, '/'); }
function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// First run: copy the starter content into data/. After that, data/ belongs to the live site
// and is never part of Git or a deploy, so publishing new code can't overwrite your work.
foreach (['site', 'works'] as $seed) {
    if (!is_file(DATA_DIR . "/$seed.json") && is_file(ROOT . "/inc/seed/$seed.json")) {
        @copy(ROOT . "/inc/seed/$seed.json", DATA_DIR . "/$seed.json");
    }
}

function read_json(string $name, $default = []) {
    $f = DATA_DIR . "/$name.json";
    if (!is_file($f)) return $default;
    $d = json_decode((string) file_get_contents($f), true);
    return is_array($d) ? $d : $default;
}

function write_json(string $name, $data): void {
    $f = DATA_DIR . "/$name.json";
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    rename($tmp, $f);
}

function site(): array {
    static $s = null;
    return $s ??= read_json('site', []);
}
function setting(string $k, string $default = ''): string {
    $v = site()[$k] ?? $default;
    return is_string($v) ? $v : $default;
}
/** Settings stored as multi-line text → array of trimmed non-empty lines. */
function lines(string $k): array {
    return array_values(array_filter(array_map('trim', explode("\n", setting($k))), 'strlen'));
}

function all_works(): array { return read_json('works', []); }

function published_works(): array {
    $w = array_filter(all_works(), fn($x) => ($x['status'] ?? 'draft') === 'published');
    usort($w, fn($a, $b) => [(int)($b['year'] ?? 0), $b['created'] ?? ''] <=> [(int)($a['year'] ?? 0), $a['created'] ?? '']);
    return array_values($w);
}

function find_work(string $slug): ?array {
    foreach (all_works() as $w) if (($w['slug'] ?? '') === $slug) return $w;
    return null;
}

function slugify(string $s): string {
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s), '-'));
    return $s !== '' ? $s : 'project';
}

function cat_label(string $c): string { return CATEGORIES[$c]['label'] ?? ucfirst($c); }
function cat_color(string $c): string { return CATEGORIES[$c]['color'] ?? '#111'; }

function work_url(array $w): string { return url('work/' . rawurlencode($w['slug'])); }

/** Plain text → paragraphs; supports **bold**, *italic*, [link](https://…). */
function prose(string $text): string {
    $out = '';
    foreach (preg_split('/\R{2,}/', trim($text)) as $p) {
        if (trim($p) === '') continue;
        $h = e(trim($p));
        $h = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $h);
        $h = preg_replace('/(?<!\*)\*(?!\s)(.+?)\*/s', '<em>$1</em>', $h);
        $h = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $h);
        $out .= '<p>' . nl2br($h) . "</p>\n";
    }
    return $out;
}

/** YouTube / Vimeo URL → embed URL, or null. */
function video_embed(string $u): ?string {
    if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([\w-]{11})~', $u, $m))
        return 'https://www.youtube-nocookie.com/embed/' . $m[1];
    if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $u, $m))
        return 'https://player.vimeo.com/video/' . $m[1];
    return null;
}

function media_url(?string $p): string {
    if (!$p) return '';
    return preg_match('~^https?://~', $p) ? $p : url($p);
}

/** Poster-style cover shown when a project has no image yet. */
function poster(array $w, string $class = ''): string {
    $c = $w['category'] ?? 'personal';
    $dark = in_array($c, ['commercial'], true) ? ' poster--ink' : '';
    return '<div class="poster' . $dark . ' ' . e($class) . '" style="--pc:' . e(cat_color($c)) . '">'
         . '<span class="poster__t">' . e($w['title'] ?? '') . '</span>'
         . '<span class="poster__m">' . e(trim(($w['year'] ?? '') . ' / ' . cat_label($c), ' /')) . '</span></div>';
}

function cover(array $w, string $size = 'thumb'): string {
    $src = $w['cover'][$size] ?? ($w['cover']['large'] ?? '');
    if (!$src) return poster($w);
    return '<img src="' . e(media_url($src)) . '" alt="' . e($w['title'] ?? '') . '" loading="lazy" decoding="async">';
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('sdc');
    session_set_cookie_params(['lifetime' => 0, 'path' => BASE, 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
    session_start();
}

function page_head(string $title = '', string $desc = '', string $active = ''): void {
    $GLOBALS['__active'] = $active;
    $GLOBALS['__title']  = $title;
    $GLOBALS['__desc']   = $desc;
    require ROOT . '/inc/header.php';
}
function page_foot(): void { require ROOT . '/inc/footer.php'; }
