<?php
/**
 * The search, and the sort that groups a series.
 *
 * Both bugs here reached two live shelves through a green test suite, and for
 * the same reason: the thing that was wrong could not be seen by running the
 * code the way the tests run it.
 *
 * The search clause named the backslash as its LIKE escape character. SQLite,
 * which the tests use, gives a backslash no meaning inside a string literal,
 * so ESCAPE '\' is the one-character string it looks like. MySQL, which both
 * shelves use, reads the backslash as escaping the quote that follows it, so
 * the literal never closes and the statement never parses. Every search on
 * both shelves answered 500 while every test passed.
 *
 * The sort put the volume before the series, which lines up every book
 * numbered 1 from every series, then every book numbered 2. Nothing errors;
 * it is simply not what "by series" means. A test that only checked which
 * books came back would have passed too.
 */
declare(strict_types=1);

use App\Core\Dialect;
use App\Repository\BookRepository;
use App\Repository\SeriesRepository;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo, PROJECT_ROOT . '/schema.sql');
(new UserRepository($pdo))->create('m@example.org', 'ein-langes-passwort', 'M');

$books = new BookRepository($pdo);

Assert::group('The escape character has to be legal in both dialects');

/* This is the assertion that would have caught it, and it needs no database:
 * a backslash is the wrong answer whatever it is tested against. */
Assert::true(
    'the LIKE escape character is not a backslash',
    Dialect::LIKE_ESCAPE !== '\\'
);

// The same, one level up: no SQL anywhere may spell the clause out by hand.
$sources = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PROJECT_ROOT . '/app'));
$handWritten = [];
foreach ($sources as $file) {
    if ($file->isDir() || $file->getExtension() !== 'php') {
        continue;
    }
    if (preg_match("#ESCAPE '\\\\\\\\'#", (string) file_get_contents($file->getPathname()))) {
        $handWritten[] = $file->getFilename();
    }
}
Assert::same('and no query writes a backslash escape by hand', $handWritten, []);

Assert::group('A search term that is all wildcards');

$books->insert(1, ['title' => '100% Wolle', 'isbn13' => '9780000000001']);
$books->insert(1, ['title' => 'Hundert Prozent', 'isbn13' => '9780000000002']);
$books->insert(1, ['title' => 'Ein_Unterstrich', 'isbn13' => '9780000000003']);
$books->insert(1, ['title' => 'Achtung! Ein Ausruf', 'isbn13' => '9780000000004']);

$titles = static function (string $term) use ($books): array {
    return array_column($books->search(1, ['search' => $term])['rows'], 'title');
};

// Without the ESCAPE clause doing its job, "100%" matches everything that
// starts with 100 - and "%" on its own matches the whole shelf.
Assert::same('a per cent sign is a character, not a wildcard', $titles('100%'), ['100% Wolle']);
// On its own it is a search for the character, not for everything.
Assert::same('and on its own it matches only titles holding one', $titles('%'), ['100% Wolle']);
Assert::same('an underscore matches only itself', $titles('n_U'), ['Ein_Unterstrich']);

/* The escape character in the term itself. It is doubled before it is sent,
 * or it would escape the letter after it and quietly change the search. */
Assert::same('the escape character is a character too', $titles('Achtung!'), ['Achtung! Ein Ausruf']);
Assert::same('the ordinary case still works', $titles('Prozent'), ['Hundert Prozent']);

Assert::group('By series means the series first, the volume second');

$series = new SeriesRepository($pdo);
$storm = $series->findOrCreate(1, 'Die Sturmlicht-Chroniken', $made);
$dune  = $series->findOrCreate(1, 'Der Wüstenplanet', $made);

$books->insert(1, ['title' => 'Sturmlicht eins', 'series_id' => $storm, 'series_index' => 1.0]);
$books->insert(1, ['title' => 'Sturmlicht zwei', 'series_id' => $storm, 'series_index' => 2.0]);
$books->insert(1, ['title' => 'Wüstenplanet eins', 'series_id' => $dune, 'series_index' => 1.0]);
$books->insert(1, ['title' => 'Wüstenplanet zwei', 'series_id' => $dune, 'series_index' => 2.0]);
$books->insert(1, ['title' => 'Aaa ohne Reihe']);

$order = array_column($books->search(1, ['sort' => 'series', 'search' => 'planet'])['rows'], 'title');
Assert::same('one series stays together', $order, ['Wüstenplanet eins', 'Wüstenplanet zwei']);

$all = array_column($books->search(1, ['sort' => 'series'])['rows'], 'title');
$storms = array_search('Sturmlicht eins', $all, true);
$dunes  = array_search('Wüstenplanet eins', $all, true);
Assert::same('volume two follows volume one of the same series', $all[$storms + 1], 'Sturmlicht zwei');
Assert::same('and the other series is not interleaved', $all[$dunes + 1], 'Wüstenplanet zwei');

/* A sort never hides a book, so the books in no series are still there - all
 * of them after all of the series, where an empty column belongs, rather than
 * scattered through it. Alphabetically first is the giveaway: without the
 * empty test it would head the list. */
$noSeries = array_slice($all, 4);
Assert::same(
    'every book in no series comes after every book in one',
    $noSeries,
    ['100% Wolle', 'Aaa ohne Reihe', 'Achtung! Ein Ausruf', 'Ein_Unterstrich', 'Hundert Prozent']
);

Assert::group('And the sidebar does not offer it before there is one');

$view = new App\Core\View(PROJECT_ROOT . '/app/templates');
$sidebar = static function (int $seriesTotal, array $filters = []) use ($view): string {
    return $view->render('partials.shelf_filters', [
        'filters'        => $filters,
        'urlFor'         => static fn (array $changes): string => '/?' . http_build_query($changes),
        'hasFilters'     => $filters !== [],
        'formatter'      => new App\Core\Formatter('de'),
        'tags'           => [], 'tagTotal'    => 0,
        'labels'         => [], 'labelTotal'  => 0,
        'topAuthors'     => [], 'authorTotal' => 0,
        'languageCounts' => [], 'languages'   => [],
        'seriesList'     => [], 'seriesTotal' => $seriesTotal,
        'coverCounts'    => ['with' => 1, 'without' => 1],
        'isbnCounts'     => ['with' => 1, 'without' => 1],
        'reviewCounts'   => ['with' => 1, 'without' => 1],
    ]);
};

Assert::true(
    'a shelf with no series does not offer the sort',
    !str_contains($sidebar(0), t('sort.series'))
);
Assert::true(
    'one series is enough for it to appear',
    str_contains($sidebar(1), t('sort.series'))
);
Assert::true(
    'and somebody standing in it keeps the control',
    str_contains($sidebar(0, ['sort' => 'series']), t('sort.series'))
);
