<?php
/**
 * The address follows the name.
 *
 * A slug was worked out once, when the book was inserted, and never again. So
 * three books whose titles had just been corrected went on living at
 *
 *   /book/152-die-156-chronik-der-drachenlanze-9783442245109
 *
 * while the page above it said "Drachenzwielicht": the wrong title, and a
 * catalogue's non-sorting marks, in the address bar, permanently.
 *
 * The price of fixing it is named rather than hidden - an address that
 * changes is an address somebody may have written down, and this shelf has a
 * blog next to it that links to book pages. Having both would take a table of
 * former addresses to redirect from. That was a deliberate choice, not an
 * oversight, which is the only reason this file is not about redirects.
 */
declare(strict_types=1);

use App\Repository\BookRepository;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo, PROJECT_ROOT . '/schema.sql');
(new UserRepository($pdo))->create('m@example.org', 'ein-langes-passwort', 'M');

$books = new BookRepository($pdo);

Assert::group('Correcting a title moves the book');

$id = $books->insert(1, [
    'title'  => '&#152;Die&#156; Chronik der Drachenlanze',
    'isbn13' => '9783442245109',
]);

Assert::same(
    'the address starts out as the title was',
    $books->findById(1, $id)['slug'],
    '152-die-156-chronik-der-drachenlanze-9783442245109'
);

$books->update(1, $id, ['title' => 'Drachenzwielicht']);

Assert::same(
    'and follows it when it is corrected',
    $books->findById(1, $id)['slug'],
    'drachenzwielicht-9783442245109'
);
Assert::true('the book is found at the new address', $books->findBySlug(1, 'drachenzwielicht-9783442245109') !== null);
Assert::same('and no longer at the old one', $books->findBySlug(1, '152-die-156-chronik-der-drachenlanze-9783442245109'), null);

Assert::group('Saving twice must not glue a number on');

/* The trap this walks into: the uniqueness check asks whether any book holds
 * the address, and the book asking is one of them. Without excluding it, a
 * save that changes nothing would find its own address taken and settle for
 * "-2" - once per save, forever, and the book would crawl away from its name
 * a suffix at a time. */
for ($i = 0; $i < 3; $i++) {
    $books->update(1, $id, ['title' => 'Drachenzwielicht']);
}

Assert::same(
    'three more saves leave it exactly where it was',
    $books->findById(1, $id)['slug'],
    'drachenzwielicht-9783442245109'
);

Assert::group('Two books that would like the same address');

/* The ISBN is what usually keeps titles apart, so this is about the books
 * that have none - six titles repeat in the collection. */
$first = $books->insert(1, ['title' => 'Erdsee']);
$second = $books->insert(1, ['title' => 'Etwas anderes']);

Assert::same('the first takes the plain name', $books->findById(1, $first)['slug'], 'erdsee');

$books->update(1, $second, ['title' => 'Erdsee']);
$moved = $books->findById(1, $second)['slug'];

Assert::same('and the second gets out of its way', $moved, 'erdsee-2');
Assert::same('without disturbing the first', $books->findById(1, $first)['slug'], 'erdsee');

Assert::group('The ISBN is half the address too');

$books->update(1, $id, ['isbn13' => '9783442245116']);

Assert::same(
    'correcting it moves the book as well',
    $books->findById(1, $id)['slug'],
    'drachenzwielicht-9783442245116'
);

Assert::group('Everything else leaves the address alone');

/* Only the two columns the slug is built from. A rating, a note or a reading
 * date has no business changing where a book lives, and re-deriving on every
 * save would be a slow way to find that out. */
$before = $books->findById(1, $id)['slug'];
$books->update(1, $id, ['rating' => 4, 'notes' => 'gelesen im Zug', 'reading_status' => 'read']);

Assert::same('a rating does not move it', $books->findById(1, $id)['slug'], $before);

Assert::group('Where the reader is sent afterwards');

/* $book in the controller is the row as it was before the save. Redirecting
 * to that slug after a rename lands on a 404, which is the last thing
 * anybody expects from a successful save. */
$controller = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/BookController.php');
Assert::true(
    'the saved row is read back for the redirect',
    str_contains($controller, "\$saved = \$this->app->books->findById(\$this->app->ownerId, \$bookId);")
);
Assert::true(
    'and the old slug is only the fallback',
    str_contains($controller, "'/book/' . (\$saved['slug'] ?? \$book['slug'])")
);

// The column is not in the allow-list, so no form can post a slug: it is
// derived here or not at all.
$repository = (string) file_get_contents(PROJECT_ROOT . '/app/Repository/BookRepository.php');
$allowed = substr($repository, strpos($repository, '$allowed = ['), 400);
Assert::true('nothing may set the address directly', !str_contains($allowed, "'slug'"));
