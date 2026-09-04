<?php
/**
 * The third cover source.
 *
 * Google and Open Library between them left 1.451 of 3.042 books blank, all
 * but 95 of them with a perfectly good German ISBN. MVB - the book trade's
 * own directory, reached through the German National Library's catalogue -
 * answered for 143 of 160 of those when it was measured, which is the reason
 * this source exists at all.
 *
 * What is worth pinning down here is not that it works, which only the
 * network can say, but the three decisions around it: where in the order it
 * is asked, that only its own host may be fetched from, and that its picture
 * outranks a thumbnail the way a publisher's file should.
 */
declare(strict_types=1);

use App\Core\Csp;
use App\Core\CoverStorage;
use App\Lookup\CoverFinder;
use App\Lookup\LookupChain;
use App\Lookup\MvbCoverLookup;
use App\Repository\BookRepository;
use App\Repository\CoverRepository;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

Assert::group('MVB: the address');

Assert::same(
    'the cover service is addressed by ISBN alone',
    MvbCoverLookup::coverUrl('9783404178025'),
    'https://portal.dnb.de/opac/mvb/cover?isbn=9783404178025'
);

// An ISBN reaches this from a barcode and from a text field, so it is escaped
// like any other value that ends up in a URL.
Assert::true(
    'anything unexpected in the number is escaped, not appended',
    !str_contains(MvbCoverLookup::coverUrl('978 3&x=1'), '&x=1')
);

Assert::group('MVB: asked first only where it has something');

// MVB lists what the German book trade sells. Measured on ISBNs from
// elsewhere it answered 2 times in 25, so there it is a last resort.
Assert::true('a German ISBN is its home ground', MvbCoverLookup::isLikelyFor('9783404178025'));
Assert::true('an English one is not', !MvbCoverLookup::isLikelyFor('9780007491452'));

/* The scanner asks the same question while a book is still on screen, and it
 * has to reach the same answer - what it shows is what the book ends up with.
 * Both go through this one list. */
Assert::same(
    'the services a German ISBN is asked, in order',
    array_column(CoverFinder::coverServices('9783404178025'), 1),
    ['mvb', 'openlibrary']
);
Assert::same(
    'and the other way round for an English one',
    array_column(CoverFinder::coverServices('9780007491452'), 1),
    ['openlibrary', 'mvb']
);
Assert::same(
    'each one carries its own attribution',
    CoverFinder::coverServices('9783404178025')[0][2],
    MvbCoverLookup::ATTRIBUTION
);

/* The order itself. candidates() is private because nothing outside the class
 * has any business assembling a cover source list - but the order is the whole
 * decision, and a test that drove it through the network would be testing the
 * DNB's uptime instead. */
$order = static function (string $isbn): array {
    $finder = new CoverFinder(
        new LookupChain(),
        new CoverRepository(new PDO('sqlite::memory:')),
        new CoverStorage(sys_get_temp_dir())
    );
    [$candidates] = (new ReflectionMethod(CoverFinder::class, 'candidates'))->invoke($finder, $isbn, null);

    return array_column($candidates, 1);
};

Assert::same(
    'for a German ISBN it is asked before Open Library',
    $order('9783404178025'),
    ['mvb', 'openlibrary']
);
Assert::same(
    'for an English one it is the last resort instead',
    $order('9780007491452'),
    ['openlibrary', 'mvb']
);

/* The scanner's save used to take 'google' or 'openlibrary' and nothing else,
 * so a cover it had just shown from MVB would have been dropped on the way to
 * the shelf - silently, because a rejected source is not an error there. */
$accepted = App\Core\Input::oneOf('mvb', [
    CoverRepository::SOURCE_MVB,
    CoverRepository::SOURCE_GOOGLE,
    CoverRepository::SOURCE_OPENLIBRARY,
]);
Assert::same('and the scanner accepts it back when the book is saved', $accepted, 'mvb');

Assert::group('MVB: only its own host is fetched from');

$assertAllowed = new ReflectionMethod(CoverStorage::class, 'assertAllowed');
$storage = new CoverStorage(sys_get_temp_dir());
$allowed = static function (string $url) use ($assertAllowed, $storage): bool {
    try {
        $assertAllowed->invoke($storage, $url);

        return true;
    } catch (RuntimeException) {
        return false;
    }
};

Assert::true('the catalogue is allowed', $allowed('https://portal.dnb.de/opac/mvb/cover?isbn=9783404178025'));

/* The URL that reaches the fetcher is built here rather than read out of an
 * API response, so this is belt and braces - but the allowlist is what stands
 * between a cover fetcher and the server's own network, and a lookalike host
 * is exactly the shape an attack on it takes. */
Assert::true('a lookalike is not', !$allowed('https://portal.dnb.de.example.com/cover'));
Assert::true('nor the rest of the domain', !$allowed('https://dnb.de/cover'));
Assert::true('nor the same host over http', !$allowed('http://portal.dnb.de/opac/mvb/cover'));

Assert::true(
    'and a visitor\'s browser may load it too, for the owner-only preview',
    str_contains((new Csp())->header(), 'https://portal.dnb.de')
);

Assert::group('MVB: a publisher\'s file beats a thumbnail');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo, dirname(__DIR__) . '/schema.sql');
(new UserRepository($pdo))->create('m@example.org', 'ein-langes-passwort', 'M');

$books = new BookRepository($pdo);
$covers = new CoverRepository($pdo);

/* The case this was built for: 764 of 925 Google covers are under 400 pixels
 * wide, because for a German title Google has never scanned it hands back a
 * 300 pixel record thumbnail and nothing larger exists to ask for. MVB has
 * the file the publisher supplied to the trade. */
$book = $books->insert(1, ['title' => 'Klein bei Google', 'isbn13' => '9783404178025']);
$covers->save($book, CoverRepository::SOURCE_GOOGLE, 'ab/g.webp', null, 'Cover: Google Books', 300, 450);
$covers->save($book, CoverRepository::SOURCE_MVB, 'cd/m.webp', null, MvbCoverLookup::ATTRIBUTION, 383, 599);

Assert::same('the publisher\'s file is shown', $covers->bestFor($book, true)['source'], 'mvb');
Assert::same('both are kept, so either can be withdrawn', $covers->countBySource(1), ['google' => 1, 'mvb' => 1]);

// Provenance still decides above quality, exactly as it does for VLB-TIX: a
// photograph of the actual copy is the actual copy.
$covers->save($book, CoverRepository::SOURCE_OWN, 'ef/o.webp', null, null, 350, 520);
Assert::same('a photograph of the copy still wins', $covers->bestFor($book, true)['source'], 'own');

// And a rejection reaches it like any other source.
$covers->remove($book, CoverRepository::SOURCE_OWN);
$covers->remove($book, CoverRepository::SOURCE_MVB);
Assert::same('a thrown-out MVB cover is remembered', $covers->rejectedSources($book), ['mvb', 'own']);
Assert::same('and Google is shown again', $covers->bestFor($book, true)['source'], 'google');
