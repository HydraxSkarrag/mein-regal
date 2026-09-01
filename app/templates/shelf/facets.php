<?php
/**
 * Every genre, or everyone, with the number of books behind each.
 *
 * @var string $heading
 * @var array<string, list<array{label: string, count: int, url: string}>> $groups
 * @var int $total
 */
declare(strict_types=1);
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
  <ul class="facet-list">
    <?php foreach ($entries as $entry): ?>
    <li><a href="<?= e($entry['url']) ?>">
      <span><?= e($entry['label']) ?></span><span class="n"><?= e($formatter->number($entry['count'])) ?></span></a></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
<?php endif; ?>
