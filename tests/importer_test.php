<?php
declare(strict_types=1);

use App\Import\CsvReader;
use App\Import\Importer;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Repository\TagRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

Assert::group('CsvReader');

$reader = new CsvReader(__DIR__ . '/fixtures/bookstats_sample.csv');
$rows = iterator_to_array($reader->rows());

Assert::same('all data rows read', count($rows), 6);
Assert::same('header parsed into keys', $reader->header()[0], 'Titel');
// The file is Latin-1; reading it as UTF-8 would produce mojibake.
Assert::same('umlauts survive the encoding conversion', $rows[2]['Titel'], 'Der kleine Major Tom. Band 2: Rückkehr zur Erde');

Assert::group('Importer end to end');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo, dirname(__DIR__) . '/schema.sql');

$books = new BookRepository($pdo);
$importer = new Importer($pdo, $books, new AuthorRepository($pdo), new TagRepository($pdo));

$dry = $importer->run(new CsvReader(__DIR__ . '/fixtures/bookstats_sample.csv'), 1, true);
Assert::same('a dry run writes nothing', (int) $pdo->query('SELECT COUNT(*) FROM books')->fetchColumn(), 0);
Assert::same('but still counts the rows', $dry->rows, 6);

$report = $importer->run(new CsvReader(__DIR__ . '/fixtures/bookstats_sample.csv'), 1, false);

Assert::same('every row imported', $report->imported, 6);
Assert::same('no errors', $report->errors, []);
Assert::same('books stored', (int) $pdo->query('SELECT COUNT(*) FROM books')->fetchColumn(), 6);

// "Flechsig, Dorothea" and "Dorothea Flechsig" are one person, and
// "Unbekannt" is nobody.
$authors = $pdo->query('SELECT name FROM authors ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
Assert::same('the two spellings became one person', count(array_filter($authors, fn ($n) => str_contains($n, 'Flechsig'))), 1);
Assert::same('"Unbekannt" was not stored as a person', in_array('Unbekannt', $authors, true), false);
Assert::same('the two-author row produced two people', in_array('Bernd Flessner', $authors, true), true);

$tollkuehn = $books->findByIsbn(1, '9783833906183');
Assert::same('German date converted', $tollkuehn['started_at'], '2022-04-13');
Assert::same('decimal comma converted', (float) $tollkuehn['price'], 10.0);
Assert::same('binding mapped', $tollkuehn['binding'], 'hardcover');
Assert::same('slug transliterates the umlaut', str_contains($tollkuehn['slug'], 'tollkuehn'), true);

// "0" throughout the export means "not set".
$abgebrochen = $books->findByIsbn(1, '9783748800835');
Assert::same('rating 0 stored as unrated', $abgebrochen['rating'], null);
Assert::same('price 0,00 stored as unknown', $abgebrochen['price'], null);

// A product EAN is not an ISBN, but the book is still imported.
$game = $pdo->query("SELECT * FROM books WHERE title = 'Ein Spiel, kein Buch'")->fetch();
Assert::same('non-book EAN not stored as an ISBN', $game['isbn13'], null);
Assert::same('the row is kept regardless', $game['title'], 'Ein Spiel, kein Buch');
Assert::same('year 0 stored as unknown', $game['published_year'], null);

$audio = $books->findByIsbn(1, '9783442483839');
Assert::same('audio duration combined into minutes', (int) $audio['audio_minutes'], 150);
Assert::same('"Am lesen" mapped', $audio['reading_status'], 'reading');

Assert::group('Importer bulk-date detection');

// With a low threshold the shared 23.02.2022 is recognised as a data-entry day.
$flagged = (int) $pdo->query('SELECT COUNT(*) FROM books WHERE acquired_at_is_bulk = 1')->fetchColumn();
Assert::same('the sample is below the real threshold, so nothing is flagged', $flagged, 0);

$bulk = $importer->findBulkDates(new CsvReader(__DIR__ . '/fixtures/bookstats_sample.csv'), 4);
Assert::same('the shared entry day is spotted', array_key_first($bulk), '2022-02-23');
Assert::same('with its count', $bulk['2022-02-23'], 4);
