<?php
declare(strict_types=1);

use App\Export\Exporter;
use App\Import\CsvReader;
use App\Import\Importer;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

Assert::group('Exporter: the way out');

$makeDb = static function (): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    SqliteSchema::apply($pdo, dirname(__DIR__) . '/schema.sql');
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
