<?php
/** 404 and 500 share a shape; only the words differ. */
declare(strict_types=1);
?>
<div class="page-empty">
  <h1><?= e($heading) ?></h1>
  <p class="muted"><?= e($body) ?></p>
  <?php /* The aside goes under the plain sentence rather than instead of it.
           Somebody who has landed here wants to know what happened first;
           the joke is what they read on the way out. Only 404 gets one - a
           500 means something is actually broken, and being funny about that
           is being funny at the reader's expense. */ ?>
  <?php if (($quip ?? '') !== ''): ?>
  <p class="error-quip"><?= e($quip) ?></p>
  <?php endif; ?>
  <p class="mt-l"><a class="btn" href="/"><?= e(t('book.back')) ?></a></p>
</div>
