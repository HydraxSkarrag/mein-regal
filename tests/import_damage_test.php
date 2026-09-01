<?php
/**
 * What a fresh import must not reproduce.
 *
 * Two defects were repaired in the collection by hand once. Both came out of
 * the source file, so both would come straight back the next time that file
 * is read - which is the only reason this test exists: it is aimed at the
 * import, not at the data.
 */
declare(strict_types=1);

use App\Core\Text;
use App\Import\BookstatsRow;

Assert::group('Import: characters Latin-1 could not carry');

$row = static fn (string $title): BookstatsRow => new BookstatsRow(['Titel' => $title]);

// Bookstats writes its CSV as Latin-1 and replaces everything else with a
// literal "?". 194 of these were dashes between a title and its subtitle.
Assert::same(
    'a question mark with a space in front was a dash',
    $row('Das Juwel ? Der Schwarze Schlüssel: Roman')->title(),
    'Das Juwel – Der Schwarze Schlüssel: Roman'
);
Assert::same(
    'several in one title',
    $row('A ? B ? C')->title(),
    'A – B – C'
);
Assert::same(
    'between letters it was an apostrophe',
    $row('A Good Girl?s Guide to Murder')->title(),
    'A Good Girl’s Guide to Murder'
);
Assert::same(
    'and before a capital it was a narrow space',
    $row('Das?Sagen-Epos in moderner?Sprache')->title(),
    'Das Sagen-Epos in moderner Sprache'
);

// The rule has to be narrow, or it eats real punctuation. A question mark
// ends a sentence and is never preceded by a space.
Assert::same(
    'a real question mark survives',
    $row('Wieso? Weshalb? Warum? Unser Garten')->title(),
    'Wieso? Weshalb? Warum? Unser Garten'
);
Assert::same(
    'even next to other punctuation',
    $row('Alles Schweine, oder was?!')->title(),
    'Alles Schweine, oder was?!'
);
Assert::same(
    'a title with no question mark is untouched',
    $row('Bernd Flessner: Der kleine Major Tom')->title(),
    'Bernd Flessner: Der kleine Major Tom'
);

Assert::group('Import: the role belongs in the role column');

// The export writes the role into the author field. Taking the string whole
// left the marker in the name and filed every link under 'author'.
foreach ([
    ['Eva Gebhardt (Ill.)',            'Eva Gebhardt',        'illustrator'],
    ['Katharina Staar (Illustratorin)', 'Katharina Staar',    'illustrator'],
    ['Van [Ill.] Gool',                'Van Gool',            'illustrator'],
    ['Hans-Jörg (Hrsg.) Uther',        'Hans-Jörg Uther',     'editor'],
    ['Bruno Horst [Hrsg.] Bull',       'Bruno Horst Bull',    'editor'],
    ['Alexandra Haag (Aut.)',          'Alexandra Haag',      'author'],
    ['Johanna Fischer (Autorin)',      'Johanna Fischer',     'author'],
    ['Bernd Flessner',                 'Bernd Flessner',      'author'],
] as [$raw, $name, $role]) {
    $split = Text::splitRole($raw);
    Assert::same('"' . $raw . '" is ' . $name, $split['name'], $name);
    Assert::same('"' . $raw . '" did the ' . $role . '\'s job', $split['role'], $role);
}

// An aside that is not a role stays put: it is part of how the name is
// written, and only the sort order needs to look past it.
$milne = Text::splitRole('A. A. (Alan Alexander) Milne');
Assert::same('an expanded initial is not a role', $milne['name'], 'A. A. (Alan Alexander) Milne');
Assert::same('and counts as an author', $milne['role'], 'author');
Assert::same('though it still sorts by surname', Text::sortName($milne['name']), 'Milne, A. A.');

Assert::group('Import: the file is Windows-1252, not ISO-8859-1');

// The two agree from 0xA0 up and differ in 0x80-0x9F: printable punctuation
// in the one, control characters in the other. The export uses that range, so
// the narrower guess turned a dash into an invisible control character which
// then travelled into the database intact and unreadable.
$path = sys_get_temp_dir() . '/regal-encoding-' . bin2hex(random_bytes(4)) . '.csv';
file_put_contents(
    $path,
    "\"Titel\";\"Autor(en)\"\r\n"
    // 0x96 is an en dash in Windows-1252 and a control character in ISO-8859-1.
    // 0xFC is u-umlaut in both, so it proves the shared range still decodes.
    . "\"Dumbledore \x96 The Complete Screenplay f\xFCr alle\";\"Rowling, J. K.\"\r\n"
);

$rows = [];
foreach ((new App\Import\CsvReader($path))->rows() as $row) {
    $rows[] = $row;
}
unlink($path);

Assert::same('the byte at 0x96 is a dash', $rows[0]['Titel'], 'Dumbledore – The Complete Screenplay für alle');
Assert::true(
    'and nothing invisible came through',
    preg_match('/[\x{0080}-\x{009F}]/u', $rows[0]['Titel']) === 0
);
Assert::true('the result is valid UTF-8', mb_check_encoding($rows[0]['Titel'], 'UTF-8'));
