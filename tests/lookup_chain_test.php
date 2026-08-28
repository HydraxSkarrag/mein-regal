<?php
declare(strict_types=1);

use App\Lookup\BookData;
use App\Lookup\LookupChain;
use App\Lookup\LookupSource;

/** A source that answers from a fixed table, so the chain can be tested offline. */
final class FakeSource implements LookupSource
{
    public int $calls = 0;

    /** @param array<string, BookData|null> $answers */
    public function __construct(private string $name, private array $answers)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function find(string $isbn13): ?BookData
    {
        $this->calls++;

        return $this->answers[$isbn13] ?? null;
    }
}

Assert::group('LookupChain ordering');

$chain = new LookupChain(
    new FakeSource('dnb', []),
    new FakeSource('google', []),
    new FakeSource('openlibrary', [])
);

Assert::same('German ISBN asks the DNB first', $chain->orderFor('9783473408061'), ['dnb', 'google', 'openlibrary']);
Assert::same('English ISBN asks Open Library first', $chain->orderFor('9781451608137'), ['openlibrary', 'google', 'dnb']);
Assert::same('unknown group falls back to Google', $chain->orderFor('9789992158104'), ['google', 'openlibrary', 'dnb']);

Assert::group('LookupChain merging');

$german = '9783473408061';

$dnbAnswer = new BookData(
    source: 'dnb', isbn13: $german, title: 'Milla und das erfundene Glück',
    authors: [['name' => 'Rüdiger Bertram', 'role' => 'author']],
    publisher: 'Ravensburger Buchverlag', publishedYear: 2017, pageCount: 189,
    language: 'ger', binding: 'hardcover', price: 12.99
);
$googleAnswer = new BookData(
    source: 'google', isbn13: $german, title: 'Milla (Google spelling)',
    publisher: 'Wrong Publisher', publishedYear: 1999,
    coverUrl: 'https://books.google.com/x.jpg', attribution: 'Cover: Google Books'
);

$dnb = new FakeSource('dnb', [$german => $dnbAnswer]);
$google = new FakeSource('google', [$german => $googleAnswer]);
$openlibrary = new FakeSource('openlibrary', []);

$chain = new LookupChain($dnb, $google, $openlibrary);
$outcome = $chain->find($german);
$book = $outcome['result'];

Assert::same('the first source stays authoritative', $book?->title, 'Milla und das erfundene Glück');
Assert::same('a later source does not overwrite the publisher', $book?->publisher, 'Ravensburger Buchverlag');
Assert::same('nor the year', $book?->publishedYear, 2017);
Assert::same('the price only the DNB has survives', $book?->price, 12.99);
Assert::same('but the cover gap is filled', $book?->coverUrl, 'https://books.google.com/x.jpg');
Assert::same('and carries its attribution', $book?->attribution, 'Cover: Google Books');
Assert::same('Open Library was not needed', $openlibrary->calls, 0);

Assert::group('LookupChain misses');

$chain = new LookupChain(new FakeSource('dnb', []), new FakeSource('google', []), new FakeSource('openlibrary', []));
$outcome = $chain->find('9783473408061');
Assert::same('no source answers means null', $outcome['result'], null);
Assert::same('and every source was tried', $outcome['tried'], ['dnb', 'google', 'openlibrary']);
