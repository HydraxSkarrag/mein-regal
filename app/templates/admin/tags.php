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
 * leads to a confirmation page anyway. Removing a tag does not - see
 * partials/tag_row.php for where its button lives instead.
 *
 * @var list<array{id: int, name: string, kind: string, dropped_at: ?string, book_count: int}> $tags
 * @var string $countLine how many genres of how many tags, counted by the controller
 * @var int $notation how many names still carry a catalogue number
 * @var int $removedId the tag removed a moment ago without a script, or 0
 */
declare(strict_types=1);

$live = array_values(array_filter($tags, static fn (array $t): bool => $t['dropped_at'] === null));
$dropped = array_values(array_filter($tags, static fn (array $t): bool => $t['dropped_at'] !== null));

/* Everything on this screen is looked at by name.
 *
 * The list started out ordered by size, on the theory that the biggest tags
 * are where the work pays off - which is true and useless: with three
 * hundred and eighty entries in three columns, the question is always "where
 * is this one", and only the alphabet answers that. Folded, so that Ärzte
 * sits with A rather than after Z. */
$alphabetical = static fn (array $a, array $b): int
    => App\Core\Text::fold($a['name']) <=> App\Core\Text::fold($b['name']);

/* The tag removed a moment ago, when that happened without a script: the
 * browser came back here scrolled to it. It stands in the list where it was,
 * as the row that takes it back, and is left out of the removed ones below
 * so it is not on the page twice. The select boxes do not offer it. */
$rows = $live;
foreach ($dropped as $index => $tag) {
    if ((int) $tag['id'] === $removedId) {
        $rows[] = $tag;
        unset($dropped[$index]);
    }
}
$dropped = array_values($dropped);

$byName = $live;
usort($byName, $alphabetical);
usort($rows, $alphabetical);
?>
<?= $view->render('partials.admin_nav', ['adminCurrent' => 'tags']) ?>

<div class="page-head">
  <h1><?= e(t('tags.title')) ?></h1>
  <span class="count"><?= e($countLine) ?></span>
</div>

<?php if ($error !== ''): ?>
<p class="flash flash--error"><?= e($error) ?></p>
<?php endif; ?>

<?php if ($notation > 0): ?>
<?php /* Only while there is something to do. This is a repair, not a feature:
         the lookups stopped letting notation through, so on a shelf tidied
         once the section is simply not there.
         
         First of the tools, because it is the one that makes the others'
         lists shorter - merging "59 Belletristik" by hand is work that this
         does for nothing, and four of the nine entries it fixes cannot be
         reached by hand at all. */ ?>
<div class="tag-tools">
  <form class="panel" method="get" action="/admin/tags/tidy">
    <h2><?= e(t('tags.notation.heading')) ?></h2>
    <p class="note mt-0"><?= e(t('tags.notation.hint')) ?></p>
    <p><strong><?= e(t('tags.notation.found', ['count' => $formatter->number($notation)])) ?></strong></p>
    <button class="btn" type="submit"><?= e(t('tags.notation.do')) ?> &hellip;</button>
  </form>
</div>
<?php endif; ?>

<div class="tag-tools">
  <form class="panel" method="get" action="/admin/tags/merge">
    <h2><?= e(t('tags.merge.heading')) ?></h2>
    <p class="note mt-0"><?= e(t('tags.merge.hint')) ?></p>
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
    <p class="note mt-0"><?= e(t('tags.field.hint')) ?></p>
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

<?php /* Its own row, below the two tools, because it is not a tool for one
         tag but a file for the whole shelf - and the way back has to be read
         before the button, which is why the export is linked right here. */ ?>
<form class="panel mb-l" method="post" action="/admin/tags/assign/preview" enctype="multipart/form-data">
  <?= $csrfField ?>
  <h2><?= e(t('tags.assign.heading')) ?></h2>
  <p class="note mt-0"><?= e(t('tags.assign.hint')) ?></p>
  <p class="note"><?= e(t('tags.assign.backup')) ?>
    <a href="/admin/export/full"><?= e(t('maintenance.export.full')) ?></a></p>
  <div class="field">
    <label for="assign-file"><?= e(t('maintenance.import.file')) ?></label>
    <input id="assign-file" type="file" name="csv" accept=".csv,text/csv" required>
  </div>
  <button class="btn" type="submit"><?= e(t('tags.assign.preview')) ?> &hellip;</button>
</form>

<form method="post" action="/admin/tags">
  <?= $csrfField ?>

  <h2><?= e(t('tags.sort.heading')) ?></h2>
  <p class="note"><?= e(t('tags.hint')) ?></p>

  <ul class="tag-sort">
    <?php foreach ($rows as $tag): ?>
    <?= $view->render('partials.tag_row', ['tag' => $tag]) ?>
    <?php endforeach; ?>
  </ul>

  <div class="tag-sort-actions">
    <button class="btn btn--primary" type="submit"><?= e(t('common.save')) ?></button>
  </div>
</form>

<?php /* What every × and every undo in the list above submits. Empty but for
         the token, so a removal carries nothing else along - in particular
         not the genre ticks, which stay on the page unsaved and untouched. */ ?>
<form id="tag-actions" method="post" action="/admin/tags"><?= $csrfField ?></form>

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
