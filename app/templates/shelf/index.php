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
  <aside class="sidebar">
    <h2><?= e(t('filter.sort')) ?></h2>
    <ul>
      <?php foreach (['recent', 'title', 'year', 'rating', 'read'] as $sort): ?>
      <li><a href="<?= e($urlFor(['sort' => $sort])) ?>"
             aria-current="<?= ($filters['sort'] ?? 'recent') === $sort ? 'true' : 'false' ?>"><?= e(t('sort.' . $sort)) ?></a></li>
      <?php endforeach; ?>
    </ul>

    <?php /*
       * The sidebar shows the biggest few. Making the heading itself a quiet
       * link hid the other 367 just as thoroughly as having no link at all -
       * nothing about a heading suggests it can be clicked. The way out is
       * its own control, with the number on it: it says both that there is
       * more and how much more.
       */ ?>
    <h2 class="sidebar-head">
      <span><?= e(t('filter.genre')) ?></span>
      <a class="facet-all" href="/genres"><?= e(t('facets.all', ['count' => $formatter->number($tagTotal)])) ?></a>
    </h2>
    <ul>
      <?php foreach ($tags as $tag): ?>
      <li><a href="<?= e($urlFor(['tag' => ($filters['tag'] ?? '') === $tag['slug'] ? '' : $tag['slug']])) ?>"
             aria-current="<?= ($filters['tag'] ?? '') === $tag['slug'] ? 'true' : 'false' ?>">
        <span><?= e($tag['name']) ?></span><span class="n"><?= e($formatter->number((int) $tag['book_count'])) ?></span></a></li>
      <?php endforeach; ?>
    </ul>

    <?php if ($labels !== []): ?>
    <h2 class="sidebar-head">
      <span><?= e(t('filter.label')) ?></span>
      <a class="facet-all" href="/labels"><?= e(t('facets.all', ['count' => $formatter->number($labelTotal)])) ?></a>
    </h2>
    <ul>
      <?php foreach ($labels as $tag): ?>
      <li><a href="<?= e($urlFor(['tag' => ($filters['tag'] ?? '') === $tag['slug'] ? '' : $tag['slug']])) ?>"
             aria-current="<?= ($filters['tag'] ?? '') === $tag['slug'] ? 'true' : 'false' ?>">
        <span><?= e($tag['name']) ?></span><span class="n"><?= e($formatter->number((int) $tag['book_count'])) ?></span></a></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <h2 class="sidebar-head">
      <span><?= e(t('filter.author')) ?></span>
      <a class="facet-all" href="/authors"><?= e(t('facets.all', ['count' => $formatter->number($authorTotal)])) ?></a>
    </h2>
    <ul>
      <?php foreach ($topAuthors as $person): ?>
      <li><a href="<?= e($urlFor(['author' => ($filters['author'] ?? '') === $person['name'] ? '' : $person['name']])) ?>"
             aria-current="<?= ($filters['author'] ?? '') === $person['name'] ? 'true' : 'false' ?>">
        <span><?= e($person['name']) ?></span><span class="n"><?= e($formatter->number((int) $person['book_count'])) ?></span></a></li>
      <?php endforeach; ?>
    </ul>

    <h2><?= e(t('filter.review')) ?></h2>
    <ul>
      <li><a href="<?= e($urlFor(['review' => ($filters['review'] ?? '') === 'yes' ? '' : 'yes'])) ?>"
             aria-current="<?= ($filters['review'] ?? '') === 'yes' ? 'true' : 'false' ?>">
        <span><?= e(t('filter.review.yes')) ?></span><span class="n"><?= e($formatter->number($reviewCounts['with'])) ?></span></a></li>
      <li><a href="<?= e($urlFor(['review' => ($filters['review'] ?? '') === 'no' ? '' : 'no'])) ?>"
             aria-current="<?= ($filters['review'] ?? '') === 'no' ? 'true' : 'false' ?>">
        <span><?= e(t('filter.review.no')) ?></span><span class="n"><?= e($formatter->number($reviewCounts['without'])) ?></span></a></li>
    </ul>

    <h2><?= e(t('filter.cover')) ?></h2>
    <ul>
      <li><a href="<?= e($urlFor(['cover' => ($filters['cover'] ?? '') === 'yes' ? '' : 'yes'])) ?>"
             aria-current="<?= ($filters['cover'] ?? '') === 'yes' ? 'true' : 'false' ?>">
        <span><?= e(t('filter.cover.yes')) ?></span><span class="n"><?= e($formatter->number($coverCounts['with'])) ?></span></a></li>
      <li><a href="<?= e($urlFor(['cover' => ($filters['cover'] ?? '') === 'no' ? '' : 'no'])) ?>"
             aria-current="<?= ($filters['cover'] ?? '') === 'no' ? 'true' : 'false' ?>">
        <span><?= e(t('filter.cover.no')) ?></span><span class="n"><?= e($formatter->number($coverCounts['without'])) ?></span></a></li>
    </ul>

    <h2><?= e(t('filter.isbn')) ?></h2>
    <ul>
      <li><a href="<?= e($urlFor(['isbn' => ($filters['isbn'] ?? '') === 'yes' ? '' : 'yes'])) ?>"
             aria-current="<?= ($filters['isbn'] ?? '') === 'yes' ? 'true' : 'false' ?>">
        <span><?= e(t('filter.isbn.yes')) ?></span><span class="n"><?= e($formatter->number($isbnCounts['with'])) ?></span></a></li>
      <li><a href="<?= e($urlFor(['isbn' => ($filters['isbn'] ?? '') === 'no' ? '' : 'no'])) ?>"
             aria-current="<?= ($filters['isbn'] ?? '') === 'no' ? 'true' : 'false' ?>">
        <span><?= e(t('filter.isbn.no')) ?></span><span class="n"><?= e($formatter->number($isbnCounts['without'])) ?></span></a></li>
    </ul>

    <h2><?= e(t('filter.binding')) ?></h2>
    <ul>
      <?php foreach ($bindingCounts as $binding => $count): ?>
        <?php if ($binding === '') { continue; } ?>
      <li><a href="<?= e($urlFor(['binding' => ($filters['binding'] ?? '') === $binding ? '' : $binding])) ?>"
             aria-current="<?= ($filters['binding'] ?? '') === $binding ? 'true' : 'false' ?>">
        <span><?= e(t('binding.' . $binding)) ?></span><span class="n"><?= e($formatter->number($count)) ?></span></a></li>
      <?php endforeach; ?>
    </ul>

    <?php
      /* Language, capped like every other list here. The field is empty for
         books nobody has looked up yet, and an "unknown" entry filtering to
         a thousand books says nothing about them - so only the languages
         that are actually recorded get a row. */
      $languages = array_filter(
          $languageCounts ?? [],
          static fn ($count, $code): bool => $code !== '' && $count > 0,
          ARRAY_FILTER_USE_BOTH
      );
    ?>
    <?php if (count($languages) > 1): ?>
    <h2><?= e(t('filter.language')) ?></h2>
    <ul>
      <?php foreach (array_slice($languages, 0, 10, true) as $code => $count): ?>
      <li><a href="<?= e($urlFor(['language' => ($filters['language'] ?? '') === $code ? '' : $code])) ?>"
             aria-current="<?= ($filters['language'] ?? '') === $code ? 'true' : 'false' ?>">
        <span><?= e(App\Core\Formatter::language((string) $code)) ?></span><span class="n"><?= e($formatter->number($count)) ?></span></a></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if ($hasFilters): ?>
    <p style="margin-top:18px"><a href="/"><?= e(t('filter.reset')) ?></a></p>
    <?php endif; ?>
  </aside>

  <div>
    <?php if ($books === []): ?>
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
    <p class="note" style="text-align:center">
      <?= e(t('shelf.range', [
          'from'  => $formatter->number($offset + 1),
          'to'    => $formatter->number($offset + count($books)),
          'total' => $formatter->number($total),
      ])) ?>
    </p>
    <?php endif; ?>
  </div>
</div>
