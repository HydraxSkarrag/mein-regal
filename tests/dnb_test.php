<?php
declare(strict_types=1);

use App\Lookup\Binding;
use App\Lookup\DnbLookup;
use App\Lookup\HttpClient;

Assert::group('DnbLookup (offline, against a stored response)');

$dnb = new DnbLookup(new HttpClient());

$xml = file_get_contents(__DIR__ . '/fixtures/dnb_9783473408061.xml');
$book = $dnb->parse($xml, '9783473408061');

Assert::true('a record is returned', $book !== null);
Assert::same('title without the responsibility statement', $book?->title, 'Milla und das erfundene Glück');
Assert::same('publisher without the place', $book?->publisher, 'Ravensburger Buchverlag');
Assert::same('year', $book?->publishedYear, 2017);
Assert::same('page count from dc:format', $book?->pageCount, 189);
Assert::same('language', $book?->language, 'ger');
Assert::same('binding parsed out of the identifier', $book?->binding, Binding::HARDCOVER);
Assert::same('German retail price', $book?->price, 12.99);
Assert::same('ISBN-10 derived', $book?->isbn10, '3473408069');
Assert::same('author, read the way people write it', $book?->authors[0]['name'] ?? null, 'Rüdiger Bertram');
Assert::same('role recognised', $book?->authors[0]['role'] ?? null, 'author');
Assert::same('subject without the group letter', $book?->tags[0] ?? null, 'Kinder- und Jugendliteratur');
Assert::same('result is usable', $book?->isUsable(), true);

$empty = $dnb->parse(file_get_contents(__DIR__ . '/fixtures/dnb_notfound.xml'), '9781451608137');
Assert::same('no record means null, not an empty book', $empty, null);

Assert::same('malformed XML is survivable', $dnb->parse('<not xml', '9783473408061'), null);
Assert::same('empty body is survivable', $dnb->parse('', '9783473408061'), null);

Assert::group('DnbLookup awkward records');

// "Krause, Ute [Verfasser] [Illustrator]" - two role brackets on one person.
$muskeltiere = $dnb->parse(file_get_contents(__DIR__ . '/fixtures/dnb_9783570178997.xml'), '9783570178997');
Assert::same('no bracket left in the name', $muskeltiere?->authors[0]['name'] ?? null, 'Ute Krause');
Assert::same('first recognised role wins', $muskeltiere?->authors[0]['role'] ?? null, 'author');

// A translated work is filed under its original title in brackets first.
$lepera = $dnb->parse(file_get_contents(__DIR__ . '/fixtures/dnb_9783442342761.xml'), '9783442342761');
Assert::same('German title, not the English original', $lepera?->title, 'Heile - Dich - Selbst');
Assert::true('subtitle survives', str_starts_with($lepera?->subtitle ?? '', 'warum auch kleinste'));
Assert::same('the translator is not filed as an author', $lepera?->authors[1]['role'] ?? null, 'translator');
Assert::same('translator name', $lepera?->authors[1]['name'] ?? null, 'Elisabeth Liebl');

Assert::group('DnbLookup: multi-volume works');

// The simple oai_dc view of a volume keeps the volume label and throws the
// work's own title away, so this book arrives as "1. Trilogie" - impossible
// to find again by searching for "Erdsee".
Assert::same('a bare volume label is spotted', DnbLookup::isVolumeOnly('1. Trilogie'), true);
Assert::same('as is "Band 3"', DnbLookup::isVolumeOnly('Band 3'), true);
Assert::same('and "Bd. 2"', DnbLookup::isVolumeOnly('Bd. 2'), true);
// A bare number is NOT treated as a volume label - "1984" is a book.
Assert::same('a bare number is left alone', DnbLookup::isVolumeOnly('1984'), false);
Assert::same('bracketed numbering is caught', DnbLookup::isVolumeOnly('[4]'), true);

// Real titles must not be mistaken for one.
foreach (['Erdsee', 'Milla und das erfundene Glück', '1984', 'Die Trilogie der Stadt', 'Band of Brothers'] as $title) {
    Assert::same('"' . $title . '" is a real title', DnbLookup::isVolumeOnly($title), false);
}
Assert::same('no title is not a volume label', DnbLookup::isVolumeOnly(null), false);

// MARC keeps the title in 245 $a and the volume in $n.
$marc = file_get_contents(__DIR__ . '/fixtures/dnb_marc_9783596704057.xml');
$recovered = DnbLookup::parseMarcTitle($marc);

Assert::same('the work title is recovered', $recovered['title'] ?? null, 'Erdsee');
Assert::same('with the volume as subtitle', $recovered['subtitle'] ?? null, '1. Trilogie');
Assert::same('nonsense yields nothing', DnbLookup::parseMarcTitle('<not marc'), null);

Assert::group('DnbLookup: contributors from MARC');

// The plain view of this record lists only the translator as creator and
// drops Ursula K. Le Guin entirely. MARC keeps her in field 100 with the
// role in $e.
$people = DnbLookup::parseMarcContributors($marc);

Assert::same('the author is recovered', $people[0]['name'] ?? null, 'Ursula K. Le Guin');
Assert::same('with the right role', $people[0]['role'] ?? null, 'author');
Assert::same('the translator is kept as a translator', $people[1]['name'] ?? null, 'Karen Nölle');
Assert::same('with her role', $people[1]['role'] ?? null, 'translator');
Assert::same('nonsense yields nobody', DnbLookup::parseMarcContributors('<not marc'), []);

// The second request is earned by either symptom, so a record with a fine
// title but no author still gets one.
Assert::same('a list with only a translator counts as authorless', DnbLookup::hasAuthor([
    ['name' => 'Karen Nölle', 'role' => 'translator'],
]), false);
Assert::same('a real author satisfies it', DnbLookup::hasAuthor([
    ['name' => 'Ursula K. Le Guin', 'role' => 'author'],
]), true);

Assert::same('the publisher comes out of MARC too', DnbLookup::parseMarcPublisher($marc), 'FISCHER Tor');
Assert::same('nonsense yields no publisher', DnbLookup::parseMarcPublisher('<not marc'), null);
