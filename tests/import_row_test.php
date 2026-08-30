<?php
declare(strict_types=1);

use App\Import\BookstatsRow;
use App\Lookup\Binding;
use App\Repository\BookRepository;

Assert::group('BookstatsRow: "0" means unset');

$zeros = new BookstatsRow([
    'Titel' => 'Testbuch', 'Bewertung' => '0', 'Preis' => '0,00',
    'Erscheinungsjahr' => '0', 'Seitenanzahl' => '0',
    'Dauer (Stunden)' => '0', 'Dauer (Minuten)' => '0',
]);

// Reading these as numbers would invent 2,075 one-star ratings and 662 free books.
Assert::same('rating 0 is unrated', $zeros->rating(), null);
Assert::same('price 0,00 is unknown', $zeros->price(), null);
Assert::same('year 0 is unknown', $zeros->publishedYear(), null);
Assert::same('0 pages is unknown', $zeros->pageCount(), null);
Assert::same('0h 0m is no duration', $zeros->audioMinutes(), null);

Assert::group('BookstatsRow: German formats');

$row = new BookstatsRow([
    'Titel' => 'Burg Tollkühn', 'Preis' => '12,90', 'Bewertung' => '4',
    'Erscheinungsjahr' => '2020', 'Seitenanzahl' => '192',
    'Lesebeginn' => '13.04.2022', 'Leseende' => '04.06.2022', 'Erhalten am' => '23.02.2022',
    'Buchart' => 'Hardcover', 'Lesestatus' => 'Gelesen', 'Erhalten als' => 'Rezensionsexemplar',
    'Dauer (Stunden)' => '2', 'Dauer (Minuten)' => '30',
]);

Assert::same('decimal comma', $row->price(), 12.90);
Assert::same('German date to ISO', $row->startedAt(), '2022-04-13');
Assert::same('finished date', $row->finishedAt(), '2022-06-04');
Assert::same('binding mapped to a stable key', $row->binding(), Binding::HARDCOVER);
Assert::same('status mapped', $row->readingStatus(), BookRepository::STATUS_READ);
Assert::same('review copy mapped', $row->acquisitionType(), BookRepository::ACQUIRED_REVIEW);
Assert::same('hours and minutes combined', $row->audioMinutes(), 150);
Assert::same('rating kept', $row->rating(), 4);

Assert::group('BookstatsRow: dates');

Assert::same('empty date', BookstatsRow::germanDate(''), null);
Assert::same('impossible date rejected', BookstatsRow::germanDate('31.02.2022'), null);
Assert::same('already-ISO input rejected', BookstatsRow::germanDate('2022-02-23'), null);
Assert::same('leap day accepted', BookstatsRow::germanDate('29.02.2024'), '2024-02-29');

Assert::group('BookstatsRow: identifiers and people');

$noIsbn = new BookstatsRow(['Titel' => 'X', 'ISBN' => '', 'Autor(en)' => 'Unbekannt']);
Assert::same('missing ISBN', $noIsbn->isbn13(), null);
Assert::same('"Unbekannt" yields no author', $noIsbn->authors()['names'], []);

$game = new BookstatsRow(['Titel' => 'X', 'ISBN' => '4005556022946']);
Assert::same('a non-book EAN is not an ISBN', $game->isbn13(), null);
Assert::same('but the raw value is kept for the report', $game->rawIsbn(), '4005556022946');

$two = new BookstatsRow(['Titel' => 'X', 'Autor(en)' => 'Flessner, Bernd, Schilling, Peter']);
Assert::same('two authors split', $two->authors()['names'], ['Bernd Flessner', 'Peter Schilling']);

$untitled = new BookstatsRow(['Titel' => '']);
Assert::same('an empty title still gets one', $untitled->title(), '(untitled)');

Assert::group('BookstatsRow: machine identifiers in the genre column');

// Nineteen rows of the real export carry a shop's internal category id where
// a genre should be. Imported faithfully they become tags nobody can read.
Assert::same(
    'a UUID is not a genre',
    BookstatsRow::looksLikeIdentifier('7c9a6c79-19ea-4dea-90da-d7d47042d341_1001'),
    true
);
Assert::same(
    'nor is a campaign slug',
    BookstatsRow::looksLikeIdentifier('2017-Ravensburger-Alle-Leserabe-Buecher'),
    true
);

// The test has to be narrow: real genres contain hyphens and ampersands too.
foreach ([
    'Fantasy', 'New Adult', 'Science-Fiction', 'Fantasy & Horror',
    'Mythen & Legenden', 'Erstes Lesealter', 'Bildung & Nachschlagewerk',
    'Kinderbücher', 'Paranormal & Urban', 'Graphic Novel', 'Krimi/Thriller',
] as $genre) {
    Assert::same('"' . $genre . '" survives', BookstatsRow::looksLikeIdentifier($genre), false);
}
Assert::same('an empty value is not an identifier', BookstatsRow::looksLikeIdentifier(''), false);

$identifierRow = new BookstatsRow(['Titel' => 'X', 'Genre' => '7c9a6c79-19ea-4dea-90da-d7d47042d341_1001']);
Assert::same('the genre is dropped', $identifierRow->genre(), null);
Assert::same('but the row is flagged for the report', $identifierRow->genreIsIdentifier(), true);

$goodRow = new BookstatsRow(['Titel' => 'X', 'Genre' => 'Science-Fiction']);
Assert::same('a real genre is kept', $goodRow->genre(), 'Science-Fiction');
Assert::same('and not flagged', $goodRow->genreIsIdentifier(), false);
