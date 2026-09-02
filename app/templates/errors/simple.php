<?php
/** 404 and 500 share a shape; only the words differ. */
declare(strict_types=1);
?>
<div class="page-empty">
  <h1><?= e($heading) ?></h1>
  <p class="muted"><?= e($body) ?></p>
  <p class="mt-l"><a class="btn" href="/"><?= e(t('book.back')) ?></a></p>
</div>
