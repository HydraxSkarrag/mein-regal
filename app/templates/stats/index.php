<?php
/**
 * Public statistics.
 *
 * The same for everyone, signed in or not. Anything that belongs to the owner
 * alone - what the books cost, where they came from, how complete the records
 * are - lives on the dashboard instead. A page that quietly grows extra
 * sections when you are signed in leaves you unable to tell a missing number
 * from a hidden one.
 */
declare(strict_types=1);

$totals = $totals ?? [];
$books = (int) ($totals['books'] ?? 0);
?>
<div class="page-head"><h1><?= e(t('stats.title')) ?></h1></div>

<div class="metrics">
  <div class="metric">
    <div class="label"><?= e(t('stats.books')) ?></div>
    <div class="value"><?= e($formatter->number($books)) ?></div>
  </div>
  <div class="metric">
    <div class="label"><?= e(t('stats.pages')) ?></div>
    <div class="value"><?= e($formatter->number((int) ($totals['pages'] ?? 0))) ?></div>
  </div>
  <div class="metric">
    <div class="label"><?= e(t('stats.authors')) ?></div>
    <div class="value"><?= e($formatter->number($authorCount)) ?></div>
  </div>
</div>

<?php if ($finishedYears !== []): ?>
<h2><?= e(t('stats.per.year')) ?></h2>
<?= $view->render('partials.chart_columns', [
    'series'      => $finishedYears,
    'caption'     => t('stats.per.year'),
    'formatter'   => $formatter,
    'partialLast' => array_key_last($finishedYears) === (int) date('Y'),
    'note'        => array_key_last($finishedYears) === (int) date('Y') ? t('stats.year.running') : '',
]) ?>
<?php endif; ?>

<?php if ($publishedYears !== []): ?>
<h2><?= e(t('stats.published')) ?></h2>
<?php /* The one number on this page that is regularly misread. An ISBN names
         an edition, so every source the shelf asks answers with the year that
         edition was printed - Erdsee reads 2020 for a book written in 1968.
         The chart is right and the reading is wrong, which is a caption's
         job to fix. */ ?>
<p class="note"><?= e(t('stats.published.note')) ?></p>
<?= $view->render('partials.chart_columns', [
    'series'    => $publishedYears,
    'caption'   => t('stats.published'),
    'formatter' => $formatter,
]) ?>
<?php endif; ?>

<div class="stat-grid mt-l">
  <div>
    <h2 class="mt-0"><?= e(t('stats.status')) ?></h2>
    <?= $view->render('partials.chart_split', [
        'counts'    => $statuses,
        'caption'   => t('stats.status'),
        'formatter' => $formatter,
        'label'     => static fn (string $k): string => t('status.' . $k),
    ]) ?>

    <h2><?= e(t('stats.binding')) ?></h2>
    <?= $view->render('partials.chart_split', [
        'counts'    => $bindings,
        'caption'   => t('stats.binding'),
        'formatter' => $formatter,
        'label'     => static fn (string $k): string => t('binding.' . $k),
    ]) ?>
  </div>

  <div>
    <h2 class="mt-0"><?= e(t('stats.genres')) ?></h2>
    <?= $view->render('partials.barlist', [
        'counts'    => array_column($genres, 'book_count', 'name'),
        'formatter' => $formatter,
    ]) ?>

    <?php if ($ratings !== []): ?>
    <h2><?= e(t('stats.ratings')) ?></h2>
    <?php
      /* Every rating that occurs, not the five whole ones.
       *
       * The list used to be [5, 4, 3, 2, 1] and looked up each as a string,
       * so a book rated three and a half was in neither bucket and simply
       * left the chart - the bars no longer added up to the number of rated
       * books, and nothing said so. The column has held halves since it was
       * made, and the edit page has offered them just as long.
       *
       * Sorted downwards, so the best is at the top where it was. */
      $byStars = [];
      $steps = array_keys($ratings);
      // Sorted by value, keyed by the string the database gave: casting to a
      // float and back would turn "4.0" into "4" and lose the row.
      usort($steps, static fn ($a, $b): int => (float) $b <=> (float) $a);
      foreach ($steps as $step) {
          $byStars[(string) $step] = $ratings[$step];
      }
    ?>
    <?= $view->render('partials.barlist', [
        'counts'    => $byStars,
        'formatter' => $formatter,
        /* Drawn, not written: half a star is a span, which is the same
           partial the shelf and the book page use. */
        'label'     => static fn (string $key): string =>
            $view->render('partials.stars', ['rating' => (float) $key, 'withEmpty' => false]),
        'labelIsMarkup' => true,
    ]) ?>
    <p class="note"><?= e(t('stats.rated.note', [
        'rated' => $formatter->number(array_sum($ratings)),
        'total' => $formatter->number($books),
    ])) ?></p>
    <?php endif; ?>
  </div>
</div>
