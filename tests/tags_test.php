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

$tags->setGenres(1, [$fantasy]);

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

// A browser sends only ticked boxes, so anything missing from a save has to
// be read as "not a genre" - otherwise unticking would never take effect.
$tags->setGenres(1, [$age]);
Assert::same('the new one is a genre', $tags->count(1, TagRepository::KIND_GENRE), 1);
Assert::same(
    'and the one left out fell back to a label',
    array_column($tags->listAllByName(1, TagRepository::KIND_GENRE), 'name'),
    ['Ab 10 Jahren']
);

Assert::same('saving nothing clears the genres', $tags->setGenres(1, []), 0);
Assert::same('all labels again', $tags->count(1, TagRepository::KIND_LABEL), 3);

// The sidebar asks the same question with a limit on it.
$tags->setGenres(1, [$fantasy, $binding]);
Assert::same(
    'the sidebar sees genres only',
    count($tags->listWithCounts(1, 14, TagRepository::KIND_GENRE)),
    2
);
Assert::same('and the count agrees', $tags->setGenres(1, [$fantasy, $binding]), 2);

// An unused tag is not a genre worth listing: the facet pages join through
// book_tags, and a tag on no book would be a link to an empty shelf.
$orphan = $tags->findOrCreate(1, 'Nie benutzt');
$tags->setGenres(1, [$fantasy, $orphan]);
Assert::same(
    'an orphan genre is not listed',
    array_column($tags->listAllByName(1, TagRepository::KIND_GENRE), 'name'),
    ['Fantasy']
);
Assert::same('nor counted', $tags->count(1, TagRepository::KIND_GENRE), 1);
