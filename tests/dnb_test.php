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
