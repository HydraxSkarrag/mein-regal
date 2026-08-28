<?php
/** 404 and 500 share a shape; only the words differ. */
declare(strict_types=1);
?>
<div style="text-align:center;padding:48px 16px">
  <h1><?= e($heading) ?></h1>
  <p style="color:var(--muted)"><?= e($body) ?></p>
  <p style="margin-top:24px"><a class="btn" href="/"><?= e(t('book.back')) ?></a></p>
</div>
