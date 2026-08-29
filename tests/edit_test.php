<?php
declare(strict_types=1);

use App\Core\CoverImage;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

Assert::group('CoverImage::attributionLink');

// Open Library asks for a link back as a courtesy; Google's terms ask
// outright. Naming a source without linking it satisfies neither.
Assert::same(
    'Open Library credit points at the edition',
    CoverImage::attributionLink(['source' => 'openlibrary'], '9783499006548'),
    'https://openlibrary.org/isbn/9783499006548'
);
Assert::same(
    'Google credit points at the volume',
    CoverImage::attributionLink(['source' => 'google'], '9783499006548'),
    'https://books.google.com/books?vid=ISBN9783499006548'
);
Assert::same(
    'our own photograph needs no backlink',
    CoverImage::attributionLink(['source' => 'own'], '9783499006548'),
    null
);
Assert::same('no ISBN, no link', CoverImage::attributionLink(['source' => 'openlibrary'], null), null);
Assert::same('no cover, no link', CoverImage::attributionLink(null, '9783499006548'), null);

Assert::group('BookRepository::update');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo, dirname(__DIR__) . '/schema.sql');

$users = new UserRepository($pdo);
$maike = $users->create('maike@example.org', 'ein-langes-passwort', 'Maike');
$daniel = $users->create('daniel@example.org', 'noch-ein-passwort', 'Daniel');

$books = new BookRepository($pdo);
$authors = new AuthorRepository($pdo);
$tags = new TagRepository($pdo);

$bookId = $books->insert($maike, [
    'isbn13' => '9783499006548',
    'title'  => 'Regenglanz',
    'reading_status' => 'unread',
]);

Assert::same('an update reports success', $books->update($maike, $bookId, [
    'publisher' => 'Rowohlt',
    'rating'    => 5,
    'notes'     => 'Erster Band.',
]), true);

$updated = $books->findById($maike, $bookId);
Assert::same('the publisher was written', $updated['publisher'], 'Rowohlt');
Assert::same('the rating was written', (int) $updated['rating'], 5);
Assert::same('untouched fields keep their value', $updated['title'], 'Regenglanz');

// owner_id is in the WHERE clause, not left to the caller to remember.
Assert::same(
    'another owner cannot edit this book',
    $books->update($daniel, $bookId, ['publisher' => 'Fremd']),
    false
);
Assert::same('and it really was not changed', $books->findById($maike, $bookId)['publisher'], 'Rowohlt');
Assert::same('nor can another owner read it', $books->findById($daniel, $bookId), null);

// Only known columns are written; anything else is dropped rather than
// reaching the query.
$books->update($maike, $bookId, ['owner_id' => $daniel, 'publisher' => 'Rowohlt Taschenbuch']);
Assert::same('owner_id cannot be reassigned through an update', (int) $books->findById($maike, $bookId)['owner_id'], $maike);
Assert::same('the legitimate field still went through', $books->findById($maike, $bookId)['publisher'], 'Rowohlt Taschenbuch');

Assert::same('an update with nothing usable does nothing', $books->update($maike, $bookId, ['nonsense' => 1]), false);

Assert::group('Replacing contributors and tags');

$books->replaceAuthors($maike, $bookId, [
    ['name' => 'Laura Kneidl', 'role' => 'author'],
    ['name' => 'Max Mustermann', 'role' => 'translator'],
], $authors);

$statement = $pdo->prepare(
    'SELECT a.name, ba.role FROM book_authors ba JOIN authors a ON a.id = ba.author_id
      WHERE ba.book_id = ? ORDER BY ba.position'
);
$statement->execute([$bookId]);
$people = $statement->fetchAll();

Assert::same('both people are attached', count($people), 2);
Assert::same('in the order given', $people[0]['name'], 'Laura Kneidl');
Assert::same('with their roles', $people[1]['role'], 'translator');

// Replacing means replacing - the previous list is gone, not merged into.
$books->replaceAuthors($maike, $bookId, [['name' => 'Laura Kneidl', 'role' => 'author']], $authors);
$statement->execute([$bookId]);
Assert::same('a shorter list really shortens it', count($statement->fetchAll()), 1);

$books->replaceTags($maike, $bookId, ['Fantasy', 'New Adult'], $tags);
$tagStatement = $pdo->prepare(
    'SELECT t.name FROM tags t JOIN book_tags bt ON bt.tag_id = t.id WHERE bt.book_id = ? ORDER BY t.name'
);
$tagStatement->execute([$bookId]);
Assert::same('tags attached', array_column($tagStatement->fetchAll(), 'name'), ['Fantasy', 'New Adult']);

$books->replaceTags($maike, $bookId, [], $tags);
$tagStatement->execute([$bookId]);
Assert::same('and can be cleared', $tagStatement->fetchAll(), []);

Assert::group('Filtering by cover');

$withCover = $books->insert($maike, ['isbn13' => '9783473408061', 'title' => 'Mit Cover', 'reading_status' => 'unread']);
$pdo->prepare('INSERT INTO covers (book_id, source, path, is_public) VALUES (?, ?, ?, 1)')
    ->execute([$withCover, 'own', 'a3/9783473408061.webp']);

Assert::same('only books that have one', $books->search($maike, ['cover' => 'yes'])['total'], 1);
Assert::same('only books that lack one', $books->search($maike, ['cover' => 'no'])['total'], 1);
Assert::same('and everything without the filter', $books->search($maike, [])['total'], 2);

$counts = $books->countByCover($maike);
Assert::same('the counts agree', [$counts['with'], $counts['without']], [1, 1]);

Assert::group('BookRepository::delete');

$doomed = $books->insert($maike, ['isbn13' => '9783442718689', 'title' => 'Weggegeben', 'reading_status' => 'unread']);
$books->replaceAuthors($maike, $doomed, [['name' => 'Nur Dieses Buch', 'role' => 'author']], $authors);
$books->replaceTags($maike, $doomed, ['Einmalgenre'], $tags);
$pdo->prepare('INSERT INTO covers (book_id, source, path, is_public) VALUES (?, ?, ?, 1)')
    ->execute([$doomed, 'own', 'ff/9783442718689.webp']);

// A book kept, sharing an author with the one about to go.
$kept = $books->insert($maike, ['isbn13' => '9783499006548', 'title' => 'Bleibt', 'reading_status' => 'unread']);
$books->replaceAuthors($maike, $kept, [['name' => 'Geteilte Person', 'role' => 'author']], $authors);
$books->replaceAuthors($maike, $doomed, [
    ['name' => 'Nur Dieses Buch', 'role' => 'author'],
    ['name' => 'Geteilte Person', 'role' => 'author'],
], $authors);

$authorsBefore = (int) $pdo->query('SELECT COUNT(*) FROM authors')->fetchColumn();

// Another owner must not be able to delete it.
$refused = $books->delete($daniel, $doomed);
Assert::same('a stranger cannot delete the book', $refused['deleted'], false);
Assert::same('and it is still there', $books->findById($maike, $doomed) !== null, true);

$result = $books->delete($maike, $doomed);
Assert::same('the owner can', $result['deleted'], true);
Assert::same('the book is gone', $books->findById($maike, $doomed), null);

// The caller gets the paths so it can remove the files; the repository owns
// rows, not the filesystem.
Assert::same('cover paths come back for the caller to unlink', $result['coverPaths'], ['ff/9783442718689.webp']);
Assert::same('the cover row is gone', (int) $pdo->query('SELECT COUNT(*) FROM covers')->fetchColumn(), 1);

$statement = $pdo->prepare('SELECT COUNT(*) FROM book_authors WHERE book_id = ?');
$statement->execute([$doomed]);
Assert::same('its contributor links are gone', (int) $statement->fetchColumn(), 0);

// Giving a book away must not leave its author in the filter list forever -
// but an author who still has books stays.
$remaining = $pdo->query('SELECT name FROM authors ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
Assert::same('the author with no books left is cleared out', in_array('Nur Dieses Buch', $remaining, true), false);
Assert::same('the one still holding a book stays', in_array('Geteilte Person', $remaining, true), true);
// The sweep is not limited to this book's authors: it clears every person
// left without one, including any orphaned by an earlier edit. Assert that
// invariant rather than a fixture-specific count.
$orphans = (int) $pdo->query(
    'SELECT COUNT(*) FROM authors WHERE NOT EXISTS (SELECT 1 FROM book_authors ba WHERE ba.author_id = authors.id)'
)->fetchColumn();
Assert::same('no author is left without a book', $orphans, 0);
Assert::true('and the list got shorter', count($remaining) < $authorsBefore);

$remainingTags = $pdo->query('SELECT name FROM tags')->fetchAll(PDO::FETCH_COLUMN);
Assert::same('an orphaned tag goes too', in_array('Einmalgenre', $remainingTags, true), false);

Assert::same('deleting something already gone is harmless', $books->delete($maike, $doomed)['deleted'], false);
