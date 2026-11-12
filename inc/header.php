<?php
$name   = setting('studio_name', 'SUPER DUPER CORP');
$title  = $GLOBALS['__title'] ? $GLOBALS['__title'] . ' — ' . $name : $name . ' — ' . setting('tagline');
$desc   = $GLOBALS['__desc'] ?: setting('meta_description', setting('tagline'));
$active = $GLOBALS['__active'];
$nav = ['work' => 'Work', 'about' => 'Studio', 'contact' => 'Contact'];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<?php if (!empty($GLOBALS['__og_image'])): ?><meta property="og:image" content="<?= e($GLOBALS['__og_image']) ?>"><?php endif; ?>
<meta name="theme-color" content="#0d0d0d">
<link rel="icon" href="<?= url('assets/favicon.svg') ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/site.css') ?>?v=1">
</head>
<body class="page-<?= e($active ?: 'home') ?>">
<a class="skip" href="#main">Skip to content</a>
<header class="bar">
  <a class="bar__mark" href="<?= url() ?>" aria-label="<?= e($name) ?> home">
    <span class="dot"></span><?= e($name) ?>
  </a>
  <button class="bar__toggle" aria-expanded="false" aria-controls="nav">Menu</button>
  <nav id="nav" class="bar__nav">
    <?php foreach ($nav as $k => $label): ?>
      <a href="<?= url($k) ?>"<?= $active === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
</header>
<main id="main">
