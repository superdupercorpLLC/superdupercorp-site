<?php
/**
 * Ingest API for the `sdc` uploader and Claude.
 * Auth: "Authorization: Bearer <key>" (keys are created in Admin → Site copy → Uploader keys).
 * Safety: nothing created or changed here is ever published. Only a person in the admin publishes.
 *
 *   GET  ?a=ping                     → site name + host upload limits
 *   POST ?a=create   (JSON)          → create a draft project (idempotent by source_key)
 *   POST ?a=upload   (multipart)     → add a file to a project (deduplicated by content hash)
 *   GET  ?a=drafts[&all=1]           → drafts that still need details
 *   POST ?a=update   (JSON)          → fill in details on a draft
 *   GET  ?a=diag                     → server capabilities + installed code version
 *   POST ?a=self_update              → install the latest code from the site's own GitHub repo
 */
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/media.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function body_json(): array {
    $d = json_decode((string) file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}
function clean_str($v, int $max = 300): string { return mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $max); }

// --- auth -------------------------------------------------------------------
$hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!$hdr && function_exists('getallheaders')) foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $hdr = $v;
if (!$hdr && !empty($_SERVER['HTTP_X_SDC_KEY'])) $hdr = 'Bearer ' . $_SERVER['HTTP_X_SDC_KEY'];
if (!preg_match('/^Bearer\s+(\S{20,})$/', $hdr, $m)) out(['error' => 'Missing uploader key.'], 401);
$auth = read_json('auth', []);
$hash = hash('sha256', $m[1]);
$keyIdx = null;
foreach ($auth['api_keys'] ?? [] as $i => $k) if (hash_equals($k['hash'], $hash)) { $keyIdx = $i; break; }
if ($keyIdx === null) out(['error' => 'Uploader key not recognised (it may have been revoked).'], 403);
if (time() - strtotime($auth['api_keys'][$keyIdx]['last_used'] ?? '2000-01-01') > 300) {
    $auth['api_keys'][$keyIdx]['last_used'] = date('c');
    write_json('auth', $auth);
}

$a = $_GET['a'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$fields = function (array $in): array {
    $o = [];
    foreach (['title' => 160, 'client' => 160, 'agency' => 160, 'role' => 160, 'summary' => 240] as $k => $max)
        if (array_key_exists($k, $in)) $o[$k] = clean_str($in[$k], $max);
    foreach (['body' => 20000, 'credits' => 4000] as $k => $max)
        if (array_key_exists($k, $in)) $o[$k] = mb_substr(str_replace("\r", '', (string) $in[$k]), 0, $max);
    if (array_key_exists('year', $in)) $o['year'] = preg_match('/^(19|20)\d\d$/', (string) $in['year']) ? (int) $in['year'] : '';
    if (isset($in['category']) && isset(CATEGORIES[$in['category']])) $o['category'] = $in['category'];
    if (isset($in['disciplines'])) {
        $d = is_array($in['disciplines']) ? $in['disciplines'] : explode(',', (string) $in['disciplines']);
        $o['disciplines'] = array_values(array_unique(array_filter(array_map(fn($x) => clean_str($x, 40), $d), 'strlen')));
    }
    if (isset($in['link'])) $o['link'] = preg_match('~^https?://~', (string) $in['link']) ? clean_str($in['link'], 500) : '';
    if (isset($in['video_url'])) $o['video_url'] = clean_str($in['video_url'], 500);
    return $o;
};

// Any crash becomes a readable JSON error (and lands in the PHP error log) instead of a bare 500.
set_exception_handler(function (Throwable $e) {
    error_log('[sdc api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out(['error' => 'Server error: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'], 500);
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
        http_response_code(500);
        echo json_encode(['error' => 'Fatal: ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')']);
    }
});

// --- actions ------------------------------------------------------------------
if ($a === 'ping') {
    out(['ok' => true, 'site' => setting('studio_name', 'SUPER DUPER CORP'),
         'limits' => ['upload_max_filesize' => ini_get('upload_max_filesize'), 'post_max_size' => ini_get('post_max_size')],
         'categories' => array_keys(CATEGORIES)]);
}

if ($a === 'diag') {
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    $can = fn($f) => function_exists($f) && !in_array($f, $disabled, true);
    $git = $can('exec') ? trim((string) @exec('command -v git 2>/dev/null')) : '';
    out(['ok' => true, 'php' => PHP_VERSION, 'sapi' => PHP_SAPI,
         'ext' => ['gd' => extension_loaded('gd'), 'webp' => function_exists('imagewebp'), 'fileinfo' => class_exists('finfo'),
                   'exif' => function_exists('exif_read_data'), 'mbstring' => extension_loaded('mbstring')],
         'memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time'),
         'writable' => ['uploads' => is_writable(ROOT . '/uploads'), 'data' => is_writable(DATA_DIR)],
         'data_outside_webroot' => !str_starts_with(DATA_DIR, ROOT . '/'),
         'can_run_commands' => ['exec' => $can('exec'), 'shell_exec' => $can('shell_exec'), 'proc_open' => $can('proc_open')],
         'git' => $git ?: null, 'phar' => class_exists('PharData'), 'curl' => function_exists('curl_init'),
         'allow_url_fopen' => (bool) ini_get('allow_url_fopen'), 'version' => read_json('version', null)]);
}

if ($a === 'self_update' && $method === 'POST') {
    require dirname(__DIR__) . '/inc/selfupdate.php';
    @set_time_limit(300);
    try { out(['ok' => true] + self_update('main')); }
    catch (RuntimeException $e) { out(['error' => $e->getMessage()], 502); }
}

if ($a === 'create' && $method === 'POST') {
    $in = body_json();
    $works = all_works();
    $key = clean_str($in['source_key'] ?? '', 200);
    if ($key !== '') foreach ($works as $w) if (($w['source_key'] ?? '') === $key)
        out(['ok' => true, 'id' => $w['id'], 'slug' => $w['slug'], 'existing' => true]);
    $w = $fields($in) + ['title' => '', 'client' => '', 'agency' => '', 'year' => '', 'category' => 'personal', 'role' => '',
        'disciplines' => [], 'summary' => '', 'body' => '', 'credits' => '', 'link' => '', 'video_url' => ''];
    if ($w['title'] === '') $w['title'] = 'Untitled ' . date('Y-m-d H:i');
    $w += ['cover' => [], 'gallery' => [], 'featured' => false];
    $w['status'] = 'draft';
    $w['needs_details'] = true;
    $w['source'] = clean_str($in['source'] ?? '', 500);
    $w['source_key'] = $key;
    $w['imported_via'] = clean_str($in['via'] ?? 'uploader', 40);
    $w['id'] = new_id();
    $w['slug'] = unique_slug($w['title']);
    $w['created'] = $w['updated'] = date('c');
    $works[] = $w;
    write_json('works', $works);
    out(['ok' => true, 'id' => $w['id'], 'slug' => $w['slug'], 'existing' => false]);
}

if ($a === 'upload' && $method === 'POST') {
    $id = (string) ($_POST['id'] ?? '');
    $files = files_of('file');
    if (!$files) out(['error' => 'No file received. It may be larger than the host allows (' . ini_get('upload_max_filesize') . ').'], 400);
    $f = $files[0];
    if ($f['error'] !== UPLOAD_ERR_OK) out(['error' => upload_error_text($f['error'])], 413);
    $sha = hash_file('sha256', $f['tmp_name']);
    $hashes = read_json('hashes', []);
    if (isset($hashes[$sha])) out(['ok' => true, 'duplicate' => true, 'id' => $hashes[$sha]]);

    $works = all_works();
    $idx = null;
    foreach ($works as $i => $w) if ($w['id'] === $id) { $idx = $i; break; }
    if ($idx === null) out(['error' => 'Unknown project id.'], 404);
    $w = $works[$idx];
    if (($w['status'] ?? '') !== 'draft') out(['error' => 'This project is live. Add new files in the admin so you can check them first.'], 409);
    try { $s = store_upload($f, $w['title'] ?: 'work'); }
    catch (RuntimeException $ex) { out(['error' => $ex->getMessage()], 415); }

    // Re-read in case another upload landed meanwhile, then append.
    $works = all_works();
    foreach ($works as $i => $x) if ($x['id'] === $id) { $idx = $i; break; }
    $w = $works[$idx];
    $item = $s + ['caption' => clean_str($_POST['caption'] ?? '', 240), 'wide' => false, 'sha256' => $sha];
    $w['gallery'][] = $item;
    if ((!$w['cover'] || !empty($_POST['make_cover'])) && $s['type'] === 'image') $w['cover'] = ['large' => $s['src'], 'thumb' => $s['thumb']];
    $w['updated'] = date('c');
    $works[$idx] = $w;
    write_json('works', $works);
    $hashes[$sha] = $id;
    write_json('hashes', $hashes);
    out(['ok' => true, 'duplicate' => false, 'type' => $s['type'], 'src' => media_url($s['src'])]);
}

if ($a === 'drafts' && $method === 'GET') {
    $all = !empty($_GET['all']);
    $list = [];
    $abs = fn($p) => $p ? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . media_url($p)) : '';
    foreach (all_works() as $w) {
        if (($w['status'] ?? '') !== 'draft') continue;
        if (!$all && empty($w['needs_details'])) continue;
        $list[] = [
            'id' => $w['id'], 'title' => $w['title'], 'client' => $w['client'] ?? '', 'agency' => $w['agency'] ?? '',
            'year' => $w['year'] ?? '', 'category' => $w['category'] ?? '', 'role' => $w['role'] ?? '',
            'disciplines' => $w['disciplines'] ?? [], 'summary' => $w['summary'] ?? '', 'body' => $w['body'] ?? '',
            'source' => $w['source'] ?? '', 'needs_details' => !empty($w['needs_details']),
            'images' => array_values(array_filter(array_map(fn($g) => ($g['type'] ?? 'image') === 'image' ? $abs($g['thumb'] ?? $g['src']) : '', $w['gallery'] ?? []))),
        ];
    }
    out(['ok' => true, 'count' => count($list), 'drafts' => $list]);
}

if ($a === 'update' && $method === 'POST') {
    $in = body_json();
    $works = all_works();
    foreach ($works as $i => $w) {
        if ($w['id'] !== ($in['id'] ?? null)) continue;
        if (($w['status'] ?? '') !== 'draft') out(['error' => 'Only drafts can be changed through the API.'], 409);
        $w = array_merge($w, $fields($in));
        $w['needs_details'] = false;
        $w['ai_suggested'] = !empty($in['ai']);
        if (!empty($in['title'])) $w['slug'] = unique_slug($w['title'], $w['id']);
        $w['updated'] = date('c');
        $works[$i] = $w;
        write_json('works', $works);
        out(['ok' => true, 'id' => $w['id'], 'slug' => $w['slug']]);
    }
    out(['error' => 'Unknown project id.'], 404);
}

out(['error' => 'Unknown action.'], 400);
