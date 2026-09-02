<?php
/**
 * Sorting tags into genres and labels, and tidying them up.
 *
 * Each checkbox carries a hidden field of the same name in front of it, so
 * that an unticked box arrives as "0" rather than as nothing at all. Without
 * it the save could only ever read silence as "not a genre", which is fine
 * while the form lists every tag and wrong the day it lists some of them.
 *
 * The three tidying actions are forms of their own rather than controls
 * inside the big one: a form cannot be nested in another, and each of them
 * leads to a confirmation page anyway.
 *
 * @var list<array{id: int, name: string, kind: string, dropped_at: ?string, book_count: int}> $tags
 * @var int $genreCount
 */
declare(strict_types=1);

$live = array_values(array_filter($tags, static fn (array $t): bool => $t['dropped_at'] === null));
$dropped = array_values(array_filter($tags, static fn (array $t): bool => $t['dropped_at'] !== null));

/* Two orders, because the two are used differently.
 *
 * The checkbox list stays in order of size: that is the order in which the
 * work pays off. A dropdown is used to find one particular name among three
 * hundred and eighty, and for that only the alphabet helps. Folded, so that
 * Ärzte sits with A rather than after Z. */
$byName = $live;
usort(
    $byName,
    static fn (array $a, array $b): int => App\Core\Text::fold($a['name']) <=> App\Core\Text::fold($b['name'])
);
?>
<?= $view->render('partials.admin_nav', ['adminCurrent' => 'tags']) ?>

<div class="page-head">
  <h1><?= e(t('tags.title')) ?></h1>
  <span class="count"><?= e(t('tags.count', [
      'genres' => $formatter->number($genreCount),
      'total'  => $formatter->number(count($live)),
  ])) ?></span>
</div>

<?php if ($error !== ''): ?>
<p class="flash flash--error"><?= e($error) ?></p>
<?php endif; ?>

<div class="tag-tools">
  <form class="panel" method="get" action="/admin/tags/merge">
    <h2><?= e(t('tags.merge.heading')) ?></h2>
    <p class="note" style="margin-top:0"><?= e(t('tags.merge.hint')) ?></p>
    <div class="field">
      <label for="merge-from"><?= e(t('tags.merge.from')) ?></label>
      <select id="merge-from" name="from">
        <?php foreach ($byName as $tag): ?>
        <option value="<?= e((string) $tag['id']) ?>"><?= e($tag['name']) ?> (<?= e($formatter->number((int) $tag['book_count'])) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="merge-into"><?= e(t('tags.merge.into')) ?></label>
      <select id="merge-into" name="into">
        <?php foreach ($byName as $tag): ?>
        <option value="<?= e((string) $tag['id']) ?>"><?= e($tag['name']) ?> (<?= e($formatter->number((int) $tag['book_count'])) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn" type="submit"><?= e(t('tags.merge.do')) ?> &hellip;</button>
  </form>

  <form class="panel" method="get" action="/admin/tags/field">
    <h2><?= e(t('tags.field.heading')) ?></h2>
    <p class="note" style="margin-top:0"><?= e(t('tags.field.hint')) ?></p>
    <div class="field">
      <label for="field-tag"><?= e(t('tags.field.tag')) ?></label>
      <select id="field-tag" name="tag">
        <?php foreach ($byName as $tag): ?>
        <option value="<?= e((string) $tag['id']) ?>"><?= e($tag['name']) ?> (<?= e($formatter->number((int) $tag['book_count'])) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="field-value"><?= e(t('tags.field.value')) ?></label>
      <select id="field-value" name="pair">
        <?php foreach ($fieldValues as $pair): ?>
        <option value="<?= e($pair['field'] . ':' . $pair['value']) ?>"><?= e($pair['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn" type="submit"><?= e(t('tags.field.do')) ?> &hellip;</button>
  </form>
</div>

<form method="post" action="/admin/tags">
  <?= $csrfField ?>

  <h2><?= e(t('tags.sort.heading')) ?></h2>
  <p class="note"><?= e(t('tags.hint')) ?></p>

  <div class="tag-sort-actions">
    <button class="btn btn--primary" type="submit"><?= e(t('common.save')) ?></button>
  </div>

  <ul class="tag-sort">
    <?php foreach ($live as $tag): ?>
    <li>
      <input type="hidden" name="genre[<?= e((string) $tag['id']) ?>]" value="0">
      <input type="checkbox" id="tag-<?= e((string) $tag['id']) ?>"
             name="genre[<?= e((string) $tag['id']) ?>]" value="1"
             <?= $tag['kind'] === 'genre' ? 'checked' : '' ?>>
      <label for="tag-<?= e((string) $tag['id']) ?>">
        <span><?= e($tag['name']) ?></span>
        <span class="n"><?= e($formatter->number((int) $tag['book_count'])) ?></span>
      </label>
      <a class="tag-remove" href="/admin/tags/<?= e((string) $tag['id']) ?>/remove"
         title="<?= e(t('tags.remove')) ?>" aria-label="<?= e(t('tags.remove')) ?>">&times;</a>
    </li>
    <?php endforeach; ?>
  </ul>

  <div class="tag-sort-actions">
    <button class="btn btn--primary" type="submit"><?= e(t('common.save')) ?></button>
  </div>
</form>

<?php if ($dropped !== []): ?>
<h2><?= e(t('tags.dropped.heading')) ?></h2>
<p class="note"><?= e(t('tags.dropped.hint')) ?></p>
<ul class="tag-sort tag-sort--dropped">
  <?php foreach ($dropped as $tag): ?>
  <li>
    <form method="post" action="/admin/tags/<?= e((string) $tag['id']) ?>/restore">
      <?= $csrfField ?>
      <span><?= e($tag['name']) ?></span>
      <span class="n"><?= e($formatter->number((int) $tag['book_count'])) ?></span>
      <button class="link-button" type="submit"><?= e(t('tags.restore')) ?></button>
    </form>
    <a class="tag-purge" href="/admin/tags/<?= e((string) $tag['id']) ?>/purge"><?= e(t('tags.purge')) ?></a>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
