<?php
/**
 * A removed tag is gone from every page, and stays restorable.
 *
 * Removing a tag hides it and keeps its links; that is the promise under the
 * ×, the tag administration and the file import alike. Two queries forgot the
 * first half: "Kinder- und Jugendliteratur", removed from regal.hydrax.org,
 * stood on the page of "Der Junge in der Nussschale" beside the genres that
 * replaced it, and in the book's edit form. Two more broke the second half
 * without anybody seeing it: every save of the edit form took the removed
 * tag's link off the book, and deleting the last book carrying a removed tag
 * deleted the tag - the record an import checks before making the name again.
 */
declare(strict_types=1);

use App\Repository\BookRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use Tests\Support\TestDatabase;

require_once __DIR__ . '/support/TestDatabase.php';

$pdo = TestDatabase::fresh();
(new UserRepository($pdo))->create('d@example.org', 'ein-langes-passwort', 'D');
$books = new BookRepository($pdo);
$tags = new TagRepository($pdo);

$book = $books->insert(1, ['title' => 'Der Junge in der Nussschale', 'isbn13' => '9783894033675']);
$other = $books->insert(1, ['title' => 'Es wird gut, kleine Maus', 'isbn13' => '9783863212940']);

$old = $tags->findOrCreate(1, 'Kinder- und Jugendliteratur');
$genre = $tags->findOrCreate(1, 'Bilderbuch', $created, TagRepository::KIND_GENRE);
$label = $tags->findOrCreate(1, 'Trauer und Tod');
foreach ([$old, $genre, $label] as $tagId) {
    $tags->link($book, $tagId);
}
$tags->drop(1, $old);

Assert::group('What a book shows');

Assert::same(
    'the book page and the edit form see only tags in use, genres first',
    array_column($tags->forBook(1, $book), 'name'),
    ['Bilderbuch', 'Trauer und Tod']
);
Assert::same('and a book belonging to somebody else is nobody\'s business', $tags->forBook(2, $book), []);

foreach (['app/Controller/ShelfController.php', 'app/Controller/BookController.php'] as $file) {
    $source = (string) file_get_contents(PROJECT_ROOT . '/' . $file);
    Assert::true(basename($file) . ' asks the repository', str_contains($source, '->tags->forBook('));
    Assert::true('and has no tag query of its own', !str_contains($source, 'JOIN book_tags'));
}

Assert::group('Saving a book leaves a removed tag\'s link alone');

/* The edit form shows the tags in use, so those are what it posts back. A
 * save used to delete every link of the book first, the removed tag's with
 * them, and link() rightly refuses to put a removed tag back. */
$books->replaceTags(1, $book, ['Bilderbuch', 'Trauer und Tod'], $tags);
Assert::same('the removed tag still knows its book', $tags->bookIdsFor(1, $old), [$book]);
Assert::same('the book still shows what it showed', array_column($tags->forBook(1, $book), 'name'), ['Bilderbuch', 'Trauer und Tod']);

$books->replaceTags(1, $book, ['Bilderbuch'], $tags);
Assert::same('a tag in use taken off in the form does go', array_column($tags->forBook(1, $book), 'name'), ['Bilderbuch']);

$tags->restore(1, $old);
Assert::same(
    'so restoring brings it back where it was',
    array_column($tags->forBook(1, $book), 'name'),
    ['Bilderbuch', 'Kinder- und Jugendliteratur']
);

Assert::group('Deleting a book keeps the record of a removal');

$lonely = $tags->findOrCreate(1, 'collection:Ravenloft');
$tags->link($other, $lonely);
$tags->drop(1, $lonely);
$unused = $tags->findOrCreate(1, 'Nur an diesem Buch');
$tags->link($other, $unused);

$books->delete(1, $other);
Assert::true('a removed tag outlives the last book carrying it', $tags->find(1, $lonely) !== null);

/* Which is the point of keeping it: the next import meets the name and
 * finds it removed, instead of finding nothing and making it again. */
$again = $books->insert(1, ['title' => 'Neu importiert', 'isbn13' => '9783442245857']);
$tags->link($again, $tags->findOrCreate(1, 'collection:Ravenloft'));
Assert::same('so an import does not bring the name back', $tags->forBook(1, $again), []);

Assert::same('a tag in use with nothing left on it is still tidied away', $tags->find(1, $unused), null);
