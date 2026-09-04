<?php
/**
 * The shelf: a grid of covers.
 *
 * @var list<array<string,mixed>> $books
 * @var array<int,string> $authorLines
 * @var array<int,array<string,mixed>> $covers
 */
declare(strict_types=1);
?>
<div class="page-head">
  <h1><?= e($heading) ?></h1>
  <?php /* A filter can narrow the shelf to one book, and "1 Bücher" reads like a fault. */ ?>
  <span class="count"><?= e($total === 1
      ? t('shelf.count.one')
      : t('shelf.count', ['count' => $formatter->number($total)])) ?></span>
</div>

<form class="searchbar" method="get" action="/" role="search">
  <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5"/></svg>
  <label class="visually-hidden" for="q"><?= e(t('shelf.search')) ?></label>
  <input id="q" type="text" name="q" value="<?= e($filters['search'] ?? '') ?>"
         placeholder="<?= e(t('shelf.search')) ?>" autocomplete="off">
  <?php foreach (['status', 'tag', 'sort'] as $keep): ?>
    <?php if (($filters[$keep] ?? '') !== ''): ?>
  <input type="hidden" name="<?= e($keep) ?>" value="<?= e($filters[$keep]) ?>">
    <?php endif; ?>
  <?php endforeach; ?>
</form>

<div class="chips">
  <a class="chip" href="<?= e($urlFor(['status' => ''])) ?>"
     aria-current="<?= ($filters['status'] ?? '') === '' ? 'true' : 'false' ?>"><?= e(t('shelf.all')) ?></a>
  <?php foreach ($statusCounts as $status => $count): ?>
  <a class="chip" href="<?= e($urlFor(['status' => $status])) ?>"
     aria-current="<?= ($filters['status'] ?? '') === $status ? 'true' : 'false' ?>"><?= e(t('status.' . $status)) ?> <?= e($formatter->number($count)) ?></a>
  <?php endforeach; ?>
</div>

<div class="layout-with-sidebar">
  <?php
    /* Everything the filters need, handed over once. A partial gets what it
       is given and nothing else - which is the whole point of them, and also
       why the first attempt rendered a page that could not call $urlFor. */
    $filterData = compact(
        'filters', 'urlFor', 'hasFilters', 'formatter',
        'tags', 'tagTotal', 'labels', 'labelTotal', 'topAuthors', 'authorTotal',
        'bindingCounts', 'languageCounts', 'languages',
        'coverCounts', 'isbnCounts', 'reviewCounts'
    );
  ?>
  <aside class="sidebar filters">
<?= $view->render('partials.shelf_filters', $filterData) ?>
  </aside>

  <?php /* The same controls on a phone, folded away. The sidebar is beside
           the shelf on a wide screen and simply gone below 900px, which left
           sorting and every facet unreachable there - the shelf had filters
           and the device most used to read it could not touch them. */ ?>
  <details class="filters-drawer filters">
    <summary>
      <span class="filters-drawer-label"><?= e(t('filter.mobile')) ?></span>
      <?php if ($hasFilters): ?><span class="filters-drawer-on"><?= e(t('filter.mobile.on')) ?></span><?php endif; ?>
    </summary>
    <div class="filters-drawer-body">
<?= $view->render('partials.shelf_filters', $filterData) ?>
    </div>
  </details>

  <div>
    <?php if ($books === [] && $shelfIsEmpty): ?>
    <?php /* A new installation is not a search that failed. Telling somebody
             with no books to try a different filter describes a situation
             they are not in and hides the one thing they should do next. */ ?>
    <p class="empty"><?= e(t('shelf.nothing.yet')) ?><br>
      <span class="note"><?= $signedIn
        ? t('shelf.nothing.yet.hint', ['scan' => '<a href="/scan">' . e(t('nav.scan')) . '</a>'])
        : e(t('shelf.nothing.yet.visitor')) ?></span></p>
    <?php elseif ($books === []): ?>
    <p class="empty"><?= e(t('shelf.empty')) ?><br><span class="note"><?= e(t('shelf.empty.hint')) ?></span></p>
    <?php else: ?>
    <ul class="shelf">
      <?php foreach ($books as $book): ?>
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
          <?php
            /* Show whatever the shelf is currently ordered by. Sorting by
               year without the years on show leaves you trusting the order
               rather than reading it. */
            $sortValue = null;
            switch ($filters['sort'] ?? 'recent') {
                case 'year':
                    $sortValue = $book['published_year'] !== null ? (string) $book['published_year'] : null;
                    break;
                case 'rating':
                    $parts = App\Core\Formatter::stars($book['rating']);
                    $sortValue = $parts === null
                        ? null
                        : App\Core\Formatter::starsText($book['rating']) . ' ' . $parts['text'];
                    break;
                case 'read':
                    $sortValue = $book['finished_at'] !== null ? $formatter->date($book['finished_at']) : null;
                    break;
                case 'recent':
                    $sortValue = $formatter->date(substr((string) $book['created_at'], 0, 10));
                    break;
                case 'acquired':
                    $sortValue = $book['acquired_at'] !== null ? $formatter->date($book['acquired_at']) : null;
                    break;
            }
          ?>
          <?php if ($sortValue !== null && $sortValue !== ''): ?>
          <p class="book-sortvalue<?= ($filters['sort'] ?? '') === 'rating' ? ' book-sortvalue--stars' : '' ?>"><?= e($sortValue) ?></p>
          <?php endif; ?>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($pages > 1): ?>
    <nav class="pager" aria-label="<?= e(t('page.nav')) ?>">
      <?php if ($page > 1): ?>
      <a class="pager-step" href="<?= e($pageUrl($page - 1)) ?>" rel="prev">&lsaquo; <?= e(t('page.previous')) ?></a>
      <?php else: ?>
      <span class="pager-step is-off">&lsaquo; <?= e(t('page.previous')) ?></span>
      <?php endif; ?>

      <?php foreach ($pageNumbers as $number): ?>
        <?php if ($number === null): ?>
      <span class="pager-gap" aria-hidden="true">…</span>
        <?php elseif ($number === $page): ?>
      <span class="pager-page is-here" aria-current="page"><?= e($formatter->number($number)) ?></span>
        <?php else: ?>
      <a class="pager-page" href="<?= e($pageUrl($number)) ?>"
         aria-label="<?= e(t('page.go', ['number' => $number])) ?>"><?= e($formatter->number($number)) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>

      <?php if ($page < $pages): ?>
      <a class="pager-step" href="<?= e($pageUrl($page + 1)) ?>" rel="next"><?= e(t('page.next')) ?> &rsaquo;</a>
      <?php else: ?>
      <span class="pager-step is-off"><?= e(t('page.next')) ?> &rsaquo;</span>
      <?php endif; ?>
    </nav>
    <?php endif; ?>
    <p class="note center">
      <?= e(t('shelf.range', [
          'from'  => $formatter->number($offset + 1),
          'to'    => $formatter->number($offset + count($books)),
          'total' => $formatter->number($total),
      ])) ?>
    </p>
    <?php endif; ?>
  </div>
</div>
