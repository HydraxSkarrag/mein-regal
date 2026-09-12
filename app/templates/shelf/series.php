<?php
/**
 * One series, in volume order, with the gaps named.
 *
 * A grid of the six volumes somebody owns looks complete. "Band 4 fehlt" is
 * the sentence they came for, and it is the only thing this page does that
 * the shelf filtered by series would not.
 *
 * @var array{id: int, name: string, slug: string, total: ?int, note: ?string} $series
 * @var list<array<string,mixed>> $volumes
 * @var list<int>                 $gaps
 */
declare(strict_types=1);

use App\Core\Formatter;
use App\Repository\SeriesRepository;
?>
<div class="page-head">
  <h1><?= e($series['name']) ?></h1>
  <?php /* Volumes, not books: a Sammelband is one of the second and several
           of the first, and the word next to the number is "Bände". */ ?>
  <?php $owned = SeriesRepository::countVolumes($volumes); ?>
  <span class="count"><?= e($series['total'] !== null
      ? t('series.owned.of', ['owned' => $owned, 'total' => $series['total']])
      : t('series.owned', ['owned' => $owned])) ?></span>
</div>

<?php if (($series['note'] ?? '') !== ''): ?>
<?php /* "gezählt wie bei Audible, mit den Novellen" - the sentence that stops
         the same question being answered differently next year. The
         catalogue itself does not agree: the same Sanderson novel is volume 7
         in the Heyne records and 8 in the audio ones. */ ?>
<p class="note"><?= e($series['note']) ?></p>
<?php endif; ?>

<?php if ($gaps !== []): ?>
<p class="series-gaps">
  <?= e(count($gaps) === 1
      ? t('series.gap.one', ['number' => $gaps[0]])
      : t('series.gap.many', ['numbers' => implode(', ', $gaps)])) ?>
</p>
<?php endif; ?>

<?php if ($volumes === []): ?>
<p class="note"><?= e(t('series.empty')) ?></p>
<?php else: ?>
<ul class="shelf">
  <?php foreach ($volumes as $book): ?>
  <li class="book">
    <a href="/book/<?= e($book['slug']) ?>">
      <?= $view->render('partials.cover', [
          'book'       => $book,
          'cover'      => $covers[(int) $book['id']] ?? null,
          'authorLine' => $authorLines[(int) $book['id']] ?? '',
          'small'      => true,
      ]) ?>
      <?php if ($book['reading_status'] === 'unread'): ?>
      <span class="badge-unread" title="<?= e(t('status.unread')) ?>"></span>
      <?php endif; ?>
      <p class="book-title"><?= e($book['title']) ?></p>
      <?php if (($authorLines[(int) $book['id']] ?? '') !== ''): ?>
      <p class="book-author"><?= e($authorLines[(int) $book['id']]) ?></p>
      <?php endif; ?>
      <?php /* The volume number, because that is what this page is ordered
               by and an order you cannot read is an order you have to
               trust. */ ?>
      <p class="book-sortvalue">
        <?= e($book['series_index'] !== null
            ? t('book.series.only', [
                'number' => Formatter::volume($book['series_index'], $book['series_index_end'] ?? null),
              ])
            : t('series.unnumbered')) ?>
      </p>
    </a>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php /* Back and edit at the foot, the same as the book page: above the
         heading they were the first thing read on a page about one series -
         two ways out, before the series itself.
         
         A button saying "Alle Reihen" used to stand here as well. While the
         bar was at the head of the page the two were at opposite ends and
         each had its moment; moved down, they became the same link twice in
         eleven pixels. The bar stays, because it is the shape the book page
         has and it carries the way to the edit page with it.
         
         Editing stays a link in this row rather than a fold. Folding away is
         what this shelf does for deleting a book and for the filters on a
         phone, and neither of those is "edit this". */ ?>
<p class="detail-actions">
  <a href="/series">&larr; <?= e(t('series.title')) ?></a>
  <?php if ($signedIn): ?>
  <a href="/reihe/<?= e($series['slug']) ?>/edit"><?= e(t('series.edit')) ?></a>
  <?php endif; ?>
</p>
