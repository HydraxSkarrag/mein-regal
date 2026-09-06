<?php
/**
 * Searching the catalogue by title, for the books a barcode cannot reach.
 *
 * Every other lookup starts from an ISBN, which is fine until the book is
 * older than the number. This shelf holds 82 of those.
 *
 * The two assertions that matter here are the two things that were wrong when
 * it was first pointed at the live catalogue, both of which produced results
 * that looked entirely plausible: a note printed as a title, and an ISBN that
 * did not exist.
 */
declare(strict_types=1);

use App\Lookup\TitleSearch;

Assert::group('Title search: the query');

Assert::same('a title alone', TitleSearch::query('Der kleine Häwelmann'), 'TIT="Der kleine Häwelmann"');
Assert::same(
    'a title and a person',
    TitleSearch::query('Alfons Zitterbacke', 'Holtz-Baumert'),
    'TIT="Alfons Zitterbacke" and PER="Holtz-Baumert"'
);
Assert::same('an author alone is a search too', TitleSearch::query('', 'Storm'), 'PER="Storm"');

/* Quotes are dropped rather than escaped. A double quote inside a CQL phrase
 * has no meaning worth keeping, and building a catalogue query out of
 * somebody's punctuation is how a search box becomes an injection. */
Assert::same(
    'quotes in the input cannot close the phrase',
    TitleSearch::query('Titel" or PER="x'),
    'TIT="Titel  or PER= x"'
);

// An empty form must not fetch the first ten books in Germany.
Assert::same('nothing typed, nothing asked', TitleSearch::query('', ''), null);
Assert::same('whitespace is nothing', TitleSearch::query('   '), null);

Assert::group('Title search: reading a record');

/* Trimmed from the real answer for "Der kleine Häwelmann". Both traps are in
 * here, which is why it is this record and not a tidy invented one. */
$xml = <<<'XML'
<searchRetrieveResponse xmlns="http://www.loc.gov/zing/srw/">
 <records><record><recordData>
  <oai_dc:dc xmlns:oai_dc="http://www.openarchives.org/OAI/2.0/oai_dc/"
             xmlns:dc="http://purl.org/dc/elements/1.1/"
             xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
             xmlns:tel="http://krait.kb.nl/coop/tel/handbook/telterms.html"
             xmlns:dnb="http://d-nb.de/standards/dnbterms">
   <dc:title>[Die Regentrude] ; Die Regentrude : drei Märchen / Theodor Storm ; mit Federzeichnungen von Rolf von Hoerschelmann</dc:title>
   <dc:creator>Storm, Theodor [Verfasser]</dc:creator>
   <dc:creator>Hoerschelmann, Rolf von [Verfasser]</dc:creator>
   <dc:publisher>Oebisfelde : Alfa-Veda</dc:publisher>
   <dc:date>2024</dc:date>
   <dc:language>ger</dc:language>
   <dc:format>98 Seiten</dc:format>
   <dc:identifier xsi:type="dnb:IDN">1323121013</dc:identifier>
   <dc:identifier xsi:type="tel:ISBN">978-3-98837-012-9 Broschur</dc:identifier>
  </oai_dc:dc>
 </recordData></record></records>
</searchRetrieveResponse>
XML;

$found = TitleSearch::parse($xml);
Assert::same('one record, one candidate', count($found), 1);
$one = $found[0];

/* The title field is a whole citation: "[Series] ; Title : subtitle / Author
 * ; illustrated by Somebody". Taking the part after the last "; " before
 * dropping the responsibility statement returned "mit Federzeichnungen von
 * Rolf von Hoerschelmann" as the title of a book. The slash goes first. */
Assert::same('the title is the title', $one['title'], 'Die Regentrude : drei Märchen');

/* And the number: every record also carries a dnb:IDN, the library's own
 * accession number, ten digits long. Reading digits out of whichever
 * identifier came first handed those to the ISBN parser, which accepted the
 * ones whose check digit happened to work out - German records came back
 * carrying invented 978-1 numbers. Only tel:ISBN counts. */
Assert::same('the ISBN is the one the record calls an ISBN', $one['isbn13'], '9783988370129');

Assert::same('the roles in brackets are not part of a name', $one['authors'], ['Storm, Theodor', 'Hoerschelmann, Rolf von']);
Assert::same('the place is not part of the publisher', $one['publisher'], 'Alfa-Veda');
Assert::same('the year is a number', $one['year'], 2024);
Assert::same('and the pages too', $one['pages'], 98);
Assert::same('the language is a code', $one['language'], 'ger');

Assert::group('Title search: a record with no number at all');

/* The case the whole thing exists for. A book older than the ISBN has no
 * identifier to offer, and what the list shows is then all there is. */
$old = TitleSearch::parse(str_replace(
    '<dc:identifier xsi:type="tel:ISBN">978-3-98837-012-9 Broschur</dc:identifier>',
    '',
    $xml
));

Assert::same('it is still a candidate', count($old), 1);
Assert::same('with no ISBN', $old[0]['isbn13'], null);
Assert::same('and everything else intact', $old[0]['title'], 'Die Regentrude : drei Märchen');

Assert::same('nonsense is no records rather than an error', TitleSearch::parse('not xml at all'), []);

Assert::group('Title search: the cover shown beside a candidate');

/* Half the records have no cover - measured across three real searches, 12 of
 * 24 candidates had one, 7 had none and 5 had no ISBN to ask with. So the
 * picture is laid over a placeholder ground rather than put in an <img>: a
 * background that 404s simply does not paint, where an image that 404s is a
 * broken icon in half the list.
 *
 * The URL goes into a nonce-bearing style element, which is only safe because
 * of what the value is. This is that guarantee, stated where it can fail:
 * everything TitleSearch reports as an ISBN has been through
 * Isbn::normalize, so it cannot carry a quote, a bracket or a space out of
 * the catalogue and into a stylesheet.
 */
$fromCatalogue = TitleSearch::parse($xml)[0]['isbn13'];

Assert::same('an ISBN from a record is digits and nothing else', preg_match('/^[0-9]{13}$/', (string) $fromCatalogue), 1);
Assert::true(
    'so the address built from it closes its own quotes',
    !str_contains(App\Lookup\MvbCoverLookup::coverUrl((string) $fromCatalogue), '"')
);

/* And the record that has none reports null rather than something empty that
 * would render a rule pointing nowhere. */
Assert::same('no ISBN, no rule to write', $old[0]['isbn13'], null);
