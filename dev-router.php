<?php
// Local preview only: `php -S localhost:8000 dev-router.php`. Mirrors the .htaccess rewrites.
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/(data/|tools/|\.|.*\.(json|md|txt)$)~', $p) && $p !== '/robots.txt') { http_response_code(403); exit('Forbidden'); }
if (preg_match('~^/uploads/.*\.php~', $p)) { http_response_code(403); exit; }
if ($p !== '/' && is_file(__DIR__ . $p) && !str_ends_with($p, '.php')) return false;
$routes = ['~^/work/?$~' => 'work.php', '~^/(about|studio)/?$~' => 'about.php', '~^/contact/?$~' => 'contact.php'];
if (preg_match('~^/work/([A-Za-z0-9-]+)/?$~', $p, $m)) { $_GET['slug'] = $m[1]; require __DIR__ . '/project.php'; return; }
foreach ($routes as $re => $f) if (preg_match($re, $p)) { require __DIR__ . "/$f"; return; }
if ($p === '/admin' ) { header('Location: /admin/'); return; }
if ($p === '/' ) { require __DIR__ . '/index.php'; return; }
$f = __DIR__ . (str_ends_with($p, '/') ? $p . 'index.php' : $p);
if (is_file($f) && str_ends_with($f, '.php')) { chdir(dirname($f)); require $f; return; }
require __DIR__ . '/404.php';
