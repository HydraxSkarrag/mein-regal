<?php
/**
 * Two volumes of one series arrived on the shelf as the same book.
 *
 *   /book/152-die-156-chronik-der-drachenlanze-9783442245116
 *   /book/152-die-156-chronik-der-drachenlanze-9783442245109
 *
 * Both titled "&#152;Die&#156; Chronik der Drachenlanze", telling apart only
 * by the ISBN at the end of the address - and their own names,
 * Drachenzwielicht and Drachenjäger, were in the record all along, one
 * subfield further on.
 *
 * Two separate faults with one symptom, which is why the address carries
 * both of them.
 */
declare(strict_types=1);

use App\Core\Text;
use App\Lookup\DnbLookup;

Assert::group('The marks a catalogue sorts by are not part of the title');

/* MARC brackets a leading article so a sort can skip it: "Die" wrapped in
 * U+0098 and U+009C. The words stay - only the brackets mean anything, and
 * only to a filing clerk.
 *
 * Those code points are illegal in XML 1.0, so the DNB escapes them, and what
 * comes out of the parser is the literal text "&#152;Die&#156;".
 * html_entity_decode will not turn that back either: it refuses a reference
 * to a forbidden code point, and returns the string untouched. So it
 * travelled into the title, into the slug and into the address bar, and the
 * one place it never looked like a control character was where somebody
 * might have noticed.
 */
Assert::same(
    'the escaped form goes',
    Text::withoutSortMarks('&#152;Die&#156; Chronik der Drachenlanze'),
    'Die Chronik der Drachenlanze'
);
Assert::same(
    'and so do the characters themselves',
    Text::withoutSortMarks("\u{0098}Der\u{009C} Steppenwolf"),
    'Der Steppenwolf'
);
Assert::same(
    'the article is kept - it is part of the name',
    Text::withoutSortMarks('&#152;Die&#156; Simpsons'),
    'Die Simpsons'
);
Assert::same('a title without them is untouched', Text::withoutSortMarks('Erdsee'), 'Erdsee');

// Measured: sixty-one of four hundred MARC records carry them. One in six
// German titles beginning with an article, not an oddity worth a special case.
Assert::true(
    'PHP really does leave the reference alone',
    html_entity_decode('&#152;Die&#156;', ENT_QUOTES, 'UTF-8') === '&#152;Die&#156;'
);

Assert::group('A volume of a series is called by its own name');

$record = static fn (string $isbn): string
    => (string) file_get_contents(PROJECT_ROOT . '/tests/fixtures/dnb_marc_' . $isbn . '.xml');

/* 245 splits a series volume across subfields: $a the series, $n which part,
 * $p what that part is called. Only $a and $n were read, so six volumes of
 * one series would all have been "Die Chronik der Drachenlanze". */
$first = DnbLookup::parseMarcTitle($record('9783442245116'));

Assert::same('the part name is the title', $first['title'], 'Drachenjäger');
Assert::same(
    'and the series with its number is the subtitle',
    $first['subtitle'],
    'Die Chronik der Drachenlanze 2'
);
Assert::true('nothing is left of the sort marks', !str_contains((string) $first['subtitle'], '#15'));

/* A record with no $p is the ordinary case and behaves as before: $a is the
 * title and $b the subtitle. Worth its own assertion because the change had
 * to leave nine records in ten exactly where they were. */
$plain = DnbLookup::parseMarcTitle($record('9783932170973'));

Assert::same('the title proper stands on its own', $plain['title'], 'Die Simpsons und die Philosophie');
Assert::same(
    'with the remainder under it',
    $plain['subtitle'],
    'schlauer werden mit der berühmtesten Fernsehfamilie der Welt'
);

Assert::group('What a title may not carry into the shelf');

/* Three things ride along in these subfields and none of them is a title:
 * who wrote it, after " / "; "[u.a.]", which marks a record standing for
 * several volumes at once; and the trailing punctuation MARC uses to join
 * fields that are being printed one after another. */
foreach ([$first, $plain] as $parsed) {
    foreach (['title', 'subtitle'] as $field) {
        $value = (string) ($parsed[$field] ?? '');
        Assert::true($field . ' carries no statement of responsibility', !str_contains($value, ' / '));
        Assert::true('and no "[u.a.]"', !str_contains($value, '[u.a.]'));
        Assert::true('and does not end in punctuation', $value === '' || !str_ends_with($value, '.'));
    }
}

Assert::group('The rule is applied to everything the catalogue says');

/* Not in parseMarcTitle: the marks turn up in the names of series and in the
 * publisher too, and a rule written per parser is a rule one parser will be
 * missing. It sits in the one function every value from this catalogue
 * passes through. */
$source = (string) file_get_contents(PROJECT_ROOT . '/app/Lookup/DnbLookup.php');
$funnel = strpos($source, 'private static function normalise');
$call = strpos($source, 'Text::withoutSortMarks(');

Assert::true('the funnel is where it happens', $call !== false && $funnel !== false && $call > $funnel);

/* Twice, not once: the series statement is read straight out of the MARC
 * subfields rather than through the record parser, because oai_dc has no
 * series field at all and that reading is its own request. Both go through
 * the same function, which is the point. */
Assert::same('and everywhere it is the same rule', substr_count($source, 'Text::withoutSortMarks('), 2);
