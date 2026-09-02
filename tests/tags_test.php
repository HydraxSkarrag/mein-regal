<?php
/**
 * Genres and labels.
 *
 * The export wrote real genres, age ranges, bindings and shop categories into
 * one field, and three hundred and eighty of them in a single list is not a
 * filter. They are told apart by a kind - by hand, because no rule separates
 * "Fantasy" from "Ab 10 Jahren" that would not also separate things it should
 * not.
 */
declare(strict_types=1);

use App\Repository\BookRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

Assert::group('Tags: label until somebody says genre');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo, dirname(__DIR__) . '/schema.sql');
(new UserRepository($pdo))->create('m@example.org', 'ein-langes-passwort', 'M');

$books = new BookRepository($pdo);
$tags = new TagRepository($pdo);

$bookId = $books->insert(1, ['title' => 'Ein Buch', 'isbn13' => '9783473408061']);
$fantasy = $tags->findOrCreate(1, 'Fantasy');
$age = $tags->findOrCreate(1, 'Ab 10 Jahren');
$binding = $tags->findOrCreate(1, 'Taschenbücher');
foreach ([$fantasy, $age, $binding] as $tagId) {
    $tags->link($bookId, $tagId);
}

/*
 * The direction is the whole point. Defaulting a new tag to "genre" would let
 * every import grow the genre list again - which is exactly how it came to
 * hold three hundred and eighty entries.
 */
Assert::same('a new tag is a label', $tags->count(1, TagRepository::KIND_GENRE), 0);
Assert::same('all three are labels', $tags->count(1, TagRepository::KIND_LABEL), 3);
Assert::same('and both kinds together are still three', $tags->count(1), 3);

// The form speaks about every tag it shows, ticked or not: each checkbox has
// a hidden field of the same name in front of it, so an unticked box arrives
// as a plain "no" instead of as silence.
$tags->setKinds(1, [$fantasy => true, $age => false, $binding => false]);

Assert::same('one genre now', $tags->count(1, TagRepository::KIND_GENRE), 1);
Assert::same('the other two stayed labels', $tags->count(1, TagRepository::KIND_LABEL), 2);
Assert::same(
    'the genre list holds only the genre',
    array_column($tags->listAllByName(1, TagRepository::KIND_GENRE), 'name'),
    ['Fantasy']
);
Assert::same(
    'and the label list only the rest',
    array_column($tags->listAllByName(1, TagRepository::KIND_LABEL), 'name'),
    ['Ab 10 Jahren', 'Taschenbücher']
);

// Unticking has to take effect, which is exactly what the hidden field is
// for: the tag arrives with a "0" and is demoted.
$tags->setKinds(1, [$fantasy => false, $age => true, $binding => false]);
Assert::same('the new one is a genre', $tags->count(1, TagRepository::KIND_GENRE), 1);
Assert::same(
    'and the unticked one fell back to a label',
    array_column($tags->listAllByName(1, TagRepository::KIND_GENRE), 'name'),
    ['Ab 10 Jahren']
);

/*
 * A tag the form did not mention is left exactly as it was. That is the
 * difference the hidden field buys: the save no longer has to assume it was
 * shown everything, so the screen can be filtered or paged later without
 * quietly demoting whatever was off-screen.
 */
$tags->setKinds(1, [$binding => false]);
Assert::same(
    'a tag left out of the request keeps its kind',
    array_column($tags->listAllByName(1, TagRepository::KIND_GENRE), 'name'),
    ['Ab 10 Jahren']
);

Assert::same(
    'and saying no to everything clears them',
    $tags->setKinds(1, [$fantasy => false, $age => false, $binding => false]),
    0
);
Assert::same('all labels again', $tags->count(1, TagRepository::KIND_LABEL), 3);

// The sidebar asks the same question with a limit on it.
$tags->setKinds(1, [$fantasy => true, $binding => true]);
Assert::same(
    'the sidebar sees genres only',
    count($tags->listWithCounts(1, 14, TagRepository::KIND_GENRE)),
    2
);
Assert::same('and the count agrees', $tags->setKinds(1, [$fantasy => true, $binding => true]), 2);

// An unused tag is not a genre worth listing: the facet pages join through
// book_tags, and a tag on no book would be a link to an empty shelf.
$orphan = $tags->findOrCreate(1, 'Nie benutzt');
$tags->setKinds(1, [$fantasy => true, $binding => false, $orphan => true]);
Assert::same(
    'an orphan genre is not listed',
    array_column($tags->listAllByName(1, TagRepository::KIND_GENRE), 'name'),
    ['Fantasy']
);
Assert::same('nor counted', $tags->count(1, TagRepository::KIND_GENRE), 1);

Assert::group('Removing a tag has to survive the next import');

$pdo2 = new PDO('sqlite::memory:');
$pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo2->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo2, dirname(__DIR__) . '/schema.sql');
(new UserRepository($pdo2))->create('m@example.org', 'ein-langes-passwort', 'M');

$books2 = new BookRepository($pdo2);
$tags2 = new TagRepository($pdo2);

$one = $books2->insert(1, ['title' => 'Erstes', 'isbn13' => '9783473408061']);
$two = $books2->insert(1, ['title' => 'Zweites', 'isbn13' => '9783150087510']);
$shop = $tags2->findOrCreate(1, 'Custom Stores');
$tags2->link($one, $shop);
$tags2->link($two, $shop);

$tags2->drop(1, $shop);

Assert::same('it is gone from the lists', $tags2->count(1), 0);
Assert::same('and from the shelf sidebar', $tags2->listWithCounts(1, 10), []);
Assert::same('and from what the editor suggests', $tags2->allForOwner(1), []);

/*
 * The links are the whole point of not deleting: they stay, so restoring is
 * one update rather than a reconstruction. Deleting them would also let the
 * next import recreate the tag and re-link the books - the same mistake
 * covers made, where a picture thrown out by hand came back that night.
 */
Assert::same(
    'the links are untouched',
    (int) $pdo2->query('SELECT COUNT(*) FROM book_tags')->fetchColumn(),
    2
);

// link() is the gate every path goes through - import, scanner, edit form.
$three = $books2->insert(1, ['title' => 'Drittes', 'isbn13' => '9783785723166']);
$tags2->link($three, $shop);
Assert::same(
    'a removed tag cannot be put on a book',
    (int) $pdo2->query('SELECT COUNT(*) FROM book_tags')->fetchColumn(),
    2
);

// And the same name does not become a second tag: the slug is still taken.
Assert::same('nor does it come back under its own name', $tags2->findOrCreate(1, 'Custom Stores'), $shop);

$tags2->restore(1, $shop);
Assert::same('restoring brings it back', $tags2->count(1), 1);
Assert::same('with both its books', $tags2->listWithCounts(1, 10)[0]['book_count'], 2);

Assert::group('Merging two spellings of one genre');

$comic = $tags2->findOrCreate(1, 'Comic');
$comics = $tags2->findOrCreate(1, 'Comics');
$tags2->link($one, $comic);
$tags2->link($two, $comic);
$tags2->link($two, $comics);

$result = $tags2->merge(1, $comic, $comics);

Assert::same('one book gained the target tag', $result['moved'], 1);
Assert::same('the other already had it', $result['already'], 1);
Assert::same('the source is gone from the lists', $tags2->find(1, $comic)['dropped_at'] !== null, true);
Assert::same(
    'and both books now carry the target',
    (int) $pdo2->query('SELECT COUNT(*) FROM book_tags WHERE tag_id = ' . $comics)->fetchColumn(),
    2
);

/*
 * Copied, not moved. Moving would make the merge the one step here that
 * cannot be undone; this way restoring the source brings its books with it.
 */
Assert::same(
    'the source keeps its own links',
    (int) $pdo2->query('SELECT COUNT(*) FROM book_tags WHERE tag_id = ' . $comic)->fetchColumn(),
    2
);

Assert::same('merging a tag into itself does nothing', $tags2->merge(1, $comics, $comics)['moved'], 0);

Assert::group('Folding a tag into the field it duplicates');

$paperback = $tags2->findOrCreate(1, 'Taschenbücher');
$tags2->link($one, $paperback);
$tags2->link($two, $paperback);
$tags2->link($three, $paperback);
$books2->update(1, $two, ['binding' => 'paperback']);
$books2->update(1, $three, ['binding' => 'hardcover']);

$ids = $tags2->bookIdsFor(1, $paperback);
$preview = $books2->fillFieldFor(1, $ids, 'binding', 'paperback', true);

Assert::same('one book has an empty binding', $preview['filled'], 1);
Assert::same('one already says paperback', $preview['already'], 1);
Assert::same('and one says something else', $preview['conflicting'], 1);
Assert::same(
    'a dry run writes nothing',
    (int) $pdo2->query("SELECT COUNT(*) FROM books WHERE binding IS NULL")->fetchColumn(),
    1
);

$books2->fillFieldFor(1, $ids, 'binding', 'paperback');
Assert::same(
    'the empty one is filled',
    (int) $pdo2->query("SELECT COUNT(*) FROM books WHERE binding = 'paperback'")->fetchColumn(),
    2
);

// A shop category is not better evidence than the record: the book that
// says hardcover keeps saying hardcover.
Assert::same(
    'and the contradiction is left alone',
    (int) $pdo2->query("SELECT COUNT(*) FROM books WHERE binding = 'hardcover'")->fetchColumn(),
    1
);

$threw = false;
try {
    $books2->fillFieldFor(1, $ids, 'title', 'irgendwas');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
Assert::same('only whitelisted fields can be written this way', $threw, true);
