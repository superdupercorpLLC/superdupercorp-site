<?php
if (!defined('ROOT')) require __DIR__ . '/inc/bootstrap.php';
http_response_code(404);
page_head('Not found', '', '');
?>
<section class="pagehead">
  <h1 class="h-mega">404</h1>
  <p class="pagehead__lede">This page wandered off. Maybe it's still sitting in an old hard drive somewhere.</p>
  <p><a class="btn btn--big" href="<?= url('work') ?>">See the work →</a></p>
</section>
<?php page_foot(); ?>
