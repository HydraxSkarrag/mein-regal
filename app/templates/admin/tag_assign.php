<?php
/**
 * What reading a file of genres and labels would do, before it does it.
 *
 * In the order somebody checks it: the numbers, then what happens to the
 * names, then what goes, then every book that changes. The button is at the
 * foot, after all of it, and missing when there is nothing to do or when a
 * name is a genre in one row and a label in another.
 *
 * @var array<string,mixed> $plan see App\Content\TagAssignment::plan
 * @var bool   $canApply
 * @var string $fingerprint
 * @var string $contents the file, sent back with the button
 * @var string $notice
 */
declare(strict_types=1);

use App\Repository\TagRepository;

$changed = array_values(array_filter($plan['books'], static fn (array $b): bool => $b['changed']));
$new = array_values(array_filter($plan['names'], static fn (array $n): bool => $n['was'] === null));
$reused = array_values(array_filter($plan['names'], static fn (array $n): bool => $n['was'] !== null));
$kindName = static fn (string $kind): string => t($kind === TagRepository::KIND_GENRE ? 'edit.tags.kind.genre' : 'edit.tags.kind.label');
$list = static fn (array $names): string => $names === [] ? '–' : implode(', ', $names);
?>
<?= $view->render('partials.admin_nav', ['adminCurrent' => 'tags']) ?>

<div class="page-head">
  <h1><?= e(t('tags.assign.title')) ?></h1>
</div>

<?php if ($notice !== ''): ?>
<p class="flash flash--hint"><?= e($notice) ?></p>
<?php endif; ?>

<?php if ($plan['conflicts'] !== []): ?>
<p class="flash flash--error"><?= e(t('tags.assign.conflicts', ['names' => implode(', ', $plan['conflicts'])])) ?></p>
<?php endif; ?>

<div class="panel mb-l">
  <h2><?= e(t('tags.assign.summary')) ?></h2>
  <ul class="assign-summary">
    <li><?= e(t('tags.assign.books.changed', ['count' => $formatter->number(count($changed))])) ?></li>
    <li><?= e(t('tags.assign.books.same', ['count' => $formatter->number(count($plan['books']) - count($changed))])) ?></li>
    <?php if ($plan['outside'] > 0): ?>
    <li><?= e(t('tags.assign.books.outside', ['count' => $formatter->number($plan['outside'])])) ?></li>
    <?php endif; ?>
    <li><?= e(t('tags.assign.names.new', ['count' => $formatter->number(count($new))])) ?></li>
    <li><?= e(t('tags.assign.names.reused', ['count' => $formatter->number(count($reused))])) ?></li>
    <li><?= e(t('tags.assign.dropped', ['count' => $formatter->number(count($plan['drop']))])) ?></li>
    <?php if ($plan['rejected'] !== []): ?>
    <li class="note--danger"><?= e(t('tags.assign.rejected', ['count' => $formatter->number(count($plan['rejected']))])) ?></li>
    <?php endif; ?>
  </ul>
</div>

<?php if ($plan['rejected'] !== []): ?>
<h2><?= e(t('tags.assign.rejected.heading')) ?></h2>
<p class="note"><?= e(t('tags.assign.rejected.hint')) ?></p>
<ul class="assign-list">
  <?php foreach ($plan['rejected'] as $row): ?>
  <li><?= e(t('tags.assign.line', ['line' => $row['line']])) ?>: <?= e($row['title']) ?> <span class="note--danger"><?= e(t($row['reason'])) ?></span></li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>

<h2><?= e(t('tags.assign.names.heading')) ?></h2>
<ul class="assign-list assign-list--columns">
  <?php foreach ($plan['names'] as $name): ?>
  <li>
    <strong><?= e($name['name']) ?></strong>
    <span class="note"><?= e($kindName($name['kind'])) ?> ·
    <?php if ($name['was'] === null): ?>
      <?= e(t('tags.assign.name.new')) ?>
    <?php else: ?>
      <?php
        $notes = [];
        if ($name['was']['dropped']) { $notes[] = t('tags.assign.name.restored'); }
        if ($name['was']['kind'] !== $name['kind']) { $notes[] = t('tags.assign.name.kind', ['from' => $kindName($name['was']['kind'])]); }
        if ($name['was']['name'] !== $name['name']) { $notes[] = t('tags.assign.name.spelling', ['from' => $name['was']['name']]); }
      ?>
      <?= e($notes === [] ? t('tags.assign.name.reused') : implode(', ', $notes)) ?>
    <?php endif; ?></span>
  </li>
  <?php endforeach; ?>
</ul>

<?php if ($plan['drop'] !== []): ?>
<h2><?= e(t('tags.assign.drop.heading')) ?></h2>
<p class="note"><?= e(t('tags.assign.drop.hint')) ?></p>
<ul class="assign-list assign-list--columns">
  <?php foreach ($plan['drop'] as $tag): ?>
  <li><?= e($tag['name']) ?> <span class="n"><?= e($formatter->number($tag['count'])) ?></span></li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($changed !== []): ?>
<h2><?= e(t('tags.assign.books.heading')) ?></h2>
<div class="table-scroll">
  <table class="assign-table">
    <thead>
      <tr>
        <th><?= e(t('tags.assign.col.book')) ?></th>
        <th><?= e(t('tags.assign.col.genres')) ?></th>
        <th><?= e(t('tags.assign.col.labels')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($changed as $book): ?>
      <tr>
        <td><?= e($book['title']) ?></td>
        <?php foreach (['genres', 'labels'] as $column): ?>
        <td>
          <?php if ($book['before'][$column] === $book['after'][$column]): ?>
          <?= e($list($book['after'][$column])) ?>
          <?php else: ?>
          <del><?= e($list($book['before'][$column])) ?></del>
          <ins><?= e($list($book['after'][$column])) ?></ins>
          <?php endif; ?>
        </td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<form method="post" action="/admin/tags/assign" class="confirm-actions mt-l">
  <?= $csrfField ?>
  <input type="hidden" name="fingerprint" value="<?= e($fingerprint) ?>">
  <?php /* The file itself, back to the server with the button. A textarea
           rather than a hidden input, which keeps line breaks as they are. */ ?>
  <textarea name="csv" hidden>
<?= e($contents) ?></textarea>
  <?php if ($canApply): ?>
  <button class="btn btn--primary" type="submit"><?= e(t('tags.assign.do')) ?></button>
  <?php else: ?>
  <p class="note"><?= e(t($plan['conflicts'] !== [] ? 'tags.assign.blocked' : 'tags.assign.nothing')) ?></p>
  <?php endif; ?>
  <a class="btn" href="/admin/tags"><?= e(t('common.cancel')) ?></a>
</form>
