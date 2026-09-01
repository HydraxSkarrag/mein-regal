<?php
/**
 * A cover thrown out has to stay thrown out.
 *
 * Removing one used to delete the row, which made the book cover-less - and
 * the nightly job then fetched the same wrong image from the same source and
 * put it back. Sorting bad covers out by hand was valid until the next night.
 *
 * The rule now: the source is blocked for that book, the book is not. It still
 * counts as having no cover, so another source may be tried and an upload by
 * hand still wins.
 */
declare(strict_types=1);

use App\Repository\BookRepository;
use App\Repository\CoverRepository;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

Assert::group('Covers: a rejection outlives the row');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo, dirname(__DIR__) . '/schema.sql');
(new UserRepository($pdo))->create('m@example.org', 'ein-langes-passwort', 'M');

$books = new BookRepository($pdo);
$covers = new CoverRepository($pdo);

$bookId = $books->insert(1, ['title' => 'Mit falschem Cover', 'isbn13' => '9783473408061']);
$covers->save($bookId, CoverRepository::SOURCE_GOOGLE, 'ab/x-google.webp', null, 'Cover: Google Books', 400, 600);

Assert::true('the cover is found', $covers->bestFor($bookId, true) !== null);
Assert::same('and counts towards the shelf', $books->countByCover(1)['with'], 1);

$paths = $covers->remove($bookId, CoverRepository::SOURCE_GOOGLE);

Assert::same('removal hands back the file to unlink', $paths, ['ab/x-google.webp']);
Assert::same('the cover is gone from view', $covers->bestFor($bookId, true), null);
Assert::same('the book counts as having none', $books->countByCover(1)['without'], 1);
Assert::same('and the filter agrees', count($books->search(1, ['cover' => 'no'], 10, 0)['rows']), 1);
Assert::same('it is not in the "with" half', count($books->search(1, ['cover' => 'yes'], 10, 0)['rows']), 0);
Assert::same('the dashboard no longer counts it', $covers->countBySource(1), []);

// The point of the whole exercise: the source is remembered.
Assert::same('the rejected source is remembered', $covers->rejectedSources($bookId), ['google']);
Assert::same(
    'and the row was kept, not deleted',
    (int) $pdo->query('SELECT COUNT(*) FROM covers WHERE book_id = ' . $bookId)->fetchColumn(),
    1
);

// Another source is still allowed - it is the source that is blocked.
$covers->save($bookId, CoverRepository::SOURCE_OPENLIBRARY, 'cd/x-openlibrary.webp', null, null, 400, 600);
Assert::same('another source still gets through', $covers->bestFor($bookId, true)['source'], 'openlibrary');
Assert::same('the rejection is unaffected', $covers->rejectedSources($bookId), ['google']);

// Saving the same source again is a deliberate act and lifts the rejection.
$covers->save($bookId, CoverRepository::SOURCE_GOOGLE, 'ab/x-google.webp', null, null, 400, 600);
Assert::same('an explicit save clears the rejection', $covers->rejectedSources($bookId), []);
Assert::same('and the cover is back', $covers->bestFor($bookId, true)['source'], 'google');
