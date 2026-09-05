<?php
/**
 * Sorting and the facets, for the sidebar and for the drawer.
 *
 * The same markup in both places, because it is the same set of controls -
 * on a wide screen beside the shelf, on a phone folded into a <details> above
 * it. Rendered twice rather than moved by CSS: a closed <details> hides its
 * contents through user-agent rules that no two engines agree on, and the one
 * engine that has to be right here is whichever one is on the phone.
 *
 * Which of the two is on screen is settled by the media query alone, so the
 * hidden one is out of the accessibility tree as well.
 */
declare(strict_types=1);

/* A facet that cannot divide the shelf is not a filter, it is a fact.
 *
 * "With cover 3.042 / without 0" is a heading, two rows and a click that
 * changes nothing - and all three yes/no facets get there on their own: the
 * covers arrive over a few nights, the last ISBN gets typed in one afternoon,
 * and a shelf whose owner keeps no review links never had that one to begin
 * with. So they show themselves only while both halves exist, and quietly
 * stop taking up room once they do not.
 *
 * Reviews are the case that shows why this is the right rule rather than a
 * setting. review_url is an ordinary field on the edit page, so anyone can
 * paste a link by hand whether or not a blog is configured - keying the facet
 * on that configuration would hide a working filter from somebody who links
 * their reviews somewhere else. The counts already know.
 *
 * The exception is a filter somebody is standing in. Hiding the control that
 * is currently narrowing the shelf would leave them looking at a short list
 * with no way to see what did it.
 */
$splits = static function (array $counts, string $active): bool {
    return ($counts['with'] > 0 && $counts['without'] > 0) || $active !== '';
};
?>
  <h2><?= e(t('filter.sort')) ?></h2>
  <ul>
    <?php foreach (App\Repository\BookRepository::sorts() as $sort): ?>
      <?php
        /* Clicking the sort you are already on turns it round. Picking a
           different one starts from its own natural direction - newest
           first for a date, A to Z for a title - because that is what
           somebody means when they pick it without saying more. */
        $active = ($filters['sort'] ?? 'recent') === $sort;
        $natural = App\Repository\BookRepository::naturalDirection($sort);
        $current = $active && ($filters['dir'] ?? '') !== '' ? $filters['dir'] : $natural;
        $next = $active ? ($current === 'asc' ? 'desc' : 'asc') : '';
      ?>
    <li><a href="<?= e($urlFor(['sort' => $sort, 'dir' => $next])) ?>"
           aria-current="<?= $active ? 'true' : 'false' ?>"
           title="<?= e(t($active ? ('sort.turn.' . ($current === 'asc' ? 'desc' : 'asc')) : 'sort.by')) ?>">
      <?= e(t('sort.' . $sort)) ?><?php if ($active): ?><span class="sort-dir" aria-hidden="true"><?= $current === 'asc' ? '↑' : '↓' ?></span><?php endif; ?></a></li>
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

  <?php if ($splits($reviewCounts, (string) ($filters['review'] ?? ''))): ?>
  <h2><?= e(t('filter.review')) ?></h2>
  <ul>
    <li><a href="<?= e($urlFor(['review' => ($filters['review'] ?? '') === 'yes' ? '' : 'yes'])) ?>"
           aria-current="<?= ($filters['review'] ?? '') === 'yes' ? 'true' : 'false' ?>">
      <span><?= e(t('filter.review.yes')) ?></span><span class="n"><?= e($formatter->number($reviewCounts['with'])) ?></span></a></li>
    <li><a href="<?= e($urlFor(['review' => ($filters['review'] ?? '') === 'no' ? '' : 'no'])) ?>"
           aria-current="<?= ($filters['review'] ?? '') === 'no' ? 'true' : 'false' ?>">
      <span><?= e(t('filter.review.no')) ?></span><span class="n"><?= e($formatter->number($reviewCounts['without'])) ?></span></a></li>
  </ul>
  <?php endif; ?>

  <?php if ($splits($coverCounts, (string) ($filters['cover'] ?? ''))): ?>
  <h2><?= e(t('filter.cover')) ?></h2>
  <ul>
    <li><a href="<?= e($urlFor(['cover' => ($filters['cover'] ?? '') === 'yes' ? '' : 'yes'])) ?>"
           aria-current="<?= ($filters['cover'] ?? '') === 'yes' ? 'true' : 'false' ?>">
      <span><?= e(t('filter.cover.yes')) ?></span><span class="n"><?= e($formatter->number($coverCounts['with'])) ?></span></a></li>
    <li><a href="<?= e($urlFor(['cover' => ($filters['cover'] ?? '') === 'no' ? '' : 'no'])) ?>"
           aria-current="<?= ($filters['cover'] ?? '') === 'no' ? 'true' : 'false' ?>">
      <span><?= e(t('filter.cover.no')) ?></span><span class="n"><?= e($formatter->number($coverCounts['without'])) ?></span></a></li>
  </ul>
  <?php endif; ?>

  <?php if ($splits($isbnCounts, (string) ($filters['isbn'] ?? ''))): ?>
  <h2><?= e(t('filter.isbn')) ?></h2>
  <ul>
    <li><a href="<?= e($urlFor(['isbn' => ($filters['isbn'] ?? '') === 'yes' ? '' : 'yes'])) ?>"
           aria-current="<?= ($filters['isbn'] ?? '') === 'yes' ? 'true' : 'false' ?>">
      <span><?= e(t('filter.isbn.yes')) ?></span><span class="n"><?= e($formatter->number($isbnCounts['with'])) ?></span></a></li>
    <li><a href="<?= e($urlFor(['isbn' => ($filters['isbn'] ?? '') === 'no' ? '' : 'no'])) ?>"
           aria-current="<?= ($filters['isbn'] ?? '') === 'no' ? 'true' : 'false' ?>">
      <span><?= e(t('filter.isbn.no')) ?></span><span class="n"><?= e($formatter->number($isbnCounts['without'])) ?></span></a></li>
  </ul>
  <?php endif; ?>

  <?php /* Binding used to have a facet here. Whether a book arrived as a
           hardback, a paperback or a file is a fact about the object and not
           about the reading, and nobody browsing a shelf goes looking for the
           paperbacks. It is still on the book, still on the edit page and
           still counted in the statistics - it just is not a way in. */ ?>

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
  <p class="mt-s"><a href="/"><?= e(t('filter.reset')) ?></a></p>
  <?php endif; ?>
