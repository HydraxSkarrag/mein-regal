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

require_once __DIR__ . '/support/SqliteSchema.php';

Assert::group('Shelf filters: a facet that cannot divide the shelf');

$render = static function (
    array $coverCounts,
    array $isbnCounts,
    array $filters = [],
    array $reviewCounts = ['with' => 5, 'without' => 5]
): string {
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
        'reviewCounts' => $reviewCounts,
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

Assert::group('Shelf filters: reviews are the same question');

/* Reported from a second installation - one book, no review link, and a
 * "Rezension" heading over "Mit 0 / Ohne 1". The rule existed; it had been
 * applied to two of the three yes/no facets and not to this one.
 *
 * It is deliberately the counts that decide and not the configuration.
 * review_url is an ordinary field on the edit page, so a link can be pasted
 * by hand whether or not a blog is set up - keying this on review_blog_url
 * would take a working filter away from somebody who keeps their reviews
 * somewhere other than a WordPress. */
$noReviews = $render(
    ['with' => 1, 'without' => 0],
    ['with' => 1, 'without' => 0],
    [],
    ['with' => 0, 'without' => 1]
);
Assert::true('a shelf with no review links does not offer the facet', !str_contains($noReviews, t('filter.review')));

$oneReview = $render(
    ['with' => 1, 'without' => 0],
    ['with' => 1, 'without' => 0],
    [],
    ['with' => 1, 'without' => 1]
);
Assert::true('one pasted link brings it back', str_contains($oneReview, t('filter.review')));

Assert::group('Shelf filters: binding is no longer a way in');

/* Whether a book arrived as a hardback, a paperback or a file is a fact about
 * the object rather than about the reading, and nobody browsing a shelf goes
 * looking for the paperbacks. It is still on the book and still in the
 * statistics - it is just not a facet. */
/* Checked against the markup and not against t('filter.binding'): that key was
 * removed with the facet, so the translator now hands its own name back and
 * the assertion would hold whatever the sidebar did. A link is evidence. */
Assert::true('no link filters by binding any more', !str_contains($mixed, 'binding='));
Assert::true('and sorting is still the first thing offered', str_contains($mixed, t('filter.sort')));

Assert::group('A card is not the edit page: its buttons stay their own size');

/* .edit-actions is the long edit form's sticky bar - it follows the page
 * down, paints a gradient over what is behind it, and stretches its first
 * button so Save dominates the row. All three are right there.
 *
 * Two short cards borrowed it because it was "a row of buttons", and the
 * stretch then made the accent button a different width on each: one row
 * holds three buttons and the other two, so the one that grows grows
 * differently. Same label, same act, two widths.
 */
foreach (['shelf/new.php', 'scan/index.php'] as $file) {
    $source = (string) file_get_contents(PROJECT_ROOT . '/app/templates/' . $file);
    Assert::true($file . ' uses the plain row', str_contains($source, 'class="form-actions"'));
    Assert::true('and not the sticky bar', !str_contains($source, 'class="edit-actions"'));
}

$style = (string) file_get_contents(PROJECT_ROOT . '/public/css/style.css');
Assert::true('the sticky bar still exists for the page it was written for', str_contains($style, '.edit-actions {'));
Assert::true(
    'and only it stretches its first button',
    str_contains($style, '.edit-actions > :first-child') && !str_contains($style, '.form-actions > :first-child')
);

Assert::group('Stars, read as "at least"');

/* The filter existed in the repository and nowhere else: buildWhere() had the
 * condition, index() never read the parameter, and no template linked to it.
 * Measured before it was wired up, ?rating=4 returned all 180 books on the
 * running shelf, because the value went nowhere.
 */
$controller = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/ShelfController.php');
$partial = (string) file_get_contents(PROJECT_ROOT . '/app/templates/partials/shelf_filters.php');
$page = (string) file_get_contents(PROJECT_ROOT . '/app/templates/shelf/index.php');

Assert::true(
    'the controller reads it, and only whole stars',
    str_contains($controller, "\$this->oneOf(\$request->query('rating'), ['1', '2', '3', '4', '5'])")
);
Assert::true('the sidebar offers it', str_contains($partial, "\$urlFor(['rating'"));
Assert::true('and the counts reach the sidebar', str_contains($page, "'ratingCounts'"));

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Tests\Support\SqliteSchema::apply($pdo, PROJECT_ROOT . '/schema.sql');
(new App\Repository\UserRepository($pdo))->create('m@example.org', 'ein-langes-passwort', 'M');

$books = new App\Repository\BookRepository($pdo);

/* Cumulative, and a half belongs to the step below it. "ab 4 Sternen" holds
 * the fives and the four and a halves, because somebody looking for the good
 * ones does not mean "exactly four" - and 4.5 turning up under 5 rather than
 * under 4 is the one row nobody expects it in.
 */
foreach ([5.0, 5.0, 4.5, 4.0, 3.5, 1.0] as $stars) {
    $books->insert(1, ['title' => 'Bewertet mit ' . $stars, 'rating' => $stars]);
}
$books->insert(1, ['title' => 'Noch ungelesen', 'rating' => null]);

$atLeast = $books->countByRating(1);

Assert::same('two books have five stars', $atLeast[5], 2);
Assert::same('four reach four, the half among them', $atLeast[4], 4);
Assert::same('five reach three', $atLeast[3], 5);
Assert::same('and six reach one', $atLeast[1], 6);
Assert::same('nothing changes at two', $atLeast[2], 5);

// The unrated book is in none of them. It is not a bad book, it is a book
// nobody has said anything about yet.
Assert::true('an unrated book counts nowhere', $atLeast[1] < 7);

/* The list agrees with the number beside the row. A count that promised more
 * than the page delivers is the fault this replaces. */
$found = $books->search(1, ['rating' => '4'], 100, 0);
Assert::same('and the filter returns exactly that many', $found['total'], $atLeast[4]);

$halves = array_filter(
    $found['rows'],
    static fn (array $row): bool => (float) $row['rating'] === 4.5
);
Assert::same('with the half star among them', count($halves), 1);

Assert::group('The order of the sidebar');

/* Rated by how likely somebody is to browse by it, not by how easy the facet
 * was to build. The series list stood at the top because it was written last.
 *
 * ISBN and review sit after the language because both are working queues:
 * useful while filling gaps, not while looking for something to read.
 */
$heads = [];
if (preg_match_all("/t\('(filter\.[a-z]+|series\.title)'\)/", $partial, $found)) {
    foreach ($found[1] as $key) {
        if (!in_array($key, $heads, true) && $key !== 'filter.reset') {
            $heads[] = $key;
        }
    }
}

Assert::same(
    'what a book is about first, the record last',
    $heads,
    [
        'filter.sort',
        'filter.genre',
        'filter.label',
        'filter.author',
        'series.title',
        'filter.rating',
        'filter.review',
        'filter.cover',
        'filter.language',
        'filter.isbn',
    ]
);
