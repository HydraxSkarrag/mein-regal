<?php
/**
 * A book with no ISBN, made by hand.
 *
 * Nothing in the application could do this. The scanner needs an ISBN by
 * definition and its save refuses anything else, so the 82 books on this
 * shelf without one - Fontane in five volumes, a picture book from 1988 -
 * arrived through a CSV import and there was no way to add the 83rd.
 *
 * What is worth pinning down is not the form, which is one field, but what
 * the shelf does with a book that has no number: the slug it gets, that it
 * stays reachable, and that nothing downstream assumes the ISBN is there.
 */
declare(strict_types=1);

use App\Repository\BookRepository;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo, dirname(__DIR__) . '/schema.sql');
(new UserRepository($pdo))->create('m@example.org', 'ein-langes-passwort', 'M');

$books = new BookRepository($pdo);

Assert::group('A book by hand: no ISBN, and still a book');

$id = $books->insert(1, ['title' => 'Fontane Werke in 5 Bänden', 'reading_status' => 'unread']);
$book = $books->findById(1, $id);

Assert::true('it exists', $book !== null);
Assert::same('with the title it was given', $book['title'], 'Fontane Werke in 5 Bänden');
Assert::same('and no ISBN at all', $book['isbn13'], null);

/* The slug is what the address is built from, and uniqueSlug() normally hangs
 * the ISBN on the end to keep two printings of one title apart. With no
 * number there is nothing to hang, so the title has to carry the address by
 * itself - and it does. */
Assert::same('the address comes from the title alone', $book['slug'], 'fontane-werke-in-5-baenden');
Assert::true('and it is reachable by it', $books->findBySlug(1, $book['slug']) !== null);

/* Two books by the same name is the ordinary case here, not an edge one: a
 * shelf without ISBNs is a shelf of old editions, and old editions repeat.
 * Without the ISBN to tell them apart, the second must not take the first
 * one's address. */
$second = $books->insert(1, ['title' => 'Fontane Werke in 5 Bänden', 'reading_status' => 'unread']);
$secondBook = $books->findById(1, $second);

Assert::true('the second gets an address of its own', $secondBook['slug'] !== $book['slug']);
Assert::same('numbered rather than mangled', $secondBook['slug'], 'fontane-werke-in-5-baenden-2');
Assert::true('and the first is still where it was', $books->findBySlug(1, $book['slug'])['id'] === $id);

Assert::group('A book by hand: it behaves like any other');

// The shelf must show it. A book that cannot be found again is not saved.
Assert::same('it is on the shelf', count($books->search(1, [], 10, 0)['rows']), 2);
Assert::same('and counted', $books->countAll(1), 2);

/* The one worklist it must stay out of: enrichment is keyed on isbn13, so a
 * book without one is not asked about night after night. That is the right
 * behaviour and not an oversight - there is nothing to ask with. */
$open = $pdo->query('SELECT COUNT(*) FROM books WHERE isbn13 IS NULL')->fetchColumn();
Assert::same('both are outside the lookup queue, having nothing to look up with', (int) $open, 2);

// It filters as "without ISBN", which is how they are found again to be
// photographed or completed by hand.
Assert::same('and the ISBN facet can find them', $books->countByIsbn(1), ['with' => 0, 'without' => 2]);

Assert::group('A book by hand: the author typed alongside the title is kept');

/* The form asks for a title and an author, because both narrow a catalogue
 * search. Choosing "create without searching" used to keep only the title -
 * the author field was read from the pipe-separated list a picked record
 * fills in, and an empty one meant nobody had named anybody.
 *
 * Somebody who has just typed a name and is then asked for it again on the
 * next screen is being told the form was not listening.
 */
$hand = $books->insert(1, ['title' => 'Der kleine Häwelmann', 'reading_status' => 'unread']);
$books->replaceAuthors(1, $hand, [['name' => 'Theodor Storm', 'role' => 'author']], new App\Repository\AuthorRepository($pdo));

$rows = $pdo->prepare(
    'SELECT a.name, ba.role FROM authors a JOIN book_authors ba ON ba.author_id = a.id WHERE ba.book_id = ?'
);
$rows->execute([$hand]);
$people = $rows->fetchAll();

Assert::same('the name is on the book', count($people), 1);
Assert::same('spelled as it was typed', $people[0]['name'], 'Theodor Storm');
Assert::same('as an author, which is the only role a single field can mean', $people[0]['role'], 'author');
