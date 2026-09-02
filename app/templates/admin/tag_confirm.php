<?php
/**
 * The page between meaning to do something and doing it.
 *
 * Always the same shape: what will happen in numbers, what will not happen,
 * and one button. Shared by removing, merging and folding a tag into a field,
 * because three different confirmation screens would be three chances to
 * word a warning badly.
 *
 * @var string              $heading
 * @var string              $warning
 * @var list<string>        $notes
 * @var array<string,string> $hidden
 * @var ?string $listUrl the books this is about, opened in a second tab so
 *                       the confirmation is still there afterwards
 */
declare(strict_types=1);
?>
<?= $view->render('partials.admin_nav', ['adminCurrent' => 'tags']) ?>

<h1><?= e($heading) ?></h1>

<div class="panel">
  <p class="confirm-warning"><?= e($warning) ?></p>
  <?php if ($notes !== []): ?>
  <ul class="note">
    <?php foreach ($notes as $note): ?>
    <li><?= e($note) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <?php if (($listUrl ?? null) !== null): ?>
  <p><a href="<?= e($listUrl) ?>" target="_blank" rel="noopener"><?= e(t('tags.show.books')) ?></a></p>
  <?php endif; ?>

  <form method="post" action="<?= e($action) ?>" class="confirm-actions">
    <?= $csrfField ?>
    <?php foreach ($hidden as $name => $value): ?>
    <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
    <?php endforeach; ?>
    <button class="btn btn--primary" type="submit"><?= e($button) ?></button>
    <a class="btn" href="/admin/tags"><?= e(t('common.cancel')) ?></a>
  </form>
</div>
