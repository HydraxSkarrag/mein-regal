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

Assert::group('A reading date is not a reading status');

/* The two look like the same fact and are not. Forty books can be marked
 * read while one carries a date, and "Zuletzt gelesen" then orders that one
 * book and leaves thirty-nine in the same undated heap as the unread ones -
 * which is exactly what it looked like on a live shelf. */
$dates = new PDO('sqlite::memory:');
$dates->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dates->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($dates, PROJECT_ROOT . '/schema.sql');
(new UserRepository($dates))->create('d@example.org', 'ein-langes-passwort', 'D');
$dated = new BookRepository($dates);

$dated->insert(1, ['title' => 'Mit Datum', 'reading_status' => 'read', 'finished_at' => '2026-08-01']);
$dated->insert(1, ['title' => 'Ohne Datum', 'reading_status' => 'read']);
$dated->insert(1, ['title' => 'Ungelesen']);

Assert::same('only the dated one counts', $dated->countDated(1), 1);
Assert::same('though two are marked read', $dated->countBy(1, 'reading_status')['read'] ?? 0, 2);

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

/* The same rule for "Zuletzt gelesen", for the same reason and with a number
 * instead of a yes: below a handful of dates the sort is one book followed
 * by the alphabet. */
$withDates = static function (int $dated, array $filters = []) use ($view): string {
    return $view->render('partials.shelf_filters', [
        'filters'        => $filters,
        'urlFor'         => static fn (array $changes): string => '/?' . http_build_query($changes),
        'hasFilters'     => $filters !== [],
        'formatter'      => new App\Core\Formatter('de'),
        'tags'           => [], 'tagTotal'    => 0,
        'labels'         => [], 'labelTotal'  => 0,
        'topAuthors'     => [], 'authorTotal' => 0,
        'languageCounts' => [], 'languages'   => [],
        'seriesList'     => [], 'seriesTotal' => 0,
        'datedCount'     => $dated,
        'coverCounts'    => ['with' => 1, 'without' => 1],
        'isbnCounts'     => ['with' => 1, 'without' => 1],
        'reviewCounts'   => ['with' => 1, 'without' => 1],
    ]);
};

$few = BookRepository::MIN_READING_DATES - 1;
Assert::true(
    'a shelf with barely any dates does not offer the sort',
    !str_contains($withDates($few), t('sort.read'))
);
Assert::true(
    'the threshold itself is enough',
    str_contains($withDates(BookRepository::MIN_READING_DATES), t('sort.read'))
);
Assert::true(
    'and somebody standing in it keeps the control',
    str_contains($withDates(0, ['sort' => 'read']), t('sort.read'))
);

Assert::group('The series field completes the way the genre field does');

/* A <datalist> looked like completion on a desktop browser and was nothing at
 * all on a phone - Safari on iOS ignores it - so the one place where a series
 * is actually typed, standing in front of the shelf, had no help. The list is
 * now built in JavaScript, and it wears the genre field's clothes because it
 * is the same job: complete against what the shelf already holds. */
$css = (string) file_get_contents(PROJECT_ROOT . '/public/css/style.css');
$tagsJs = (string) file_get_contents(PROJECT_ROOT . '/public/js/tags.js');
$seriesJs = (string) file_get_contents(PROJECT_ROOT . '/public/js/series.js');

foreach (['picker-list', 'picker-new', 'picker-warning'] as $class) {
    Assert::true('the ' . $class . ' rule exists once', str_contains($css, '.' . $class));
    Assert::true('the genre field uses it', str_contains($tagsJs, $class));
    Assert::true('and so does the series field', str_contains($seriesJs, $class));
}

/* The old names would still work - CSS does not mind - and would leave two
 * spellings for one component, which is how the two drift apart. */
Assert::true('nothing is still called tagbox-list', !str_contains($css, 'tagbox-list'));
Assert::true('and no script asks for one', !str_contains($tagsJs, 'tagbox-list'));

// The chips stay the tag field's own: a book is in one series, not in three.
Assert::true('the chips are still the tag field alone', str_contains($css, '.tagbox-chip'));
Assert::true('and the series field grows none', !str_contains($seriesJs, 'tagbox-chip'));

/* Two lists of suggestions under one input would be one too many, so the
 * script takes the datalist away when it takes over - and the datalist stays
 * in the markup for the case where the script never runs. */
Assert::true('the script drops the native list', str_contains($seriesJs, "removeAttribute('list')"));

$edit = (string) file_get_contents(PROJECT_ROOT . '/app/templates/shelf/edit.php');
Assert::true('the markup still carries the fallback', str_contains($edit, '<datalist id="known-series">'));
Assert::true('the picker gets its own data', str_contains($edit, 'id="known-series-data"'));
Assert::true('and the row is marked for the narrow-screen rule', str_contains($edit, 'field-row--picker'));
Assert::true('which the stylesheet has', str_contains($css, '.field-row--picker'));
