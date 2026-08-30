<?php
/**
 * What this software is, and where it came from.
 *
 * Not stored in the database and not editable. It describes the application
 * rather than the collection, so it should read the same on every
 * installation - and stay right without anyone remembering to maintain it.
 *
 * The wording lives in the translation files, which is what makes it exist in
 * both languages at once instead of only in whichever one was written first.
 */
declare(strict_types=1);
?>
<h1><?= e(t('project.title')) ?></h1>

<p class="lead"><?= e(t('project.lead')) ?></p>

<div class="prose">
  <p><?= e(t('project.what')) ?></p>

  <h2><?= e(t('project.origin.heading')) ?></h2>
  <p>
    <?= t('project.origin', [
      'blog' => '<a href="' . e($originUrl) . '" rel="noopener">' . e($originName) . '</a>',
    ]) ?>
  </p>

  <h2><?= e(t('project.how.heading')) ?></h2>
  <ul>
    <li><?= e(t('project.how.scan')) ?></li>
    <li><?= e(t('project.how.sources')) ?></li>
    <li><?= e(t('project.how.covers')) ?></li>
    <li><?= e(t('project.how.export')) ?></li>
  </ul>

  <h2><?= e(t('project.self.heading')) ?></h2>
  <p><?= e(t('project.self')) ?></p>
  <p>
    <a class="btn btn--primary" href="<?= e($repository) ?>" rel="noopener"><?= e(t('project.repository')) ?></a>
  </p>
  <p class="note"><?= e(t('project.licence')) ?></p>
</div>
