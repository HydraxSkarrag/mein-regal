<?php
/**
 * Sorting tags into genres and labels.
 *
 * Each checkbox carries a hidden field of the same name in front of it, so
 * that an unticked box arrives as "0" rather than as nothing at all. Without
 * it the save could only ever read silence as "not a genre", which is fine
 * while the form lists every tag and wrong the day it lists some of them.
 *
 * @var list<array{id: int, name: string, kind: string, book_count: int}> $tags
 * @var int $genreCount
 */
declare(strict_types=1);
?>
<?= $view->render('partials.admin_nav', ['adminCurrent' => 'tags']) ?>

<div class="page-head">
  <h1><?= e(t('tags.title')) ?></h1>
  <span class="count"><?= e(t('tags.count', [
      'genres' => $formatter->number($genreCount),
      'total'  => $formatter->number(count($tags)),
  ])) ?></span>
</div>

<?php if ($error !== ''): ?>
<p class="flash flash--error"><?= e($error) ?></p>
<?php endif; ?>

<p class="note"><?= e(t('tags.hint')) ?></p>

<form method="post" action="/admin/tags">
  <?= $csrfField ?>

  <div class="tag-sort-actions">
    <button class="btn btn--primary" type="submit"><?= e(t('common.save')) ?></button>
  </div>

  <ul class="tag-sort">
    <?php foreach ($tags as $tag): ?>
    <li>
      <input type="hidden" name="genre[<?= e((string) $tag['id']) ?>]" value="0">
      <input type="checkbox" id="tag-<?= e((string) $tag['id']) ?>"
             name="genre[<?= e((string) $tag['id']) ?>]" value="1"
             <?= $tag['kind'] === 'genre' ? 'checked' : '' ?>>
      <label for="tag-<?= e((string) $tag['id']) ?>">
        <span><?= e($tag['name']) ?></span>
        <span class="n"><?= e($formatter->number((int) $tag['book_count'])) ?></span>
      </label>
    </li>
    <?php endforeach; ?>
  </ul>

  <div class="tag-sort-actions">
    <button class="btn btn--primary" type="submit"><?= e(t('common.save')) ?></button>
  </div>
</form>
