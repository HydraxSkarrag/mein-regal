<?php
/**
 * Every genre, or everyone, with the number of books behind each.
 *
 * An entry that brings a cover with it is drawn with the picture in front of
 * the name, which only the series index does - a genre is not a thing with a
 * cover and a person is not one either. The other two pages pass none and get
 * the list they always had, out of the same loop.
 *
 * @var string $heading
 * @var array<string, list<array{label: string, count: int, url: string,
 *                               countLabel?: ?string, cover?: ?array<string,mixed>,
 *                               coverBook?: ?array<string,mixed>}>> $groups
 * @var int $total
 */
declare(strict_types=1);

/* Asked once for the page rather than per row: the list carries one shape or
   the other, and a series whose cover is missing still belongs in the same
   column as the ones that have theirs - the stand-in fills that gap, which is
   what it is for. */
$withCovers = false;
foreach ($groups as $entries) {
    foreach ($entries as $entry) {
        if (($entry['coverBook'] ?? null) !== null) {
            $withCovers = true;
            break 2;
        }
    }
}
?>
<div class="page-head">
  <h1><?= e($heading) ?></h1>
  <span class="count"><?= e(t('facets.count', ['count' => $formatter->number($total)])) ?></span>
</div>

<?php if ($groups === []): ?>
<p class="note"><?= e(t('facets.none')) ?></p>
<?php else: ?>
<nav class="alphabet" aria-label="<?= e($heading) ?>">
  <?php foreach (array_keys($groups) as $letter): ?>
  <a href="#letter-<?= e(rawurlencode($letter)) ?>"><?= e($letter) ?></a>
  <?php endforeach; ?>
</nav>

<?php foreach ($groups as $letter => $entries): ?>
<section class="facet-group">
  <h2 id="letter-<?= e(rawurlencode($letter)) ?>"><?= e($letter) ?></h2>
  <ul class="facet-list<?= $withCovers ? ' facet-list--covers' : '' ?>">
    <?php foreach ($entries as $entry): ?>
    <?php $count = ($entry['countLabel'] ?? null) ?? $formatter->number($entry['count']); ?>
    <?php if ($withCovers): ?>
    <li><a href="<?= e($entry['url']) ?>">
      <?= ($entry['coverBook'] ?? null) === null ? '' : $view->render('partials.cover', [
          'book'       => $entry['coverBook'],
          'cover'      => $entry['cover'] ?? null,
          'authorLine' => '',
          'small'      => true,
          'sizes'      => '48px',
      ]) ?>
      <span class="series-text">
        <span class="series-name"><?= e($entry['label']) ?></span>
        <span class="n"><?= e($count) ?></span>
      </span></a></li>
    <?php else: ?>
    <li><a href="<?= e($entry['url']) ?>">
      <span><?= e($entry['label']) ?></span><span class="n"><?= e($count) ?></span></a></li>
    <?php endif; ?>
    <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
<?php endif; ?>
