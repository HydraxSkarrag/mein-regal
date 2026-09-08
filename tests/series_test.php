<?php
/**
 * Book series: a name, a volume, and the two things that belong to neither.
 *
 * A tag would have done half the job. It has no order, and a series is read
 * from volume one; and it has nowhere to put the two facts that are about the
 * series rather than about any book in it - how many volumes there are, and
 * which counting applies.
 *
 * The second is not hypothetical. The catalogue does not agree with itself:
 * "Der Rhythmus des Krieges" is volume 7 in the Heyne records and 8 in the
 * audio and e-book ones, for the same text.
 */
declare(strict_types=1);

use App\Core\Formatter;
use App\Core\Input;
use App\Lookup\DnbLookup;
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
$series = new SeriesRepository($pdo);

Assert::group('One series, however it is typed');

$first = $series->findOrCreate(1, 'Die Sturmlicht-Chroniken', $made);
Assert::true('the first one is made', $made);

/* Matched on the slug, so a second spelling finds the first series instead of
 * making a second. This is the lesson the 385 imported genre strings taught
 * this shelf, applied before it can happen again. */
$again = $series->findOrCreate(1, 'Die Sturmlicht Chroniken', $made);
Assert::same('a different spelling is the same series', $again, $first);
Assert::true('and nothing was made', !$made);

Assert::same('an empty name is no series', $series->findOrCreate(1, '   '), null);

Assert::group('Volumes, in volume order');

$put = static function (string $title, ?float $index) use ($books, $series, $first): int {
    return $books->insert(1, [
        'title'        => $title,
        'series_id'    => $first,
        'series_index' => $index,
    ]);
};

$put('Der Weg der Könige', 1.0);
$put('Der Rhythmus des Krieges', 8.0);
$put('Die Tänzerin am Abgrund', 7.0);
$noNumber = $put('Irgendein Sonderband', null);

$volumes = $series->volumes(1, $first);
Assert::same(
    'read from volume one',
    array_column($volumes, 'title'),
    ['Der Weg der Könige', 'Die Tänzerin am Abgrund', 'Der Rhythmus des Krieges', 'Irgendein Sonderband']
);

/* Unnumbered last rather than first. A book somebody has not got round to
 * numbering belongs at the end of the list, not in front of volume one. */
Assert::same('the unnumbered one is last', (int) $volumes[3]['id'], $noNumber);

Assert::group('The gaps, which are the point of the page');

/* Owning volumes 1, 7 and 8 of a twelve-volume series is not the same as
 * owning a complete trilogy, and a grid of three covers looks identical
 * either way. */
Assert::same(
    'counted up to the stated total',
    SeriesRepository::gaps($volumes, 12),
    [2, 3, 4, 5, 6, 9, 10, 11, 12]
);

/* Without a total, only the holes between what is held. "What comes after the
 * last one I own" is not a gap, it is the future. */
Assert::same(
    'and only between them when nobody said how many',
    SeriesRepository::gaps($volumes, null),
    [2, 3, 4, 5, 6]
);

// A half step is a novella between two novels. Its absence is not a hole.
$withHalf = [['series_index' => 1.0], ['series_index' => 2.0], ['series_index' => 4.5]];
Assert::same('halves are not counted as missing', SeriesRepository::gaps($withHalf, null), [3, 4]);
Assert::same('nothing numbered, nothing missing', SeriesRepository::gaps([['series_index' => null]], 5), []);

Assert::group('How many there are is not how many you have');

Assert::same('nobody has said yet', $series->find(1, $first)['total'], null);
$series->update(1, $first, ['total' => 12, 'note' => 'gezählt wie bei Audible, mit den Novellen']);
Assert::same('now they have', (int) $series->find(1, $first)['total'], 12);

/* The note exists because the sources disagree and the answer has to survive
 * being asked again next year. */
Assert::true('and said why', str_contains((string) $series->find(1, $first)['note'], 'Audible'));

Assert::group('A series outlives its books');

$empty = $series->findOrCreate(1, 'Mistborn');
$listed = array_column($series->listForOwner(1), 'owned', 'name');

Assert::same('a series with no books is still listed', $listed['Mistborn'] ?? null, 0);
Assert::same('and one with books counts them', (int) ($listed['Die Sturmlicht-Chroniken'] ?? 0), 4);

/* Deleting one frees its books rather than taking them with it. No tombstone,
 * unlike a tag: nothing recreates a series behind the owner's back, because
 * nothing writes one except a form or a record somebody accepted. */
$series->delete(1, $empty);
Assert::same('it is gone', $series->find(1, $empty), null);

Assert::group('Which volume, as somebody would type it');

Assert::same('a whole number', Input::volume('12'), 12.0);
Assert::same('a half with a comma', Input::volume('4,5'), 4.5);
Assert::same('and with a point', Input::volume('4.5'), 4.5);
Assert::same('nothing is nothing', Input::volume(''), null);
Assert::same('zero is not a volume', Input::volume('0'), null);
Assert::same('nor is a word', Input::volume('Band'), null);

// Shown back without the trailing nought the column keeps.
Assert::same('twelve reads as twelve', Formatter::volume(12.0), '12');
Assert::same('and four and a half keeps its half', Formatter::volume(4.5), '4,5');

Assert::group('What the catalogue says, and what it must not be believed about');

/* MARC keeps a work series and a publisher's numbered line in the same field.
 * "Goldmann $v 24510 : Fantasy" is Goldmann's stock number for a Drachenlanze
 * volume, not its position in the series.
 *
 * The number does not tell them apart: measured over a few hundred records,
 * work numbers ran 1 to 13 and stock numbers from 24000 - but a cap would be
 * a guess and a long-running series would break it. The name does. A
 * publisher's line is named after the publisher, and the record says who that
 * is a field away.
 */
$marc = static fn (string $isbn): string
    => (string) file_get_contents(PROJECT_ROOT . '/tests/fixtures/dnb_marc_' . $isbn . '.xml');

$drachenlanze = DnbLookup::parseMarcSeries($marc('9783442245116'));

Assert::same('the stock number is refused', $drachenlanze['name'], 'Die Chronik der Drachenlanze');
Assert::same('and the real volume found in 245 instead', $drachenlanze['index'], 2.0);

// A book in no series says so, rather than being filed under its publisher.
Assert::same('no series is no series', DnbLookup::parseMarcSeries($marc('9783932170973')), null);

$source = (string) file_get_contents(PROJECT_ROOT . '/app/Lookup/DnbLookup.php');
Assert::true(
    'the publisher is what settles it',
    str_contains($source, 'private static function isPublisherLine')
);
Assert::true('and a colon settles the rest', str_contains($source, "str_contains(\$volume, ':')"));

/* Every shape the volume field takes, all four measured off the wire. A range
 * is one volume holding two parts and starts at the first of them. */
$number = new ReflectionMethod(DnbLookup::class, 'volumeNumber');
$number->setAccessible(true);

foreach (['8' => 8.0, 'Band 13' => 13.0, '8. Roman' => 8.0, '3/4' => 3.0, 'Bd. 2' => 2.0] as $raw => $want) {
    Assert::same('"' . $raw . '"', $number->invoke(null, $raw), $want);
}
Assert::same('and something that is not a number at all', $number->invoke(null, 'Fantasy'), null);

Assert::group('The series is asked for separately, and on purpose');

/* oai_dc carries no series field at all - measured on a book that plainly is
 * in one - so reading it is a second request against MARC. Made where one
 * person is adding one book, and not in the nightly job, which would double
 * its load on the catalogue for a field most books do not have.
 */
$scan = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/ScanController.php');
$enrich = (string) file_get_contents(PROJECT_ROOT . '/bin/enrich.php');

Assert::true('the scanner asks', str_contains($scan, '->seriesFor($isbn)'));
Assert::true('and only for a record the catalogue answered', str_contains($scan, "\$found->source === 'dnb'"));
Assert::true('the nightly job does not', !str_contains($enrich, 'seriesFor'));

Assert::group('Nothing may post a series id');

/* The form posts a name. Turning it into a row is the controller's job,
 * because "make one if it is not there" is a decision about the shelf - and
 * because a raw id off a form would be somebody else's series.
 */
$controller = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/BookController.php');
Assert::true(
    'the name is resolved against this owner',
    str_contains($controller, '$this->app->series->findOrCreate(')
);
Assert::true(
    'and the volume is dropped with the series',
    str_contains($controller, "\$seriesId === null ? null : Input::volume(")
);
