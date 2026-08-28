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
<?= $view->render('partials.chart_columns', [
    'series'    => $publishedYears,
    'caption'   => t('stats.published'),
    'formatter' => $formatter,
]) ?>
<?php endif; ?>

<div class="stat-grid" style="margin-top:2rem">
  <div>
    <h2 style="margin-top:0"><?= e(t('stats.status')) ?></h2>
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
    <h2 style="margin-top:0"><?= e(t('stats.genres')) ?></h2>
    <?= $view->render('partials.barlist', [
        'counts'    => array_column($genres, 'book_count', 'name'),
        'formatter' => $formatter,
    ]) ?>

    <?php if ($ratings !== []): ?>
    <h2><?= e(t('stats.ratings')) ?></h2>
    <?php
      $byStars = [];
      foreach ([5, 4, 3, 2, 1] as $stars) {
          if (isset($ratings[(string) $stars])) {
              $byStars[str_repeat('★', $stars)] = $ratings[(string) $stars];
          }
      }
    ?>
    <?= $view->render('partials.barlist', ['counts' => $byStars, 'formatter' => $formatter]) ?>
    <p class="note"><?= e(t('stats.rated.note', [
        'rated' => $formatter->number(array_sum($ratings)),
        'total' => $formatter->number($books),
    ])) ?></p>
    <?php endif; ?>
  </div>
</div>
