<?php
/**
 * Sorting tags into genres and labels.
 *
 * One form for all of them, saved in one go: a browser sends only the boxes
 * that are ticked, so a half-saved screen would silently demote everything it
 * did not mention.
 *
 * @var list<array{id: int, name: string, kind: string, book_count: int}> $tags
 * @var int $genreCount
 */
declare(strict_types=1);
?>
<div class="page-head">
  <h1><?= e(t('tags.title')) ?></h1>
  <span class="count"><?= e(t('tags.count', [
      'genres' => $formatter->number($genreCount),
      'total'  => $formatter->number(count($tags)),
  ])) ?></span>
  <span class="count"><a href="/admin"><?= e(t('nav.admin')) ?></a></span>
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
