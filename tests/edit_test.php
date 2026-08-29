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
