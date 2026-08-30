<?php
/**
 * The owner's dashboard.
 *
 * Everything the public statistics page deliberately leaves out, plus the
 * part that makes this a working tool rather than a display: a short list of
 * what is still worth doing, each entry linking straight at the books it
 * refers to.
 */
declare(strict_types=1);

$totals = $totals ?? [];
$books = (int) ($totals['books'] ?? 0);
$pct = static fn (int $n): float => $books > 0 ? round($n / $books * 100, 1) : 0.0;
?>
<div class="page-head">
  <h1><?= e(t('nav.admin')) ?></h1>
  <span class="count"><?= e(t('auth.hello', ['name' => $user['display_name'] ?? ''])) ?></span>
  <span class="count"><a href="/stats"><?= e(t('stats.public')) ?></a></span>
  <span class="count"><a href="/admin/data"><?= e(t('maintenance.title')) ?></a></span>
</div>

<div class="metrics">
  <div class="metric">
    <div class="label"><?= e(t('stats.books')) ?></div>
    <div class="value"><?= e($formatter->number($books)) ?></div>
  </div>
  <div class="metric">
    <div class="label"><?= e(t('stats.spend')) ?></div>
    <div class="value"><?= e($formatter->money((float) ($totals['spend'] ?? 0))) ?></div>
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

<div class="stat-grid">
  <div class="panel">
    <h2><?= e(t('stats.todo')) ?></h2>
    <ul class="todo-list">
      <li>
        <span><a href="/?cover=no"><?= e(t('stats.no.cover')) ?></a></span>
        <span class="n"><?= e($formatter->number($todo['no_cover'] ?? 0)) ?>
          <span class="note">· <?= e($formatter->number($pct((int) ($todo['no_cover'] ?? 0)), 1)) ?> %</span></span>
      </li>
      <li>
        <span><a href="/?isbn=no"><?= e(t('stats.no.isbn')) ?></a></span>
        <span class="n"><?= e($formatter->number($todo['no_isbn'] ?? 0)) ?></span>
      </li>
      <li>
        <span><a href="/?status=read&amp;sort=read"><?= e(t('stats.no.rating')) ?></a></span>
        <span class="n"><?= e($formatter->number($todo['no_rating'] ?? 0)) ?></span>
      </li>
    </ul>
    <p class="note"><?= e(t('stats.enrich.note')) ?></p>
  </div>

  <div class="panel">
    <h2><?= e(t('stats.covers')) ?></h2>
    <?php if ($coverSources !== []): ?>
    <?= $view->render('partials.chart_split', [
        'counts'    => array_map('intval', $coverSources),
        'caption'   => t('stats.covers'),
        'formatter' => $formatter,
        'label'     => static fn (string $k): string => match ($k) {
            'own'         => t('cover.own'),
            'vlbtix'      => 'VLB-TIX',
            'google'      => 'Google Books',
            'openlibrary' => 'Open Library',
            default       => $k,
        },
    ]) ?>
    <?php else: ?>
    <p class="note"><?= e(t('stats.covers.none')) ?></p>
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid" style="margin-top:1.5rem">
  <div class="panel">
    <h2><?= e(t('stats.acquired')) ?></h2>
    <?= $view->render('partials.chart_split', [
        'counts'    => $acquisitions,
        'caption'   => t('stats.acquired'),
        'formatter' => $formatter,
        'label'     => static fn (string $k): string => t('acquired.' . $k),
    ]) ?>
    <?php if ($bulkDated > 0): ?>
    <p class="note"><?= e(t('stats.bulk.count', ['count' => $formatter->number($bulkDated)])) ?></p>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2><?= e(t('stats.recent')) ?></h2>
    <ul class="recent">
      <?php foreach ($recentlyAdded as $entry): ?>
      <li>
        <a href="/book/<?= e($entry['slug']) ?>"><?= e($entry['title']) ?></a>
        <div class="when"><?= e($formatter->date(substr((string) $entry['created_at'], 0, 10))) ?></div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

<h2><?= e(t('stats.coverage')) ?></h2>
<p class="note" style="margin-top:0"><?= e(t('stats.coverage.note')) ?></p>
<?php
  $total = max(1, (int) ($coverage['total'] ?? 1));
  $fields = $coverage;
  unset($fields['total']);
  arsort($fields);
  $labels = [
      'isbn13' => t('book.isbn'), 'subtitle' => t('book.subtitle'), 'publisher' => t('book.publisher'),
      'published_year' => t('book.year'), 'page_count' => t('book.pages'),
      'language' => t('book.language'), 'binding' => t('book.binding'), 'price' => t('book.price'),
      'acquisition_type' => t('book.acquired.as'), 'acquired_at' => t('book.acquired'),
      'started_at' => t('book.started'), 'finished_at' => t('book.finished'),
      'rating' => t('book.rating'), 'notes' => t('book.notes'),
  ];
?>
<ul class="bars" style="max-width:560px">
<?php foreach ($fields as $field => $filled): ?>
  <li>
    <div class="row">
      <span><?= e($labels[$field] ?? $field) ?></span>
      <span class="n"><?= e($formatter->number((int) round($filled / $total * 100))) ?> %</span>
    </div>
    <div class="bar"><div class="bar-fill<?= $filled / $total < 0.4 ? ' bar-fill--muted' : '' ?>"
      style="width: <?= round($filled / $total * 100, 1) ?>%"></div></div>
  </li>
<?php endforeach; ?>
</ul>
