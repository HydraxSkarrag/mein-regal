<?php
/**
 * A book by hand: the title, and then the ordinary edit page.
 *
 * One field, because a book cannot do without a title and can do without
 * everything else for a while. The next screen is the full form, which
 * already knows about contributors, tags, ratings and the rest - so this asks
 * the one question that has to be answered and then gets out of the way.
 *
 * @var ?string $error
 * @var string  $title
 * @var string  $csrfField
 */
declare(strict_types=1);
?>
<div class="page-head">
  <h1><?= e(t('new.title')) ?></h1>
</div>

<div class="card">
  <p class="note mt-0"><?= e(t('new.hint')) ?></p>

  <?php if ($error !== null): ?>
  <p class="form-error" role="alert"><?= e($error) ?></p>
  <?php endif; ?>

  <form method="post" action="/book/new">
    <?= $csrfField ?>
    <div class="field mb-s">
      <label for="title"><?= e(t('book.title')) ?></label>
      <?php /* autofocus: this page exists to be typed into, and it is reached
               by somebody who already knows what they are going to type. */ ?>
      <input id="title" type="text" name="title" value="<?= e($title) ?>"
             maxlength="500" autocomplete="off" required autofocus>
    </div>
    <div class="edit-actions">
      <button class="btn btn--primary" type="submit"><?= e(t('new.create')) ?></button>
      <a class="btn" href="/scan"><?= e(t('common.cancel')) ?></a>
    </div>
  </form>
</div>
