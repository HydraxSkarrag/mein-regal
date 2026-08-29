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
  <span class="count"><?= e(t('shelf.count', ['count' => $formatter->number($total)])) ?></span>
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

    <h2><?= e(t('filter.genre')) ?></h2>
    <ul>
      <?php foreach ($tags as $tag): ?>
      <li><a href="<?= e($urlFor(['tag' => ($filters['tag'] ?? '') === $tag['slug'] ? '' : $tag['slug']])) ?>"
             aria-current="<?= ($filters['tag'] ?? '') === $tag['slug'] ? 'true' : 'false' ?>">
        <span><?= e($tag['name']) ?></span><span class="n"><?= e($formatter->number((int) $tag['book_count'])) ?></span></a></li>
      <?php endforeach; ?>
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

    <h2><?= e(t('filter.binding')) ?></h2>
    <ul>
      <?php foreach ($bindingCounts as $binding => $count): ?>
        <?php if ($binding === '') { continue; } ?>
      <li><a href="<?= e($urlFor(['binding' => ($filters['binding'] ?? '') === $binding ? '' : $binding])) ?>"
             aria-current="<?= ($filters['binding'] ?? '') === $binding ? 'true' : 'false' ?>">
        <span><?= e(t('binding.' . $binding)) ?></span><span class="n"><?= e($formatter->number($count)) ?></span></a></li>
      <?php endforeach; ?>
    </ul>

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
        <a href="/buch/<?= e($book['slug']) ?>">
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
                    $sortValue = $book['rating'] !== null
                        ? str_repeat('★', (int) $book['rating']) . str_repeat('☆', 5 - (int) $book['rating'])
                        : null;
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

    <?php if ($nextUrl !== null): ?>
    <p style="text-align:center;margin-top:28px">
      <a class="btn" href="<?= e($nextUrl) ?>"><?= e(t('shelf.more')) ?></a>
    </p>
    <?php endif; ?>
    <p class="note" style="text-align:center">
      <?= e(t('shelf.showing', ['shown' => $formatter->number(count($books) + $offset), 'total' => $formatter->number($total)])) ?>
    </p>
    <?php endif; ?>
  </div>
</div>
