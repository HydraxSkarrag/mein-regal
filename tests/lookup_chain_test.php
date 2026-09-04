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
    coverUrl: 'https://books.google.com/x.jpg', attribution: 'Cover: Google Books',
    coverSource: 'google'
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

// The record says dnb and the cover says google, and both are true. Sharing
// one source field meant the scanner discarded the very cover it had just
// shown the user, because the record's source was not a cover source.
Assert::same('the record keeps its own source', $book?->source, 'dnb');
Assert::same('while the cover keeps the source it came from', $book?->coverSource, 'google');
Assert::same('and that reaches the client', $book?->toArray()['cover_source'], 'google');
Assert::same('Open Library was not needed', $openlibrary->calls, 0);

Assert::group('LookupChain misses');

$chain = new LookupChain(new FakeSource('dnb', []), new FakeSource('google', []), new FakeSource('openlibrary', []));
$outcome = $chain->find('9783473408061');
Assert::same('no source answers means null', $outcome['result'], null);
Assert::same('and every source was tried', $outcome['tried'], ['dnb', 'google', 'openlibrary']);


Assert::group('BookData: a record with its own cover keeps it');

// When the first source does supply a cover, a later one must not overwrite
// either the image or who it belongs to.
$withCover = new BookData(
    source: 'openlibrary', title: 'Hat eins',
    coverUrl: 'https://covers.openlibrary.org/a.jpg',
    attribution: 'Cover: Open Library', coverSource: 'openlibrary'
);
$other = new BookData(
    source: 'google', title: 'Auch da', pageCount: 200,
    coverUrl: 'https://books.google.com/b.jpg',
    attribution: 'Cover: Google Books', coverSource: 'google'
);
$merged = $withCover->mergeMissingFrom($other);

Assert::same('the first cover stands', $merged->coverUrl, 'https://covers.openlibrary.org/a.jpg');
Assert::same('with its own source', $merged->coverSource, 'openlibrary');
Assert::same('and its own credit', $merged->attribution, 'Cover: Open Library');
Assert::same('while a real gap is still filled', $merged->pageCount, 200);

Assert::group('LookupChain: a cover already in hand stops the hunt');

/* This one condition was the whole of Google's daily thousand.
 *
 * A German record comes from the DNB, the DNB holds no cover images at all,
 * so every German book was incomplete on that single point and every German
 * book cost a Google query looking for a picture Google usually did not have
 * either. The run of 4 September stopped on the quota after 837 books.
 *
 * The free cover services answer by ISBN alone. Once one of them has
 * delivered, a missing coverUrl is no longer a reason to ask anybody.
 */
$dnbComplete = new BookData(
    source: 'dnb', isbn13: $german, title: 'Milla und das erfundene Glück',
    authors: [['name' => 'Rüdiger Bertram', 'role' => 'author']],
    publisher: 'Ravensburger Buchverlag', publishedYear: 2017, pageCount: 189,
    language: 'ger', binding: 'hardcover', price: 12.99
);

$dnb = new FakeSource('dnb', [$german => $dnbComplete]);
$google = new FakeSource('google', [$german => $googleAnswer]);
$chain = new LookupChain($dnb, $google, new FakeSource('openlibrary', []));

$chain->find($german);
Assert::same('without a cover the chain carries on to Google', $google->calls, 1);

$google->calls = 0;
$outcome = $chain->find($german, true, true);
Assert::same('with one in hand it stops at the DNB', $google->calls, 0);
Assert::same('and the record is the DNB\'s, complete', $outcome['result']->pageCount, 189);
Assert::same('Google was never even tried', $outcome['tried'], ['dnb']);

/* The flag says "a cover exists", not "stop early". A record that is genuinely
 * short of something a lookup can fill still costs the query it always did -
 * that is the query being saved for, not saved from. */
$dnbThin = new BookData(
    source: 'dnb', isbn13: $german, title: 'Milla und das erfundene Glück',
    authors: [['name' => 'Rüdiger Bertram', 'role' => 'author']],
    publishedYear: 2017
);
$google->calls = 0;
$thin = new LookupChain(new FakeSource('dnb', [$german => $dnbThin]), $google, new FakeSource('openlibrary', []));
$thin->find($german, true, true);
Assert::same('a missing page count still sends the chain onwards', $google->calls, 1);

Assert::group('LookupChain: a source that is out for today is set aside');

/* Running out of Google used to end the whole nightly run. That was right
 * while every cover came through the metadata chain and wrong the moment the
 * free cover services existed: on 5 September, 78% of the books still without
 * a cover had one waiting at MVB, and none of them needed Google to get it.
 *
 * Retiring the source has to be distinguishable from asking it, though. A
 * source that was never put the question contributes no failure - and an
 * empty failure list is what the nightly job reads as "every source answered,
 * and none of them has this book", which locks a book out for thirty days.
 */
$google = new FakeSource('google', [$german => $googleAnswer]);
$chain = new LookupChain(new FakeSource('dnb', []), $google, new FakeSource('openlibrary', []));

Assert::same('to begin with it is asked', $chain->orderFor($german), ['dnb', 'google', 'openlibrary']);

$chain->retire('google');

Assert::same('once retired it is out of the order', $chain->orderFor($german), ['dnb', 'openlibrary']);

$outcome = $chain->find($german);
Assert::same('and is not called at all', $google->calls, 0);
Assert::same('nor listed among those tried', $outcome['tried'], ['dnb', 'openlibrary']);
Assert::same('the book is simply not found', $outcome['result'], null);

/* The trap, stated: this looks exactly like "everyone answered and nobody has
 * it". Only the caller knows it retired something, which is why bin/enrich.php
 * keeps its own note and refuses to cache a miss while one is set aside. */
Assert::same('a retired source leaves no failure behind', $outcome['failures'], []);
