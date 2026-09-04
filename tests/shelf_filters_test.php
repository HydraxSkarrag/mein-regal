<?php
/**
 * The facets that know when to leave.
 *
 * "Mit Cover 3.042 / Ohne Cover 0" is not a filter. It is a heading, two rows
 * and a click that changes nothing, taking up space in a sidebar that is
 * meant to hold ten controls at most - and on a phone, in a drawer where
 * every row costs a scroll. Both yes/no facets get there on their own: the
 * covers arrive over a few nights, the last missing ISBN gets typed in one
 * afternoon.
 *
 * The one thing that must not happen is a filter disappearing while somebody
 * is standing in it, which would leave a short shelf and nothing on screen
 * explaining why.
 */
declare(strict_types=1);

use App\Core\Formatter;
use App\Core\View;

Assert::group('Shelf filters: a facet that cannot divide the shelf');

$render = static function (array $coverCounts, array $isbnCounts, array $filters = []): string {
    $view = new View(PROJECT_ROOT . '/app/templates');

    return $view->render('partials.shelf_filters', [
        'filters'    => $filters,
        'urlFor'     => static fn (array $changes): string => '/?' . http_build_query($changes),
        'hasFilters' => $filters !== [],
        'formatter'  => new Formatter('de'),
        'tags'       => [],
        'tagTotal'   => 0,
        'labels'     => [],
        'labelTotal' => 0,
        'topAuthors' => [],
        'authorTotal' => 0,
        'languageCounts' => [],
        'languages'  => [],
        'coverCounts' => $coverCounts,
        'isbnCounts'  => $isbnCounts,
        'reviewCounts' => ['with' => 5, 'without' => 5],
    ]);
};

$mixed = $render(['with' => 1496, 'without' => 1546], ['with' => 2960, 'without' => 82]);
Assert::true('while some books lack a cover, the cover facet is there', str_contains($mixed, t('filter.cover')));
Assert::true('and the ISBN facet too', str_contains($mixed, t('filter.isbn')));

$done = $render(['with' => 3042, 'without' => 0], ['with' => 3042, 'without' => 0]);
Assert::true('once every book has a cover it goes', !str_contains($done, t('filter.cover')));
Assert::true('and so does the ISBN one', !str_contains($done, t('filter.isbn')));

// A brand-new shelf is the same situation from the other end, and the answer
// is the same: nothing to divide, nothing to offer.
$empty = $render(['with' => 0, 'without' => 0], ['with' => 0, 'without' => 0]);
Assert::true('an empty shelf offers neither', !str_contains($empty, t('filter.cover')));

/* Standing in a filter is the exception. Someone can be looking at ?cover=no
 * when the last cover arrives - from the nightly job, or from their own phone
 * in the next room. Taking the control away then leaves a shelf that is short
 * for a reason nothing on the page states. */
$standing = $render(['with' => 3042, 'without' => 0], ['with' => 3042, 'without' => 0], ['cover' => 'no']);
Assert::true('a filter in use stays on screen', str_contains($standing, t('filter.cover')));
Assert::true('but only that one', !str_contains($standing, t('filter.isbn')));

Assert::group('Shelf filters: binding is no longer a way in');

/* Whether a book arrived as a hardback, a paperback or a file is a fact about
 * the object rather than about the reading, and nobody browsing a shelf goes
 * looking for the paperbacks. It is still on the book and still in the
 * statistics - it is just not a facet. */
Assert::true('the binding facet is gone', !str_contains($mixed, t('filter.binding')));
Assert::true('and sorting is still the first thing offered', str_contains($mixed, t('filter.sort')));
