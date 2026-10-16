<?php
/**
 * A removed label that a new book arrives with.
 *
 * Reported on regal.hydrax.org: the scanner showed a label the owner had
 * removed under Genres and labels as one of the new book's labels, and the
 * book was saved without it. Bringing the label back afterwards did not put
 * it on that book either. link() dropped the connection without a word, on
 * the reasoning that a removed name must not come back with the next import -
 * which a hidden link does not do. The other half of removing, that restoring
 * is real, it broke for every book that arrived in between.
 *
 * So a book that arrives with a removed label keeps it, out of sight, the way
 * the books that had it when it was removed keep theirs. The card says it is
 * removed instead of showing it like any other. The edit form is the one
 * place that still refuses: there somebody types the name by hand, is told
 * it is removed, and the save does what the message says.
 */
declare(strict_types=1);

use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Import\CsvReader;
use App\Import\Importer;
use Tests\Support\TestDatabase;

require_once __DIR__ . '/support/TestDatabase.php';

$scanPdo = TestDatabase::fresh();
(new UserRepository($scanPdo))->create('d@example.org', 'ein-langes-passwort', 'D');
$scanBooks = new BookRepository($scanPdo);
$scanTags = new TagRepository($scanPdo);

$scanGone = $scanTags->findOrCreate(1, 'Kinder- und Jugendliteratur');
$scanTags->drop(1, $scanGone);

Assert::group('A new book with a removed label');

/* What ScanController::store does with the labels on the card. */
$scanned = $scanBooks->insert(1, ['title' => 'Zogg', 'isbn13' => '9783407795595']);
foreach (['Kinder- und Jugendliteratur', 'Bilderbuch'] as $name) {
    $scanTags->link($scanned, $scanTags->findOrCreate(1, $name));
}

Assert::same('the book shows only what is in use', array_column($scanTags->forBook(1, $scanned), 'name'), ['Bilderbuch']);
Assert::same('but the removed label knows the book', $scanTags->bookIdsFor(1, $scanGone), [$scanned]);

$scanTags->restore(1, $scanGone);
Assert::same(
    'so bringing it back puts it on the book that arrived while it was away',
    array_column($scanTags->forBook(1, $scanned), 'name'),
    ['Bilderbuch', 'Kinder- und Jugendliteratur']
);
$scanTags->drop(1, $scanGone);

Assert::group('Merged and then removed');

/* A name merged into a label that was itself removed later: the link goes
 * where the trail ends, and comes back with that label. */
$scanEnd = $scanTags->findOrCreate(1, 'Fantasy fiction');
$scanOld = $scanTags->findOrCreate(1, 'Fantasie');
$scanTags->merge(1, $scanOld, $scanEnd);
$scanTags->drop(1, $scanEnd);
$trail = $scanBooks->insert(1, ['title' => 'Erdsee', 'isbn13' => '9783596704057']);
$scanTags->link($trail, $scanTags->findOrCreate(1, 'Fantasie'));
Assert::same('the link lands at the end of the trail', $scanTags->bookIdsFor(1, $scanEnd), [$trail]);
Assert::same('and not on the merged name', $scanTags->bookIdsFor(1, $scanOld), []);

Assert::group('The import, which is the same kind of source');

$scanCsv = tempnam(sys_get_temp_dir(), 'regal') . '.csv';
file_put_contents($scanCsv, mb_convert_encoding(
    "\"Titel\";\"Autor(en)\";\"ISBN\";\"Genre\"\r\n\"Leander sieht Maud\";\"Nadia Marfaing\";\"9783961290239\";\"Kinder- und Jugendliteratur\"\r\n",
    'Windows-1252',
    'UTF-8'
));
(new Importer($scanPdo, $scanBooks, new AuthorRepository($scanPdo), $scanTags))->run(new CsvReader($scanCsv), 1, false);
@unlink($scanCsv);
$imported = $scanBooks->findByIsbn(1, '9783961290239');
Assert::true('the imported book is there', $imported !== null);
Assert::same('without the removed label on show', $scanTags->forBook(1, (int) ($imported['id'] ?? 0)), []);
Assert::true('but with it kept', in_array((int) ($imported['id'] ?? 0), $scanTags->bookIdsFor(1, $scanGone), true));

Assert::group('The edit form still means what it says');

/* There the name is typed by hand, and the field and the save both say it
 * will not be taken. Keeping it anyway would make the message untrue. */
$edited = $scanBooks->insert(1, ['title' => 'Bearbeitet', 'isbn13' => '9783442245857']);
$scanBooks->replaceTags(1, $edited, ['Kinder- und Jugendliteratur', 'Bilderbuch'], $scanTags);
$scanTags->restore(1, $scanGone);
Assert::same(
    'a removed name typed into the form is not on the book after it comes back',
    array_column($scanTags->forBook(1, $edited), 'name'),
    ['Bilderbuch']
);
$scanTags->drop(1, $scanGone);

Assert::group('What the scanner card is told');

$scanController = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/ScanController.php');
$scanScript = (string) file_get_contents(PROJECT_ROOT . '/public/js/scanner.js');
$scanTemplate = (string) file_get_contents(PROJECT_ROOT . '/app/templates/scan/index.php');
Assert::true('the lookup marks the removed labels', str_contains($scanController, "\$data['tags_removed']"));
Assert::true('the card draws them as removed', str_contains($scanScript, 'book.tags_removed') && str_contains($scanScript, 'result-tag--removed'));
Assert::true('with a sentence saying what happens to them', str_contains($scanTemplate, "'tagRemoved'"));
