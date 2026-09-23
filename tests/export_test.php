<?php
declare(strict_types=1);

use App\Export\Exporter;
use App\Import\CsvReader;
use App\Import\Importer;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use Tests\Support\TestDatabase;

require_once __DIR__ . '/support/TestDatabase.php';

Assert::group('Exporter: the way out');

$makeDb = static function (): PDO {
    $pdo = TestDatabase::fresh();
    (new UserRepository($pdo))->create('m@example.org', 'ein-langes-passwort', 'M');

    return $pdo;
};

$first = $makeDb();
$importer = new Importer($first, new BookRepository($first), new AuthorRepository($first), new TagRepository($first));
$importer->run(new CsvReader(__DIR__ . '/fixtures/bookstats_sample.csv'), 1, false);

$exporter = new Exporter($first);

$out = fopen('php://memory', 'r+');
$written = $exporter->bookstatsCsv(1, $out);
rewind($out);
$exported = stream_get_contents($out);
fclose($out);

Assert::same('every book is written out', $written, 6);
Assert::true('the header is the original one', str_starts_with($exported, '"Titel";"Autor(en)";"ISBN"'));

// The original file was Latin-1 with CRLF; matching it is what makes the
// file readable by the tool this project replaced.
Assert::true('lines end the way the original did', str_contains($exported, "\r\n"));
Assert::same('the bytes are Latin-1, not UTF-8', mb_check_encoding($exported, 'UTF-8') && str_contains($exported, 'ü'), false);

$asUtf8 = (string) iconv('ISO-8859-1', 'UTF-8', $exported);
Assert::true('umlauts survive the conversion', str_contains($asUtf8, 'Rückkehr zur Erde'));
Assert::true('so do the German dates', str_contains($asUtf8, '13.04.2022'));
Assert::true('and the decimal comma', str_contains($asUtf8, '"10,00"'));

Assert::group('Exporter: it reads back in');

// The claim is that this file is a way out, so it has to go back in. Export,
// import into an empty database, export again - the two files must match.
$path = tempnam(sys_get_temp_dir(), 'regal') . '.csv';
file_put_contents($path, $exported);

$second = $makeDb();
$secondImporter = new Importer($second, new BookRepository($second), new AuthorRepository($second), new TagRepository($second));
$report = $secondImporter->run(new CsvReader($path), 1, false);

Assert::same('the same number of books comes back', $report->imported, 6);
Assert::same('with no errors', $report->errors, []);
Assert::same('and nothing needing review', $report->ambiguousAuthors, 0);

$again = fopen('php://memory', 'r+');
(new Exporter($second))->bookstatsCsv(1, $again);
rewind($again);
$reExported = stream_get_contents($again);
fclose($again);

Assert::same('exporting the round trip gives the identical file', $reExported, $exported);

/* Half a star goes out and comes back. Bookstats had no halves, this shelf
 * does, and the column used to carry whatever the database handed over:
 * 3.5 on SQLite and "3.5" or "4.0" on MySQL, of which the importer read
 * none. So the way out lost every rating on the live servers while every
 * test here passed. */
$half = $makeDb();
$halfBooks = new BookRepository($half);
$halfBooks->insert(1, ['title' => 'Halb', 'rating' => 3.5]);
$halfBooks->insert(1, ['title' => 'Ganz', 'rating' => 4]);
$halfOut = fopen('php://memory', 'r+');
(new Exporter($half))->bookstatsCsv(1, $halfOut);
rewind($halfOut);
$halfCsv = (string) stream_get_contents($halfOut);
fclose($halfOut);
Assert::true('half a star is written as the price is, with a comma', str_contains($halfCsv, '"3,5"'));
Assert::true('a whole one without a decimal', str_contains($halfCsv, '"4";""') && !str_contains($halfCsv, '4.0') && !str_contains($halfCsv, '4,0'));

$halfPath = tempnam(sys_get_temp_dir(), 'regal') . '.csv';
file_put_contents($halfPath, $halfCsv);
$halfBack = $makeDb();
(new Importer($halfBack, new BookRepository($halfBack), new AuthorRepository($halfBack), new TagRepository($halfBack)))
    ->run(new CsvReader($halfPath), 1, false);
$ratingsBack = $halfBack->query('SELECT title, rating FROM books ORDER BY title')->fetchAll(PDO::FETCH_KEY_PAIR);
Assert::same(
    'and both come back as they went out',
    array_map(static fn ($r): float => (float) $r, $ratingsBack),
    ['Ganz' => 4.0, 'Halb' => 3.5]
);
@unlink($halfPath);
unlink($path);

Assert::group('Exporter: the fuller formats');

$full = fopen('php://memory', 'r+');
$exporter->fullCsv(1, $full);
rewind($full);
$fullCsv = stream_get_contents($full);
fclose($full);

Assert::true('a byte order mark, so spreadsheets read the umlauts', str_starts_with($fullCsv, "\u{FEFF}"));
Assert::true('umlauts are plain UTF-8 here', str_contains($fullCsv, 'Rückkehr zur Erde'));
Assert::true('columns the old format never had are included', str_contains($fullCsv, 'review_url'));
Assert::true('roles are spelled out', str_contains($fullCsv, 'contributors'));

$json = $exporter->json(1);
Assert::same('the json export counts the same books', $json['count'], 6);
Assert::true('it records when it was made', $json['exported_at'] !== '');
Assert::true('and carries the contributors', isset($json['books'][0]['contributors']));
Assert::same('but not the owner id, which means nothing outside', isset($json['books'][0]['owner_id']), false);

Assert::group('Exporter: the two fuller formats say the same thing');

/* They drifted apart once. The CSV had a column list of its own and the
 * series arrived after it was written; the JSON writes whatever the table
 * has, so it picked the series up - as a bare series_id, a number that means
 * nothing once this database is gone. Neither said which tags are genres. */
$pdo = $makeDb();
$tagRepository = new TagRepository($pdo);
$bookId = (new BookRepository($pdo))->insert(1, ['title' => 'Drachenzwielicht', 'isbn13' => '9783442244584']);
$pdo->exec("INSERT INTO series (owner_id, name, slug) VALUES (1, 'Die Chronik der Drachenlanze', 'die-chronik-der-drachenlanze')");
$pdo->exec('UPDATE books SET series_id = ' . (int) $pdo->lastInsertId() . ', series_index = 5.0, series_index_end = 6.0 WHERE id = ' . $bookId);

$fantasy = $tagRepository->findOrCreate(1, 'Fantasy');
$dragons = $tagRepository->findOrCreate(1, 'Drachen');
$junk = $tagRepository->findOrCreate(1, 'collection:Forgotten Realms');
$misspelt = $tagRepository->findOrCreate(1, 'Fantasie');
foreach ([$fantasy, $dragons, $junk, $misspelt] as $tagId) {
    $tagRepository->link($bookId, $tagId);
}
$tagRepository->setKinds(1, [$fantasy => true, $misspelt => true]);
$tagRepository->drop(1, $junk);
$tagRepository->merge(1, $misspelt, $fantasy);

$exporter = new Exporter($pdo);
$json = $exporter->json(1)['books'][0];

$full = fopen('php://memory', 'r+');
$exporter->fullCsv(1, $full);
rewind($full);
$header = str_getcsv(substr((string) fgets($full), 3), ',', '"', '');
$row = array_combine($header, str_getcsv((string) fgets($full), ',', '"', ''));
fclose($full);

Assert::same('the CSV names the series', $row['series'], 'Die Chronik der Drachenlanze');
Assert::same('with the volume as written, not "5.0"', [$row['series_index'], $row['series_index_end']], ['5', '6']);
Assert::same(
    'the JSON names it too, as one thing',
    $json['series'],
    ['name' => 'Die Chronik der Drachenlanze', 'index' => 5, 'index_end' => 6]
);
Assert::true(
    'and not by an id that means nothing outside',
    !array_key_exists('series_id', $json) && !array_key_exists('series_index', $json)
);

Assert::same('genres and labels apart, in the CSV', [$row['genres'], $row['labels']], ['Fantasy', 'Drachen']);
Assert::same('and in the JSON', [$json['genres'], $json['labels']], [['Fantasy'], ['Drachen']]);

/* Removing a tag keeps its links, which is what makes it reversible - and
 * what brought it back in every export. After a merge the book carried the
 * old name beside the new one, because a merge copies the links. */
Assert::true('a removed tag stays removed in the CSV', !str_contains(implode(';', $row), 'collection:'));
Assert::true('and in the JSON', !str_contains((string) json_encode($json), 'collection:'));
Assert::true('a merged-away spelling does not reappear', !str_contains(implode(';', $row), 'Fantasie'));

Assert::same(
    'the old format fills its one Genre column with a genre',
    str_getcsv((string) iconv('ISO-8859-1', 'UTF-8', (static function () use ($exporter): string {
        $out = fopen('php://memory', 'r+');
        $exporter->bookstatsCsv(1, $out);
        rewind($out);
        fgets($out);
        return (string) fgets($out);
    })()), ';', '"', '')[5],
    'Fantasy'
);
