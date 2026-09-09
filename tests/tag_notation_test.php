<?php
/**
 * The catalogues file books under a scheme, and hand the notation over with
 * the name attached.
 *
 * "59 Belletristik" sat in the shelf's tag list beside "Belletristik" - the
 * same thing twice, one of them wearing a number - and eight more like it
 * under a "#" heading at the end of the alphabet. The lookup did strip a
 * notation, but only the two shapes somebody had happened to look at: a
 * single capital letter, and exactly three digits.
 *
 * Measured across seven years of DNB records, sixty-two of sixty-three
 * subject values began with a notation, in six shapes. Half of them went
 * straight through.
 */
declare(strict_types=1);

use App\Core\Text;
use App\Repository\BookRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

Assert::group('Every shape the notation comes in');

/* Each of these was read off the wire, not invented. The three-digit rule
 * caught row two and nothing else. */
$measured = [
    '07 Kinder- und Jugendliteratur'      => 'Kinder- und Jugendliteratur',
    '830 Deutsche Literatur'              => 'Deutsche Literatur',
    '0300 Mathematik, Physik, Astronomie' => 'Mathematik, Physik, Astronomie',
    '621.3 Elektrotechnik, Elektronik'    => 'Elektrotechnik, Elektronik',
    '10a Erziehung, Unterricht'           => 'Erziehung, Unterricht',
    'B Belletristik'                      => 'Belletristik',
    '59 Belletristik'                     => 'Belletristik',
];

foreach ($measured as $raw => $want) {
    Assert::same('"' . $raw . '"', Text::withoutClassification($raw), $want);
}

Assert::group('A code with no name is not a tag');

// "301" and "10b" arrived as whole subject values, with nothing after them.
Assert::same('three bare digits', Text::withoutClassification('301'), null);
Assert::same('a letter suffix', Text::withoutClassification('10b'), null);
Assert::same('a decimal notation', Text::withoutClassification('781.66'), null);
Assert::same('a bare letter', Text::withoutClassification('K'), null);
Assert::same('and nothing at all', Text::withoutClassification('   '), null);

Assert::group('What must survive it');

/* The reason the rule is not "starts with a digit". A notation has to be
 * followed by whitespace, and be at least two digits - the schemes here have
 * no one-digit groups, while "3 Musketiere" is a perfectly good subject. */
Assert::same('a century keeps its number', Text::withoutClassification('20. Jahrhundert'), '20. Jahrhundert');
Assert::same('and so does a year', Text::withoutClassification('1968'), '1968');
Assert::same('one digit is not a notation', Text::withoutClassification('3 Musketiere'), '3 Musketiere');
Assert::same('an ordinary name is untouched', Text::withoutClassification('High Fantasy'), 'High Fantasy');
Assert::same(
    'brackets and colons are somebody else is problem, not this one',
    Text::withoutClassification('Santa Fe (Submarine : SSN 763)'),
    'Santa Fe (Submarine : SSN 763)'
);

/* The gap that is left, written down rather than pretended away: no shape in
 * the string tells "50 Jahre Bundesrepublik" from "59 Belletristik". Curing
 * it would take the published list of group numbers, a table to keep in step
 * with two catalogues, for a case that did not occur once in the sample. */
Assert::same(
    'a subject that really starts with a number loses it',
    Text::withoutClassification('50 Jahre Bundesrepublik'),
    'Jahre Bundesrepublik'
);

Assert::group('One rule, wherever subjects become tags');

/* Three sources hand out subjects and all three used to decide for
 * themselves what to keep. The DNB had the rule, the other two had none. */
foreach (['DnbLookup', 'GoogleBooksLookup', 'OpenLibraryLookup'] as $source) {
    $code = (string) file_get_contents(PROJECT_ROOT . '/app/Lookup/' . $source . '.php');
    Assert::true($source . ' asks Text', str_contains($code, 'Text::withoutClassification('));
}

/* And the rule is nowhere else, or there are two of them again. Anchored,
 * because the year is pulled out of a date with \d{3} in it and that one is
 * none of this rule's business. */
$dnb = (string) file_get_contents(PROJECT_ROOT . '/app/Lookup/DnbLookup.php');
Assert::true('the old subject pattern is gone', !str_contains($dnb, "'/^\\d{3}"));
Assert::true('and so is the capital-letter one', !str_contains($dnb, "'/^[A-Z]"));

Assert::group('Cleaning up what got through before');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo, PROJECT_ROOT . '/schema.sql');
(new UserRepository($pdo))->create('m@example.org', 'ein-langes-passwort', 'M');

$books = new BookRepository($pdo);
$tags = new TagRepository($pdo);

$put = static function (string $name, int $count) use ($books, $tags): int {
    $id = $tags->findOrCreate(1, $name);
    for ($i = 0; $i < $count; $i++) {
        $tags->link($books->insert(1, ['title' => $name . ' ' . $i]), $id);
    }

    return $id;
};

/* The two cases the tidy-up has to tell apart, both taken from the shelf
 * this was reported on. */
$numbered = $put('59 Belletristik', 4);
$plain    = $put('Belletristik', 4);
$alone    = $put('46 Bildende Kunst', 1);

// Where the corrected name is free, the entry keeps its books and drops the
// number.
Assert::true('a lone entry is renamed', $tags->rename(1, $alone, 'Bildende Kunst'));

$after = [];
foreach ($tags->listAllByName(1) as $tag) {
    $after[$tag['name']] = (int) $tag['book_count'];
}
Assert::same('and keeps every book on it', $after['Bildende Kunst'] ?? null, 1);
Assert::true('under the new name only', !isset($after['46 Bildende Kunst']));

// Where it is taken, renaming would collide - two tags cannot share a slug -
// so the script merges instead. Refusing rather than guessing is what makes
// that decision the caller's.
Assert::same('a taken name is refused', $tags->rename(1, $numbered, 'Belletristik'), false);
Assert::same('and the entry is untouched', $tags->find(1, $numbered)['name'], '59 Belletristik');

$tags->merge(1, $numbered, $plain);

$after = [];
foreach ($tags->listAllByName(1) as $tag) {
    $after[$tag['name']] = (int) $tag['book_count'];
}
Assert::same('the books end up on one entry', $after['Belletristik'] ?? null, 8);
Assert::true('and the numbered twin is gone from the list', !isset($after['59 Belletristik']));

/* Dropped rather than deleted, like everything else in the tag
 * administration: a merge decided by a script has to be as reversible as one
 * decided by hand. */
Assert::true('the merged entry is still there to restore', $tags->find(1, $numbered) !== null);

Assert::group('An entry no book carries any more is still an entry');

/* Reported from the shelf: the tidy-up fixed seven of nine and left "14
 * Soziologie, Gesellschaft" and "49 Theater, Tanz, Film" sitting in the list.
 * They had lost their last book, and the listing the plan was reading joins
 * book_tags - so an orphan was not in it at all.
 *
 * They are in the list a reader sees, numbers and all. A rule that cleans the
 * ones with books and quietly skips the ones without is the worst kind: it
 * looks like it worked. */
$orphan = $tags->findOrCreate(1, '49 Theater, Tanz, Film');

$plan = App\Content\TagNotation::plan($tags, 1);
$named = array_map(
    static fn (array $item): string => (string) $item['tag']['name'],
    $plan['renames']
);

Assert::true('an orphan is in the plan', in_array('49 Theater, Tanz, Film', $named, true));

App\Content\TagNotation::apply($tags, 1, $plan);
Assert::same('and is renamed like any other', $tags->find(1, $orphan)['name'], 'Theater, Tanz, Film');

/* A dropped entry is a different thing: a tombstone that keeps later imports
 * pointing at the right tag, and in no list a reader sees. Renaming one
 * achieves nothing; merging into one would send books somewhere invisible. */
$tombstone = $tags->findOrCreate(1, '11 Psychologie');
$tags->drop(1, $tombstone);

$after = App\Content\TagNotation::plan($tags, 1);
$names = array_merge(
    array_map(static fn (array $i): string => (string) $i['tag']['name'], $after['renames']),
    array_map(static fn (array $i): string => (string) $i['from']['name'], $after['merges'])
);
Assert::true('a dropped one is left alone', !in_array('11 Psychologie', $names, true));

Assert::group('The tidy-up writes nothing until told to');

$script = (string) file_get_contents(PROJECT_ROOT . '/bin/tags.php');
Assert::true('it has a dry run', str_contains($script, 'DRY RUN'));
Assert::true('and --commit is what writes', str_contains($script, "isset(\$options['commit'])"));
Assert::true('it refuses to run over the web', str_contains($script, "PHP_SAPI !== 'cli'"));

Assert::group('The script and the button decide the same thing');

/* The host has no shell, so the repair has to be reachable from the tag
 * administration - and four of the nine entries on the shelf this was
 * reported on cannot be fixed by hand there at all: merging needs a tag
 * under the corrected name to merge into, and "46 Bildende Kunst" had no
 * plain twin.
 *
 * Two implementations of "what should happen to these names" would drift, so
 * there is one, and both read it. */
$controller = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/TagController.php');
foreach (['bin/tags.php' => $script, 'TagController' => $controller] as $what => $code) {
    Assert::true($what . ' plans through TagNotation', str_contains($code, 'TagNotation::plan('));
    Assert::true('and applies through it', str_contains($code, 'TagNotation::apply('));
}

// The section is a repair and disappears once there is nothing to repair.
$page = (string) file_get_contents(PROJECT_ROOT . '/app/templates/admin/tags.php');
Assert::true('the panel is conditional', str_contains($page, 'if ($notation > 0)'));

/* "tidy" is a word where the neighbouring routes take a number, and the
 * router takes the first match - registered after /admin/tags/{id}/... it
 * would never be reached. */
$routes = (string) file_get_contents(PROJECT_ROOT . '/public/index.php');
$tidy = strpos($routes, "/admin/tags/tidy");
$byId = strpos($routes, "/admin/tags/{id}");
Assert::true('the route exists', $tidy !== false);
Assert::true('and comes before the numbered ones', (int) $tidy < (int) $byId);

Assert::group('The catalogue talking to itself');

/* Open Library files a book under "nyt:series_books=2011-03-26" to record
 * that it stood on that week's bestseller list. It is not a subject, it is a
 * note between machines - and it came through untouched, because the notation
 * rule looks for digits or a capital at the front and this begins with "nyt".
 * Two books on a real shelf carried it, and it had its own page in the tag
 * list.
 *
 * Matched on the shape and not on "nyt": a namespace, a colon, a key, an
 * equals sign, no whitespace anywhere. The next one will be called something
 * else.
 */
foreach ([
    'nyt:series_books=2011-03-26',
    'nyt:combined-print-and-e-book-fiction=2011-05-01',
    'nyt:hardcover-graphic-books=2015-05-31',
] as $machine) {
    Assert::same('"' . $machine . '" is not a subject', Text::withoutClassification($machine), null);
}

/* And the shapes it must not eat. Measured against 957 distinct subjects off
 * 39 Open Library records from this shelf: three machine tags dropped, two
 * bare classification codes that the older rule already dropped, and nothing
 * else touched. */
foreach ([
    'New York Times bestseller' => 'New York Times bestseller',
    'Fiction: general'          => 'Fiction: general',
    'Paris (france), fiction'   => 'Paris (france), fiction',
    'Flamel, Nicholas, 1418'    => 'Flamel, Nicholas, 1418',
    '20. Jahrhundert'           => '20. Jahrhundert',
] as $subject => $want) {
    Assert::same('"' . $subject . '" survives', Text::withoutClassification($subject), $want);
}
